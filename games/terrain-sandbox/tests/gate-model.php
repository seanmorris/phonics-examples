<?php
declare(strict_types=1);

// GateModel draws through WebGL in the browser. Here a recording stand-in for the GL calls and the engine's
// Program class shows which gates it draws, with which print, and what it uploads.

namespace Phonics\Render
{
	if(!class_exists(Program::class))
	{
		/** Records the uniform names a GateModel asks for, in place of the engine's shader program. */
		final class Program
		{
			public static int $created = 0;
			private array $locations = [];

			public function __construct(string $vertex, string $fragment)
			{
				if(!str_contains($vertex, 'uniform vec2 uUvOffset;') || !str_contains($vertex, 'vUv = aUv + uUvOffset;'))
					throw new \RuntimeException('model.vert lacks the print offset');
				self::$created++;
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
	use TerrainSandbox\Course;
	use TerrainSandbox\GateModel;
	use TerrainSandbox\Session;

	foreach(['GL_ARRAY_BUFFER', 'GL_ELEMENT_ARRAY_BUFFER', 'GL_STATIC_DRAW', 'GL_FLOAT', 'GL_SHORT', 'GL_UNSIGNED_SHORT',
		'GL_UNSIGNED_BYTE', 'GL_TEXTURE0', 'GL_TEXTURE_2D', 'GL_RGBA8', 'GL_RGBA', 'GL_TEXTURE_MIN_FILTER',
		'GL_LINEAR_MIPMAP_LINEAR', 'GL_TEXTURE_MAG_FILTER', 'GL_LINEAR', 'GL_TRIANGLES'] as $index => $name)
		if(!defined($name)) define($name, 0x1000 + $index);

	/** Every recorded call: [function, arguments], with uniform locations replaced by their names. */
	$gl = [];
	$uniforms = [];
	function record(string $function, array $arguments): void { $GLOBALS['gl'][] = [$function, $arguments]; }
	function glGetUniformLocation(int $program, string $name): int
	{
		$GLOBALS['uniforms'][] = $name;
		return count($GLOBALS['uniforms']) - 1;
	}
	function uniform(string $function, int $location, array $values): void
	{
		record($function, [$GLOBALS['uniforms'][$location] ?? $location, ...$values]);
	}
	function glUniform1i(int $l, int $v): void { uniform(__FUNCTION__, $l, [$v]); }
	function glUniform1f(int $l, float $v): void { uniform(__FUNCTION__, $l, [$v]); }
	function glUniform2f(int $l, float ...$v): void { uniform(__FUNCTION__, $l, $v); }
	function glUniform3f(int $l, float ...$v): void { uniform(__FUNCTION__, $l, $v); }
	function glUniform4f(int $l, float ...$v): void { uniform(__FUNCTION__, $l, $v); }
	function glUniformMatrix3fv(int $l, int $count, bool $transpose, array $v): void { uniform(__FUNCTION__, $l, $v); }
	function glUniformMatrix4fv(int $l, int $count, bool $transpose, array $v): void { uniform(__FUNCTION__, $l, $v); }
	function glGenVertexArrays(int $count, array &$names): void { record(__FUNCTION__, []); $names = [count($GLOBALS['gl'])]; }
	function glGenBuffers(int $count, array &$names): void { $names = range(1, $count); }
	function glGenTextures(int $count, array &$names): void { $names = [count($GLOBALS['gl'])]; }
	function glBindVertexArray(int $name): void { record(__FUNCTION__, [$name]); }
	function glDrawElements(int $mode, int $count, int $type, int $offset): void { record(__FUNCTION__, [$count]); }
	function glDeleteVertexArrays(int $count, array $names): void { record(__FUNCTION__, $names); }
	foreach(['glBindBuffer', 'glBufferData', 'glEnableVertexAttribArray', 'glVertexAttribPointer', 'glActiveTexture',
		'glBindTexture', 'glTexImage2D', 'glTexParameteri', 'glGenerateMipmap', 'glDeleteBuffers', 'glDeleteTextures'] as $name)
		eval("function $name(...\$arguments): void {}");

	$checks = 0;
	function expect(bool $condition, string $message): void
	{
		global $checks;
		$checks++;
		if(!$condition) throw new RuntimeException($message);
	}
	/** Calls of one function in the log since an index. */
	function calls(string $function, int $since = 0): array
	{
		return array_values(array_map(static fn(array $call): array => $call[1],
			array_filter(array_slice($GLOBALS['gl'], $since), static fn(array $call): bool => $call[0] === $function)));
	}
	/** Values one uniform received since an index. */
	function values(string $uniform, int $since = 0): array
	{
		$found = [];
		foreach(array_slice($GLOBALS['gl'], $since) as [$function, $arguments])
			if(str_starts_with($function, 'glUniform') && $arguments[0] === $uniform) $found[] = array_slice($arguments, 1);
		return $found;
	}

	$session = new Session(['worldSize' => 8192, 'heightScale' => 0, 'detailAmplitude' => 0, 'waterLevel' => -5]);
	$shapes = $session->gateShapes;
	$model = new GateModel($shapes, dirname(__DIR__) . '/assets/gates/');
	$camera = $session->camera;
	$camera->position = [0.0, 10.0, 0.0]; $camera->yaw = M_PI; $camera->pitch = 0.0;
	$draw = static function() use ($model, $session, $camera): int {
		$since = count($GLOBALS['gl']);
		$model->draw($session->course, $camera, $session->atmosphere, $session->water, 1280, 720);
		return $since;
	};

	$course = $session->course;
	$start = $course->place('start', 'road', 0, 0, 100, 0)['gate'];
	$air = $course->place('checkpoint', 'air', 10, 40, 400, M_PI / 2)['gate'];
	$behind = $course->place('checkpoint', 'road', 0, 0, -100, 0)['gate'];
	$far = $course->place('checkpoint', 'road', 0, 0, 1600, 0)['gate'];
	$farAir = $course->place('checkpoint', 'air', 0, 40, 4000, 0)['gate'];
	$finish = $course->place('finish', 'road', -30, 0, 200, M_PI)['gate'];
	$since = $draw();
	$counts = array_column(calls('glDrawElements', $since), 0);
	$road = $shapes->kind('road'); $airShape = $shapes->kind('air');
	expect($model->drawn === 4 && count($counts) === 4, 'Four of the six gates are in range and in view');
	expect(Program::$created === 1 && count(calls('glGenVertexArrays', $since)) === 2,
		'The first frame compiles one program and uploads the road and air meshes, not the water mesh');
	$offsets = values('uUvOffset', $since);
	$origins = values('uOrigin', $since);
	expect(count($offsets) === 4 && count($origins) === 4, 'Each drawn gate sets its own print and position');
	// Road gates come first, in course order, then the air gates.
	expect($origins[0] === [0.0, -10.0, 100.0] && $origins[1] === [-30.0, -10.0, 200.0]
		&& $origins[2] === [10.0, 30.0, 400.0] && $origins[3] === [0.0, 30.0, 4000.0],
		'Draws are grouped by kind, and the gate 100 m behind and the road gate 1.6 km away are culled');
	expect($offsets[0] === $shapes->atlas['start'] && $offsets[1] === $shapes->atlas['finish']
		&& $offsets[2] === $shapes->atlas['checkpoint'] && $offsets[3] === $shapes->atlas['checkpoint'],
		'Each gate shows its role print through the atlas offset');
	$rotation = values('uRotation', $since);
	$basis = $rotation[2];
	expect(abs($basis[6] - 1) < 1e-12 && abs($basis[8]) < 1e-12 && abs($basis[0]) < 1e-12 && abs($basis[2] + 1) < 1e-12,
		'An air gate facing +X turns its +Z axis to +X and its +X axis to -Z');
	expect($counts[0] === $counts[1] && $counts[2] !== $counts[0], 'Road and air gates draw their own meshes');

	$since = $draw();
	expect($model->drawn === 4 && calls('glGenVertexArrays', $since) === [] && Program::$created === 1,
		'Later frames reuse the program and the meshes');
	expect(count(calls('glBindVertexArray', $since)) === 3, 'Each kind binds its mesh once, then the vertex array is unbound');
	$camera->yaw = 0.0;
	$since = $draw();
	expect($model->drawn === 1 && values('uOrigin', $since) === [[0.0, -10.0, -100.0]],
		'Turning round draws only the gate that was behind');
	$camera->yaw = M_PI;

	$course->remove($finish['id']);
	$course->remove($far['id']);
	$since = $draw();
	expect(values('uUvOffset', $since)[0] === $shapes->atlas['start-finish'],
		'Removing the finish gate switches the start gate to the start / finish print');

	// A water gate floats at the wave height of its position.
	$session->input(['values' => ['waterLevel' => 3.0, 'waterChoppiness' => 300.0]], 1 / 60, 16 / 9);
	$wet = $course->place('checkpoint', 'water', 5, 3, 60, 0)['gate'];
	$session->step(1 / 60);
	$float = $course->gate($wet['id'])['y'];
	$since = $draw();
	$origin = null;
	foreach(values('uOrigin', $since) as $value) if($value[0] === 5.0) $origin = $value;
	expect(abs($float - 3) > 1e-3 && $origin !== null && abs($origin[1] - ($float - 10)) < 1e-9
		&& count(calls('glGenVertexArrays', $since)) === 1, 'A water gate draws at its height on the waves, uploading the water mesh');

	// A loaded file replaces the course, and each loaded course starts its revision count again.
	$loaded = static fn(string $role): Course => Course::import(['gates' => [['role' => $role, 'kind' => 'road', 'x' => 0,
		'y' => 0, 'z' => 50, 'yaw' => 0] + ($role === 'start' ? ['laps' => 1] : [])]], 4096);
	$session->course = $loaded('finish');
	$since = $draw();
	expect($model->drawn === 1 && values('uUvOffset', $since) === [$shapes->atlas['finish']], 'A loaded course is drawn');
	$session->course = $loaded('start');
	$since = $draw();
	expect($session->course->revision === 0 && $model->drawn === 1
		&& values('uUvOffset', $since) === [$shapes->atlas['start-finish']],
		'A second loaded course with the same revision number is drawn afresh');

	$model->release();
	$since = $draw();
	expect(Program::$created === 2 && count(calls('glGenVertexArrays', $since)) === 1 && $model->drawn === 1,
		'After a lost context the next frame uploads the program and mesh again');
	$session->course = new Course;
	$model->draw($session->course, $camera, $session->atmosphere, $session->water, 1280, 720);
	expect($model->drawn === 0, 'A course without gates draws nothing');

	$session->close();
	echo "Landscape sandbox gate drawing: $checks checks passed\n";
}
