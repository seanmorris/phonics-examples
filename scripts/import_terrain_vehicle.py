"""Bake the sandbox vehicles with gltfpack 0.18. Textured vehicles also require ImageMagick."""
import argparse
import gzip
import itertools
import json
from pathlib import Path
import struct
import subprocess
import tempfile
import zipfile

ASSETS = Path(__file__).resolve().parents[1] / "games/terrain-sandbox/assets"
TEXTURE = "textures/texMain_baseColor.png"
VERTEX = struct.Struct("<3f3h2H6B")


def accessor(scene, binary, number):
    field = scene["accessors"][number]
    view = scene["bufferViews"][field["bufferView"]]
    components = {"SCALAR": 1, "VEC2": 2, "VEC3": 3}[field["type"]]
    kind = {5123: "H", 5125: "I", 5126: "f"}[field["componentType"]]
    record = struct.Struct(f"<{components}{kind}")
    start = view.get("byteOffset", 0) + field.get("byteOffset", 0)
    stride = view.get("byteStride", record.size)
    return [record.unpack_from(binary, start + i * stride) for i in range(field["count"])]


def srgb(value):
    encoded = 12.92 * value if value <= .0031308 else 1.055 * value ** (1 / 2.4) - .055
    return round(255 * encoded)


def prepare_car(folder):
    # Preserve wheel membership through gltfpack's static mesh merge.
    file = folder / "scene.gltf"
    scene = json.loads(file.read_text())
    scene.pop("animations", None)
    wheel_materials = {}
    for node in scene["nodes"]:
        if not node.get("name", "").startswith(("left_tire_", "right_tire_", "left_wheel_", "right_wheel_")):
            continue
        for child in node["children"]:
            for part in scene["meshes"][scene["nodes"][child]["mesh"]]["primitives"]:
                original = part["material"]
                if original not in wheel_materials:
                    material = scene["materials"][original]
                    wheel_materials[original] = len(scene["materials"])
                    scene["materials"].append(dict(material, name=material["name"] + "_wheel"))
                part["material"] = wheel_materials[original]
    file.write_text(json.dumps(scene))


def prepare_jet(folder):
    path = folder / "scene.gltf"
    scene = json.loads(path.read_text())
    # Replace the display turntable pose with Y up, nose along +Z. Use the airborne configuration.
    root = scene["nodes"][scene["scenes"][0]["nodes"][0]]
    root["matrix"] = [1,0,0,0, 0,0,-1,0, 0,1,0,0, 0,0,0,1]
    body = scene["nodes"][root["children"][0]]
    body["children"] = [i for i in body["children"]
                        if not scene["nodes"][i]["name"].startswith(("shassy_", "stvorka_", "stvoka_"))]
    path.write_text(json.dumps(scene))


def jet_atlas(folder, scene, primitives):
    # Reserve half the atlas for the painted fuselage, then pack the smaller fittings beside it.
    width, height = 1024, 512
    pixels = bytearray(width * height * 4)
    rects = {}
    materials = (scene["materials"][p["material"]]["pbrMetallicRoughness"] for p in primitives)
    textures = {pbr["baseColorTexture"]["index"] for pbr in materials if "baseColorTexture" in pbr}
    images = sorted({scene["images"][scene["textures"][i]["source"]]["uri"] for i in textures},
                    key=lambda uri: ("fuzel-" not in uri, uri))
    if len(images) > 17:
        raise ValueError("Too many F5 textures for the vehicle atlas")
    for i, uri in enumerate(images):
        x, y, slot = (0, 0, 512) if i == 0 else (512 + (i-1)%4 * 128, (i-1)//4 * 128, 128)
        size = slot - 8
        source = subprocess.check_output(["convert", str(folder / uri), "-resize", f"{size}x{size}!",
                                          "-alpha", "on", "-depth", "8", "RGBA:-"])
        # Four wrapped texels at every edge preserve filtering and the small authored UV overhangs.
        for row in range(slot):
            start = ((row - 4) % size) * size * 4
            line = source[start:start + size * 4]
            start = ((y + row) * width + x) * 4
            pixels[start:start + slot*4] = line[-16:] + line + line[:16]
        rects[uri] = (x + 4, y + 4, size)
    return (width, height, pixels), rects


def export_model(folder, vehicle):
    car = vehicle == "testarossa"
    scene = json.loads((folder / "craft.gltf").read_text())
    roots = [scene["nodes"][i] for i in scene["scenes"][scene.get("scene", 0)]["nodes"]]
    if not roots or any(node.keys() != {"mesh"} for node in roots):
        raise ValueError("Expected gltfpack to bake static geometry in world coordinates")
    binary = (folder / scene["buffers"][0]["uri"]).read_bytes()
    primitives = [part for node in roots for part in scene["meshes"][node["mesh"]]["primitives"]]
    bounds = [scene["accessors"][part["attributes"]["POSITION"]] for part in primitives]
    center_x, center_z = [(min(b["min"][i] for b in bounds) + max(b["max"][i] for b in bounds)) / 2 for i in (0, 2)]
    # The physics origin is 32 cm above the keel. The model's bow already points along local +Z.
    keel_y = min(b["min"][1] for b in bounds) + .32
    scale = 1.0
    if car:
        # The source nose points along +Z. Centre on the axles, with Y=0 at the tire contacts.
        tires = next(p for p in primitives if scene["materials"][p["material"]]["name"] == "Tires_wheel")
        points = accessor(scene, binary, tires["attributes"]["POSITION"])
        center_x = (max(p[0] for p in points) + min(p[0] for p in points)) / 2
        center_z = (max(p[2] for p in points) + min(p[2] for p in points)) / 2
        keel_y = min(p[1] for p in points)
        scale = 4.48 / (max(b["max"][2] for b in bounds) - min(b["min"][2] for b in bounds))
    atlas = None
    if vehicle == "f5":
        keel_y = (min(b["min"][1] for b in bounds) + max(b["max"][1] for b in bounds)) / 2
        scale = 15.6 / (max(b["max"][2] for b in bounds) - min(b["min"][2] for b in bounds))
        atlas, rects = jet_atlas(folder, scene, primitives)
    vertices = bytearray()
    indices = bytearray()
    vertex_ids = {}
    for part in primitives:
        attributes = part["attributes"]
        pbr = scene["materials"][part["material"]]["pbrMetallicRoughness"]
        textured = "baseColorTexture" in pbr
        color = [srgb(v) for v in pbr.get("baseColorFactor", [1, 1, 1, 1])[:3]]
        roughness = round(255 * pbr.get("roughnessFactor", 1))
        metallic = round(255 * pbr.get("metallicFactor", 1))
        surface = 255 if textured else int(scene["materials"][part["material"]].get("name", "").endswith("_wheel"))
        positions = accessor(scene, binary, attributes["POSITION"])
        normals = accessor(scene, binary, attributes["NORMAL"])
        uvs = accessor(scene, binary, attributes["TEXCOORD_0"]) if textured else itertools.repeat((0, 0))
        if atlas and textured:
            texture = scene["textures"][pbr["baseColorTexture"]["index"]]
            left, top, size = rects[scene["images"][texture["source"]]["uri"]]
            # The canopy has very large repeating UVs on a single-color texture.
            uvs = [((left + max(-3, min(size + 3, u * size))) / atlas[0],
                    (top + max(-3, min(size + 3, v * size))) / atlas[1]) for u, v in uvs]
        part_indices = []
        for (x, y, z), normal, uv in zip(positions, normals, uvs):
            record = VERTEX.pack((x - center_x)*scale, (y - keel_y)*scale, (z - center_z)*scale,
                *(round(max(-1, min(1, n)) * 32767) for n in normal),
                *(round(v * 65535) for v in uv), *color, roughness, metallic, surface)
            if record not in vertex_ids:
                vertex_ids[record] = len(vertex_ids)
                vertices.extend(record)
            part_indices.append(vertex_ids[record])
        for (index,) in accessor(scene, binary, part["indices"]):
            indices.extend(struct.pack("<H", part_indices[index]))

    if atlas:
        width, height, pixels = atlas
    elif car:
        width, height, pixels = 1, 1, bytes([255]*4)
    else:
        width, height = 512, 256
        pixels = subprocess.check_output(["convert", str(folder / TEXTURE), "-resize", f"{width}x{height}!",
                                          "-alpha", "on", "-depth", "8", "RGBA:-"])
        if len(pixels) != width * height * 4:
            raise ValueError("Unexpected dashboard texture dimensions")
    header = struct.pack("<4I", len(vertices) // VERTEX.size, len(indices) // 2, width, height)
    return gzip.compress(header + vertices + indices + pixels, compresslevel=9, mtime=0)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("archive", type=Path)
    parser.add_argument("--vehicle", choices=("jetski", "testarossa", "f5"), default="jetski")
    args = parser.parse_args()
    destination = ASSETS / args.vehicle
    with tempfile.TemporaryDirectory() as tmp, zipfile.ZipFile(args.archive) as archive:
        folder = Path(tmp)
        for name in ("scene.gltf", "scene.bin"):
            archive.extract(name, folder)
        options = ["-km"]
        if args.vehicle == "testarossa":
            prepare_car(folder)
        elif args.vehicle == "f5":
            for name in archive.namelist():
                if name.startswith("textures/") and name.endswith(".jpeg"):
                    archive.extract(name, folder)
            prepare_jet(folder)
        else:
            archive.extract(TEXTURE, folder)
            options = ["-si", "0.05", "-sa"]
        subprocess.run(["gltfpack", "-i", str(folder / "scene.gltf"), "-o", str(folder / "craft.gltf"),
                        *options, "-noq"], check=True)
        data = export_model(folder, args.vehicle)
        destination.mkdir(parents=True, exist_ok=True)
        (destination / "mesh.bin.gz").write_bytes(data)
        (destination / "license.txt").write_bytes(archive.read("license.txt"))


if __name__ == "__main__":
    main()
