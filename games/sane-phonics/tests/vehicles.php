<?php
use PhpWasmEngine\Ecs\Registry;
use PhpWasmEngine\Effects\CameraShake;
use PhpWasmEngine\Effects\Rumble;
use PhpWasmEngine\Gameplay\
{
	Vehicle, Vehicles, Rider, Skateboard, Tornado, EggWalker, VehicleMelee, VehicleEmitter
	, VehicleProjectile, Combo, ComboReward, RingCounter, PlayerCombat, Badnik, BadnikBehavior, Badniks
};
use PhpWasmEngine\Input\Controls;
use PhpWasmEngine\Physics\
{
	Body, Terrain, Platformer
};
use PhpWasmEngine\Render\
{
	Sprite, SpriteGroup, SpriteLayers
};

/**
 * Build a board and opt-in rider above a flat floor.
 *
 * @param string $profile Character movement profile retained while riding.
 * @return array{Registry,Terrain,Vehicles,Body,Rider,int,Vehicle,int} Test objects and entity IDs.
 */
function vehicleFixture(string $profile = 'runner'): array
{
	$registry = new Registry;
	$terrain = new Terrain;
	$terrain->add([[-2000, 100], [4000, 100], [4000, 500], [-2000, 500]]);
	$system = new Vehicles($registry, $terrain, new Platformer($terrain));
	$vehicle = new Vehicle(Skateboard::body(0, 94), seatHeight: 12);
	$vehicle->body->grounded = true;
	$id = $system->add($vehicle, new Skateboard, new Sprite(1));
	$body = new Body(0, 50, $profile);
	$rider = new Rider;
	$player = $registry->create($body, $rider, new Combo, new RingCounter, new PlayerCombat);

	return [$registry, $terrain, $system, $body, $rider, $player, $vehicle, $id];
}

test('vehicles mount all profiles without replacing character components and reject occupied seats', function() {
	foreach(['runner', 'flyer', 'glider'] as $profile)
	{
		[$registry, , $system, $body, $rider, $player, $vehicle, $id] = vehicleFixture($profile);
		$body->vx = 300;
		check($system->mount($player, $id));
		check($rider->vehicle === $id && $vehicle->occupant === $player && $body->ability === 'riding');
		check($body->profile === $profile && $registry->get($player, Body::class) === $body);
		near($vehicle->body->groundSpeed, 300);
		near($body->supportY(), 88);
		$other = $registry->create(new Body, new Rider);
		check(!$system->mount($other, $id));
	}
});

test('descending seat sweeps board at speed but reject ascending, other-plane and obstructed approaches', function() {
	foreach([6000, -6000] as $vy)
	{
		[$registry, , $system, $body, $rider] = vehicleFixture();
		$body->y = $vy > 0 ? -20 : 160;
		$body->vy = $vy;
		$system->sync(2, $body);
		$body->y += $vy / 60;
		$system->update($registry, 1 / 60);
		check(($rider->vehicle !== null) === ($vy > 0));
	}

	[$registry, $terrain, $system, $body, , $player, , $id] = vehicleFixture();
	$body->collisionLayer = 2;
	check(!$system->mount($player, $id));
	$body->collisionLayer = 1;
	$terrain->add([[-30, 30], [30, 30], [30, 80], [-30, 80]]);
	check(!$system->mount($player, $id));
});

test('vehicle input jumps the board without triggering rider abilities and up-jump exits once', function() {
	foreach(['runner', 'flyer', 'glider'] as $profile)
	{
		[$registry, , $system, $body, $rider, $player, $vehicle, $id] = vehicleFixture($profile);
		check($system->mount($player, $id));
		$input = new Controls;
		$input->set([0 => true, 'right' => true, 'down' => true]);
		check($system->control($player, $input, 1 / 60));
		$system->update($registry, 1 / 60);
		check($vehicle->body->jumping && $vehicle->body->vy < 0);
		check($body->ability === 'riding' && !$body->jumping && $body->charge === 0.0);
		$input->set([]);
		$system->control($player, $input, 1 / 60);
		$system->update($registry, 1 / 60);
		$input->set(['up' => true, 0 => true]);
		check($system->control($player, $input, 1 / 60));
		check($rider->vehicle === null && $vehicle->occupant === null && $body->jumping);
		near($body->vx, 120);
		near($body->vy, -$body->jumpSpeed);
		check(!$system->mount($player, $id));
	}
});

test('locked seats block voluntary exits and quick-drop preserves downward vehicle velocity', function() {
	[$registry, , $system, $body, $rider, $player, $vehicle, $id] = vehicleFixture();
	$vehicle->lockedIn = true;
	check($system->mount($player, $id));
	check(!$system->dismount($player, 'jump'));
	$vehicle->lockedIn = false;
	$vehicle->quickDrop = true;
	$vehicle->body->vy = 180;
	check($system->dismount($player, 'jump'));
	near($body->vy, -$body->jumpSpeed + 180);
	check($rider->vehicle === null);
});

test('vehicle passenger casts stop at a low ceiling without tunneling or losing the seat', function() {
	[$registry, $terrain, $system, $body, $rider, $player, $vehicle, $id] = vehicleFixture();
	check($system->mount($player, $id));
	$terrain->add([[40, -100], [41, -100], [41, 70], [40, 70]]);
	$vehicle->body->groundSpeed = 2400;
	$vehicle->body->friction = 0;
	$system->update($registry, .1);
	check($body->x + $body->radius <= 40.001);
	check($rider->vehicle === $id && $terrain->canPlace($body));
	near($body->x, $vehicle->body->x);
});

test('vehicle damage detaches without overwriting knockback and removals clear both association ends', function() {
	[$registry, , $system, $body, $rider, $player, $vehicle, $id] = vehicleFixture();
	check($system->mount($player, $id));
	$registry->get($player, PlayerCombat::class)->damage($body, null, -1);
	$system->update($registry, 1 / 60);
	check($rider->vehicle === null && $vehicle->occupant === null);
	near($body->vx, -240);
	near($body->vy, -360);
	$rider->cooldowns = [];
	$body->ability = '';
	check($system->mount($player, $id));
	$registry->destroy($id);
	check($rider->vehicle === null && $body->ability === '');
	$system->close();
	$system->close();
	check($registry->alive($player));
});

test('skateboard tricks use numbered edges, preserve a manual chain and bank after landing', function() {
	[$registry, , $system, $body, , $player, $vehicle, $id] = vehicleFixture();
	check($system->mount($player, $id));
	$board = $registry->get($id, Skateboard::class);
	$combo = $registry->get($player, Combo::class);
	$vehicle->body->grounded = false;
	$vehicle->body->y = -400;
	$vehicle->body->gravity = 0;
	$input = new Controls;
	$input->set([2 => true]);
	$system->control($player, $input, 1 / 60);
	$system->update($registry, 1 / 60);
	check($board->trick === 'handstand' && count($combo->chain) === 1);

	for($i = 0; $i < 60; $i++)
	{
		$input->set([2 => true]);
		$system->control($player, $input, 1 / 60);
		$system->update($registry, 1 / 60);
	}

	check(count($combo->chain) === 1 && $board->trick === 'handstand');
	$input->set([]);
	$system->control($player, $input, 1 / 60);
	$system->update($registry, 1 / 60);
	$vehicle->body->y = 94;
	$vehicle->body->grounded = true;
	$input->set([3 => true]);
	$system->control($player, $input, 1 / 60);
	$system->update($registry, 1 / 60);
	check($board->trick === 'manual' && count($combo->chain) === 2);

	for($i = 0; $i < 30; $i++)
	{
		$input->set([3 => true]);
		$system->control($player, $input, 1 / 60);
		$system->update($registry, 1 / 60);
	}

	check(count($combo->chain) === 2);

	for($i = 0; $i < 20; $i++)
	{
		$input->set([]);
		$system->control($player, $input, 1 / 60);
		$system->update($registry, 1 / 60);
	}

	check(!$combo->chain && $registry->get($player, RingCounter::class)->score === 400);
});

test('skateboard rail tricks accrue one grind reward only after a chain and never a second generic reward', function() {
	[$registry, $terrain, $system, $body, , $player, $vehicle, $id] = vehicleFixture();
	$terrain->addRail([[-1000, 0], [3000, 0]]);
	$vehicle->body->y = -6;
	check($system->mount($player, $id));
	$combo = $registry->get($player, Combo::class);
	$combo->add(new ComboReward('Handstand', 100));
	$vehicle->body->grinding = true;
	$vehicle->body->groundSpeed = 600;

	for($i = 0; $i < 30; $i++) $system->update($registry, 1 / 60);
	check(count($combo->chain) === 2);
	check($combo->chain[1]->points >= 390);
});

test('vehicle shutdown leaves the player alive and resets stale steering before remount', function() {
	[$registry, , $system, $body, $rider, $player, $vehicle, $id] = vehicleFixture();
	check($system->mount($player, $id));
	$input = new Controls;
	$input->set(['right' => true]);
	$system->control($player, $input, 1 / 60);
	$system->close();
	check($rider->vehicle === null && $vehicle->occupant === null);
	check(!$registry->alive($id) && $registry->alive($player));
	check(!$system->mount($player, $id));
});

test('boarding does not synthesize a jump edge from an already held button', function() {
	[$registry, , $system, , , $player, $vehicle, $id] = vehicleFixture();
	$input = new Controls;
	$input->set([0 => true]);
	$input->set([0 => true]);
	check($system->mount($player, $id));
	$system->control($player, $input, 1 / 60);
	$system->update($registry, 1 / 60);
	check($vehicle->body->grounded && !$vehicle->body->jumping);
	$input->set([]);
	$system->control($player, $input, 1 / 60);
	$system->update($registry, 1 / 60);
	$input->set([0 => true]);
	$system->control($player, $input, 1 / 60);
	$system->update($registry, 1 / 60);
	check(!$vehicle->body->grounded && $vehicle->body->jumping);
});

test('seat sweeps still board when ordinary floor collision clamps the falling player first', function() {
	[$registry, $terrain, $system, $body, $rider, $player] = vehicleFixture();
	$body->y = 20;
	$body->vy = 2000;
	$system->sync($player, $body);
	(new Platformer($terrain))->step($body, new Controls, .1);
	check($body->grounded);
	$system->update($registry, .1);
	check($rider->vehicle !== null && $body->ability === 'riding');
});

test('a skateboard carries its rider over quarter bends and through both loop collision planes', function() {
	$map = (new PhpWasmEngine\Tiled\Loader)->map(dirname(__DIR__, 3) . '/examples/assets/vehicles.tmj');

	foreach([1, -1] as $direction)
	{
		$registry = new Registry;
		$terrain = PhpWasmEngine\Physics\MapTerrain::build($map);
		$system = new Vehicles($registry, $terrain, new Platformer($terrain));
		$vehicle = new Vehicle(Skateboard::body($direction > 0 ? 80 : 1880, 442));
		$vehicle->body->grounded = true;
		$vehicle->body->collisionLayer = $direction > 0 ? 1 : 2;
		$id = $system->add($vehicle, new Skateboard);
		$body = new Body($vehicle->body->x, 400);
		$body->collisionLayer = $vehicle->body->collisionLayer;
		$body->vx = $direction * 900;
		$player = $registry->create($body, new Rider);
		check($system->mount($player, $id));
		$quarter = $ceiling = false;

		for($frame = 0; $frame < 480; $frame++)
		{
			$system->update($registry, 1 / 60);
			check($body->grounded && $body->ability === 'riding', 'passenger must remain seated around the course');
			near($body->angle, $vehicle->body->angle);
			$quarter = $quarter || ($body->x < 1280 && abs(abs($body->angle) - M_PI_2) < .2);
			$ceiling = $ceiling || abs(abs($body->angle) - M_PI) < .2;

			if($direction > 0 ? $body->x > 1920 : $body->x < 160) break;
		}

		check($frame < 480 && $quarter && $ceiling, 'course traversal failed at ' . json_encode($system->snapshot()));
		check($body->collisionLayer === ($direction > 0 ? 2 : 1));
	}
});

test('chassis ring sweeps and monitor effects credit the rider once', function() {
	[$registry, $terrain, $system, $body, , $player, $vehicle, $id] = vehicleFixture();
	check($system->mount($player, $id));
	$map = new PhpWasmEngine\Tiled\TileMap('empty', ['tilewidth' => 32, 'tileheight' => 32]);
	$rings = new PhpWasmEngine\Gameplay\Rings($registry, $map);
	$ring = $registry->create(new PhpWasmEngine\Gameplay\Ring(50, 98, 1));
	$vehicle->body->groundSpeed = 1200;
	$system->update($registry, .1);
	$rings->update($registry, .1);
	check(!$registry->alive($ring));
	$counter = $registry->get($player, RingCounter::class);
	check($counter->rings === 1 && !$registry->has($id, RingCounter::class));
	$monitors = new PhpWasmEngine\Gameplay\Monitors($registry, $terrain);
	$monitor = new PhpWasmEngine\Gameplay\Monitor(200, 100);
	$monitors->create($monitor, new PhpWasmEngine\Gameplay\RingMonitorEffect(10), new Sprite(1));
	$vehicle->body->rolling = true;
	$hit = $terrain->castBody($vehicle->body, 200, 0);
	check($hit !== null && $monitors->impact($vehicle->body, $hit));
	check($counter->rings === 11 && $monitor->broken);
	$rings->update($registry, .1);
	check($counter->rings === 11);
});

test('vehicle regions supply locked boards, retire on restricted exit, and sweep thin drop zones', function() {
	[$registry, , $system, $body, $rider, $player, , $original] = vehicleFixture();
	$system->remove($original);
	$body->y = 82;
	$body->grounded = true;
	$body->vx = $body->groundSpeed = 300;
	$region = new PhpWasmEngine\Gameplay\VehicleRegion(-20, 0, 100, 120, restricted: true);
	$system->region($region);
	$system->update($registry, 1 / 60);
	$vehicle = $system->ridden($player);
	check($vehicle !== null && $vehicle->lockedIn);
	near($vehicle->body->groundSpeed, 300);
	check(!$system->dismount($player));
	$vehicle->body->x = 100;
	$system->update($registry, 1 / 60);
	check($rider->vehicle === null && !$registry->alive($vehicle->entity));
	check($body->grounded && $body->groundSpeed > 0);
	near($body->supportY(), 100);
	check($region->spawned[$player] === false);
	$body->x = 0;
	$system->update($registry, 1 / 60);
	check($rider->vehicle === null && !$system->vehicles);
	$vehicle = new Vehicle(Skateboard::body(0, 94));
	$vehicle->body->grounded = true;
	$id = $system->add($vehicle, new Skateboard);
	check($system->mount($player, $id));
	$system->sync($player, $body);
	$system->region(new PhpWasmEngine\Gameplay\VehicleRegion(50, 0, 1, 120, drop: true));
	$vehicle->body->groundSpeed = 1200;
	$system->update($registry, .1);
	check($rider->vehicle === null && $body->vx > 1000);
});

test('vehicle loader preserves offsets and all GID flips without leaving ghost collision', function() {
	foreach(range(0, 7) as $flags)
	{
		$map = (new PhpWasmEngine\Tiled\Loader)->map(dirname(__DIR__, 3) . '/examples/assets/vehicles.tmj');
		$map->x = 100;
		$map->y = 30;
		$layer = $map->layer('Skateboards');
		$layer->offsetX = 7;
		$layer->offsetY = 11;
		$layer->properties['solid'] = true;
		$layer->opacity = .4;
		$object = $layer->objects[1];
		$object['gid'] += $flags * 536870912.0;
		$layer->objects = [$object];
		$map->layers = [$layer];
		$registry = new Registry;
		$terrain = PhpWasmEngine\Physics\MapTerrain::build($map);
		$system = new Vehicles($registry, $terrain, new Platformer($terrain), $map);
		check(count($system->vehicles) === 1);
		$vehicle = array_values($system->vehicles)[0];
		near($vehicle->body->x, 187);
		near($vehicle->body->y, 483);
		$art = $registry->get($vehicle->entity, Sprite::class);
		near($art->opacity, .4);
		check($art->outline, 'vehicles follow the default actor outline policy');
		check($map->tile($art->gid)['class'] === 'skateboard');
		check(!$terrain->solidAt(187, 480), 'vehicle object cannot remain as a static rectangle');
		$system->close();
		check($registry->query(Vehicle::class) === []);
	}
});

test('post-platform vehicle carry follows the chassis without advancing its simulation twice', function() {
	[$registry, , $system, $body, $rider, $player, $vehicle, $id] = vehicleFixture();
	check($system->mount($player, $id));
	$vehicle->body->x += 20;
	$vehicle->body->y -= 10;
	$system->carry($registry, 1 / 60);
	near($body->x, 20);
	near($body->supportY(), 78);
	check($rider->vehicle === $id);
	$registry->remove($player, Rider::class);
	check($vehicle->occupant === null && $body->ability === '');
});

test('vehicle attacks and badnik damage retain the rider as the score and inventory owner', function() {
	foreach([false, true] as $attack)
	{
		[$registry, $terrain, $system, $body, $rider, $player, $vehicle, $id] = vehicleFixture();
		check($system->mount($player, $id));
		$badniks = new PhpWasmEngine\Gameplay\Badniks($registry, $terrain);

		$enemy = $badniks->create(
			new PhpWasmEngine\Gameplay\Badnik(0, 100, width: 10, height: 4)
			, new class implements PhpWasmEngine\Gameplay\BadnikBehavior {}
			, new Sprite(1)
		);

		$vehicle->body->rolling = $attack;
		$registry->get($player, RingCounter::class)->rings = 5;
		$badniks->update($registry, 1 / 60);
		$system->carry($registry, 1 / 60);
		$combat = $registry->get($player, PlayerCombat::class);
		check($combat->defeats === ($attack ? 1 : 0));
		check($combat->hits === ($attack ? 0 : 1));
		check(($rider->vehicle === null) === !$attack);
		check($registry->get($player, RingCounter::class)->rings === ($attack ? 5 : 0));
		check($registry->has($enemy, PhpWasmEngine\Gameplay\Badnik::class) === !$attack);
		check(!$registry->has($id, PlayerCombat::class));
	}
});

test('vehicle mount callbacks receive the rider association and failed callbacks release it', function() {
	foreach([false, true] as $fail)
	{
		[$registry, , $system, $body, $rider, $player, $vehicle, $id] = vehicleFixture();
		$registry->remove($id, Skateboard::class);

		$behavior = new class($fail) implements PhpWasmEngine\Gameplay\VehicleBehavior {
			public ?int $seen = null;

			/**
			 * Select whether the test callback fails.
			 *
			 * @param bool $fail Whether to throw during mounting.
			 * @return void Retains the selected failure policy.
			 */
			public function __construct(private bool $fail) {}

			/**
			 * Observe the new rider before their incoming velocity is replaced.
			 *
			 * @param Vehicle $vehicle Seat being assigned.
			 * @param Body $rider Incoming character body.
			 * @return void Records occupant identity and optionally throws.
			 */
			public function mounted(Vehicle $vehicle, Body $rider): void
			{
				$this->seen = $vehicle->occupant;

				if($this->fail) throw new RuntimeException('Mount hook failed');
			}

			/**
			 * Leave the default drive controls intact.
			 *
			 * @param Vehicle $vehicle Driven vehicle.
			 * @param Controls $input Current logical controls.
			 * @param Combo|null $combo Optional rider combo.
			 * @param float $dt Positive simulation seconds.
			 * @return void Makes no changes for this callback fixture.
			 */
			public function control(Vehicle $vehicle, Controls $input, ?Combo $combo, float $dt): void {}

			/**
			 * Leave the simulated vehicle unchanged.
			 *
			 * @param Vehicle $vehicle Simulated vehicle.
			 * @param Combo|null $combo Optional rider combo.
			 * @param float $dt Positive simulation seconds.
			 * @return void Makes no changes for this callback fixture.
			 */
			public function updated(Vehicle $vehicle, ?Combo $combo, float $dt): void {}
		};

		$registry->add($id, $behavior);

		if($fail) rejects(fn() => $system->mount($player, $id));
		else check($system->mount($player, $id));

		check($behavior->seen === $player, 'a mount callback needs the rider identity');
		check($vehicle->occupant === ($fail ? null : $player));
		check($rider->vehicle === ($fail ? null : $id));
		check($body->ability === ($fail ? '' : 'riding'));
		$system->close();
	}
});

test('Tornado rocket takeoff, pitch, landing gear, stall and fuel use reference controls', function() {
	$vehicle = new Vehicle(Tornado::body(0, 82));
	$vehicle->occupant = 1;
	$vehicle->body->grounded = true;
	check($vehicle->body->radius === 48.0 && $vehicle->body->halfHeight === 24.0);
	check($vehicle->body->acceleration === 1800.0 && $vehicle->body->braking === 855.0);
	check($vehicle->body->friction === 405.0 && $vehicle->body->gravity === 2340.0);
	check($vehicle->body->jumpSpeed === 480.0 && $vehicle->body->maxSpeed === 300.0);

	$tornado = new Tornado([
		'tornado' => 10
		, 'tornado-flight' => 11
		, 'tornado-gear' => 12
		, 'tornado-thrust' => 13
		, 'tornado-crashed' => 14
	]);

	$tornado->mounted($vehicle, new Body);
	$input = new Controls;
	$input->set([2 => true]);
	$tornado->control($vehicle, $input, null, 1 / 60);
	check(!$vehicle->body->grounded && $tornado->flying && $tornado->thrusting);
	check(!$tornado->landingGear);
	near($tornado->fuel, 99.9);
	$takeoffSpeed = 240 * 1.025 ** sin(-.208);
	near($vehicle->body->vx, $takeoffSpeed);
	near($tornado->pitch, -.208);
	near($vehicle->body->vy, sin(-.208) * $takeoffSpeed * 2);
	check($vehicle->cameraMode === 'airplane' && $vehicle->spriteGid === 13);

	$input->set([]);
	$tornado->control($vehicle, $input, null, 1 / 60);
	near($tornado->fuel, 100);
	near($tornado->pitch, -.1664);
	check($vehicle->spriteGid === 11);

	$input->set([1 => true, 'up' => true]);
	$tornado->control($vehicle, $input, null, 1 / 60);
	check($tornado->landingGear);
	near($tornado->pitch, -.1964);
	check($vehicle->spriteGid === 12);

	$vehicle->body->vx = 120;
	$input->set([]);
	$tornado->control($vehicle, $input, null, 1 / 60);
	check($vehicle->body->gravity === 2340.0, 'an unpowered slow aircraft should stall');
	$vehicle->body->grounded = true;
	$tornado->updated($vehicle, null, 1 / 60);
	check(!$tornado->flying && $tornado->landingGear && $vehicle->cameraMode === 'auto');
	check($vehicle->spriteGid === 10 && $vehicle->visualAngle === 0.0);
});

test('Tornado depletion and powered crashes apply the reference half-second ignition lockout', function() {
	$vehicle = new Vehicle(Tornado::body());
	$vehicle->occupant = 1;
	$vehicle->body->vx = 600;
	$tornado = new Tornado;
	$tornado->mounted($vehicle, new Body);
	$tornado->fuel = .05;
	$tornado->thrusting = true;
	$input = new Controls;
	$input->set([2 => true]);
	$tornado->control($vehicle, $input, null, 1 / 60);
	near($tornado->fuel, 0);
	$tornado->control($vehicle, $input, null, 1 / 60);
	check(!$tornado->thrusting);

	$input->set([]);

	for($frame = 0; $frame < 20; $frame++) $tornado->control($vehicle, $input, null, 1 / 60);

	near($tornado->fuel, 0);

	for($frame = 0; $frame < 12; $frame++) $tornado->control($vehicle, $input, null, 1 / 60);

	check($tornado->fuel > 0);
	$tornado->fuel = 100;
	$tornado->thrusting = $tornado->flying = true;
	$vehicle->body->vx = 0;
	$vehicle->body->jumping = false;
	$tornado->updated($vehicle, null, 1 / 60);
	check($tornado->crashed && !$tornado->thrusting);
	$input->set([2 => true]);
	$tornado->control($vehicle, $input, null, 1 / 60);
	check(!$tornado->thrusting, 'holding thrust cannot bypass crash recovery');
});

test('generated Tornado artwork faces right before runtime mirroring', function() {
	$png = file_get_contents(dirname(__DIR__, 3) . '/examples/assets/tornado-thrust.png');
	check($png !== false && substr($png, 0, 8) === "\x89PNG\r\n\x1a\n");
	$idat = '';

	for($offset = 8; $offset < strlen($png);)
	{
		$length = unpack('N', substr($png, $offset, 4))[1];
		$type = substr($png, $offset + 4, 4);

		if($type === 'IDAT') $idat .= substr($png, $offset + 8, $length);

		$offset += 12 + $length;
	}

	$rgba = gzuncompress($idat);
	check($rgba !== false);

	$pixel = static function(int $x, int $y) use ($rgba): array {
		$offset = $y * (1 + 96 * 4) + 1 + $x * 4;

		return array_values(unpack('C4', substr($rgba, $offset, 4)));
	};

	check($pixel(87, 10) === [215, 229, 236, 210], 'the propeller belongs on the right-facing nose');
	check($pixel(47, 16) === [89, 191, 226, 255], 'the cockpit belongs at the aircraft center');
	check($pixel(0, 27) === [255, 103, 48, 230], 'rocket exhaust belongs behind the right-facing tail');
	check($pixel(95, 27)[3] === 0, 'the right-facing nose must not contain exhaust');
});

test('Tornado mirrors visual pitch for its artwork and rider without changing its collision angle', function() {
	$body = Tornado::body(100, 100);
	$vehicle = new Vehicle($body, seatHeight: 20, seatForward: -12);
	$rider = new Body;
	[$flatX, $flatY, $flatAngle] = $vehicle->seat($rider);
	$vehicle->visualAngle = -.5;
	[$pitchedX, $pitchedY, $pitchedAngle] = $vehicle->seat($rider);
	near($body->angle, 0);
	near($flatAngle, 0);
	near($pitchedAngle, -.5);
	check(abs($pitchedX - $flatX) > 1 && abs($pitchedY - $flatY) > 1);

	$registry = new Registry;
	$terrain = new Terrain;
	$system = new Vehicles($registry, $terrain, new Platformer($terrain));
	$body->facing = $body->direction = -1;
	[$leftX, $leftY, $leftAngle] = $vehicle->seat($rider);
	$sprite = new Sprite(1);
	$sprite->rotation = .125;
	$system->add($vehicle, sprite: $sprite);

	near($leftAngle, .5);
	check(abs($leftX - $flatX) > 1 && abs($leftY - $flatY) > 1);
	near($sprite->rotation, .625);
	check($sprite->flipX, 'left-facing artwork should mirror while its local nose-up rotation reverses');
	$system->close();
});

test('Tornado D-pad and post-dead-zone stick input use moving-boxes pitch signs', function() {

	$sample = static function(array $values, int $direction = 1): array {
		$registry = new Registry;
		$terrain = new Terrain;
		$terrain->add([[-1000, 100], [1000, 100], [1000, 500], [-1000, 500]]);
		$system = new Vehicles($registry, $terrain, new Platformer($terrain));
		$body = Tornado::body(0, 0);
		$body->facing = $body->direction = $direction;
		$body->vx = 600 * $direction;
		$vehicle = new Vehicle($body);
		$tornado = new Tornado;
		$system->add($vehicle, $tornado);
		$vehicle->occupant = 1;
		$tornado->mounted($vehicle, new Body);
		$input = new Controls;
		$input->set($values);
		$tornado->control($vehicle, $input, null, 1 / 60);
		$result = [$tornado->pitch, $body->vy];
		$system->close();

		return $result;
	};

	[$dpadUp, $rightClimb] = $sample(['up' => 1]);
	[$stickUp] = $sample(['up' => .35]);
	[$dpadDown] = $sample(['down' => 1]);
	[$stickDown] = $sample(['down' => .35]);
	[$leftUp, $leftClimb] = $sample(['up' => .35], -1);
	[$neutral] = $sample([]);
	[$stickDrift] = $sample(['up' => .05]);

	near($dpadUp, $stickUp);
	near($dpadDown, $stickDown);
	near($stickDrift, $neutral);
	check($dpadUp < -.203 && $dpadDown > -.203, 'Up must pitch upward and Down downward');
	near($leftUp, $dpadUp);
	check($rightClimb < 0 && $leftClimb < 0, 'Up must climb in either travel direction');
});

test('Tornado exchanges forward speed continuously and symmetrically with pitch', function() {

	$sample = static function(float $pitch): array {
		$body = Tornado::body();
		$body->facing = $body->direction = 1;
		$body->vx = 600;
		$vehicle = new Vehicle($body);
		$vehicle->occupant = 1;
		$tornado = new Tornado;
		$tornado->mounted($vehicle, new Body);
		$tornado->flying = $tornado->thrusting = true;
		$tornado->landingGear = false;
		$tornado->pitch = $pitch;
		$input = new Controls;
		$input->set([2 => true]);
		$tornado->control($vehicle, $input, null, 1 / 60);

		return [$body->vx, $body->vy, $tornado->pitch];
	};

	[$climbSpeed, $climbVelocity, $climbPitch] = $sample(-.5);
	[$levelSpeed, $levelVelocity] = $sample(0);
	[$descentSpeed, $descentVelocity, $descentPitch] = $sample(.5);

	check($climbSpeed < $levelSpeed && $levelSpeed < $descentSpeed);
	near($levelSpeed, 600);
	near($levelVelocity, 0);
	near($climbSpeed * $descentSpeed, 600 ** 2);
	near($climbPitch, -$descentPitch);
	check($climbVelocity < 0 && $descentVelocity > 0);
});

test('Tornado course loads annotated flight art and leaves its aircraft out of static collision', function() {
	$map = (new PhpWasmEngine\Tiled\Loader)->map(dirname(__DIR__, 3) . '/examples/assets/tornado.tmj');
	check($map->data['width'] === 320 && $map->data['height'] === 96);
	$registry = new Registry;
	$terrain = PhpWasmEngine\Physics\MapTerrain::build($map);
	$system = new Vehicles($registry, $terrain, new Platformer($terrain), $map);
	check(count($system->vehicles) === 1);
	$vehicle = array_values($system->vehicles)[0];
	check($registry->has($vehicle->entity, Tornado::class));
	check($registry->get($vehicle->entity, Sprite::class)->gid === 38);
	check($vehicle->seatHeight === 14.0 && $vehicle->seatForward === 0.0);
	[$seatX] = $vehicle->seat(new Body);
	near($seatX, $vehicle->body->supportX(), 1e-6);
	$vehicle->body->facing = $vehicle->body->direction = -1;
	[$leftSeatX] = $vehicle->seat(new Body);
	near($leftSeatX, $vehicle->body->supportX(), 1e-6);
	check(!$terrain->solidAt($vehicle->body->x, $vehicle->body->y));
	check($terrain->solidAt(16, 2800));
	check($terrain->solidAt(2128, 2784) && !$terrain->solidAt(2128, 2700));
	check($terrain->solidAt(6400, 2600) && !$terrain->solidAt(6400, 2500));
	check(!$terrain->solidAt(4000, 32), 'the terrain-following course must not impose a ceiling');
	$system->close();
});

test('Tornado reference controls traverse both sensor ridges and land on the final runway', function() {
	$map = (new PhpWasmEngine\Tiled\Loader)->map(dirname(__DIR__, 3) . '/examples/assets/tornado.tmj');
	$registry = new Registry;
	$terrain = PhpWasmEngine\Physics\MapTerrain::build($map);
	$system = new Vehicles($registry, $terrain, new Platformer($terrain), $map);
	$vehicle = array_values($system->vehicles)[0];
	$tornado = $registry->get($vehicle->entity, Tornado::class);
	$player = $registry->create(new Body(144, 2656), new Rider);
	check($system->mount($player, $vehicle->entity));
	$input = new Controls;
	$gear = false;
	$minimumY = INF;
	$landed = false;

	for($frame = 0; $frame < 1200; $frame++)
	{
		if($vehicle->body->x < 7600)
		{
			$values = [2 => true];

			if($vehicle->body->y > 2400) $values['up'] = true;
		}
		else
		{
			$values = ['down' => true];

			if(!$gear)
			{
				$values[1] = true;
				$gear = true;
			}
		}

		$input->set($values);
		$system->control($player, $input, 1 / 60);
		$system->update($registry, 1 / 60);
		$minimumY = min($minimumY, $vehicle->body->y);
		check(!$tornado->crashed, 'the authored ring route must clear its terrain profile');

		if($vehicle->body->grounded && $vehicle->body->x > 7600)
		{
			$landed = true;
			break;
		}
	}

	check($minimumY < 2400, 'the gradual high ridge should exercise a sustained climb');
	check($landed && $vehicle->body->x >= 8192 && $vehicle->body->x <= 10192);
	check($tornado->landingGear && !$tornado->flying);
	$system->close();
});

test('Tornado high-speed flight cannot tunnel through a thin wall', function() {
	$terrain = new Terrain;
	$terrain->add([[100, -200], [101, -200], [101, 200], [100, 200]]);
	$body = Tornado::body(0, 0);
	$body->gravity = 0;
	$body->vx = 600;
	(new Platformer($terrain))->step($body, new Controls, .2);
	check($body->x + $body->radius <= 100.001);
	near($body->vx, 0);
});

test('Tornado pitch remains controllable after clearing the runway sensor', function() {
	$registry = new Registry;
	$terrain = new Terrain;
	$terrain->add([[-1000, 100], [2000, 100], [2000, 500], [-1000, 500]]);
	$system = new Vehicles($registry, $terrain, new Platformer($terrain));
	$vehicle = new Vehicle(Tornado::body(0, 76), seatHeight: 14, seatForward: 0, lockedIn: true);
	$vehicle->body->grounded = true;
	$tornado = new Tornado;
	$id = $system->add($vehicle, $tornado);
	$rider = new Body(0, 68);
	$player = $registry->create($rider, new Rider);
	check($system->mount($player, $id));
	$input = new Controls;
	$noseUp = 0.0;

	for($frame = 0; $frame < 120; $frame++)
	{
		$input->set([2 => true, 'up' => true]);
		$system->control($player, $input, 1 / 60);
		$system->update($registry, 1 / 60);
		$noseUp = min($noseUp, $tornado->pitch);
		check($system->ridden($player) === $vehicle, 'pitch cannot eject a seated rider near terrain');
	}

	check($vehicle->body->x > 200 && $vehicle->body->y < 76);
	check($noseUp < -.2, 'the downward sensor should pitch up to clear nearby terrain');
	check($vehicle->visualAngle < -.1, 'holding Up must retain a climb beyond the terrain sensor');
});

test('EggWalker composes reference locomotion with ECS melee and emitter capabilities', function() {
	$body = EggWalker::body(0, 68);
	check($body->radius === 16.0 && $body->halfHeight === 32.0);
	check($body->acceleration === 360.0 && $body->braking === 324.0 && $body->friction === 180.0);
	check($body->airAcceleration === 1080.0 && $body->gravity === 2340.0);
	check($body->jumpSpeed === 720.0 && $body->maxSpeed === 150.0);
	$vehicle = new Vehicle($body, seatHeight: 48);
	$vehicle->occupant = 1;
	$walker = new EggWalker;
	$components = $walker->vehicleComponents();
	check(count($components) === 4 && $components[0] instanceof VehicleMelee);
	check($components[1] instanceof VehicleEmitter);
	check($components[2] instanceof CameraShake);
	check($components[3] instanceof Rumble);
	$rider = new Body;
	$walker->mounted($vehicle, $rider);
	check($vehicle->cameraMode === 'walker');

	$input = new Controls;
	$body->grounded = false;
	$body->vy = 120;
	$input->set([0 => true]);
	$walker->control($vehicle, $input, null, 1 / 60);
	check($walker->boosting && $body->vy < 120);
	$input->set([0 => true, 'up' => true]);
	$walker->control($vehicle, $input, null, 1 / 60);
	check($walker->latchedBooster);
	$input->set([]);
	$walker->control($vehicle, $input, null, 1 / 60);
	check($walker->boosting, 'the Up latch should retain the air booster after releasing jump');

	$body->vy = 120;
	$input->set(['down' => true]);
	$walker->control($vehicle, $input, null, 1 / 60);
	check($walker->crouching && !$walker->boosting && !$vehicle->jumpEnabled);
	check($body->halfHeight === 24.0 && $vehicle->seatHeight === 30.0 && $body->vy === 360.0);
	$input->set([4 => true]);
	$walker->control($vehicle, $input, null, 1 / 60);
	check($body->facing === -1 && $body->direction === -1 && $body->halfHeight === 32.0);
	$input->set([5 => true]);
	$walker->control($vehicle, $input, null, 1 / 60);
	check($body->facing === 1 && $body->direction === 1);

	$body->grounded = false;
	$body->vy = 500;
	$input->set(['down' => true]);
	$walker->control($vehicle, $input, null, 1 / 60);
	$body->grounded = true;
	$walker->updated($vehicle, null, 1 / 60);
	near($components[2]->y, -6.65);
	near($walker->vehicleSnapshot($vehicle)['shakeY'], -6.65);

	$components[2]->impulse();
	$input->set([]);
	$walker->control($vehicle, $input, null, 1 / 60);
	$body->grounded = false;
	$body->vy = 100;
	$input->set(['down' => true]);
	$walker->control($vehicle, $input, null, 1 / 60);
	$body->grounded = true;
	$walker->updated($vehicle, null, 1 / 60);
	near($components[2]->y, 0);
});

test('EggWalker Tiled loading registers behavior and reusable weapon components', function() {
	$map = (new PhpWasmEngine\Tiled\Loader)->map(dirname(__DIR__, 3) . '/examples/assets/egg-walker.tmj');
	$registry = new Registry;
	$terrain = PhpWasmEngine\Physics\MapTerrain::build($map);
	$system = new Vehicles($registry, $terrain, new Platformer($terrain), $map);
	check(count($system->vehicles) === 1);
	$vehicle = array_values($system->vehicles)[0];
	check($registry->has($vehicle->entity, EggWalker::class));
	check($registry->has($vehicle->entity, VehicleMelee::class));
	check($registry->has($vehicle->entity, VehicleEmitter::class));
	check($registry->has($vehicle->entity, CameraShake::class));
	check($registry->get($vehicle->entity, Sprite::class)->gid === 38);
	check($registry->has($vehicle->entity, SpriteLayers::class));
	$layers = $registry->get($vehicle->entity, SpriteLayers::class);
	check($layers->behind === [$registry->get($vehicle->entity, Sprite::class)]);
	check(count($layers->front) === 1 && $layers->front[0]->gid === 39);
	check($vehicle->seatHeight === 48.0 && $vehicle->seatForward === 0.0);
	check(!$terrain->solidAt($vehicle->body->x, $vehicle->body->y));
	check($terrain->solidAt(32, 640) && !$terrain->solidAt(2048, 640));
	$playerSprite = new Sprite(1);
	$player = $registry->create(new Body(144, 574), new Rider, $playerSprite);
	check($system->mount($player, $vehicle->entity));
	check($layers->anchor === $player);
	check(SpriteGroup::collectGroups($registry) === [[$layers->behind[0]], [$playerSprite], [$layers->front[0]]]);
	$input = new Controls;
	$minimumY = INF;

	for($frame = 0; $frame < 1200; $frame++)
	{
		$values = ['right' => true];

		if($vehicle->body->x > 1800 && $vehicle->body->x < 2350) $values[0] = true;

		$input->set($values);
		$system->control($player, $input, 1 / 60);
		$system->update($registry, 1 / 60);
		$minimumY = min($minimumY, $vehicle->body->y);

		if($vehicle->body->x > 2400) break;
	}

	check($frame < 1200 && $vehicle->body->grounded, 'the authored booster gap must match walker physics');
	check($minimumY < 520, 'the gap should require a visible boosted arc');
	$system->close();
});

test('EggWalker ECS weapons repeat fire and pop badniks with rider credit', function() {
	$registry = new Registry;
	$terrain = new Terrain;
	$terrain->add([[-1000, 100], [2000, 100], [2000, 500], [-1000, 500]]);
	$system = new Vehicles($registry, $terrain, new Platformer($terrain));
	$vehicle = new Vehicle(EggWalker::body(0, 68), seatHeight: 48, lockedIn: true);
	$vehicle->body->grounded = true;
	$id = $system->add($vehicle, new EggWalker);
	$body = new Body(0, 34);
	$counter = new RingCounter;
	$combat = new PlayerCombat;
	$player = $registry->create($body, new Rider, $counter, $combat);
	check($system->mount($player, $id));
	$badniks = new Badniks($registry, $terrain);

	$enemy = $badniks->create(
		new Badnik(80, 68, points: 100)
		, new class implements BadnikBehavior {}
		, new Sprite(1)
	);

	$input = new Controls;
	$input->set([1 => true]);
	$system->control($player, $input, 1 / 60);
	$system->update($registry, 1 / 60);
	$badniks->update($registry, 1 / 60);
	check(!$registry->has($enemy, Badnik::class));
	check($counter->score === 100 && $combat->defeats === 1);
	$input->set([]);

	for($frame = 0; $frame < 12; $frame++)
	{
		$system->control($player, $input, 1 / 60);
		$system->update($registry, 1 / 60);
		$badniks->update($registry, 1 / 60);
	}

	$target = $badniks->create(
		new Badnik(112, 44, points: 100)
		, new class implements BadnikBehavior {}
		, new Sprite(1)
	);

	$input->set([2 => true]);

	for($frame = 0; $frame < 30 && $registry->has($target, Badnik::class); $frame++)
	{
		$system->control($player, $input, 1 / 60);
		$system->update($registry, 1 / 60);
		$badniks->update($registry, 1 / 60);
	}

	check(!$registry->has($target, Badnik::class));
	check($counter->score === 200 && $combat->defeats === 2);
	$emitter = $registry->get($id, VehicleEmitter::class);
	check($emitter->shots >= 2, 'holding cannon fire should use the configured repeat interval');
	check(count($registry->query(VehicleProjectile::class)) < $emitter->shots, 'the hitting projectile should retire');
	$badniks->close();
	$system->close();
});
