# Water and buoyancy

Water changes movement without becoming solid terrain. Add a water region first, then connect switches, buoyant objects, and visual effects as needed. The examples below keep physics, particles, and scene composition separate.

For placement templates and nested settings, see [Authoring courses in Tiled](authoring.md). The [gameplay guide](gameplay.md) lists demo controls and the other reference chapters.

## Try swimming and surface skimming

Choose **Level → Water regions** to use the complete example. Build speed or launch a spindash
from the bank to skim across the surface, or enter the pool to see reduced gravity and reduced control. Skimming can
only begin while grounded; jumping or falling onto the surface enters the water instead. Bodies
may opt into density-based buoyancy, and Tiled properties can add currents. The world behind each
visible liquid rectangle is sampled through a
scrolling displacement texture and then tinted. The HUD and pause menu render afterward and stay
sharp.

## Create a water region

Place `water-region.tj`, resize its unrotated rectangle, and edit its nested `WaterRegion`
settings. Legacy rectangles can use class `WaterRegion`, `Water`, or `water-region`. The boolean `water` property also marks a rectangle; `region` remains available as
a moving-boxes-2 compatibility alias. `WaterRegions::fromMaps()` applies map and object-layer
offsets, including Tiled's bottom anchor for tile objects.

~~~php
use SanePhonics\Physics\{Body,Platformer,TerrainCompiler,WaterRegions};
use SanePhonics\Render\WaterRenderer;

$terrain = TerrainCompiler::build($map);
$waters = WaterRegions::fromMaps($map);
$movement = new Platformer($terrain, waters: $waters);
$body = new Body(80, 100);
$body->density = .8; // Zero disables buoyancy; values below water density float.

$waterRenderer = new WaterRenderer($device->width, $device->height);

// During world rendering:
$waterRenderer->begin();
$mapRenderer->render($camera, $milliseconds);
$spriteRenderer->render($sprites, $camera, $milliseconds);
$waterRenderer->compose($waters, $camera, $milliseconds);
// Draw screen-space HUD and menus here.
~~~

## Choose physics and appearance settings

The physics properties are `gravity` as a gravity multiplier, `drag` as the underwater control and
jump multiplier, `density`, `skimSpeed` in pixels per second, and `currentX`/`currentY` as
accelerations in pixels per second squared. Rendering reads Tiled `color` in `#AARRGGBB` form,
`displacement` in screen pixels, `displacementScale` in world pixels per repeat, and
`displacementSpeed` in map pixels per second.

## Read water contacts and create particles

`Body::$water`, `underwater`, `waterDepth`, `waterEntered`, `waterExited`, and `skimming` expose the
result to gameplay code. Point-segment versus rectangle tests retain entry and exit edges when a
body crosses an entire water volume in one fixed step. Water remains nonsolid; ordinary terrain
still uses full body casts. Surface skimming tests the complete vertical step and cannot miss a
thin boundary solely because the actor is moving quickly.

`WaterParticles` observes those same body edges after movement. Crossing the top surface creates a
one-shot `water-splash` sprite, while an active grounded skim retains one looping `water-skim`
sprite behind the body and flips it with travel direction. Both effects are ordinary ECS entities
with `WaterParticle` and `Sprite` components. Annotate the two animations as Tiled tile classes,
schedule `WaterParticles::update()` after movement, and call `splash($x, $y)` when scripts need an
explicit effect.

## Explore the four water courses

The example includes four focused maps. **Water regions** places the pool surface at exactly the
same Y coordinate as both dry banks. **Underwater rail** starts and ends on dry land, retaining the
grinding state while the rail crosses the water and preserving the exit frame before ordinary
ground resumes. **Surface skim rail** puts two rails at the waterline and overlaps each rail with
the water surface before an open-water gap. A fast body therefore moves from grinding to
skim-grinding to skimming, then back through skim-grinding to grinding without entering the liquid
from a vertical side. `Body::$grinding` and `Body::$skimming` remain independent throughout the
transition. **Rising water loop** rests the loop on the seabed with the initial waterline through
its center, then uses three pressure switches to drain, restore, or fill the pool.

## Connect switches and buoyant platforms

`WaterFeatures` reads object references from Tiled, including legacy `Switch` / `WaterSwitch` objects.
The `switch.tj` template nests `water` and `setPoint` object references under `WaterSwitch`, with optional `fillSpeed`,
`drainSpeed`, and `collisionLayer` values. Its top is a one-way collision edge: a jump passes
through from below, then the descending player lands on and activates the plate. The `block.tj`
template nests buoyancy settings under `BuoyantPlatform`; legacy tile objects with `density` also
become buoyant solids. `hLock` records that their horizontal position is fixed. These
object-reference `water` properties select a volume; they do not turn the control or platform into
another water rectangle. The demo retains the reference map's object ID 3958 for its 32×160,
density 0.8 vertical door block. `activate($switchId)` gives scripts and tests the same action as a
player standing on a switch, while `snapshot()` exposes switches, surfaces, targets, and platform
motion.

~~~php
$features = new WaterFeatures($engine->entities, $terrain, $map, $waters);
$particles = new WaterParticles($engine->entities, $map, $waters);
$features->activate(103);
$engine->systems->add('water-features', [$features, 'update'], after: ['movement']);
$engine->systems->add('water-particles', [$particles, 'update'], after: ['water-features']);
~~~

## Restore and close the water renderer

`WaterRenderer` captures the scene into a framework `RenderTarget` (colour texture, depth buffer
and framebuffer) and owns one procedural RG displacement texture. It copies the scene once, then
submits one clipped quad per visible region. Displacement phase uses world
coordinates, so neighboring regions do not restart their waves. Call `restore()` after the map and
sprite resources on WebGL recovery, and call `close()` before closing the device.
