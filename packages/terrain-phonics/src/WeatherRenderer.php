<?php
declare(strict_types=1);
namespace TerrainPhonics;

use Phonics\Render\Program;

/** One cloud sheet and one bounded particle draw. Both read simulation time and camera-relative coordinates. */
final class WeatherRenderer
{
	/** Raindrops and snowflakes drawn around the eye at full intensity. */
	private const DROPS = 98304, FLAKES = 36864;

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
	/** Where the eye last was, when, and its velocity from there, which slants the rain. */
	private array $eye = [], $drift = [0., 0., 0.];
	private float $seen = 0.;
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
		glUniform1f($p->location('uRoadSnow'), $this->controls['roadSnow'] / 100.);

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
			glUniform2f($p->location('uView'), 2 * tan($camera->fov / 2) / max(1, $height), $height);
			$this->follow($camera->position);
			glUniform3f($p->location('uDrift'), ...$this->drift);

			$each = $c['kind'] === 'snow' ? self::FLAKES : self::DROPS;
			glDrawArraysInstanced(GL_TRIANGLE_STRIP, 0, 4, (int)ceil($each * $c['intensity'] / 100));
		}

		glDepthMask(true);
	}

	/**
	 * Follow the eye from one frame to the next to learn its velocity.
	 *
	 * A jump too far for one frame, or a step in time that is backward or long, is a cut and not a motion: the
	 * velocity starts again from rest. While time stands still the last velocity is kept.
	 *
	 * @param array $eye Eye position in meters.
	 * @return void No value.
	 */
	private function follow(array $eye): void
	{
		$elapsed = $this->time - $this->seen;

		if($elapsed === 0.) return;

		if($this->eye === [] || $elapsed < 0 || $elapsed > .25) $this->drift = [0., 0., 0.];
		else
		{
			$step = [$eye[0] - $this->eye[0], $eye[1] - $this->eye[1], $eye[2] - $this->eye[2]];
			$speed = sqrt($step[0] ** 2 + $step[1] ** 2 + $step[2] ** 2) / $elapsed;

			// The eye's motion is smoothed over a few frames, so a camera that shakes does not shake the rain.
			foreach($step as $axis => $moved)
			$this->drift[$axis] = $speed > 200.
				? 0.
				: $this->drift[$axis] + .25 * ($moved / $elapsed - $this->drift[$axis]);
		}

		$this->eye = [$eye[0], $eye[1], $eye[2]];
		$this->seen = $this->time;
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
