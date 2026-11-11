<?php
declare(strict_types=1);
namespace PhpPoker\Ui;

use PhpPoker\Render\Text;
use PhpPoker\Render\Theme;

/**
 * One game's How to Play rules, set for a canvas: the window, and the rows of copy that scroll inside it.
 *
 * The copy is one column, as a text-mode help screen sets it: a part heading, one headed step after another with the
 * showdown's example hand, then the part every game shares (the list of hands, the wild-card rule and the key
 * legend). It scrolls a whole row at a time, so nothing is ever drawn half inside the window. The presenter moves
 * through the rows and the painter draws them, so the two cannot disagree about what is in view.
 */
final class HelpPage
{
	/** @var array<string,self> Pages by canvas, look, input family and game. */
	private static array $built = [];

	/** Height of all the copy, in design units. */
	public readonly float $height;
	/** Last row the view can start on: from there the end of the copy is in view. Zero when nothing scrolls. */
	public readonly int $last;
	/** @var list<float> Top of each row, measured down from the top of the copy. */
	private array $tops = [];

	/**
	 * @param array<string,mixed> $frame Window, view, scrollbar and pager from `Layout::help()`.
	 * @param list<array{height:float,ink:float,items:list<array<string,mixed>>}> $rows Rows of copy: how far each
	 *   moves the next one down, how much of that it draws on, and what it draws, placed from the row's own corner.
	 * @param bool $block Whether the scrollbar's thumb is the one-cell block of a text-mode window, which marks
	 *   where the view is, rather than a bar whose length also shows how much of the copy it holds.
	 */
	private function __construct(public readonly array $frame, public readonly array $rows, private readonly bool $block)
	{
		$top = 0.0;
		$bottom = 0.0;
		foreach($rows as $row)
		{
			$this->tops[] = $top;
			$bottom = $top + $row['ink'];
			$top += $row['height'];
		}
		$this->height = $bottom;
		$last = 0;
		while($last < count($rows) - 1 && $bottom - $this->tops[$last] > $frame['view'][3] + 1e-6) $last++;
		$this->last = $last;
	}

	/**
	 * The rules of one game on this canvas.
	 *
	 * @param string $mode `classic` or `modern`; the looks set type in different faces, so they wrap differently.
	 * @param string $theme Input family whose legend closes the copy: `keyboard`, `generic`, `xbox` or `playstation`.
	 * @param string $game One of `GameText::HELP_GAMES`.
	 */
	public static function of(Layout $layout, Text $text, string $mode, string $theme, string $game): self
	{
		$canvas = implode(':', [$layout->profile, $layout->width(), $layout->height(), $layout->scale, $layout->horizontalScale, $layout->dpr, $mode, $theme]);
		if(isset(self::$built["$canvas:$game"])) return self::$built["$canvas:$game"];
		if(count(self::$built) > 32) self::$built = [];
		$width = $layout->help()['view'][2];
		$copies = [];
		$needed = 0.0;
		foreach(GameText::HELP_GAMES as $each)
		{
			$copies[$each] = self::rows($layout, $text, $mode, $theme, $each, $width);
			$needed = max($needed, array_sum(array_column($copies[$each], 'height')));
		}
		// Every game shares one window, as tall as the longest copy where the canvas allows it, so turning to the next
		// game never moves the pager from under the pointer.
		$frame = $layout->help($needed);
		foreach($copies as $each => $rows) self::$built["$canvas:$each"] = new self($frame, $rows, $mode === Theme::CLASSIC);
		return self::$built["$canvas:$game"] ?? self::$built["$canvas:" . GameText::HELP_GAMES[0]];
	}

	/** @return float How far down the copy a row starts. */
	public function top(int $row): float
	{
		return $this->tops[max(0, min(count($this->tops) - 1, $row))] ?? 0.0;
	}

	/**
	 * What the view draws when it starts on a row: every following row that fits whole.
	 *
	 * @return list<array<string,mixed>> Items placed in design units.
	 */
	public function shown(int $first): array
	{
		$first = max(0, min($this->last, $first));
		[$left, $top, , $room] = $this->frame['view'];
		$items = [];
		for($row = $first; $row < count($this->rows); $row++)
		{
			$offset = $this->tops[$row] - $this->tops[$first];
			if($row > $first && $offset + $this->rows[$row]['ink'] > $room + 1e-6) break;
			foreach($this->rows[$row]['items'] as $item)
			{
				$item['x'] += $left;
				$item['y'] += $top + $offset;
				$items[] = $item;
			}
		}
		return $items;
	}

	/** @return int The row a view starting on `$first` starts on after paging down: the first row it could not show whole. */
	public function after(int $first): int
	{
		$first = max(0, min($this->last, $first));
		$room = $this->frame['view'][3];
		$next = $first + 1;
		while($next < count($this->rows) && $this->tops[$next] + $this->rows[$next]['ink'] - $this->tops[$first] <= $room + 1e-6) $next++;
		return min($this->last, $next);
	}

	/** @return int The row a view starting on `$first` starts on after paging up: the earliest that still ends just above it. */
	public function before(int $first): int
	{
		$first = max(0, min($this->last, $first));
		$previous = $first;
		while($previous > 0 && $this->tops[$first] - $this->tops[$previous - 1] <= $this->frame['view'][3] + 1e-6) $previous--;
		return max(0, min($first - 1, $previous));
	}

	/** @return int The row that starts nearest a distance down the copy, among those a view can start on. */
	public function at(float $offset): int
	{
		$nearest = 0;
		for($row = 1; $row <= $this->last; $row++)
			if(abs($this->tops[$row] - $offset) < abs($this->tops[$nearest] - $offset)) $nearest = $row;
		return $nearest;
	}

	/**
	 * The scrollbar's thumb for a view starting on a row.
	 *
	 * @return array{float,float,float,float}|null Design rectangle on the track, or null when all the copy is in view.
	 */
	public function thumb(int $first): ?array
	{
		if($this->last === 0) return null;
		$track = $this->frame['bar']['track'];
		// Never shorter than the bar is wide: the arrows are squares of that size, and so is the block.
		$cell = $this->frame['bar']['up'][3];
		$length = min($track[3], $this->block ? $cell : max($cell, $track[3] * $this->frame['view'][3] / max(1e-6, $this->height)));
		$first = max(0, min($this->last, $first));
		return [$track[0], $track[1] + ($track[3] - $length) * $this->tops[$first] / $this->tops[$this->last], $track[2], $length];
	}

	/**
	 * The row a view starts on once its thumb has been dragged to a height on the track.
	 *
	 * @param float $top Design y of the thumb's upper edge.
	 */
	public function dragged(float $top): int
	{
		$thumb = $this->thumb(0);
		if($thumb === null) return 0;
		$track = $this->frame['bar']['track'];
		$travel = max(1e-6, $track[3] - $thumb[3]);
		return $this->at($this->tops[$this->last] * max(0, min(1, ($top - $track[1]) / $travel)));
	}

	/**
	 * Set one game's copy in rows of the given width.
	 *
	 * @param float $width Width of the view, in design units.
	 * @return list<array{height:float,ink:float,items:list<array<string,mixed>>}>
	 */
	private static function rows(Layout $layout, Text $text, string $mode, string $theme, string $game, float $width): array
	{
		$classic = $mode === Theme::CLASSIC;
		$dos = $classic && isset($text->faces()['dos']);
		$faces = ['head' => $dos ? 'dos' : 'bold', 'body' => $dos ? 'dos' : ($classic ? 'bold' : 'body')];
		$shown = static fn(string $copy): string => $classic ? strtoupper($copy) : $copy;
		$across = $layout->stretch();
		// Four sizes carry the outline: a part, a section within it, its copy, and notes on that copy.
		$body = $layout->controlText();
		$note = $layout->captionText();
		$part = $body * 1.5;
		$section = $body * 1.2;
		// Type is drawn at the chrome's scale, which a short desktop window holds above the field's own. Heights are
		// in field units, so a line of type is its size times this.
		$em = $layout->typeScale();
		$leading = static fn(float $size): float => $size * $em * 1.45;
		// Copy is indented under its heading, which stands at the view's edge.
		$inset = 12 * $across;
		$measure = static fn(string $copy, float $size, string $face): float
			=> $text->measure($copy, $size * $layout->uiScale(), $face) / $layout->horizontalScale;
		$row = static fn(float $height, array $items): array => ['height' => $height, 'ink' => $height, 'items' => $items];
		$label = static fn(string $copy, float $x, float $middle, float $size, string $face, string $align = 'left'): array
			=> ['kind' => 'text', 'text' => $copy, 'x' => $x, 'y' => $middle, 'size' => $size, 'face' => $faces[$face], 'align' => $align];
		$line = static fn(string $copy, float $size): array => $row($leading($size), [$label($copy, $inset, $leading($size) / 2, $size, 'body')]);
		$wrapped = static fn(string $copy, float $size): array => array_map(static fn(string $each): array => $line($each, $size),
			$text->wrap($shown($copy), $size * $layout->uiScale(), ($width - $inset) * $layout->horizontalScale, 40, $faces['body']));
		// A part opens with the largest type over a heavy rule, with a note set against the far edge where it fits.
		$opens = static function(string $title, string $aside = '') use ($shown, $measure, $label, $row, $leading, $part, $note, $em, $width, $across, $faces, $text): array {
			$title = $shown($title);
			$height = $leading($part);
			$items = [$label($title, 0, $height / 2, $part, 'head')];
			$aside = $shown($aside);
			// The note shares the title's baseline: capitals are centred on a label's middle, so the smaller sits lower.
			if($aside !== '' && $measure($title, $part, $faces['head']) + 16 * $across + $measure($aside, $note, $faces['body']) <= $width)
				$items[] = $label($aside, $width, $height / 2 + $text->capHeight($faces['head']) * ($part - $note) * $em / 2, $note, 'body', 'right');
			$items[] = ['kind' => 'rule', 'x' => 0, 'y' => $height, 'width' => $width, 'height' => 2];
			return $row($height + 8, $items);
		};
		// A section's heading is a size smaller, with a hairline running on from it to the far edge.
		$heads = static function(string $title) use ($shown, $measure, $label, $row, $leading, $section, $width, $across, $faces): array {
			$title = $shown($title);
			$height = $leading($section);
			$ruled = $measure($title, $section, $faces['head']) + 8 * $across;
			return $row($height + 2, [$label($title, 0, $height / 2, $section, 'head'),
				['kind' => 'rule', 'x' => $ruled, 'y' => $height / 2 - .5, 'width' => max(0, $width - $ruled), 'height' => 1]]);
		};

		$blocks = [];
		$steps = GameText::helpSteps($game);
		foreach($steps as $index => [$title, $copy])
		{
			$rows = [...($index === 0 ? [$opens('Playing a hand', GameText::helpSummary($game))] : []), $heads($title), ...$wrapped($copy, $body)];
			// The last step is the showdown, which sets out an example: the hand as the game deals it, each group
			// over its label, the cards that do not play dimmed, and a note on what to see in it.
			if($index === count($steps) - 1)
			{
				$example = GameText::helpExample($game);
				$grow = $body * $em / 14;
				$card = [28 * $grow * $across, 40 * $grow];
				$items = [];
				$x = $inset;
				foreach($example['groups'] as [$name, $cards])
				{
					$start = $x;
					foreach($cards as $id)
					{
						$items[] = ['kind' => 'card', 'x' => $x, 'y' => 4, 'width' => $card[0], 'height' => $card[1], 'id' => $id, 'dim' => in_array($id, $example['plays'], true) ? 1.0 : .5];
						$x += $card[0] + 3 * $grow * $across;
					}
					$items[] = $label($shown($name), $start, 4 + $card[1] + 3 + $leading($note) / 2, $note, 'body');
					// A group is at least as wide as its label, so two labels never run together.
					$x = max($x, $start + $measure($shown($name), $note, $faces['body']) + 3 * $grow * $across) + 9 * $grow * $across;
				}
				$rows[] = $row(4 + $card[1] + 3 + $leading($note) + 2, $items);
				array_push($rows, ...$wrapped($example['caption'], $note));
			}
			$blocks[] = $rows;
		}

		// Ten hands in two columns of five, numbered so the order is plain.
		$rows = [$opens('In every game'), $heads('Hands, best first')];
		$cell = ($width - $inset) / 2;
		$number = $measure('10', $body, $faces['body']);
		for($rank = 0; $rank < 5; $rank++)
		{
			$items = [];
			foreach([0, 1] as $side)
			{
				$left = $inset + $side * $cell;
				$items[] = $label((string)($side * 5 + $rank + 1), $left + $number, $leading($body) / 2, $body, 'body', 'right');
				$items[] = $label($shown(GameText::HAND_RANKS[$side * 5 + $rank]), $left + $number + 8 * $across, $leading($body) / 2, $body, 'body');
			}
			$rows[] = $row($leading($body), $items);
		}
		$rows[] = $line($shown(GameText::HAND_RANKS_NOTE), $note);
		$blocks[] = $rows;

		$blocks[] = [$heads('Wild cards'), ...$wrapped(GameText::HELP_WILD, $body)];

		// Key caps end on one line and their meanings start on the next, so the legend reads as a table.
		$controls = GameText::helpControls($game, $theme);
		$capHeight = $note * $em + 8;
		$capWidth = static fn(string $cap): float => max($capHeight * $across, $measure(strtoupper($cap), $note, $faces['head']) + 10 * $across);
		$tab = 0.0;
		foreach($controls as [$caps]) $tab = max($tab, array_sum(array_map($capWidth, $caps)) + (count($caps) - 1) * 4 * $across);
		$sides = $width >= 26 * $body * $em * $across ? 2 : 1;
		$cell = ($width - $inset) / $sides;
		$step = max($leading($body), $capHeight + 6);
		$rows = [$heads($theme === 'keyboard' ? 'Keys' : 'Controller')];
		$each = (int)ceil(count($controls) / $sides);
		for($index = 0; $index < $each; $index++)
		{
			$items = [];
			for($side = 0; $side < $sides; $side++)
			{
				if(!isset($controls[$side * $each + $index])) continue;
				[$caps, $meaning] = $controls[$side * $each + $index];
				$left = $inset + $side * $cell;
				$right = $left + $tab;
				foreach(array_reverse($caps) as $cap)
				{
					$right -= $capWidth($cap);
					$items[] = ['kind' => 'key', 'text' => strtoupper($cap), 'x' => $right, 'y' => ($step - $capHeight) / 2, 'width' => $capWidth($cap), 'height' => $capHeight, 'size' => $note];
					$right -= 4 * $across;
				}
				$items[] = $label($shown($meaning), $left + $tab + 8 * $across, $step / 2, $body, 'body');
			}
			$rows[] = $row($step, $items);
		}
		$blocks[] = $rows;

		// Blocks follow one another with a gap that belongs to the row above it, so a view that starts on a block
		// starts flush with its heading.
		$gap = $body * $em * .8;
		$copy = [];
		foreach($blocks as $index => $rows)
		{
			if($index < count($blocks) - 1) $rows[count($rows) - 1]['height'] += $gap;
			array_push($copy, ...$rows);
		}
		return $copy;
	}
}
