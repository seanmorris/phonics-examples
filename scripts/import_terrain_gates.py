"""Bake the Landscape Sandbox race gates from two CC-BY models, then paint their prints with Node.

Usage: python3 scripts/import_terrain_gates.py finish_line.zip davita_finish-line_2020.zip

The road gate is the "Finish Line" gantry with solid posts in place of its lattice towers and a flat label panel in
place of its 3D lettering. The air gate is the same board and the tabs on top of the towers at three times the size,
without posts or base plates. The water gate is the inflatable arch scaled to 12 metres. Only geometry comes from the
archives: the importer never extracts their textures, and scripts/generate-terrain-gates.mjs paints every pixel of
the three atlases.

Each gate's frame puts the origin at the bottom centre of its window, +Y up, +Z along the direction of travel and
+X across. Every atlas stacks one print per role in bands a quarter of its height, and the meshes address the top
band, so a draw selects a role by adding that role's offset from gates.json to the texture coordinates.

Each vertex carries a tag naming its part, so that model.vert can resize a gate's opening. The posts and the tabs move
out with the window's sides, the board and the tabs rise with its top, and the checker bands either side of the label
show as many whole columns as fill the board. The meshes hold enough spare columns for the widest opening; at the
mesh's own size they fold away into the end of each band. The arch scales.
"""
import argparse
import gzip
from itertools import combinations
import json
from pathlib import Path
import struct
import subprocess
import sys
import tempfile
import zipfile

# The vehicle importer's helpers, without leaving a bytecode cache in scripts/.
sys.dont_write_bytecode = True
sys.path.insert(0, str(Path(__file__).resolve().parent))
from import_terrain_vehicle import VERTEX, accessor, srgb  # noqa: E402

ROOT = Path(__file__).resolve().parents[1]
ASSETS = ROOT / "games/terrain-sandbox/assets/gates"
ROLES = ("start", "checkpoint", "finish", "start-finish")
# The posts reach this far below the base so that sloping ground never shows a gap under them.
POST_DEPTH = 8.0
AIR_SCALE = 3.0
WATER_WIDTH = 12.0
# The source arch's base stands this far below the water line, which leaves its opening 3.6 metres high.
WATER_DRAFT = .8
# The feet then reach this far down. A gate rides at the wave height at its centre, and the sandbox's swell can leave
# the water at a foot up to 0.83 metres lower than that at 100% choppiness, 2.5 metres at the panel's highest, 300%.
# The last 0.2 metres allow for the jet ski's wakes.
FOOT_DEPTH = 2.7
# Atlas sizes and the board's print layout in pixels of one role band: the label, then a strip for each board
# colour. The generator reads these back from gates.json.
BOARD_ATLAS = (1024, 512)
BOARD_PRINT = {"label": [8, 8, 760, 112], "swatches": {"frame": [776, 0, 80, 128], "light": [856, 0, 84, 128],
                                                        "dark": [940, 0, 84, 128]}}
# The arch print maps gate metres to band pixels: the gate origin lands on `origin`, and +Y runs up the band. Each
# 400-row band holds the arch from its crown to its feet; a taller atlas would take the mesh past BakedMesh's 4 MiB.
WATER_ATLAS = (496, 1600)
WATER_PRINT = {"pixelsPerMetre": 40, "origin": [248, 287]}
# Cells of the gantry's 8 by 8 palette texture that its board uses, by column and row.
BOARD_CELLS = {(0, 7): "light", (7, 7): "dark", (2, 7): "frame", (3, 5): "label"}
# The range of each kind's opening in metres, width then height. The board meshes hold checker columns for the widest.
SIZES = {"road": ((6, 60), (2, 40)), "water": ((4.5, 60), (2, 40)), "air": ((20, 300), (5, 150))}
# The arch stretches to an opening of another shape, scaling up at most twice as much as across, or across at most
# twice as much as up, which keeps its lettering legible.
ARCH_STRETCH = 2
# Part tags, the last byte of each vertex. Every gate tag lies from 128 to 223, clear of the vehicles' wheel tags
# below 128 and the F5's hinge tags from 250 to 254. Bit 6 marks a part that samples the atlas.
TEXTURED = 64
LABEL, FRAME, SQUARE, CAP, POST, BASE, ARCH = range(1, 8)


def tag(part):
    return 128 + (TEXTURED if part in (LABEL, FRAME, SQUARE, ARCH) else 0) + part


def multiply(a, b):
    return [[sum(a[i][k] * b[k][j] for k in range(4)) for j in range(4)] for i in range(4)]


def node_matrix(node):
    if "matrix" in node:
        m = node["matrix"]
        return [[m[c * 4 + r] for c in range(4)] for r in range(4)]
    if node.keys() & {"translation", "rotation", "scale"}:
        raise ValueError("Expected the gate scenes to place their nodes with matrices")
    return [[float(i == j) for j in range(4)] for i in range(4)]


def primitives(folder):
    """Every primitive of the scene by mesh name, with positions and normals in scene coordinates."""
    scene = json.loads((folder / "scene.gltf").read_text())
    binary = (folder / scene["buffers"][0]["uri"]).read_bytes()
    found = {}

    def walk(number, parent):
        node = scene["nodes"][number]
        matrix = multiply(parent, node_matrix(node))
        if "mesh" in node:
            mesh = scene["meshes"][node["mesh"]]
            for part in mesh["primitives"]:
                attributes = part["attributes"]
                points = [tuple(sum(matrix[r][c] * v for c, v in enumerate((*p, 1))) for r in range(3))
                          for p in accessor(scene, binary, attributes["POSITION"])]
                normals = []
                for n in accessor(scene, binary, attributes["NORMAL"]):
                    n = [sum(matrix[r][c] * n[c] for c in range(3)) for r in range(3)]
                    length = sum(v * v for v in n) ** .5
                    normals.append(tuple(v / length for v in n))
                found[mesh["name"]] = {
                    "points": points, "normals": normals, "uvs": accessor(scene, binary, attributes["TEXCOORD_0"]),
                    "corners": [i for (i,) in accessor(scene, binary, part["indices"])],
                    "material": scene["materials"][part["material"]]["pbrMetallicRoughness"]}
        for child in node.get("children", ()):
            walk(child, matrix)

    for number in scene["scenes"][scene.get("scene", 0)]["nodes"]:
        walk(number, [[float(i == j) for j in range(4)] for i in range(4)])
    return found


def bounds(points):
    return [min(p[k] for p in points) for k in range(3)], [max(p[k] for p in points) for k in range(3)]


class Mesh:
    """Triangles in the gate frame, packed as the sandbox's vehicle meshes are."""

    def __init__(self):
        self.vertices, self.indices, self.ids, self.triangles = bytearray(), bytearray(), {}, 0
        # Corners of the gate at the mesh's own size, which leaves out the spare checker columns.
        self.points = []

    def triangle(self, corners, paint, spare=False):
        """Add one triangle of (position, normal, uv) corners, wound anticlockwise seen from its front."""
        for point, normal, uv in corners:
            record = VERTEX.pack(*point, *(round(max(-1, min(1, n)) * 32767) for n in normal),
                                 *(round(max(0, min(1, v)) * 65535) for v in uv), *paint)
            if record not in self.ids:
                self.ids[record] = len(self.ids)
                self.vertices.extend(record)
                if not spare:
                    self.points.append(point)
            self.indices.extend(struct.pack("<H", self.ids[record]))
        self.triangles += 1

    def quad(self, corners, normal, uvs, paint):
        for a, b, c in ((0, 1, 2), (0, 2, 3)):
            self.triangle([(corners[i], normal, uvs[i]) for i in (a, b, c)], paint)

    def data(self, width, height):
        if len(self.ids) > 65536:
            raise ValueError("Too many gate vertices for 16-bit indices")
        header = struct.pack("<4I", len(self.ids), len(self.indices) // 2, width, height)
        # The generator replaces these blank pixels with the prints.
        return gzip.compress(header + self.vertices + self.indices + bytes(width * height * 4), mtime=0)


def metal_paint(material, part):
    color = [srgb(v) for v in material["baseColorFactor"][:3]]
    return (*color, round(255 * material["roughnessFactor"]), round(255 * material["metallicFactor"]), tag(part))


def board_uv(rectangle, width, height):
    """Texture coordinates of the centre of a rectangle given in band pixels."""
    x, y, w, h = rectangle
    return (x + w / 2) / width, (y + h / 2) / height


def cell(board, corners):
    """Which board colour a triangle samples from the gantry's palette texture."""
    cells = {(int(board["uvs"][i][0] * 8), int(board["uvs"][i][1] * 8)) for i in corners}
    if len(cells) != 1 or next(iter(cells)) not in BOARD_CELLS:
        raise ValueError(f"Unexpected board palette cells {sorted(cells)}")
    return BOARD_CELLS[next(iter(cells))]


def gantry(parts, scale, lift, posts, widest):
    """The gantry's board and tabs, and optionally solid posts and base plates, in a gate frame with the board facing
    -Z. The checker bands run on in spare columns for an opening `widest` metres wide."""
    towers = parts["pole.001_Metal_0"]
    board = parts["Board.001_CP Black_0"]
    # The two lattice towers, each one's lowest and highest corner.
    sides = [bounds([p for p in towers["points"] if p[0] * sign > 0]) for sign in (-1, 1)]
    # The towers stand on one line across the gate, which becomes the gate's plane.
    centre_x = sum(low[0] + high[0] for low, high in sides) / 4
    centre_z = sum(low[2] + high[2] for low, high in sides) / 4

    def place(p):
        # A half turn about Y puts the board's printed face towards the oncoming vehicle, on the -Z side.
        return (-(p[0] - centre_x) * scale, (p[1] + lift) * scale, -(p[2] - centre_z) * scale)

    def turn(n):
        return (-n[0], n[1], -n[2])

    mesh = Mesh()
    width, height = BOARD_ATLAS
    swatches = {name: board_uv(rect, width, height) for name, rect in BOARD_PRINT["swatches"].items()}

    def textured(part):
        return (255, 255, 255, round(255 * board["material"]["roughnessFactor"]), 0, tag(part))

    board_low, board_high = bounds(board["points"])
    back = board_low[2]
    # The printed face is a frame around a recessed floor, which holds the squares and the red panel that the
    # lettering stood on. The back face is a plain rectangle.
    panel = [board["points"][i] for k in range(0, len(board["corners"]), 3)
             for i in board["corners"][k:k + 3] if cell(board, board["corners"][k:k + 3]) == "label"]
    floor = panel[0][2]
    counts = {"source": sum(len(part["corners"]) // 3 for part in parts.values()),
              "towers": len(towers["corners"]) // 3, "lettering": len(parts["Finish__0"]["corners"]) // 3,
              "board": len(board["corners"]) // 3, "back": 0, "panel": len(panel) // 3, "kept": 0, "copied": 0}
    squares = []
    for k in range(0, len(board["corners"]), 3):
        corners = board["corners"][k:k + 3]
        points = [board["points"][i] for i in corners]
        role = cell(board, corners)
        if max(p[2] for p in points) < back + .001:
            counts["back"] += 1
            continue
        if role == "label":
            continue
        uv = swatches[role]
        paint = textured(FRAME if role == "frame" else SQUARE)
        mesh.triangle([(place(p), turn(board["normals"][i]), uv) for p, i in zip(points, corners)], paint)
        counts["kept"] += 1
        if all(abs(p[2] - floor) < .0005 for p in points):
            if role == "frame":
                raise ValueError("Expected only checker squares on the board's floor")
            squares.append((points, uv))

    # A flat copy of the floor replaces the back face, inside a ring of the frame colour, so the board reads from
    # behind without growing thicker. Seen from behind, source -X is to the right, so the copies take their corners
    # in reverse order and the labels read along -X.
    def behind(x0, x1, y0, y1, uvs):
        corners = [place(p) for p in ((x1, y0, back), (x0, y0, back), (x0, y1, back), (x1, y1, back))]
        return corners, turn((0, 0, -1)), uvs

    for points, uv in squares:
        mesh.triangle([(place((p[0], p[1], back)), turn((0, 0, -1)), uv) for p in points[::-1]], textured(SQUARE))
        counts["copied"] += 1
    (inner_x0, inner_y0, _), (inner_x1, inner_y1, _) = bounds([p for points, _ in squares for p in points] + panel)
    frame = [swatches["frame"]] * 4
    for x0, x1, y0, y1 in ((board_low[0], board_high[0], board_low[1], inner_y0),
                           (board_low[0], board_high[0], inner_y1, board_high[1]),
                           (board_low[0], inner_x0, inner_y0, inner_y1),
                           (inner_x1, board_high[0], inner_y0, inner_y1)):
        mesh.quad(*behind(x0, x1, y0, y1, frame), textured(FRAME))
    counts["ring"] = 8

    # The label panel replaces the red panel on the floor, and its copy lies on the back.
    (x0, y0, _), (x1, y1, _) = bounds(panel)
    label_x, label_y, label_w, label_h = BOARD_PRINT["label"]
    if abs(label_w / label_h - (x1 - x0) / (y1 - y0)) > .01 * label_w / label_h:
        raise ValueError("The label layout no longer matches the panel's proportions")
    left, right = label_x / width, (label_x + label_w) / width
    top, bottom = label_y / height, (label_y + label_h) / height
    uvs = [(left, bottom), (right, bottom), (right, top), (left, top)]
    front = [place(p) for p in ((x0, y0, floor), (x1, y0, floor), (x1, y1, floor), (x0, y1, floor))]
    mesh.quad(front, turn((0, 0, 1)), uvs, textured(LABEL))
    mesh.quad(*behind(x0, x1, y0, y1, uvs), textured(LABEL))
    counts["labels"] = 4

    # Each checker band, in the gate frame: where it starts beside the label, how wide its columns are, and how
    # many it shows. A side is -1 for -X and 1 for +X.
    def levels(values):
        """Distinct values, merging those within a millimetre of the last one kept."""
        found = []
        for value in sorted(values):
            if not found or value - found[-1] > .001:
                found.append(value)
        return found

    band = {}
    for side in (-1, 1):
        placed = [place(p) for points, _ in squares for p in points]
        across = levels(abs(p[0]) for p in placed if p[0] * side > 0)
        rows = levels(p[1] for p in placed)
        band[side] = {"label": across[0], "column": (across[-1] - across[0]) / (len(across) - 1),
                      "columns": len(across) - 1, "rows": len(rows) - 1}
    columns = {band[side]["columns"] for side in band}
    if len(columns) != 1 or any(b["rows"] != 4 for b in band.values()):
        raise ValueError("Expected the same number of checker columns on both sides, in four rows")
    (shown,) = columns

    # Spare columns continue each band past its end, the pattern repeating every two columns. They lie over the end
    # of the board and the posts, where at the mesh's own size model.vert folds them into the band's last edge.
    def spares(baked):
        for points, uv in squares:
            placed = [place(p) for p in points]
            side = 1 if sum(p[0] for p in placed) > 0 else -1
            b = band[side]
            column = int((min(abs(p[0]) for p in placed) - b["label"]) / b["column"] + .5)
            if column < shown - 2:
                continue
            for extra in range(shown + (column - shown) % 2, baked, 2):
                shift = side * (extra - column) * b["column"]
                moved = [(p[0] + shift, p[1], p[2]) for p in placed]
                mesh.triangle([(q, turn((0, 0, 1)), uv) for q in moved], textured(SQUARE), spare=True)
                rear = [(q[0], q[1], place((0, 0, back))[2]) for q in moved[::-1]]
                mesh.triangle([(q, turn((0, 0, -1)), uv) for q in rear], textured(SQUARE), spare=True)
                counts["spare"] = counts.get("spare", 0) + 2

    metal = None
    tabs = ("matel tab_Metal_0", "matel tab bottom_Metal_0") if posts else ("matel tab_Metal_0",)
    for name, part in zip(tabs, (CAP, BASE)):
        tab = parts[name]
        metal = metal_paint(tab["material"], part)
        for k in range(0, len(tab["corners"]), 3):
            corners = tab["corners"][k:k + 3]
            mesh.triangle([(place(tab["points"][i]), turn(tab["normals"][i]), (0, 0)) for i in corners], metal)
        counts[name] = len(tab["corners"]) // 3

    # The posts take the towers' square footprint and centre line, rounded to millimetres.
    low, high = sides[1]
    post_x = round((low[0] + high[0]) / 2 - centre_x, 3)
    half = round(max(high[0] - low[0], high[2] - low[2]) / 2, 3)
    footprint = []
    if posts:
        if scale != 1:
            raise ValueError("Only the road gate has posts")
        paint = metal_paint(parts["matel tab_Metal_0"]["material"], POST)
        top = (min(p[1] for p in parts["matel tab_Metal_0"]["points"]) + lift) * scale
        for x in (-post_x, post_x):
            footprint.append({"x": x, "z": 0, "halfSize": half, "bottom": -POST_DEPTH, "top": round(top, 3)})
            # Four walls with outward normals. The top tab covers the top and the ground hides the bottom.
            for nx, nz in ((1, 0), (0, 1), (-1, 0), (0, -1)):
                # Corners run anticlockwise seen from outside: along the wall, then up.
                a = (x + half * (nx - nz), half * (nz + nx))
                b = (x + half * (nx + nz), half * (nz - nx))
                corners = [(a[0], -POST_DEPTH, a[1]), (b[0], -POST_DEPTH, b[1]),
                           (b[0], top, b[1]), (a[0], top, a[1])]
                mesh.quad(corners, (nx, 0, nz), [(0, 0)] * 4, paint)
        counts["posts"] = 16

    # The window spans the towers' inner faces, from the base to the board's lower edge.
    window = {"halfWidth": round((post_x - half) * scale, 3), "bottom": 0,
              "top": round((board_low[1] + lift) * scale, 3)}
    # Enough columns for the widest opening, which widens each band by the half width it adds.
    added = widest / 2 - window["halfWidth"]
    baked = max(round((shown * b["column"] + added) / b["column"]) for b in band.values()) + 1
    spares(baked)
    layout = {"label": [round(band[side]["label"], 4) for side in (-1, 1)],
              "column": [round(band[side]["column"], 5) for side in (-1, 1)], "columns": shown, "baked": baked}
    return mesh, window, footprint, layout, counts


def crossings(triangles, axis, value):
    """Where a line through the triangles at `value` along `axis` (0 for X, 1 for Y) meets them, along the other."""
    other = 1 - axis
    found = []
    for triangle in triangles:
        hits = []
        for a, b in ((0, 1), (1, 2), (2, 0)):
            p, q = triangle[a], triangle[b]
            if (p[axis] - value) * (q[axis] - value) <= 0 and p[axis] != q[axis]:
                t = (value - p[axis]) / (q[axis] - p[axis])
                hits.append(p[other] + t * (q[other] - p[other]))
        if hits:
            found.append((min(hits), max(hits)))
    return found


def inside(point, triangle):
    """Whether a point lies inside a triangle, both seen along Z."""
    (ax, ay, _), (bx, by, _), (cx, cy, _) = triangle
    x, y = point
    sides = [(bx - ax) * (y - ay) - (by - ay) * (x - ax), (cx - bx) * (y - by) - (cy - by) * (x - bx),
             (ax - cx) * (y - cy) - (ay - cy) * (x - cx)]
    return all(v > 0 for v in sides) or all(v < 0 for v in sides)


def grooves(plane):
    """Quads closing the gaps between pieces of one flat face: two nearly vertical edges of its outline that stand
    less than 5 centimetres apart, over the heights both span. Each quad's corners run up one edge and down the
    other."""
    uses = {}
    for triangle in plane:
        for a, b in ((0, 1), (1, 2), (2, 0)):
            key = frozenset((tuple(round(v, 4) for v in triangle[a]), tuple(round(v, 4) for v in triangle[b])))
            uses.setdefault(key, []).append((triangle[a], triangle[b]))
    upright = [tuple(sorted(edges[0], key=lambda p: p[1])) for edges in uses.values() if len(edges) == 1]
    upright = [(p, q) for p, q in upright if abs(q[0] - p[0]) < .1 * (q[1] - p[1])]

    def across(edge, y):
        (x0, y0, z), (x1, y1, _) = edge
        return (x0 + (x1 - x0) * (y - y0) / (y1 - y0), y, z)

    quads = []
    for one, other in combinations(upright, 2):
        low, high = max(one[0][1], other[0][1]), min(one[1][1], other[1][1])
        if high - low > .1 and 0 < abs(across(one, low)[0] - across(other, low)[0]) < .05:
            quads.append([across(one, low), across(other, low), across(other, high), across(one, high)])
    return quads


def arch(parts):
    """The inflatable arch, 12 metres wide, with its feet below the water line and its print mapped flat."""
    (part,) = parts.values()
    low, high = bounds(part["points"])
    scale = WATER_WIDTH / (high[0] - low[0])
    centre_x, centre_z = (low[0] + high[0]) / 2, (low[2] + high[2]) / 2

    # Below the water line the source has only the bevelled rims of the feet. Lowering them stretches the legs' walls,
    # each of which runs between two edges parallel to Z, so every face stays flat.
    rims = [(p[1] - low[1]) * scale - WATER_DRAFT for p in part["points"]]
    if max(y for y in rims if y < 0) > -WATER_DRAFT + .05:
        raise ValueError("Expected nothing but the feet's rims below the water line")

    def place(p):
        y = (p[1] - low[1]) * scale - WATER_DRAFT
        return ((p[0] - centre_x) * scale, y - (FOOT_DEPTH - WATER_DRAFT if y < 0 else 0), (p[2] - centre_z) * scale)

    width, height = WATER_ATLAS
    k = WATER_PRINT["pixelsPerMetre"]
    ox, oy = WATER_PRINT["origin"]
    mesh = Mesh()
    paint = (255, 255, 255, round(255 * part["material"].get("roughnessFactor", 1)), 0, tag(ARCH))
    faces = []
    for t in range(0, len(part["corners"]), 3):
        corners = part["corners"][t:t + 3]
        normal = [sum(part["normals"][i][j] for i in corners) for j in range(3)]
        length = sum(v * v for v in normal) ** .5
        faces.append((corners, [place(part["points"][i]) for i in corners], [v / length for v in normal]))
    front = [points for _, points, normal in faces if normal[2] > .9]
    # The legs are separate pieces whose inner walls run on up inside the crossbar, where their edges show through
    # the faces. A wall whose outward side, 5 centimetres out, is still inside the arch faces into it.
    # A flat projection, as in the source, but each face reads left to right from its own side. Seen from -Z,
    # which faces oncoming vehicles, the print runs along -X. The edges take the +Z face's mapping.
    def mapping(p, direction):
        return (ox + direction * p[0] * k) / width, (oy - p[1] * k) / height

    triangles = []
    for corners, points, normal in faces:
        if abs(normal[2]) < .9:
            across = (normal[0] ** 2 + normal[1] ** 2) ** .5
            probe = [sum(p[j] for p in points) / 3 + .05 * normal[j] / across for j in (0, 1)]
            if any(inside(probe, triangle) for triangle in front):
                continue
        triangles.append(points)
        direction = -1 if normal[2] < -.9 else 1
        mesh.triangle([(p, part["normals"][i], mapping(p, direction)) for p, i in zip(points, corners)], paint)
    # Where the crossbar meets each leg, the two pieces' face edges stand apart and only the bevels just removed
    # lined the gap between them. Flat quads close it on both faces.
    closed = 0
    for side in (1, -1):
        for quad in grooves([points for _, points, normal in faces if normal[2] * side > .9]):
            (ax, ay, _), (bx, by, _), (cx, cy, _) = quad[:3]
            if ((bx - ax) * (cy - ay) - (by - ay) * (cx - ax)) * side < 0:
                quad = quad[::-1]
            mesh.quad(quad, (0, 0, side), [mapping(p, side) for p in quad], paint)
            triangles.extend((quad[:3], [quad[0], quad[2], quad[3]]))
            closed += 1
    if closed != 4:
        raise ValueError(f"Expected a groove beside each leg on both faces of the arch, found {closed}")

    # The print must stay inside the top band, clear of the next one.
    used = [(ox + sign * p[0] * k, oy - p[1] * k) for p in mesh.points for sign in (-1, 1)]
    if min(u for u, _ in used) < 4 or max(u for u, _ in used) > width - 4 or min(v for _, v in used) < 4 \
            or max(v for _, v in used) > height / len(ROLES) - 4:
        raise ValueError("The arch no longer fits its print band")
    # The opening, seen along Z: lines up through it meet the crossbar's underside first, and lines across it meet
    # the legs' inner faces. A first pass finds the legs below the crossbar's middle. Then both are sampled every
    # centimetre over the window, from the water line to just under the crossbar, and the narrowest values kept.
    def legs(top):
        heights = [i / 100 for i in range(int(top * 100))]
        return min(min(abs(v) for span in crossings(triangles, 1, y) for v in span) for y in heights)

    def underside(inner):
        columns = [i / 100 for i in range(-int(inner * 100), int(inner * 100) + 1)]
        return min(min(low for low, _ in crossings(triangles, 0, x)) for x in columns)

    crossbar = underside(legs(min(low for low, _ in crossings(triangles, 0, 0)) - .05))
    inner = legs(crossbar - .005)
    window = {"halfWidth": round(inner, 3), "bottom": 0, "top": round(crossbar, 3)}
    return mesh, window


def extent(mesh):
    """How far the gate reaches from its origin at the mesh's own size, in metres. The extent reaches down to the
    window's bottom edge at least, which the air gate's board hangs above."""
    low, high = bounds(mesh.points)
    return {"halfWidth": round(max(-low[0], high[0]), 3), "bottom": round(min(0, low[1]), 3),
            "top": round(high[1], 3), "halfDepth": round(max(-low[2], high[2]), 3)}


def opening(name):
    """The range of a kind's opening as gates.json records it."""
    (narrowest, widest), (lowest, highest) = SIZES[name]
    found = {"width": [narrowest, widest], "height": [lowest, highest]}
    if name == "water":
        found["stretch"] = ARCH_STRETCH
    return found


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("gantry", type=Path, help='"Finish Line" by Kemal Çolak (finish_line.zip)')
    parser.add_argument("arch", type=Path, help='"Davita Finish-Line 2020" by BrandXR (davita_finish-line_2020.zip)')
    args = parser.parse_args()
    licenses = {}
    sources = {}
    for name, path in (("gantry", args.gantry), ("arch", args.arch)):
        with tempfile.TemporaryDirectory() as tmp, zipfile.ZipFile(path) as archive:
            folder = Path(tmp)
            for member in ("scene.gltf", "scene.bin"):
                archive.extract(member, folder)
            sources[name] = primitives(folder)
            licenses[name] = archive.read("license.txt")

    # The road gate's base plates rest on the ground. The air gate keeps the towers' feet at its origin.
    lift = -min(p[1] for p in sources["gantry"]["matel tab bottom_Metal_0"]["points"])
    road, road_window, posts, road_board, counts = gantry(sources["gantry"], 1, lift, True, SIZES["road"][0][1])
    air, air_window, _, air_board, _ = gantry(sources["gantry"], AIR_SCALE, 0, False, SIZES["air"][0][1])
    water, water_window = arch(sources["arch"])
    kinds = {
        "road": {"mesh": "road.mesh.bin.gz", "window": road_window, "posts": posts, "extent": extent(road),
                 "size": opening("road"), "board": road_board, "print": BOARD_PRINT},
        "water": {"mesh": "water.mesh.bin.gz", "window": water_window, "posts": [], "extent": extent(water),
                  "size": opening("water"), "print": WATER_PRINT},
        "air": {"mesh": "air.mesh.bin.gz", "window": air_window, "posts": [], "extent": extent(air),
                "size": opening("air"), "board": air_board, "print": BOARD_PRINT},
    }
    contract = {"version": 1, "roles": list(ROLES), "kinds": kinds,
                "atlas": {"roles": {role: [0, i / len(ROLES)] for i, role in enumerate(ROLES)}}}
    ASSETS.mkdir(parents=True, exist_ok=True)
    for name, mesh, size in (("road", road, BOARD_ATLAS), ("water", water, WATER_ATLAS), ("air", air, BOARD_ATLAS)):
        (ASSETS / kinds[name]["mesh"]).write_bytes(mesh.data(*size))
        print(f"{name}: {mesh.triangles} triangles, {len(mesh.ids)} vertices")
    print("road source:", json.dumps(counts))
    (ASSETS / "gates.json").write_text(json.dumps(contract, indent="\t") + "\n")
    (ASSETS / "finish-line-license.txt").write_bytes(licenses["gantry"])
    (ASSETS / "arch-license.txt").write_bytes(licenses["arch"])
    subprocess.run(["node", str(ROOT / "scripts/generate-terrain-gates.mjs")], check=True)


if __name__ == "__main__":
    main()
