<?php
declare(strict_types=1);
namespace TerrainPhonics;

use Phonics\Render\Program;

/**
 * One cloud sheet, one bounded particle draw, and lightning. All read simulation time and camera-relative
 * coordinates.
 */
final class WeatherRenderer
{
	/** Raindrops and snowflakes drawn around the eye at full intensity. */
	private const DROPS = 98304, FLAKES = 36864;
	/** Straight pieces in one bolt: 128 in the main channel, ten forks of 32 and fourteen twigs of 16. */
	private const PIECES = 672;
	/** Strikes drawn at once. */
	private const SHOWN = 4;

	private array $texture = [];
	private \WeakMap $enabled;
	private bool $active = false;

	/**
	 * Initialize the per-program weather binding cache.
	 *
	 * @return void No value.
	 */
	public function __construct()
	{
		$this->enabled = new \WeakMap;
		$this->lighted = new \WeakMap;
	}

	private ?Program $clouds = null, $particles = null, $bolt = null, $shade = null;
	/**
	 * Strikes lit this frame, nearest first: top, landing point, seed, light, distance from the eye, name, and how
	 * far the bolt is in front of the eye, from 0 behind or beside it to 1 in view.
	 */
	private array $strikes = [];
	/** Strikes begun in the last two slots, the controls and square they were listed for, and where each landed. */
	private array $slots = [], $listed = [], $landed = [];
	private bool $flashed = false;
	/**
	 * The strike that lights the land this frame, and the one whose shadows are drawn next: each a name, where its
	 * light is and how brightly it shines. Empty when there is none.
	 */
	private array $light = [], $next = [];
	private ?FlashShadows $shadows = null;
	/** The name of the strike the shadow map holds, or nothing when it holds none that can be used. */
	private string $shadowed = '';
	private \WeakMap $lighted;
	private float $exposure = 1.;
	/**
	 * How far up the bolt the light that falls on the land sits, the distance at which it is half as bright, in
	 * meters, and how bright it makes a surface that faces it at the foot of the bolt.
	 */
	private const LAMP = .35, HALF = 1200., SHINE = 5.;
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
	public function clear(): void
	{
		$this->active = false;
		$this->strikes = $this->light = $this->next = [];
	}

	/**
	 * Sample the cloud deck, wind and surface conditions for this frame.
	 *
	 * @param Weather $weather Weather controls and accumulated surface state.
	 * @param float $time Simulation time in seconds.
	 * @param float $sea Water surface elevation in meters.
	 * @param float $sunY Vertical component of the normalized sun direction.
	 * @param ?Landscape $ground Landscape lightning lands on. Without one there is no lightning.
	 * @param array $eye Eye position in meters.
	 * @param float $exposure The scene's exposure, which a flash's light on the land is set against.
	 * @param array $forward The unit direction the eye looks in. A strike darkens the scene only when in front of it.
	 * @return void No value.
	 */
	public function prepare(
		Weather $weather
		, float $time
		, float $sea
		, float $sunY
		, ?Landscape $ground = null
		, array $eye = [0., 0., 0.]
		, float $exposure = 1.
		, array $forward = [0., 0., -1.]
	): void {
		$this->controls = $c = $weather->controls();

		$this->time = $time;
		$this->sea = $sea;
		$this->ground = $weather->ground($time);
		$this->active = $c['coverage'] > 0 || ($c['kind'] !== 'none' && $c['intensity'] > 0) || max($this->ground) > 0.;
		$this->strikes = $this->light = $this->next = [];
		$this->exposure = $exposure;

		if(!$this->active) return;

		$this->offset = $weather->offset($time);
		$this->deck = [
			$c['coverage'] * .01,$sea + $c['altitude'] * .3048,$c['shadow'] * .01
			, min(1., max(0., ($sunY + .12) / .34))
		];

		if($ground !== null && $c['coverage'] > 0 && ($c['lightning'] > 0 || $weather->called()))
		$this->storm($weather, $ground, $eye, $forward);

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
	 * Find the strikes lit this frame and the high ground each lands on.
	 *
	 * @param Weather $weather Weather controls and accumulated surface state.
	 * @param Landscape $ground Landscape the strikes land on.
	 * @param array $eye Eye position in meters.
	 * @param array $forward The unit direction the eye looks in.
	 * @return void No value.
	 */
	private function storm(Weather $weather, Landscape $ground, array $eye, array $forward): void
	{
		$slot = (int)floor($this->time / Lightning::SLOT);
		$listed = [$this->controls, floor($eye[0] / Lightning::SQUARE), floor($eye[2] / Lightning::SQUARE)];

		// The lists hold while the controls and the eye's square stay the same: 162 hashes every two seconds.
		if($listed !== $this->listed) [$this->listed, $this->slots] = [$listed, []];

		$this->slots = array_intersect_key($this->slots, [$slot - 1 => true, $slot => true]);
		$rain = $this->controls['kind'] === 'rain' ? $this->controls['intensity'] / 100. : 0.;
		$life = $this->controls['boltSeconds'];
		$landed = $soon = [];
		$ahead = false;
		$called = $weather->called();

		foreach([$slot - 1, $slot] as $n) $this->slots[$n] ??= Lightning::begun($weather, $n, $eye[0], $eye[2]);

		foreach([...$this->slots, $called ? [$called] : []] as $list)
		foreach($list as [$x, $z, $start, $seed])
		{
			$id = "$x:$z:$start";

			// A strike keeps the landing point found for it for as long as its slot is listed.
			if(isset($this->landed[$id])) $landed[$id] = $this->landed[$id];

			if($this->time - $start >= $life) continue;

			if($this->time < $start)
			{
				// A strike due within a second has its landing looked up now, one strike a frame, so the frame it
				// begins on does not pay for it. The soonest of them has its shadows drawn ahead as well.
				if($start - $this->time < 1. && !isset($landed[$id]) && !$ahead)
				{
					$landed[$id] = Lightning::ground($ground, $x, $z, $this->sea, $this->controls['boltReach']);
					$ahead = true;
				}

				if(isset($landed[$id]) && $start - $this->time < .15 && (!$soon || $start < $soon[3]))
				$soon = [$id, Lightning::top($x, $z, $landed[$id], $this->deck[1]), $landed[$id], $start];

				continue;
			}

			$land = $landed[$id] ??= Lightning::ground($ground, $x, $z, $this->sea, $this->controls['boltReach']);

			// Ground that reaches the cloud leaves no room for a bolt.
			if($this->deck[1] - $land[1] < 40.) continue;

			[$main, $forks, $down] = Lightning::glow($this->time - $start, $seed, $life);
			$far = hypot($land[0] - $eye[0], $land[2] - $eye[2]);
			// Rain between the eye and a distant strike dims it a little: by an eighth at 13 kilometers in the
			// heaviest. A bolt stays close to white however far off it is.
			$clear = .8 + .2 * exp(-$far / (60000. - 48000. * $rain));
			$top = Lightning::top($x, $z, $land, $this->deck[1]);
			// How squarely the middle of the bolt lies ahead of the eye: fully in view within 50 degrees of where
			// the eye looks, and not at all past 80 degrees.
			$to = [
				($top[0] + $land[0]) / 2. - $eye[0]
				, ($top[1] + $land[1]) / 2. - $eye[1]
				, ($top[2] + $land[2]) / 2. - $eye[2]
			];

			$away = sqrt($to[0] ** 2 + $to[1] ** 2 + $to[2] ** 2);
			$ahead = ($to[0] * $forward[0] + $to[1] * $forward[1] + $to[2] * $forward[2]) / max(1., $away);
			$ahead = min(1., max(0., ($ahead - .17) / .47));

			$this->strikes[] = [
				$top
				, $land
				, $seed
				, [$main * $clear, $forks * $clear, $down]
				, $far
				, $id
				, $ahead * $ahead * (3. - 2. * $ahead)
			];
		}

		$this->landed = $landed;
		// Nearest first, so the strikes that matter most are the ones kept.
		array_multisort(array_column($this->strikes, 4), $this->strikes);
		$this->strikes = array_slice($this->strikes, 0, self::SHOWN);

		if($this->controls['flashLight'] <= 0) return;

		// The nearest lit strike lights the land. With none lit, the next one due has its shadows drawn ahead.
		if($this->strikes)
		{
			[$top, $land, , $light, , $id] = $this->strikes[0];
			$this->light = $this->next = [$id, self::lamp($top, $land), $light[0]];
		}
		elseif($soon) $this->next = [$soon[0], self::lamp($soon[1], $soon[2]), 0.];
	}

	/**
	 * Place the light a bolt sheds on the land: a point part of the way up it.
	 *
	 * @param array $top Where the bolt leaves the cloud, as X, height and Z in meters.
	 * @param array $land Where it lands, as X, height and Z in meters.
	 * @return array The light's X, height and Z in meters.
	 */
	private static function lamp(array $top, array $land): array
	{
		return [
			$land[0] + self::LAMP * ($top[0] - $land[0])
			, $land[1] + self::LAMP * ($top[1] - $land[1])
			, $land[2] + self::LAMP * ($top[2] - $land[2])
		];
	}

	/**
	 * Draw the shadows of the strike that lights the land, or of the one about to, if they are not drawn yet.
	 *
	 * @param int $heights The scene's coarse height texture, or zero before it exists.
	 * @param float $extent Width of the square world that texture covers, in meters.
	 * @param bool $packed Whether its heights are stored in RGBA8.
	 * @param int $revision Revision of the landscape it holds.
	 * @param array $range The lowest and highest ground in the landscape, in meters.
	 * @param ?array $fine The fine grid of heights near the eye from Clipmap::fine(), if there is one.
	 * @return void The caller resets the viewport and the depth, cull, blend and scissor state afterward.
	 */
	public function bake(
		int $heights
		, float $extent
		, bool $packed
		, int $revision
		, array $range = [-32768., 32767.]
		, ?array $fine = null
	): void {
		if(!$this->next) return;

		$this->shadows ??= new FlashShadows;

		$drawn = $this->shadows->update(
			$heights
			, $extent
			, $packed
			, $revision
			, $this->next[1]
			, $this->next[0]
			, $range
			, $fine
		);

		// A map that could not be drawn, or that holds another strike, must not shadow this one.
		$this->shadowed = $drawn || $this->shadowed === $this->next[0] ? $this->next[0] : '';
	}

	/**
	 * Tell what share of ordinary daylight is left in the air under the cloud deck.
	 *
	 * It mirrors deckAir() in weather.glsl: 1 with no storm darkness, and less the darker and more closed the deck
	 * is.
	 *
	 * @return float From 0.08 under the heaviest closed deck to 1.
	 */
	public function air(): float
	{
		if(!$this->active || !$this->deck || $this->deck[0] <= 0. || $this->deck[3] <= 0.) return 1.;

		$closed = min(1., max(0., ($this->deck[0] - .55) / .4));
		$block = $this->deck[2] + (1. - $this->deck[2]) * $closed * $closed * (3. - 2. * $closed);

		return 1. - .92 * $this->deck[0] * $block * $this->controls['stormDark'] / 100.;
	}

	/**
	 * Tell where the nearest lit strike shines from, for anything outside the scene that should flash with it.
	 *
	 * @return ?array The light's X, height and Z in meters and its strength from 0 to 1, or null when none is lit.
	 */
	public function bolt(): ?array
	{
		if(!$this->strikes) return null;

		[$top, $land, , $light] = $this->strikes[0];

		return [...self::lamp($top, $land), $light[0]];
	}

	/**
	 * Tell how far the land's exposure drops while lightning lights it.
	 *
	 * @return float A factor for the exposure of everything but the sky and the cloud: 1 with no flash.
	 */
	public function exposure(): float
	{
		$share = $this->controls ? $this->controls['flashLight'] / 100. : 0.;

		if($share <= 0. || !$this->strikes) return 1.;

		// Lowering exposure by the darkening's factor raised to 2.2 dims a mid-gray about as much as multiplying
		// the finished picture by that factor does.
		return 1. - $share * (1. - $this->keep() ** 2.2);
	}

	/**
	 * Hand a ground or water program the strike that lights the land, or take it away again.
	 *
	 * @param Program $p Program in use that declares uBoltAt and uBoltLight.
	 * @return void No value.
	 */
	private function shine(Program $p): void
	{
		if(!$this->light)
		{
			if(isset($this->lighted[$p]))
			{
				glUniform3f($p->location('uBoltLight'), 0., 0., 0.);
				unset($this->lighted[$p]);
			}

			return;
		}

		[$id, $at, $power] = $this->light;
		[$red, $green, $blue] = $this->tint();
		// The light is set against the lowered exposure, so a slope facing the bolt comes out bright however dark
		// the rest of the land has gone.
		$shine = self::SHINE * $power * $this->controls['flashLight'] / 100.
			/ max(.0001, $this->exposure * $this->exposure());

		$this->lighted[$p] = true;
		glUniform4f($p->location('uBoltAt'), $at[0], $at[1], $at[2], self::HALF);

		glUniform3f(
			$p->location('uBoltLight')
			, $shine * (.6 * $red + .4)
			, $shine * (.6 * $green + .4)
			, $shine * (.6 * $blue + .4)
		);

		if($this->shadowed === $id) $this->shadows->bind($p);
		else glUniform3f($p->location('uBoltShadowAt'), 0., 0., 0.);
	}

	/**
	 * Tell how much of its brightness everything but a lit cloud keeps this frame.
	 *
	 * @return float 1 with no lightning lit, down to 1 less the `flashDark` control under a full flash close by.
	 */
	private function keep(): float
	{
		$sum = 0.;

		// A strike darkens the scene less the farther off it is: half as much at 5 kilometers. The darkening
		// follows the square of the strike's light, so it lifts as the bolt fades from view and not after it,
		// and a strike beside or behind the eye, whose bolt is not on the screen, does not darken at all.
		foreach($this->strikes as [, , , $light, $far, , $ahead])
		$sum += $ahead * $light[0] ** 2 / (1. + ($far / 5000.) ** 2);

		return 1. - $this->controls['flashDark'] / 100. * min(1., $sum);
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

			$this->shine($p);

			return;
		}

		$this->enabled[$p] = true;
		glUniform4f($p->location('uDeck'), ...$this->deck);
		glUniform2f($p->location('uGroundCover'), ...$this->ground);
		glUniform1f($p->location('uRoadSnow'), $this->controls['roadSnow'] / 100.);
		glUniform1f($p->location('uCloudLight'), $this->controls['cloudLight'] / 100.);
		glUniform1f($p->location('uStormDark'), $this->controls['stormDark'] / 100.);
		$this->shine($p);

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

		if($this->strikes) $this->darken($root);

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

			// The sheet darkens with the land and glows around the top of each lit strike. A program that never
			// flashed needs no reset.
			if($this->strikes || $this->flashed)
			{
				$lost = 1. - $this->keep();
				$glow = $c['flashGlow'] / 100.;
				glUniform3f($p->location('uDim'), $lost, $lost, $lost);
				// The cloud glows a paler shade of the bolt's color.
				[$red, $green, $blue] = $this->tint();
				glUniform3f($p->location('uFlashColor'), .6 * $red + .4, .6 * $green + .4, .6 * $blue + .4);
				glUniform1i($p->location('uFlashes'), count($this->strikes));

				foreach($this->strikes as $n => [$top, , , $light])
				glUniform3f($p->location("uFlash[$n]"), $top[0], $top[2], $light[0] * $glow);

				$this->flashed = (bool)$this->strikes;
			}

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
			// Rain and snow are lit by the same air, so under a heavy deck they dim with the haze.
			glUniform1f($p->location('uDay'), $this->deck[3] * (.3 + .7 * $this->air()));
			glUniform2f($p->location('uView'), 2 * tan($camera->fov / 2) / max(1, $height), $height);
			$this->follow($camera->position);
			glUniform3f($p->location('uDrift'), ...$this->drift);

			$each = $c['kind'] === 'snow' ? self::FLAKES : self::DROPS;
			glDrawArraysInstanced(GL_TRIANGLE_STRIP, 0, 4, (int)ceil($each * $c['intensity'] / 100));
		}

		if($this->strikes) $this->bolts($root, $camera, $matrix, $width, $height);

		glDepthMask(true);
	}

	/**
	 * Read the bolt's color control.
	 *
	 * @return array Red, green and blue as they display, each from 0 to 1.
	 */
	private function tint(): array
	{
		$color = hexdec(substr($this->controls['boltColor'], 1));

		return [($color >> 16) / 255., ($color >> 8 & 255) / 255., ($color & 255) / 255.];
	}

	/**
	 * Darken every pixel that is not sky while lightning is lit, so the land stands dark against the lit cloud.
	 *
	 * One triangle covers the screen at the far depth and passes only where something nearer was drawn. Its color
	 * multiplies what is there.
	 *
	 * @param string $root Directory of the package's shaders.
	 * @return void No value.
	 */
	private function darken(string $root): void
	{
		// The share of the darkening that the flash's own light has taken over is left to the land's exposure.
		$keep = 1. - (1. - $this->keep()) * (1. - $this->controls['flashLight'] / 100.);

		// Less than a five-hundredth of darkening cannot be seen and is not worth a pass over the screen.
		if($keep > .998) return;

		$p = $this->shade ??= new Program(
			file_get_contents($root . 'sky.vert')
			, "#version 300 es\nprecision highp float;\nuniform vec3 uKeep;\nout vec4 color;\n"
				. "void main() { color=vec4(uKeep,1.); }\n"
		);

		$p->use();
		// The darkened land leans a little blue, as it does by a flash. The lean grows with the darkening, to its
		// full amount once the land has lost three tenths of its brightness, and is gone when the darkening is.
		$lean = min(1., (1. - $keep) / .3);

		glUniform3f(
			$p->location('uKeep')
			, $keep * (1. - .08 * $lean)
			, $keep * (1. - .04 * $lean)
			, min(1., $keep * (1. + .06 * $lean))
		);

		glDepthFunc(GL_GREATER);
		glBlendFuncSeparate(GL_ZERO, GL_SRC_COLOR, GL_ZERO, GL_ONE);
		glDrawArrays(GL_TRIANGLES, 0, 3);
		glDepthFunc(GL_LESS);
		glBlendFuncSeparate(GL_SRC_ALPHA, GL_ONE_MINUS_SRC_ALPHA, GL_ONE, GL_ONE_MINUS_SRC_ALPHA);
	}

	/**
	 * Draw each lit strike's bolt.
	 *
	 * The pass keeps the brightest value written to each pixel instead of adding them, so the pieces of a bolt
	 * join without seams. Hills in front hide it.
	 *
	 * @param string $root Directory of the package's shaders.
	 * @param Camera $camera Camera defining the viewpoint and projection.
	 * @param array $matrix Camera-relative view and projection matrix.
	 * @param int $width Render target width in pixels.
	 * @param int $height Render target height in pixels.
	 * @return void No value.
	 */
	private function bolts(string $root, Camera $camera, array $matrix, int $width, int $height): void
	{
		$p = $this->bolt ??= new Program(
			file_get_contents($root . 'lightning.vert')
			, file_get_contents($root . 'lightning.frag')
		);

		$p->use();
		$eye = $camera->position;
		glUniformMatrix4fv($p->location('uViewProjection'), 1, false, $matrix);
		glUniform3f($p->location('uView'), 2 * tan($camera->fov / 2) / max(1, $height), $width, $height);

		glUniform3f(
			$p->location('uShape')
			, $this->controls['boltWidth'] / 100.
			, $this->controls['boltZigzag'] / 100.
			, $this->controls['boltForks'] / 100.
		);

		glUniform3f($p->location('uTint'), ...$this->tint());
		glBlendEquationSeparate(GL_MAX, GL_FUNC_ADD);
		glBlendFuncSeparate(GL_ONE, GL_ONE, GL_ZERO, GL_ONE);

		foreach($this->strikes as [$top, $land, $seed, $light])
		{
			glUniform3f($p->location('uTop'), $top[0] - $eye[0], $top[1] - $eye[1], $top[2] - $eye[2]);
			glUniform3f($p->location('uBottom'), $land[0] - $eye[0], $land[1] - $eye[1], $land[2] - $eye[2]);
			// The bolt is drawn at the square root of its light. It keeps the brightest value at each pixel, so at
			// its plain light it would drop out of sight against the cloud while it was still lighting the scene.
			glUniform4f($p->location('uBolt'), $seed, $light[2], sqrt($light[0]), sqrt($light[1]));
			glDrawArraysInstanced(GL_TRIANGLE_STRIP, 0, 4, self::PIECES);
		}

		glBlendEquationSeparate(GL_FUNC_ADD, GL_FUNC_ADD);
		glBlendFuncSeparate(GL_SRC_ALPHA, GL_ONE_MINUS_SRC_ALPHA, GL_ONE, GL_ONE_MINUS_SRC_ALPHA);
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
		$this->bolt?->close();
		$this->shade?->close();
		$this->shadows?->close();

		if($this->texture) glDeleteTextures(1, $this->texture);
	}
}
