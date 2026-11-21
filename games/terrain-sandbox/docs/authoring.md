# Shape a landscape

Choose a tool under **Shape the ground**, then click the terrain. Each click creates one transaction, allowing deliberate edits without filling history with a brush operation every frame. Right-drag still looks around.

Before generating, open **Landform parameters** to change the height scale, fine detail, slope damping, or starting water level. Stronger damping removes more fine detail from steep faces. Domain warp bends the landforms. Terrace spacing creates smooth ledges; set it to zero to turn terraces off. In Canyon country the spacing controls the thickness of the whole sequence of rock beds. At the default setting of 80 meters, look for a pale shelf below the salmon cliffs. Exposed faces show fractures and fine red layers, while loose debris softens the boundaries on gentler slopes.

The canyon preset lowers water to −450 meters and starts inside the valley, exposing both its floor and steep walls. After generation, use **Water level** in the **Water** section to flood or expose the existing ground.

**Limestone Plain** rises from a grassy valley into broad foothills and pointed summits. The lower slopes have routes a car can climb, and sloping benches break up the longer faces. **Terrace spacing** sets the height between benches (175 m by default). Lower **Terrace strength** to soften their edges, or increase **Floor width** for more open ground between peaks.

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

Width sets the road surface width. Shoulder sets how far the cut or fill blends into the surrounding ground. **Apply road** grades the route and paints its surface. **Clear points** abandons the uncommitted route.

Roads modify the heightfield. Bridges, tunnels, and overhangs require separate geometry and are outside this sandbox.

## Place practice balloons

In **Shape**, choose **Balloon**, set the height, and click the ground or water under the desired position. The balloon stays that height above the higher surface when you edit the ground or change the water level. Choose **Remove** and click a balloon to delete its placement.

You can save up to 32 placements with a landscape. Popping a balloon during flight preserves its placement. **Reset balloons** in **Explore** restores the authored targets and ends quick practice.

## Keep a reusable landscape

A save records the seed and complete recipe together with the ordered edit history. It pins the generator version so a later algorithm change cannot silently reshape an old landscape. Render caches are reconstructed as needed after loading.

The example is offline. Reproducible local terrain and edit replay do not, by themselves, establish a multiplayer authority or synchronization protocol.

## Color patterns

In **Surface → Color layers**, open a layer to choose a color pattern. **Mottled** blends patches of a second color, **Streaks** follows upright faces, and **Gravel** scatters small flecks. **Weathered beds** adds thin sediment lines that follow the dipping strata and streaks that cross them. Set the pattern color and amount, then set its scale in meters. Vertical stretch lengthens the pattern on cliffs. Choose **Apply layers** to preview and save the result.

The layer's elevation and slope masks set where its color and pattern can appear. **Edge noise** varies those mask boundaries. **Stipple** adds flecks throughout the layer, including on broad stretches of sand or rock. **Fractal noise** blends broad patches with finer flecks. **Mineral veins** adds narrow, broken seams. Limestone Plain uses the first pattern for grass and slope vegetation and stretches the veins down exposed rock faces. Color patterns use fixed world coordinates and fade as their features become too small to resolve.

Mountain Ridges uses its preset grass, lichen, and snow detail until you apply a custom layer stack. **Use preset colors** restores the mountain shading.
