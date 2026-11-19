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
	private Program $program;
	private BakedMesh $mesh;
	/** Pose key driving each hinge slot, and every slot's hinge point and unit axis, flattened for the shader. */
	private array $controls = [], $hingePoints = [], $hingeAxes = [];

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
	 * @param float $altitude Height in metres at which to measure the sunlight's path through the atmosphere.
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
	 * and its unit axis in model metres. Empty for a mesh without moving surfaces.
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

	/** Render the current vehicle pose in metres, with local +Z pointing forward. */
	public function draw(array $pose, Camera $camera, Atmosphere $sky, Water $water, int $width, int $height): void
	{
		$cy = cos($pose['yaw']);   $sy = sin($pose['yaw']);
		$cp = cos($pose['pitch']); $sp = sin($pose['pitch']);
		$cr = cos($pose['roll']);  $sr = sin($pose['roll']);
		// Column-major basis. Positive pitch raises the nose and positive roll lowers local +X.
		$basis = [
			$cy*$cr + $sy*$sp*$sr, -$cp*$sr, -$sy*$cr + $cy*$sp*$sr,
			$cy*$sr - $sy*$sp*$cr,  $cp*$cr, -$sy*$sr - $cy*$sp*$cr,
			$sy*$cp,                 $sp,      $cy*$cp
		];
		$p = $this->program;
		self::light($p, $camera, $sky, $water, $width, $height, $pose['y']);
		if(isset($pose['wheelSpin']))
		{
			glUniform4f($p->location('uWheelTravel'), ...$pose['wheelTravel']);
			glUniform2f($p->location('uWheelAngles'), $pose['wheelSpin'], $pose['steering']);
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
