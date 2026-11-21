<?php
declare(strict_types=1);

namespace TerrainSandbox;

use Phonics\Render\Program;
use TerrainPhonics\Atmosphere;
use TerrainPhonics\AtmosphereRenderer;
use TerrainPhonics\Camera;
use TerrainPhonics\Water;
use TerrainPhonics\WaterAppearance;

/** A baked vehicle, drawn against the landscape and water depth buffer. */
final class VehicleModel
{
	/** Surface tags from 250 up turn about the hinges in the uniform arrays of model.vert, which hold this many. */
	public const FIRST_SURFACE = 250, SURFACES = 5;
	/**
	 * Lamp tags in model.vert: LAMPS tags from FIRST_LAMP up glow, and the last of them and the tag after it turn with
	 * the pop-up headlights. In order: the tail and brake lamps, the high brake lamp, the reversing lamps, the bumper
	 * lamps, the headlamps in the pods, and the pods' covers.
	 */
	public const FIRST_LAMP = 2, LAMPS = 5;
	/** Linear colors of the lamps' light: red lenses, and halogen bulbs behind clear ones. */
	public const RED = [1.0, .008, .004], WHITE = [1.0, .88, .7];
	/**
	 * Radiance of each lamp at full brightness in the shader's linear units, in which full sunlight on a white surface
	 * facing it gives up to 20 / π. A tail lamp glows at TAIL while the headlights are on, and at BRAKE while braking.
	 */
	public const BRAKE = 6.0, TAIL = .7, REVERSE = 2.5, HEADLAMP = 3.5;
	/**
	 * Each headlamp's beam on the ground and water, in the units of the terrain package's SpotLights: candela on the
	 * axis at full brightness, the range in meters, and the cone's inner and outer half-angles in radians.
	 */
	public const BEAM_INTENSITY = 80000.0, BEAM_RANGE = 120.0, BEAM_INNER = .1, BEAM_OUTER = .3;
	/**
	 * Openings of the pods, as Session::carLamps() gives them, between which the rising headlamps clear the car's
	 * nose. Below the first the nose stops all of each beam, and above the second it stops less than 1%.
	 * tests/lamps.php measures both by casting rays over the beams' cones against the Testarossa's mesh.
	 */
	public const BEAM_CLEAR = [.6, .76];
	private Program $program;
	private BakedMesh $mesh;
	/** Pose key driving each hinge slot, and every slot's hinge point and unit axis, flattened for the shader. */
	private array $controls = [], $hingePoints = [], $hingeAxes = [];
	/** The pods and headlamps from lamps.json, or null for a model without lamps. */
	private ?array $lamps;

	/** Upload the baked model once on boarding, or again when the context is restored. */
	public function __construct(string $meshFile)
	{
		$hinges = self::hinges($meshFile);
		if($hinges)
		{
			$points = $axes = array_fill(0, self::SURFACES, [0.0, 0.0, 0.0]);
			foreach($hinges as $slot => $hinge)
			{
				$this->controls[$slot] = $hinge['control'];
				$points[$slot] = $hinge['point'];
				$axes[$slot] = $hinge['axis'];
			}
			$this->hingePoints = array_merge(...$points);
			$this->hingeAxes = array_merge(...$axes);
		}
		$this->lamps = self::lamps($meshFile);
		$this->mesh = new BakedMesh($meshFile);
		try { $this->program = self::program(); }
		catch(\Throwable $error)
		{
			$this->mesh->close();
			throw $error;
		}
	}

	/**
	 * Compile the model shaders, which light the vehicles and the race gates alike.
	 * @return Program A new program in the current context.
	 */
	public static function program(): Program
	{
		$root = dirname(__DIR__) . '/assets/vehicles/';
		$fragment = str_replace(['// OPTICS','// NIGHT LIGHT'],
			[WaterAppearance::shaderSource(), AtmosphereRenderer::nightLightingSource()], file_get_contents($root . 'model.frag'));
		return new Program(file_get_contents($root . 'model.vert'), $fragment);
	}

	/**
	 * Bind a model program and set the uniforms that every model drawn this frame shares.
	 * @param Program $p A program from program().
	 * @param Camera $camera Viewing camera.
	 * @param Atmosphere $sky Sunlight and sky state.
	 * @param Water $water Water level and appearance, for models below the surface.
	 * @param int $width Viewport width in pixels.
	 * @param int $height Viewport height in pixels.
	 * @param float $altitude Height in meters at which to measure the sunlight's path through the atmosphere.
	 * @return void Leaves the program in use.
	 */
	public static function light(Program $p, Camera $camera, Atmosphere $sky, Water $water, int $width, int $height,
		float $altitude): void
	{
		$environment = $sky->uniforms();
		$sun = $environment['sunDirection'];
		$transmission = $sky->transmittance(max(0.0, $altitude), $sun[1], 8);
		$intensity = $environment['sunIntensity'] / M_PI;
		$p->use();
		$water->appearance->bind($p);
		glUniform2f($p->location('uEyeAndWaterHeight'), $camera->position[1], $water->level());
		glUniformMatrix4fv($p->location('uViewProjection'), 1, false, $camera->viewProjection($width, $height));
		glUniform3f($p->location('uSunDirection'), ...$sun);
		glUniform3f($p->location('uSunlight'), $transmission[0]*$intensity, $transmission[1]*$intensity, $transmission[2]*$intensity);
		glUniform1f($p->location('uDaylight'), min(1.0, max(0.0, ($sun[1] + .10)/.15)) * $environment['sunIntensity']/20);
		glUniform3f($p->location('uNightAmbient'), ...$environment['nightAmbient']);
		glUniform1f($p->location('uExposure'), $environment['exposure']);
		glUniform1i($p->location('uDashboard'), 0);
	}

	/**
	 * Read the hinges of the moving surfaces that the importer writes beside a mesh as surfaces.json.
	 * @param string $meshFile Path of the baked mesh.
	 * @return array Hinge slot (the surface tag minus FIRST_SURFACE) => pose key that drives it, a point on the hinge
	 * and its unit axis in model meters. Empty for a mesh without moving surfaces.
	 */
	public static function hinges(string $meshFile): array
	{
		$file = dirname($meshFile) . '/surfaces.json';
		if(!is_file($file)) return [];
		$hinges = [];

		foreach(json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR)['surfaces'] as $surface)
		{
			$slot = $surface['tag'] - self::FIRST_SURFACE;
			[$x, $y, $z] = $surface['axis'];
			$length = sqrt($x*$x + $y*$y + $z*$z);
			if($slot < 0 || $slot >= self::SURFACES || isset($hinges[$slot]) || !$length)
				throw new \RuntimeException('Invalid vehicle surface');
			$hinges[$slot] = ['control' => $surface['control'], 'point' => array_map('floatval', $surface['point']),
				'axis' => [$x / $length, $y / $length, $z / $length]];
		}

		return $hinges;
	}

	/**
	 * Read the pop-up headlights and the headlamps that the importer writes beside a car's mesh as lamps.json.
	 * @param string $meshFile Path of the baked mesh.
	 * @return array|null 'pods': the hinge's 'point' in model meters, its 'axis', which is +X, and the 'angle' in
	 * radians that opens the pods; 'headlamps': one beam per pod with the pods open, each a 'position' in model
	 * meters and a unit 'direction'. Null for a mesh without lamps.
	 */
	public static function lamps(string $meshFile): ?array
	{
		$file = dirname($meshFile) . '/lamps.json';
		if(!is_file($file)) return null;
		$lamps = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
		$vector = static fn(mixed $v): bool => is_array($v) && array_is_list($v) && count($v) === 3
			&& count(array_filter($v, static fn($n): bool => is_int($n) || is_float($n))) === 3;
		$pods = $lamps['pods'] ?? null;
		if(!$vector($pods['point'] ?? null) || ($pods['axis'] ?? null) !== [1, 0, 0]
			|| !is_float($pods['angle'] ?? null) || !is_array($lamps['headlamps'] ?? null))
			throw new \RuntimeException('Invalid vehicle lamps');
		foreach($lamps['headlamps'] as $beam)
			if(!$vector($beam['position'] ?? null) || !$vector($beam['direction'] ?? null))
				throw new \RuntimeException('Invalid vehicle headlamp');
		return $lamps;
	}

	/**
	 * Work out the light each lamp gives off for model.vert's uLampGlow.
	 * @param array $lamps Levels from Session::carLamps(): 'brake', 'reverse' and 'headlamps', from 0 to 1.
	 * @return list<float> Linear RGB radiance for each lamp tag from FIRST_LAMP, flattened.
	 */
	public static function lampGlow(array $lamps): array
	{
		$light = static fn(array $color, float $radiance): array => array_map(
			static fn(float $channel): float => $channel * $radiance, $color);
		$headlamps = $light(self::WHITE, self::HEADLAMP * $lamps['headlamps']);
		return array_merge($light(self::RED, max(self::BRAKE * $lamps['brake'], self::TAIL * $lamps['headlamps'])),
			$light(self::RED, self::BRAKE * $lamps['brake']), $light(self::WHITE, self::REVERSE * $lamps['reverse']),
			$headlamps, $headlamps);
	}

	/**
	 * Find where a car's headlamps shine from and toward, for lighting the ground and water ahead.
	 * @param array $lamps The car's pods and headlamps from lamps().
	 * @param array $pose Pose as draw() takes it, with the levels from Session::carLamps() under 'lamps'.
	 * @return list<array> One beam per pod: its 'position' and unit 'direction' in world meters, and its 'level' from
	 * 0 to 1, the headlamps' brightness scaled by how much of the beam clears the nose as the pods rise (BEAM_CLEAR).
	 * The beams swing with the pods and turn with the body, so a car on its side or roof shines them wherever its nose
	 * points.
	 */
	public static function beams(array $lamps, array $pose): array
	{
		$basis = self::basis($pose);
		// The headlamps are listed with the pods open. Turn them back about +X to the pods' current angle.
		$angle = $lamps['pods']['angle'] * ($pose['lamps']['pods'] - 1);
		$c = cos($angle); $s = sin($angle);
		[, $hingeY, $hingeZ] = $lamps['pods']['point'];

		// The nose hides the headlamps until the pods lift them clear, so no light reaches the ground through the body.
		[$hidden, $clear] = self::BEAM_CLEAR;
		$rise = max(0.0, min(1.0, ($pose['lamps']['pods'] - $hidden) / ($clear - $hidden)));
		$level = $pose['lamps']['headlamps'] * $rise * $rise * (3 - 2 * $rise);

		$world = static fn(array $v): array => [$basis[0]*$v[0] + $basis[3]*$v[1] + $basis[6]*$v[2],
			$basis[1]*$v[0] + $basis[4]*$v[1] + $basis[7]*$v[2], $basis[2]*$v[0] + $basis[5]*$v[1] + $basis[8]*$v[2]];

		$beams = [];
		foreach($lamps['headlamps'] as ['position' => [$x, $y, $z], 'direction' => [$dx, $dy, $dz]])
		{
			$y -= $hingeY; $z -= $hingeZ;
			$at = $world([$x, $hingeY + $y*$c - $z*$s, $hingeZ + $y*$s + $z*$c]);
			$beams[] = ['position' => [$pose['x'] + $at[0], $pose['y'] + $at[1], $pose['z'] + $at[2]],
				'direction' => $world([$dx, $dy*$c - $dz*$s, $dy*$s + $dz*$c]),
				'level' => $level];
		}

		return $beams;
	}

	/**
	 * Build the spot lights for Scene::setSpotLights() from a car's headlamps.
	 * @param array $lamps The car's pods and headlamps from lamps().
	 * @param array $pose Pose as draw() takes it, with the levels from Session::carLamps() under 'lamps'.
	 * @param float $night Atmosphere::uniforms()['nightVisibility'], zero while the sun is at or above the horizon.
	 * The beams make no visible difference in sunlight, so a sky without night gets no spot lights.
	 * @return list<array> One light per beam from beams(), its intensity scaled by the beam's level. Empty by day,
	 * while the headlamps are dark, or while the nose still hides them.
	 */
	public static function spotLights(array $lamps, array $pose, float $night): array
	{
		if($night <= 0) return [];
		$beams = self::beams($lamps, $pose);
		if(!$beams || $beams[0]['level'] <= 0) return [];

		$lights = [];
		foreach($beams as $beam)
			$lights[] = ['position' => $beam['position'], 'direction' => $beam['direction'], 'color' => self::WHITE,
				'intensity' => self::BEAM_INTENSITY * $beam['level'], 'range' => self::BEAM_RANGE,
				'inner' => self::BEAM_INNER, 'outer' => self::BEAM_OUTER];
		return $lights;
	}

	/**
	 * Turn a vehicle pose into its rotation.
	 * @param array $pose 'yaw', 'pitch' and 'roll' in radians. Positive pitch raises the nose and positive roll
	 * lowers local +X.
	 * @return list<float> Column-major 3x3 basis: the world directions of local +X, +Y and +Z.
	 */
	public static function basis(array $pose): array
	{
		$cy = cos($pose['yaw']);   $sy = sin($pose['yaw']);
		$cp = cos($pose['pitch']); $sp = sin($pose['pitch']);
		$cr = cos($pose['roll']);  $sr = sin($pose['roll']);
		return [
			$cy*$cr + $sy*$sp*$sr, -$cp*$sr, -$sy*$cr + $cy*$sp*$sr,
			$cy*$sr - $sy*$sp*$cr,  $cp*$cr, -$sy*$sr - $cy*$sp*$cr,
			$sy*$cp,                 $sp,      $cy*$cp
		];
	}

	/**
	 * Render the current vehicle pose in meters, with local +Z pointing forward.
	 * @param array $pose Position, orientation and moving parts. A car with lamps also reads the levels from
	 * Session::carLamps() under 'lamps'; without them its lamps keep the last levels drawn, dark at first.
	 */
	public function draw(array $pose, Camera $camera, Atmosphere $sky, Water $water, int $width, int $height): void
	{
		$basis = self::basis($pose);
		$p = $this->program;
		self::light($p, $camera, $sky, $water, $width, $height, $pose['y']);
		if(isset($pose['power']) && $this->controls)
			glUniform1f($p->location('uEngineThrust'), Jet::thrustLevel($pose['power']));
		if(isset($pose['wheelSpin']))
		{
			glUniform4f($p->location('uWheelTravel'), ...$pose['wheelTravel']);
			glUniform3f($p->location('uWheelAngles'), $pose['wheelSpin'], ...Car::frontWheelAngles($pose['steering']));
			glUniform4f($p->location('uWheelLayout'), Car::FRONT_TRACK / 2, Car::REAR_TRACK / 2, Car::WHEELBASE / 2, Car::TIRE_RADIUS);
		}
		if($this->controls)
		{
			$angles = array_fill(0, self::SURFACES, 0.0);
			foreach($this->controls as $slot => $control) $angles[$slot] = (float)($pose[$control] ?? 0.0);
			glUniform3fv($p->location('uHingePoint[0]'), self::SURFACES, $this->hingePoints);
			glUniform3fv($p->location('uHingeAxis[0]'), self::SURFACES, $this->hingeAxes);
			glUniform1fv($p->location('uHingeAngle[0]'), self::SURFACES, $angles);
		}
		if($this->lamps !== null && isset($pose['lamps']))
		{
			glUniform3fv($p->location('uLampGlow[0]'), self::LAMPS, self::lampGlow($pose['lamps']));
			glUniform2f($p->location('uPodHinge'), $this->lamps['pods']['point'][1], $this->lamps['pods']['point'][2]);
			glUniform1f($p->location('uPodAngle'), $this->lamps['pods']['angle'] * $pose['lamps']['pods']);
		}
		glUniformMatrix3fv($p->location('uRotation'), 1, false, $basis);
		glUniform3f($p->location('uOrigin'), $pose['x'] - $camera->position[0], $pose['y'] - $camera->position[1],
			$pose['z'] - $camera->position[2]);
		$this->mesh->bind();
		$this->mesh->draw();
		glBindVertexArray(0);
	}

	/** Release the context-local handles owned by the vehicle. */
	public function close(): void
	{
		$this->mesh->close();
		$this->program->close();
	}
}
