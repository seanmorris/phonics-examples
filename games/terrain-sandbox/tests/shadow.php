<?php
declare(strict_types=1);

// The car's ground shadow: where it falls for each sun, how it fades in the air, the grid that covers it, the terrain
// heights it keeps, and the GL state it leaves. GroundShadow draws through WebGL in the browser; here a recording
// stand-in for the GL calls and the engine's Program class shows what it uploads and sets.

namespace Phonics\Render
{
	if(!class_exists(Program::class))
	{
		/** Stands in for the engine's shader program. */
		final class Program
		{
			public function __construct(string $vertex, string $fragment) {}
			public function use(): void {}
			public function location(string $name): int { return \glGetUniformLocation(0, $name); }
			public function close(): void {}
		}
	}
}

namespace
{
	$package = getenv('PHONICS_TERRAIN_ROOT') ?: dirname(__DIR__, 3) . '/packages/terrain-phonics';
	require_once $package . '/autoload.php';
	require_once dirname(__DIR__) . '/autoload.php';

	use TerrainSandbox\Car;
	use TerrainSandbox\GroundShadow;
	use TerrainSandbox\Session;

	foreach(['GL_ARRAY_BUFFER', 'GL_ELEMENT_ARRAY_BUFFER', 'GL_STATIC_DRAW', 'GL_DYNAMIC_DRAW', 'GL_FLOAT',
		'GL_UNSIGNED_SHORT', 'GL_TRIANGLES', 'GL_BLEND', 'GL_ZERO', 'GL_SRC_COLOR', 'GL_ONE', 'GL_LEQUAL', 'GL_LESS'] as $index => $name)
		if(!defined($name)) define($name, 0x1000 + $index);

	/** GL state the shadow leaves behind, and what it uploaded and drew. */
	$gl = ['enabled' => [], 'depthMask' => true, 'depthFunc' => GL_LESS, 'vertices' => '', 'draws' => [], 'uniforms' => []];
	$names = [];
	function glGetUniformLocation(int $program, string $name): int { $GLOBALS['names'][] = $name; return count($GLOBALS['names']) - 1; }
	function glUniform2f(int $l, float ...$v): void { $GLOBALS['gl']['uniforms'][$GLOBALS['names'][$l]] = $v; }
	function glUniform3f(int $l, float ...$v): void { $GLOBALS['gl']['uniforms'][$GLOBALS['names'][$l]] = $v; }
	function glUniform4f(int $l, float ...$v): void { $GLOBALS['gl']['uniforms'][$GLOBALS['names'][$l]] = $v; }
	function glUniformMatrix4fv(int $l, int $count, bool $transpose, array $v): void {}
	function glGenVertexArrays(int $count, array &$names): void { $names = [1]; }
	function glGenBuffers(int $count, array &$names): void { $names = range(1, $count); }
	function glEnable(int $cap): void { $GLOBALS['gl']['enabled'][$cap] = true; }
	function glDisable(int $cap): void { unset($GLOBALS['gl']['enabled'][$cap]); }
	function glDepthMask(bool $on): void { $GLOBALS['gl']['depthMask'] = $on; }
	function glDepthFunc(int $func): void { $GLOBALS['gl']['depthFunc'] = $func; }
	function glBufferData(int $target, int $size, string $data, int $usage): void
	{
		if($target === GL_ARRAY_BUFFER) $GLOBALS['gl']['vertices'] = $data;
	}
	function glDrawElements(int $mode, int $count, int $type, int $offset): void { $GLOBALS['gl']['draws'][] = $count; }
	foreach(['glBindVertexArray', 'glDeleteVertexArrays', 'glBindBuffer', 'glEnableVertexAttribArray',
		'glVertexAttribPointer', 'glDeleteBuffers', 'glBlendFuncSeparate'] as $name)
		eval("function $name(...\$arguments): void {}");

	$checks = 0;
	function expect(bool $condition, string $message): void
	{
		global $checks;
		$checks++;
		if(!$condition) throw new RuntimeException($message);
	}
	function near(float $a, float $b, float $tolerance = 1e-9): bool { return abs($a - $b) <= $tolerance; }

	$parked = ['x' => 0.0, 'y' => .1, 'z' => 0.0, 'yaw' => 0.0];

	// On the ground the patch is a soft dark area between the wheels, the same whichever way the car faces.
	$standing = GroundShadow::shape($parked, 0.0);
	$shade = $standing['shade'];
	expect(near($shade[0], GroundShadow::STRENGTH), 'A car on the ground darkens its patch fully');
	[$a0, $a1, $c0, $c1] = $standing['extent'];
	expect(near($a0, -$a1) && near($c0, -$c1), 'The patch is centred between the axles');
	expect(near(GroundShadow::darkness($shade, 0, 0), GroundShadow::STRENGTH), 'It is darkest under the middle of the car');
	$halfBase = Car::WHEELBASE / 2;
	$tyres = [[$halfBase, Car::FRONT_TRACK / 2], [$halfBase, -Car::FRONT_TRACK / 2], [-$halfBase, Car::REAR_TRACK / 2],
		[-$halfBase, -Car::REAR_TRACK / 2]];
	$atTyres = max(array_map(static fn(array $at) => GroundShadow::darkness($shade, ...$at), $tyres));
	$underTyres = min(array_map(static fn(array $at) => GroundShadow::darkness($shade, ...$at), $tyres));
	expect($underTyres > .05 * GroundShadow::STRENGTH && $atTyres < .2 * GroundShadow::STRENGTH,
		"It has mostly faded where the tyres touch the ground ($underTyres to $atTyres)");
	$side = GroundShadow::darkness($shade, 0, Car::REAR_TRACK / 2);
	$axle = GroundShadow::darkness($shade, $halfBase, 0);
	expect(min($side, $axle) > .2 * GroundShadow::STRENGTH && max($side, $axle) < .5 * GroundShadow::STRENGTH,
		"At the middle of each wheel line some of it remains ($side, $axle)");
	expect(GroundShadow::darkness($shade, $halfBase + .26, 0) === 0.0
		&& GroundShadow::darkness($shade, 0, Car::REAR_TRACK / 2 + .26) === 0.0
		&& Car::REAR_TRACK / 2 + .26 < Car::BODY_HALF_WIDTH + .15 && $halfBase + .26 < Car::BODY_REAR,
		'It is gone just past the wheels, under the sills and the overhangs');
	expect($a1 >= GroundShadow::HALF_LENGTH + $shade[2] && $c1 >= GroundShadow::HALF_WIDTH + $shade[2]
		&& $a1 < $halfBase + .5 && $c1 < Car::REAR_TRACK / 2 + .5, 'The grid covers the fade and little more');
	$fading = true;
	for($r = 0.0; $r <= 1.5; $r += .05)
		$fading = $fading && GroundShadow::darkness($shade, $r, 0) >= GroundShadow::darkness($shade, $r + .05, 0)
			&& GroundShadow::darkness($shade, 0, $r) >= GroundShadow::darkness($shade, 0, $r + .05);
	expect($fading, 'It only ever fades outwards');

	// In the air it grows fainter and wider, measured from the ground under the car.
	$jump = GroundShadow::shape(['y' => 4.0] + $parked, 0.0);
	expect($jump['shade'][0] < .25 * GroundShadow::STRENGTH && $jump['shade'][2] > $shade[2] && $jump['extent'][1] > $a1,
		'A car 4 m up leaves a fainter, wider patch');
	$high = GroundShadow::shape(['y' => 25.0] + $parked, 0.0);
	expect($high['shade'][0] < .005 && $high['extent'][1] === GroundShadow::shape(['y' => 9.0] + $parked, 0.0)['extent'][1],
		'High enough it is gone, and it stops widening');
	$hill = GroundShadow::shape(['y' => 30.1] + $parked, 30.0);
	expect(near($hill['shade'][0], GroundShadow::STRENGTH), 'On a hill top the car still stands on its patch');

	// The grid covers the extent at any heading.
	foreach([0.0, .4, M_PI / 2, 2.5, -1.1] as $yaw)
	{
		$pose = ['x' => 13.3, 'z' => -7.9, 'yaw' => $yaw];
		$extent = GroundShadow::shape($pose + $parked, 0.0)['extent'];
		[$i, $j, $columns, $rows] = GroundShadow::grid($pose, $extent);
		$s = sin($yaw); $c = cos($yaw);
		$inside = true;
		foreach([[$extent[0], $extent[2]], [$extent[0], $extent[3]], [$extent[1], $extent[2]], [$extent[1], $extent[3]]] as [$a, $b])
		{
			$x = $pose['x'] + $a * $s + $b * $c; $z = $pose['z'] + $a * $c - $b * $s;
			$inside = $inside && $x >= $i * GroundShadow::SPACING && $x <= ($i + $columns - 1) * GroundShadow::SPACING
				&& $z >= $j * GroundShadow::SPACING && $z <= ($j + $rows - 1) * GroundShadow::SPACING;
		}
		expect($inside && $columns <= 32 && $rows <= 32, "The grid covers the shadow at heading $yaw");
	}

	// Drawing: the grid takes the terrain's heights, keeps them, and only looks up the points it has not seen.
	$session = new Session(['worldSize' => 512, 'waterLevel' => -40]);
	$land = $session->landscape;
	$camera = $session->camera;
	$camera->position = [3.0, 12.0, -9.0];
	$shadow = new GroundShadow;
	$pose = ['x' => 10.2, 'y' => $land->height(10.2, 20.4) + .1, 'z' => 20.4, 'yaw' => .3];
	$first = $shadow->draw($pose, $land, $camera, 1280, 720);
	[, , $columns, $rows] = GroundShadow::grid($pose, GroundShadow::shape($pose, $land->height(10.2, 20.4))['extent']);
	expect($first === $columns * $rows + 1, 'The first frame looks up every grid point once, and the centre');
	expect(count($gl['draws']) === 1 && $gl['draws'][0] === 6 * ($columns - 1) * ($rows - 1), 'It draws the whole grid');
	$points = array_chunk(array_values(unpack('g*', $gl['vertices'])), 3);
	expect(count($points) === $columns * $rows, 'One vertex per grid point');
	$fits = true;
	foreach($points as [$x, $y, $z])
		$fits = $fits && near($y, $land->height($x + 3.0, $z - 9.0) + .03 - 12.0, 1e-4)
			&& near(fmod(abs($x + 3.0) + 1e-6, GroundShadow::SPACING), 0, 1e-4);
	expect($fits, 'Each vertex sits on the terrain, just above it, at a grid point, relative to the eye');
	expect(!isset($gl['enabled'][GL_BLEND]) && $gl['depthMask'] === true && $gl['depthFunc'] === GL_LESS,
		'It leaves blending off, depth writes on and the depth test at LESS');
	expect(near($gl['uniforms']['uOrigin'][0], 7.2, 1e-6) && near($gl['uniforms']['uOrigin'][1], 29.4, 1e-6),
		'The shadow centres on the car, relative to the eye');
	expect($gl['uniforms']['uHalf'] === [GroundShadow::HALF_LENGTH, GroundShadow::HALF_WIDTH]
		&& $gl['uniforms']['uShade'] === GroundShadow::shape($pose, $land->height(10.2, 20.4))['shade'],
		'The shader gets the footprint and the shade that darkness() describes');

	expect($shadow->draw($pose, $land, $camera, 1280, 720) === 1, 'A parked car looks up only the ground at its centre');
	$moved = ['x' => $pose['x'] + GroundShadow::SPACING] + $pose;
	$step = $shadow->draw($moved, $land, $camera, 1280, 720);
	expect($step - 1 <= $rows + 1, "Moving one grid step looks up at most one new column ($step)");

	// An edit, or another landscape, starts the heights again.
	$land->queue(['type' => 'raise', 'x' => 10, 'z' => 20, 'radius' => 6, 'amount' => 3]);
	$land->commit();
	expect($shadow->draw($moved, $land, $camera, 1280, 720) > $columns * $rows / 2, 'An edited landscape is looked up again');
	$points = array_chunk(array_values(unpack('g*', $gl['vertices'])), 3);
	$fits = true;
	foreach($points as [$x, $y, $z]) $fits = $fits && near($y, $land->height($x + 3.0, $z - 9.0) + .03 - 12.0, 1e-4);
	expect($fits, 'After the edit, the shadow follows the raised ground');

	// High in the air the car leaves nothing to draw.
	$draws = count($gl['draws']);
	$shadow->draw(['y' => $pose['y'] + 30] + $pose, $land, $camera, 1280, 720);
	expect(count($gl['draws']) === $draws, 'A car 30 m up draws no shadow');
	$shadow->close();
	$session->close();

	echo "Landscape sandbox shadow: $checks checks passed\n";
}
