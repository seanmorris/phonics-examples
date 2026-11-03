<?php
declare(strict_types=1);
namespace PhpNoid\Render;
use PhpNoid\Playfield;
use PhpNoid\Component\Ball;
use PhpNoid\Component\Brick;
use PhpNoid\Component\Capsule;
use PhpNoid\Component\Enemy;
use PhpNoid\Component\Paddle;
use PhpNoid\Component\Projectile;
use Phonics\Render\RectRenderer;

/** Original geometric artwork; all simulation state stays in ECS components. */
final class Scene
{

	/**
	 * Draw the playfield with the framework's batched primitive renderer.
	 * @param Playfield $field Current campaign and entities.
	 * @param RectRenderer $rects Session-owned renderer with context-loss recovery.
	 * @param float $offsetX Left world coordinate shown at output x zero.
	 * @param int|null $viewportWidth Visible world width; null uses the complete playfield.
	 * @return void Submits the complete frame.
	 */
	public static function draw(
		Playfield $field
		, RectRenderer $rects
		, float $offsetX = 0
		, ?int $viewportWidth = null
	): void {
		$r = $field->registry();
		$width = $field->state->width;
		$height = $field->state->height;
		$batch = [[0, 0, 8, $height, .1, .6, .9, 1], [$width - 8, 0, 8, $height, .1, .6, .9, 1]];

		foreach($r->query(Brick::class) as $id)
		{
			$b = $r->get($id, Brick::class);
			$color = $b->unbreakable
				? [.8, .65, .3]
				: ($b->material === 'green'
					? [.2, .65, .25]
					: ($b->hits > 2 ? [.65, .7, .85] : ($b->hits > 1 ? [1, .45, .2] : [.2, .8, .7]))
				);

			self::balloon($batch, $b->x, $b->y, $b->width, $b->height, $color, rounded: false);

			for($hit = 0; $hit < min(6, $b->hits); $hit++)
			$batch[] = [$b->x + 5 + $hit * 6, $b->y + $b->height - 5, 3, 2, .03, .08, .12, .8];

			if($b->material === 'green')
			$batch[] = [$b->x + $b->width - 8, $b->y + 7, 4, 6, 1, .15, .25, 1];
		}

		foreach($r->query(Paddle::class) as $id)
		{
			$p = $r->get($id, Paddle::class);

			if($p->invisible > 0)
			{
				$batch[] = [$p->x - 8, $p->y + 6, 16, 2, .7, .9, 1, .2];
				continue;
			}

			$color = isset($p->powers['catch']) ? [1, .9, .4] : [.7, .9, 1];

			if($p->catchFlash > 0)
			{
				$blend = min(1, $p->catchFlash / .24);
				$flash = [.25, 1, .58];

				foreach($color as $channel => $value)
				$color[$channel] = $value * (1 - $blend) + $flash[$channel] * $blend;
			}

			self::balloon($batch, $p->x - $p->width / 2, $p->y - 7, $p->width, 14, $color);

			foreach([-1, 1] as $side)
			{
				$x = $p->x + $side * ($p->width / 2 - 4) - 4;
				self::balloon($batch, $x, $p->y - 7, 8, 14, [1, .25, .4], true);

				if(isset($p->powers['laser'])) $batch[] = [$x + 2, $p->y - 15, 4, 8, 1, .5, .5, 1];
			}

			if(isset($p->powers['missile'])) $batch[] = [$p->x - 4, $p->y - 17, 8, 10, 1, .7, .2, 1];
		}

		foreach($r->query(Ball::class) as $id)
		{
			$b = $r->get($id, Ball::class);
			$color = $b->team === 'player' ? [1, 1, 1] : self::color($b->effect);
			// Snap artwork only. Physics keeps its subpixel position and radius; corners remain clear.
			$x = round($b->x);
			$y = round($b->y);

			foreach(self::sphere($color) as $pixel)
			{
				$pixel[0] += $x;
				$pixel[1] += $y;
				$batch[] = $pixel;
			}

			if($b->team !== 'player') $batch[] = [$x - 2, $y - 2, 4, 4, .03, .05, .1, 1];
		}

		foreach($r->query(Capsule::class) as $id)
		{
			$c = $r->get($id, Capsule::class);
			$color = $field->capsules[$c->kind]['color'];
			self::balloon($batch, $c->x - 11, $c->y - 8, 22, 16, $color, true);

			foreach(self::glyph($c->kind) as $y => $row)
			foreach(str_split($row) as $x => $pixel)
			if($pixel === '1') $batch[] = [$c->x - 5 + $x * 2, $c->y - 5 + $y * 2, 2, 2, .03, .05, .1, 1];
		}

		foreach($r->query(Enemy::class) as $id)
		{
			$e = $r->get($id, Enemy::class);
			$color = match($e->family)
			{
				'destroyer' => [1, .15, .25], 'annihilator' => [.72, .48, .88],
				'sympathizer' => [.6, 1, .7], default => self::color($e->effect)
			};

			$batch[] = [$e->x - 14, $e->y - 8, 28, 16, ...$color, 1];
			$batch[] = [$e->x - 20, $e->y, 6, 12, ...$color, .8];
			$batch[] = [$e->x + 14, $e->y, 6, 12, ...$color, .8];
			$batch[] = [$e->x - 9, $e->y - 4, 18, 4, .03, .06, .12, 1];

			for($hit = 0; $hit < min(7, $e->hits); $hit++)
			$batch[] = [$e->x - 6 + $hit * 4, $e->y + 3, 2, 2, 1, 1, 1, 1];
		}

		foreach($r->query(Projectile::class) as $id)
		{
			$p = $r->get($id, Projectile::class);

			if($p->team === 'enemy')
			{
				$batch[] = [$p->x - 5, $p->y - 6, 10, 12, 1, .2, .3, 1];
				$batch[] = [$p->x - 3, $p->y - 4, 6, 8, .02, .02, .04, 1];
			}
			else
			{
				$size = $p->kind === 'missile' ? 4 : 2;
				$batch[] = [$p->x - $size, $p->y - 8, $size * 2, 16, 1, .5, .15, 1];
			}
		}

		if($field->state->flash > 0) $batch[] = [0, 0, $width, $height, .8, .05, .1, $field->state->flash * .35];

		if($field->state->fade > 0) $batch[] = [0, 0, $width, $height, .025, .035, .085, $field->state->fade];

		if($offsetX !== 0.0)
		foreach($batch as &$rectangle) $rectangle[0] -= $offsetX;

		unset($rectangle);
		$rects->render($batch, $viewportWidth ?? (int)$width, (int)$height);
	}

	/**
	 * Share the Menacer palette between ships and their emitted balls.
	 * @param string $effect Authored Menacer effect name.
	 * @return array RGB channels in the unit range.
	 */
	public static function color(string $effect): array
	{
		return match($effect)
		{
			'cyan' => [.2, .9, 1], 'red' => [1, .2, .3], 'green' => [.3, .9, .3],
			'silver' => [.75, .8, .9], 'copper', 'bronze' => [1, .55, .2], default => [.6, .65, .7]
		};
	}

	/**
	 * Append a rounded VGA balloon with stepped highlights and a deep lower rim.
	 * @param array $batch Destination primitive batch.
	 * @param float $x Left edge.
	 * @param float $y Top edge.
	 * @param float $width Outer width.
	 * @param float $height Outer height.
	 * @param array $color Base RGB channels.
	 * @param bool $compact Use a shorter highlight suitable for capsule glyphs.
	 * @param bool $rounded Clip the silhouette corners; bricks use square corners.
	 * @return void Appends the balloon's rectangle records to the destination batch.
	 */
	public static function balloon(
		array &$batch
		, float $x
		, float $y
		, float $width
		, float $height
		, array $color
		, bool $compact = false
		, bool $rounded = true
	): void {
		$x = round($x);
		$y = round($y);
		$cut = $rounded ? min(2, floor(min($width, $height) / 4)) : 0;
		$depth = max(1, min(2, floor($height / 7)));
		$batch[] = [$x + $cut, $y, $width - 2 * $cut, $height, ...array_slice($color, 0, 3), 1];
		$batch[] = [$x, $y + $cut, $width, $height - 2 * $cut, ...array_slice($color, 0, 3), 1];

		// Each band reaches the silhouette: no unshaded base-color rim remains outside it.
		foreach([.48, .30, .14] as $step => $light)
		{
			$inset = $step === 0 ? $cut : 0;
			$batch[] = [$x + $inset, $y + $step * $depth, $width - 2 * $inset, $depth, 1, 1, 1, $light];
			$shade = [.42, .26, .12][$step];
			$batch[] = [
				$x + $inset
				, $y + $height - ($step + 1) * $depth
				, $width - 2 * $inset
				, $depth
				, .015
				, .025
				, .05
				, $shade
			];
		}

		foreach([.20, .10] as $step => $light)
		{
			$batch[] = [$x + $step, $y + $cut, 1, $height - 2 * $cut, 1, 1, 1, $light];
			$batch[] = [$x + $width - $step - 1, $y + $cut, 1, $height - 2 * $cut, .015, .025, .05, $light];
		}

		if($width >= 14)
		$batch[] = [$x + 4, $y + $depth, $compact ? 4 : floor(($width - 8) / 3), 1, 1, 1, 1, .24];
	}

	/**
	 * Cache opaque horizontal pixel runs for a seven-tone sphere with the existing silhouette.
	 * @param array $color Base RGB channels.
	 * @return array Rectangle records forming the shaded sphere.
	 */
	private static function sphere(array $color): array
	{
		static $cache = [];
		$key = implode(',', $color);

		if(isset($cache[$key])) return $cache[$key];

		$runs = [];

		foreach([2, 4, 4, 5, 5, 5, 5, 4, 4, 2] as $row => $half)
		{
			$last = null;

			for($column = -$half; $column < $half; $column++)
			{
				$nx = ($column + .5) / 5;
				$ny = ($row - 4.5) / 5;
				$nz = sqrt(max(0, 1 - $nx * $nx - $ny * $ny));
				$light = max(0, min(1, -.45 * $nx - .55 * $ny + .70 * $nz));
				$tone = (int)round($light * 6);

				if($last === $tone)
				{
					$runs[count($runs) - 1][2]++;
					continue;
				}

				$last = $tone;
				$brightness = .38 + .62 * $tone / 6;
				$runs[] = [
					$column, $row - 5, 1, 1
					, $color[0] * $brightness
					, $color[1] * $brightness
					, $color[2] * $brightness
					, 1
				];
			}
		}

		return $cache[$key] = $runs;
	}

	/**
	 * Return original five-pixel capsule markings independent of color perception.
	 * @param string $kind Capsule export key.
	 * @return array Five binary rows, rendered with primitive rectangles.
	 */
	private static function glyph(string $kind): array
	{
		return match($kind)
		{
			'expand' => ['00100', '00100', '11111', '00100', '00100'],
			'shrink' => ['00000', '00000', '11111', '00000', '00000'],
			'catch' => ['01110', '10000', '10000', '10000', '01110'],
			'split' => ['11110', '00001', '01110', '00001', '11110'],
			'laser' => ['10000', '10000', '10000', '10000', '11111'],
			'missile' => ['10001', '11011', '10101', '10001', '10001'],
			'power' => ['11110', '10001', '11110', '10000', '10000'],
			'fast' => ['11111', '10000', '11110', '10000', '10000'],
			'slow' => ['01111', '10000', '01110', '00001', '11110'],
			'extra' => ['01010', '11111', '11111', '01110', '00100'],
			default => ['10010', '01001', '00100', '01001', '10010']
		};
	}
}
