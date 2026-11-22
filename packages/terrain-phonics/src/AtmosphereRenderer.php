<?php
declare(strict_types=1);
namespace TerrainPhonics;

use Phonics\Render\Program;
use Phonics\Render\RenderTarget;

/**
 * Optional GPU sky-view cache for the spherical single-scattering atmosphere.
 *
 * Two latitude/longitude maps retain linear HDR scattering at the eye and water altitudes. Logarithmic RGBA8
 * storage needs no float render-target extension. The solar disk is evaluated separately at display resolution.
 * Two additional RGBA8 atlases cache directional aerial perspective at sixteen distances up to 90 km. The
 * angular and distance interpolation approximate the same single-scattering model; no multiple scattering is added.
 */
final class AtmosphereRenderer
{
	/** Program and targets are retained across frames and rebuilt after context loss. */
	private ?Program $program = null, $aerialProgram = null;
	private array $targets = [], $vao = [];
	/** Each altitude has an independent cache key and completed-render count. */
	private array $keys = ['', ''], $altitudes = [0.0,0.0], $generations = [0,0];
	/** Aerial perspective depends on eye altitude, but not the water surface's altitude. */
	private string $aerialKey = '';
	private float $aerialAltitude = 0;
	private int $aerialGeneration = 0;
	/** Azimuth, latitude and logarithmically distributed distance samples. */
	private const AERIAL_GRID = [32,16,16];
	private const AERIAL_DISTANCE = 90000.0;
	private bool $closed = false;

	/**
	 * Allocate RGBA8 sky and aerial targets with their integration programs in the current GL context.
	 * @param Atmosphere $atmosphere Physical model shared with the scene's transmittance texture.
	 * @param int $width Longitude samples, between 16 and 1024.
	 * @param int $height Latitude samples, between eight and 512.
	 * @return void Allocates GPU objects; update() populates them before the first cached draw.
	 */
	public function __construct(private Atmosphere $atmosphere, private int $width = 256, private int $height = 128)
	{
		if($width < 16 || $width > 1024 || $height < 8 || $height > 512)
		throw new \InvalidArgumentException('Unsupported sky cache dimensions');

		$root = dirname(__DIR__) . '/shaders/';

		try
		{
			$this->program = new Program(
				file_get_contents($root . 'atmosphere-cache.vert')
				, str_replace(
					'// ATMOSPHERE'
					, file_get_contents($root . 'atmosphere.glsl')
					, file_get_contents($root . 'atmosphere-cache.frag')
				)
			);

			glGenVertexArrays(1, $this->vao);
			$this->targets[] = new RenderTarget($width, $height, GL_NEAREST, false, 'Eye sky cache');
			$this->targets[] = new RenderTarget($width, $height, GL_NEAREST, false, 'Water sky cache');

			$this->aerialProgram = new Program(
				file_get_contents($root . 'atmosphere-cache.vert')
				, str_replace(
					'// ATMOSPHERE'
					, file_get_contents($root . 'atmosphere.glsl')
					, file_get_contents($root . 'atmosphere-aerial.frag')
				)
			);

			[$azimuths,$latitudes,$distances] = self::AERIAL_GRID;

			$this->targets[] = new RenderTarget(
				$azimuths * $distances
				, $latitudes
				, GL_NEAREST
				, false
				, 'Aerial extinction'
			);

			$this->targets[] = new RenderTarget(
				$azimuths * $distances
				, $latitudes
				, GL_NEAREST
				, false
				, 'Aerial radiance'
			);
		}
		catch(\Throwable $error)
		{
			$this->close();
			throw $error;
		}
	}

	/**
	 * Compose the original atmosphere helpers with cached skyRadiance() and terrainAtmosphere() replacements.
	 * @return string Shader include without a version directive; bind() supplies its cache uniforms.
	 * Unbound caches and aerial requests outside the cached distance or altitude use the original integrator.
	 */
	public static function shaderSource(): string
	{
		$root = dirname(__DIR__) . '/shaders/';

		return "#define skyRadiance terrainUncachedSkyRadiance\n#define terrainAtmosphere terrainUncachedAtmosphere\n"
			. file_get_contents($root . 'atmosphere.glsl') . "\n#undef skyRadiance\n#undef terrainAtmosphere\n"
			. self::nightLightingSource() . "\n" . file_get_contents($root . 'atmosphere-cache.glsl') . "\n" . file_get_contents(
				$root . 'atmosphere-aerial.glsl'
			);
	}

	/** Shared blue fill for opaque scene materials, evaluated before exposure and tone mapping. */
	public static function nightLightingSource(): string
	{
		return file_get_contents(dirname(__DIR__) . '/shaders/night-light.glsl');
	}

	/**
	 * Recompute only maps whose physical atmosphere or rounded half-meter altitude changed.
	 * @param float $eyeAltitude Eye height above the model's sea-level radius, in meters.
	 * @param int $transmittanceTexture Uploaded raw RGBA8 texture from this Atmosphere's transmittanceLut().
	 * @param float $waterAltitude Mean water height, used for reflected sky lighting.
	 * @return bool True when at least one map was rendered. Exposure, position and rotation do not invalidate it.
	 * The caller's framebuffer is restored. After a redraw the caller must reset its viewport, program, VAO,
	 * texture bindings, depth mask, and depth/cull/blend/scissor enables before drawing the scene.
	 */
	public function update(float $eyeAltitude, int $transmittanceTexture, float $waterAltitude = 0): bool
	{
		if($this->closed) throw new \LogicException('Sky cache is closed');

		if(!is_finite($eyeAltitude) || !is_finite($waterAltitude) || $transmittanceTexture <= 0)
		throw new \InvalidArgumentException('Sky cache requires finite altitudes and a transmittance texture');

		$parameters = $this->atmosphere->uniforms();

		if($parameters['transmittanceSize'][0] < 4 || $parameters['transmittanceSize'][1] < 2)
		throw new \LogicException('Build and upload the atmosphere transmittance LUT before updating the sky cache');

		$physical = $parameters;
		unset($physical['exposure'], $physical['angularRadius'], $physical['nightGlow'],
			$physical['nightAmbient'], $physical['nightVisibility'], $physical['skyAmbient'], $physical['skyLight']);
		$signature = json_encode($physical, JSON_THROW_ON_ERROR);
		$changed = false;

		foreach([$eyeAltitude,$waterAltitude] as $slot => $altitude)
		{
			$altitude = min($parameters['atmosphereHeight'], round(max(0, $altitude) * 2) / 2);
			$key = $signature . ':' . $altitude;

			if($this->keys[$slot] === $key) continue;

			if(!$changed)
			{
				$this->prepare($this->program, $parameters, $transmittanceTexture);
				glUniform2f($this->program->location('uSkyCacheSize'), $this->width, $this->height);
			}

			$this->targets[$slot]->bind();

			try
			{
				glUniform1f($this->program->location('uSkyCacheAltitude'), $altitude);
				glDrawArrays(GL_TRIANGLES, 0, 3);
			}
			finally
			{
				$this->targets[$slot]->unbind();
			}

			$this->keys[$slot] = $key;
			$this->altitudes[$slot] = $altitude;
			$this->generations[$slot]++;
			$changed = true;
		}

		$aerialKey = $signature . ':' . $this->altitudes[0];

		if($this->aerialKey !== $aerialKey)
		{
			$this->aerialKey = '';
			$this->prepare($this->aerialProgram, $parameters, $transmittanceTexture);
			glUniform3f($this->aerialProgram->location('uAerialCacheGrid'), ...self::AERIAL_GRID);
			glUniform1f($this->aerialProgram->location('uAerialCacheMaxDistance'), self::AERIAL_DISTANCE);
			glUniform1f($this->aerialProgram->location('uAerialCacheAltitude'), $this->altitudes[0]);

			$this->targets[2]->bind();
			try
			{
				$second = GL_COLOR_ATTACHMENT0 + 1;
				glFramebufferTexture2D(GL_FRAMEBUFFER, $second, GL_TEXTURE_2D, $this->targets[3]->texture(), 0);
				glDrawBuffers(2, [GL_COLOR_ATTACHMENT0,$second]);
				glDrawArrays(GL_TRIANGLES, 0, 3);
			}
			finally
			{
				$this->targets[2]->unbind();
			}

			$this->aerialKey = $aerialKey;
			$this->aerialAltitude = $this->altitudes[0];
			$this->aerialGeneration++;
			$changed = true;
		}

		return $changed;
	}

	/**
	 * Establish the shared integration state and upload physical parameters.
	 * @param Program $program Sky or aerial integration program.
	 * @param array $parameters Current Atmosphere uniform values.
	 * @param int $transmittanceTexture Scene-owned raw RGBA8 transmittance texture.
	 * @return void Leaves a full-screen pass ready apart from its target and output-specific uniforms.
	 */
	private function prepare(Program $program, array $parameters, int $transmittanceTexture): void
	{
		glDisable(GL_DEPTH_TEST);
		glDisable(GL_CULL_FACE);
		glDisable(GL_BLEND);
		glDisable(GL_SCISSOR_TEST);
		glDepthMask(false);
		$program->use();
		glBindVertexArray($this->vao[0]);
		glActiveTexture(GL_TEXTURE0);
		glBindTexture(GL_TEXTURE_2D, $transmittanceTexture);
		glUniform1i($program->location('uTransmittance'), 0);

		foreach($parameters as $name => $value)
		{
			$location = $program->location('u' . ucfirst($name));

			if(is_array($value) && count($value) === 3) glUniform3f($location, ...$value);
			elseif(is_array($value) && count($value) === 2) glUniform2f($location, ...$value);
			elseif(is_numeric($value)) glUniform1f($location, $value);
		}
	}

	/**
	 * Bind the four caches and their sampling uniforms on an already-current scene program.
	 * @param Program $program Program containing shaderSource().
	 * @param int $firstTextureUnit First of four consecutive texture units; default leaves units zero and one free.
	 * @return void Selects cached sampling after update(), or the original integrator until both maps are ready.
	 */
	public function bind(Program $program, int $firstTextureUnit = 2): void
	{
		if($this->closed) throw new \LogicException('Sky cache is closed');

		if($firstTextureUnit < 0) throw new \InvalidArgumentException('Texture units must be nonnegative');

		foreach(['uSkyCacheEye','uSkyCacheWater','uAerialCacheTransmittance','uAerialCacheRadiance'] as $slot => $name)
		{
			glActiveTexture(GL_TEXTURE0 + $firstTextureUnit + $slot);
			glBindTexture(GL_TEXTURE_2D, $this->targets[$slot]->texture());
			glUniform1i($program->location($name), $firstTextureUnit + $slot);
		}

		glUniform2f($program->location('uSkyCacheSize'), $this->width, $this->height);
		glUniform2f($program->location('uSkyCacheAltitudes'), ...$this->altitudes);
		glUniform1i($program->location('uSkyCacheReady'), $this->keys[0] !== '' && $this->keys[1] !== '' ? 1 : 0);
		glUniform3f($program->location('uAerialCacheGrid'), ...self::AERIAL_GRID);
		glUniform1f($program->location('uAerialCacheMaxDistance'), self::AERIAL_DISTANCE);
		glUniform1f($program->location('uAerialCacheAltitude'), $this->aerialAltitude);
		glUniform1i($program->location('uAerialCacheReady'), $this->aerialKey !== '' ? 1 : 0);
	}

	/**
	 * Read cache diagnostics without GPU readback.
	 * @return array Dimensions, cached altitudes, per-map redraw generations and readiness.
	 */
	public function stats(): array
	{
		return [
			'width' => $this->width
			, 'height' => $this->height
			, 'altitudes' => $this->altitudes
			, 'generations' => $this->generations
			, 'ready' => !$this->closed && $this->keys[0] !== '' && $this->keys[1] !== ''
			, 'aerialGrid' => self::AERIAL_GRID
			, 'aerialGeneration' => $this->aerialGeneration
			, 'aerialReady' => !$this->closed && $this->aerialKey !== ''
		];
	}

	/**
		 * Rebuild GPU resources after context restoration; the next update regenerates all four maps.
	 * @return void Does nothing after close().
	 */
	public function restore(): void
	{
		if($this->closed) return;

		$this->keys = ['', ''];
		$this->aerialKey = '';
		$this->program->restore();
		$this->aerialProgram->restore();

		foreach($this->targets as $target) $target->restore();

		if($this->vao) glDeleteVertexArrays(1, $this->vao);

		glGenVertexArrays(1, $this->vao);
	}

	/**
	 * Release all texture targets, integration programs and the shared vertex array.
	 * @return void Repeated closure is harmless.
	 */
	public function close(): void
	{
		if($this->closed) return;

		$this->closed = true;

		foreach($this->targets as $target) $target->close();
		$this->program?->close();
		$this->aerialProgram?->close();

		if($this->vao) glDeleteVertexArrays(1, $this->vao);

		$this->vao = [];
		$this->keys = ['', ''];
		$this->aerialKey = '';
	}
}
