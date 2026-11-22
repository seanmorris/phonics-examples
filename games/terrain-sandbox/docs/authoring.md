# Shape a landscape

Choose a tool under **Shape the ground**, then click the terrain. Each click creates one transaction, allowing deliberate edits without filling history with a brush operation every frame. Right-drag still looks around.

Before generating, open **Landform parameters** to change the height scale, fine detail, slope damping, or starting water level. Stronger damping removes more fine detail from steep faces. Domain warp bends the landforms. Terrace spacing creates smooth ledges; set it to zero to turn terraces off. In Canyon country the spacing controls the thickness of the whole sequence of rock beds. At the default setting of 80 meters, look for a pale shelf below the salmon cliffs. Exposed faces show fractures and fine red layers, while loose debris softens the boundaries on gentler slopes.

The canyon preset lowers water to −450 meters and starts inside the valley, exposing both its floor and steep walls. After generation, use **Water level** in the **Water** section to flood or expose the existing ground.

**Limestone Plain** rises from a grassy valley into broad foothills and pointed summits. The lower slopes have routes a car can climb, and sloping benches break up the longer faces. **Terrace spacing** sets the height between benches (175 m by default). Lower **Terrace strength** to soften their edges, or increase **Floor width** for more open ground between peaks.

**Basalt Fjords** floods winding valleys between mossy headlands. Look for thin, weathered seams that taper along the dark cliff faces. Under **Surface → Lava beds**, raise **Vertical stretch** to space the flow contacts farther apart, or lower **Pattern amount** to soften their color. **Seep stains** adds darker streaks down the rock. Lower **Water level** to expose more of the gravel shoreline.

**Amber Dunes** has sharp sand crests, darker slip faces, and salt on the lowest basin floors. Fly close to a gentle slope to see the wind ripples catch the low sun. On the **Surface** tab, change **Pattern scale** on **Wind ripples** to set the distance between ripples. It starts at 0.78 m.

In **Arctic Meltwater**, fly low along a blue ice bank and turn toward the sun to catch its reflection. Under **Surface → Glacier ice**, lower **Reflection roughness** for a sharp glint or raise it for a frosted finish. **Reflection strength** controls how much of the exposed ice carries that finish. Snow and gravel above the ice cover its sheen.

In **Tidal Lagoon**, the channels are deep enough to launch the jet ski. Follow the darker water around the pale sandbars. The waterline sets where seagrass, salt grass and scrub can grow.

In **Red Mesas**, the mesa walls use the canyon's bedded rock layers, so editing those layers under **Surface** recolors every butte. Lower **Floor width** under **Landscape** for more and larger mesas. In **Heather Moor**, raise the water level to flood more lochans; the peat banks and straw grass follow the new waterline.

Generating starts a new edit history; save a landscape first if you want to keep it.


Choose **World size** in the **Landscape** tab before selecting **Generate**. The 65.5 km setting doubles the side length and gives four times the playable area.

## Sculpt and paint

- **Raise / Lower:** change the ground by the selected strength, fading smoothly toward the brush edge.
- **Flatten:** blend the ground toward the Flatten elevation.
- **Smooth:** reduce local changes using neighboring surface samples.
- **Crater:** cut a depression with a low surrounding rim.
- **Paint material:** replace the procedural material within the brush area.

Radius is measured in meters. Strength is the maximum elevation change for Raise, Lower, and Crater; Flatten and Smooth use a fixed blending strength.

The current landscape stays visible and physical while the next revision is prepared. Editing controls pause briefly during this preparation so terrain and collision can switch together. **Undo** and **Redo**, or **Ctrl+Z** and **Ctrl+Shift+Z**, operate on complete transactions.

Picking distant ground can take a moment. The exact intersection search runs in small slices while flight and the rest of the scene remain responsive. A click retains the brush settings it started with.

## Grade a road

Select **Road control points**, then click at least two points on the ground. Each point captures its ground elevation plus the current Offset. Positive offsets build embankments; negative offsets cut into the ground. You can change the offset before placing the next point to shape the road's grade.

Numbered points and a connecting line preview the route in the view. They move with the camera and disappear when behind it; the overlay does not intercept clicks.

Width sets the painted pavement edge. Shoulder sets how far the cut or fill blends into the surrounding ground outside that edge. **Apply road** grades the route and paints its surface. **Clear points** abandons the uncommitted route.

Open **Roads** or choose **Edit on map** from Shape to see the route on a north-up terrain map. Select a saved road or choose **New road** and **Place points**. Drag empty ground to pan, scroll to zoom around the pointer, or choose **Fit road**. **Whole map** fits the entire editable landscape, including distant canyon branches. Other roads stay visible for reference.

Select a numbered point to drag it or enter its coordinates. Dragging preserves elevation. **Set to ground** samples the terrain at the point. The square handles control the incoming and outgoing curve. Under **Curve handles**, change their horizontal or vertical offsets to shape a bend, crest or dip. **Use automatic handle** restores the tangent on that side. In **Place points**, click a curve to insert a control point at that spot, or click open ground to extend the road. Splitting preserves the bend, elevation and bank. In **Select / pan**, click the curve and choose **Split curve** for the same result.

The elevation profile shows the selected span, the full route length and maximum grade. Bank tilts the road across its width. **Apply road** commits the changes in the original road's place, including its charcoal pavement and 40 cm ochre centerline. The centerline alternates 10 feet of paint with a 10-foot gap along the curve. Solid white lines follow both edges of the pavement. After sunset the white lines and ochre dashes glow faintly. Undo and redo are under **Shape**. Saving the landscape preserves the points, handles and edit history.

For a manual race, place at least three points along a loop 120 m to 40 km long. Choose **Close loop and create circuit** to grade the road and place the start/finish gate at the first point, followed by ordered checkpoints. You can also select a saved road, reshape it, and use the same button to update its circuit. Long roads prepare over successive frames and stay together as one edit when you save or undo.

Open **Circuit generator** in Roads to find a route around a natural feature. The generator fits longer curves through the surveyed route and keeps more points where the direction, elevation or bank changes sharply. The road picker lists those curves in numbered sections. You can adjust points, curves and banks before applying the dry or flooded course. **Apply road** grades the generated sections together if you only want the pavement.

Roads modify the heightfield. Bridges, tunnels, and overhangs require separate geometry and are outside this sandbox.

## Place practice balloons

In **Shape**, choose **Balloon**, set the height, and click the ground or water under the desired position. The balloon stays that height above the higher surface when you edit the ground or change the water level. Choose **Remove** and click a balloon to delete its placement.

You can save up to 32 placements with a landscape. Popping a balloon during flight preserves its placement. **Reset balloons** in **Explore** restores the authored targets and ends quick practice.

## Keep a reusable landscape

A save records the seed and complete recipe together with the ordered edit history. It pins the generator version so a later algorithm change cannot silently reshape an old landscape. Render caches are reconstructed as needed after loading.

The example is offline. Reproducible local terrain and edit replay do not, by themselves, establish a multiplayer authority or synchronization protocol.

## Author a surface

Open **Surface** to see the material stack that draws the current landscape. Layers run from the base upward. Expand a named layer to edit it. Colors and numeric values preview immediately. Adding, moving or removing a layer, selecting a different pattern, or changing a parent starts a draft. Choose **Apply layers** to use the draft. Saving writes the last applied stack.

Use a parent mask to put one material inside another. To add ripples to a beach, add a layer above **Sand**, choose **Sand** under **Within layer mask**, and set **Affects** to **Relief**, **Relief source** to **Pattern**, and **Pattern** to **Ripples**. Set the scale in meters and turn the sun to see the highlights. Choose **Both** to have the same pattern tint the surface. **Flatten underlying relief** smooths the previous lighting normal where the layer covers it.

Mountain Ridges exposes its meadow, forest, granite, iron stains, snow and lighting relief in the same stack. Other surfaces can use those fields. **Shared field settings** controls features that several layers sample, including gully spacing, block size and slab dimensions. **Load preset surface** copies a whole material recipe onto the current landform. An edited stack survives terrain generation. **Follow landform preset** reconnects it to the landform.

On **Granite slabs**, the base color is weathered rock and the pattern color is fresh rock. **Pattern amount** controls how much fresh rock shows through. **Pattern scale** and **Vertical stretch** set the spacing of the fractures and size of the mineral patches. The default scale of 13 m and stretch of about 1.77 match the 13 m by 23 m joints in **Shared field settings**.

To soften the rock on steep faces, raise **Steep-face detail damping** under **Landform parameters** and generate again. Fine cracks soften first, and broad gullies keep more of their shape.

Brushes and roads use the five colors under **Paint inks**. Painting covers the natural color and relief at full coverage. The shoulder blends back into the natural surface. Road paint and markings start to fade on slopes above 60° and give way entirely to the landscape at 75°. The minimap reads the same material stack and paint colors.
