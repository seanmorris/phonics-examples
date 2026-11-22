# Terrain Phonics

Editable landscapes, directional water waves, and a scattering atmosphere for Phonics applications.
The package is optional and does not depend on a map editor or change the engine's 2D collision API.
The companion **Landscape Sandbox** demonstrates the public interfaces with a flight camera, terrain tools,
roads, and a test watercraft.

## Start with a surface

```php
require 'terrain-phonics/autoload.php';

$ground = new TerrainPhonics\Landscape([
    'seed' => 'first-light',
    'preset' => 'coast',
    'worldSize' => 32768,
    'spacing' => 1,
]);
$contact = $ground->sample(120, -80);
// height, dx, dz, upward normal, material, and committed revision
```

Coordinates are meters, Y is up, and the square world's X/Z bounds are centered on zero.
Queries outside the bounded world are rejected. `height()` and `sample()` use the same authoritative
Catmull–Rom reconstruction. A renderer can change its mesh resolution without changing these queries.

The generator combines finite smooth Perlin bands with a separate larger-scale slope guide.
Steeper guide slopes attenuate fine-detail **amplitude**, especially at the finer octaves.
The guide never feeds back from an already attenuated surface. Rounded mountain ridges avoid absolute-value cusps.
Surface normals differentiate the final reconstructed height, including all terrain edits.
Optional domain warps and quintic terraces retain continuous derivatives; the guide includes their full derivative chain.
`warpAmplitude` bends the larger landforms, while `terraceSpacing` and `terraceStrength` add smooth terrace-like steps.
In new canyon recipes, exposed rock and physical ledges share a sequence of dipping beds. Terrace spacing scales the thickness of the sequence in meters. Resistant beds form cliffs above shelves, and softer beds form debris slopes. Version-one recipes retain their original canyon geometry for replay.
Smooth joins preserve derivatives; a heightfield still cannot represent an exactly vertical face or an overhang.

Only requested lattice nodes are generated. Packed tiles have a bounded LRU cache; `pin()` reserves a small
region for a physical body independently of camera travel. `unpin()` and `close()` release ownership.

`localBounds($x, $z, $cells)` bounds the surface over the square block of lattice cells around a point without
generating a node. The interval includes terrain edits and cubic interpolation, and it stays valid until the next
committed revision. A body that only has to stay above the ground can compare its height with the block's `maxY`
and take an exact `height()` sample only when that test fails. The sandbox's jet does this with blocks from 2 to
256 cells, so it generates no nodes while it cruises.

`heightEditIndex()` returns a shared index for the current revision. Use `isEmpty()` to check whether any active
edit can change elevation, or `intersect([$minX, $minZ, $maxX, $maxZ])` to check one area. The bounds include
the interpolation border. Each road uses its enclosing rectangle, and material paint contributes no footprint.

## Edit and preserve a landscape

```php
$ground->queue(['type' => 'raise', 'x' => 120, 'z' => -80, 'radius' => 24, 'amount' => 8]);
$ground->commit();
$ground->queue([
    'type' => 'road',
    'points' => [[100, 20, -100], [150, 24, -60], [210, 26, -20]],
    'width' => 8, 'shoulder' => 5, 'crown' => .2,
]);
$ground->commit();
$saved = $ground->save();
$restored = TerrainPhonics\Landscape::load($saved);
```

Road edits accept an optional `endBlend` distance in meters. Set it on overlapping spline sections to fade their grade and paint into each other. It defaults to zero.

A transaction contains all operations queued before one `commit()`. Supported operations are raise, lower,
flatten, smooth, crater, material, and road. Undo/redo works on transactions. Saved data includes the generator
version and edit history; derived sample caches and road acceleration indexes are rebuilt on load.
Finite operation and road-kernel limits keep malformed files from allocating unbounded work.
For distant pointer picking, use `rayQuery()` and advance it with a time budget and a maximum number of traversal steps.
The picker skips blocks whose full height range misses the ray, including the effects of edits and cubic
interpolation. It solves the remaining cells in front-to-back order. Both picking APIs return the same first hit.

A visible application should prepare a candidate with `$ground->fork()`, edit that candidate,
and call `Scene::prepareLandscape()`. Continue simulating and drawing the old surface while preparation runs.
At a fixed-update boundary, `takePreparedLandscape()` publishes the prepared mesh revision and returns the
surface to install in physics. New operations must wait until the pending transaction completes.

Roads grade terrain using smoothly blended sampled spline kernels, including their shoulders and ends.
A road stores ordered control points as `[x,y,z]` in meters. The default tangents follow a Catmull-Rom curve. To shape a bend or a crest, supply a `handles` list alongside `points`. Each entry can set `in` and `out` to cubic Bézier control offsets `[dx,dy,dz]`. Omit either side to calculate it automatically.

`roadRecords()` lists the active roads by their position in the edit sequence. Queue a revised record with `replaceAt` set to that position to move the original road and preserve any later paint or grading. Version 2 saves retain these edits and handles through undo and redo. Roads with automatic handles and ordinary append-only history still save as version 1.

For interactive grading, advance `queueSteps($road)` within the frame budget. It yields while sampling and indexing a long spline and queues the entire road once it finishes. Call `commit()` to publish the edit. One landscape supports 65,536 active road samples across all its roads.

At a junction, operations apply in authoring order. Roads should agree on their crossing elevation. Both terrain renderers draw a 40 cm ochre centerline along graded pavement, with alternating 10-foot dashes and 10-foot gaps measured along the curve. A road-color brush paints solid pavement.
Bridges remain separate meshes. There is one ground height at each X/Z position, so this representation
cannot describe caves or overhangs.

## Draw and simulate

`Camera` supplies flight motion, a perspective matrix, and picking rays. `Scene` draws the ground and water
using nested grids. Each grid has an elevation texture that the GPU fills from the seed and recipe. As the
camera travels, only the newly exposed strips need to be evaluated. The grid edges share vertices across
levels. Coarse levels draw first after a jump or context restore, and finer levels follow. Steep terrain gets
wider midrange grids to hold detail on visible cliffs. Transition weights follow the continuous camera
position. The fine and coarse grids share interpolated heights and normals where their coverage meets.

Call `update()` before `render()`. For a ground vehicle, pass a world-space `[x, z]` look-ahead point as the third argument to `update()`. This widens the finest grid by about a third and shifts its center toward the point by up to one sixth of the new width. The caller owns frame presentation. Call `restore()` after context loss and
`close()` when unloading. `stats()` reports resident levels, pending work, texture bytes, and edit uploads.
Pass `useClipmap: false` to `Scene` for the PHP patch renderer, which uses the selection interval and work
budget settings. Its GPU resources and update path are independent of the clipmap.

Collision and picking use `Landscape::height()` and its related samplers. The clipmap uploads a spatially
indexed texture of road splines when the roads change. It retains each road's packed grade and paint overlay, composites them in edit order, and uploads changed texture rows when the atlas dimensions stay the same. As the camera moves, the elevation shader reads the
nearby spline samples to grade the surface and paint the road and shoulders. Where a brush overlaps a road,
PHP supplies the final samples in edit order. The world overview also uses PHP reconstruction in edited
areas when its pixels fall between terrain lattice nodes. `Scene::drawChart()` draws
a heading-up chart at two zoom levels from the same elevation and surface layers. It keeps the vehicle centered
and limits the wider view near the map boundary. Pass `circular: true` to draw a disc within the chart viewport.

Recipe presets fill in a common set of shape coefficients. These coefficients can combine broad directional
rise, rounded ridges, canyon walls, rolling relief and terracing. The `limestone` preset uses a 3,050 m
base wavelength and a wide wall transition to give isolated peaks broad foothills. Partial terraces at
175 m elevation intervals form sloping benches below the summits. `basalt-fjords` cuts flooded valleys
between rolling headlands. Terracing starts at zero on this preset. `amber-dunes` uses narrow ridges and rolling relief to give the
two faces of a dune different slopes. `arctic-meltwater` raises broad shelves above flooded channels.
`tidal-lagoon` brings low, curving ridges just above sea level to form chains of sand cays. Existing recipes expand to the values of
their versioned preset. `surround` can be `none`, `sea`, `plateau` or `mountains`. It extends the scenery without
changing the landscape's playable extent.

### Surface materials

`SurfaceLayers::landscape($recipe, $appearance)` returns the editable material recipe used by the ground and the minimap. Assign a replacement to `Scene::$layers`. The stack holds up to sixteen ordered layers with stable IDs. Heights, slope, water level, dipping beds, noise and an earlier layer’s mask can restrict coverage. A parent can be given by ID or by its earlier index.

A layer's `channel` selects `color`, `relief` or `both`. For example, add this layer above an existing layer with ID `sand` to give that sand small directional ripples under changing light.

```php
$rows = $surface->layers;
$rows[] = ['id'=>'ripples', 'name'=>'Sand ripples', 'parent'=>'sand',
    'channel'=>'relief', 'relief'=>'pattern', 'pattern'=>'ripples',
    'patternScale'=>1.4, 'strength'=>0.25];
$scene->layers = new SurfaceLayers($rows, $surface->fields, $surface->bounce);
```

Patterns use world-space meters and fade with the pixel footprint. Stipple has five scales of grain. At the 1.6 m scale used on Mountain Ridges, these range from about 53 cm to 7 mm. A layer can use one pattern for tint and bump, sample a shared coverage field such as scree, or add facets, crown, block and rib relief. `flatten` controls how much previous relief is removed under its coverage. Lighting normals affect presentation only. The chart samples the same stack for color. Paint inks apply last on both views. Fully painted road uses its road ink and geometric normal, with shoulders blending by coverage. `Scene::$paint` can supply a separate ink palette.

The `jointed` pattern blends weathered stone into freshly exposed rock across patches of a face. Its scale sets horizontal joint spacing in meters. Vertical stretch multiplies that spacing along the dip. Mountain Ridges uses 13 m by 23 m to match its granite fractures. Smaller mineral inclusions and grains become visible as you approach.

The `contacts` pattern marks thin boundaries between rock units. Each boundary dips, varies in thickness and gradually disappears along the face. `patternScale` sets the horizontal scale of exposed patches. Multiplying it by `patternStretch` gives the mean vertical spacing. Basalt Fjords uses 84 m and 0.30 for flows averaging about 25 m apart. These controls affect the material independently of geometric terraces.

`Water` evaluates analytic directional waves and bounded wake packets. Its shader include uses the same
parameters and derivatives as PHP. `Watercraft` samples several buoyancy points and adds damping, drag,
steering, planing, and shoreline contacts. It is an arcade test craft, not a full rigid-body engine.
The optional `gravity` constructor argument defaults to 9.81 m/s². Increasing it shortens free fall
and adjusts buoyancy to keep the resting hull at the same waterline.

Above 2 m/s, a hull in contact with the water emits a wake about every 0.2 seconds.
Foam follows the stern and spreads as the trail ages. The sandbox’s **Shore and wake foam**
control sets the amount visible.

`Atmosphere` models Rayleigh and Mie single scattering. The default sun disc has an angular radius of 0.00935 radians (0.536°). Changing its radius spreads the same total sunlight over a different solid angle.

Pass a list to `Atmosphere::setLayers()` to add up to six colored aerosol layers. For example, `[['name'=>'Sea mist', 'bottom'=>0, 'top'=>350, 'density'=>0.2, 'color'=>'#b5ccd4']]` adds a low coastal mist. Base and top are meters above sea level. Density is optical depth per kilometer and color is the sRGB tint of scattered sunlight. A one-kilometer path at density 0.2 transmits about 82% of its direct light. Overlapping layers contribute together, and sight lines cross them in altitude order. Set `enabled` to false to hide a layer and keep its settings.

The sky, reflected sky and distant terrain use cached scattering. Editing a color rebuilds the scattering caches. The sunlight transmittance texture holds clear air only; the shaders add each active layer's extinction exactly, because the texture's altitude rows are too far apart for sub-kilometer layers. Changing a layer's bounds or density still rebuilds the texture, whose alpha keeps the layers' transmission. Camera rotation and repeated draws at a fixed altitude reuse the caches.

`Atmosphere::setSkyColor($hex)` recolors the sky through its molecular scattering law. The color is how the zenith should display with the sun 35° up at an exposure of 1; the default `#4b698a` keeps Earth's coefficients. Each channel's radiance is compared with the default sky's, and a density and a wavelength exponent are fitted to the ratios (Earth's air has exponent 4). The exponent stays between 3 and 5: freer channels turn sunsets yellow-green. A deeper blue raises the exponent and thins the air, so distant land stays clearer, and `#41659a` displays as `#436590`. A paler blue lowers the exponent or thickens the air, which also deepens sunsets. Colors outside the fitted family land on the nearest sky in it. The change rebuilds the sunlight texture and the sky caches.

Sunlight paths also cross an ozone layer (peak absorption (0.650, 1.881, 0.085) × 10⁻⁶ per meter at 25 km, falling to zero at 10 and 40 km). It takes green out of a low sun, so sunsets turn orange instead of yellow-green. It lies far above any view of the terrain, so it enters only through the sunlight texture and costs nothing per pixel. Pass `'ozone' => [0, 0, 0]` to remove it.

`Atmosphere::setHazeColor($hex)` tints the light the general aerosol haze scatters. Only the hue counts: the brightest channel scatters fully, so white and every gray leave the haze neutral, and `setHaze()` alone sets its strength. The haze's extinction stays gray, so sunlight and its texture are unchanged. Because aerosols scatter mostly forward, the tint shows most in the glow around the sun and along the horizon.

Terrain's diffuse sky light comes from the model's own sky. `Atmosphere::skyIrradiance($sine)` integrates single-scattered sky radiance over the hemisphere above level ground, for a sun at that sine of elevation, and divides by π so it multiplies albedo like the shaders' sunlight. Each whole degree is integrated once per atmosphere (12 sky rays, about 3 ms natively) and interpolated between. `uniforms()` returns it as `skyAmbient`, scaled by `Atmosphere::setSkyLight($strength)`; the default 1 is the model's own value. It changes with haze, sky color, haze color and the layers, and it dims and warms as the sun sets.

Use `Atmosphere::setNightGlow($strength)` to set the blue night fill. The default is 0.5. Custom materials can read the linear RGB `nightAmbient` from `Atmosphere::uniforms()`. The glow and stars fade in between sunset and a solar elevation of −10°. The stars keep fixed directions as the camera moves.
The world shadow map stores the elevation below which a ridge blocks the sun and the sunlit fraction of the
terrain overview. Nearby ledges compare their own elevation against the ridge. As the mesh loses detail,
lighting blends into the overview’s visibility, filtered over the area each pixel covers. The map refreshes
when terrain changes or the sun moves by a tenth of a degree. Around the camera the clipmap also tests sunlight
at shared vertices on a world-aligned terrain grid. Where the vertices disagree it refines the crossing edges.
Where they agree it checks the center for a shadow or a sunbeam. Nearby pixels resolve the edge. Small projected triangles
use their sunlit area. The close shadow fades with distance from the eye so crossing a cache boundary leaves
the blend continuous. Both local passes stay cached across ordinary frames and refresh when the camera crosses
a grid block, the terrain changes, or the sun moves by a tenth of a degree. Water reflects the cached sky with
a lower-environment approximation; it does not reflect individual terrain objects. Distant wave normals and
foam are filtered for their pixel footprint without changing the physical waves.

`Scene::setSpotLights($lights)` sets up to two spot lights, such as a vehicle's headlights, that light the ground
and the water from the next `render()`. Call it on each frame the lamps move; an empty list clears them. Each light
is an array with exactly these keys:

| Key | Unit | Meaning |
| --- | --- | --- |
| `position` | meters | World position of the lamp, `[x, y, z]`. |
| `direction` | | Axis of the beam, `[x, y, z]`, of any nonzero length. |
| `color` | | Linear RGB, each channel nonnegative, scaling the intensity per channel. White is `[1, 1, 1]`. |
| `intensity` | candela | Luminous intensity along the axis. |
| `range` | meters | Distance at which the light has faded out; at least 0.01. |
| `inner`, `outer` | radians | Half-angles of the cone: full light inside `inner`, none outside `outer`. `0 <= inner <= outer` and `0 < outer <= π/2`. |

```php
$scene->setSpotLights([
    ['position' => [12, 3.1, -40], 'direction' => [0, -.05, 1], 'color' => [1, .9, .75],
        'intensity' => 20000, 'range' => 80, 'inner' => .15, 'outer' => .4],
]);
```

A list that breaks any of these rules throws `InvalidArgumentException` and leaves the previous lights in place.
A lamp's irradiance is its intensity times the cosine of incidence, divided by the squared distance. It fades to
zero at the range, as `(1 - (d/range)^4)^2`, and across the band between the cones, as the square of the linear
blend of their cosines. `SpotLights::LUX` converts lux to scene units: Atmosphere's default `sunIntensity` of 20
stands for about 128,000 lux of sunlight above the atmosphere. By day a lamp adds that physical share and little
shows. At night the renderer multiplies lamp light by `SpotLights::NIGHT_GAIN`, 10,000, along the dusk curve of
the night fill. The default fill lights level ground as about 4,200 lux of sunlight would, standing for moonlit
ground seen by eyes adapted to the dark. Under the same gain it matches about 0.4 lux; a full moon on a clear night
gives 0.05 to 0.3 lux.

On level ground a low lamp's irradiance falls with the cube of distance, so the ground 3 m ahead of a car receives
hundreds of times what the ground 50 m ahead does, more than the tone map can show without turning the near ground
white. The shaders therefore compress the lamps' summed irradiance above `SpotLights::KNEE`, 1 scene unit after the
night gain. With m the largest channel of the sum the lamps would give with their cones wide open, they scale the
sum through the cones by `KNEE ln(1 + m / KNEE) / m`, which keeps the lamps' color. Light well below the knee passes
almost unchanged; ten times the knee comes out as 2.4 times it, and a hundred times as 4.6 times. Because the scale
ignores the cones, a beam on a nearby slope fades across its whole band between the cones. Scaled by the light
through the cones instead, such a beam would stay near full brightness until close to its outer cone and show as a
flat disc with a hard rim.
Exposure and tone mapping then treat the compressed lamp light like sunlight.

The terrain reflects lamp light diffusely from its albedo. Light that reaches submerged ground loses the water's
`waterFogDensity` for each meter of its path below the water level. The water between the eye and submerged ground
scatters the lamp light that falls where the sightline enters it. The water surface adds lamp light to its foam
and its body color; it does not reflect the lamps. No surface casts a shadow from a lamp, so a lamp lights the
far side of a hill within its range. Models drawn by the `drawOpaque` callback do not receive lamp light.
The scene links each of its ground and water programs twice: once with `SpotLights::shaderSource()` and once with
`SpotLights::shaderSource(false)`, which defines `TERRAIN_NO_SPOT_LIGHTS` so that both lamp functions return zero.
`render()` draws with the lit pair while at least one light is set and with the lamp-free pair otherwise, and then
uploads nothing for the lights. A GL implementation may compile a program's pipeline only when the program first
draws. In SwiftShader the first draw of the lit ground and water programs took 0.19 and 0.24 s, against 3 and 1 ms
for later draws, so the frame in which the first lamp lit stalled. Each lit program therefore also draws once in the
first frame that has patches to draw, and again after `restore()`, even without a light. After that, changing pair
costs nothing more than drawing with the same one. A lit program receives the lights again only when they change
and after `restore()`, and keeps them while the lamp-free pair draws.
An application that draws only changed frames must draw one after changing the lights. A custom shader can include
either source and upload `SpotLights::uniforms($nightVisibility)` to a program built with the lit one.

Measured in the sandbox in Chromium with SwiftShader, PHP compiled to WebAssembly takes 0.034 ms to validate and
pack two lights, 0.013 ms to upload changed lights to one program and 0.006 ms to find a program's lights unchanged.
Moving lamps cost about 0.06 ms a frame for both programs. Choosing the pair costs native PHP nothing measurable:
`render()` took the same time within 1% with the lamp-free pair as with the single pair before it.

Full-screen passes of the linked shaders at 960 by 540 pixels in SwiftShader, timed in pairs against the shaders
from before the lamp code existed, gave these ratios on a shared machine. The first two columns come from two runs.
The third comes from a later 40-round run of the current shaders, whose knee takes its scale from the open cones; that
run gave the lamp-free programs 0.98, 1.01 and 1.01:

| Pass | Lamp code, no light set | Lamp-free program | Lit program, two lights |
| --- | --- | --- | --- |
| Terrain | 1.10 to 1.14 | 0.95 to 0.96 | 1.26 |
| Submerged terrain | 1.07 to 1.15 | 0.95 to 1.04 | 1.34 |
| Water | 1.06 to 1.14 | 1.02 to 1.06 | 1.14 |

The first column is what every frame without a light paid before the lamp-free programs; the second is within the
spread between runs. In Mesa's llvmpipe every variant stayed within 0.87 to 1.20 of the old shaders, as widely as the same variant
varied between runs, so these runs show no reliable lamp cost there. No GPU was available to measure. Compiling,
linking and first drawing one ground or water program took 0.4 to 0.7 s in SwiftShader on the same machine, and the
scene now links four of them where it linked two.

Call `$water->setLevel($meters)` to move the mean surface and `$water->setWaveScale($multiplier)` to change swell height and steepness during play. A scale of zero leaves only local wakes, and one restores the authored waves. Rendering, buoyancy samples, and visibility bounds share those values.

Set `$water->appearance = new WaterAppearance([...])` to tune water rendering. Use `waterColor` (sRGB hex), `waterTransparency`, `waterReflectivity`, and `waterFoam` (fractions), and `waterFogDensity` (extinction per meter). The renderer blends sky reflection and foam over the opaque scene. Submerged terrain fades toward the water tint according to the length of the sightline below mean water level. Draw custom opaque objects with the optional `drawOpaque` callback to `Scene::render()` so the water surface covers only their submerged portions. Their shaders can use `WaterAppearance::shaderSource()` and `bind()` for the same underwater fog.

## Terrain appearance

`Scene::$appearance` supplies preset parameters and default paint inks. With `Scene::$layers` set to null, replacing the appearance rebuilds the preset stack on the next render. Assigning a `SurfaceLayers` object commits its own colors and field settings. An application that draws only changed frames must request a draw after either change.

```php
$scene->appearance = new TerrainPhonics\TerrainAppearance(['snowline' => 220, 'snowColor' => '#f4f8ff'] + $saved);
```

The constructor reads the keys of `TerrainAppearance::DEFAULTS` and ignores others, so an application can pass its whole
settings array. It throws `InvalidArgumentException`, naming the setting, for a color that is not a six-digit hex
string and for a number that is not a finite integer or float; numeric strings are rejected. Numbers outside
`TerrainAppearance::RANGES` are clamped. Each start and end listed in `TerrainAppearance::ORDER` stays apart by a
minimum gap: an end that is too low moves up to the start plus the gap, rounded to nine decimal places, and when that
passes the top of the end's range the end stays at the top and the start moves down. Values one gap apart, such as 0.2
and 0.21, stay as they are, although their binary difference is slightly less than 0.01. `settings` holds the result, in
the order of `DEFAULTS`, with colors in lower case. `GROUPS` lists the settings in panel order under `colors`,
`banding`, `blending` and `rock`. `uniforms()` returns the values `bind()` uploads, for a custom terrain shader.
`TerrainAppearance::linear($hex)` converts one color to linear RGB and throws `InvalidArgumentException` for anything
but a six-digit hex string.

Coast starts with grass, exposed rock, shore sand and snow. Its optional coastal bands have zero coverage. Mountain Ridges uses reusable fields for jointed slabs, iron stains, lichen, talus and forest crowns. On Mountain Ridges, dark conifers give way to sage-green meadow, cool granite and blue-white snow. Early canyons have horizontal strata, and current canyons use dipping beds. Limestone Plain sets deep green valleys against silver-gray karst and pale calcite veins.

Basalt Fjords pairs smoky olive heath with charcoal-blue lava faces, dark tidal gravel and green-blue water. Weathered flow contacts break across the cliffs. Amber Dunes puts fine wind ripples on sand and flattens them where gravel or salt covers the basin floor. Each preset has an editable surface stack, and the five paint inks cover any of these surfaces.

In Arctic Meltwater, snow collects on gentler ground and its coverage controls the wind ripples. Steeper banks expose veined ice and rock. Tidal Lagoon measures the height of its shoals, seagrass, beaches and scrub from the waterline. Changing the water level moves those material boundaries with it.

Set `reflectivity` on a color layer to give it a clear dielectric coating. The range is 0 (matte) to 1 (fully coated). `roughness` ranges from 0 (polished) to 1 (frosted). The shader samples the atmosphere in the reflected view direction and filters the solar highlight by roughness and pixel size. Snow, rock and paint cover the finish with the same masks as the layer color. The minimap shows the underlying tint. The Arctic ice uses an air-to-ice refractive index of about 1.31. [Warren and Brandt’s optical constants of ice](https://atmos.washington.edu/ice_optical_constants/)

The table lists the appearance settings for the coastal, mountain and canyon recipes.

| Setting | Coast | Mountain | Version-one canyon | Canyon |
| --- | --- | --- | --- | --- |
| `grassColor` | Cover | Level valley floors | Paint only | Paint only |
| `soilColor` | | | Cover | |
| `rockColor`, `rockBandColor`, `bandSpacing` | Rock | | Rock | |
| `bandContrast` | | | Rock | Beds |
| `bandSharpness` | | | Rock | Bed contacts |
| `sandColor`, `beachHeight`, `beachWidth` | Beach | Beach | Beach | Beach |
| `snowColor` | Snow | Snow | Paint only | Paint only |
| `roadColor` | Paint | Paint | Paint | Paint |
| `canyonRustColor`, `canyonShaleColor`, `canyonSandstoneColor`, `canyonCapColor` | | | | Beds |
| `forestColor`, `meadowColor`, `tundraColor`, `graniteColor`, `stainColor`, `talusColor` | | Ground | | |
| `rockSlopeStart`, `rockSlopeEnd` | Rock | Bare rock, talus | Rock | Rock |
| `snowline` | Snow | Snow, and the zones that follow it | | |
| `snowTransition`, `snowSlopeStart`, `snowSlopeEnd` | Snow | Snow | | |
| `alpineStart`, `alpineEnd` | | Bare rock | | |
| `treeline`, `tundraStart` | | Zones | | |
| `talusAmount`, `stainAmount`, `lichenAmount` | | Rock | | |
| `macroVariation` | Grass, snowline | Granite, talus, snowline and zones | Soil | Dust and debris |

### Colors

Colors are sRGB hex strings.

| Setting | Default | Effect |
| --- | --- | --- |
| `grassColor` | `#55693a` | Grass cover on coast and mountain terrain, and grass paint. |
| `soilColor` | `#815f40` | Base soil color on version-one canyons. |
| `rockColor` | `#867c6f` | Exposed rock, and the rock between the light bands on version-one canyon terrain. |
| `rockBandColor` | `#af957c` | The light bands in version-one canyon rock, and the coast's optional bands, which have zero coverage by default. |
| `sandColor` | `#bfaf8b` | Sand on level ground near the water, and sand paint. |
| `snowColor` | `#eaf1f8` | Snow on coast and mountain terrain, and snow paint. |
| `roadColor` | `#373b3d` | Road paint. |
| `canyonRustColor` | `#8a4f35` | The lowest bed of each canyon section. The red beds are a brighter shade of it. |
| `canyonShaleColor` | `#6b5546` | The soft shale above the rust. |
| `canyonSandstoneColor` | `#b07a4f` | Both sandstone cliffs. |
| `canyonCapColor` | `#c2aa86` | The pale cap rock at the top of the section. The ledges are a darker shade of it. |
| `forestColor` | `#1d291b` | Spruce-fir forest below the mountain treeline. |
| `meadowColor` | `#48581f` | Subalpine shrubs and meadow above the treeline. Shrub clumps mix it about halfway to the forest color. |
| `tundraColor` | `#484230` | Tundra between the meadow and the snow. |
| `graniteColor` | `#565a5c` | Mountain rock. Lichen tints it gray-green. |
| `stainColor` | `#74605a` | Iron stains on the granite. |
| `talusColor` | `#7b7b77` | Talus, and the block fields on gentle bare ground near the snowline. |

The red beds and ledges keep the ratio, channel by channel, that they had to the rust and the cap rock in the default
palette. Canyon dust and debris, beds too fine to resolve at a distance, and the light the walls throw into shadow
follow the average of the beds weighted by their thickness. The red beds and the dust are lighter than the colors they
follow, so each derived channel stops at 1: red beds under a white rust are white.

### Banding

| Setting | Default | Range | Effect |
| --- | --- | --- | --- |
| `bandSpacing` | 52.36 m (2π / 0.12) | 5 to 1000 | Height of one dark and one light band in version-one canyon rock, and in the coast's optional bands. |
| `bandContrast` | 1 | 0 to 1 | 0 paints banded rock one color: halfway between the two rock colors, or the canyon's distant color. |
| `bandSharpness` | 1 | 1 to 8 | Higher values narrow the transitions between canyon beds. |

Current canyon beds take their spacing from the recipe's `terraceSpacing` so material contacts align with ledges. As a pixel begins to span several beds, their colors converge to the average weighted by bed thickness.

### Blending

A slope is 1 minus the upward component of the surface normal: 0 on level ground, 1 on a vertical face. A slope of
0.08 is about 23° and 0.38 about 52°.

| Setting | Default | Range | Effect |
| --- | --- | --- | --- |
| `rockSlopeStart` | 0.08 | 0 to 1 | Slope where rock starts to replace grass, soil or canyon debris. |
| `rockSlopeEnd` | 0.38 | 0 to 1 | Slope where rock has replaced them. At least 0.01 above the start. |
| `beachHeight` | 3 m | −100 to 500 | Height above the water up to which level ground is sand. |
| `beachWidth` | 15 m | 0.5 to 500 | Height over which the sand fades into the ground above it. |
| `snowline` | 340 m | −1000 to 5000 | World height where snow begins on coast and mountain terrain. |
| `snowTransition` | 65 m | 1 to 1000 | Height above the snowline over which snow becomes complete. |
| `alpineStart` | −110 m | −2000 to 0 | Height relative to the snowline where mountain terrain starts turning to bare rock. |
| `alpineEnd` | −30 m | −2000 to 0 | Height relative to the snowline where it is bare rock. At least 1 m above the start. |
| `treeline` | −150 m | −2000 to 0 | Height relative to the snowline where mountain forest gives way to subalpine meadow. |
| `tundraStart` | −90 m | −2000 to 0 | Height relative to the snowline where the meadow gives way to tundra. At least 1 m above the treeline. |
| `snowSlopeStart` | 0.25 | 0 to 1 | Slope where snow starts to thin. |
| `snowSlopeEnd` | 0.65 | 0 to 1 | Slope beyond which ground stays bare. At least 0.01 above the start. |
| `macroVariation` | 1 | 0 to 3 | Strength of the broad noise that varies the brightness and warmth of grass and plain layers, soil cover, canyon dust and debris, granite brightness, mountain talus, the snowline and the mountain zones. At 1 the snowline moves up to 25 m either way. 0 removes the variation. |

On mountains, winding ribs and patchy noise lower the treeline, the foot of the tundra and the start of bare rock
locally. Gullies raise those boundaries, so rock runs down the ribs and vegetation climbs the gullies.
The broad noise moves these zones 70 m against the snowline at a `macroVariation` of 1: where it raises the snowline
25 m, the zones lie 45 m lower. The transitions break into clumps of trees, shrubs and rock, and avalanche chutes open
the forest down the gullies of steeper slopes. Below the treeline, soil holds on slopes about 0.07 steeper than it does
above it.

### Rock

The scree, slab and lichen fields read these settings wherever a stack uses them.

The `ribs` relief source shades shallow cuts that run in the local downhill direction. Their spacing and course vary across a hillside. `gullySize` sets the typical spacing in meters. The cuts taper into the face at their shoulders and fade into the meadow as the ground levels out.

Natural rock relief follows the landform’s `damping` and `slopeScale`. Fine fractures and loose stones lose their lighting contrast on steep faces before broad gullies do. At a grade equal to `slopeScale` and a damping of 1, the shader gives an eight-meter feature half its original lighting gradient. A damping of 0 gives full relief at every grade. The color layers still place granite, lichen and forest according to their own masks.

| Setting | Default | Range | Effect |
| --- | --- | --- | --- |
| `talusAmount` | 0.5 | 0 to 1 | Share of the moderately steep ground covered by talus. 0 leaves none. |
| `stainAmount` | 0.3 | 0 to 1 | Share of the granite stained with iron. |
| `lichenAmount` | 0.5 | 0 to 1 | Strength of the lichen that tints the granite gray-green in patches. |

## Verify changes

From the engine checkout run `npm run test:terrain`. The appearance suite checks validation, the defaults against the
old shader constants, and the uniform values `bind()` uploads. The surface suite checks derivatives and seams,
transaction replay, roads, residency, and picking. Water tests cover derivative parity, wakes, buoyancy,
shoreline contacts, and fixed-step behavior. Atmosphere tests cover optical depth, boundary cases and cache
invalidation. Renderer tests check bounded residency, complete coverage and revision publication.
Run `npm run test:terrain:gpu` with Chromium installed to compare cached scattering against direct GLSL
integration and to check GPU wave heights, derivatives, velocities, and normals against PHP. It also checks that every
appearance uniform is read, that banded rock does not alias, that mountain rock has no bands, and that talus and stain
colors show nowhere at an amount of 0.

The performance target is hardware-accelerated desktop WebGL2 at 1080p/60 FPS. Record the browser and GPU
alongside measured frame distributions; software WebGL results are correctness evidence, not proof of that
target. A paused and unchanged sandbox should stop issuing draws after outstanding preparation settles.
