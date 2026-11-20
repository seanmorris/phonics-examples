"""Bake the supplied balloon, game projectile and HUD labels for the F5 practice demo."""
import argparse
import gzip
import json
import struct
import subprocess
import zipfile
from pathlib import Path

from import_terrain_vehicle import ASSETS, VERTEX, accessor, srgb


def bake(path, name, height_or_length):
    with zipfile.ZipFile(path) as archive:
        scene = json.loads(archive.read("scene.gltf"))
        binary = archive.read("scene.bin")
        # The projectile's three pieces share one bind frame (+Z forward).
        # The balloon's authored mesh is Z up. Both use one base-color map.
        vertices = bytearray()
        indices = bytearray()
        parts = [part for mesh in scene["meshes"] for part in mesh["primitives"]]
        bounds = [scene["accessors"][p["attributes"]["POSITION"]] for p in parts]
        low = [min(b["min"][i] for b in bounds) for i in range(3)]
        high = [max(b["max"][i] for b in bounds) for i in range(3)]
        center = [(a + b) / 2 for a, b in zip(low, high)]
        scale = height_or_length / (high[2] - low[2])
        for part in parts:
            attrs = part["attributes"]
            values = lambda key: accessor(scene, binary, attrs[key])
            offset = len(vertices) // VERTEX.size
            pbr = scene["materials"][part["material"]]["pbrMetallicRoughness"]
            rgb = [srgb(c) for c in pbr.get("baseColorFactor", [1, 1, 1])[:3]]
            for pos, norm, uv in zip(values("POSITION"), values("NORMAL"), values("TEXCOORD_0")):
                x, y, z = [(v - c)*scale for v, c in zip(pos, center)]
                if name == "balloon":
                    x, y, z = x, z, -y
                    norm = norm[0], norm[2], -norm[1]
                vertices.extend(VERTEX.pack(x, y, z, *(round(max(-1, min(1,n))*32767) for n in norm),
                    *(round(max(0,min(1,v))*65535) for v in uv), *rgb, 180, 32, 255))
            for (i,) in accessor(scene, binary, part["indices"]):
                indices.extend(struct.pack("<H", offset+i))
        image = scene["textures"][pbr["baseColorTexture"]["index"]]["source"]
        pixels = subprocess.check_output(["convert", "png:-", "-resize", "512x512!", "-depth", "8", "RGBA:-"],
                                        input=archive.read(scene["images"][image]["uri"]))
        folder = ASSETS / name
        folder.mkdir(exist_ok=True)
        header = struct.pack("<4I", len(vertices)//VERTEX.size, len(indices)//2, 512, 512)
        (folder / "mesh.bin.gz").write_bytes(gzip.compress(header+vertices+indices+pixels, mtime=0))
        (folder / "license.txt").write_bytes(archive.read("license.txt"))


def labels(path):
    # All source pixels are opaque. Isolate the green ink in three clean labels, preserving dark lettering.
    source = subprocess.check_output(["convert", str(path), "-depth", "8", "RGB:-"])
    if len(source) != 1280*720*3:
        raise ValueError("Expected the 1280 by 720 HUD extraction")
    pixels = bytearray(224 * 48 * 4)
    for sx, sy, w, h, dy in [(20,655,216,16,0),(280,655,168,16,16),(91,153,88,16,32)]:
        for y in range(h):
            for x in range(w):
                i = ((sy+y)*1280+sx+x)*3
                r, g, b = source[i:i+3]
                j = ((dy+y)*224+x)*4
                pixels[j:j+4] = bytes((r,g,b,255 if g > max(r,b)*1.12 and g > 24 else 0))
    subprocess.run(["convert", "-size", "224x48", "-depth", "8", "RGBA:-", "-strip",
                    "PNG32:"+str(ASSETS / "f5" / "hud-labels.png")], input=pixels, check=True)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ["balloon", "projectile", "hud"]:
        parser.add_argument(name, type=Path)
    args = parser.parse_args()
    bake(args.balloon, "balloon", 20)
    bake(args.projectile, "projectile", 4)
    labels(args.hud)
