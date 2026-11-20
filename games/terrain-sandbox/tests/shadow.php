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

	// On the ground the patch is darkest between the wheels, with a fainter skirt under the body's outline that shows
	// just past the bumpers and sills, where the chase camera can see it.
	$standing = GroundShadow::shape($parked, 0.0);
	$dark = static fn(float $along, float $across, ?array $shape = null): float =>
		GroundShadow::darkness($shape ?? $standing, $along, $across);
	expect(near($standing['shade'][0], GroundShadow::STRENGTH) && near($standing['skirt'][0], GroundShadow::SKIRT),
		'A car on the ground darkens its patch fully');
	expect(near($dark(0, 0), GroundShadow::STRENGTH), 'It is darkest under the middle of the car');
	$halfBase = Car::WHEELBASE / 2;
	$tyres = [[$halfBase, Car::FRONT_TRACK / 2], [$halfBase, -Car::FRONT_TRACK / 2], [-$halfBase, Car::REAR_TRACK / 2],
		[-$halfBase, -Car::REAR_TRACK / 2]];
	$atTyres = array_map(static fn(array $at) => $dark(...$at), $tyres);
	// The rear tyres sit 0.15 m inside the sills, where the skirt has just begun to fade.
	expect(min($atTyres) >= .9 * GroundShadow::SKIRT && max($atTyres) < GroundShadow::STRENGTH,
		'Under the tyres it is lighter than the middle and about as dark as the skirt');
	$bumper = $dark(-Car::BODY_REAR, 0); $sill = $dark(0, Car::BODY_HALF_WIDTH);
	expect($bumper > .2 && $sill > .2 && $bumper < GroundShadow::SKIRT && $sill < GroundShadow::SKIRT,
		"At the rear bumper and the sills it shows ($bumper, $sill)");
	expect($dark(-Car::BODY_REAR - .2, 0) > 0 && $dark(-Car::BODY_REAR - .4, 0) === 0.0
		&& $dark(Car::BODY_FRONT + .4, 0) === 0.0 && $dark(0, Car::BODY_HALF_WIDTH + .4) === 0.0,
		'It is gone 0.4 m past the body on every side');
	[$a0, $a1, $c0, $c1] = $standing['extent'];
	expect(near($c0, -$c1) && near($a0 + $a1, 2 * GroundShadow::BODY_CENTRE) && $a0 <= -Car::BODY_REAR - .4
		&& $a1 >= Car::BODY_FRONT + .4 && $c1 >= Car::BODY_HALF_WIDTH + .4 && $a1 < Car::BODY_FRONT + .6,
		'The grid covers the body and its fade, and little more');
	$fading = true;
	for($r = 0.0; $r <= 3.0; $r += .05)
		$fading = $fading && $dark($r, 0) >= $dark($r + .05, 0) && $dark(-$r, 0) >= $dark(-$r - .05, 0)
			&& $dark(0, $r) >= $dark(0, $r + .05);
	expect($fading, 'It only ever fades outwards');

	// In the air it grows fainter and wider, measured from the ground under the car.
	$jump = GroundShadow::shape(['y' => 4.0] + $parked, 0.0);
	expect($dark(0, 0, $jump) < .25 * GroundShadow::STRENGTH && $jump['skirt'][2] > $standing['skirt'][2]
		&& $jump['extent'][1] > $a1, 'A car 4 m up leaves a fainter, wider patch');
	$high = GroundShadow::shape(['y' => 25.0] + $parked, 0.0);
	expect($high['shade'][0] < .005 && $high['extent'][1] === GroundShadow::shape(['y' => 9.0] + $parked, 0.0)['extent'][1],
		'High enough it is gone, and it stops widening');
	$hill = GroundShadow::shape(['y' => 30.1] + $parked, 30.0);
	expect(near($dark(0, 0, $hill), GroundShadow::STRENGTH), 'On a hill top the car still stands on its patch');

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
	$drawn = GroundShadow::shape($pose, $land->height(10.2, 20.4));
	expect($gl['uniforms']['uHalf'] === [GroundShadow::HALF_LENGTH, GroundShadow::HALF_WIDTH]
		&& $gl['uniforms']['uShade'] === $drawn['shade'] && $gl['uniforms']['uSkirt'] === $drawn['skirt']
		&& $gl['uniforms']['uBody'] === [GroundShadow::BODY_HALF_LENGTH, GroundShadow::BODY_HALF_WIDTH, GroundShadow::BODY_CENTRE],
		'The shader gets the footprints and the shades that darkness() describes');

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
