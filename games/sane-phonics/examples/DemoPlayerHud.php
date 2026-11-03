<?php
declare(strict_types=1);

use SanePhonics\Gameplay\RingCounter;
use Phonics\Render\Atlas;
use Phonics\Render\BitmapFont;
use Phonics\Render\Camera;
use Phonics\Render\Sprite;
use Phonics\Render\SpriteRenderer;

/** Screen-space score, time, and ring display styled after moving-boxes-2's player HUD. */
final class DemoPlayerHud
{
	public array $snapshot = [];
	private ?SpriteRenderer $sprites = null;
	private bool $ownsAtlas, $closed = false;
	private array $cache = [], $nextCache = [];

	/**
	 * Prepare a player HUD using the menu's annotated bitmap font and optional shared atlas.
	 *
	 * @param BitmapFont $font Font used for yellow labels, white values, and their pixel shadows.
	 * @param RingCounter $counter Player totals displayed without duplicating gameplay state.
	 * @param Atlas|null $atlas Matching borrowed atlas, or null to allocate one on the first draw.
	 * @return void Retains CPU-side display state without allocating graphics immediately.
	 */
	public function __construct(
		private BitmapFont $font
		, private RingCounter $counter
		, private ?Atlas $atlas = null
	){
		$this->ownsAtlas = $atlas === null;
	}

	/**
	 * Format the three moving-boxes-2-style player rows for display and inspection.
	 *
	 * @param RingCounter $counter Current score and ring totals.
	 * @param float $seconds Nonnegative active level time in seconds.
	 * @return array<string,string> SCORE, TIME, and RINGS values with fixed-width numeric fields.
	 */
	public static function rows(RingCounter $counter, float $seconds): array
	{
		$seconds = max(0, $seconds);
		$minutes = (int)floor($seconds / 60);
		$remaining = (int)floor(fmod($seconds, 60));

		return [
			'SCORE:' => str_pad((string)$counter->score, 6, ' ', STR_PAD_LEFT)
			, 'TIME:' => sprintf('%02d:%02d', $minutes, $remaining)
			, 'RINGS:' => str_pad((string)$counter->rings, 4, ' ', STR_PAD_LEFT)
		];
	}

	/**
	 * Draw the player HUD at the upper left without a panel or diagnostic content.
	 *
	 * @param DemoGame $game Session supplying output dimensions and active level time.
	 * @param bool $visible Whether a player-controlled scene is currently on screen.
	 * @return void Publishes the displayed values and submits one cached glyph batch when visible.
	 */
	public function render(DemoGame $game, bool $visible = true): void
	{
		$seconds = max(0, $game->engine->time - $game->levelStartedAt);
		$rows = self::rows($this->counter, $seconds);
		$vehicle = $game->level?->vehicles?->ridden($game->entity);

		if($vehicle !== null && $game->engine->entities->has($vehicle->entity, \SanePhonics\Gameplay\Tornado::class))
		{
			$tornado = $game->engine->entities->get($vehicle->entity, \SanePhonics\Gameplay\Tornado::class);
			$rows['FUEL:'] = str_pad((string)(int)round($tornado->fuel), 3, ' ', STR_PAD_LEFT) . '%';
		}

		$this->snapshot = [
			'visible' => $visible && !$this->closed
			, 'score' => $this->counter->score
			, 'rings' => $this->counter->rings
			, 'seconds' => $seconds
			, 'rows' => $rows
			, 'draws' => 0
		];

		if(!$visible || $this->closed)
		{
			$this->cache = [];

			return;
		}

		$width = $game->engine->device->width;
		$height = $game->engine->device->height;
		$scale = min(2, $width / 480, $height / 270);
		$x = 8 * $scale;
		$y = 6 * $scale;
		$line = $this->font->lineHeight * $scale;
		$valueX = $x + 64 * $scale;
		$valueWidth = 48 * $scale;
		$shadow = $scale;
		$batch = $this->nextCache = [];

		foreach($rows as $label => $value)
		{
			$labelColor = $label === 'RINGS:' && $this->counter->rings === 0
				? [1, .2, .15, 1]
				: [1, .85, .1, 1];

			foreach([[-$shadow, 0], [$shadow, 0], [0, -$shadow], [0, $shadow]] as [$sx, $sy])
			{
				array_push(
					$batch
					, ...$this->text($label, $x + $sx, $y + $sy, $scale, 68 * $scale, 'left', [0, 0, 0, .75])
					, ...$this->text($value, $valueX + $sx, $y + $sy, $scale, $valueWidth, 'right', [0, 0, 0, .75])
				);
			}

			array_push(
				$batch
				, ...$this->text($label, $x, $y, $scale, 68 * $scale, 'left', $labelColor)
				, ...$this->text($value, $valueX, $y, $scale, $valueWidth, 'right', [1, 1, 1, 1])
			);

			$y += $line;
		}

		$this->atlas ??= new Atlas($this->font->map);
		$this->sprites ??= new SpriteRenderer($this->atlas);
		$this->sprites->render($batch, new Camera(0, 0, $width, $height));
		$this->snapshot['draws'] = $this->sprites->draws;
		$this->cache = $this->nextCache;
	}

	/**
	 * Cache one aligned glyph run for the current text, position, size, and color.
	 *
	 * @param string $text Label or formatted value to draw.
	 * @param float $x Left edge of the alignment box in output pixels.
	 * @param float $y Top edge of the line in output pixels.
	 * @param float $scale Font scale independent of the world camera.
	 * @param float $width Available alignment width in output pixels.
	 * @param string $align Left or right alignment within the width.
	 * @param array{float,float,float,float} $color Glyph tint in RGBA order.
	 * @return list<Sprite> Cached glyph sprites positioned in screen space.
	 */
	private function text(
		string $text
		, float $x
		, float $y
		, float $scale
		, float $width
		, string $align
		, array $color
	): array {
		$key = serialize([$text, $x, $y, $scale, $width, $align, $color]);
		$glyphs = $this->cache[$key] ?? null;

		if($glyphs === null)
		{
			$glyphs = $this->font->layout($text, $scale, INF, $color, $align, $width)['sprites'];

			foreach($glyphs as $sprite)
			{
				$sprite->x += $x;
				$sprite->y += $y;
			}
		}

		$this->nextCache[$key] = $glyphs;

		return $glyphs;
	}

	/**
	 * Rebuild HUD graphics after restoring its borrowed or owned font atlas.
	 *
	 * @return void Preserves player totals while replacing invalid GPU objects.
	 */
	public function restore(): void
	{
		if($this->closed)
		{
			return;
		}

		if($this->ownsAtlas)
		{
			$this->atlas?->restore();
		}

		$this->sprites?->restore();
		$this->cache = [];
	}

	/**
	 * Release HUD graphics without closing an atlas borrowed from the menu renderer.
	 *
	 * @return void Disposes once and leaves its player counter available to the game session.
	 */
	public function close(): void
	{
		if($this->closed)
		{
			return;
		}

		$this->closed = true;
		$this->sprites?->close();

		if($this->ownsAtlas)
		{
			$this->atlas?->close();
		}

		$this->cache = $this->nextCache = [];
	}
}
