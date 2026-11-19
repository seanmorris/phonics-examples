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
# The F5 turns about its centre of gravity, in metres from the middle of its bounding box with the nose along +Z:
# a quarter of the way along the mean aerodynamic chord of the wing's reference trapezoid, halfway up the fuselage.
F5_BALANCE = (0.0, -0.50, -1.53)
# The F5's moving surfaces in the same frame. Each is a closed piece of the fuselage mesh that shares no vertex
# with the skin, found as the only piece inside its box. It turns about the line from the first hinge point to the
# second: the middle of the leading edge at both ends of an aileron or the rudder, and the stabilators' common
# spanwise pivot at a quarter of their mean chord. Positive angles follow the right-hand rule about that direction,
# so a positive aileron raises the +X trailing edge and lowers the -X one, a positive stabilator raises both and a
# positive rudder swings towards +X.
F5_SURFACES = (
    ("aileron", ((2.2, -1.1, -3.6), (3.2, -0.9, -2.7)), (2.2649, -0.9753, -2.8175), (3.1411, -0.9805, -2.9156)),
    ("aileron", ((-3.2, -1.1, -3.6), (-2.2, -0.9, -2.7)), (-2.2628, -0.9753, -2.8175), (-3.1389, -0.9805, -2.9156)),
    ("stabilator", ((0.5, -1.1, -6.8), (2.4, -0.85, -5.0)), (0.0, -0.953, -5.81), (1.0, -0.953, -5.81)),
    ("stabilator", ((-2.4, -1.1, -6.8), (-0.5, -0.85, -5.0)), (0.0, -0.953, -5.81), (1.0, -0.953, -5.81)),
    ("rudder", ((-0.1, -0.3, -7.2), (0.1, 0.75, -6.2)), (0.0, 0.715, -6.3628), (0.0, -0.2725, -6.3628)),
)
# Surface tags count up from here. Like 255 they mark textured surfaces, and the vertex shader turns them.
FIRST_SURFACE = 250


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


def pieces(points, corners):
    # Triangles that share a corner position belong together, whatever their normals and texture coordinates.
    parent = list(range(len(points)))

    def root(i):
        while parent[i] != i:
            parent[i] = parent[parent[i]]
            i = parent[i]
        return i

    first = {}
    for i, point in enumerate(points):
        parent[root(i)] = root(first.setdefault(point, i))
    for k in range(0, len(corners), 3):
        for i in corners[k + 1:k + 3]:
            parent[root(i)] = root(corners[k])
    groups = {}
    for k in range(0, len(corners), 3):
        groups.setdefault(root(corners[k]), []).append(k // 3)
    return groups.values()


def jet_surfaces(scene, binary, primitives, frame):
    # Return the surface tag of every moving triangle by primitive and triangle, and the hinges in the same frame.
    found = [[] for _ in F5_SURFACES]
    for number, part in enumerate(primitives):
        # Positions that agree to a tenth of a millimetre are joined. Some panel corners differ in the last bits.
        positions = accessor(scene, binary, part["attributes"]["POSITION"])
        points = [tuple(round(v, 4) for v in frame(p)) for p in positions]
        corners = [i for (i,) in accessor(scene, binary, part["indices"])]
        for triangles in pieces(points, corners):
            piece = [points[corners[3*t + k]] for t in triangles for k in range(3)]
            low, high = [min(p[k] for p in piece) for k in range(3)], [max(p[k] for p in piece) for k in range(3)]
            for slot, (_, box, _, _) in enumerate(F5_SURFACES):
                if all(box[0][k] <= low[k] and high[k] <= box[1][k] for k in range(3)):
                    found[slot].append((number, triangles))
    tags = {}
    hinges = []
    for slot, ((control, _, start, end), matches) in enumerate(zip(F5_SURFACES, found)):
        if len(matches) != 1:
            raise ValueError(f"Expected one F5 {control} piece in its box, found {len(matches)}")
        number, triangles = matches[0]
        for t in triangles:
            tags[number, t] = FIRST_SURFACE + slot
        length = sum((b - a) ** 2 for a, b in zip(start, end)) ** .5
        axis = [(b - a) / length for a, b in zip(start, end)]
        hinges.append({"tag": FIRST_SURFACE + slot, "control": control, "point": start, "axis": axis})
    return tags, hinges


def jet_repairs(vertices, indices, vertex):
    # A moving part must not uncover the inside of the model. In the source the +X aileron lacks its end caps and
    # part of its leading face, and its cut-out in the wing lacks the walls that the -X side has, so those faces are
    # copied across from the -X side onto the +X vertices they match. The rudder stands in a hole in the fuselage
    # spine, so a fixed copy of its foot stays behind to close the hole when it swings.
    records = [bytes(vertices[i:i + VERTEX.size]) for i in range(0, len(vertices), VERTEX.size)]
    points = [struct.unpack_from("<3f", record) for record in records]
    corners = struct.unpack(f"<{len(indices) // 2}H", indices)
    triangles = [corners[k:k + 3] for k in range(0, len(corners), 3)]
    low, high = ([v - offset for v, offset in zip(corner, F5_BALANCE)] for corner in F5_SURFACES[1][1])

    def near(point, side):
        # Within 3 cm of the -X aileron's box, or of its mirror image for side -1.
        return all(low[k] - .03 <= point[k] * (side if k == 0 else 1) <= high[k] + .03 for k in range(3))

    # Each -X tag and the +X tag it becomes.
    pair = {FIRST_SURFACE + 1: FIRST_SURFACE, 255: 255}
    targets = {}
    for record, point in zip(records, points):
        if record[-1] in pair.values() and near(point, -1):
            targets.setdefault(record[-1], []).append(point)
    shape = lambda tag, corner_points: (tag, frozenset(tuple(round(v, 4) for v in p) for p in corner_points))
    existing = {shape(records[t[0]][-1], [points[i] for i in t]) for t in triangles}
    for triangle in triangles:
        tag = records[triangle[0]][-1]
        if tag not in pair or not all(near(points[i], 1) for i in triangle):
            continue
        mirrored = []
        for i in triangle:
            x, y, z = points[i]
            distance, match = min((sum((a - b) ** 2 for a, b in zip((-x, y, z), p)), p) for p in targets[pair[tag]])
            # The two sides of the model differ by about 2 mm.
            mirrored.append(match if distance <= .004 ** 2 else None)
        if None in mirrored or len(set(mirrored)) < 3 or shape(pair[tag], mirrored) in existing:
            continue
        existing.add(shape(pair[tag], mirrored))
        # Reflection reverses the winding, so the corners are taken backwards.
        for i, point in reversed(list(zip(triangle, mirrored))):
            nx, ny, nz = struct.unpack_from("<3h", records[i], 12)
            record = struct.pack("<3f3h", *point, -nx, ny, nz) + records[i][18:-1] + bytes([pair[tag]])
            indices.extend(struct.pack("<H", vertex(record)))

    rudder = [t for t in triangles if records[t[0]][-1] == FIRST_SURFACE + 4]
    foot = min(points[i][1] for t in rudder for i in t)
    for triangle in rudder:
        corner_points = [points[i] for i in triangle]
        (ax, ay, az), (bx, by, bz), (cx, cy, cz) = corner_points
        # The foot is the level face at the bottom: its normal is vertical.
        normal = ((by - ay)*(cz - az) - (bz - az)*(cy - ay), (bz - az)*(cx - ax) - (bx - ax)*(cz - az),
                  (bx - ax)*(cy - ay) - (by - ay)*(cx - ax))
        if max(p[1] for p in corner_points) > foot + .012 or abs(normal[1]) < .9 * sum(v * v for v in normal) ** .5:
            continue
        # 2 mm below the foot and facing up, as part of the fixed skin.
        for i, (x, y, z) in reversed(list(zip(triangle, corner_points))):
            record = struct.pack("<3f3h", x, y - .002, z, 0, 32767, 0) + records[i][18:-1] + bytes([255])
            indices.extend(struct.pack("<H", vertex(record)))


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
    tags, hinges = {}, None
    if vehicle == "f5":
        keel_y = (min(b["min"][1] for b in bounds) + max(b["max"][1] for b in bounds)) / 2
        scale = 15.6 / (max(b["max"][2] for b in bounds) - min(b["min"][2] for b in bounds))
        atlas, rects = jet_atlas(folder, scene, primitives)
        # Find the moving surfaces in the bounding-box frame, then move the origin to the centre of gravity.
        box = (center_x, keel_y, center_z)
        tags, hinges = jet_surfaces(scene, binary, primitives, lambda p: [(v - c) * scale for v, c in zip(p, box)])
        center_x, keel_y, center_z = (c + offset / scale for c, offset in zip(box, F5_BALANCE))
        for hinge in hinges:
            hinge["point"] = [round(v - offset, 5) for v, offset in zip(hinge["point"], F5_BALANCE)]
            hinge["axis"] = [round(v, 6) for v in hinge["axis"]]
    vertices = bytearray()
    indices = bytearray()
    vertex_ids = {}

    def vertex(record):
        if record not in vertex_ids:
            vertex_ids[record] = len(vertex_ids)
            vertices.extend(record)
        return vertex_ids[record]

    for number, part in enumerate(primitives):
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
        corners = [i for (i,) in accessor(scene, binary, part["indices"])]
        corner_tags = [tags.get((number, k // 3), surface) for k in range(len(corners))]
        # Each vertex keeps its place with the tag of the first triangle that uses it.
        first_tags = {}
        for index, tag in zip(corners, corner_tags):
            first_tags.setdefault(index, tag)
        records = []
        for i, ((x, y, z), normal, uv) in enumerate(zip(positions, normals, uvs)):
            records.append(VERTEX.pack((x - center_x)*scale, (y - keel_y)*scale, (z - center_z)*scale,
                *(round(max(-1, min(1, n)) * 32767) for n in normal),
                *(round(v * 65535) for v in uv), *color, roughness, metallic, first_tags.get(i, surface)))
            vertex(records[-1])
        # The tag is the last byte of a record. Where a moving part shares a vertex with other triangles, they get
        # separate copies and each triangle turns with its own part.
        for index, tag in zip(corners, corner_tags):
            indices.extend(struct.pack("<H", vertex(records[index][:-1] + bytes([tag]))))
    if hinges:
        jet_repairs(vertices, indices, vertex)

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
    return gzip.compress(header + vertices + indices + pixels, compresslevel=9, mtime=0), hinges


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
        data, hinges = export_model(folder, args.vehicle)
        destination.mkdir(parents=True, exist_ok=True)
        (destination / "mesh.bin.gz").write_bytes(data)
        if hinges:
            # Hinges of the tagged surfaces, in metres in the model frame, for the vertex shader.
            lines = ",\n".join(" " + json.dumps(hinge) for hinge in hinges)
            (destination / "surfaces.json").write_text('{"surfaces": [\n' + lines + "\n]}\n")
        (destination / "license.txt").write_bytes(archive.read("license.txt"))


if __name__ == "__main__":
    main()
