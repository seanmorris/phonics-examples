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
	public const GID_BACKDROP = 5;
	public const GID_TOWER_CAP = 7;
	public const GID_TOWER_BODY = 8;
	public const GID_TOWER_BASE = 10;
	public const GID_GRASS = 11;
	public const GID_DIRT = 12;
	public const GID_SCENERY = 13;
	/** The backdrop block, its tile size, and the ground tile size, in world units. */
	public const BACKDROP = 160;
	/** The backdrop draws at twice the world's pixel size, so its 160-pixel block spans 320 world units. */
	public const BACKDROP_SCALE = 2;
	public const GROUND_TILE = 32;
	/** Tower slices are 64 wide with the 56-unit tower centred, and 8 tall so any grid height can be built. */
	public const SLICE_W = 64;
	public const SLICE_H = 8;
	/** The backdrop's top row, continued up into the sky of tall frames; night multiplies it like every tile. */
	public const DAY_SKY = [.282, .4, .773];
	public const NIGHT_TINT = [.55, .60, .85];
	public const NIGHT_SKY = [.155, .24, .657];
	/** Scenery between each pair of towers, as [scenery index, offset from the tower's right edge], picked per pair. */
	public const SCENERY_LAYOUTS = [[], [[0, 24]], [[1, 16], [0, 56]], [[2, 32]], [[1, 40]], [[0, 8], [2, 48]]];
	public const INK = [.21, .20, .33];
	public const CREAM = [.95, .89, .75];
	public const PLAQUE = [.95, .89, .75, .97];
	public const TEAL = [.18, .43, .45];
	/** The backdrop's pale water teal, for subtitles and information rows over the deep sky. */
	public const MIST = [.5, .835, .804];

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
	/** @var list<array{int,int,float}> Backdrop bands as [top, height, parallax rate], read from the backdrop tileset. */
	private array $bands = [];
	/** @var list<int> Width of each scenery piece, read from the scenery tileset. */
	private array $sceneryWidths = [];

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

		// The generator writes the parallax mask's band edges, the chosen rates and the scenery widths into the
		// tilesets, so the art and its layout data travel together.
		$property = static function(TileMap $map, string $name): string {
			foreach($map->tilesets as $set) foreach($set['properties'] ?? [] as $entry)
			if($entry['name'] === $name) return (string)$entry['value'];

			throw new \UnexpectedValueException("The Phlappy Phant tilesets do not define $name");
		};
		$edges = array_map('intval', explode(',', $property($map, 'phlappy.bands')));
		$rates = array_map('floatval', explode(',', $property($map, 'phlappy.parallax')));

		if(count($edges) !== count($rates) + 1) throw new \UnexpectedValueException('Backdrop bands and rates disagree');

		foreach($rates as $index => $rate) $this->bands[] = [$edges[$index], $edges[$index + 1] - $edges[$index], $rate];

		$this->sceneryWidths = array_map('intval', explode(',', $property($map, 'phlappy.widths')));
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

		$sprites = [];
		$y = static fn(float $worldY): float => ($worldY + $offsetY) * $scale;

		// The backdrop sits on the ground line; each band of the parallax mask drifts at its own rate, and the tile's
		// animation swaps the river frames.
		$backdropSpan = self::BACKDROP * self::BACKDROP_SCALE;
		$backdropTop = Simulation::GROUND_Y - $backdropSpan;

		foreach($this->bands as [$top, $bandHeight, $rate])
		{
			for($x = -fmod($game->scroll * $rate, $backdropSpan); $x < $visible; $x += $backdropSpan)
			{
				$band = $this->tile(
					self::GID_BACKDROP
					, $x * $scale
					, $y($backdropTop + $top * self::BACKDROP_SCALE)
					, $scale * self::BACKDROP_SCALE
					, $tint
				);
				$band->crop = [0, $top, self::BACKDROP, $bandHeight];
				$band->animated = true;
				$sprites[] = $band;
			}
		}

		for($x = -fmod($game->scroll, self::GROUND_TILE); $x < $visible; $x += self::GROUND_TILE)
		{
			$sprites[] = $this->tile(self::GID_GRASS, $x * $scale, $y(Simulation::GROUND_Y), $scale, $tint);
			$sprites[] = $this->tile(self::GID_DIRT, $x * $scale, $y(Simulation::GROUND_Y + self::GROUND_TILE), $scale, $tint);
		}

		$towers = [];

		foreach($game->towers as $tower)
		{
			$wx = $phantX + $tower->previousX + ($tower->x - $tower->previousX) * $alpha;

			// Scenery stands on the ground between this pair and the next, on the 8-unit grid.
			foreach(self::SCENERY_LAYOUTS[($tower->index * 5 + intdiv($tower->index, 3)) % count(self::SCENERY_LAYOUTS)] as [$piece, $offset])
			{
				$sx = $wx + Simulation::TOWER_W + $offset;

				if($sx > $visible || $sx + $this->sceneryWidths[$piece] < 0) continue;

				$scenery = $this->tile(self::GID_SCENERY + $piece, $sx * $scale, $y(Simulation::GROUND_Y), $scale, $tint);
				$scenery->originY = 1;
				$sprites[] = $scenery;
			}

			$left = $wx - (self::SLICE_W - Simulation::TOWER_W) / 2;

			if($left > $visible || $left + self::SLICE_W < 0) continue;

			$towers[] = [$left, $tower];
		}

		// Each pair is the same tower twice: one standing on the ground, and one hanging from the sky with its grass
		// cap turned to face the gap. Wall slices alternate so the 16-row dirt pattern runs on from the cap.
		foreach($towers as [$left, $tower])
		{
			$sx = $left * $scale;
			$hanging = function(int $gid, float $worldY) use ($sx, $y, $scale, $tint): Sprite {
				$slice = $this->tile($gid, $sx, $y($worldY), $scale, $tint);
				$slice->flipY = true;

				return $slice;
			};

			$sprites[] = $hanging(self::GID_TOWER_CAP, $tower->gapTop - self::SLICE_H);

			for($wy = $tower->gapTop - 2 * self::SLICE_H, $n = 0; $wy + $offsetY > -self::SLICE_H; $wy -= self::SLICE_H, $n++)
			$sprites[] = $hanging(self::GID_TOWER_BODY + $n % 2, $wy);

			$top = $tower->gapTop + $tower->gap;
			$sprites[] = $this->tile(self::GID_TOWER_CAP, $sx, $y($top), $scale, $tint);

			for($wy = $top + self::SLICE_H, $n = 0; $wy < Simulation::GROUND_Y; $wy += self::SLICE_H, $n++)
			$sprites[] = $this->tile(self::GID_TOWER_BODY + $n % 2, $sx, $y($wy), $scale, $tint);

			$sprites[] = $this->tile(self::GID_TOWER_BASE, $sx, $y(Simulation::GROUND_Y), $scale, $tint);
		}

		// The title page belongs to the wordmark and the menu; the phant appears on the ready screen.
		if($page !== 'title')
		{
			$py = $game->previousY + ($game->y - $game->previousY) * $alpha;
			// The tumble uses the left-facing frames rather than a flip, so the lettering never mirrors.
			$phant = new Sprite(
				($game->phase === 'dead' ? self::GID_PHANT_LEFT : self::GID_PHANT) + $game->frame
				, ($phantX + Simulation::PHANT_W / 2) * $scale
				, ($py + Simulation::PHANT_H / 2 + $offsetY) * $scale
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

		$this->sprites->render($sprites, $camera, $seconds * 1000);

		$labels = [];
		// Text scales are whole numbers too, so glyph strokes stay even.
		$textScale = (float)$k;

		if($page === 'play')
		{
			$this->shadowed($labels, (string)$game->score, $width / 2, $height * .07, $textScale * 2, 'center');

			// The only tell that the perfect player is flying: one world pixel in the corner winks lighter now and then.
			if($game->autopilot && fmod($seconds, 2.5) < .1) $this->rects->render([[$k, $k, $k, $k, 1, 1, 1, 1]], $width, $height);

			if($game->phase === 'ready')
			{
				$this->shadowed($labels, 'TAP TO FLAP', $width / 2, $height * .62, $textScale, 'center');
				$this->shadowed($labels, 'BEST ' . $best, $width / 2, $height * .69, $textScale, 'center');
			}
			elseif($game->phase === 'dead')
			{
				$this->shadowed($labels, 'BONK', $width / 2, $height * .45, $textScale * 2, 'center');
			}
		}

		if($page === 'title' && $menu->depth() === 0)
		{
			// The wordmark fills up to the frame's width minus a margin at a whole-number scale, so phones get a
			// smaller logo rather than a clipped or resampled one.
			$logo = (float)max(1, min(2 * $k + 1, (int)floor(($width - 24 * $scale) / max(1, $this->measure('PHLAPPY PHANT')))));
			$this->shadowed($labels, 'PHLAPPY PHANT', $width / 2, $height * .11, $logo, 'center');
		}

		$this->text->render($labels, $camera);

		$this->regions = [];

		if($menu->isOpen)
		{
			if($this->needsPlaque($menu)) $this->plaquePage($menu, $width, $height, $seconds, $k);
			else $this->skyPage($menu, $page, $width, $height, $seconds, $k, $camera);
		}
	}

	/**
	 * Whether the open page needs the shared plaque renderer: value columns and text editing live there.
	 *
	 * @param Menu $menu Open menu.
	 * @return bool True for the bindings editor and any page with input rows.
	 */
	private function needsPlaque(Menu $menu): bool
	{
		if($menu->editor !== null) return true;

		foreach($menu->rows() as $item) if(($item['input'] ?? '') !== '') return true;

		return false;
	}

	/**
	 * Draw a page as the shared cream plaque, for the bindings editor.
	 *
	 * @param Menu $menu Open menu.
	 * @param int $width Output width in pixels.
	 * @param int $height Output height in pixels.
	 * @param float $seconds Elapsed UI time.
	 * @param int $k World pixel scale.
	 * @return void Publishes the plaque's hit regions.
	 */
	private function plaquePage(Menu $menu, int $width, int $height, float $seconds, int $k): void
	{
		// The shared menu renderer scales by min(style scale, width / 320, height / 180); handing it the whole number
		// below that keeps its glyphs crisp, and the plaque width follows so the hint row fits.
		$m = max(1, min($k, 3, (int)floor(min($width / 320, $height / 180))));
		$this->menus->style['scale'] = $m;
		$this->menus->style['width'] = 330 * $m;
		$this->menus->showHints = $m >= 2 && $width >= 330 * $m - 10;
		$this->menus->style['dimmer'] = [...self::INK, .35];
		[$px, $py, $pw, $ph] = $this->plaque($menu, $width, $height);
		$edge = max(2, round(2 * $k));
		$drop = max(3, round(4 * $k));
		$this->rects->render([
			[$px - $edge + $drop, $py - $edge + $drop, $pw + 2 * $edge, $ph + 2 * $edge, self::INK[0], self::INK[1], self::INK[2], .45]
			, [$px - $edge, $py - $edge, $pw + 2 * $edge, $ph + 2 * $edge, self::INK[0], self::INK[1], self::INK[2], 1]
		], $width, $height);
		$this->menus->render($menu, $width, $height, $seconds);
		$this->regions = $this->menus->regions;
	}

	/**
	 * Draw a page in the open sky: big cream pixel text with the wordmark's ink shadow, the selected item on a teal
	 * band behind a blinking chevron, informational rows in teal, and a themed hint line along the bottom.
	 *
	 * @param Menu $menu Open menu.
	 * @param string $page Current application page.
	 * @param int $width Output width in pixels.
	 * @param int $height Output height in pixels.
	 * @param float $seconds Elapsed UI time, for the chevron blink.
	 * @param int $k World pixel scale.
	 * @param Camera $camera Screen camera.
	 * @return void Publishes one hit region per row.
	 */
	private function skyPage(Menu $menu, string $page, int $width, int $height, float $seconds, int $k, Camera $camera): void
	{
		$rows = $menu->rows();
		$chevron = $this->measure('> ');
		$title = (string)$menu->title;
		$front = $page === 'title' && $menu->depth() === 0;
		// The undimmed title pages keep every row above the backdrop's dark strip, over the sky, clouds and field where
		// the ink shadows carry the text; the front page also starts under the wordmark.
		$sky = $page === 'title' ? (int)floor(($height / $k - Simulation::HEIGHT + Simulation::GROUND_Y - self::BACKDROP_SCALE * (self::BACKDROP - $this->bands[4][0])) * $k) : $height;
		$avail = $width - 24 * $k;
		$logo = max(1, min(2 * $k + 1, (int)floor($avail / max(1, $this->measure('PHLAPPY PHANT')))));
		$underLogo = $front ? (int)floor($height * .11 + 16 * $logo + 16 * $k) : 0;
		$room = $front ? $sky - $underLogo - 4 * $k : $sky - 16 * $k;

		// Pick the largest whole scale at which the rows, wrapped to the width, still fit in the sky; long
		// informational lines wrap rather than shrinking every row to the width of the longest one.
		for($r = $k + 1; $r >= 1; $r--)
		{
			$wrapped = [];
			$widest = 1.0;
			$lineCount = 0;

			$actionWraps = false;

			foreach($rows as $id => $item)
			{
				$lines = $this->wrap((string)$item['label'], $avail / $r - $chevron);
				$wrapped[$id] = $lines;
				$lineCount += count($lines);
				$actionWraps = $actionWraps || (count($lines) > 1 && !$item['disabled']);

				foreach($lines as $line) $widest = max($widest, $this->measure($line));
			}

			$titleScale = $front ? $r : max(1, min(2 * $r, (int)floor($avail / max(1, $this->measure($title)))));
			$titleHeight = 16 * $titleScale + 10 * $r;
			$blockHeight = $titleHeight + (count($rows) * 18 + ($lineCount - count($rows)) * 13) * $r;

			// Informational rows may wrap; a choice stays on one line unless nothing else fits.
			if($r === 1 || (!$actionWraps && $blockHeight <= $room && ($widest + $chevron) * $r <= $avail)) break;
		}

		$blockWidth = ($widest + $chevron) * $r;
		$top = (int)floor(($sky - $blockHeight) / 2);

		if($front)
		{
			// Hang just below the wordmark, never climbing into it.
			$top = max($underLogo, min($top, $sky - $blockHeight - 4 * $k));
		}

		$x = (int)floor(($width - $blockWidth) / 2);
		$rects = [];

		// Only the front page shows the world undimmed; every other page sits its text on the dimmed backdrop.
		if(!$front) $rects[] = [0, 0, $width, $height, self::INK[0], self::INK[1], self::INK[2], .4];

		$labels = [];
		$shadow = (float)$r;

		$this->shadowed($labels, $title, $width / 2, (float)$top, (float)$titleScale, 'center', $front ? self::MIST : self::CREAM);

		$y = $top + $titleHeight;
		$pad = 6 * $r;
		$blink = fmod($seconds, .9) < .6;

		foreach($rows as $id => $item)
		{
			$selected = $menu->selected === $id;
			$lines = $wrapped[$id];
			$rowHeight = (18 + 13 * (count($lines) - 1)) * $r;
			$rect = [$x - $pad, $y - 2 * $r, $blockWidth + 2 * $pad, $rowHeight];

			if($selected)
			{
				$rects[] = [$rect[0] + $shadow, $rect[1] + $shadow, $rect[2], $rect[3], self::INK[0], self::INK[1], self::INK[2], .6];
				$rects[] = [$rect[0], $rect[1], $rect[2], $rect[3], self::TEAL[0], self::TEAL[1], self::TEAL[2], 1];

				if($blink) $this->label($labels, '>', (float)$x, (float)$y, (float)$r, 'left', self::CREAM);
			}

			$colour = $item['disabled'] ? self::MIST : ($selected ? [1, 1, 1] : self::CREAM);
			$textX = (float)($x + $chevron * $r);

			foreach($lines as $n => $line)
			{
				$lineY = (float)($y + 13 * $n * $r);

				$this->shadowed($labels, $line, $textX, $lineY, (float)$r, 'left', $colour);
			}

			$this->regions[] = [
				'id' => (string)$id
				, 'label' => (string)$item['label']
				, 'disabled' => (bool)$item['disabled']
				, 'rect' => $rect
			];

			$y += $rowHeight;
		}

		// Above the host's quick controls, which sit in the bottom corner, and never wider than the frame.
		$hint = $menu->depth() > 0 ? '✚ MOVE  ⓿ SELECT  ❶ BACK' : '✚ MOVE  ⓿ SELECT';
		$hintScale = max(1, min($k, (int)floor(($width - 16) / max(1, $this->measure($hint)))));
		$this->shadowed($labels, $hint, $width / 2, (float)($height - 34 * $k), (float)$hintScale, 'center');

		$this->rects->render($rects, $width, $height);
		$this->text->render($labels, $camera);
	}

	/**
	 * Break a label into lines no wider than a limit at font scale one, on spaces.
	 *
	 * @param string $text Label contents.
	 * @param float $limit Maximum line width in scale-one pixels.
	 * @return list<string> At least one line; a single word longer than the limit stays whole.
	 */
	private function wrap(string $text, float $limit): array
	{
		if($this->measure($text) <= $limit) return [$text];

		$lines = [];
		$line = '';

		foreach(explode(' ', $text) as $word)
		{
			$candidate = $line === '' ? $word : $line . ' ' . $word;

			if($line !== '' && $this->measure($candidate) > $limit)
			{
				$lines[] = $line;
				$line = $word;
			}
			else $line = $candidate;
		}

		if($line !== '') $lines[] = $line;

		return $lines;
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
	 * Lay out a label over its own ink drop shadow, offset by one scaled pixel, so it reads on any sky.
	 *
	 * @param list<Sprite> $batch Sprite batch to append to.
	 * @param string $text Label contents.
	 * @param float $x Horizontal output position.
	 * @param float $y Vertical output position.
	 * @param float $scale Bitmap-font scale.
	 * @param string $align Horizontal alignment name.
	 * @param array{float,float,float} $colour Face tint; the shadow is always ink.
	 * @return void Shadow then face glyphs are appended to the batch.
	 */
	private function shadowed(
		array &$batch
		, string $text
		, float $x
		, float $y
		, float $scale
		, string $align = 'left'
		, array $colour = self::CREAM
	): void {
		$this->label($batch, $text, $x + $scale, $y + $scale, $scale, $align, self::INK);
		$this->label($batch, $text, $x, $y, $scale, $align, $colour);
	}

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
