# Rings, enemies, and rewards

Rings, enemies, monitors, and shields share the player’s movement and combat components. This chapter explains their contacts, rewards, damage rules, and level ownership. Camera following has its own [movement reference](movement.md#follow-the-player-with-the-camera); arena locks remain below with the boss encounter.

For placement templates and nested settings, see [Authoring courses in Tiled](authoring.md). The [gameplay guide](gameplay.md) lists demo controls and the other reference chapters.

## Collect rings and show the player HUD

The platformer map marks animated tiles with `SanePhonics.Entity.ring`. `Gameplay\Rings`
turns those cells into `Ring` plus `Sprite` entities and clears the original map cells
before rendering. Independent object placements from `templates/ring.tj` also become pickups,
with their authored visibility, transforms, and nested settings. Each player that owns both `Body` and `RingCounter` can collect them.
The pickup check sweeps from the previous body position to the current one, so fast
movement cannot tunnel through a ring. Call `sync()` after a teleport to prevent the
teleport path from collecting anything.

~~~php
use SanePhonics\Gameplay\{RingCounter,Rings};

$counter = new RingCounter;
$player = $engine->entities->create($body, $counter, $sprite);
$rings = new Rings($engine->entities, $map); // Run before constructing MapRenderer.
$rings->sync($player, $body);
$engine->systems->add('rings', [$rings, 'update'], after: ['movement']);
~~~

The nested `Ring.radius`, `Ring.value`, and `Ring.points` fields configure each pickup.
Legacy `ring` classes and flat `ring.radius`, `ring.value`, and `ring.points` properties remain readable. `close()` removes every uncollected entity owned by that map. The live demo
resets rings and elapsed level time when a level changes while retaining score in the
player component.

The example player HUD renders **SCORE**, **TIME**, and **RINGS** at the upper left with
the annotated menu font, colored labels, and pixel shadows matching moving-boxes-2.
It has no background panel and hides in camera-only scenes. The FPS meter remains an
independent lower-left diagnostic with no black rectangle behind it.

## Add enemies and contact damage

`Gameplay\Badnik` holds the position, contact bounds, points, and optional explosion art shared by enemy types. A species component implements the empty `BadnikBehavior` marker. `Badniks` owns player contacts, mercy damage, scoring, airborne rebound, explosions, and owner-tagged ballistic projectiles. A grounded walking player takes damage and loses held rings. The player sprite flickers during mercy and cannot turn its damage knockback into an attack. A rolling or jumping player outside mercy pops the badnik and receives its point value. Walking off a ledge produces a plain fall, not a somersault attack.

`Gameplay\CrabMeats` promotes `CrabMeat` objects from Tiled. It recreates the reference schedule in time-based engine units: 65 walking frames, 10 standing frames, 15 firing-pose frames, paired shots on frame 87, and a three-frame launch delay. Each patrol leg alternates direction. Tiled properties keep their familiar frame units; `patrolSpeed`, projectile speeds, and projectile gravity use per-frame units and convert to pixels per second internally.

~~~php
use SanePhonics\Gameplay\{Badniks,CrabMeats,PlayerCombat};

$player = $engine->entities->create($body, $counter, new PlayerCombat, $sprite);
$badniks = new Badniks($engine->entities, $terrain, $rings);
$crabs = new CrabMeats($engine->entities, $terrain, $map, $badniks);
$badniks->sync($player, $body); // Also call after teleports.

$engine->systems->add('crabmeats', [$crabs, 'update'], after: ['movement']);
$engine->systems->add('badniks', [$badniks, 'update'], after: ['crabmeats']);
~~~

Annotate tiles with `crabmeat-standing`, `crabmeat-walking`, `crabmeat-shooting`, `badnik-projectile`, and `badnik-explosion` classes. The walking, shooting, and explosion tiles may use ordinary Tiled animation frames. Place `crab-meat.tj` and expand `CrabMeat`; legacy point objects with class `CrabMeat` also work. Supported fields include `direction`, `patrolBeat`, `patrolPause`, `patrolSpeed`, `patrolOffset`, `shotTelegraph`, projectile tuning, `collisionLayer`, and `points`. Call `$badniks->close()` during level cleanup.

Player-to-badnik and player-to-projectile contacts use relative swept bounds, so either participant can cross the other between fixed updates without tunneling. Projectile-to-terrain movement uses `Terrain::castBody()` over the complete displacement. A projectile whose owning badnik has popped retires without damaging the player.

## Lose and recover rings after damage

When `Badniks` receives the level's `Rings` manager, damage scatters up to sixteen held rings in two radial groups before clearing the counter. The burst keeps moving-boxes-2's reduced recovery set: every third ring is a short-lived visual, while the others become collectible after 48 reference frames. `ScatteredRing` stores velocity, gravity, pickup delay, lifetime, restitution, and collision layer. `Rings` uses full body casts for terrain bounces and relative swept pickup checks for moving rings. Recovered rings return to `RingCounter`; unrecovered rings expire between about 3.7 and 6 seconds.

## Run a multi-hit boss encounter

`Gameplay\Boss` holds reusable position, bounds, health, score, collision layer, velocity, and phase
state without inheriting ordinary one-hit badnik behavior. `MiniBosses` promotes `MiniBoss` or
`mini-boss` Tiled objects and runs the moving-boxes-2 encounter: the idle boss wakes when its arena
activates, stalks the player horizontally, and drops a spike bomb every 60 reference frames. A
landed bomb splits into two outward fragments. Main bombs use complete body casts against terrain,
and every boss, bomb, and fragment contact uses relative swept bounds so high speeds cannot tunnel.

Rolling and jump somersaults damage the top or sides. An underside attack produces the reference dud
rebound without removing health. Per-attacker hit cooldowns preserve the eight-hit fight while the
damaged pose recovers. Zero health releases `BossRegions` immediately, but leaves a dead shell for a
final attack. The finisher starts a 90-frame stream of explosion entities before the boss escapes
upward. Player damage reuses rings, shields, mercy, combo failure, and knockback behavior. The final
hit awards the configured points and increments `PlayerCombat::$defeats` once.

~~~php
use SanePhonics\Gameplay\{MiniBosses,PlayerCombat};

$player = $engine->entities->create($body, $counter, new PlayerCombat, $sprite);
$bosses = new MiniBosses($engine->entities, $terrain, $map, $rings);
$bosses->sync($player, $body); // Also call after teleports.

$engine->systems->add('mini-bosses', [$bosses, 'update'], after: ['movement']);

// Wake the object referenced by the active BossRegion.
$bosses->wakeSource($region->bossSourceId);
~~~

### Share combat timing and author the boss

The manager advances `PlayerCombat` mercy clocks by default. Pass `advanceCombat: false` when a
`Badniks` manager already advances the same players, as the combined demo does.

Annotate tiles with `mini-boss-idle`, `mini-boss-damaged`, `mini-boss-dead`, `spike-bomb`,
`spike-fragment`, and `boss-explosion` classes. Place `mini-boss.tj` and expand `MiniBoss`; legacy point or tile objects with class `MiniBoss`
also work. Its settings can override `hitPoints`, `points`, `collisionLayer`, stalking speed, bomb timing and
gravity, hit and recovery frames, explosion timing, and escape behavior. These editor properties use
reference frames and pixels per frame; the manager converts them to seconds and pixels per second.
`snapshot()`, `entityForSourceId()`, `aliveSource()`, and `wakeSource()` provide stable scripting
verbs. Call `close()` with the rest of the level cleanup.

## Lock and release a boss arena

`Gameplay\BossRegions` loads `boss-region.tj` placements with nested `BossRegion` settings,
as well as moving-boxes-2-compatible `BossRegion` or `boss-region` rectangle objects. The historical editor convention of naming an otherwise untyped object
`boss-region` is accepted too. Set `BossRegion.boss` to the boss in the same map using the object picker (legacy objects use a flat `boss` property). The
application supplies a callback that maps that source object ID to live boss state, so the lock
works with any ECS boss component or behavior instead of depending on CrabMeat.

Grounded entry engages the arena. The complete movement segment is tested against the rectangle,
so a body crossing both sides in one fixed step is placed at the entrance instead of tunneling
through. Once engaged, the body center remains inside the horizontal arena bounds while the camera
pans toward a destination within those bounds. Aerial pass-through does not start a fight. Target
defeat releases the player immediately, completes the region, and lets the camera pan back to its
ordinary follow target. `cameraAnchorY`
defaults to `0.72`, matching the reference boss framing, and accepts a value from zero through one.

~~~php
use SanePhonics\Gameplay\{BossRegions,MiniBosses,PlatformCamera};

$bosses = new MiniBosses($engine->entities, $terrain, $map, $rings);

$locks = BossRegions::fromMaps(
	fn(int $sourceId): bool => $bosses->aliveSource($sourceId)
	, $map
);

$locks->sync($body); // Call after spawning or teleporting; $release defaults to true and clears an old lock.
$region = $locks->update($body); // Call after movement.
$cameraBehavior = $cameraBehavior ?? new PlatformCamera;

if($region)
{
	$bosses->wakeSource($region->bossSourceId);
	$cameraBehavior->setMode('boss');
	$cameraBehavior->bossAnchorY = $region->cameraAnchorY;
	$cameraBehavior->setWorldBounds($region->x, $region->x + $region->width);
}
else
{
	$cameraBehavior->setMode('auto');
	$cameraBehavior->clearWorldBounds();
}

$cameraBehavior->update($camera, $body, $dt);
~~~

`BossRegions::constrainCamera()` remains available for applications that require an immediate hard
clamp. The demo uses `PlatformCamera` so arena entry and release are visible pans.

The **CrabMeat badniks** demo uses an eight-hit `MiniBoss` as object 3. Enter from either side,
survive its spike bombs, attack the top or sides, then strike the dead shell to start its escape.
The arena releases on the eighth hit. `canvas.dataset.boss` exposes health, phase, position, attacks,
and effects; `canvas.dataset.bossLock` exposes the active bounds for scripts and browser tests.

## Break monitors and grant rewards

`Gameplay\Monitor` describes a solid, bottom-anchored reward box independently of its reward. `Monitors` adds each box to the same removable `Terrain` index used by the platformer. Walking and plain falling leave it solid. A roll or jump somersault outside mercy removes the contacted shape during the continuous movement sweep, so a fast player breaks the monitor without stopping or tunneling through it. Airborne somersaulting players rebound after the hit.

### Choose or implement a reward

`MonitorEffect` is the reward API. `RingMonitorEffect` supplies the reference 10-ring reward and accepts any positive amount, including the 30-ring variant. `ShieldMonitorEffect` grants normal, electric, fire, water, or star shields. Implement the interface for lives, information triggers, or game-specific inventory without changing collision and rendering behavior.

~~~php
use SanePhonics\Gameplay\{Monitor,Monitors,RingMonitorEffect,Shield,ShieldMonitorEffect,Shields};
use Phonics\Physics\Hit;
use SanePhonics\Physics\{Body,Platformer};
use Phonics\Render\Sprite;

$shields = new Shields($engine->entities, $map);
$monitors = new Monitors($engine->entities, $terrain, $map, shields: $shields);

$movement = new Platformer(
	$terrain
	, onImpact: fn(Body $body, Hit $hit): bool =>
		$monitors->impact($body, $hit, $engine->time * 1000)
);

$engine->systems->add('monitors', [$monitors, 'update'], after: ['movement']);
$engine->systems->add('shields', [$shields, 'update'], after: ['monitors']);

// Run before Platformer::step() so shield actions can explicitly consume Jump.
$jumpConsumed = $shields->control($player, $engine->controls, $dt);
$movement->step($body, $engine->controls, $dt, $jumpConsumed);

// Direct creation accepts any MonitorEffect and atlas-backed casing/screen sprites.
$monitor = $monitors->create(
	new Monitor(320, 448, brokenGid: $brokenGid, explosionGid: $explosionGid)
	, new RingMonitorEffect(10)
	, new Sprite($shellGid, 320, 448, .5, 1)
	, new Sprite($ringScreenGid, 320, 430, .5, .5)
);

$shieldMonitor = $monitors->create(
	new Monitor(480, 448, brokenGid: $brokenGid, explosionGid: $explosionGid)
	, new ShieldMonitorEffect(Shield::ELECTRIC, shields: $shields)
	, new Sprite($shellGid, 480, 448, .5, 1)
	, new Sprite($electricScreenGid, 480, 430, .5, .5)
);
~~~

The seven monitor templates nest shared settings under `Monitor` and reward tuning under
`RingMonitorEffect` or `ShieldMonitorEffect`. Tile-object placements use their bottom-left anchor;
legacy point objects retain their bottom-center anchor. Legacy point objects may use `Monitor`, `RingMonitor`, `TripleRingMonitor`, `NormalShieldMonitor`, `ElectricShieldMonitor`, `FireShieldMonitor`, `WaterShieldMonitor`, or `StarShieldMonitor`. `BubbleShieldMonitor` is an alias for water. The historical `SheildNormalMonitor` and corresponding elemental class spellings are accepted when importing moving-boxes-2 data. A base `Monitor` selects the same effects with its `reward` property. `amount`, `points`, `starDuration`, `width`, `height`, and `collisionLayer` are optional.

Annotate tiles with `monitor-shell`, `monitor-broken`, `monitor-explosion`, and the appropriate `monitor-screen-*` class. Shield screens use `monitor-screen-shield-normal`, `-electric`, `-fire`, `-water`, and `-star`. Optional `shield-aura-*` tiles give `Shields` animated player art. Tiled animation controls casing static, screen flicker, aura motion, and the explosion.

Runner profiles use Jump directly for electric and water shield actions during a jump somersault. Flyer and glider profiles keep an unmodified Jump for their native flight or glide. Hold Up or Down with Jump to select electric jump, or hold Down with Jump to select water bounce. Plain falling cannot start any of these jump abilities. Fire shoulder thrust is additive and does not cancel active flight or glide. Pass the boolean returned by `Shields::control()` to `Platformer::step()` so a shield-selected jump is not also handled as a profile ability.

The intact entity combines its casing `Sprite` with a screen `SpriteGroup`. On the first pop, the casing switches to its broken tile and stays in the level, while the screen rises and fades on a temporary entity and the reward applies exactly once. `pop()` is also available for scripted damage. Destroying an intact monitor externally releases its collider. Call `close()` during level cleanup to retire casings and effects.

## Manage shields and their abilities

`Gameplay\Shield` is a player component with a scriptable inventory and one active selection. `grant()`, `select()`, `has()`, and `inventory()` are independent of input and rendering. Acquiring normal, electric, fire, or water retains that type until it absorbs a non-immune hit. Acquiring star temporarily overrides the current selection for 26 seconds by default, then restores it. Reacquiring star refreshes the timer. Granting another type during star adds it to inventory without interrupting the override.

### Absorb or ignore damage

`PlayerCombat::damage()` accepts an optional shield and damage category. Any active shield prevents ring loss for one otherwise damaging hit, then receives the same mercy knockback as the unshielded player. Electric, fire, and water shields ignore their matching category and generic `projectile` damage without consuming the shield or starting mercy. Star ignores every category. `Badniks` reads the player's component automatically: shielded body contact defeats the badnik, immune projectiles retire harmlessly, and an absorbed hit does not scatter rings.

### Update abilities and manage aura sprites

`Shields` owns only map-specific aura entities, so shield inventory can persist when a level closes. Its fixed-step `update()` advances star time, follows each `Body`, retires consumed auras, and completes water rebounds. Call `control()` before platformer movement to enable the reference abilities through `Controls`: electric gets three presses during a jump, fire spends a quarter-second airborne thrust budget with buttons 4 and 5, and water converts a jump into a downward attack and upward rebound. Landing resets electric and fire budgets. Menus and scripts can call `Shield::select()` to switch among acquired types.

Aura rendering is optional. With no annotated map, construct `Shields` without its second argument to update headless `Shield` components and abilities. A `ShieldMonitorEffect` without a manager still grants inventory, which scripts can advance directly through `Shield::update()`. With a map, `Shields` creates a separate `ShieldAura` plus `Sprite`, allowing the ordinary sprite batch to draw it over the player. `Shields::close()` removes those map-specific sprites without erasing inventory. Call `Monitors::close()` as well to retire monitor casings and pop effects.
