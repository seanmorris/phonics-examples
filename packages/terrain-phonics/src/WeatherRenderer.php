<?php
declare(strict_types=1);
namespace TerrainPhonics;

use Phonics\Render\Program;

/** One cloud sheet and one bounded particle draw. Both read simulation time and camera-relative coordinates. */
final class WeatherRenderer
{
	private array $texture = [];
	private \WeakMap $enabled;
	private bool $active = false;

	/**
	 * Initialize the per-program weather binding cache.
	 *
	 * @return void No value.
	 */
	public function __construct() { $this->enabled = new \WeakMap; }

	private ?Program $clouds = null, $particles = null;
	private array $deck = [], $offset = [], $ground = [], $controls = [];
	private float $time = 0., $sea = 0.;

	/**
	 * Disable weather drawing for the current view.
	 *
	 * @return void No value.
	 */
	public function clear(): void { $this->active = false; }

	/**
	 * Sample the cloud deck, wind and surface conditions for this frame.
	 *
	 * @param Weather $weather Weather controls and accumulated surface state.
	 * @param float $time Simulation time in seconds.
	 * @param float $sea Water surface elevation in meters.
	 * @param float $sunY Vertical component of the normalized sun direction.
	 * @return void No value.
	 */
	public function prepare(Weather $weather, float $time, float $sea, float $sunY): void
	{
		$this->controls = $c = $weather->controls();
		$this->time = $time;
		$this->sea = $sea;
		$this->ground = $weather->ground($time);
		$this->active = $c['coverage'] > 0 || ($c['kind'] !== 'none' && $c['intensity'] > 0) || max($this->ground) > 0.;

		if(!$this->active) return;

		$this->offset = $weather->offset($time);
		$this->deck = [
			$c['coverage'] * .01,$sea + $c['altitude'] * .3048,$c['shadow'] * .01
			, min(1., max(0., ($sunY + .12) / .34))
		];

		if(!$this->texture && $c['coverage'] > 0)
		{
			glGenTextures(1, $this->texture);
			glActiveTexture(GL_TEXTURE0 + 15);
			glBindTexture(GL_TEXTURE_2D, $this->texture[0]);

			glTexImage2D(
				GL_TEXTURE_2D
				, 0
				, GL_R8
				, 128
				, 128
				, 0
				, GL_RED
				, GL_UNSIGNED_BYTE
				, file_get_contents(dirname(__DIR__) . '/textures/cloud-noise.r8')
			);

			glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MIN_FILTER, GL_LINEAR_MIPMAP_LINEAR);
			glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MAG_FILTER, GL_LINEAR);
			glGenerateMipmap(GL_TEXTURE_2D);
			glActiveTexture(GL_TEXTURE0);
		}
	}

	/**
	 * Upload active weather uniforms to a landscape shader.
	 *
	 * @param Program $p Active GPU program receiving terrain uniforms.
	 * @return void No value.
	 */
	public function bind(Program $p): void
	{
		if(!$this->active)
		{
			// New programs start with zero uniforms. Clear only a program that last drew active weather.
			if(isset($this->enabled[$p]))
			{
				glUniform4f($p->location('uDeck'), 0., 0., 0., 0.);
				glUniform2f($p->location('uGroundCover'), 0., 0.);
				unset($this->enabled[$p]);
			}

			return;
		}

		$this->enabled[$p] = true;
		glUniform4f($p->location('uDeck'), ...$this->deck);
		glUniform2f($p->location('uGroundCover'), ...$this->ground);

		if($this->deck[0] <= 0.) return;

		glUniform2f($p->location('uWeatherOffset'), ...$this->offset);
		glActiveTexture(GL_TEXTURE0 + 15);
		glBindTexture(GL_TEXTURE_2D, $this->texture[0]);
		glUniform1i($p->location('uCloudNoise'), 15);
		glActiveTexture(GL_TEXTURE0);
	}

	/**
	 * Draw the cloud deck and camera-relative precipitation.
	 *
	 * @param Camera $camera Camera defining the viewpoint and projection.
	 * @param int $width Render target width in pixels.
	 * @param int $height Render target height in pixels.
	 * @param float $exposure Scene exposure for the rendered weather.
	 * @return void No value.
	 */
	public function draw(Camera $camera, int $width, int $height, float $exposure): void
	{
		if(!$this->active) return;

		$c = $this->controls;
		$particles = $c['kind'] !== 'none' && $c['intensity'] > 0 && $camera->position[1] < $this->deck[1] + 16.;

		if($c['coverage'] == 0 && !$particles) return;

		$root = dirname(__DIR__) . '/shaders/';
		$matrix = $camera->viewProjection($width, $height);
		glDepthMask(false);

		if($c['coverage'] > 0)
		{
			$p = $this->clouds ??= new Program(
				file_get_contents($root . 'clouds.vert')
				, str_replace(
					'// WEATHER'
					, file_get_contents($root . 'weather.glsl')
					, file_get_contents($root . 'clouds.frag')
				)
			);

			$p->use();
			$this->bind($p);
			glUniformMatrix4fv($p->location('uViewProjection'), 1, false, $matrix);
			glUniform3f($p->location('uEye'), ...$camera->position);
			glUniform1f($p->location('uExposure'), $exposure);
			glDrawArrays(GL_TRIANGLE_STRIP, 0, 4);
		}

		if($particles)
		{
			$p = $this->particles ??= new Program(
				file_get_contents($root . 'precipitation.vert')
				, file_get_contents($root . 'precipitation.frag')
			);

			$p->use();
			[, $right,$up] = $camera->basis();
			glUniformMatrix4fv($p->location('uViewProjection'), 1, false, $matrix);
			glUniform3f($p->location('uEye'), ...$camera->position);
			glUniform3f($p->location('uRight'), ...$right);
			glUniform3f($p->location('uUp'), ...$up);
			glUniform2f($p->location('uWeatherOffset'), ...$this->offset);
			glUniform2f($p->location('uFall'), fmod($this->time, 4096.), (float)($c['kind'] === 'snow'));
			$angle = deg2rad($c['heading']);
			$speed = $c['wind'] * .44704;
			glUniform2f($p->location('uWind'), $speed * sin($angle), -$speed * cos($angle));
			glUniform2f($p->location('uHeightBand'), $this->sea, $this->deck[1]);
			glUniform1f($p->location('uDay'), $this->deck[3]);
			glDrawArraysInstanced(GL_TRIANGLE_STRIP, 0, 4, (int)ceil(15.36 * $c['intensity']));
		}

		glDepthMask(true);
	}

	/**
	 * Release the weather shaders, geometry and cloud-noise texture.
	 *
	 * @return void No value.
	 */
	public function close(): void
	{
		$this->clouds?->close();
		$this->particles?->close();

		if($this->texture) glDeleteTextures(1, $this->texture);
	}
}
