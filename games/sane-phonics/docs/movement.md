# Player movement and camera

Use these systems to move a player through terrain, follow ring trails and rails, change gravity, and frame the action. Start with `Platformer`; add the abilities your game needs. Each section includes its own update order and reset requirements.

For placement templates and nested settings, see [Authoring courses in Tiled](authoring.md). The [gameplay guide](gameplay.md) lists demo controls and the other reference chapters.

## Move against terrain

`Platformer` is this package's PHP movement implementation. Units are pixels and seconds; the default simulation rate is 60 Hz.

- Acceleration, braking, friction, slope gravity, rolling, variable jump height, jump buffering, and coyote time.
- Surface-tangent ground speed and outward-normal jumps. Sensors follow slopes, walls, ceilings, and loops while momentum remains sufficient.
- Jump somersault state and compact collision height persist through the full arc, then clear on landing. Plain ledge falls remain standing and do not count as attacks.
- Ground and rail departures share MB2-style air steering: 1,080 px/s² acceleration below the running-speed limit, jump-only apex drag, and halved upward velocity on Jump release. Ordinary air steering retains the takeoff look direction.
- Crouching and tap-charged spindashes, runner drop dashes, reversible glide with wall catching, climbing, ledge-top transitions and jumping, and horizontally steerable flight with a locked airborne look direction and finite lift budget.
- Solid polygons, tile collision shapes, one-way platforms, and down+jump drop-through.
- Per-body collision planes and directional Tiled layer switches for open loops, including reversed crown gates and rolling-only gates.

### Sweep the whole body against terrain

Terrain::castBody casts the center against segments expanded by the oriented body. The earliest time of impact limits the move; remaining motion slides along the surface. It covers the whole displacement, including tiny obstacles between point sensors. Short Terrain::raycast calls handle foot adhesion, head clearance when standing, and wall contact while climbing.

Terrain is spatially indexed and matching internal tile edges cancel. The platform systems handle rider carrying and platform pushes; arbitrary body-to-body collision and recovery from spawning inside solid terrain are not implemented. Place bodies in empty space. Sweeps resolve at most six contacts and discard unresolved motion in tight corners.

### Choose collision planes for loops

Select **Loop-de-loop** and hold Right to run the loop, then Left to return through it. [`examples/assets/loop.tmj`](../examples/assets/loop.tmj) splits an 8×8 circular tileset into a shared crown/floor and two selectable lower halves. `layer-switch` objects outside the entrances select the far wall. A `back=true` gate at the crown changes halves so the character can descend and exit. The rule follows ground speed around the surface, including the ceiling.

`Body::$collisionLayer` defaults to 1. `TerrainCompiler::build` (the framework's `MapTerrain::compile` with
Sane's grinding, skip and object hooks) reads the integer `collisionLayer` property, or names such as `Collision 0`, `Collision 1`, and `Collision 2`. Zero is common terrain, always included. Set `collision=false` on decorative layers that reuse collision tiles. Rendering remains independent of collision selection. `Terrain::add($points, $oneWay, $collisionLayer)` supports the same planes for geometry created in PHP; `raycast(..., collisionLayer: $body->collisionLayer)` selects the plane for custom probes, and `castBody` reads it from the body.

Gate crossings are swept over the full displacement and processed before later terrain impacts. After each switch, the remaining movement is recast against the destination. Blocked switches retry while overlapping; neither thin gates nor newly solid obstacles are skipped at high speed. The [layout and authoring guide](loops.md) includes the reference map analysis, a diagram, and all switch properties.

## Change gravity and launch from springs

Each `Body` has a cardinal gravity unit vector in `gravityX` and `gravityY`; gravity magnitude remains in `gravity`. `setGravityDirection()` rejects zero and diagonal vectors. Ground acceleration, air steering, terminal speed, jump release, flight, glide, drop-through, support loss, and landing all project through the active gravity and logical-right tangent axes. Existing down-gravity behavior keeps the same tuning.

Landing compares the terrain contact normal with the active gravity vector. A ceiling whose normal points down therefore becomes ground when gravity points up, and either wall becomes ground for horizontal gravity. `Terrain::castBody()` still resolves the whole displacement, so a fast body cannot tunnel through a ceiling before regrounding.

An airborne body moving against gravity at 180 px/s or faster can also acquire an angled upper surface. This mirrors moving-boxes-2's separate sloped upper-collision path: the impact projects velocity onto the terrain tangent, then ordinary adhesion follows the wall-to-ceiling curve while momentum remains sufficient. A flat ceiling remains a head bump. The tests rotate the same spring, wall, quarter curve, and ceiling through all four gravity vectors and both curve directions.

### Author contact edges and launch strength

Place `gravity-switch.tj` or `spring.tj` and edit the corresponding nested settings.
Legacy point objects with class `GravitySwitch` or `Spring` remain supported. Object rotation defines the contact edge: zero faces up, 90 faces right, 180 faces down, and 270 faces left. Both accept `length`, `collisionLayer`, and `spriteGid`. A gravity switch accepts `gravity=down|right|up|left`, or explicit `gravityX` and `gravityY`; a spring accepts positive `power` in pixels per second.

~~~php
$features = new GravityFeatures($entities, $terrain, $map);
$systems->add('gravity-features', [$features, 'update'], after: ['movement']);

$features->activate($switchObjectId, $body);
$features->launch($springObjectId, $body);
$state = $features->snapshot();
~~~

Both features are ECS components with atlas-backed sprites and stable Tiled object IDs. Their directed one-way collision edges preserve authored wall and ceiling normals. Springs launch along that normal, clear ground speed, and leave `jumping=false`, matching a forced airborne bounce rather than a player jump. `GravityFeatures::close()` removes every owned entity and collision edge.

Choose **Gravity switches & springs** in either menu. The wide chamber gives the player room to land and explore before reaching each deliberately spaced pressure plate. The four plates cover floor, right-wall, ceiling, and left-wall gravity, while a spring faces inward from each surface. [`examples/assets/gravity.tmj`](../examples/assets/gravity.tmj) is the complete authoring example.

Choose **Curved ceiling springs** for the focused regression course. It starts on a spring and needs no input: four stations launch the player beside a wall, carry the player around a quarter curve, then change gravity for the flight to the next station. The cycle covers down, left, up, and right gravity before returning to its starting spring. **Shift+R** restarts the sequence. [`examples/assets/ceiling-curves.tmj`](../examples/assets/ceiling-curves.tmj) keeps each smooth collision path as a solid Tiled polyline; [`examples/assets/ceiling-curves.png`](../examples/assets/ceiling-curves.png) renders the matching guide rails.

## Follow a ring trail with light dash

Choose **Level → Light dash** in either menu with the **Runner** profile. Jump from the start
and hold **C** or controller **X / Square** to follow the first arc. The second arc has a wider
gap between rings. Either trail works in reverse; restart the level to restore collected rings.

### Acquire targets and schedule control

`Gameplay\LightDash` stores one player's target, cooldown, timeout, and combo reward.
`LightDashes::control()` acquires a ring within 48 world pixels only when another ring continues
the trail within 48 pixels. Horizontal velocity excludes entrance rings behind the player.
Subsequent pickups redirect to the nearest eligible ring within 128 pixels. Delayed or decorative
scattered rings cannot become targets. Releasing the button leaves an acquired dash active.

~~~php
use SanePhonics\Gameplay\{LightDash, LightDashes, RingCounter, Rings};

$player = $engine->entities->create($body, new RingCounter, new LightDash);
$dashes = new LightDashes($engine->entities);
$rings = new Rings($engine->entities, $map, onCollect: [$dashes, 'collected']);
$rings->sync($player, $body);

// Each fixed step: dash control, other abilities, movement, then ring pickups.
$dashing = $dashes->control($player, $engine->controls, $dt);
$jumpConsumed = $dashing || ($shields?->control($player, $engine->controls, $dt) ?? false);
$movement->step($body, $engine->controls, $dt, $jumpConsumed);
$rings->update($engine->entities, $dt);
~~~

### End a dash and preserve momentum

Speed follows MB2's distance/angle calculation, capped at 1,650 world pixels per second
(55 × 0.5 pixels per reference frame). Each target has a nine-frame timeout, and starting a dash
sets the same cooldown. Gravity continues, directional steering is suppressed, and
`Platformer::step()` sweeps the whole displacement against terrain. A solid impact ends steering;
landing, damage, profile changes, a missing target, or an exhausted trail also release it.
Momentum survives a normal trail exit. `Body::$lightDashed` prevents releasing Jump from cutting
the exit's upward velocity and clears on landing. Reset the `LightDash` component and this body
flag when teleporting or resetting a player.

An existing `Combo` gains one **Light Dash** reward, increasing by 10 points per acquired target.
A dash alone does not start a combo. `Body::$ability === 'lightdash'` identifies the active pose.
The generic `Rings` pickup callback receives the collector ID, removed ring ID, and `Ring` after
awarding the pickup; redirects wait until the next control step so all same-step pickups retire.

Dropdash direction follows MB2's landing input order: a held horizontal direction selects the
boost on an ordinary moving landing. Neutral input retains the previous orientation. The boost
adds to existing momentum, so opposite input can reverse a slow landing or brake a faster one.
Fast grinding keeps its travel-direction lock.

## Grind rails and score combos

Set the boolean `grinding` property on a Tiled collision layer to make its surfaces
grindable. An object polyline with class `Rail` or `Grinding` creates an open one-way rail;
`collisionLayer` selects its collision plane. Scripted levels use the same path by calling
`Terrain::addRail()` with ordered world-space points.

~~~php
use SanePhonics\Gameplay\{Combo,ComboReward};
use SanePhonics\Physics\{Body,Platformer,Terrain};

$terrain = new Terrain;
$terrain->addRail([[64, 224], [320, 192], [576, 224]]);
$movement = new Platformer($terrain);
$combo = new Combo;

// During each fixed update:
$movement->step($body, $engine->controls, $dt);
$banked = $combo->update($body, $dt);
$counter->score += $banked ?? 0;

// Game-specific tricks can join the same chain.
$combo->add(new ComboReward('Backflip', 250, 2));
~~~

### Enter and leave a rail

A rail contact clears rolling, preserves `Body::$direction` against opposite input, lets that input
change the independently rendered `Body::$facing`, enforces the reference minimum speed, accelerates
along its tangent, and permits the lower-friction Down pose.
Jump leaves along the current surface normal. A rail endpoint retains tangent momentum and becomes
a plain fall, so a chain can continue onto another rail. The body cast checks the entire movement
distance before attaching, which prevents fast downward movement from tunneling through a thin rail.

Choose **Level → Grinding rail** for a complete Tiled example. It begins with a level
rail-to-ground-to-rail transition, then two independent quarter bends lift the track to an upper
straight and two lower it again before a separate loop. Collision layers 1 and 2 hold the loop's
alternate lower halves; shared layer 0 holds its crown and straight rail. Directional switches at
both entrances and a reversed switch at the crown select a passable route in either direction. The
stop wall after the exit clears tangent motion so opposite input can run the complete course in
reverse. Open `examples/assets/grinding.tmj` and `grinding.tsj` to inspect the annotations, object
polygons, and collision planes.

### Bank or lose a combo

`Combo` adds one live `Rail Grind` reward per rail run and grows its points with speed. `add()`
accepts game-specific rewards while airborne. An ordinary grounded landing banks
`ceil(sum(points) * sum(multiplier))`; `fail()` clears the chain and exposes the lost value without
awarding it. Both results remain available for a configurable duration. The example renders up to
four recent labels, overflow, chain arithmetic, and the success or failure result in the top-right
bitmap HUD. Enemy damage fails a player-owned `Combo` automatically.

## Follow the player with the camera

`Gameplay\PlatformCamera` reimplements moving-boxes-2's actor framing as a renderer-independent
controller. Automatic mode follows ordinary ground movement, waits through short falls before
switching to aerial framing, tracks climbing, and uses tube framing while rolling on walls and
ceilings. Speed shifts the actor away from the direction of travel to show more of the route
ahead. Floor, wall, and ceiling orientations rotate that look-ahead onto the active surface.

The controller also exposes the reference modes for scripted sequences: `panning`, `boss`,
`perspective`, `bridge`, `cliff`, `walker`, `airplane`, `boost-ring`, `cross-cannon`,
`cross-cannon-quick`, `cutScene`, `corkscrew`, `popping`, `hooked`, `cinematic`, `draggable`, and
`locked`. `focus()` and `clearFocus()` pan to and from a world point. `setWorldBounds()` confines
the visible span, while `setCameraLimits()` applies direct top-left limits such as a level floor.
Both bounds constrain the destination before damping, so changing a target or screen lock does not
snap the current view. Anchor and position damping are independent of the simulation step size.

~~~php
use SanePhonics\Gameplay\PlatformCamera;

$cameraBehavior = new PlatformCamera;
$cameraBehavior->setCameraLimits(maxY: 120);

// Call after movement. The final argument is a filtered up/down look axis.
$cameraBehavior->update($camera, $body, $dt, $look);

$cameraBehavior->focus($switchX, $switchY); // Smooth scripted pan.
$cameraBehavior->clearFocus();              // Smooth return to the actor.
$cameraBehavior->setMode('cinematic');       // Explicit framing until setMode('auto').
~~~

Holding Up or Down for 0.75 seconds applies the reference look framing when the application passes
that input to `update()`. The demo passes it only for a stationary grounded actor, preserving
crouch, spindash, grinding, and rolling controls. `sync()` clears stale air and look timers after a
spawn or teleport. The debug overlay reports the resolved mode and smoothed anchor.
