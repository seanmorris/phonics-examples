<?php
declare(strict_types=1);
namespace PhpTris\Render;
use Phonics\Render\
{
	Atlas,BitmapFont,Camera,RectRenderer,SpriteRenderer
};
use Phonics\Ui\Menu;
use PhpTris\
{
	MatchState,Module,Shapes
};

/** Native-pixel SDL presentation with cached settled geometry and one batched text pass. */
final class Scene
{
	private Atlas $atlas;
	private SpriteRenderer $sprites;
	private RectRenderer $rects;
	private array $boxes = [], $glyphs = [], $cache = [], $next = [], $settled = [];
	private string $boardKey = '';
	private array $settledPositions = [];
	public array $regions = [], $snapshot = [];
	public const COLORS = [
		[.94,.42,.27]
		, [.21,.84,.67]
		, [.66,.36,.91]
		, [.27,.62,.88]
		, [.87,.74,.21]
		, [.88,.33,.65]
		, [
			.46,.79,.21
		]
		, [.27,.33,.42]
	];

	/**
	 * Initialize the component and its owned resources.
	 *
	 * @param BitmapFont $font Shared bitmap font and controller glyph source.
	 * @return void Updates owned state without returning a value.
	 */
	public function __construct(public BitmapFont $font)
	{
		$this->atlas = new Atlas($font->map);
		$this->sprites = new SpriteRenderer($this->atlas);
		$this->rects = new RectRenderer;
	}

	/**
	 * Append an integer-pixel rectangle to the current render batch.
	 *
	 * @param float $x Horizontal cell or pixel coordinate.
	 * @param float $y Vertical cell or pixel coordinate.
	 * @param float $w Viewport or rectangle width in physical pixels.
	 * @param float $h Viewport or rectangle height in physical pixels.
	 * @param array $color Palette index or RGBA channels.
	 * @return void Updates owned state without returning a value.
	 */
	private function box(float $x, float $y, float $w, float $h, array $color): void
	{
		$this->boxes[] = [round($x),round($y),round($w),round($h),...$color];
	}

	/**
	 * Reuse cached bitmap glyph layouts for unchanged text.
	 *
	 * @param string $text Text to lay out with bitmap glyphs.
	 * @param int $x Horizontal cell or pixel coordinate.
	 * @param int $y Vertical cell or pixel coordinate.
	 * @param int $scale Integer glyph magnification.
	 * @param int $width Maximum text width in physical pixels.
	 * @param array $color Palette index or RGBA channels.
	 * @return void Updates owned state without returning a value.
	 */
	private function text(string $text, int $x, int $y, int $scale, int $width, array $color = [.9,.94,1,1]): void
	{
		$key = json_encode([$text,$x,$y,$scale,$width,$color,$this->font->theme]);

		if(!isset($this->cache[$key]))
		{
			$layout = $this->font->layout($text, $scale, max(1, $width), $color);

			foreach($layout['sprites'] as $sprite)
			{
				$sprite->x += $x;
				$sprite->y += $y;
			}

			$this->cache[$key] = $layout['sprites'];
		}

		$this->next[$key] = $this->cache[$key];
		array_push($this->glyphs, ...$this->cache[$key]);
	}

	/**
	 * Draw shaded cells with edge-to-edge balloon lighting.
	 *
	 * @param int $x Horizontal cell or pixel coordinate.
	 * @param int $y Vertical cell or pixel coordinate.
	 * @param int $size Cell side length in physical pixels.
	 * @param int $color Palette index or RGBA channels.
	 * @param string $kind Cell role in board resolution.
	 * @param float $alpha Cell opacity.
	 * @return void Updates owned state without returning a value.
	 */
	private function block(int $x, int $y, int $size, int $color, string $kind = 'normal', float $alpha = 1): void
	{
		$c = self::COLORS[$color % 8];

		if($kind === 'core') $c = [1,.75,.25];

		if($kind === 'charge') $c = [.66,.45,1];

		if($kind === 'goal') $c = [.51,.78,.93];

		$depth = max(1, min(2, intdiv($size, 7)));
		$this->box($x, $y, $size, $size, [...$c, $alpha]);

		// Match the square-cornered brick highlights, shading right to the edges.
		foreach([.48, .30, .14] as $step => $light)
		{
			$this->box($x, $y + $step * $depth, $size, $depth, [1, 1, 1, $light * $alpha]);
			$shade = [.42, .26, .12][$step];
			$this->box($x, $y + $size - ($step + 1) * $depth, $size, $depth, [.015, .025, .05, $shade * $alpha]);
		}

		foreach([.20, .10] as $step => $light)
		{
			$this->box($x + $step, $y, 1, $size, [1, 1, 1, $light * $alpha]);
			$this->box($x + $size - $step - 1, $y, 1, $size, [.015, .025, .05, $light * $alpha]);
		}

		if($size >= 14) $this->box($x + 4, $y + $depth, intdiv($size - 8, 3), 1, [1, 1, 1, .24 * $alpha]);

	}

	/**
	 * Render a menu label and expose its accessible hit rectangle.
	 *
	 * @param string $id Board entity identifier or UI action identifier.
	 * @param string $label Visible action label.
	 * @param int $x Horizontal cell or pixel coordinate.
	 * @param int $y Vertical cell or pixel coordinate.
	 * @param int $w Viewport or rectangle width in physical pixels.
	 * @param int $h Viewport or rectangle height in physical pixels.
	 * @param int $scale Integer glyph magnification.
	 * @param bool $selected Whether the menu action is selected.
	 * @param bool $hold Whether the touch action repeats while held.
	 * @return void Updates owned state without returning a value.
	 */
	private function button(
		string $id
		, string $label
		, int $x
		, int $y
		, int $w
		, int $h
		, int $scale
		, bool $selected = false
		, bool $hold = false
	): void {
		$this->box($x, $y, $w, $h, $selected?[.25,.24,.37,1]:[.10,.12,.19,1]);
		$this->box($x, $y, $w, 2, $selected?[.9,.73,.46,1]:[.36,.41,.5,1]);
		$this->text($label, $x + 4, $y + intdiv($h - 16 * $scale, 2), $scale, $w - 8);
		$this->regions[] = ['id' => $id,'label' => $label,'rect' => [$x,$y,$w,$h],'hold' => $hold];
	}

	/**
	 * Draw the current screen at native physical-pixel resolution.
	 *
	 * @param MatchState $m Current board entity component view.
	 * @param int $w Viewport or rectangle width in physical pixels.
	 * @param int $h Viewport or rectangle height in physical pixels.
	 * @param float $dpr Display physical pixels per CSS pixel.
	 * @param bool $touch Whether to display touch controls.
	 * @param string $page Current UI screen.
	 * @param Menu $menu Menu selection and actions.
	 * @param string $best Formatted personal record.
	 * @param string $selectedMode Mode selected for the next run.
	 * @return void Updates owned state without returning a value.
	 */
	public function render(
		MatchState $m
		, int $w
		, int $h
		, float $dpr
		, bool $touch
		, string $page
		, Menu $menu
		, string $best = ''
		, string $selectedMode = 'endless'
	): void {
		$this->boxes = $this->glyphs = $this->regions = $this->next = $this->snapshot = [];
		$scale = max((int)ceil($dpr), (int)round($w / 854 * 1.3));
		$portrait = $h > $w;
		$pad = (int)round(16 * $dpr);
		$this->box(0, 0, $w, $h, [.035,.032,.065,1]);

		for($i = 0; $i < 6; $i++)
		{
			$c = .12 + $i * .025;
			$this->box($i, $i, $w - 2 * $i, 1, [$c,$c,.07 + $c,1]);
			$this->box($i, $i, 1, $h - 2 * $i, [$c,$c,.07 + $c,1]);
			$this->box($w - 1 - $i, $i, 1, $h - 2 * $i, [$c,$c,.07 + $c,1]);
		}

		if($page !== 'play')
		{
			$title = $page === 'title'
				? 'PHPTRIS'
				: ($page === 'modes'
					? 'CHOOSE A MODE'
					: ($page === 'help'
						? 'HOW TO PLAY'
						: ($page === 'pause'
							? 'PAUSED'
							: $m->run->notice
						)
					)
				);

			$large = $page === 'title'?max($scale, (int)floor($w / (strlen($title) * 10 + 50))):$scale;
			$large = min($large, 4 * (int)ceil($dpr));
			$this->text($title, $pad, (int)round($h * .08), $large, $w - $pad * 2, [.93,.75,.5,1]);

			if($page === 'help')
			{
				$body =
					"FILL ROWS. KEEP THE BOARD CLEAR.\n\nARROWS MOVE / SOFT DROP\nUP / X ROTATE RIGHT\nZ / CTRL ROTATE LEFT\nSPACE HARD DROP\nC / SHIFT HOLD\nP / ESC PAUSE\n\nPAD A / B ROTATE, X DROP, Y HOLD.\n\n";

				$body .= "HOLD ROTATION TO KEEP SPINNING.\nSUCCESSFUL MOVES RESET LOCKING.\n\nCHALLENGES SHOW THEIR GOAL IN THE HUD.";
				$this->text($body, $pad, (int)round($h * .18), $scale, $w - 2 * $pad);
			}
			elseif($page === 'title')
			{
				$this->text('BUILD. CLEAR. REACT.', $pad, (int)round($h * .25), $scale, $w - 2 * $pad);

				$this->text(
					strtoupper(Module::MODES[$selectedMode])
					, $pad
					, (int)round($h * .31)
					, $scale
					, $w - 2 * $pad
				);

				$tile = min(24 * $scale, (int)floor(($w - 2 * $pad) / 7) - 6);

				for($i = 0; $i < 7; $i++) $this->block($pad + $i * ($tile + 6), (int)round($h * .38), $tile, $i);
			}
			elseif($page === 'results')
			$this->text(
				'SCORE ' . $m->run->score . '  LINES ' . $m->run->lines . "\nTIME "
					. number_format($m->run->elapsed, 2)
				, $pad
				, (int)round($h * .20)
				, $scale
				, $w - 2 * $pad
			);

			$rows = $menu->rows();
			$n = count($rows);
			$buttonHeight = max((int)ceil(44 * $dpr), 24 * $scale);
			$gap = max(4, (int)round(6 * $dpr));
			$columns = ($page === 'modes' && !$portrait)?2:1;
			$top = $page === 'modes'
				? (int)round($h * .2)
				: max((int)round($h * .48), $h - $pad - ($n * ($buttonHeight + $gap)) - 40 * $scale);

			if($page === 'help') $top = $h - $pad - $buttonHeight - 30 * $scale;

			$bw = (int)min(($w - $pad * 3) / $columns, max(420 * $dpr, 280 * $scale));
			$i = 0;

			foreach($rows as $id => $item)
			{
				$rowCount = (int)ceil($n / $columns);
				$col = $columns === 2?intdiv($i, $rowCount):0;
				$row = $columns === 2?$i % $rowCount:$i;

				$this->button(
					$id
					, strtoupper($item['label'])
					, $pad + $col * ($bw + $pad)
					, $top + $row * ($buttonHeight + $gap)
					, $bw
					, $buttonHeight
					, $scale
					, $menu->selected === $id
				);

				$i++;
			}

			$this->text(
				($this->font->theme === 'keyboard' ? 'ENTER / SPACE SELECT' : '⓿ SELECT   ❶ BACK')
				, $pad
				, $h - 26 * $scale
				, $scale
				, $w - 2 * $pad
			);
		}
		else
		{
			$s = $m->run;
			$top = (int)round(($portrait?160:58) * $dpr);
			$bottom = (int)round(($touch?164:42) * $dpr);
			$cell = max(4, (int)floor(min(($w - 2 * $pad) / 10, ($h - $top - $bottom) / 20)));
			$bx = intdiv($w - $cell * 10, 2);
			$by = $top;
			$this->box($bx - 3, $by - 3, $cell * 10 + 6, $cell * 20 + 6, [.30,.33,.43,1]);
			$this->box($bx, $by, $cell * 10, $cell * 20, [.025,.03,.055,1]);

			for($x = 0; $x < 10; $x++) $this->box($bx + $x * $cell, $by, 1, $cell * 20, [.065,.065,.105,1]);

			if($s->mode === 'targets')
			foreach([5,10,14,17,19,20] as $index => $row)
			$this->box($bx, $by + (20 - $row) * $cell, $cell * 10, 2, [.32 + $index * .08,.35,.40,1]);

			$key = implode(':', [spl_object_id($m->board),$m->board->revision,$bx,$by,$cell]);

			if($key !== $this->boardKey)
			{
				$before = $this->boxes;
				$this->boxes = [];
				$this->settledPositions = [];

				foreach($m->board->cells as $position => $c)
				{
					$y = intdiv($position, 10) - 20;

					if($y < 0) continue;

					$first = count($this->boxes);
					$this->block($bx + ($position % 10) * $cell, $by + $y * $cell, $cell, $c['color'], $c['kind']);

					if($c['square'])
					$this->box($bx + ($position % 10) * $cell, $by + $y * $cell, $cell, 2, [.95,.84,.5,1]);

					for($index = $first; $index < count($this->boxes); $index++)
					$this->settledPositions[$index] = $position;
				}

				$this->settled = $this->boxes;
				$this->boxes = $before;
				$this->boardKey = $key;
			}

			if(!$s->motions) array_push($this->boxes, ...$this->settled);
			else
			foreach($this->settled as $index => $rect)
			{
				if(isset($s->motions[$this->settledPositions[$index]]))
				$rect[1] -= round($cell * min(1, max(0, $s->delay / .04)));

				if($rect[1] < $by) continue;

				$this->boxes[] = $rect;
			}

			if($s->phase === 'flash')
			foreach($s->rows as $row)
			if($row >= 20) $this->box($bx, $by + ($row - 20) * $cell, $cell * 10, $cell, [.9,.88,1,.65]);

			$a = $m->active;

			if($a->shape)
			{
				$ghost = $m->ghost();

				foreach(Shapes::cells($a->shape, $a->rotation) as $i => [$x,$y])
				{
					if($s->ghost && $y + $ghost >= 20)
					$this->block(
						$bx + ($a->x + $x) * $cell
						, $by + ($ghost + $y - 20) * $cell
						, $cell
						, $a->colors[$i]
						, 'normal'
						, .22
					);

					if($y + $a->y >= 20)
					$this->block(
						$bx + ($a->x + $x) * $cell
						, $by + ($a->y + $y - 20) * $cell
						, $cell
						, $a->colors[$i]
						, $a->shape === 'A'?'charge':'normal'
					);
				}
			}

			$this->text('PHPTRIS', $pad, $pad, $scale, $w - 2 * $pad, [.93,.75,.5,1]);
			$stats = 'SCORE ' . $s->score . "\nLINES " . $s->lines . "\nRANK " . $s->level;

			if($s->challenge()) $stats .= "\nGOAL " . $s->goal . '/' . $s->target();

			$time = $s->mode === 'time'?max(0, $s->duration - $s->elapsed):$s->elapsed;
			$stats .= "\nTIME " . gmdate('i:s', (int)$time);

			if($portrait)
			{
				$this->text(
					Module::MODES[$s->mode] . '  RANK ' . $s->level
					, $pad
					, $pad + 20 * $scale
					, $scale
					, $w - 2 * $pad
				);

				$this->text(
					'SCORE ' . $s->score . '  LINES ' . $s->lines
					, $pad
					, $pad + 40 * $scale
					, $scale
					, $w - 2 * $pad
				);

				$this->text(
					$s->challenge()?'GOAL ' . $s->goal . '/' . $s->target():'TIME ' . gmdate('i:s', (int)$time)
					, $pad
					, $pad + 60 * $scale
					, $scale
					, $w - 2 * $pad
				);
			}
			else
			{
				$this->text(Module::MODES[$s->mode], $pad, $by, $scale, $bx - $pad * 2);
				$this->text($stats, $pad, $by + 40 * $scale, $scale, $bx - $pad * 2);
				$this->text($best, $pad, $by + 180 * $scale, $scale, $bx - $pad * 2);
			}

			$preview = max(4, (int)round(($portrait?7:12) * $dpr));
			$px = $portrait?$pad:$bx + $cell * 10 + $pad;
			$py = $portrait?$pad + 80 * $scale:$by + 24 * $scale;
			$items = array_merge([$m->queue->held], array_slice($m->queue->items, 0, 6));

			if(!$portrait) $this->text('HOLD / NEXT', $px, $by, $scale, $w - $px - $pad);

			foreach($items as $i => $item)
			{
				if(!$item) continue;

				$x0 = $px + ($portrait?$i * 5 * $preview:0);
				$y0 = $py + ($portrait?0:$i * 4 * $preview);

				foreach(Shapes::cells($item['shape']) as $j => [$x,$y])
				$this->block($x0 + $x * $preview, $y0 + $y * $preview, $preview, $item['colors'][$j]);
			}

			if($s->phase === 'countdown')
			{
				$this->box($bx, $by + $cell * 8, $cell * 10, $cell * 4, [.08,.07,.15,.95]);
				$this->text($s->notice, $bx + 8, $by + $cell * 9, $scale, $cell * 10 - 16, [1,.85,.5,1]);
			}

			if($touch)
			{
				$labels = [
					'left' => 'LEFT'
					, 'soft' => 'DOWN'
					, 'right' => 'RIGHT'
					, 'ccw' => 'CCW'
					, 'cw' => 'CW'
					, 'hold' => 'HOLD'
					, 'drop' => 'DROP'
					, 'pause' => 'PAUSE'
				];

				$bw = (int)floor(($w - 2 * $pad) / 4);
				$bh = (int)round(48 * $dpr);
				$i = 0;

				foreach($labels as $id => $label)
				{
					$this->button(
						$id
						, $label
						, $pad + ($i % 4) * $bw
						, $h - (int)round(152 * $dpr) + intdiv($i, 4) * ($bh + 4)
						, $bw - 4
						, $bh
						, max(1, (int)ceil($dpr))
						, false
						, $id !== 'pause'
					);

					$i++;
				}
			}
			else
			$this->button(
				'pause'
				, 'PAUSE'
				, $pad
				, $h - 48 * (int)ceil($dpr)
				, 110 * (int)ceil($dpr)
				, 44 * (int)ceil($dpr)
				, max(1, (int)ceil($dpr))
			);

			$this->snapshot = ['board' => [$bx,$by,$cell * 10,$cell * 20],'cellSize' => $cell,'portrait' => $portrait];
		}

		$this->rects->render($this->boxes, $w, $h);
		$this->sprites->render($this->glyphs, new Camera(0, 0, $w, $h));
		$this->cache = $this->next;
		$this->snapshot += ['width' => $w,'height' => $h,'scale' => $scale];
	}

	/**
	 * Recreate rendering resources after graphics context restoration.
	 *
	 * @return void Updates owned state without returning a value.
	 */
	public function restore(): void
	{
		$this->atlas->restore();
		$this->sprites->restore();
		$this->rects->restore();
	}

	/**
	 * Release owned graphics resources.
	 *
	 * @return void Updates owned state without returning a value.
	 */
	public function close(): void
	{
		$this->sprites->close();
		$this->rects->close();
		$this->atlas->close();
	}
}
