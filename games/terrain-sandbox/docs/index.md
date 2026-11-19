# Landscape Sandbox

Landscape Sandbox is a place to explore and edit procedural terrain. The default landscape is about 32 kilometres square. Nearby geometry becomes more detailed as you approach it, while the physical ground remains independent of visual detail.

## Fly over the landscape

Right-drag on the view to look around. Use **W/A/S/D** to move horizontally and **Q/E** to descend or climb. Hold **Shift** to fly at 300 metres per second. The Flight slider changes the ordinary movement speed.

**Home view** returns to the starting viewpoint. **H** folds or opens the controls. **Fullscreen** enlarges the whole sandbox, including its controls.

Choose a preset and seed, then select **Generate** to create a new landscape. Generation replaces the current landscape and its edits, so save your work first. A seed reproduces its landscape under the saved generator version.

## Watch the atmosphere

Sun azimuth moves the sun around the horizon; elevation raises or lowers it. Haze controls aerosols, and Exposure adjusts the displayed brightness. Enable **Animate the sun** to see the light move over time.

The sun, sky, distant landscape haze, and water use the same atmosphere and lighting settings. Clouds are outside this example.

## Adjust the water

**Water level** raises or lowers the surface immediately, in metres. **Choppiness** controls the height and steepness of the waves the jet ski rides. At 0% the surface is calm between wakes, 100% gives the original swell, and 300% triples its height. Both controls work while paused and are included in saved landscapes.

Use **Water color** to tint the surface and underwater haze. Raise **Transparency** to see through the surface. Lower the fog density for a longer view through the water, or raise it for a murkier lake. **Fog density · coarse** covers the full range from 0 to 1 per metre. **Fog density · fine** adjusts within 0.005 of the coarse setting in increments of 0.0001 per metre. **Reflectivity** controls sky and sun reflections, strongest toward the horizon. **Shore foam** controls the white fringe in the shallows. These settings take effect while riding or paused and travel with a saved landscape.

## Ride the water

Select **Board watercraft**, or press **B**, to find nearby water deep enough for the craft. Use **W/S** for throttle and reverse and **A/D** to steer. The craft responds to the same large waves shown by the renderer and leaves local wake disturbances.

Select **Return to flight**, or press **F**, to explore and edit again. The craft is an arcade physics demonstration, not a complete racing game.

## Pause and save

**P** pauses waves, the moving sun, and watercraft physics. Camera movement and authoring remain available. Once terrain preparation settles, an unchanged paused view stops submitting rendered frames.

**Save file** downloads a JSON document containing the landscape recipe, committed edits, environment controls, and camera. **Load file** opens a file picker. Files are local to your device; the sandbox does not upload them.

## Choose detail

Economical, Balanced, and Detailed adjust terrain mesh density. They do not change collision heights or saved terrain. The diagnostic display reports rendered patches, mesh memory, generation time, and render submission time. Render submission measures how long the application spends issuing the scene and presenting it. The interval between draws includes the rest of the frame and any idle time. During continuous animation, 16.7 ms between draws corresponds to 60 frames per second.

The sandbox gives terrain generation an eight-millisecond work budget while the view is stationary and reduces it to two milliseconds while flying, looking, or driving. Work stops when the requested terrain is ready; this budget does not make settled paused views redraw.

The first target is a desktop with hardware-accelerated WebGL2. Terrain and shaders also run with software WebGL, but comparable frame rates are not expected.
