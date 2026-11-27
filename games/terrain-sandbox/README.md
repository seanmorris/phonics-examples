# Landscape Sandbox

An offline authoring and flight sandbox for the optional `terrain-phonics` package. Fly over procedural mountains, canyons, or coasts; shape the ground; grade roads; adjust the sun and atmosphere; drive a car, ride a buoyant watercraft, or fly an F5, and race each of them through gates you place.

See [the controls guide](docs/index.md) and [landscape authoring](docs/authoring.md).

The soundtrack streams from the `music/` directory next to `game.phar`. To change the rotation, edit `music/playlist.txt` and run `node scripts/generate-terrain-music.mjs` from the repository root. The generator uses FFmpeg and ffprobe to read the MP3s’ ID3 credits and embedded pictures. The application and site builds copy the streams and thumbnails alongside the archive.

The terrain, camera, editing, and simulation run in PHP. Browser code provides accessible authoring controls, file dialogs, input collection, and lifecycle integration. The example does not require Tiled or a network connection.

Each vehicle is a Phonics entity holding its physics object, `RacerMotion`, and its own `Race`. To add another racer, register those components together and pass its movement system’s name in the session’s `movementSystems` list. On each fixed step, call `RacerMotion::publish($before, $after)` with the two vehicle poses. Pass `relocated: true` for a teleport. A change in the car’s recovery counter also skips the crossing. The race system runs after movement and times every racer against the shared course. The HUD follows the local driver.

Run `node scripts/bench-terrain.mjs` from the examples checkout with the preview running to measure cold and warm road clicks and flight frame intervals. The report includes the GPU, drawing-buffer size, time to each confirmed marker, and time spent in the picker. Set `TERRAIN_URL` for another preview or `VIEW_WIDTH` and `VIEW_HEIGHT` for a different browser viewport.

The GPU generates elevation textures in concentric levels around the camera. At each level, moving into a new grid cell refreshes only the newly exposed strips. The F5 local map uses resident detail and the wide chart uses the world overview. Both maps use the current palette and water level.

Use the Landscape tab to copy a preset into the landform controls, then edit the coefficients and generate. Select a surround to extend the scenery beyond the authored area. The Surface tab can store an ordered stack of color and lighting-relief layers with shared fields, height, slope, noise and parent masks. Paint inks override this stack on the ground and minimap. The save file carries these settings and the existing terrain edit history.

Read `state.renderer` on `document.querySelector('canvas[data-terrain-sandbox]').terrainSandboxDebug` to see the resident levels, texture bytes, texels generated and edit upload bytes. Run `node scripts/bench-terrain-stream.mjs` for whole-frame percentiles and vertex transfers, with `SOFTWARE_GL=1` to select SwiftShader.

The status object reports the race gates in `state.course`, each with its opening's `width` and `height` in meters, and the race in `state.race`. `state.race.reach` is null while the vehicle can pass the next gate's opening; otherwise its `message` says why not, and for the F5 its `needed` gives the opening height in meters the F5 needs. `state.gateSizes` gives each kind's own `width` and `height`, its `widths` and `heights` ranges, and the water arch's `stretch`. `state.renderer.gateDraws` counts the gates the last frame drew, after culling by distance and by the view, and `state.renderer.gateMs` is the time it spent on them in milliseconds. While you drive, ride or fly, `state.duck.offset` gives the meters [x, y, z] by which the drawn chase eye moved to clear a gate at the last step, and `state.duck.passages` the passage it holds past each nearby gate by id: `through`, `over` or `under`. `state.camera` stays the eye the vehicle's camera chose.

The Testarossa's brake and reversing lamps follow the pedals and the handbrake, and its tail lamps light with the headlights. **L** and a gamepad's **X** step the headlights through Auto, On and Off, and the **Car headlights** select sets them; the Drive on land section of [the controls guide](docs/index.md) gives the rules. While the car is driven with its headlights lit, day or night, `VehicleModel::spotLights()` builds one light per headlamp from the pose each frame draws, and `application.php` passes them to the terrain package's `Scene::setSpotLights()` whenever they change. In every other case the application clears them. By day the beams keep their physical brightness, which barely shows. While the switch is not Off and the sun is up, `Session` looks from just above the car toward the sun four times a second with a budgeted `RayQuery`. Terrain in the way lights Auto's headlights at once. It also eases `Session::lampAdaptation()` toward `shadeLampGain()`, which is `SHADE_LAMP_GAIN` (1,000, a tenth of the night gain) scaled by how much light the shadow takes away (`Atmosphere::shadeAdaptation()`). `application.php` passes that gain with the lights. `Session::SHADE_RELEASE` seconds of sun turn Auto's headlights off and let the gain fall back to one. `state.lampAdaptation` reports it. A paused car takes no steps, so `Session::input()` snaps the headlamps and pods to a changed switch at once (`CarLamps::snap()`). `VehicleModel::BEAM_INTENSITY`, `BEAM_RANGE`, `BEAM_INNER` and `BEAM_OUTER` set each beam's intensity, range and cone; the intensity scales with how far the headlamps have lit, and stays at zero until the rising pods lift the headlamps clear of the nose (`BEAM_CLEAR`, which `tests/lamps.php` checks against the mesh). `state.headlights` reports the switch and `state.headlightsLit` whether it lights them now. While driving, `state.lamps` gives each lamp's level and the pods' opening. `state.renderer.spotLights` counts the lights set.

The Testarossa has a soft contact shadow, darkest between its wheels and fainter around its bumpers and sills. Its strength depends on clearance above the local ground. It stays at full strength through 25 cm, fades to half strength at 1.625 m, and stops drawing at 3 m. The shadow follows the terrain triangles already on the GPU. While those triangles stream in, a small grid of cached terrain heights supplies the fallback.

The Surface tab edits a PHP-owned `SurfaceLayers` recipe. PHP sends the available channels, fields, masks and parameter ranges through the `terrainSurface` bridge. Before committing a new structure, the scene links the ground, headlight and minimap shaders. Numeric edits reuse the linked programs. Version-five saves carry the committed recipe, its origin and the paint inks alongside the landscape edit history.

## Pause menu

Escape, Start or the pause button opens `SandboxMenu`, which drives the engine's `Menu`, `MenuInput` and `MenuRenderer` from the sandbox's own input hook. The engine's `PauseMenu` adapter would take over that hook, which already carries the page's input bridge. Menu rows send the same actions and values the page does, so Session checks them. The Audio page reads and sets levels through `terrainAudioGet` and `terrainAudioSet` in `browser/car-audio.mjs`, which keeps the master, engine and tire buses and remembers their levels in the browser. The soundtrack answers for the song list, the song and the music level through `module.terrainMusic`, an object with `get(name)` and `set(name, value)`, and also hears the master level.

## Terrain streaming measurements

Initial clipmap comparison, before the wider canyon grids. Chromium with SwiftShader, a 640 × 400 viewport,
balanced detail and 40 animation callbacks per phase. The camera starts at 750 m and the moving phase uses forward flight at 300 m/s. Coverage measures from submitting the load until terrain is first visible. The baseline is the previous shared preview, including its worker. The GPU result uses the new terrain package in the private preview.

| Preset | First coverage, ms before → after | Stationary p95 / p99, ms before → after | Moving p95 / p99, ms before → after | Moving vertex upload, MB before → after |
| --- | ---: | ---: | ---: | ---: |
| Coast | 503 → 479 | 467 / 533 → 300 / 400 | 533 / 533 → 217 / 333 | 5.56 → 0 |
| Mountain | 678 → 365 | 467 / 500 → 267 / 400 | 633 / 683 → 317 / 417 | 5.37 → 0 |
| Canyon v1 | 645 → 243 | 500 / 533 → 283 / 350 | 550 / 550 → 317 / 417 | 5.27 → 0 |
| Canyon v2 | 588 → 223 | 367 / 450 → 283 / 350 | 500 / 567 → 267 / 433 | 4.79 → 0 |

Balanced detail retained about 2.4 MB of elevation and material textures during this run. The old pipeline held 11.2–12.5 MB of terrain meshes at the end of moving samples. Browser CPU rendering limits these frame rates. Discrete GPU timing, the lowest detail setting and matching projected error between the two renderers have yet to be measured.

## Watercraft model

The watercraft uses “Jetski” by Futurealiti. The sandbox uses a simplified mesh and a resized dashboard texture. To regenerate them, run `python3 scripts/import-terrain-jetski.py /path/to/jetski.zip` with gltfpack 0.18 and ImageMagick on the path. [Original model](https://sketchfab.com/3d-models/jetski-15e79c65f67a468592cdc2ac1f5b389b) · [Author](https://sketchfab.com/futurealiti) · [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/)

## Car model

The flat twelve fires six cylinders per bank every two crank revolutions. The near bank's six firings carry the note, 47.5 Hz at idle and 400 Hz at redline. Within each bank, cylinders alternate richer and leaner, which adds a growl an octave below. PHP generates 64 cycles of rounded discharges with small differences from cycle to cycle. The browser plays that pressure at gearbox RPM through two tailpipes whose resonances stay fixed in hertz, a bass shelf and a muffler that rounds off everything above the note. A longer exhaust passage reinforces the low growl. Opening the throttle brings out upper harmonics from the same cylinder pulses. Dry pavement adds tire squeal in proportion to tread slip. Rebuild the engine audio with `php scripts/generate-terrain-engine.php`. [Tire recording and credits](assets/car-audio/license.txt)

The car uses “Ferrari-Testarossa-84-low-poly” by XGLOL235. The sandbox scales the model to 4.48 meters, bakes its materials, and animates the wheels while driving. The importer also tags the tail, brake, reversing, bumper and head lamps and the covers of the pop-up headlights, so the shaders can light the lamps and open the pods, and writes the pods' hinge, the 55-degree opening that the model's own animation gives them and the headlamp beams to `assets/testarossa/lamps.json`. Rebuild it with `python3 scripts/import_terrain_vehicle.py --vehicle testarossa /path/to/ferrari-testarossa-84-low-poly.zip` and gltfpack 0.18. [Original model](https://sketchfab.com/3d-models/ferrari-testarossa-84-low-poly-2eb128eab34a4c25bfcdb2d75152d31a) · [Author](https://sketchfab.com/XGLOL235) · [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/)

## Aircraft model

The aircraft uses “F5” by manilov.ap. The sandbox uses a gear-up mesh with the belly tank removed, scaled to 15.6 meters, centered on its center of gravity, and packs the original paint textures into one atlas. The importer also tags the ailerons, stabilators and rudder and writes their hinges to `assets/f5/surfaces.json`, so the vertex shader can move them, and closes the holes in the source mesh that they would uncover. Rebuild it with `python3 scripts/import_terrain_vehicle.py --vehicle f5 /path/to/f5.zip`, gltfpack 0.18, and ImageMagick. [Original model](https://sketchfab.com/3d-models/f5-8089d66a8a8748b2a4b049b3c06f6a02) · [Author](https://sketchfab.com/manilov.ap) · [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/)

## Generated combat sounds

| Sound | Asset |
| --- | --- |
| Enemy Vulcan fire hitting the player in the F5 | [f5-enemy-vulcan-hit.wav](assets/car-audio/f5-enemy-vulcan-hit.wav) |
| Projectile explosion | [burst.wav](assets/car-audio/burst.wav) |

Regenerate these effects with `php scripts/generate-terrain-impacts.php`. The projectile explosion is a dry recording. The landscape adds echoes during play. As the camera moves, each arrival tracks its world position for stereo pan, far-ear delay, distance attenuation, muffling and Doppler pitch.

## Race gate models

The road gate uses the board and metal tabs of “Finish Line” by Kemal Çolak. Solid posts on the 0.8-meter footprint of its lattice towers replace the towers; each one's foot reaches a meter below the ground under it, so sloping ground never shows a gap. A flat label panel replaces the 3D lettering, and the board's back carries a copy of its printed face. The road board extends upward from the opening at twice the source height. Its lettering and checker pattern double in both dimensions. On narrow gates, the lettering scales down proportionally to leave room for the checks. The air gate uses the source proportions at three times the original size, with the board and tabs suspended above the flight path. The source has 29,658 triangles. At their default sizes, the road and air gates have 1,328 and 1,408 visible triangles respectively. Their meshes hold 4,344 and 5,760 triangles, including checker columns that unfold as the gate widens. [Original model](https://sketchfab.com/3d-models/finish-line-70aecb6bac234266bdebd22322396caa) · [Author](https://sketchfab.com/kemalcolak) · [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/)

The water gate uses the inflatable arch of “Davita Finish-Line 2020” by BrandXR, scaled to 12 meters wide with its opening 3.6 meters high. Its feet reach 2.7 meters below the water line: the gate rides at the wave height at its center, and at the highest choppiness, 300%, the water at a foot can stand 2.5 meters lower. The source builds the legs and the crossbar as separate pieces, so the importer removes the 36 faces hidden inside the crossbar and closes the 3.5-centimeter grooves where the pieces meet, leaving 912 of the 940 triangles. Its prints are new: the importer never extracts the archive's texture. [Original model](https://sketchfab.com/3d-models/davita-finish-line-2020-003d816301084f719897503bdb4ffbc3) · [Author](https://sketchfab.com/brandxr) · [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/)

Rebuild the three meshes, `assets/gates/gates.json` and the license files with `python3 scripts/import_terrain_gates.py /path/to/finish_line.zip /path/to/davita_finish-line_2020.zip`. The importer reads only geometry from the archives, then runs `node scripts/generate-terrain-gates.mjs`, which paints the start, checkpoint, finish and start / finish prints into each mesh's atlas, one band per role. `npm run generate` also runs the generator, and `npm run check:generated` verifies the committed prints. `gates.json` records each gate's window, posts and extent in meters at its own size, the range of opening sizes it takes, the layout of its board's checker bands and the texture offset of each role's print.

A placed gate's opening takes any width and height in its kind's range. The importer tags every vertex with the part it belongs to, and `assets/vehicles/model.vert` moves each part for the size: the posts move to the opening's sides and rise to its top, the board sits on top with its label centered between two bands of whole checker columns, and the water arch scales, at most twice as much one way as the other. The road gate's posts and base plates also follow the ground under each post. The lettering is Barlow Semi Condensed Bold from `games/php-billiards/art/typography`, under the SIL Open Font License 1.1.

## F5 balloon practice assets

The balloon model is *CC0 - Balloon* by plaggy. Its supplied license identifies it as CC BY 4.0. The game projectile model is *Heatseeker Air-Air missile* by Martini-SF, licensed under CC BY-NC 4.0. The importer scales the meshes and bakes the material and texture data. [Balloon credit](assets/balloon/license.txt) · [Projectile credit](assets/projectile/license.txt)

The cockpit draws its instruments and labels with Canvas text and shapes in a palette inspired by *AirForce Delta*. Rebuild the practice assets with `python3 scripts/import_terrain_practice.py cc0_-_balloon.zip f5-game-projectile.zip`.
