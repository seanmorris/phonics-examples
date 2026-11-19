# Landscape Sandbox

An offline authoring and flight sandbox for the optional `terrain-phonics` package. Fly over procedural mountains, canyons, or coasts; shape the ground; grade roads; adjust the sun and atmosphere; drive a car, ride a buoyant watercraft, or fly an F5.

See [the controls guide](docs/index.md) and [landscape authoring](docs/authoring.md).

The terrain, camera, editing, and simulation run in PHP. Browser code provides accessible authoring controls, file dialogs, input collection, and lifecycle integration. The example does not require Tiled or a network connection.

Run `node scripts/bench-terrain.mjs` from the examples checkout with the preview running to measure cold and warm road clicks and flight frame intervals. The report includes the GPU, drawing-buffer size, time to each confirmed marker, and time spent in the picker. Set `TERRAIN_URL` for another preview or `VIEW_WIDTH` and `VIEW_HEIGHT` for a different browser viewport.

While the view moves, the renderer selects terrain detail at most ten times a second. To compare frame rates without that limit, run `document.querySelector('canvas').terrainSandboxDebug.values({selectionInterval: 0})` in the browser console, or pass another spacing in seconds up to 1. The same object's `state.selectionInterval` reports the value in use and `state.renderer.selections` counts selections. The value has no control in the panel and is not saved with a landscape.

## Watercraft model

The watercraft uses “Jetski” by Futurealiti. The sandbox uses a simplified mesh and a resized dashboard texture. To regenerate them, run `python3 scripts/import-terrain-jetski.py /path/to/jetski.zip` with gltfpack 0.18 and ImageMagick on the path. [Original model](https://sketchfab.com/3d-models/jetski-15e79c65f67a468592cdc2ac1f5b389b) · [Author](https://sketchfab.com/futurealiti) · [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/)

## Car model

The car uses “Ferrari-Testarossa-84-low-poly” by XGLOL235. The sandbox scales the model to 4.48 metres, bakes its materials, and animates the wheels while driving. Rebuild it with `python3 scripts/import_terrain_vehicle.py --vehicle testarossa /path/to/ferrari-testarossa-84-low-poly.zip` and gltfpack 0.18. [Original model](https://sketchfab.com/3d-models/ferrari-testarossa-84-low-poly-2eb128eab34a4c25bfcdb2d75152d31a) · [Author](https://sketchfab.com/XGLOL235) · [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/)

## Aircraft model

The aircraft uses “F5” by manilov.ap. The sandbox uses a gear-up mesh scaled to 15.6 metres and packs the original paint textures into one atlas. Rebuild it with `python3 scripts/import_terrain_vehicle.py --vehicle f5 /path/to/f5.zip`, gltfpack 0.18, and ImageMagick. [Original model](https://sketchfab.com/3d-models/f5-8089d66a8a8748b2a4b049b3c06f6a02) · [Author](https://sketchfab.com/manilov.ap) · [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/)
