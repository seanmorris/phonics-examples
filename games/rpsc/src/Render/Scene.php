<?php
declare(strict_types=1);
namespace Rpsc\Render;

use Phonics\Render\Atlas;
use Phonics\Render\BitmapFont;
use Phonics\Render\Camera;
use Phonics\Render\RectRenderer;
use Phonics\Render\Sprite;
use Phonics\Render\SpriteRenderer;
use Phonics\Tiled\TileMap;
use Phonics\Ui\Menu;
use Phonics\Ui\MenuRenderer;
use Rpsc\Board;
use Rpsc\Ui\Table;

/**
 * Draws the table: the board, the pieces as the table poses them, both sides' rows, the buttons, and the menu.
 *
 * The art is pixel art, so everything is drawn at a whole-number scale: the largest that fits the board and both
 * sides' rows in the frame, stacked on a tall frame or side by side on a wide one. Text uses about half that scale,
 * also a whole number. The player's own side is always at the bottom, so Red sees the board turned round.
 *
 * Each frame also publishes the regions a pointer or a screen reader can press: one per piece, one per square the
 * selected piece can reach, and one per button.
 */
final class Scene
{
	/** Edge of one board square in art pixels. */
	public const TILE = 20;
	/** Narrowest frame, in art pixels, that still holds the board and its border. */
	public const MIN_WIDTH = 164;

	private const BACKGROUND = [.066, .074, .094, 1.0];
	private const WOOD = [.36, .22, .13];
	private const WOOD_LIGHT = [.5, .33, .2];
	private const WOOD_DARK = [.2, .12, .07];
	private const CREAM = [.96, .92, .82];
	private const DIM = [.6, .59, .57];
	private const GOLD = [1.0, .86, .5];
	private const SIDE_TEXT = [[.78, .78, .86], [.95, .47, .4]];
	private const BUTTON = [.19, .21, .27];
	private const BUTTON_EDGE = [.42, .45, .53];

	/**
	 * @var list<array<string,mixed>> Pressable regions from the latest render: `id`, `label`, and `rect` in frame pixels.
	 */
	public array $regions = [];
	/**
	 * @var array<string,mixed> Geometry of the latest render: `mode`, `scale`, `text`, and the board's `x`, `y`, and `size`.
	 */
	public array $layout = [];
	/** Height in frame pixels to keep clear at the top right, where the page sets its mute and fullscreen buttons. */
	public int $reserve = 0;

	private Atlas $atlas;
	private Atlas $fontAtlas;
	private SpriteRenderer $sprites;
	private SpriteRenderer $text;
	private RectRenderer $rects;
	private MenuRenderer $menus;
	/** @var array<string,int> Global tile id for each named tile of the pieces sheet. */
	private array $tiles = [];
	/** @var array<string,array<string,mixed>> Text layouts by content, scale, and colour. */
	private array $cache = [];

	/**
	 * Prepare the sprite, text, rectangle, and menu renderers.
	 *
	 * @param BitmapFont $font Shared bitmap font for the rows, the buttons, and the menu.
	 * @param TileMap $map Map whose tileset is the pieces sheet.
	 * @return void Renderer resources are held until close() is called.
	 */
	public function __construct(private BitmapFont $font, TileMap $map)
	{
		$this->atlas = new Atlas($map);
		$this->fontAtlas = new Atlas($font->map);
		$this->sprites = new SpriteRenderer($this->atlas);
		$this->text = new SpriteRenderer($this->fontAtlas);
		$this->rects = new RectRenderer;
		$this->menus = new MenuRenderer($font);
		$this->menus->style['panel'] = [.05, .055, .075, .96];
		$this->menus->style['foreground'] = [...self::CREAM, 1.0];
		$this->menus->style['selected'] = [...self::GOLD, 1.0];
		$this->menus->style['selection'] = [.56, .15, .14, 1.0];
		$this->menus->style['highlight'] = [.17, .18, .23, 1.0];
		$this->menus->style['disabled'] = [...self::DIM, 1.0];
		$this->menus->style['dimmer'] = [0, 0, 0, .55];

		// The generator names every tile in the tileset, so this class never hard-codes a position on the sheet.
		foreach($map->tilesets as $set) foreach($set['properties'] ?? [] as $entry)
		{
			if($entry['name'] !== 'rpsc.tiles') continue;

			foreach(explode(',', (string)$entry['value']) as $index => $name) $this->tiles[$name] = (int)$set['firstgid'] + $index;
		}

		if(!isset($this->tiles['square-red'], $this->tiles['black-back'], $this->tiles['king']))
		{
			throw new \UnexpectedValueException('The RPSC tileset does not name its tiles');
		}
	}

	/**
	 * Colour to clear the frame with before render().
	 *
	 * @return array{float,float,float,float} RGBA clear colour.
	 */
	public function background(): array
	{
		return self::BACKGROUND;
	}

	/**
	 * Draw one frame.
	 *
	 * @param Table $table Pieces, selection, cursor, and status to draw.
	 * @param Menu $menu Menu drawn over the table while it is open.
	 * @param list<array{id:string,label:string,enabled?:bool}> $buttons Buttons for the player's row, left to right.
	 * @param bool $rows Whether to draw both sides' rows and the status line; the title draws the board alone.
	 * @param int $width Frame width in pixels.
	 * @param int $height Frame height in pixels.
	 * @param float $seconds Interface clock, for pulses and the menu's caret.
	 * @return void The frame is drawn, and `$regions` and `$layout` describe it.
	 */
	public function render(Table $table, Menu $menu, array $buttons, bool $rows, int $width, int $height, float $seconds): void
	{
		$view = $table->view();
		$human = $view['human'] ?? Board::BLACK;
		$fit = $this->fit($width, $height);
		['scale' => $k, 'text' => $t, 'tile' => $tile, 'size' => $size, 'border' => $border, 'line' => $line, 'gap' => $gap
			, 'mini' => $mini, 'row' => $rowHeight, 'button' => $buttonHeight, 'x' => $left, 'y' => $top] = $fit;
		$this->layout = ['mode' => $fit['mode'], 'scale' => $k, 'text' => $t, 'x' => $left, 'y' => $top, 'size' => $size, 'flipped' => $human === Board::RED];
		$this->regions = [];
		$camera = new Camera(0, 0, $width, $height);
		$pulse = .5 + .5 * sin($seconds * 5);

		// Where a square is drawn: the player's own back rank is always the bottom row.
		$place = static function(float $x, float $y) use ($human, $left, $top, $tile): array {
			$column = $human === Board::RED ? Board::SIZE - 1 - $x : $x;
			$row = $human === Board::RED ? $y : Board::SIZE - 1 - $y;

			return [$left + ($column + .5) * $tile, $top + ($row + .5) * $tile];
		};

		$rects = [
			[$left - $border, $top - $border, $size + 2 * $border, $size + 2 * $border, ...self::WOOD_DARK, 1]
			, [$left - $border, $top - $border, $size + 2 * $border - $k, $size + 2 * $border - $k, ...self::WOOD_LIGHT, 1]
			, [$left - $border + $k, $top - $border + $k, $size + 2 * $border - 2 * $k, $size + 2 * $border - 2 * $k, ...self::WOOD, 1]
		];
		$squares = [];
		$occupied = [];

		foreach($table->pieces as $piece) if($piece['alpha'] > 0) $occupied[$piece['square']] = $piece;

		for($square = 0; $square < Board::SQUARES; $square++)
		{
			[$cx, $cy] = $place(Board::x($square), Board::y($square));
			$squares[] = $this->tile(Board::owner($square) === Board::BLACK ? 'square-red' : 'square-black', $cx, $cy, $k);
		}

		// The far-rank squares each side has to fill carry a faint crown until a piece stands on them.
		foreach([Board::BLACK, Board::RED] as $side) foreach(Board::goal($side) as $square)
		{
			if(isset($occupied[$square])) continue;

			[$cx, $cy] = $place(Board::x($square), Board::y($square));
			$squares[] = $this->tile('crown', $cx, $cy, $k, $side === Board::BLACK ? [.36, .07, .07] : [.25, .24, .31]);
		}

		$marks = [];

		foreach($table->trail as $square)
		{
			[$cx, $cy] = $place(Board::x($square), Board::y($square));
			$marks[] = [$cx - $tile / 2, $cy - $tile / 2, $tile, $tile, 1, .88, .45, .13];
		}

		$pieces = [];
		$lifted = [];
		$targets = $table->targets();
		$victims = $table->victims();

		foreach($targets as $square)
		{
			[$cx, $cy] = $place(Board::x($square), Board::y($square));
			$pieces[] = $this->tile('dot', $cx, $cy, $k, self::CREAM, .55 + .35 * $pulse);
		}

		foreach($table->pieces as $piece)
		{
			if($piece['alpha'] <= 0) continue;

			[$cx, $cy] = $place($piece['x'], $piece['y']);
			$name = ($piece['side'] === Board::BLACK ? 'black-' : 'red-') . ($piece['kind'] === null ? 'back' : strtolower(Board::KINDS[$piece['kind']]));
			// A piece turning over narrows to nothing at the halfway point, where its back gives way to its face.
			$turn = isset($piece['reveal']) ? abs(1 - 2 * $piece['face']) : 1.0;
			$batch = [];
			$batch[] = $this->tile($name, $cx, $cy, $k * $piece['scale'], null, $piece['alpha'], max(.06, $turn));

			if($piece['king']) $batch[] = $this->tile('king', $cx, $cy, $k * $piece['scale'], null, $piece['alpha'], max(.06, $turn));

			if($piece['scale'] > 1.001) array_push($lifted, ...$batch);
			else array_push($pieces, ...$batch);
		}

		if($table->selected !== null)
		{
			[$cx, $cy] = $place(Board::x($table->selected), Board::y($table->selected));
			$pieces[] = $this->tile('ring', $cx, $cy, $k, self::GOLD, .7 + .3 * $pulse);
		}

		foreach($victims as $square)
		{
			[$cx, $cy] = $place(Board::x($square), Board::y($square));
			$pieces[] = $this->tile('reticle', $cx, $cy, $k, self::GOLD, .6 + .4 * $pulse);
		}

		if($table->cursor >= 0 && !$menu->isOpen)
		{
			[$cx, $cy] = $place(Board::x($table->cursor), Board::y($table->cursor));
			$pieces[] = $this->tile('cursor', $cx, $cy, $k, [1, 1, 1], .75 + .25 * $pulse);
		}

		// Rows: each side's name, what it has left, its far-rank progress, and what it has lost. The opponent's row is
		// above the board and the player's below it, or both stand in a column beside the board on a wide frame.
		$labels = [];
		$minis = [];
		$totals = $table->totals();
		$counts = $totals['counts'] ?? [Board::PIECES, Board::PIECES];
		$lost = $totals['lost'] ?? [[], []];
		$filled = $totals['filled'] ?? [0, 0];
		$other = Board::other($human);
		$level = ucfirst((string)($view['level'] ?? ''));
		$names = [$human => 'You', $other => $level === '' ? 'Computer' : "Computer: $level"];
		$status = $rows ? $table->status() : '';
		$statusColour = $table->banner !== '' || ($view['phase'] ?? '') === 'over' ? self::GOLD : self::CREAM;
		$wide = $fit['mode'] === 'landscape';
		$rowLeft = $wide ? $left + $size + $border + 2 * $gap : $left;
		$rowRight = $wide ? $rowLeft + $fit['column'] : $left + $size;
		$bottom = $top + $size + $border;
		// Beside the board a row takes three lines: the name, then the progress and the count, then the lost pieces.
		$block = $wide ? $rowHeight + $line + $gap : $rowHeight;
		$placed = $wide
			? [[$other, max($top - $border, $this->reserve)], [$human, $bottom - $buttonHeight - $gap - $block]]
			: [[$other, $top - $border - $gap - $rowHeight], [$human, $bottom + 2 * $gap + $line]];

		foreach($rows ? $placed : [] as [$side, $y])
		{
			$this->label($labels, Board::SIDES[$side], $rowLeft, $y, $t, 'left', self::SIDE_TEXT[$side]);
			$this->label($labels, $names[$side], $rowLeft + $this->measure(Board::SIDES[$side] . ' ') * $t, $y, $t, 'left', self::DIM);
			$this->label($labels, $counts[$side] . ' left', $rowRight, $y + ($wide ? $line + $gap : 0), $t, 'right', self::CREAM);

			// Far-rank progress: four crowns, gold for each square held. A wide frame sets them under the name, with
			// the count; a tall one sets them beside the count.
			$crownX = $wide ? $rowLeft + 4 * $t : $rowRight - $this->measure($counts[$side] . ' left  ') * $t - 3 * 9 * $t - 4 * $t;
			$crownY = $y + ($wide ? $line + $gap : 0) + $line / 2 + $t;

			for($index = 0; $index < Board::GOALS; $index++)
			{
				$minis[] = $this->tile('crown', $crownX + $index * 9 * $t, $crownY, $t, $index < $filled[$side] ? self::GOLD : [.3, .31, .36]);
			}

			// What this side has lost, in the order it lost it: public knowledge, since both players watched each go.
			$trayY = $y + ($wide ? 2 : 1) * ($line + $gap) + self::TILE * $mini / 2;

			foreach($lost[$side] as $index => $kind)
			{
				$name = ($side === Board::BLACK ? 'black-' : 'red-') . strtolower(Board::KINDS[$kind]);
				$minis[] = $this->tile($name, $rowLeft + (self::TILE / 2 + $index * 12) * $mini, $trayY, $mini);
			}
		}

		if($status !== '' && $wide)
		{
			$set = $this->label($labels, $status, $rowLeft, 0, $t, 'left', $statusColour, $fit['column']);
			$middle = ($placed[0][1] + $block + $placed[1][1]) / 2;

			// The wrapped status is centred in the gap between the two rows.
			foreach($set['count'] > 0 ? array_slice($labels, -$set['count']) : [] as $sprite) $sprite->y += floor($middle - $set['height'] / 2);
		}
		elseif($status !== '') $this->label($labels, $status, $left + $size / 2, $bottom + $gap, $t, 'center', $statusColour);

		// Buttons sit at the right of the player's lowest line, beside the lost pieces on a tall frame.
		$buttonY = $wide ? $bottom - $buttonHeight : $bottom + 3 * $gap + 2 * $line;
		$x = $rowRight;

		foreach(array_reverse($buttons) as $button)
		{
			$buttonWidth = (int)ceil($this->measure($button['label']) * $t) + 6 * $k;
			$x -= $buttonWidth;
			$enabled = $button['enabled'] ?? true;
			$rects[] = [$x, $buttonY, $buttonWidth, $buttonHeight, ...self::BUTTON_EDGE, $enabled ? 1 : .4];
			$rects[] = [$x + $k, $buttonY + $k, $buttonWidth - 2 * $k, $buttonHeight - 2 * $k, ...self::BUTTON, 1];
			$this->label($labels, $button['label'], $x + $buttonWidth / 2, $buttonY + ($buttonHeight - $line) / 2, $t, 'center', $enabled ? self::CREAM : self::DIM);
			$this->regions[] = ['id' => $button['id'], 'label' => $button['label'], 'rect' => [$x, $buttonY, $buttonWidth, $buttonHeight], 'disabled' => !$enabled];
			$x -= 2 * $k;
		}

		$this->rects->render($rects, $width, $height);
		$this->sprites->render($squares, $camera);

		if($marks !== []) $this->rects->render($marks, $width, $height);

		$this->sprites->render([...$pieces, ...$lifted, ...$minis], $camera);
		$this->text->render($labels, $camera);

		// One region per piece and per square the selection can reach. Empty squares that do nothing are left to the
		// canvas, so a tap on one still reaches the game and lets go of the selection.
		if(!$menu->isOpen)
		{
			foreach(array_unique([...array_keys($occupied), ...$targets]) as $square)
			{
				[$cx, $cy] = $place(Board::x($square), Board::y($square));
				$this->regions[] = ['id' => "square-$square", 'label' => $table->label($square), 'rect' => [$cx - $tile / 2, $cy - $tile / 2, $tile, $tile]];
			}
		}
		else
		{
			$this->menus->style['scale'] = max(2, $t);
			$this->menus->style['width'] = min($width - 8 * $k, 300 * max(2, $t));
			$this->menus->render($menu, $width, $height, $seconds);
			$this->regions = $this->menus->regions;
		}
	}

	/**
	 * Choose the layout and the largest whole-number scale that fit a frame.
	 *
	 * A tall frame stacks the opponent's row, the board, and the player's row. A wide frame stands both rows in a
	 * column to the right of the board, so the board can take the full height. Whichever gives the larger board wins,
	 * and a tie goes to the stacked layout.
	 *
	 * @param int $width Frame width in pixels.
	 * @param int $height Frame height in pixels.
	 * @return array<string,int|string|bool> The mode, the art and text scales, every measure derived from them, the
	 * board's top-left corner, the column width on a wide frame, and whether everything fits.
	 */
	public function fit(int $width, int $height): array
	{
		$best = null;

		foreach(['portrait', 'landscape'] as $mode)
		{
			for($k = max(1, (int)floor($width / self::MIN_WIDTH)); $k >= 1; $k--)
			{
				$t = max(1, intdiv($k, 2));
				$mini = max(1, intdiv($k, 2));
				$measures = [
					'mode' => $mode, 'scale' => $k, 'text' => $t, 'tile' => self::TILE * $k, 'size' => Board::SIZE * self::TILE * $k
					, 'border' => 2 * $k, 'line' => 16 * $t, 'gap' => 2 * $k, 'mini' => $mini
					, 'row' => 16 * $t + 2 * $k + self::TILE * $mini, 'button' => max(self::TILE * $mini, 16 * $t + 2 * $k)
				];
				['size' => $size, 'border' => $border, 'line' => $line, 'gap' => $gap, 'row' => $row, 'button' => $button] = $measures;

				if($mode === 'portrait')
				{
					// The opponent's row sits against the board, below the page's own buttons in the corner. The
					// player's side needs the status line, its row, and the buttons. Spare height is shared, and a
					// frame too short for both keeps the player's side whole.
					$above = $this->reserve + $row + $gap + $border;
					$under = $border + 3 * $gap + 2 * $line + $button + $gap;
					$spare = $height - $size;
					$fits = $size + 2 * $border <= $width && $spare >= $above + $under;
					$measures['x'] = intdiv($width - $size, 2);
					$measures['y'] = $fits ? $above + intdiv($spare - $above - $under, 2) : max($border, $spare - $under);
					$measures['column'] = 0;
				}
				else
				{
					// The column holds the longest row line, or the three placement buttons, whichever is wider.
					$column = max(176 * $t, 150 * $t + 22 * $k);
					$total = $size + 2 * $border + 2 * $gap + $column;
					$fits = $total + 2 * $gap <= $width && $size + 2 * $border <= $height;
					$measures['x'] = intdiv($width - $total, 2) + $border;
					$measures['y'] = intdiv($height - $size, 2);
					$measures['column'] = $column;
				}

				if(!$fits && $k > 1) continue;

				// When nothing fits even at the smallest scale, the stacked layout is the one that degrades gracefully.
				$measures['fits'] = $fits;

				if($best === null || ($fits && (!$best['fits'] || $k > $best['scale']))) $best = $measures;

				break;
			}
		}

		return $best;
	}

	/**
	 * Find the square under a point of the latest render.
	 *
	 * @param float $x Horizontal position in frame pixels.
	 * @param float $y Vertical position in frame pixels.
	 * @return int Square number, or -1 when the point is off the board.
	 */
	public function squareAt(float $x, float $y): int
	{
		if($this->layout === []) return -1;

		$tile = self::TILE * $this->layout['scale'];
		$column = (int)floor(($x - $this->layout['x']) / $tile);
		$row = (int)floor(($y - $this->layout['y']) / $tile);

		if($column < 0 || $row < 0 || $column >= Board::SIZE || $row >= Board::SIZE) return -1;

		return $this->layout['flipped'] ? Board::square(Board::SIZE - 1 - $column, $row) : Board::square($column, Board::SIZE - 1 - $row);
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

	/**
	 * Build one sprite from the pieces sheet, centred on a point.
	 *
	 * @param string $name Tile name from the tileset.
	 * @param float $x Centre in frame pixels.
	 * @param float $y Centre in frame pixels.
	 * @param float $scale Frame pixels per art pixel.
	 * @param array{float,float,float}|null $tint Colour multiplier for white marks, or null to keep the art's colours.
	 * @param float $opacity Opacity from 0 to 1.
	 * @param float $squash Horizontal scale from 0 to 1, for a piece turning over.
	 * @return Sprite The sprite, without the engine's outline.
	 */
	private function tile(string $name, float $x, float $y, float $scale, ?array $tint = null, float $opacity = 1.0, float $squash = 1.0): Sprite
	{
		$sprite = new Sprite($this->tiles[$name], $x, $y, .5, .5);
		$sprite->scaleX = $scale * $squash;
		$sprite->scaleY = $scale;
		$sprite->opacity = $opacity;
		$sprite->outline = false;
		$sprite->animated = false;

		if($tint !== null) $sprite->tint = [...$tint, 1.0];

		return $sprite;
	}

	/**
	 * Append text to a batch.
	 *
	 * @param list<Sprite> $batch Text batch to append to.
	 * @param string $text Text to draw.
	 * @param float $x Left, centre, or right edge in frame pixels, by alignment.
	 * @param float $y Top of the first line in frame pixels.
	 * @param float $scale Whole-number text scale.
	 * @param string $align left, center, or right.
	 * @param array{float,float,float} $colour Text colour.
	 * @param float $wrap Width in frame pixels to wrap at, or INF for a single line.
	 * @return array{count:int,height:float} How many glyph sprites were appended, and the height they cover.
	 */
	private function label(array &$batch, string $text, float $x, float $y, float $scale, string $align, array $colour, float $wrap = INF): array
	{
		$key = json_encode([$text, $scale, $this->font->theme, $colour, is_finite($wrap) ? $wrap : null]);

		if(!isset($this->cache[$key]))
		{
			if(count($this->cache) > 400) $this->cache = [];

			$this->cache[$key] = $this->font->layout($text, $scale, $wrap, [...$colour, 1.0]);
		}

		if($align === 'right') $x -= $this->cache[$key]['width'];
		elseif($align === 'center') $x -= $this->cache[$key]['width'] / 2;

		foreach($this->cache[$key]['sprites'] as $cached)
		{
			$sprite = clone $cached;
			$sprite->x += floor($x);
			$sprite->y += floor($y);
			$batch[] = $sprite;
		}

		return ['count' => count($this->cache[$key]['sprites']), 'height' => (float)$this->cache[$key]['height']];
	}

	/**
	 * Measure a line of text at scale one.
	 *
	 * @param string $text Line to measure.
	 * @return float Width in pixels at scale one.
	 */
	private function measure(string $text): float
	{
		$key = 'measure:' . $text;

		if(!isset($this->cache[$key])) $this->cache[$key] = ['width' => $this->font->measure($text)['width']];

		return (float)$this->cache[$key]['width'];
	}
}
