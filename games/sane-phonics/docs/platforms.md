# Platforms, ropes, and breakable blocks

These features add moving or removable surfaces to the same terrain used by the player. Each manager owns its collision shapes and spawned entities; close it when its level unloads. Water-driven platforms are covered in [Water and buoyancy](water.md).

For placement templates and nested settings, see [Authoring courses in Tiled](authoring.md). The [gameplay guide](gameplay.md) lists demo controls and the other reference chapters.

## Move a platform along an oscillator or path

Choose **Level → Moving platforms** in the main or pause menu. The course includes a green
floating support that sinks eight pixels under a rider, blue horizontal and vertical squine
shuttles, an elliptical float, and two gold platforms following linked routes. The last route
waits for a rider. The ground below provides a return route after a missed jump.

```php
use SanePhonics\Gameplay\{MovingPlatform, PlatformOscillation, PlatformPath, Platforms};

$platforms = new Platforms($engine->entities, $terrain, $map);
$engine->systems->add('platforms', [$platforms, 'update'], after: ['movement']);

// Components can also be supplied directly. Positions are centered bottom anchors.
$id = $platforms->add(
    new MovingPlatform(300, 320, width: 96, height: 16, settle: 8)
    , motion: new PlatformOscillation(x: 160)
);

// Preserve each corner and spend remaining travel on the next segment.
$route = new PlatformPath([[600, 320], [800, 320], [800, 160]], speed: 120, loop: 0);
$platforms->add(new MovingPlatform(600, 320), motion: $route);

$platforms->remove($id); // Removes its entity and collision shape.
$platforms->close();    // Call when retiring the level.
```

`MovingPlatform`, `PlatformOscillation`, and `PlatformPath` are ECS components. Attach an
optional centered, bottom-anchored `Sprite` as the second argument to `add()`. `snapshot()`
reports object IDs, positions, velocities, settlement, and whether a rider is present.
`PlatformPath` uses pixels per second; oscillator times use seconds. A null `loop` stops at the
last point; an index links the last point back to that point. `rideOnly: true` pauses an empty route.

### Configure platform motion in Tiled

In Tiled, use `floating-platform.tj`, `moving-platform.tj`, or `path-platform.tj`.
They nest common fields under `MovingPlatform`, oscillator fields under `PlatformOscillation`,
and route fields under `PlatformPath`. Route nodes use `PathNode.next`. The table below gives
the field names within those groups. Legacy `FloatingPlatform`, `MovingPlatform`, and `PathPlatform`
objects also load, as do legacy `block` objects with nonzero `float`. Tile objects use Tiled's bottom-left anchor; ordinary rectangles
use their top-left anchor. Both become centered bottom anchors internally. Object and inherited
layer offsets are included. Rotated collision rectangles are rejected; GID flips remain supported.

| Property | Meaning |
| --- | --- |
| `oneWay` / `platform` | Pass upward through the top; defaults to true. False makes all sides solid; legacy `block` defaults to solid. |
| `settle` | Signed pixel displacement under a rider, recovered after the rider leaves. |
| `oscillateX`, `oscillateY` | Signed pixel excursions. One axis uses the reference squine curve; two use sine/cosine. |
| `squinePeriod` | Single-axis squine cycle in seconds; defaults to `1.5 * pi`. |
| `timeX`, `timeY` | Two-axis divisors in reference frames; defaults to 90 frames each. |
| `offset` | Oscillator phase in reference frames. |
| `path` | Tiled object reference to the first route point. |
| `ridePath` | Same route reference, activated while a rider stands on the platform. |
| `next` | On each route point: reference to its successor. Missing/zero ends the route; cycles loop. |
| `pathSpeed` | Reference pixels per frame (60 Hz), converted to pixels per second; defaults to 8. |
| `collisionLayer` | Shared plane 0 or a positive selected plane. |
| `outline` | Optional sprite outline override; moving platforms default to true and legacy `block` objects to false. |

### Carry riders and handle obstructions

Platforms update after body movement, so jumping, walking off, and dropping through release the
rider before transport. Foot rays verify the actual support shape. Full-displacement body casts
carry riders and detect platforms pushing stationary bodies, including fast crossings. A passenger
blocked by a wall or ceiling stops the platform instead of being pushed through terrain. Path
segments are swept separately, preserving corners even when one update passes several nodes.
Platforms move on their authored routes; route geometry itself does not stop against map tiles.

This is the suspended-block behavior from moving-boxes-2. Water-density platforms remain handled
by `WaterFeatures`. Regenerate only this course and its original artwork with
`php examples/generate-platforms.php`.

## Bend a bridge or rail under its riders

Choose **Level → Bendable bridges & rails** for two flexible decks and a deforming grind rail.
`BendableBridge` stores a sampled surface, while `BendableBridges` owns its removable collision
path and repeated tile sprites. Each grounded `Body` contributes a smooth load at its own contact
point. Loads add, so several actors can make several low points instead of fighting over one global
offset. `maxLoads` caps their combined displacement.

```php
use SanePhonics\Gameplay\{BendableBridge, BendableBridges};

$bridges = new BendableBridges($engine->entities, $terrain, $map);
$engine->systems->add('bridges', [$bridges, 'update'], after: ['movement']);

$bridge = new BendableBridge(
    x: 256
    , y: 320
    , width: 384
    , droop: 48
    , segmentWidth: 16
);

$id = $bridges->add($bridge);
$state = $bridges->snapshot();
$bridges->remove($id);
```

### Configure bridge shape and collision

Use `bendable-bridge.tj` or `bendable-rail.tj` and edit the nested `BendableBridge` settings.
Legacy classes `BendableBridge` and `BendableRail` remain supported. The rail marks every changing surface segment
as grindable. A legacy moving-boxes-2 `block` with a `droop` property loads as a bridge as well.
Tile objects use their bottom edge as the anchor; rectangle objects use their top edge. `droop`
sets the centered per-body depth, `segmentWidth` controls collision and visual sampling, `response`
sets spring speed, `maxLoads` caps summed loads, and `collisionLayer` selects the collision plane.
The optional `grinding` property turns either class into a rail.

The first and last collision segments remain flat at the authored Y coordinate. Adjacent ordinary
terrain can therefore share both endpoint height and tangent, avoiding a lip or vertical crack as
a body walks, rolls, or grinds on and off. The manager replaces one open-path terrain handle after
movement and places every supported body on the new curve. Surface casts still cover the complete
movement distance, so high-speed entries cannot tunnel through a bent span. Call `close()` when the
level unloads. Regenerate the course and its plank and rail tiles with
`php examples/generate-bridges.php`.

## Hang from a fixed or moving rope

Choose **Level → Grapple ropes** in either menu. Jump into a gold handle to grab it automatically,
hold Left or Right to pump the swing, and press Jump again to release. The course includes two
fixed suspensions, a horizontal squine shuttle with `noSwing`, and a suspension following a linked
path. All three player profiles can hang. Space / Z and controller A / Cross use logical button 0.

### Connect the grip, handle, and anchor

`GrappleGrip` opts a player into contact. Each handle entity owns `GrapplePoint` and `Rope`;
`RopeAnchor` supplies its fixed or moving suspension. `SpriteGroup` repeats atlas tiles along the
rope, so the existing sprite renderer and context-restoration path handle the artwork.

```php
use SanePhonics\Gameplay\{GrappleGrip, GrapplePoint, Grapples, Rope, RopeAnchor};

$registry->add($player, new GrappleGrip);
$grapples = new Grapples($registry, $terrain);
$anchor = $grapples->anchor(new RopeAnchor(200, 80));
$handle = $grapples->add(new GrapplePoint, new Rope($anchor, 200, 240, 160));

// In the player movement update, before shields, flight, or ordinary jumping:
$jumpConsumed = $grapples->control($player, $controls, $dt);
$movement->step($body, $controls, $dt, $jumpConsumed);

// After player movement and any moving-anchor systems:
$engine->systems->add('grapples', [$grapples, 'update'], after: ['movement', 'platforms']);

$grapples->release($player);       // Detach with inherited momentum.
$grapples->sync($player, $body);   // Call after teleporting the player.
$grapples->remove($handle);       // Releases riders and removes the handle and rope sprites.
$grapples->close();               // Retires owned handles and anchor proxies when unloading.
```

While `Body::$ability` is `hanging`, the rope controls position and ordinary platformer movement
pauses. Skip other movement abilities while hanging or when `control()` consumes Jump. Release
adds three quarters of the profile's jump speed, uses moving-anchor velocity when present and
rope velocity otherwise, and prevents regrabbing the same handle for 0.25 seconds. The first rider
controls a shared swing. Damage can replace the ability and velocity; detachment preserves that
knockback. `PlatformCamera` selects its `hooked` framing automatically.

### Link authored handles to their anchors

Pass a loaded map as the third `Grapples` constructor argument to load `grapple-point.tj`
placements or legacy `GrapplePoint` / `grapple-point` objects. The nested `GrapplePoint.tiedTo`
field is a Tiled object reference; select the suspension in this map after placing the template. `ropeLength` is a
positive maximum distance in world pixels, read from the handle, then its anchor, then defaulting
to 64. `noSwing` keeps the handle horizontally below its anchor. Optional `collisionLayer`
restricts contact to a plane; zero is shared. Points use their exact coordinates, ordinary
rectangles use their bottom center, and tile objects use Tiled's bottom-left image placement.
Map and inherited layer offsets are applied once. Rotated object rectangles are rejected.

An optional fourth constructor argument, `resolveAnchor($sourceId): ?int`, resolves a suspension
object to an existing `Body`, `MovingPlatform`, or `BuoyantPlatform` entity. Unresolved objects
become fixed anchors. Removing or disabling an anchor releases its riders. The optional fifth
argument, `onChange($player, $handle, $attached)`, lets application code activate a carrier or
observe attachment. Followed entities remain owned by their original systems.

Tiles annotated `grapple-handle`, `rope-segment`, and `rope-anchor` supply fallback art; an explicit
object GID overrides the handle or anchor image and preserves Tiled flips. Layers retain tint,
opacity, and visibility. Scripted handles accept a `Sprite`, a repeated segment GID, and its world
height through `add()`. `snapshot()` exposes handle and anchor positions, velocity, source IDs,
length, `noSwing`, availability, and riders. The demo publishes it as `canvas.dataset.grapples`.

### Handle contact and blocked movement

Contact uses relative swept bounds. Handle motion, passenger transport, and attachment alignment
cast their complete displacement through terrain to prevent high-speed tunneling. Standing
bounds must fit beneath a handle before attachment succeeds. If an obstructed passenger cannot
follow a moving anchor within the rope length, the passenger releases. The rope is a constrained
endpoint with a straight visual span; it does not wrap around terrain corners. Regenerate the
course and original handle/chain artwork with `php examples/generate-grapples.php`.

## Break blocks into particles

`BreakableBlocks` adds a stationary rectangular collider to the same `Terrain` used by the player. It uses the sprite's crop, anchor, rotation, and scale at creation. Collision includes transparent pixels inside that rectangle. Blocks stop walking and support standing, like solid wall tiles. Bind `impact()` to the platformer to break them on rolling contact.

~~~php
use Phonics\Effects\ParticleSystem;
use SanePhonics\Gameplay\BreakableBlocks;
use SanePhonics\Physics\Body;
use Phonics\Physics\Hit;
use SanePhonics\Physics\Platformer;
use Phonics\Render\Sprite;

$blocks = new BreakableBlocks($engine->entities, $terrain, $map);
$image = new Sprite(1, 320, 448, 0, 1);
$image->scaleX = $image->scaleY = 3;
$block = $blocks->create($image, columns: 4, rows: 3);

$movement = new Platformer(
    $terrain,
    onImpact: fn(Body $body, Hit $hit): bool =>
        $blocks->impact($body, $hit, $engine->time * 1000)
);

// Use this controller in your movement system, then update debris.
$engine->systems->add('particles', [new ParticleSystem, 'update'], after: ['movement']);

// In the render callback, after drawing the map:
$sprites->renderEntities($engine->entities, $camera, $engine->time * 1000);

// In scene cleanup, before closing the terrain owner:
$blocks->close();
~~~

### Break a collider without stopping movement

`create()` returns an entity with `Sprite` and `BreakableBlock` components. `collisionLayer: 0` makes it solid on every plane; supply a positive plane to restrict it. Keep the manager alive and close it when the scene ends. Blocks stay at their initial collision pose; editing a sprite later does not make a moving platform.

`impact()` checks `Body::$rolling` and the contacted shape. Walking leaves the block solid; rolling removes it before collision response, preserving speed and the remaining movement in that step. Each new sweep finds the next live obstacle, so a fast roll can break a chain of blocks and still stop at an ordinary wall. Blocks on inactive collision planes or behind a wall stay intact. Sensor probes and standing on a block do not trigger breaking. Call `shatter($block, $milliseconds)` directly for other damage or scripted triggers.

### Create and retire particle fragments

`shatter()` freezes the current animation frame, removes the collider and primary sprite, and attaches `SpriteGroup` and `ParticleBurst` to the **same entity ID**. It returns `true` once, then `false` for repeated calls. The new group contains exactly `columns * rows` pieces. Uneven image dimensions distribute integer pixels without overlap or gaps. Grid dimensions must be positive and no greater than the cropped source dimensions. Invalid grids, crops, or motion settings throw before changing an intact block.

Pieces inherit flips, rotation, scale, tint, opacity, and the outline flag, then move outward under gravity, spin, and fade. Breakable blocks disable outlines on both their intact sprite and shards. They share the original atlas and one instanced sprite batch. No fragment textures or per-piece entities are allocated. `shatter()` accepts named `speed`, `lift`, `gravity`, and `lifetime` settings, in pixels and seconds. Defaults are 240 px/s outward speed, 240 px/s upward lift, 900 px/s² gravity, and a 1.2-second lifetime. Debris is nonsolid.

`ParticleSystem::update()` queues destruction of expired particle entities; the scheduler flushes that queue after its systems finish. Call `Registry::flush()` yourself when driving the system directly. It destroys the whole particle entity, including any other components. Removing an intact `BreakableBlock` or destroying its entity releases the collider. `BreakableBlocks::close()` destroys its remaining blocks and debris while leaving unrelated terrain alone.

For an effect independent of breakable terrain, create `ParticleBurst::grid($sprite, $map, $columns, $rows, $milliseconds)` and attach both the returned burst and `$burst->sprites` to a disposable entity. Alternatively, call `$burst->step($dt)` yourself and use `finished()` to manage its lifetime. This lets a persistent actor own a group without letting `ParticleSystem` retire that actor.

### Remove other terrain shapes

Terrain also exposes `addShape($points, $oneWay, $collisionLayer): int`, `hasShape($handle): bool`, and `removeShape($handle): bool` for other removable obstacles. Handles are never reused. `Hit::$shape` identifies the removable shape, or is `null` for permanent geometry. Removal updates spatial indexes, polygon occupancy, and shared-edge cancellation immediately. Change geometry between physics steps or in the platformer's `onImpact(Body, Hit)` callback. The callback runs after the earliest body cast and before collision response; only actually removing the contacted shape allows passage, regardless of the return value. Casts and adhesion raycasts remain read-only. Fast movement cannot skip an intact block, and removing a support releases a resting player on the next step.
