<?php
declare(strict_types=1);
namespace PhlappyPhant\Render;

use Phonics\Render\Atlas;
use Phonics\Render\BitmapFont;
use Phonics\Render\Camera;
use Phonics\Render\RectRenderer;
use Phonics\Render\Sprite;
use Phonics\Render\SpriteRenderer;
use Phonics\Tiled\TileMap;
use Phonics\Ui\Menu;
use Phonics\Ui\MenuRenderer;
use PhlappyPhant\Simulation;

/**
 * Draw the world into any viewport: a fixed logical height fills the frame and the width shows as much of the
 * world ahead as the aspect allows, so nothing is letterboxed.
 */
final class Scene
{
	public const GID_PHANT = 1;
	public const GID_PHANT_LEFT = 3;
	public const GID_CAP = 5;
	public const GID_RING = 6;
	public const GID_BOTTOM = 7;
	public const GID_GROUND = 8;
	public const GID_CLOUD = 9;
	public const TILE_W = 56;
	public const TILE_H = 32;
	public const DAY_SKY = [.81, .90, .96];
	public const NIGHT_SKY = [.11, .14, .25];
	public const NIGHT_TINT = [.55, .60, .85];
	public const INK = [.21, .20, .33];
	public const CREAM = [.95, .89, .75];
	public const PLAQUE = [.95, .89, .75, .97];

	/** Accessible hit regions published by the menu renderer. */
	public array $regions = [];
	/** Output width and height, logical scale, and the phant's screen x. */
	public array $viewport = [0, 0, 1, 0];
	private Atlas $atlas;
	private Atlas $fontAtlas;
	private SpriteRenderer $sprites;
	private SpriteRenderer $text;
	private RectRenderer $rects;
	private MenuRenderer $menus;
	private array $cache = [];
	/** Zero for day, one for night, eased toward the simulation's palette. */
	private float $blend = 0;
	private float $blendClock = 0;
	private float $deathSpin = 0;

	/**
	 * Prepare sprite, text, rectangle, and menu renderers for the Phlappy Phant atlas.
	 *
	 * @param BitmapFont $font Shared bitmap font used by the HUD and menus.
	 * @param TileMap $map Map describing the sprite tileset.
	 * @return void Renderer resources are retained until close is called.
	 */
	public function __construct(private BitmapFont $font, TileMap $map)
	{
		$this->atlas = new Atlas($map);
		$this->fontAtlas = new Atlas($font->map);
		$this->sprites = new SpriteRenderer($this->atlas);
		$this->text = new SpriteRenderer($this->fontAtlas);
		$this->rects = new RectRenderer;
		$this->menus = new MenuRenderer($font);
		$this->menus->style['padding'] = 10;
		$this->menus->style['panel'] = self::PLAQUE;
		$this->menus->style['foreground'] = [...self::INK, 1.0];
		$this->menus->style['selected'] = [1, 1, 1, 1];
		$this->menus->style['highlight'] = [.18, .43, .45, 1];
		$this->menus->style['selection'] = [.12, .27, .28, 1];
		$this->menus->style['disabled'] = [.18, .43, .45, 1];
	}

	/**
	 * Sky colour for the device clear, blended between day and night.
	 *
	 * @return array{float,float,float,float} RGBA clear colour.
	 */
	public function skyColor(): array
	{
		return [...self::lerp(self::DAY_SKY, self::NIGHT_SKY, $this->blend), 1.0];
	}

	/**
	 * Render one display frame and expose accessible menu regions.
	 *
	 * @param Simulation $game Current deterministic game state.
	 * @param Menu $menu Shared menu model rendered above the world.
	 * @param string $page Current application page identifier.
	 * @param int $width Physical output width in pixels.
	 * @param int $height Physical output height in pixels.
	 * @param float $alpha Fixed-step interpolation fraction.
	 * @param float $seconds Elapsed UI animation time.
	 * @param int $best Best local score for the ready screen.
	 * @return void Draw calls are submitted to the active device.
	 */
	public function render(
		Simulation $game
		, Menu $menu
		, string $page
		, int $width
		, int $height
		, float $alpha
		, float $seconds
		, int $best
	): void {
		// Pixel art wants whole pixels: the world draws at the largest integer scale that keeps the playable height in
		// view, anchored to the ground, so a taller frame shows more sky and the tiles are never resampled.
		$k = max(1, (int)floor($height / (Simulation::HEIGHT - Simulation::GAP_MARGIN)));
		$scale = (float)$k;
		$visible = $width / $scale;
		$offsetY = $height / $scale - Simulation::HEIGHT;
		$phantX = max(60.0, min(200.0, .28 * $visible));
		$this->viewport = [$width, $height, $scale, $phantX * $scale];
		$camera = new Camera(0, 0, $width, $height);

		$elapsed = max(0, min(.25, $seconds - $this->blendClock));
		$this->blendClock = $seconds;
		$target = (float)$game->palette;
		$this->blend += max(-$elapsed, min($elapsed, $target - $this->blend));
		$tint = [...self::lerp([1, 1, 1], self::NIGHT_TINT, $this->blend), 1.0];

		$horizon = self::lerp([.95, .90, .75], [.25, .27, .42], $this->blend);
		$this->rects->render([[
			0, (Simulation::GROUND_Y - 48 + $offsetY) * $scale, $width, 48 * $scale, $horizon[0], $horizon[1], $horizon[2], 1
		]], $width, $height);

		$sprites = [];
		$parallax = $game->scroll * .3;
		$period = 230.0;
		$count = (int)ceil($visible / $period) + 2;
		$skyBand = max(40, (int)(Simulation::GROUND_Y + $offsetY - 100));

		for($i = 0; $i < $count; $i++)
		{
			$wx = $i * $period + ($i * 97) % 140;
			$x = fmod($wx - $parallax, $count * $period);

			if($x < -self::TILE_W) $x += $count * $period;
			if($x > $visible + self::TILE_W) continue;

			$sprites[] = $this->tile(self::GID_CLOUD, $x * $scale, (20 + ($i * 53) % $skyBand) * $scale, $scale, $tint);
		}

		foreach($game->barrels as $barrel)
		{
			$bx = $barrel->previousX + ($barrel->x - $barrel->previousX) * $alpha;
			$screenX = ($phantX + $bx) * $scale;

			if($screenX > $width || $screenX + self::TILE_W * $scale < 0) continue;

			// The stack hanging from the ceiling ends in a downward lid; the one on the ground starts with an upward lid.
			for($y = $barrel->gapTop - self::TILE_H; $y + $offsetY > -self::TILE_H; $y -= self::TILE_H)
			$sprites[] = $this->tile(
				$y === $barrel->gapTop - self::TILE_H ? self::GID_BOTTOM : self::GID_RING
				, $screenX
				, ($y + $offsetY) * $scale
				, $scale
				, $tint
			);

			for($y = $barrel->gapTop + $barrel->gap; $y < Simulation::GROUND_Y; $y += self::TILE_H)
			$sprites[] = $this->tile(
				$y === $barrel->gapTop + $barrel->gap ? self::GID_CAP : self::GID_RING
				, $screenX
				, ($y + $offsetY) * $scale
				, $scale
				, $tint
			);
		}

		$groundShift = fmod($game->scroll, self::TILE_W);

		for($x = -$groundShift; $x < $visible; $x += self::TILE_W)
		{
			$sprites[] = $this->tile(self::GID_GROUND, $x * $scale, (Simulation::GROUND_Y + $offsetY) * $scale, $scale, $tint);
			$sprites[] = $this->tile(self::GID_GROUND, $x * $scale, (Simulation::GROUND_Y + self::TILE_H + $offsetY) * $scale, $scale, $tint);
		}

		{
			$y = $game->previousY + ($game->y - $game->previousY) * $alpha;
			// The tumble uses the left-facing frames rather than a flip, so the lettering never mirrors.
			$phant = new Sprite(
				($game->phase === 'dead' ? self::GID_PHANT_LEFT : self::GID_PHANT) + $game->frame
				, ($phantX + Simulation::PHANT_W / 2) * $scale
				, ($y + Simulation::PHANT_H / 2 + $offsetY) * $scale
				, .5
				, .5
			);
			$phant->scaleX = $phant->scaleY = $scale;
			$phant->animated = $phant->outline = $phant->smooth = false;
			$phant->tint = [...self::lerp([1, 1, 1], [.8, .8, .95], $this->blend), 1.0];

			if($game->phase === 'dead')
			{
				$this->deathSpin = min(1.0, $this->deathSpin + $elapsed * 3);
				$phant->rotation = -M_PI / 2 * $this->deathSpin;
			}
			else
			{
				$this->deathSpin = 0;
				$phant->rotation = $game->phase === 'playing' ? max(-.45, min(1.2, $game->vy / 600)) : 0.0;
			}

			$sprites[] = $phant;
		}

		$this->sprites->render($sprites, $camera);

		$labels = [];
		// Text scales are whole numbers too, so glyph strokes stay even.
		$textScale = (float)$k;

		if($page === 'play')
		{
			$this->label($labels, (string)$game->score, $width / 2, $height * .07, $textScale * 2, 'center');

			if($game->phase === 'ready')
			{
				$this->label($labels, 'TAP TO FLAP', $width / 2, $height * .62, $textScale, 'center');
				$this->label($labels, 'BEST ' . $best, $width / 2, $height * .69, $textScale, 'center');
			}
			elseif($game->phase === 'dead')
			{
				$this->label($labels, 'BONK', $width / 2, $height * .45, $textScale * 2, 'center');
			}
		}

		if($page === 'title')
		{
			// The wordmark fills up to the frame's width minus a margin at a whole-number scale, so phones get a
			// smaller logo rather than a clipped or resampled one.
			$logo = (float)max(1, min(2 * $k + 1, (int)floor(($width - 24 * $scale) / max(1, $this->measure('PHLAPPY PHANT')))));
			$this->label($labels, 'PHLAPPY PHANT', $width / 2 + $logo, $height * .11 + $logo, $logo, 'center', self::INK);
			$this->label($labels, 'PHLAPPY PHANT', $width / 2, $height * .11, $logo, 'center', self::CREAM);
		}

		$this->text->render($labels, $camera);

		if($menu->isOpen)
		{
			// The shared menu renderer scales by min(style scale, width / 320, height / 180); handing it the whole number
			// below that keeps its glyphs crisp, and the plaque width follows so the hint row fits.
			$m = max(1, min($k, 3, (int)floor(min($width / 320, $height / 180))));
			$this->menus->style['scale'] = $m;
			$this->menus->style['width'] = 330 * $m;
			// The control hints need a wide plaque and a readable size; small frames drop them rather than clip them.
			$this->menus->showHints = $m >= 2 && $width >= 330 * $m - 10;
			$this->menus->style['dimmer'] = $page === 'title' ? [0, 0, 0, 0] : [...self::INK, .35];
			[$px, $py, $pw, $ph] = $this->plaque($menu, $width, $height);
			$edge = max(2, round(2 * $scale));
			$drop = max(3, round(4 * $scale));
			$this->rects->render([
				[$px - $edge + $drop, $py - $edge + $drop, $pw + 2 * $edge, $ph + 2 * $edge, self::INK[0], self::INK[1], self::INK[2], .45]
				, [$px - $edge, $py - $edge, $pw + 2 * $edge, $ph + 2 * $edge, self::INK[0], self::INK[1], self::INK[2], 1]
			], $width, $height);
		}

		$this->menus->render($menu, $width, $height, $seconds);
		$this->regions = $this->menus->regions;
	}

	/**
	 * The panel rectangle the shared menu renderer will use, so a border can be drawn beneath it.
	 *
	 * @param Menu $menu Open menu.
	 * @param int $width Output width in pixels.
	 * @param int $height Output height in pixels.
	 * @return array{float,float,float,float} Left, top, width, and height in output pixels.
	 */
	private function plaque(Menu $menu, int $width, int $height): array
	{
		$style = $this->menus->style;
		$s = max(.5, min((float)$style['scale'], $width / 320, $height / 180));
		$pad = $style['padding'] * $s;
		$w = max(1, min($style['width'], $width - 16));
		$rowHeight = 20 * $s;
		$header = 24 * $s;
		$footer = ($this->menus->showHints ? 38 : 20) * $s;
		$rows = count($menu->rows());
		$visible = max(1, (int)floor(($height - 16 - 2 * $pad - $header - $footer) / $rowHeight));
		$count = min($visible, max(1, $rows));
		$h = $pad * 2 + $header + $footer + $count * $rowHeight;

		return [floor(($width - $w) / 2), floor(($height - $h) / 2), (float)$w, (float)$h];
	}

	/**
	 * Build one unanimated, pixel-filtered tile sprite anchored at its top-left corner.
	 *
	 * @param int $gid Tileset GID.
	 * @param float $x Output x of the tile's left edge.
	 * @param float $y Output y of the tile's top edge.
	 * @param float $scale Logical-to-output scale.
	 * @param array{float,float,float,float} $tint Palette tint.
	 * @return Sprite Configured sprite.
	 */
	private function tile(int $gid, float $x, float $y, float $scale, array $tint): Sprite
	{
		$sprite = new Sprite($gid, $x, $y, 0, 0);
		$sprite->scaleX = $sprite->scaleY = $scale;
		$sprite->animated = $sprite->outline = $sprite->smooth = false;
		$sprite->tint = $tint;

		return $sprite;
	}

	/**
	 * Append a cached bitmap-font label to a sprite batch.
	 *
	 * @param array $batch Destination sprite batch passed by reference.
	 * @param string $text Label contents.
	 * @param float $x Horizontal output position.
	 * @param float $y Vertical output position.
	 * @param float $scale Bitmap-font scale.
	 * @param string $align Horizontal alignment name.
	 * @param array{float,float,float} $colour Glyph tint.
	 * @return void Cloned glyph sprites are appended to the batch.
	 */
	private function label(
		array &$batch
		, string $text
		, float $x
		, float $y
		, float $scale
		, string $align = 'left'
		, array $colour = [1, 1, 1]
	): void {
		$key = json_encode([$text, $scale, $this->font->theme, $align, $colour]);

		if(!isset($this->cache[$key]))
		$this->cache[$key] = $this->font->layout($text, $scale, INF, [...$colour, 1.0]);

		if($align === 'right') $x -= $this->cache[$key]['width'];
		elseif($align === 'center') $x -= $this->cache[$key]['width'] / 2;

		foreach($this->cache[$key]['sprites'] as $cached)
		{
			$sprite = clone $cached;
			$sprite->x += $x;
			$sprite->y += $y;
			$batch[] = $sprite;
		}
	}

	/**
	 * Width of a label at font scale one, from the cached layout.
	 *
	 * @param string $text Label contents.
	 * @return float Width in output pixels at scale one.
	 */
	private function measure(string $text): float
	{
		$key = json_encode([$text, 1.0, $this->font->theme, 'measure']);

		if(!isset($this->cache[$key])) $this->cache[$key] = $this->font->layout($text, 1.0, INF, [1, 1, 1, 1]);

		return (float)$this->cache[$key]['width'];
	}

	/**
	 * Blend two RGB triples.
	 *
	 * @param array{float,float,float} $from Colour at zero.
	 * @param array{float,float,float} $to Colour at one.
	 * @param float $t Blend fraction.
	 * @return array{float,float,float} Interpolated colour.
	 */
	private static function lerp(array $from, array $to, float $t): array
	{
		return [
			$from[0] + ($to[0] - $from[0]) * $t
			, $from[1] + ($to[1] - $from[1]) * $t
			, $from[2] + ($to[2] - $from[2]) * $t
		];
	}

	/**
	 * Restore graphics resources after a WebGL context loss.
	 *
	 * @return void Every owned renderer and atlas is restored.
	 */
	public function restore(): void
	{
		$this->atlas->restore();
		$this->sprites->restore();
		$this->fontAtlas->restore();
		$this->text->restore();
		$this->rects->restore();
		$this->menus->restore();
	}

	/**
	 * Release graphics and menu resources.
	 *
	 * @return void Owned render resources are closed once.
	 */
	public function close(): void
	{
		$this->menus->close();
		$this->rects->close();
		$this->text->close();
		$this->fontAtlas->close();
		$this->sprites->close();
		$this->atlas->close();
	}
}
