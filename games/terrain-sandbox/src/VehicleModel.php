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
	private const STRIDE = 28;
	/** Surface tags from 250 up turn about the hinges in the uniform arrays of model.vert, which hold this many. */
	public const FIRST_SURFACE = 250, SURFACES = 5;
	private Program $program;
	private array $vao = [], $buffers = [], $texture = [];
	private int $indexCount;
	/** Pose key driving each hinge slot, and every slot's hinge point and unit axis, flattened for the shader. */
	private array $controls = [], $hingePoints = [], $hingeAxes = [];

	/** Upload the baked model once on boarding, or again when the context is restored. */
	public function __construct(string $meshFile)
	{
		$root = dirname(__DIR__) . '/assets/vehicles/';
		$data = gzdecode(file_get_contents($meshFile), 4194304);
		if($data === false || strlen($data) < 16) throw new \RuntimeException('Cannot read vehicle mesh');
		$header = unpack('Vvertices/Vindices/Vwidth/Vheight', $data);
		$vertexBytes = $header['vertices'] * self::STRIDE;
		$this->indexCount = $header['indices'];
		$indexBytes = $this->indexCount * 2;
		$pixelOffset = 16 + $vertexBytes + $indexBytes;
		if($header['vertices'] > 65536 || !$this->indexCount || $this->indexCount % 3
			|| strlen($data) !== $pixelOffset + $header['width'] * $header['height'] * 4)
			throw new \RuntimeException('Invalid vehicle mesh dimensions');
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

		$fragment = str_replace(['// OPTICS','// NIGHT LIGHT'],
			[WaterAppearance::shaderSource(), AtmosphereRenderer::nightLightingSource()], file_get_contents($root . 'model.frag'));
		$this->program = new Program(file_get_contents($root . 'model.vert'), $fragment);
		try
		{
			glGenVertexArrays(1, $this->vao);
			glBindVertexArray($this->vao[0]);
			glGenBuffers(2, $this->buffers);
			glBindBuffer(GL_ARRAY_BUFFER, $this->buffers[0]);
			glBufferData(GL_ARRAY_BUFFER, $vertexBytes, substr($data, 16, $vertexBytes), GL_STATIC_DRAW);
			glBindBuffer(GL_ELEMENT_ARRAY_BUFFER, $this->buffers[1]);
			glBufferData(GL_ELEMENT_ARRAY_BUFFER, $indexBytes, substr($data, 16 + $vertexBytes, $indexBytes), GL_STATIC_DRAW);
			foreach([
				[3, GL_FLOAT, false, 0], [3, GL_SHORT, true, 12], [2, GL_UNSIGNED_SHORT, true, 18],
				[4, GL_UNSIGNED_BYTE, true, 22], [2, GL_UNSIGNED_BYTE, true, 26]
			] as $location => [$size, $type, $normalized, $offset])
			{
				glEnableVertexAttribArray($location);
				glVertexAttribPointer($location, $size, $type, $normalized, self::STRIDE, $offset);
			}
			glBindVertexArray(0);

			glGenTextures(1, $this->texture);
			glActiveTexture(GL_TEXTURE0);
			glBindTexture(GL_TEXTURE_2D, $this->texture[0]);
			glTexImage2D(GL_TEXTURE_2D, 0, GL_RGBA8, $header['width'], $header['height'], 0,
				GL_RGBA, GL_UNSIGNED_BYTE, substr($data, $pixelOffset));
			glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MIN_FILTER, GL_LINEAR_MIPMAP_LINEAR);
			glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MAG_FILTER, GL_LINEAR);
			glGenerateMipmap(GL_TEXTURE_2D);
		}
		catch(\Throwable $error)
		{
			$this->close();
			throw $error;
		}
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
		$environment = $sky->uniforms();
		$sun = $environment['sunDirection'];
		$transmission = $sky->transmittance(max(0.0, $pose['y']), $sun[1], 8);
		$intensity = $environment['sunIntensity'] / M_PI;
		$p = $this->program;
		$p->use();
		$water->appearance->bind($p);
		glUniform2f($p->location('uEyeAndWaterHeight'), $camera->position[1], $water->level());
		glUniformMatrix4fv($p->location('uViewProjection'), 1, false, $camera->viewProjection($width, $height));
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
		glUniform3f($p->location('uSunDirection'), ...$sun);
		glUniform3f($p->location('uSunlight'), $transmission[0]*$intensity, $transmission[1]*$intensity, $transmission[2]*$intensity);
		glUniform1f($p->location('uDaylight'), min(1.0, max(0.0, ($sun[1] + .10)/.15)) * $environment['sunIntensity']/20);
		glUniform3f($p->location('uNightAmbient'), ...$environment['nightAmbient']);
		glUniform1f($p->location('uExposure'), $environment['exposure']);
		glActiveTexture(GL_TEXTURE0);
		glBindTexture(GL_TEXTURE_2D, $this->texture[0]);
		glUniform1i($p->location('uDashboard'), 0);
		glBindVertexArray($this->vao[0]);
		glDrawElements(GL_TRIANGLES, $this->indexCount, GL_UNSIGNED_SHORT, 0);
		glBindVertexArray(0);
	}

	/** Release the context-local handles owned by the craft. */
	public function close(): void
	{
		if($this->vao) glDeleteVertexArrays(1, $this->vao);
		if($this->buffers) glDeleteBuffers(count($this->buffers), $this->buffers);
		if($this->texture) glDeleteTextures(1, $this->texture);
		$this->vao = $this->buffers = $this->texture = [];
		$this->program->close();
	}
}
