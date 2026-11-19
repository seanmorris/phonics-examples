"""Bake the sandbox vehicles with gltfpack 0.18. The jet ski dashboard also requires ImageMagick."""
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


def export_model(folder, car):
    scene = json.loads((folder / "craft.gltf").read_text())
    if scene["nodes"] != [{"mesh": 0}]:
        raise ValueError("Expected gltfpack to bake the static model into one mesh in world metres")
    binary = (folder / scene["buffers"][0]["uri"]).read_bytes()
    primitives = scene["meshes"][0]["primitives"]
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

    if car:
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
    parser.add_argument("--vehicle", choices=("jetski", "testarossa"), default="jetski")
    args = parser.parse_args()
    car = args.vehicle == "testarossa"
    destination = ASSETS / args.vehicle
    with tempfile.TemporaryDirectory() as tmp, zipfile.ZipFile(args.archive) as archive:
        folder = Path(tmp)
        for name in (("scene.gltf", "scene.bin") if car else ("scene.gltf", "scene.bin", TEXTURE)):
            archive.extract(name, folder)
        if car:
            prepare_car(folder)
        options = ["-km"] if car else ["-si", "0.05", "-sa"]
        subprocess.run(["gltfpack", "-i", str(folder / "scene.gltf"), "-o", str(folder / "craft.gltf"),
                        *options, "-noq"], check=True)
        data = export_model(folder, car)
        destination.mkdir(parents=True, exist_ok=True)
        (destination / "mesh.bin.gz").write_bytes(data)
        (destination / "license.txt").write_bytes(archive.read("license.txt"))


if __name__ == "__main__":
    main()
