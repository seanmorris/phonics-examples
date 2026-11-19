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

Coordinates are metres, Y is up, and the square world's X/Z bounds are centered on zero.
Queries outside the bounded world are rejected. `height()` and `sample()` use the same authoritative
Catmull–Rom reconstruction. A renderer can change its mesh resolution without changing these queries.

The generator combines finite smooth Perlin bands with a separate larger-scale slope guide.
Steeper guide slopes attenuate fine-detail **amplitude**, especially at the finer octaves.
The guide never feeds back from an already attenuated surface. Rounded mountain ridges avoid absolute-value cusps.
Surface normals differentiate the final reconstructed height, including all terrain edits.
Optional domain warps and quintic terraces retain continuous derivatives; the guide includes their full derivative chain.
`warpAmplitude` bends the larger landforms, while `terraceSpacing` and `terraceStrength` add smooth terrace-like steps.
In new canyon recipes, exposed rock and physical ledges share a sequence of dipping beds. Terrace spacing scales the thickness of the sequence in metres. Resistant beds form cliffs above shelves, and softer beds form debris slopes. Version-one recipes retain their original canyon geometry for replay.
Smooth joins preserve derivatives; a heightfield still cannot represent an exactly vertical face or an overhang.

Only requested lattice nodes are generated. Packed tiles have a bounded LRU cache; `pin()` reserves a small
region for a physical body independently of camera travel. `unpin()` and `close()` release ownership.

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

A transaction contains all operations queued before one `commit()`. Supported operations are raise, lower,
flatten, smooth, crater, material, and road. Undo/redo works on transactions. Saved data includes the generator
version and edit history; derived sample caches and road acceleration indexes are rebuilt on load.
Finite operation and road-kernel limits keep malformed files from allocating unbounded work.
For distant pointer picking, use `rayQuery()` and advance it with a time budget and a maximum number of traversal steps.
The picker skips blocks whose full height range misses the ray, including the effects of edits and cubic
interpolation. It solves the remaining cells in front-to-back order. Both picking APIs return the same first hit.

A visible application should prepare a candidate with `Landscape::load($ground->save())`, edit that candidate,
and call `Scene::prepareLandscape()`. Continue simulating and drawing the old surface while preparation runs.
At a fixed-update boundary, `takePreparedLandscape()` publishes the prepared mesh revision and returns the
surface to install in physics. New operations must wait until the pending transaction completes.

Roads grade terrain using smoothly blended sampled spline kernels, including their shoulders and ends.
At a junction, operations apply in explicit authoring order; roads should agree on their crossing elevation.
Bridges remain separate meshes. There is one ground height at each X/Z position, so this representation
cannot describe caves or overhangs.

## Draw and simulate

`Camera` supplies flight motion, a perspective matrix, and picking rays. `Scene` owns GPU meshes and shaders;
call `update()` with a bounded generation budget, then `render()` when the camera, simulation, or preparation
changes. The caller owns the graphics device and frame presentation. Call `restore()` after context loss and
`close()` on unloading. `stats()` exposes residency, pending work, mesh bytes, generation time, and frame count.
Patch selection combines distance with sampled projected error; it is not a certified error bound for every
unsampled feature. Nearby patches in a padded camera frustum refine first. Ground outside it keeps coarse
coverage, and a bounded motion lookahead prepares likely future coverage.

The sandbox activates `browser/PatchMesher.mjs` and passes a `BrowserPatchBuilder` to `Scene`. A dedicated
worker samples the seeded recipe in sibling groups and sends packed vertices, normals, and error bounds
through one reusable in-memory file. Patches with edited samples or off-lattice coordinates use the PHP
sampler. PHP also handles collision and authoring queries in both cases. The renderer starts drawing as soon
as the root mesh is ready and refines the view while the player moves.

`Water` evaluates analytic directional waves and bounded wake packets. Its shader include uses the same
parameters and derivatives as PHP. `Watercraft` samples several buoyancy points and adds damping, drag,
steering, planing, and shoreline contacts. It is an arcade test craft, not a full rigid-body engine.

Above 2 m/s, a hull in contact with the water emits a wake about every 0.2 seconds.
Foam follows the stern and spreads as the trail ages. The sandbox’s **Shore and wake foam**
control sets the amount visible.

`Atmosphere` supplies a spherical exponential Rayleigh/Mie model with a cached transmittance texture.
Sun direction, haze, and exposure have independent controls. This implementation uses single scattering;
multiple scattering, clouds, ozone absorption, and volumetric fluid simulation are not implemented.
Use `Atmosphere::setNightGlow($strength)` to set the blue night fill. The default is 0.5. Custom materials can read the linear RGB `nightAmbient` from `Atmosphere::uniforms()`. The glow and stars fade in between sunset and a solar elevation of −10°. The stars keep fixed directions as the camera moves.
Terrain shadows use a coarse heightfield cache for broad mountain shadows. Water reflects the cached sky with
a lower-environment approximation; it does not reflect individual terrain objects. Distant wave normals and
foam are filtered for their pixel footprint without changing the physical waves.

Call `$water->setLevel($metres)` to move the mean surface and `$water->setWaveScale($multiplier)` to change swell height and steepness during play. A scale of zero leaves only local wakes, and one restores the authored waves. Rendering, buoyancy samples, and visibility bounds share those values.

Set `$water->appearance = new WaterAppearance([...])` to tune water rendering. Use `waterColor` (sRGB hex), `waterTransparency`, `waterReflectivity`, and `waterFoam` (fractions), and `waterFogDensity` (extinction per metre). The renderer blends sky reflection and foam over the opaque scene. Submerged terrain fades toward the water tint according to the length of the sightline below mean water level. Draw custom opaque objects with the optional `drawOpaque` callback to `Scene::render()` so the water surface covers only their submerged portions. Their shaders can use `WaterAppearance::shaderSource()` and `bind()` for the same underwater fog.

## Verify changes

From the engine checkout run `npm run test:terrain`. The surface suite checks derivatives and seams,
transaction replay, roads, residency, and picking. Water tests cover derivative parity, wakes, buoyancy,
shoreline contacts, and fixed-step behavior. Atmosphere tests cover optical depth, boundary cases and cache
invalidation. Renderer tests check bounded residency, complete coverage and revision publication.
Run `npm run test:terrain:gpu` with Chromium installed to compare cached scattering against direct GLSL
integration and to check GPU wave heights, derivatives, velocities, and normals against PHP.

The performance target is hardware-accelerated desktop WebGL2 at 1080p/60 FPS. Record the browser and GPU
alongside measured frame distributions; software WebGL results are correctness evidence, not proof of that
target. A paused and unchanged sandbox should stop issuing draws after outstanding preparation settles.
