<?php
declare(strict_types=1);

use SanePhonics\Gameplay\Combo;
use Phonics\Render\Atlas;
use Phonics\Render\BitmapFont;
use Phonics\Render\Camera;
use Phonics\Render\Sprite;
use Phonics\Render\SpriteRenderer;

/** Top-right movement combo chain and result display modeled after moving-boxes-2. */
final class DemoComboHud
{
	public array $snapshot = [];
	private ?SpriteRenderer $sprites = null;
	private bool $ownsAtlas, $closed = false;
	private array $cache = [], $nextCache = [];

	/**
	 * Prepare a combo HUD with the menu's annotated bitmap font and optional shared atlas.
	 *
	 * @param BitmapFont $font Font used for action labels, totals, and result colors.
	 * @param Combo $combo Player combo state read without duplicating gameplay ownership.
	 * @param Atlas|null $atlas Matching borrowed atlas, or null to allocate one on first draw.
	 * @return void Retains CPU-side display state without allocating graphics immediately.
	 */
	public function __construct(
		private BitmapFont $font
		, private Combo $combo
		, private ?Atlas $atlas = null
	){
		$this->ownsAtlas = $atlas === null;
	}

	/**
	 * Convert a combo into the labels and arithmetic shown in the top-right display.
	 *
	 * @param Combo $combo Active chain and most recent settlement result.
	 * @return array{visible:bool,labels:list<string>,base:int,multiplier:float,total:int,overflow:int,
	 * result:int|null,failed:bool} Serializable display model.
	 */
	public static function view(Combo $combo): array
	{
		$count = count($combo->chain);
		$showChain = $count > 1 || ($combo->chain[0]->special ?? false);

		return [
			'visible' => $showChain || $combo->result !== null
			, 'labels' => $showChain
				? array_map(
					/**
					 * Read one reward label for the bounded recent-action list.
					 *
					 * @param \SanePhonics\Gameplay\ComboReward $reward Active combo reward.
					 * @return string User-facing action label.
					 */
					fn($reward): string => $reward->label
					, array_slice($combo->chain, -4)
				)
				: []
			, 'base' => $combo->base()
			, 'multiplier' => $combo->multiplier()
			, 'total' => (int)ceil($combo->base() * $combo->multiplier())
			, 'overflow' => max(0, $count - 4)
			, 'result' => $combo->result
			, 'failed' => $combo->failed
		];
	}

	/**
	 * Draw the active chain or recent result at the upper-right edge of the game frame.
	 *
	 * @param DemoGame $game Session supplying current output dimensions.
	 * @param bool $visible Whether a player-controlled scene is currently on screen.
	 * @return void Publishes a browser-test snapshot and submits a cached glyph batch when needed.
	 */
	public function render(DemoGame $game, bool $visible = true): void
	{
		$view = self::view($this->combo);
		$view['visible'] = $visible && !$this->closed && $view['visible'];
		$view['draws'] = 0;
		$this->snapshot = $view;

		if(!$view['visible'])
		{
			$this->cache = [];

			return;
		}

		$width = $game->engine->device->width;
		$height = $game->engine->device->height;
		$scale = min(2, $width / 480, $height / 270);
		$right = $width - 8 * $scale;
		$line = $this->font->lineHeight * $scale;
		$y = 6 * $scale;
		$boxWidth = 160 * $scale;
		$batch = $this->nextCache = [];

		foreach($view['labels'] as $label)
		{
			array_push($batch, ...$this->text($label, $right, $y, $scale, $boxWidth, [1, 1, 1, 1]));
			$y += $line;
		}

		if($view['labels'])
		{
			if($view['overflow'])
			{
				array_push(
					$batch
					, ...$this->text('+' . $view['overflow'], $right, $y, $scale, $boxWidth, [1, .85, .1, 1])
				);

				$y += $line;
			}

			$multiplier = rtrim(rtrim(number_format($view['multiplier'], 2, '.', ''), '0'), '.');

			array_push(
				$batch
				, ...$this->text($view['base'] . ' X ' . $multiplier, $right, $y, $scale, $boxWidth, [1, .85, .1, 1])
			);

			$y += $line;
		}

		if($view['result'] !== null)
		{
			$result = ($view['failed'] ? '' : '+') . $view['result'];
			$color = $view['failed'] ? [1, .25, .2, 1] : [.35, 1, .5, 1];
			array_push($batch, ...$this->text($result, $right, $y, $scale, $boxWidth, $color));
		}

		$this->atlas ??= new Atlas($this->font->map);
		$this->sprites ??= new SpriteRenderer($this->atlas);
		$this->sprites->render($batch, new Camera(0, 0, $width, $height));
		$this->snapshot['draws'] = $this->sprites->draws;
		$this->cache = $this->nextCache;
	}

	/**
	 * Cache one right-aligned glyph run for the current text, position, size, and color.
	 *
	 * @param string $text Combo label, arithmetic, or result to draw.
	 * @param float $right Right edge of the alignment box in output pixels.
	 * @param float $y Top edge of the line in output pixels.
	 * @param float $scale Font scale independent of the world camera.
	 * @param float $width Available alignment width in output pixels.
	 * @param array{float,float,float,float} $color Glyph tint in RGBA order.
	 * @return list<Sprite> Cached glyph sprites positioned in screen space.
	 */
	private function text(
		string $text
		, float $right
		, float $y
		, float $scale
		, float $width
		, array $color
	): array {
		$key = serialize([$text, $right, $y, $scale, $width, $color]);
		$glyphs = $this->cache[$key] ?? null;

		if($glyphs === null)
		{
			$glyphs = $this->font->layout($text, $scale, INF, $color, 'right', $width)['sprites'];

			foreach($glyphs as $sprite)
			{
				$sprite->x += $right - $width;
				$sprite->y += $y;
			}
		}

		$this->nextCache[$key] = $glyphs;

		return $glyphs;
	}

	/**
	 * Rebuild owned graphics or clear borrowed-atlas batches after context recovery.
	 *
	 * @return void Preserves the active combo while replacing invalid GPU objects.
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
	 * @return void Disposes once and leaves combo gameplay state available to its owner.
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
