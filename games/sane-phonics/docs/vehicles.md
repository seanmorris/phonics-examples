# Vehicles and riders

A vehicle has its own body while the rider keeps their character, inventory, and abilities. The skateboard example introduces that shared lifecycle. Tornado and EggWalker use it for flight and combat.

For placement templates and nested settings, see [Authoring courses in Tiled](authoring.md). The [gameplay guide](gameplay.md) lists demo controls and the other reference chapters.

## Ride and manage a skateboard

Choose **Level → Skateboard course** from either menu. The player drops onto the first board;
spares sit farther along the course. Accelerate across ordinary ground, follow four quarter bends,
and ride a loop with collision-layer switches. Rings above the entry provide jump/trick targets.
All three character profiles can ride the same board.

| Action | Keyboard | Standard gamepad |
| --- | --- | --- |
| Steer or brake | Left / Right | Left stick / D-pad |
| Crouch | Down | Down |
| Jump with the board | Space / Z | Button 0: A / Cross |
| Lean back | X | Button 1: B / Circle |
| Handstand | C | Button 2: X / Square |
| Manual | V | Button 3: Y / Triangle |
| Jump off | Up + Space / Z | Up + button 0 |
| Restore the course | Shift+R | Hold B / Circle, press Back / Select |

Hold a trick button to sustain its pose. Airborne leanbacks and handstands start a combo; manuals
extend an existing chain through a landing. Grinding extends that chain with one accumulating
rail reward. Releasing the trick and reaching ordinary ground banks the score in the rider's
inventory. Reverse travel relative to facing produces fakie labels. Jump and trick buttons belong
to the vehicle while seated, so they cannot also trigger flight, gliding, shields, or light-dash.

### Connect a rider to a vehicle

`Rider` opts a character into boarding. `Vehicle` belongs to a separate entity with its own `Body`
and optional `Sprite`. The character keeps its identity, movement profile, inventory, combat,
and combo components. `VehicleBehavior` supplies mounted, before-movement, and after-movement
callbacks. `Skateboard`, `Tornado`, and `EggWalker` share that chassis lifecycle while contributing
their own movement state and reusable capabilities.

```php
use SanePhonics\Gameplay\{Rider, Vehicle, Vehicles, Skateboard};

$registry->add($player, new Rider);
$vehicles = new Vehicles($registry, $terrain, $movement, $map);

// Or add a scripted board. Body coordinates describe its center, six pixels above the wheels.
$board = new Vehicle(Skateboard::body(320, 442), seatHeight: 12);
$boardEntity = $vehicles->add($board, new Skateboard, new Sprite($boardGid));

// Once per fixed update, before character-specific abilities:
$consumed = $vehicles->control($player, $controls, $dt);
$movement->step($body, $controls, $dt, $consumed);
$vehicles->update($registry, $dt);

// After moving platforms, before pickups/rendering; this does not step physics again.
$vehicles->carry($registry, $dt);
```

Use the returned `control()` flag to gate other ability systems. `Platformer` leaves a seated
character's movement to the vehicle. The chassis uses the existing slopes, rails, water, and
layer-switch solver. Passenger casts check the full displacement of each short curved segment
against walls and ceilings. Boarding also sweeps the falling character's path; it still works
when a fast fall reaches the floor in the same step. Rings sweep both rider and chassis bounds
and credit the rider once. Monitor and badnik rewards also retain the rider as their owner.

### Board, dismount, and clean up

`mount($player, $boardEntity)` explicitly takes an empty seat if clearance permits it.
`dismount($player)` uses the Up+Jump exit; `lockedIn` prevents this voluntary exit and `quickDrop`
makes button 0 request it without Up. `dismount($player, 'transfer')` hands off the chassis surface and velocity when clearance permits;
`'damage'` preserves externally applied knockback and `'reset'` leaves motion to the caller.
Removing either body's component releases the association. Call `sync($player, $body)` after a
teleport and `close()` when unloading the level. `close()` removes owned vehicles and regions while
preserving character entities. `Vehicle::forRider($registry, $player)` resolves a live mutual seat
association for other gameplay systems.

### Place vehicles and boarding regions

Use the `skateboard.tj`, `vehicle-region.tj`, or `drop-vehicle-region.tj` template.
The vehicle settings are nested under `Skateboard`; region settings use `VehicleRegion`.
Legacy objects use class `Skateboard` / `skate-board`, or `VehicleRegion` / `DropVehicleRegion`.
Tile objects anchor at the bottom; point objects identify the wheel contact point. `seatHeight`,
`seatForward`, and `seatAngle` configure the seat in pixels and radians. Objects also accept
`lockedIn`, `quickDrop`, `direction`, and `collisionLayer`. Layers contribute offsets, tint, opacity,
and collision planes; Tiled GID flips are decoded before atlas lookup. Objects must be axis aligned.
An annotated `skateboard` tile supplies fallback artwork, avoiding hard-coded atlas positions.

A `VehicleRegion` with `vehicle=skate-board` supplies a locked board for each eligible rider.
`restricted=true` retires it on exit and prevents repeated supply for that rider. A
`DropVehicleRegion` forces a momentum-preserving dismount; its detection includes complete
crossings of thin regions. Region supply currently supports skateboards; other vehicle types can
be registered through `add()` with their own behavior. Region attachment still requires passenger
clearance.

### Inspect and rebuild the course

`Vehicles::snapshot()` exposes entity IDs, original object IDs, occupant IDs, motion, pose, and
collision plane. The demo publishes this as `canvas.dataset.vehicles`, with the current vehicle ID
in `canvas.dataset.body.vehicle`. Restarting a level rebuilds the boards; changing level or movement
profile releases the old seat. Graphics restoration keeps the same PHP entities and associations.
Regenerate the example and its original board artwork with `php examples/generate-vehicles.php`.

## Fly the Tornado

Choose **Level → Tornado flight course** and fall into the aircraft's seat. Space / Z or controller
A / Cross makes a conventional jump takeoff. Hold C or X / Square to retract the gear and fire the
rocket engine. While airborne, Up and Down pitch the aircraft; X or B / Circle toggles landing gear.
Fuel drains at six units per second under thrust and refills at fifteen units per second while the
thruster is idle. Emptying the tank applies moving-boxes-2's half-second refill and ignition
lockout. The upper-left HUD and `Vehicles::snapshot()` expose the current fuel.

### Flight behavior and tuning

The 10,240 × 3,072 pixel course has a takeoff runway, an open-sky leveling stretch, two gradual
terrain-sensor ridges, recovery valleys, and a final landing runway. The ring trail remains four
tiles above the local ground to demonstrate the downward sensor's useful envelope. The course has
no ceiling, and its reset region is unbounded above. Low unpowered airspeed stalls; thrusting from
rest starts a rocket takeoff; a stopped powered impact enters a half-second ignition lockout. Flight
uses the reference 60 Hz constants converted to seconds: a 5 px/frame runway cap, 8 px/frame
cruise tuning, 10 px/frame flight cap, 4 px/frame thrust impulse, 5% neutral cruise drag, and
0.035-radian pitch input. A 128-pixel downward sensor applies the reference pitch damping and
near-ground thrust lift, but no longer disables climb input when terrain leaves its range. A
continuous pitch-energy curve replaces the reference's duplicated descent-only speed branch:
shallow climbs trade little forward speed, steeper climbs trade more, and descents recover speed
symmetrically. The `airplane` camera mode pans toward the aircraft's travel direction.
The aircraft, rider seat, and camera retain one association across ground, takeoff, collision, and
landing. Swept chassis and passenger casts prevent the wide aircraft from crossing thin terrain at
high speed.

### Place or create an aircraft

Use `tornado.tj` and its nested `Tornado` settings; legacy objects use class `Tornado`. Annotated `tornado`, `tornado-flight`, `tornado-gear`,
`tornado-thrust`, and `tornado-crashed` tiles select artwork without positional tile assumptions.
The usual `seatHeight`, `seatForward`, `lockedIn`, `direction`, and `collisionLayer` properties apply.
`Tornado::body()` supplies the reference dimensions and speeds; a scripted vehicle uses the same
API as the skateboard:

```php
use SanePhonics\Gameplay\{Tornado, Vehicle};

$aircraft = new Vehicle(
	Tornado::body(320, 442)
	, seatHeight: 14
	, seatForward: 0
	, lockedIn: true
);

$aircraftEntity = $vehicles->add($aircraft, new Tornado($stateGids), new Sprite($stateGids['tornado']));
```

The snapshot adds `type`, `flying`, `thrusting`, `landingGear`, `fuel`, `pitch`, and `crashed` for
Tornado entities. This gives scripts stable verbs and nouns for input playback, assertions, and
batch test drivers. Generate the map and original aircraft art with
`php examples/generate-tornado.php`.

## Drive the EggWalker

Choose **Level → EggWalker proving ground** and drop into the walker. Its dimensions and time-based
movement values follow moving-boxes-2: a 32 × 64 standing chassis, 150 px/s walking cap,
360 px/s² acceleration, 180 px/s² friction, and a 720 px/s jump. The authored course includes a
crouch-height tunnel, a booster gap, and raised CrabMeat targets matched to that slower, heavier
movement model.

| Action | Keyboard | Standard gamepad |
| --- | --- | --- |
| Walk | Left / Right | Left stick / D-pad |
| Jump; hold airborne for booster | Space / Z | Button 0: A / Cross |
| Latch booster after takeoff | Up + Space / Z | Up + button 0 |
| Cancel booster, dive, or crouch | Down | Down |
| Chain punch | X | Button 1: B / Circle |
| Hold cannon fire | C | Button 2: X / Square |
| Force facing left / right | Q / E | Buttons 4 / 5: shoulders |

The rider remains in the cockpit seat while the body switches between its 64-pixel standing and
48-pixel crouched bounds. A first airborne crouch adds the reference downward impulse. Landing that
drop while still crouched at 8 px/reference-frame or faster starts the reference vertical camera
shake: a 7-pixel impulse that reverses and decays by five percent per frame. The render-only offset
does not disturb camera following or motion-blur measurement. The booster only counters downward
gravity velocity, and Up latches it until landing or Down cancels it. Punches stay active for 12
reference frames with a 25-frame restart interval. Cannon shots repeat every six frames, travel at
240 px/s, begin falling after six frames, and expire after 200 frames.

### Reuse weapons and layered artwork

EggWalker is composed through the ECS instead of adding weapon branches to `Vehicles`.
`VehicleComponents` registers `VehicleMelee`, `VehicleEmitter`, `CameraShake`, `Rumble`, and, when the
map supplies layered art, `SpriteLayers` on the chassis entity;
`VehicleWeapons` advances those capabilities and creates rider-owned `VehicleProjectile` entities.
`Badniks` consumes the generic melee and projectile components, so another vehicle can reuse either
capability without becoming an EggWalker subtype. `VehicleDiagnostics` contributes type-specific
snapshot fields, and `VehicleTerrainAware` supplies terrain only to behaviors that request it.
EggWalker itself owns locomotion, crouch/booster state, and presentation. Its rear and foreground
art are separate `SpriteLayers` planes anchored around the occupant, matching moving-boxes-2's
chair/gun/rear-leg → rider → hull/arm/front-leg order without vehicle-specific renderer branches.

Use `egg-walker.tj` and its nested `EggWalker` settings; legacy objects use class `EggWalker` or `egg-walker`. Annotated `egg-walker`,
`egg-walker-walking`, `egg-walker-crouching`, `egg-walker-falling`, `egg-walker-boosting`,
`egg-walker-punch`, `egg-walker-shooting`, and `egg-walker-shot` tiles select state artwork; matched
`-front` classes provide each foreground plane.
The usual seat, direction, lock, and collision-layer properties apply. `Vehicles::snapshot()` adds
`type`, `crouching`, `boosting`, `punching`, `shooting`, `shots`, and `shakeY`. Regenerate the original demo
art and map with `php examples/generate-egg-walker.php`.
