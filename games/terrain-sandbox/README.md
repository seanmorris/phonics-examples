# Landscape Sandbox

An offline authoring and flight sandbox for the optional `terrain-phonics` package. Fly over procedural mountains, canyons, or coasts; shape the ground; grade roads; adjust the sun and atmosphere; and test a buoyant watercraft.

See [the controls guide](docs/index.md) and [landscape authoring](docs/authoring.md).

The terrain, camera, editing, and simulation run in PHP. Browser code provides accessible authoring controls, file dialogs, input collection, and lifecycle integration. The example does not require Tiled or a network connection.

Run `node scripts/bench-terrain.mjs` from the examples checkout with the preview running to measure cold and warm road clicks and flight frame intervals. The report includes the GPU, drawing-buffer size, time to each confirmed marker, and time spent in the picker. Set `TERRAIN_URL` for another preview or `VIEW_WIDTH` and `VIEW_HEIGHT` for a different browser viewport.

## Watercraft model

The watercraft uses “Jetski” by Futurealiti. The sandbox uses a simplified mesh and a resized dashboard texture. To regenerate them, run `python3 scripts/import-terrain-jetski.py /path/to/jetski.zip` with gltfpack 0.18 and ImageMagick on the path. [Original model](https://sketchfab.com/3d-models/jetski-15e79c65f67a468592cdc2ac1f5b389b) · [Author](https://sketchfab.com/futurealiti) · [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/)
