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
use PhlappyPhant\Random;
use PhlappyPhant\Simulation;

/**
 * Draw the world into any viewport: a fixed logical height fills the frame and the width shows as much of the
 * world ahead as the aspect allows, so nothing is letterboxed.
 */
final class Scene
{
	public const GID_PHANT = 1;
	public const GID_PHANT_LEFT = 3;
	/** Day-palette tiles; each terrain tileset stacks its graded palettes after them, one stride apart. */
	public const GID_BACKDROP = 5;
	public const GID_PILLAR_CAP = 19;
	public const GID_PILLAR_BODY = 20;
	public const GID_PILLAR_BASE = 22;
	public const GID_GRASS = 47;
	public const GID_DIRT = 48;
	public const GID_SCENERY = 61;
	public const GID_CARDINAL = 82;
	/** The cardinal's speed across the frame, and the shortest and longest quiet spell between visits, in seconds. */
	public const CARDINAL_SPEED = 52;
	public const CARDINAL_WAIT = [14, 40];
	public const STRIDE_BACKDROP = 2;
	public const STRIDE_PILLAR = 4;
	public const STRIDE_GROUND = 2;
	public const STRIDE_SCENERY = 3;
	public const STRIDE_CARDINAL = 3;
	/** Seconds for each leg of the cycle: day to dusk, then dusk to night (and back the same way at dawn). */
	public const LEG_SECONDS = 1.4;
	/** The backdrop block, its tile size, and the ground tile size, in world units. */
	public const BACKDROP = 160;
	/** The backdrop draws at twice the world's pixel size, so its 160-pixel block spans 320 world units. */
	public const BACKDROP_SCALE = 2;
	public const GROUND_TILE = 32;
	/** Pillar slices are 64 wide with the 56-unit pillar centred, and 8 tall so any grid height can be built. */
	public const SLICE_W = 64;
	public const SLICE_H = 8;
	/** The elephant is not in the terrain sheets, so it takes a light tint to sit in each keyframe's light. */
	public const PHANT_TINTS = [[1, 1, 1], [1, .88, .8], [.72, .74, .95]];
	/** Scenery between each pair of pillars, as [scenery index, offset from the pillar's right edge], picked per pair. */
	public const SCENERY_LAYOUTS = [[], [[0, 24]], [[1, 16], [0, 56]], [[2, 32]], [[1, 40]], [[0, 8], [2, 48]]];
	public const INK = [.21, .20, .33];
	public const CREAM = [.95, .89, .75];
	/** Night grass from the terrain sheet, used behind every plaque glyph. */
	public const PLAQUE = [.141, .145, .227, .98];
	public const TEAL = [.18, .43, .45];
	/** Opacity of the ink plate under a menu's text. */
	public const PLATE_ALPHA = .92;
	/** The backdrop's pale water teal, for subtitles and information rows over the deep sky. */
	public const MIST = [.5, .835, .804];

	/** Accessible hit regions published by the menu renderer. */
	public array $regions = [];
	/** @var array<string,int|float> The open sky page's text block against the frame; empty when no such page is open. */
	public array $menuBounds = [];
	/** Native-resolution browser hint metadata for open-sky menu pages. */
	public ?array $hint = null;
	/** Output width and height, logical scale, and the phant's screen x. */
	public array $viewport = [0, 0, 1, 0];
	private Atlas $atlas;
	private Atlas $fontAtlas;
	private SpriteRenderer $sprites;
	private TerrainRenderer $terrain;
	private SpriteRenderer $text;
	private RectRenderer $rects;
	private MenuRenderer $menus;
	private array $cache = [];
	/** Position in the day cycle: zero is day, one is dusk (or dawn), two is night. */
	private float $blend = 0;
	/** The palette every world sprite draws in this frame, and how far the next one is faded over it. */
	private int $palette = 0;
	private float $fade = 0;
	/** Palettes per leg of the cycle: the keyframe and the in-betweens graded towards the next. */
	private int $steps = 1;
	/** @var list<array{float,float,float}> Sky colour above the backdrop for each palette. */
	private array $skies = [];
	private float $blendClock = 0;
	private float $deathSpin = 0;
	/** The cardinal's frame-relative position in world units while it is crossing, or null while the sky is empty. */
	private ?array $cardinal = null;
	private float $cardinalWait = 6;
	/** Scenery only: its own stream, so a visit never touches the seeded course. */
	private Random $cardinalRandom;
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
		$this->terrain = new TerrainRenderer($this->atlas);
		$this->cardinalRandom = new Random(2161);
		$this->text = new SpriteRenderer($this->fontAtlas);
		$this->rects = new RectRenderer;
		$this->menus = new MenuRenderer($font);
		$this->menus->style['padding'] = 10;
		$this->menus->style['panel'] = self::PLAQUE;
		// Plaque colors come straight from the terrain palettes: pale haze and cloud text over night grass, with the
		// day treeline and field for selection. Even the font's dark inset remains lighter than the panel beneath it.
		$this->menus->style['foreground'] = [...self::MIST, 1.0];
		$this->menus->style['selected'] = [.98, .973, .941, 1.0];
		$this->menus->style['highlight'] = [.145, .31, .349, 1.0];
		$this->menus->style['selection'] = [.184, .42, .353, 1.0];
		$this->menus->style['disabled'] = [.545, .549, .502, 1.0];

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
		$this->steps = max(1, (int)$property($map, 'phlappy.steps'));
		$this->skies = array_map(
			static fn(string $hex): array => array_map(static fn(string $pair): float => hexdec($pair) / 255, str_split(ltrim($hex, '#'), 2))
			, explode(',', $property($map, 'phlappy.skies'))
		);
	}

	/**
	 * Sky colour for the device clear, blended between day and night.
	 *
	 * @return array{float,float,float,float} RGBA clear colour.
	 */
	public function skyColor(): array
	{
		$from = $this->skies[$this->palette];
		$to = $this->skies[min(count($this->skies) - 1, $this->palette + 1)];

		return [...self::lerp($from, $to, $this->fade), 1.0];
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
		// Night is two legs away: the sky warms to dusk before it darkens, and morning runs the same path back through
		// dawn. Each leg eases in and out, so the light lingers at dusk on the way.
		$target = 2.0 * $game->palette;
		$step = $elapsed / self::LEG_SECONDS;
		$this->blend += max(-$step, min($step, $target - $this->blend));
		// The generator graded in-between palettes along each leg; the eased leg position picks the pair to fade.
		$legIndex = min(1, (int)floor($this->blend));
		$leg = $this->blend - $legIndex;
		$position = $this->steps * ($legIndex + $leg * $leg * (3 - 2 * $leg));
		$last = count($this->skies) - 1;
		$this->palette = min($last, (int)floor($position));
		$this->fade = $this->palette === $last ? 0.0 : $position - $this->palette;
		$keyframe = $position / $this->steps;
		$phantKey = min(1, (int)floor($keyframe));

		$tint = [1, 1, 1, 1];

		$terrain = [];
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
				$this->graded($terrain, $band, self::STRIDE_BACKDROP);
			}
		}

		// A cardinal crosses the sky now and then, right to left. It sets out only in full daylight; one already in the
		// air when dusk falls simply finishes its crossing. It flies behind the ground, scenery and pillars, so the
		// backdrop is drawn first and the rest of the terrain after it.
		$this->terrain->render($terrain, $width, $height, $seconds * 1000, $this->fade);
		$terrain = [];

		if($this->cardinal === null)
		{
			$this->cardinalWait -= $elapsed;

			if($this->cardinalWait <= 0 && $game->palette === 0 && $this->blend <= 0)
			$this->cardinal = [$visible + 8.0, 12.0 + $this->cardinalRandom->int(88)];
		}
		else
		{
			$this->cardinal[0] -= self::CARDINAL_SPEED * $elapsed;

			if($this->cardinal[0] < -40)
			{
				$this->cardinal = null;
				$this->cardinalWait = self::CARDINAL_WAIT[0]
					+ $this->cardinalRandom->int(self::CARDINAL_WAIT[1] - self::CARDINAL_WAIT[0] + 1);
			}
		}

		if($this->cardinal !== null)
		{
			// A gentle bob keeps the glide from looking like a slide. Draw the next graded palette over the current one
			// with the scene's fade so the bird changes light without popping between palette steps. Both sprites use the
			// same animation clock, keeping their wing frames aligned throughout the fade.
			$bird = $this->tile(
				self::GID_CARDINAL + self::STRIDE_CARDINAL * $this->palette
				, round($this->cardinal[0]) * $scale
				, $y($this->cardinal[1] + round(3 * sin($seconds * 2.2)))
				, $scale
				, $tint
			);
			$bird->animated = true;
			$birds = [$bird];

			if($this->fade > 0)
			{
				$next = clone $bird;
				$next->gid += self::STRIDE_CARDINAL;
				$next->tint[3] = $this->fade;
				$birds[] = $next;
			}

			$this->sprites->render($birds, $camera, $seconds * 1000);
		}

		for($x = -fmod($game->scroll, self::GROUND_TILE); $x < $visible; $x += self::GROUND_TILE)
		{
			$this->graded($terrain, $this->tile(self::GID_GRASS, $x * $scale, $y(Simulation::GROUND_Y), $scale, $tint), self::STRIDE_GROUND);
			$this->graded(
				$terrain
				, $this->tile(self::GID_DIRT, $x * $scale, $y(Simulation::GROUND_Y + self::GROUND_TILE), $scale, $tint)
				, self::STRIDE_GROUND
			);
		}

		$pillars = [];

		foreach($game->pillars as $pillar)
		{
			$wx = $phantX + $pillar->previousX + ($pillar->x - $pillar->previousX) * $alpha;

			// Scenery stands on the ground between this pair and the next, on the 8-unit grid.
			foreach(self::SCENERY_LAYOUTS[($pillar->index * 5 + intdiv($pillar->index, 3)) % count(self::SCENERY_LAYOUTS)] as [$piece, $offset])
			{
				$sx = $wx + Simulation::PILLAR_W + $offset;

				if($sx > $visible || $sx + $this->sceneryWidths[$piece] < 0) continue;

				$scenery = $this->tile(self::GID_SCENERY + $piece, $sx * $scale, $y(Simulation::GROUND_Y), $scale, $tint);
				$scenery->originY = 1;
				$this->graded($terrain, $scenery, self::STRIDE_SCENERY);
			}

			$left = $wx - (self::SLICE_W - Simulation::PILLAR_W) / 2;

			if($left > $visible || $left + self::SLICE_W < 0) continue;

			$pillars[] = [$left, $pillar];
		}

		// Each pair is the same pillar twice: one standing on the ground, and one hanging from the sky with its grass
		// cap turned to face the gap. Wall slices alternate so the 16-row dirt pattern runs on from the cap.
		foreach($pillars as [$left, $pillar])
		{
			$sx = $left * $scale;
			$hanging = function(int $gid, float $worldY) use ($sx, $y, $scale, $tint): Sprite {
				$slice = $this->tile($gid, $sx, $y($worldY), $scale, $tint);
				$slice->flipY = true;

				return $slice;
			};

			$this->graded($terrain, $hanging(self::GID_PILLAR_CAP, $pillar->gapTop - self::SLICE_H), self::STRIDE_PILLAR);

			for($wy = $pillar->gapTop - 2 * self::SLICE_H, $n = 0; $wy + $offsetY > -self::SLICE_H; $wy -= self::SLICE_H, $n++)
			$this->graded($terrain, $hanging(self::GID_PILLAR_BODY + $n % 2, $wy), self::STRIDE_PILLAR);

			$top = $pillar->gapTop + $pillar->gap;
			$this->graded($terrain, $this->tile(self::GID_PILLAR_CAP, $sx, $y($top), $scale, $tint), self::STRIDE_PILLAR);

			for($wy = $top + self::SLICE_H, $n = 0; $wy < Simulation::GROUND_Y; $wy += self::SLICE_H, $n++)
			$this->graded($terrain, $this->tile(self::GID_PILLAR_BODY + $n % 2, $sx, $y($wy), $scale, $tint), self::STRIDE_PILLAR);

			$this->graded($terrain, $this->tile(self::GID_PILLAR_BASE, $sx, $y(Simulation::GROUND_Y), $scale, $tint), self::STRIDE_PILLAR);
		}

		// The title uses the same waiting phant as the ready screen, bobbing over the scrolling preview.
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
		$phant->tint = [...self::lerp(self::PHANT_TINTS[$phantKey], self::PHANT_TINTS[$phantKey + 1], $keyframe - $phantKey), 1.0];

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

		$this->terrain->render($terrain, $width, $height, $seconds * 1000, $this->fade);
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
				$bestLabel = 'BEST ' . $best;
				$readyScale = (float)max($k, min(2 * $k, (int)floor(
					($width - 8 * $k) / max($this->measure('TAP TO FLAP'), $this->measure($bestLabel))
				)));
				$readyY = $height * .59;
				$this->shadowed($labels, 'TAP TO FLAP', $width / 2, $readyY, $readyScale, 'center');
				$this->shadowed($labels, $bestLabel, $width / 2, $readyY + 20 * $readyScale, $readyScale, 'center');
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
		$this->hint = null;
		$this->menuBounds = [];

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
		$avail = $width - 24 * $k;
		$logo = max(1, min(2 * $k + 1, (int)floor($avail / max(1, $this->measure('PHLAPPY PHANT')))));
		// The front page hangs its choices under the wordmark; every other page has the frame from a small top margin
		// down to the hint line.
		$menuTop = $front ? (int)floor($height * .11 + 16 * $logo + 16 * $k) : 8 * $k;
		$menuBottom = $height - ($front ? 54 : 44) * $k;
		$room = $menuBottom - $menuTop;

		// Pick the largest whole scale at which the rows, wrapped to the width, fit that room. Information rows wrap
		// and pack more tightly than choices, which keep a touch-sized row; the tightest pitch is the last resort.
		foreach([[$k + 1, 13], [$k, 13], [1, 13], [1, 11]] as [$r, $pitch])
		{
			if($r < 1) continue;

			$wrapped = [];
			$heights = [];
			$widest = 1.0;
			$actionWraps = false;

			foreach($rows as $id => $item)
			{
				$lines = $this->wrap((string)$item['label'], $avail / $r - $chevron);
				$wrapped[$id] = $lines;
				$heights[$id] = ($item['disabled'] ? $pitch * count($lines) + 3 : 18 + $pitch * (count($lines) - 1)) * $r;
				$actionWraps = $actionWraps || (count($lines) > 1 && !$item['disabled']);

				foreach($lines as $line) $widest = max($widest, $this->measure($line));
			}

			$titleLimit = $front || $pitch < 13 ? $r : 2 * $r;
			$titleScale = max(1, min($titleLimit, (int)floor($avail / max(1, $this->measure($title)))));
			$titleHeight = 16 * $titleScale + ($pitch < 13 ? 4 : 10) * $r;
			$blockHeight = $titleHeight + array_sum($heights);

			// A choice stays on one line unless nothing else fits.
			if(!$actionWraps && $blockHeight <= $room && ($widest + $chevron) * $r <= $avail) break;
		}

		$blockWidth = ($widest + $chevron) * $r;
		// Centered in its room, and never above it: a page that is still too tall keeps its title on screen.
		$top = max($menuTop, min((int)floor(($menuTop + $menuBottom - $blockHeight) / 2), $menuBottom - $blockHeight));

		if($front) $top = max($menuTop, min($top, $menuBottom - $blockHeight));

		$x = (int)floor(($width - $blockWidth) / 2);
		$rects = [];

		// Only the front page shows the world undimmed; every other page dims it.
		if(!$front) $rects[] = [0, 0, $width, $height, self::INK[0], self::INK[1], self::INK[2], .4];

		// The text stands on a near-solid ink plate, so its contrast never depends on the sky, cloud or field that
		// happens to be behind it: cream on the plate is at least 7:1 and the information color at least 5:1.
		$margin = 8 * $r;
		$plate = [max(0, $x - 6 * $r - $margin), max(0, $top - $margin), $blockWidth + 12 * $r + 2 * $margin, $blockHeight + 2 * $margin];
		$plate[2] = min($plate[2], $width - $plate[0]);
		$plate[3] = min($plate[3], $height - $plate[1]);
		$rects[] = [$plate[0] + $r, $plate[1] + $r, $plate[2], $plate[3], self::INK[0], self::INK[1], self::INK[2], .5];
		$rects[] = [$plate[0], $plate[1], $plate[2], $plate[3], self::INK[0], self::INK[1], self::INK[2], self::PLATE_ALPHA];
		// What the menu needs against what the frame offers, for tests that audit every page for cropped text.
		$this->menuBounds = [
			'left' => $x - 6 * $r, 'top' => $top, 'right' => $x + $blockWidth + 6 * $r, 'bottom' => $top + $blockHeight
			, 'width' => $width, 'height' => $height, 'scale' => $r
		];

		$labels = [];
		$shadow = (float)$r;

		$this->shadowed($labels, $title, $width / 2, (float)$top, (float)$titleScale, 'center', $front ? self::MIST : self::CREAM);

		$y = $top + $titleHeight;
		$pad = 6 * $r;
		$blink = fmod($seconds, .9) < .6;

		foreach($rows as $id => $item)
		{
			$selected = $menu->selected === $id && !$item['disabled'];
			$lines = $wrapped[$id];
			$rowHeight = $heights[$id];
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
				$lineY = (float)($y + $pitch * $n * $r);

				$this->shadowed($labels, $line, $textX, $lineY, (float)$r, 'left', $colour);
			}

			// Information rows are text, not controls: they take no selection and publish no button.
			if(!$item['disabled']) $this->regions[] = [
				'id' => (string)$id
				, 'label' => (string)$item['label']
				, 'disabled' => false
				, 'rect' => $rect
			];

			$y += $rowHeight;
		}

		// The browser draws this small mixed-density line at display resolution. Rendering the four-times-denser key
		// art into the reduced world buffer first would discard detail before the host enlarges the pixel-art canvas.
		$hint = $menu->depth() > 0 ? '✚ MOVE  ⓿ SELECT  ❶ BACK' : '✚ MOVE  ⓿ SELECT';
		$hintScale = max(1, min($k, (int)floor(($width - 16) / max(1, $this->measure($hint)))));
		$this->hint = [
			'back' => $menu->depth() > 0
			, 'scale' => $hintScale
			, 'y' => $height - 34 * $k
		];

		$this->rects->render($rects, $width, $height);
		$this->text->render($labels, $camera);
	}

	/**
	 * Break a label into lines no wider than a limit at font scale one, on spaces.
	 *
	 * @param string $text Label contents.
	 * @param float $limit Maximum line width in scale-one pixels.
	 * @return list<string> At least one line; a word longer than the limit is split across lines.
	 */
	private function wrap(string $text, float $limit): array
	{
		if($this->measure($text) <= $limit) return [$text];

		$lines = [];
		$line = '';

		$words = [];

		// A single word wider than the limit is split, so nothing can run past the frame.
		foreach(explode(' ', $text) as $word)
		{
			while($this->measure($word) > $limit && strlen($word) > 1)
			{
				$cut = strlen($word) - 1;

				while($cut > 1 && $this->measure(substr($word, 0, $cut)) > $limit) $cut--;

				// Prefer to break after a hyphen, as in a license identifier.
				$hyphen = strrpos(substr($word, 0, $cut), '-');

				if($hyphen !== false && $hyphen > 0) $cut = $hyphen + 1;

				$words[] = substr($word, 0, $cut);
				$word = substr($word, $cut);
			}

			$words[] = $word;
		}

		foreach($words as $word)
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
	/**
	 * Append a terrain sprite in this frame's keyframe palette, with the next palette faded over it mid-transition.
	 *
	 * Every terrain sheet keeps the same shapes, so drawing the next palette over the current one at the eased
	 * fraction interpolates each pixel between the two hand-graded colours.
	 *
	 * @param list<array{Sprite,int}> $terrain Batch to append to, in painter's order.
	 * @param Sprite $sprite Sprite built with its day-palette GID.
	 * @param int $stride Tiles per palette in the sprite's tileset.
	 * @return void Appends one sprite and its palette stride for the one-pass terrain shader.
	 */
	private function graded(array &$terrain, Sprite $sprite, int $stride): void
	{
		$sprite->gid += $stride * $this->palette;
		$terrain[] = [$sprite, $stride];
	}

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
		$this->terrain->restore();
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
		$this->terrain->close();
		$this->atlas->close();
	}
}
