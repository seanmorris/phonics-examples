# Landscape Sandbox

Landscape Sandbox is a place to explore and edit procedural terrain. The default landscape is about 32 kilometres square. Nearby geometry becomes more detailed as you approach it, while the physical ground remains independent of visual detail.

## Fly over the landscape

Right-drag on the view to look around. Use **W/A/S/D** to move horizontally and **Q/E** to descend or climb. Hold **Shift** to fly at 300 metres per second. The Flight slider changes the ordinary movement speed.

With a gamepad, the left stick moves, the right stick looks around, and the right and left triggers climb and descend. Hold the left stick pressed in to fly at 300 metres per second.

**Home view** returns to the starting viewpoint. **H** folds or opens the controls. **Fullscreen** enlarges the whole sandbox, including its controls.

Choose a preset and seed, then select **Generate** to create a new landscape. Generation replaces the current landscape and its edits, so save your work first. A seed reproduces its landscape under the saved generator version.

## Fly the F5

Press **J**, choose **Fly F5**, or press up on a gamepad's D-pad to start airborne at your current location, facing the same direction as the camera. The jet handles like AirForce Delta on the Dreamcast and offers its two control types. Choose one under **Jet controls**, or press **Back** on a gamepad, even in flight.

**Novice**, the default, banks into turns and levels itself. **A/D** or the left stick turn. **E/Q**, or pulling and pushing the stick, climb and dive. Let go and the jet levels its wings and nose.

**Expert** turns the jet about its own axes and holds whatever attitude you leave it in, so it can roll inverted and loop. **A/D** or the stick roll, **E/Q** or the stick pitch, and **Z/X** or **LB/RB** work the rudder. In a bank the wings support only part of the jet's weight unless you pull, so a banked jet turns slowly and one on its side slides towards the ground. Switching back to Novice keeps the jet's orientation and then levels it.

In both, hold **W** or the right trigger to speed up towards 260 metres per second, and **S** or the left trigger to slow towards 55. Let go and the jet returns to its 140 metre per second cruise, as the Dreamcast game does. A trigger sets a speed in proportion to how far you squeeze it. Pulling the stick back raises the nose; tick **Push the stick forward to climb** to reverse it. The sandbox remembers both choices in this browser. Saved landscapes do not carry them.

Both chase cameras keep the horizon level. The Novice camera looks along the jet's heading. The Expert camera swings in behind the nose, so it follows loops. It turns round quickly as the nose passes straight up or down. **P** or **Start** pauses the flight. Press **F**, or down on the D-pad, to leave the jet and explore from the chase camera's position. Reaching the map edge also returns you to free flight.

## Watch the atmosphere

Sun azimuth moves the sun around the horizon; elevation raises or lowers it. Haze controls aerosols, and Exposure adjusts the displayed brightness. Enable **Animate the sun** to see the light move over time.

As the sun drops below the horizon, stars appear and a blue ambient light picks out the terrain, water, and jet ski. **Night glow** sets the strength of the sky glow and this reflected light. At zero you can see the stars against a dark sky. Set **Sun elevation** to −12° for a quick night preview. The glow setting travels with the saved landscape.

## Adjust the water

**Water level** raises or lowers the surface immediately, in metres. **Choppiness** controls the height and steepness of the waves the jet ski rides. At 0% the surface is calm between wakes, 100% gives the original swell, and 300% triples its height. Both controls work while paused and are included in saved landscapes.

Use **Water color** to tint the surface and underwater haze. Raise **Transparency** to see through the surface. Lower the fog density for a longer view through the water, or raise it for a murkier lake. **Fog density · coarse** covers the full range from 0 to 1 per metre. **Fog density · fine** adjusts within 0.005 of the coarse setting in increments of 0.0001 per metre. **Reflectivity** controls sky and sun reflections, strongest toward the horizon. **Shore foam** controls the white fringe in the shallows. These settings take effect while riding or paused and travel with a saved landscape.

## Drive on land

Select **Drive car**, press **C**, or press left on a gamepad's D-pad to take the Testarossa. The sandbox checks the ground below you first, then searches nearby for a dry, gently sloping patch with room to pull away. Graded roads use the same ground surface. If you start over water or a cliff and the search finds nothing, fly closer to land and try again.

**W** or the right trigger accelerates. **S** or the left trigger brakes while moving forward and reverses once the car stops. **A/D** or the left stick steer. The triggers and stick act in proportion to how far you move them. **Space** or **A** pulls the handbrake. The rear tires lock, slow the car and let the tail swing out into a turn. Release it and the car carries on the way it points. On a gamepad the right stick looks around the car; pull it back to look behind. An inward bank helps the tires carry speed through a corner. The wheels move independently over bumps. A crest you can roll over slowly can launch the car at speed. It carries its momentum through the air, and the suspension compresses on landing. Steeper faces can tip the car into a roll. If the car comes to rest on its roof, it waits 1.5 seconds before righting itself. It uses the last stable patch if the landing spot is too steep. Water and the map edge still bound the drive. Press **F** to return to flight.

## Ride the water

Select **Board watercraft**, press **B**, or press right on a gamepad's D-pad to find nearby water deep enough for the craft. Use **W/S** or the triggers for throttle and reverse, and **A/D** or the left stick to steer. The right stick looks around the craft. The craft responds to the same large waves shown by the renderer and leaves local wake disturbances.

Select **Return to flight**, or press **F**, to explore and edit again. The craft is an arcade physics demonstration, not a complete racing game.

## Race through gates

Choose **Race gate** under **Tool** to build a course in free flight. Click the ground to place a gate. It faces the way the camera looks, so you pass through it travelling that way. Where you click decides the kind of gate:

- On water, an inflatable arch for the jet ski, 8.9 metres wide and 3.6 metres high inside. It floats and rises and falls with the waves.
- On land with **Height above ground** at 0, a gantry on two solid posts for the car, 13.3 metres wide and 3.1 metres high inside. The car collides with the posts. On a slope the gantry stands on the higher ground across its span, so the board stays clear of the hill; each post reaches down into the ground under it, and the opening reaches down to the lower side.
- On land with a height above 0, a banner for the F5 that hangs that many metres above the ground. Fly through the space below it, 40 metres wide and 8.8 metres high. The F5 starts at least 75 metres above the ground, so a height of 71 metres puts the opening in its path.

**Opening width** and **Opening height** size the next gate you place; 0 keeps the kind's own size. Road and water gates open up to 60 metres wide and 40 metres high, and air gates up to 300 by 150 metres. The road gate's posts move to the sides of the opening and its board sits on top, showing as many checker squares as fill it beside an unchanged label. The arch stretches, at most twice as much one way as the other, so a wide arch must also be tall. A size beyond a kind's range takes the nearest one it allows, and the message says so. The note under the two fields lists each kind's range and own size.

The F5 keeps at least 8 metres above the ground and the water, so it flies under a road gate's board only when the opening is at least 9 metres high. When the vehicle you are in cannot reach the next gate's opening, such as the F5 at a road gate of its own size, the car at a gate on the water, or the jet ski at a gate on land, the race line says so, and for the F5 gives the opening height it needs.

**Gate role** chooses what a click does. A course has one start gate and one finish gate, and placing another moves it. A finish gate placed within one gate width of the start gate is not added; the start gate becomes a start / finish gate instead. Checkpoints are numbered in the order you place them, up to 32. A gate that would overlap another gate is refused. **Remove** takes away the gate you click on or beside, and the checkpoints after it move up a number. **Clear gates** removes every gate.

**Laps** belongs to the start gate, runs from 1 to 20, and counts only on a course without a finish gate. Each lap then passes the checkpoints in order and returns through the start gate, which reads START / FINISH. A course with a finish gate runs once, from the start gate through the checkpoints to the finish gate. The note under **Laps** says which of the two the course you have built does.

Drive, ride or fly through the start gate to start the clock; you do not need to stop first. Pass each gate in order and in its direction. The race ignores every other crossing. A course without a finish gate ends each lap back at the start gate, and the lap counts only once you have been at least three half widths of that gate's frame away from it since you last passed through it: 23 m from a road gate, 18 m from a water gate or 68 m from an air gate at their own sizes. Rocking back and forth through the start gate, or through it and a checkpoint just clear of it, counts no lap. The line under the status message shows the time, the lap, the next gate and its distance, the last split and the best time. The sandbox keeps your best time for each course until you reload the page. **R**, **Reset race** or **Y** on a gamepad sends the race back to wait at the start gate. Pausing stops the clock. Leaving the vehicle, boarding again, reaching the map edge or changing the course stops the race.

The chase camera never passes through a gate. Behind a vehicle that goes through the opening, it lowers itself under the board, banner or arch and rises again once past, keeping clear of the posts and legs, at whatever size the gate is. The car's camera starts lowering 10 metres before a gate, the jet ski's 8 metres and the F5's 60 metres. Behind a vehicle that goes over a gate, the camera passes over it too.

**Save file** stores the gates and their sizes with the landscape, and **Load file** brings them back. Gates in files saved before gates had sizes load at their kinds' own sizes. **Generate** starts the new landscape without gates.

## Use a gamepad

The sandbox reads gamepads that the browser maps to the standard layout, such as Xbox and PlayStation controllers. Browsers report a pad only after you press one of its buttons with the page open. The D-pad chooses where you are: up for the jet, left for the car, right for the watercraft and down for free flight. **Start** pauses and **Y** resets a race. The gamepad keeps working while a panel control has focus, and stops while the page is in the background.

## Pause and save

**P** pauses waves, the moving sun, and vehicle motion. Camera movement and authoring remain available. Once terrain preparation settles, an unchanged paused view stops submitting rendered frames.

**Save file** downloads a JSON document containing the landscape recipe, committed edits, environment controls, camera and race gates. **Load file** opens a file picker. Files are local to your device; the sandbox does not upload them.

## Choose detail

Economical, Balanced, and Detailed adjust terrain mesh density. They do not change collision heights or saved terrain. The diagnostic display reports rendered patches, mesh memory, generation time, and render submission time. Render submission measures how long the application spends issuing the scene and presenting it. The interval between draws includes the rest of the frame and any idle time. During continuous animation, 16.7 ms between draws corresponds to 60 frames per second.

The sandbox gives terrain generation an eight-millisecond work budget while the view is stationary and reduces it to two milliseconds while flying, looking, or driving. Work stops when the requested terrain is ready; this budget does not make settled paused views redraw.

The first target is a desktop with hardware-accelerated WebGL2. Terrain and shaders also run with software WebGL, but comparable frame rates are not expected.
