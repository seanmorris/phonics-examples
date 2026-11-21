<?php
declare(strict_types=1);

// The Testarossa's lamps: when the brake, reversing and head lamps light, the headlight switch, and the uniforms that
// draw them. VehicleModel draws through WebGL in the browser. Here a recording stand-in for the GL calls and the
// engine's Program class shows what it sets.

namespace Phonics\Render
{
	if(!class_exists(Program::class))
	{
		/** Records the uniform names a VehicleModel asks for, in place of the engine's shader program. */
		final class Program
		{
			public static string $vertex = '', $fragment = '';
			private array $locations = [];

			public function __construct(string $vertex, string $fragment)
			{
				self::$vertex = $vertex;
				self::$fragment = $fragment;
			}

			public function use(): void {}

			public function location(string $name): int
			{
				return $this->locations[$name] ??= \glGetUniformLocation(0, $name);
			}

			public function close(): void {}
		}
	}
}

namespace
{
	$package = getenv('PHONICS_TERRAIN_ROOT') ?: dirname(__DIR__, 3) . '/packages/terrain-phonics';
	require_once $package . '/autoload.php';
	require_once dirname(__DIR__) . '/autoload.php';

	use Phonics\Render\Program;
	use TerrainSandbox\CarLamps;
	use TerrainSandbox\Session;
	use TerrainSandbox\VehicleModel;

	foreach(['GL_ARRAY_BUFFER', 'GL_ELEMENT_ARRAY_BUFFER', 'GL_STATIC_DRAW', 'GL_FLOAT', 'GL_SHORT', 'GL_UNSIGNED_SHORT',
		'GL_UNSIGNED_BYTE', 'GL_TEXTURE0', 'GL_TEXTURE_2D', 'GL_RGBA8', 'GL_RGBA', 'GL_TEXTURE_MIN_FILTER',
		'GL_LINEAR_MIPMAP_LINEAR', 'GL_TEXTURE_MAG_FILTER', 'GL_LINEAR', 'GL_TRIANGLES'] as $index => $name)
		if(!defined($name)) define($name, 0x1000 + $index);

	/** Every recorded uniform call: [uniform name, values]. */
	$gl = [];
	$uniforms = [];
	function glGetUniformLocation(int $program, string $name): int
	{
		$GLOBALS['uniforms'][] = $name;
		return count($GLOBALS['uniforms']) - 1;
	}
	function uniform(int $location, array $values): void
	{
		$GLOBALS['gl'][] = [$GLOBALS['uniforms'][$location] ?? $location, $values];
	}
	function glUniform1i(int $l, int $v): void { uniform($l, [$v]); }
	function glUniform1f(int $l, float $v): void { uniform($l, [$v]); }
	function glUniform2f(int $l, float ...$v): void { uniform($l, $v); }
	function glUniform3f(int $l, float ...$v): void { uniform($l, $v); }
	function glUniform4f(int $l, float ...$v): void { uniform($l, $v); }
	function glUniform1fv(int $l, int $count, array $v): void { uniform($l, $v); }
	function glUniform3fv(int $l, int $count, array $v): void { uniform($l, $v); }
	function glUniformMatrix3fv(int $l, int $count, bool $transpose, array $v): void { uniform($l, $v); }
	function glUniformMatrix4fv(int $l, int $count, bool $transpose, array $v): void { uniform($l, $v); }
	function glGenVertexArrays(int $count, array &$names): void { $names = [1]; }
	function glGenBuffers(int $count, array &$names): void { $names = range(1, $count); }
	function glGenTextures(int $count, array &$names): void { $names = [1]; }
	foreach(['glBindVertexArray', 'glDrawElements', 'glDeleteVertexArrays', 'glBindBuffer', 'glBufferData',
		'glEnableVertexAttribArray', 'glVertexAttribPointer', 'glActiveTexture', 'glBindTexture', 'glTexImage2D',
		'glTexParameteri', 'glGenerateMipmap', 'glDeleteBuffers', 'glDeleteTextures'] as $name)
		eval("function $name(...\$arguments): void {}");

	$checks = 0;
	function expect(bool $condition, string $message): void
	{
		global $checks;
		$checks++;
		if(!$condition) throw new RuntimeException($message);
	}
	function input(Session $session, array $value): void { $session->input($value, 1 / 60, 16 / 9); }
	/** Values each uniform received since an index, by name. */
	function received(int $since): array
	{
		$found = [];
		foreach(array_slice($GLOBALS['gl'], $since) as [$name, $values]) $found[$name] = $values;
		return $found;
	}
	/** Build a gamepad packet: [left X, left Y, right X, right Y, left trigger, right trigger, button bits]. */
	function pad(float $x = 0, float $lt = 0, float $rt = 0, int $buttons = 0): array
	{
		return [$x, 0, 0, 0, $lt, $rt, $buttons];
	}
	/**
	 * Hold keys and a pad for some fixed steps, as the application does each frame.
	 * @return list<array> The car's state and lamp levels after each step.
	 */
	function hold(Session $session, int $steps, array $keys = [], ?array $pad = null, ?string $untilMotion = null): array
	{
		$states = [];
		for($i = 0; $i < $steps; $i++)
		{
			input($session, ['keys' => $keys, 'pad' => $pad ?? pad()]);
			$session->step(1 / 60);
			$states[] = $session->car->state() + ['lamps' => $session->carLamps(1.0)];
			if($states[$i]['motion'] === $untilMotion) break;
		}
		return $states;
	}
	/** Board the car on open level ground in daylight, heading toward +Z. */
	function park(): Session
	{
		$session = new Session(['worldSize' => 1024, 'heightScale' => 0, 'detailAmplitude' => 0, 'waterLevel' => -5]);
		$session->camera->position = [0, 50, -300]; $session->camera->yaw = M_PI;
		input($session, ['actions' => ['car']]);
		for($i = 0; $session->findingCarSite() && $i < 2000; $i++) input($session, []);
		expect($session->mode === 'car', 'The car boards on open ground');
		return $session;
	}
	/** Whether every state's brake and reversing switches agree with its speed and the pedal. */
	function switchesFollow(array $states, int $pedal, bool $handbrake = false): bool
	{
		foreach($states as $s)
			if($s['braking'] !== ($handbrake || $pedal * $s['speed'] < 0)
				|| $s['reversing'] !== ($s['speed'] < 0 || $s['speed'] == 0 && $pedal < 0)) return false;
		return true;
	}
	/**
	 * Cast rays over each headlamp's beam cone against the car's body, the pods left out.
	 * @param string $meshFile The car's baked mesh.
	 * @param array $lamps The car's pods and headlamps from VehicleModel::lamps().
	 * @return Closure Takes the pods' opening from 0 to 1 and returns, for each beam, the share of its light that
	 * leaves the car: rays from the beam's origin over its cone, weighted as the terrain shader fades the cone, that
	 * strike no body triangle within 3 meters.
	 */
	function beamClearance(string $meshFile, array $lamps): Closure
	{
		$data = gzdecode(file_get_contents($meshFile));
		['v' => $count, 'i' => $corners] = unpack('Vv/Vi', $data);
		$vertices = [];
		for($i = 0; $i < $count; $i++)
			$vertices[] = array_values(unpack('g3', $data, 16 + 28 * $i)) + [3 => ord($data[16 + 28 * $i + 27])];
		$indices = array_values(unpack("v$corners", $data, 16 + 28 * $count));

		// The body ahead of the windscreen, each triangle as a corner and two edges.
		$body = [];
		for($k = 0; $k < $corners; $k += 3)
		{
			[$a, $b, $c] = [$vertices[$indices[$k]], $vertices[$indices[$k + 1]], $vertices[$indices[$k + 2]]];
			if(in_array($a[3], [6, 7], true) || max($a[2], $b[2], $c[2]) < 1.5) continue;
			$body[] = [$a, [$b[0] - $a[0], $b[1] - $a[1], $b[2] - $a[2]],
				[$c[0] - $a[0], $c[1] - $a[1], $c[2] - $a[2]]];
		}

		$cross = static fn(array $p, array $q): array => [$p[1] * $q[2] - $p[2] * $q[1], $p[2] * $q[0] - $p[0] * $q[2],
			$p[0] * $q[1] - $p[1] * $q[0]];
		$dot = static fn(array $p, array $q): float => $p[0] * $q[0] + $p[1] * $q[1] + $p[2] * $q[2];

		$blocked = static function(array $o, array $d) use ($body, $cross, $dot): bool {
			foreach($body as [$a, $e1, $e2])
			{
				$p = $cross($d, $e2);
				$det = $dot($e1, $p);
				if(abs($det) < 1e-12) continue;

				$s = [$o[0] - $a[0], $o[1] - $a[1], $o[2] - $a[2]];
				$u = $dot($s, $p) / $det;
				if($u < 0 || $u > 1) continue;
				$q = $cross($s, $e1);
				$v = $dot($d, $q) / $det;
				$t = $dot($e2, $q) / $det;
				if($v >= 0 && $u + $v <= 1 && $t > .02 && $t < 3) return true;
			}
			return false;
		};

		return static function(float $opening) use ($lamps, $blocked, $cross, $dot): array {
			[, $hingeY, $hingeZ] = $lamps['pods']['point'];
			$angle = $lamps['pods']['angle'] * ($opening - 1);
			$c = cos($angle);
			$s = sin($angle);
			$inner = cos(VehicleModel::BEAM_INNER);
			$outer = cos(VehicleModel::BEAM_OUTER);

			$shares = [];
			foreach($lamps['headlamps'] as ['position' => [$x, $y, $z], 'direction' => [$dx, $dy, $dz]])
			{
				$origin = [$x, $hingeY + ($y - $hingeY) * $c - ($z - $hingeZ) * $s,
					$hingeZ + ($y - $hingeY) * $s + ($z - $hingeZ) * $c];
				$axis = [$dx, $dy * $c - $dz * $s, $dy * $s + $dz * $c];
				$across = $cross($axis, [0, 1, 0]);
				$length = sqrt($dot($across, $across));
				$across = array_map(static fn(float $v): float => $v / $length, $across);
				$up = $cross($across, $axis);

				$total = $free = 0.0;

				for($ring = 0; $ring <= 6; $ring++)
				{
					$theta = VehicleModel::BEAM_OUTER * $ring / 6;
					$weight = max(0, min(1, (cos($theta) - $outer) / ($inner - $outer))) ** 2 * sin(max($theta, .01));

					for($k = 0; $k < ($ring ? 12 : 1); $k++)
					{
						$phi = M_PI * $k / 6;

						$ray = [];
						for($j = 0; $j < 3; $j++)
							$ray[] = cos($theta) * $axis[$j]
								+ sin($theta) * (cos($phi) * $across[$j] + sin($phi) * $up[$j]);

						$total += $weight;
						if(!$blocked($origin, $ray)) $free += $weight;
					}
				}
				$shares[] = $free / $total;
			}

			return $shares;
		};
	}

	// Brake lamps light while the pedal opposes the motion, reversing lamps while the car rolls backward.
	$keys = park();
	$status = $keys->status();
	expect($status['headlights'] === 'auto' && !$status['headlightsLit'] && $status['lamps'] === ['brake' => 0.0,
		'reverse' => 0.0, 'headlamps' => 0.0, 'pods' => 0.0] && !$status['car']['braking'] && !$status['car']['reversing'],
		'A car boarded in daylight starts with every lamp dark and the headlights on Auto');
	$keys->dirty = false;
	$keys->step(1 / 60);
	expect(!$keys->dirty, 'A parked car with its lamps dark leaves the scene idle');
	$drive = hold($keys, 90, ['KeyW' => true]);
	expect(switchesFollow($drive, 1) && !end($drive)['braking'] && end($drive)['speed'] > 10
		&& end($drive)['lamps']['brake'] === 0.0, 'Accelerating forward lights neither the brake nor the reversing lamps');
	$coast = hold($keys, 60);
	expect(switchesFollow($coast, 0) && end($coast)['speed'] < $drive[89]['speed'] - 1
		&& max(array_map(static fn(array $s): float => $s['lamps']['brake'], $coast)) === 0.0,
		'Coasting slows the car with the brake lamps dark');
	$braking = hold($keys, 300, ['KeyS' => true]);
	$stopped = 0;
	while($braking[$stopped]['speed'] > 0) $stopped++;
	expect(switchesFollow($braking, -1) && $stopped > 20 && $braking[$stopped - 1]['braking']
		&& !$braking[$stopped]['braking'] && $braking[$stopped]['reversing'] && end($braking)['speed'] < -2,
		"S lights the brake lamps until the car stops after $stopped steps, then the reversing lamps as it backs up");
	expect($braking[5]['lamps']['brake'] > .99 && $braking[3]['lamps']['brake'] > .6 && $braking[3]['lamps']['brake'] < .9
		&& abs($braking[$stopped + 5]['lamps']['reverse'] - 1) < 1e-9 && $braking[$stopped + 6]['lamps']['brake'] === 0.0,
		'A lamp takes a tenth of a second to light or go dark');
	$forward = hold($keys, 30, ['KeyW' => true]);
	expect(switchesFollow($forward, 1) && $forward[0]['braking'] && $forward[0]['reversing'],
		'W while rolling backward brakes, with the reversing lamps still lit');
	$backing = hold($keys, 400);
	$rolling = array_values(array_filter($backing, static fn(array $s): bool => $s['speed'] < 0));
	expect(switchesFollow($backing, 0) && end($backing)['speed'] == 0 && !end($backing)['reversing']
		&& (!$rolling || $rolling[0]['reversing']), 'The reversing lamps go out once the car rolls to a stop');
	$keys->close();

	// The pad's triggers do the same, at any squeeze.
	$stick = park();
	$floored = hold($stick, 90, pad: pad(rt: 1));
	expect(switchesFollow($floored, 1) && !end($floored)['braking'], 'The right trigger drives without the brake lamps');
	$light = hold($stick, 20, pad: pad(lt: .3));
	expect(switchesFollow($light, -1) && $light[0]['braking'] && end($light)['speed'] > 0,
		'A light squeeze of the left trigger while rolling forward lights the brake lamps');
	for($i = 0; $stick->car->state()['speed'] > 0 && $i < 600; $i++) hold($stick, 1, pad: pad(lt: 1));
	$reverse = hold($stick, 60, pad: pad(lt: 1));
	expect(switchesFollow($reverse, -1) && !end($reverse)['braking'] && end($reverse)['reversing'],
		'Holding the left trigger from a stop reverses with the reversing lamps lit');
	$check = hold($stick, 10, pad: pad(rt: .5));
	expect(switchesFollow($check, 1) && $check[0]['braking'], 'The right trigger brakes a car rolling backward');
	$stick->close();

	// The handbrake lights the brake lamps, moving or parked.
	$handbrake = park();
	hold($handbrake, 90, ['KeyW' => true]);
	$space = hold($handbrake, 30, ['Space' => true]);
	expect(switchesFollow($space, 0, true) && end($space)['lamps']['brake'] === 1.0, 'Space lights the brake lamps');
	$pulled = hold($handbrake, 30, pad: pad(buttons: Session::PAD_A));
	expect(switchesFollow($pulled, 0, true), 'So does A on a gamepad');
	for($i = 0; abs($handbrake->car->state()['speed']) > 0 && $i < 900; $i++) hold($handbrake, 1);
	$handbrake->dirty = false;
	$parked = hold($handbrake, 1, ['Space' => true]);
	expect($parked[0]['speed'] == 0 && $parked[0]['braking'] && $handbrake->dirty,
		'Pulling the handbrake on a parked car lights the brake lamps and redraws');
	$released = hold($handbrake, 10);
	expect(!end($released)['braking'] && end($released)['lamps']['brake'] === 0.0, 'Releasing it puts them out');
	$handbrake->close();

	// A wreck rocks about zero speed as it tumbles and settles. Its rear lamps follow the pedal alone.
	$wreck = new Session(['worldSize' => 256, 'heightScale' => 0, 'detailAmplitude' => 0, 'waterLevel' => -5]);
	$wreck->landscape->queue(['type' => 'raise', 'x' => 0, 'z' => 45, 'radius' => 16, 'amount' => 30]);
	$wreck->landscape->commit();
	$wreck->camera->position = [0, 30, -25]; $wreck->camera->yaw = M_PI;
	input($wreck, ['actions' => ['car']]);
	for($i = 0; $wreck->findingCarSite() && $i < 2000; $i++) input($wreck, []);

	$launch = hold($wreck, 500, ['KeyW' => true], untilMotion: 'tumbling');
	$crash = hold($wreck, 1500, ['KeyS' => true], untilMotion: 'overturned');
	$after = hold($wreck, 300);

	$down = static fn(array $s): bool => in_array($s['motion'], ['tumbling', 'overturned'], true);
	$held = array_values(array_filter($crash, $down));
	$speeds = array_map(static fn(array $s): float => $s['speed'], $held);
	expect(array_filter($launch, $down) && $held && min($speeds) < 0 && max($speeds) > 0
		&& in_array('overturned', array_column($held, 'motion'), true),
		'Over the ramp the car tumbles and lies overturned, rolling both ways while S is held');

	expect(!array_filter($launch, static fn(array $s): bool => $down($s) && ($s['braking'] || $s['reversing']))
		&& !array_filter($held, static fn(array $s): bool => !$s['braking'] || $s['reversing']),
		'While the car tumbles or lies overturned, W lights neither rear lamp and S lights the brake lamps alone');
	expect(min(array_map(static fn(array $s): float => $s['lamps']['brake'], array_slice($held, 6))) === 1.0
		&& max(array_map(static fn(array $s): float => $s['lamps']['reverse'], $held)) === 0.0,
		'So the rear lamps stay steady as the wreck rocks back and forth');

	$resting = array_values(array_filter($after, $down));
	$righted = array_values(array_filter($after, static fn(array $s): bool => !in_array($s['motion'],
		['tumbling', 'overturned'], true)));
	expect($resting && !array_filter($resting, static fn(array $s): bool => $s['braking'] || $s['reversing'])
		&& $righted && switchesFollow($righted, 0),
		'A wreck with the pedal released shows neither, and the car back on its wheels follows its speed again');
	$wreck->close();

	// Auto lights the headlights while the sun is below 5 degrees. The pods open over 1.2 seconds.
	$night = park();
	input($night, ['values' => ['elevation' => 5]]);
	expect(!$night->headlightsLit() && !$night->status()['headlightsLit'], 'Auto leaves the headlights off at 5 degrees');
	input($night, ['values' => ['elevation' => 4]]);
	expect($night->headlightsLit() && $night->status()['headlightsLit'], 'Auto lights them below 5 degrees');
	$opening = hold($night, 80);
	$pods = array_map(static fn(array $s): float => $s['lamps']['pods'], $opening);
	expect($opening[5]['lamps']['headlamps'] > .99 && $pods[0] > 0 && $pods[35] < 1 && $pods[70] < 1
		&& $pods[72] === 1.0 && abs($pods[35] - .5) < .03,
		'The headlamps light at once and the pods take 1.2 seconds to open');
	$eased = true;
	for($i = 1; $i < 72; $i++) $eased = $eased && $pods[$i] > $pods[$i - 1];
	expect($eased && $pods[1] - $pods[0] < $pods[36] - $pods[35] && $pods[71] - $pods[70] < $pods[36] - $pods[35],
		'The pods start and finish their swing slowly, as the model\'s animation does');
	$night->dirty = false;
	$night->step(1 / 60);
	expect(!$night->dirty, 'Once open, the pods and lit lamps leave a parked scene idle');
	[$previous, $latest] = [$opening[78]['lamps'], $night->carLamps(1.0)];
	input($night, ['values' => ['elevation' => 30]]);
	$night->step(1 / 60);
	$half = $night->carLamps(.5);
	$whole = $night->carLamps(1.0);
	expect($whole['pods'] < 1 && $half['headlamps'] === ($latest['headlamps'] + $whole['headlamps']) / 2,
		'The sun rising past 5 degrees closes the pods, and drawing between steps blends the levels');

	hold($night, 3, ['Space' => true]);
	input($night, ['actions' => ['pause']]);
	expect($night->carLamps(.5) === $night->carLamps(1.0), 'A paused car draws the lamps of the step it stopped on');
	$frozen = $night->carLamps(1.0);
	$night->step(1 / 60);
	input($night, ['keys' => ['KeyS' => true]]);
	input($night, ['values' => ['headlights' => 'auto', 'exposure' => 1.2]]);
	expect($night->carLamps(1.0) === $frozen && $frozen['pods'] > 0 && $frozen['pods'] < 1
		&& abs($frozen['brake'] - .5) < 1e-9,
		'Pausing freezes the lamps, a half-lit brake lamp and closing pods too, while the headlight switch stays put');

	// While paused, a change to the switch opens or closes the pods and lights or darkens the headlamps at once.
	$night->dirty = false;
	input($night, ['values' => ['headlights' => 'on']]);
	$snapped = $night->carLamps(1.0);
	expect($night->paused && $night->dirty && $snapped === array_replace($frozen, ['headlamps' => 1.0, 'pods' => 1.0])
		&& $night->carLamps(.5) === $snapped && $night->message === 'Headlights on.',
		'Switched on while paused, the headlights light and the pods stand open at once');

	input($night, ['actions' => ['headlights']]);
	expect($night->carLamps(1.0) === array_replace($frozen, ['headlamps' => 0.0, 'pods' => 0.0]),
		'L or X to Off while paused closes them at once, and the brake and reversing lamps keep their levels');
	$night->step(1 / 60);
	input($night, ['actions' => ['headlights']]);
	expect($night->headlights === 'auto' && $night->carLamps(1.0)['pods'] === 0.0,
		'Auto with the sun at 30 degrees keeps them closed');

	input($night, ['values' => ['elevation' => -12]]);
	$dark = $night->carLamps(1.0);
	expect($dark['pods'] === 1.0 && $dark['headlamps'] === 1.0
		&& count(VehicleModel::spotLights(VehicleModel::lamps(dirname(__DIR__) . '/assets/testarossa/mesh.bin.gz'),
			$night->carView(1.0)[1] + ['lamps' => $dark], $night->atmosphere->uniforms()['nightVisibility'])) === 2,
		'On Auto, setting the sun below the horizon while paused opens the pods and lights both beams at once');

	input($night, ['actions' => ['pause']]);
	$resumed = hold($night, 2);
	expect($resumed[0]['lamps']['pods'] === 1.0 && $night->carLamps(.5)['headlamps'] === 1.0,
		'Play resumes from the lamps the pause showed');
	$night->close();

	// The switch: L or X steps through Auto, On and Off. A select sets it directly.
	$switch = park();
	$messages = [];
	foreach(['on', 'off', 'auto'] as $expected)
	{
		input($switch, ['actions' => ['headlights']]);
		expect($switch->headlights === $expected && $switch->status()['headlights'] === $expected,
			"L or X steps the switch to $expected");
		$messages[] = $switch->message;
	}
	expect($messages === ['Headlights on.', 'Headlights off.', 'Headlights on Auto: lit while the sun is below 5°.'],
		'Each step says what the switch now does');
	input($switch, ['values' => ['headlights' => 'on']]);
	expect($switch->headlights === 'on' && $switch->headlightsLit() && $switch->message === 'Headlights on.',
		'The select switches the headlights on, even by day');
	$lit = hold($switch, 80);
	expect(end($lit)['lamps']['headlamps'] === 1.0 && end($lit)['lamps']['pods'] === 1.0
		&& !end($lit)['braking'], 'The headlights stay lit, and their tail lamps with them, without braking');
	input($switch, ['values' => ['headlights' => 'dipped']]);
	expect($switch->headlights === 'on' && $switch->message === 'Headlights must be auto, on or off',
		'Another value is refused and leaves the switch as it was');
	input($switch, ['values' => ['headlights' => 'off']]);
	$dark = hold($switch, 80);
	expect(!$switch->headlightsLit() && end($dark)['lamps']['headlamps'] === 0.0 && end($dark)['lamps']['pods'] === 0.0,
		'Off puts the headlights out and closes the pods');
	input($switch, ['values' => ['elevation' => -12]]);
	expect(!$switch->headlightsLit(), 'Off keeps them dark at night');
	input($switch, ['actions' => ['fly']]);
	$message = $switch->message;
	input($switch, ['values' => ['headlights' => 'on']]);
	expect($switch->headlights === 'on' && $switch->message === $message,
		'Out of the car the select changes the switch without a message, as when the page restores it');
	input($switch, ['actions' => ['headlights']]);
	expect($switch->headlights === 'off' && $switch->message === 'Headlights off.', 'L or X answers in any mode');
	expect($switch->status()['lamps'] === null, 'Status reports lamp levels only while the car is driven');

	// The switch belongs to the player: save files neither carry nor change it.
	$file = json_decode($switch->save(), true, flags: JSON_THROW_ON_ERROR);
	expect(!str_contains(json_encode($file['settings'], JSON_THROW_ON_ERROR), 'headlight'),
		'Save files do not record the headlight switch');
	$file['settings']['headlights'] = 'on';
	$switch->load(json_encode($file, JSON_THROW_ON_ERROR));
	expect($switch->headlights === 'off' && !array_key_exists('headlights', $switch->settings),
		'A file cannot choose the headlight switch');
	$switch->close();

	// The lamps never touch the motion: the same drive with the headlights on and off gives the same car.
	$on = park(); $off = park();
	input($on, ['values' => ['headlights' => 'on']]);
	input($off, ['values' => ['headlights' => 'off']]);
	$strip = static fn(array $states): array => array_map(static fn(array $s): array =>
		array_diff_key($s, ['lamps' => true]), $states);
	$plan = [[60, ['KeyW' => true, 'KeyD' => true]], [80, ['KeyS' => true]], [40, ['Space' => true, 'KeyA' => true]]];
	$same = true;
	foreach($plan as [$steps, $held])
		$same = $same && $strip(hold($on, $steps, $held)) === $strip(hold($off, $steps, $held));
	expect($same, 'The car drives exactly the same with its headlights on or off');
	$on->close(); $off->close();

	// Boarding again starts with the pods closed, then opens them at night.
	$board = park();
	input($board, ['values' => ['headlights' => 'on']]);
	hold($board, 80);
	input($board, ['actions' => ['fly']]);
	input($board, ['actions' => ['car']]);
	for($i = 0; $board->findingCarSite() && $i < 2000; $i++) input($board, []);
	expect($board->carLamps(1.0)['pods'] === 0.0 && hold($board, 1)[0]['lamps']['pods'] > 0,
		'A car boarded with the headlights on opens its pods as the driver gets in');
	$board->close();

	$lamps = new CarLamps;
	expect(!$lamps->step(1 / 60, false, false, false) && $lamps->view(.5) === ['brake' => 0.0, 'reverse' => 0.0,
		'headlamps' => 0.0, 'pods' => 0.0], 'Lamps left dark report no change');

	// The baked meshes: only the Testarossa has lamp tags, and its lamps sit where their roles say.
	$assets = dirname(__DIR__) . '/assets/';
	/** Triangle count by surface tag. */
	$tags = static function(string $file): array {
		$mesh = gzdecode(file_get_contents($file));
		[$vertices, $indices] = array_values(unpack('V2', $mesh));
		$corners = unpack("v$indices", $mesh, 16 + 28 * $vertices);
		$count = [];
		for($k = 1; $k <= $indices; $k += 3)
		{
			$tag = ord($mesh[16 + 28 * $corners[$k] + 27]);
			$count[$tag] = ($count[$tag] ?? 0) + 1;
		}
		ksort($count);
		return $count;
	};
	$first = VehicleModel::FIRST_LAMP;
	foreach(['jetski/mesh.bin.gz', 'f5/mesh.bin.gz', 'gates/road.mesh.bin.gz', 'gates/water.mesh.bin.gz',
		'gates/air.mesh.bin.gz'] as $file)
		expect(!array_filter(array_keys($tags($assets . $file)),
			static fn(int $tag): bool => $tag >= 1 && $tag <= $first + 5), "$file has no wheel or lamp tags");
	expect($tags($assets . 'testarossa/mesh.bin.gz') === [0 => 22026, 1 => 7512, 2 => 70, 3 => 18, 4 => 4, 5 => 142,
		6 => 944, 7 => 464], 'The Testarossa tags its tail strip, high brake lamp, reversing lamps, bumper lamps, '
		. 'headlamps and pod covers');
	$meshFile = $assets . 'testarossa/mesh.bin.gz';
	$data = VehicleModel::lamps($meshFile);
	[$hingeX, $hingeY, $hingeZ] = $data['pods']['point'];
	expect(VehicleModel::lamps($assets . 'jetski/mesh.bin.gz') === null && $data['pods']['axis'] === [1, 0, 0]
		&& abs($data['pods']['angle'] + deg2rad(55)) < .001, 'The pods open 55 degrees about a hinge across the car');
	[$left, $right] = $data['headlamps'];
	expect(count($data['headlamps']) === 2 && $left['position'][0] < -.5 && $right['position'][0] > .5
		&& abs($left['position'][0] + $right['position'][0]) < .03 && $left['position'][2] > $hingeZ
		&& abs($left['position'][1] - .58) < .01 && $left['direction'] === $right['direction']
		&& $left['direction'][2] > .999 && $left['direction'][1] < 0,
		'One beam per pod, 0.58 m up beside the center line, aims straight ahead and slightly down');

	// The uniforms: every lamp dark gives no glow, and only a car with lamps sets them.
	expect(VehicleModel::lampGlow(['brake' => 0.0, 'reverse' => 0.0, 'headlamps' => 0.0, 'pods' => 0.0])
		=== array_fill(0, 3 * VehicleModel::LAMPS, 0.0), 'Dark lamps give off no light');
	$red = static fn(float $radiance): array => array_map(static fn(float $c): float => $c * $radiance, VehicleModel::RED);
	$white = static fn(float $radiance): array => array_map(static fn(float $c): float => $c * $radiance,
		VehicleModel::WHITE);
	$zero = [0.0, 0.0, 0.0];
	expect(VehicleModel::lampGlow(['brake' => 1.0, 'reverse' => 0.0, 'headlamps' => 0.0, 'pods' => 0.0])
		=== [...$red(VehicleModel::BRAKE), ...$red(VehicleModel::BRAKE), ...$zero, ...$zero, ...$zero],
		'Braking lights the tail strip and the high brake lamp');
	expect(VehicleModel::lampGlow(['brake' => 0.0, 'reverse' => 1.0, 'headlamps' => 1.0, 'pods' => 1.0])
		=== [...$red(VehicleModel::TAIL), ...$zero, ...$white(VehicleModel::REVERSE), ...$white(VehicleModel::HEADLAMP),
			...$white(VehicleModel::HEADLAMP)],
		'The headlights light the tail strip dimly, the bumper lamps and the headlamps; reversing lights the white '
		. 'rear lamps');
	expect(VehicleModel::lampGlow(['brake' => 1.0, 'reverse' => 0.0, 'headlamps' => 1.0, 'pods' => 1.0])[0]
		=== VehicleModel::RED[0] * VehicleModel::BRAKE, 'Braking brightens lit tail lamps');

	$session = park();
	$camera = $session->camera;
	foreach(['jetski', 'f5'] as $name)
	{
		$since = count($gl);
		(new VehicleModel($assets . "$name/mesh.bin.gz"))->draw(['x' => 0, 'y' => 0, 'z' => 0, 'yaw' => 0, 'pitch' => 0,
			'roll' => 0, 'lamps' => $session->carLamps(1.0)], $camera, $session->atmosphere, $session->water, 1280, 720);
		expect(!array_intersect_key(received($since), ['uLampGlow[0]' => 1, 'uPodHinge' => 1, 'uPodAngle' => 1]),
			"The $name sets no lamp uniform, so they keep the shader's zero defaults");
	}
	foreach(['uLampGlow[LAMPS]', 'uPodHinge', 'uPodAngle'] as $name)
		expect(preg_match('/^uniform \w+ ' . preg_quote($name, '/') . ';$/m', Program::$vertex) === 1,
			"model.vert declares $name without a value, so it starts at zero");
	expect(str_contains(Program::$vertex, 'if(tag == 1)') && str_contains(Program::$fragment, 'flat in vec3 vGlow;'),
		'Only tag 1 turns as a wheel, and the fragment shader receives the glow');
	$car = new VehicleModel($meshFile);
	$pose = $session->car->state();
	$since = count($gl);
	$car->draw($pose, $camera, $session->atmosphere, $session->water, 1280, 720);
	expect(!array_key_exists('uLampGlow[0]', received($since)), 'A pose without lamp levels sets no lamp uniform');
	$since = count($gl);
	$car->draw($pose + ['lamps' => ['brake' => 1.0, 'reverse' => 0.0, 'headlamps' => .5, 'pods' => .25]], $camera,
		$session->atmosphere, $session->water, 1280, 720);
	$set = received($since);
	expect($set['uLampGlow[0]'] === VehicleModel::lampGlow(['brake' => 1.0, 'reverse' => 0.0, 'headlamps' => .5])
		&& $set['uPodHinge'] === [$hingeY, $hingeZ] && $set['uPodAngle'] === [$data['pods']['angle'] * .25],
		'The car sets each lamp\'s glow, the hinge and the pods\' angle');

	// The beams turn with the car and swing with the pods.
	$level = ['x' => 10.0, 'y' => 2.0, 'z' => -4.0, 'yaw' => 0.0, 'pitch' => 0.0, 'roll' => 0.0,
		'lamps' => ['brake' => 0.0, 'reverse' => 0.0, 'headlamps' => 1.0, 'pods' => 1.0]];
	$beams = VehicleModel::beams($data, $level);
	$near = static fn(array $a, array $b): bool => max(array_map(static fn($p, $q) => abs($p - $q), $a, $b)) < 1e-9;
	expect($near($beams[0]['position'], [10 + $left['position'][0], 2 + $left['position'][1], -4 + $left['position'][2]])
		&& $near($beams[0]['direction'], $left['direction']) && $beams[0]['level'] === 1.0,
		'A level car facing +Z shines its beams from the open pods straight ahead');
	$turned = VehicleModel::beams($data, ['yaw' => M_PI / 2] + $level);
	expect($near($turned[0]['direction'], [$left['direction'][2], $left['direction'][1], 0.0])
		&& $turned[0]['position'][0] > 11.9, 'Turned to +X, the beams point along +X from the nose');
	$roof = VehicleModel::beams($data, ['roll' => M_PI] + $level);
	expect($roof[0]['direction'][1] > 0 && $roof[0]['position'][1] < 2 && $roof[0]['level'] === 1.0,
		'On its roof the car keeps its beams lit, now aimed slightly up from below the body\'s origin');
	$closed = VehicleModel::beams($data, ['lamps' => ['pods' => 0.0] + $level['lamps']] + $level);
	expect($closed[0]['level'] === 0.0 && $closed[0]['direction'][1] < -.8,
		'With the pods closed the headlamps face down into the body and give no beam');

	// The beams become the terrain package's spot lights.
	$lights = VehicleModel::spotLights($data, $level, night: 1.0);
	$spots = new TerrainPhonics\SpotLights;
	$spots->set($lights);
	expect(count($lights) === 2 && $spots->count() === 2 && $lights[1]['position'] === $beams[1]['position']
		&& $lights[1]['direction'] === $beams[1]['direction']
		&& $lights[0]['intensity'] === VehicleModel::BEAM_INTENSITY && $lights[0]['color'] === VehicleModel::WHITE,
		'Open pods with lit headlamps give two spot lights on the beams');

	// While the pods rise, the headlamps face into the car's nose until they clear it. No light passes the body.
	[$hidden, $clear] = VehicleModel::BEAM_CLEAR;
	$leaving = beamClearance($meshFile, $data);
	expect(max($leaving($hidden)) === 0.0 && min($leaving($clear)) >= .99,
		'The nose stops every ray of both beams with the pods open to the first of BEAM_CLEAR, and almost none at the '
		. 'second');
	$rising = static fn(float $pods): array => VehicleModel::spotLights($data,
		['lamps' => ['headlamps' => 1.0, 'pods' => $pods] + $level['lamps']] + $level, night: 1.0);
	expect($rising(.5) === [] && $rising($hidden) === [],
		'Half-open pods give no spot lights: the headlamps still face into the nose');

	$part = $rising(($hidden + $clear) / 2);
	expect(count($part) === 2 && $part[0]['intensity'] > 0 && $part[0]['intensity'] < VehicleModel::BEAM_INTENSITY,
		'The beams brighten as the headlamps rise clear of the nose');

	$dim = ['lamps' => ['headlamps' => .5, 'pods' => $clear] + $level['lamps']] + $level;
	$dimBeams = VehicleModel::beams($data, $dim);
	$dimLights = VehicleModel::spotLights($data, $dim, night: 1.0);
	expect(abs($dimLights[0]['intensity'] - VehicleModel::BEAM_INTENSITY * .5) < 1e-9
		&& $dimLights[0]['direction'] === $dimBeams[0]['direction']
		&& $dimBeams[0]['direction'] !== $beams[0]['direction'],
		'Clear of the nose, a beam\'s intensity follows the headlamps, and its aim swings with the pods');

	foreach(['dark headlamps' => ['headlamps' => 0.0], 'shut pods' => ['pods' => 0.0]] as $case => $levels)
		expect(VehicleModel::spotLights($data, ['lamps' => $levels + $level['lamps']] + $level, night: 1.0) === [],
			"With $case the car gives no spot lights, so the application clears them");
	$session->close();

	// The beams make no visible difference in sunlight, so the car sets spot lights only once the sun has set.
	$dusk = park();
	input($dusk, ['values' => ['headlights' => 'on']]);
	$beamsAt = static function(float $elevation) use ($dusk, $data): array {
		input($dusk, ['values' => ['elevation' => $elevation]]);
		$lamps = hold($dusk, 80)[79]['lamps'];
		$night = $dusk->atmosphere->uniforms()['nightVisibility'];
		return [$lamps, $night, VehicleModel::spotLights($data, $dusk->carView(1.0)[1] + ['lamps' => $lamps], $night)];
	};

	[$lamps, $night, $day] = $beamsAt(35);
	expect($lamps['headlamps'] === 1.0 && $lamps['pods'] === 1.0 && $night === 0.0 && $day === [],
		'By day the switched-on headlamps glow with the pods open, and the car sets no spot lights');
	[, $night, $horizon] = $beamsAt(0);
	expect($night === 0.0 && $horizon === [], 'Nor with the sun on the horizon');

	[, $night, $sunset] = $beamsAt(-.5);
	expect($night > 0 && count($sunset) === 2, 'Once the sun is below it, both beams light the ground');

	input($dusk, ['values' => ['headlights' => 'auto']]);
	[$lamps, , $low] = $beamsAt(3);
	expect($dusk->headlightsLit() && $lamps['pods'] === 1.0 && $low === [],
		'Auto with the sun at 3 degrees opens the pods and lights the headlamps without beams');
	$dusk->close();

	echo "Landscape sandbox lamps: $checks checks passed\n";
}
