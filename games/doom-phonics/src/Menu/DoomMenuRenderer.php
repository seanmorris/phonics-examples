<?php
declare(strict_types=1);
namespace DoomPhonics\Menu;

use DoomPhonics\Graphics\Fon2Font;
use DoomPhonics\Render\GlesMapRenderer;
use DoomPhonics\Render\WadPatch;
use Phonics\Render\BitmapFont;
use Phonics\Input\Source;
use Phonics\Ui\Menu;
use Phonics\Ui\MenuRenderer;

/** Builds classic WAD-patch pages while leaving navigation to the neutral Phonics menu model. */
final class DoomMenuRenderer
{
	private const COMPACT_ROW_X = 20.0;
	private const COMPACT_ROW_TEXT_X = 29.0;
	private const COMPACT_ROW_WIDTH = 283.0;
	private const COMPACT_ROW_HEIGHT = 19.0;
	private const COMPACT_ROW_STEP = 20.0;
	private const COMPACT_ROWS_Y = 36.0;
	private const COMPACT_VISIBLE_ROWS = 7;
	private const COMPACT_VALUE_RIGHT = 292.0;
	private const COMPACT_MESSAGE_Y = 174.0;
	private const COMPACT_HINT_Y = 184.0;
	private const COMPACT_FIELD_X = 126.0;
	private const COMPACT_FIELD_SCALE = .75;
	private const READ_THIS_KEYBOARD_RIGHT = 212.0;
	private const READ_THIS_ROWS_Y = 138.0;

	/** @var list<array<string,mixed>> Output-pixel hit regions consumed by MenuInput. */
	public array $regions = [];
	private string $font = 'STCFN';

	/** Borrow the shared button atlas; its owner handles restore and disposal. */
	public function __construct(private ?MenuRenderer $controlHints = null) {}

	/** Name of the font selected for generated menu-size labels. */
	public function font(): string { return $this->font; }

	/** Draw the current page over either the title picture or the live first-person view. */
	public function render(
		GlesMapRenderer $renderer
		, Menu $menu
		, bool $atTitle
		, float $seconds
		, string $inputTheme = 'keyboard'
		, string $controllerTheme = 'generic'
	): void {
		$this->regions = [];

		if(!$menu->isOpen) return;

		$patches = [];
		$hints = [];

		if($atTitle) $renderer->renderMenuPatches([new WadPatch('TITLEPIC', 0, 0, 320, 200)]);

		if($atTitle && $menu->depth() > 0) $renderer->renderMenuPanel(0, 0, 320, 200, .68);
		elseif(!$atTitle) $renderer->renderMenuPanel(0, 0, 320, 200, .48);

		if($menu->value('read-this-page'))
		$this->readThisPage($renderer, $menu, $patches, $inputTheme, $controllerTheme, $hints);
		elseif($menu->value('controls-page'))
		$this->controlsPage($renderer, $menu, $patches, $seconds, $inputTheme, $controllerTheme, $hints);
		elseif($menu->value('settings-section'))
		$this->optionsPage($renderer, $menu, $patches, $seconds, $inputTheme, $hints);
		else
		switch($menu->title)
		{
			case 'Disconnected': $this->disconnectedPage($renderer, $menu, $patches, $seconds);
				break;
			case 'New Game': $this->skillPage($renderer, $menu, $patches, $seconds);
				break;
			case 'Settings': $this->settingsPage($renderer, $menu, $patches, $seconds, $inputTheme, $hints);
				break;
			case 'Controls': $this->controlsPage(
				$renderer
				, $menu
				, $patches
				, $seconds
				, $inputTheme
				, $controllerTheme
				, $hints
			);

				break;
			default: $this->rootPage($renderer, $menu, $patches, $seconds, $atTitle);
		}

		$renderer->renderMenuPatches($patches);

		if($hints) $this->controlHints?->renderHints($hints, $renderer->width, $renderer->height);
	}

	/** @param list<WadPatch> $patches */
	private function disconnectedPage(
		GlesMapRenderer $renderer
		, Menu $menu
		, array &$patches
		, float $seconds
	): void {
		$renderer->renderMenuPanel(0, 0, 320, 200, .68);
		[$heading] = $this->text($renderer, $patches, 'DISCONNECTED', 0, 36, 1.5);
		$this->moveLastText($patches, (320 - $heading) / 2);
		[$message] = $this->text($renderer, $patches, 'THE ONLINE MATCH HAS ENDED.', 0, 68, 1.0);
		$this->moveLastText($patches, (320 - $message) / 2);
		$y = 108.0;

		foreach($menu->rows() as $id => $row)
		{
			$this->row($renderer, $menu, $patches, $id, $row, 75, $y, $seconds, 1.5);
			$y += 24;
		}
	}

	/** @param list<WadPatch> $patches */
	private function rootPage(
		GlesMapRenderer $renderer
		, Menu $menu
		, array &$patches
		, float $seconds
		, bool $atTitle
	): void {
		$x = $atTitle ? 97.0 : 70.0;
		$y = $atTitle ? 72.0 : 42.0;

		if(!$atTitle) $patches[] = new WadPatch('M_PAUSE', 125, 12);

		foreach($menu->rows() as $id => $row)
		{
			$this->row($renderer, $menu, $patches, $id, $row, $x, $y, $seconds);
			$y += 20;
		}
	}

	/** @param list<WadPatch> $patches */
	private function skillPage(
		GlesMapRenderer $renderer
		, Menu $menu
		, array &$patches
		, float $seconds
	): void {
		$patches[] = new WadPatch('M_NEWG', 96, 14);
		$patches[] = new WadPatch('M_SKILL', 54, 38);
		$y = 63.0;

		foreach($menu->rows() as $id => $row)
		{
			$this->row($renderer, $menu, $patches, $id, $row, 48, $y, $seconds);
			$y += 20;
		}
	}

	/** @param list<WadPatch> $patches */
	private function settingsPage(
		GlesMapRenderer $renderer
		, Menu $menu
		, array &$patches
		, float $seconds
		, string $theme
		, array &$hints
	): void {
		$this->compactPage($renderer, $menu, $patches, $hints, $theme, 'generic', '✚ CHANGE    ⓿ SELECT    ❶ BACK');
	}

	/** @param list<WadPatch> $patches @param list<array<string,mixed>> $hints */
	private function optionsPage(
		GlesMapRenderer $renderer
		, Menu $menu
		, array &$patches
		, float $seconds
		, string $theme
		, array &$hints
	): void {
		$message = (string)($menu->value('settings-message') ?? '');

		$this->compactPage(
			$renderer
			, $menu
			, $patches
			, $hints
			, $theme
			, 'generic'
			, match(true)
			{
				$menu->editor !== null => $theme === 'keyboard' ? 'ENTER DONE    ESC CANCEL' : '⓿ DONE    ❶ CANCEL',
				$menu->value('settings-section') === 'networking' => '✚ MOVE    ⓿ EDIT    ❶ BACK',
				default => '✚ MOVE    ← → CHANGE    ❶ BACK',
			}
			, $message
			, $seconds
		);
	}

	/**
	 * Render the shared compact page used by settings, options, and binding menus.
	 * @param list<WadPatch> $patches
	 * @param list<array<string,mixed>> $hints
	 */
	private function compactPage(
		GlesMapRenderer $renderer
		, Menu $menu
		, array &$patches
		, array &$hints
		, string $theme
		, string $controllerTheme
		, string $footer
		, string $message = ''
		, float $seconds = 0
	): void {
		$this->compactHeading($renderer, $menu, $patches);
		$y = self::COMPACT_ROWS_Y;

		foreach($this->compactRows($menu) as $id => $row)
		{
			$this->compactRow($renderer, $menu, $patches, $hints, $id, $row, $y, $controllerTheme, $seconds);
			$y += self::COMPACT_ROW_STEP;
		}

		if($message !== '')
		$this->text(
			$renderer
			, $patches
			, $this->fitText($renderer, $message, 288, .55)
			, 16
			, self::COMPACT_MESSAGE_Y
			, .55
		);

		$this->buttonHints($renderer, $hints, $footer, $theme, self::COMPACT_HINT_Y);
	}

	/** @param list<WadPatch> $patches */
	private function compactHeading(GlesMapRenderer $renderer, Menu $menu, array &$patches): void
	{
		$heading = strtoupper($menu->title);

		$this->menuText(
			$renderer
			, $patches
			, $heading
			, (320 - $this->menuTextWidth($renderer, $heading, 1.25)) / 2
			, 8
			, 1.25
		);
	}

	/** @return array<string,array<string,mixed>> */
	private function compactRows(Menu $menu): array
	{
		$rows = $menu->rows();
		$ids = array_keys($rows);
		$index = array_search($menu->selected, $ids, true);
		$index = $index === false ? 0 : $index;
		$last = max(0, count($rows) - self::COMPACT_VISIBLE_ROWS);
		$menu->scroll = max(0, min($last, $menu->scroll));

		if($index < $menu->scroll) $menu->scroll = $index;

		if($index >= $menu->scroll + self::COMPACT_VISIBLE_ROWS)
		$menu->scroll = min($last, $index - self::COMPACT_VISIBLE_ROWS + 1);

		return array_slice($rows, $menu->scroll, self::COMPACT_VISIBLE_ROWS, true);
	}

	/** @param list<WadPatch> $patches @param list<array<string,mixed>> $hints @param array<string,mixed> $row */
	private function compactRow(
		GlesMapRenderer $renderer
		, Menu $menu
		, array &$patches
		, array &$hints
		, string $id
		, array $row
		, float $y
		, string $controllerTheme
		, float $seconds = 0
	): void {
		$selected = $menu->selected === $id;
		$value = $row['value'] ?? '';
		$range = ($row['input'] ?? '') === 'number' && ($row['subType'] ?? '') === 'range'
			&& isset($row['min'], $row['max']) && is_numeric($row['min']) && is_numeric($row['max'])
			&& (float)$row['max'] > (float)$row['min'];

		$field = ($row['input'] ?? '') === 'string';
		$hasValue = $value !== '' || $range;
		$labelWidth = $field
			? self::COMPACT_FIELD_X - self::COMPACT_ROW_TEXT_X - 3
			: ($range ? 125.0 : ($hasValue ? 150.0 : 255.0));

		if($selected)
		$renderer->renderMenuPanel(self::COMPACT_ROW_X, $y - 4, self::COMPACT_ROW_WIDTH, self::COMPACT_ROW_HEIGHT, .65);

		$this->text(
			$renderer
			, $patches
			, $this->fitText($renderer, (string)($row['label'] ?? $id), $labelWidth, .9)
			, self::COMPACT_ROW_TEXT_X
			, $y
			, .9
		);

		if($field)
		{
			$this->compactField($renderer, $menu, $patches, $id, $row, $y, $seconds);

			return;
		}

		$display = $this->compactValue($row);

		if($range)
		{
			$this->thermometer($patches, (float)$value, 160, $y + 1, (float)$row['min'], (float)$row['max']);
			$this->rightText($renderer, $patches, $display, self::COMPACT_VALUE_RIGHT, $y + 1, .7);

			$this->sliderRegion(
				$renderer
				, $id
				, self::COMPACT_ROW_X
				, $y - 4
				, self::COMPACT_ROW_WIDTH
				, self::COMPACT_ROW_HEIGHT
				, 168
				, 80
			);

			return;
		}

		if($display !== '' && ($row['bindingDevice'] ?? null) === Source::CONTROLLER && $display !== 'Unbound')
		{
			$this->buttonHint($renderer, $hints, $display, $controllerTheme, 170, $y + 2, 122, 14, .62);
		}
		elseif($display !== '')
		{
			$display = $this->fitText($renderer, $display, 120, .7);
			$this->rightText($renderer, $patches, $display, self::COMPACT_VALUE_RIGHT, $y + 1, .7);
		}

		$this->region($renderer, $id, self::COMPACT_ROW_X, $y - 4, self::COMPACT_ROW_WIDTH, self::COMPACT_ROW_HEIGHT);
	}

	/**
	 * Draw an editable text value as an inset field on the right of a compact row, with a caret while editing.
	 * @param list<WadPatch> $patches @param array<string,mixed> $row
	 */
	private function compactField(
		GlesMapRenderer $renderer
		, Menu $menu
		, array &$patches
		, string $id
		, array $row
		, float $y
		, float $seconds
	): void {
		$editor = $menu->selected === $id ? $menu->editor : null;
		$width = self::COMPACT_ROW_X + self::COMPACT_ROW_WIDTH - 4 - self::COMPACT_FIELD_X;
		$renderer->renderMenuPanel(self::COMPACT_FIELD_X, $y - 3, $width, 15, $editor ? .85 : .5);
		$textX = self::COMPACT_FIELD_X + 4;

		$carets = $this->fieldText(
			$renderer
			, $patches
			, $editor?->text ?? (string)($row['value'] ?? '')
			, $textX
			, $y + 1
			, $width - 8
			, $editor?->caret
			, $editor !== null && fmod($seconds, 1) < .55
			, self::COMPACT_FIELD_SCALE
		);

		$this->fieldRegion(
			$renderer
			, $id
			, self::COMPACT_ROW_X
			, $y - 4
			, self::COMPACT_ROW_WIDTH
			, self::COMPACT_ROW_HEIGHT
			, $carets
			, $textX
		);
	}

	/** @param array<string,mixed> $row */
	private function compactValue(array $row): string
	{
		$value = $row['value'] ?? '';

		if(is_bool($value)) return $value ? 'On' : 'Off';

		return ($row['format'] ?? '') === 'percent' && is_numeric($value)
			? (string)round((float)$value * 100) . '%'
			: (string)$value;
	}

	/**
	 * Lay out the controls reference in the compact settings style: a heading, a three-column table, and a vertical
	 * list of choices above the shared footer.
	 * @param list<WadPatch> $patches @param list<array<string,mixed>> $hints
	 */
	private function readThisPage(
		GlesMapRenderer $renderer
		, Menu $menu
		, array &$patches
		, string $inputTheme
		, string $controllerTheme
		, array &$hints
	): void {
		$this->compactHeading($renderer, $menu, $patches);
		$this->text($renderer, $patches, 'ACTION', self::COMPACT_ROW_TEXT_X, 32, .55);
		$this->rightText($renderer, $patches, 'KEYBOARD + MOUSE', self::READ_THIS_KEYBOARD_RIGHT, 32, .55);
		$this->rightText($renderer, $patches, 'CONTROLLER', self::COMPACT_VALUE_RIGHT, 32, .55);

		$lines = [
			['Move + strafe', '⬲', '⬲']
			, ['Look', 'MOUSE', '▦']
			, ['Fire', 'MOUSE 1', '❼']
			, ['Alt fire', 'MOUSE 2', '❻']
			, ['Use', 'E', '⓿']
			, ['Weapons', 'WHEEL  0-9', '✚']
		];

		foreach($lines as $index => [$action,$keyboard,$controller])
		{
			$y = 42 + $index * 14;
			$this->text($renderer, $patches, $action, self::COMPACT_ROW_TEXT_X, $y + 3, .75);
			$this->buttonHint($renderer, $hints, $keyboard, 'keyboard', 108, $y, self::READ_THIS_KEYBOARD_RIGHT - 108, 15, .53);

			$this->buttonHint(
				$renderer
				, $hints
				, $controller
				, $controllerTheme
				, 222
				, $y
				, self::COMPACT_VALUE_RIGHT - 222
				, 15
				, .53
			);
		}

		$y = self::READ_THIS_ROWS_Y;

		foreach($menu->rows() as $id => $row)
		{
			$this->compactRow($renderer, $menu, $patches, $hints, $id, $row, $y, $controllerTheme);
			$y += self::COMPACT_ROW_STEP;
		}

		$this->buttonHints($renderer, $hints, '✚ MOVE    ⓿ SELECT    ❶ BACK', $inputTheme, self::COMPACT_HINT_Y);
	}

	/** @param list<WadPatch> $patches */
	private function controlsPage(
		GlesMapRenderer $renderer
		, Menu $menu
		, array &$patches
		, float $seconds
		, string $theme
		, string $controllerTheme
		, array &$hints
	): void {
		$footer = (string)($menu->value('controls-message') ?? '');

		$this->compactPage(
			$renderer
			, $menu
			, $patches
			, $hints
			, $theme
			, $controllerTheme
			, $menu->value('controls-message') === 'Escape / Start cancels'
				? match($theme)
				{
					'keyboard' => 'ESC CANCEL',
					default => '❾ CANCEL',
				}
			: '✚ CHANGE    ⓿ SELECT    ❶ BACK'
			, $footer
		);
	}

	/** @param list<array<string,mixed>> $hints */
	private function buttonHint(
		GlesMapRenderer $renderer
		, array &$hints
		, string $text
		, string $theme
		, float $x
		, float $y
		, float $width
		, float $height
		, float $scale
	): void {
		$xScale = $renderer->width / 320;
		$yScale = $renderer->height / 200;
		$hints[] = [
			'text' => $text
			, 'rect' => [$x * $xScale, $y * $yScale, $width * $xScale, $height * $yScale]
			, 'scale' => $scale * min($xScale, $yScale)
			, 'theme' => $theme
		];
	}

	/**
	 * Add a shared button-atlas footer in output coordinates while forcing the
	 * glyph family to the input method that currently owns menu navigation.
	 * @param list<array<string,mixed>> $hints
	 */
	private function buttonHints(
		GlesMapRenderer $renderer
		, array &$hints
		, string $text
		, string $theme
		, float $y
	): void {
		$this->buttonHint($renderer, $hints, $text, $theme, 8, $y, 304, 200 - $y, .58);
	}

	/** @param list<WadPatch> $patches @param array<string,mixed> $row */
	private function row(
		GlesMapRenderer $renderer
		, Menu $menu
		, array &$patches
		, string $id
		, array $row
		, float $x
		, float $y
		, float $seconds
		, float $textScale = 2.0
	): void {
		$names = [
			'new-game' => 'M_NGAME'
			, 'read-this' => 'M_RDTHIS'
			, 'skill-0' => 'M_JKILL'
			, 'skill-1' => 'M_ROUGH'
			, 'skill-2' => 'M_HURT'
			, 'skill-3' => 'M_ULTRA'
			, 'skill-4' => 'M_NMARE'
		];

		$name = $names[$id] ?? null;

		if($name && ($image = $renderer->catalog->picture($name)))
		{
			$patches[] = new WadPatch($name, $x, $y);
			$width = (float)$image->width;
			$height = (float)$image->height;
		}
		else
		{
			[$width,$height] = $this->menuText(
				$renderer
				, $patches
				, (string)($row['label'] ?? $id)
				, $x
				, $y + 2
				, $textScale
			);
		}

		$this->region($renderer, $id, $x - 5, $y - 3, max(24, $width + 10), max(18, $height + 6));

		if($menu->selected === $id) $this->skull($patches, $x - 32, $y, $seconds);
	}

	/**
	 * Use the optional natural-size FON2 menu face, falling back to scaled STCFN.
	 * @param list<WadPatch> $patches
	 * @return array{float,float}
	 */
	private function menuText(
		GlesMapRenderer $renderer
		, array &$patches
		, string $text
		, float $x
		, float $y
		, float $fallbackScale
	): array {
		$font = $renderer->catalog->menuFont();

		if(!$font) return $this->text($renderer, $patches, $text, $x, $y, $fallbackScale);

		$this->font = 'DBIGFONT';
		$start = $x;

		foreach(str_split(strtoupper($text)) as $character)
		{
			$code = ord($character);
			$image = $font->glyph($code);

			if($image && $code !== 32)
			$patches[] = new WadPatch(Fon2Font::pictureName($code), $x, $y);

			$x += $font->advance($code);
		}

		return [$x - $start, (float)$font->height];
	}

	private function menuTextWidth(GlesMapRenderer $renderer, string $text, float $fallbackScale): float
	{
		$font = $renderer->catalog->menuFont();

		if(!$font) return $this->textWidth($renderer, $text, $fallbackScale);

		$width = 0.0;

		foreach(str_split(strtoupper($text)) as $character) $width += $font->advance(ord($character));

		return $width;
	}

	/** @param list<WadPatch> $patches */
	private function thermometer(
		array &$patches
		, float $value
		, float $x
		, float $y
		, float $min = .5
		, float $max = 2.0
	): void {
		$patches[] = new WadPatch('M_THERML', $x, $y);

		for($index = 0; $index < 10; $index++) $patches[] = new WadPatch('M_THERMM', $x + 8 + $index * 8, $y);
		$patches[] = new WadPatch('M_THERMR', $x + 88, $y);
		$fraction = max(0, min(1, ($value - $min) / max(1e-9, $max - $min)));
		$patches[] = new WadPatch('M_THERMO', $x + 8 + round($fraction * 10) * 8, $y);
	}

	/** @param list<WadPatch> $patches */
	private function skull(array &$patches, float $x, float $y, float $seconds): void
	{
		$name = ((int)floor($seconds * 4) & 1) ? 'M_SKULL2' : 'M_SKULL1';
		$patches[] = new WadPatch($name, $x, $y);
	}

	/**
	 * Append Doom's small status font and return its virtual width and height.
	 * @param list<WadPatch> $patches
	 * @return array{float,float}
	 */
	private function text(
		GlesMapRenderer $renderer
		, array &$patches
		, string $text
		, float $x
		, float $y
		, float $scale
	): array {
		$start = $x;

		foreach(str_split(strtoupper($text)) as $character)
		{
			$code = ord($character);

			if($code < 33 || $code > 95)
			{
				$x += 4 * $scale;
				continue;
			}

			$name = 'STCFN' . str_pad((string)$code, 3, '0', STR_PAD_LEFT);
			$image = $renderer->catalog->picture($name);

			if(!$image)
			{
				$x += 4 * $scale;
				continue;
			}

			$patches[] = new WadPatch($name, $x, $y, $image->width * $scale, $image->height * $scale);
			$x += $image->width * $scale;
		}

		return [$x - $start, 7 * $scale];
	}

	/** @param list<WadPatch> $patches */
	private function rightText(
		GlesMapRenderer $renderer
		, array &$patches
		, string $text
		, float $right
		, float $y
		, float $scale
	): void {
		$this->text($renderer, $patches, $text, $right - $this->textWidth($renderer, $text, $scale), $y, $scale);
	}

	/** @param list<WadPatch> $patches */
	private function centerText(
		GlesMapRenderer $renderer
		, array &$patches
		, string $text
		, float $center
		, float $y
		, float $scale
	): void {
		$this->text($renderer, $patches, $text, $center - $this->textWidth($renderer, $text, $scale) / 2, $y, $scale);
	}

	private function textWidth(GlesMapRenderer $renderer, string $text, float $scale): float
	{
		$width = 0.0;

		foreach(str_split(strtoupper($text)) as $character)
		{
			$code = ord($character);
			$image = $code >= 33 && $code <= 95
				? $renderer->catalog->picture('STCFN' . str_pad((string)$code, 3, '0', STR_PAD_LEFT))
				: null;

			$width += ($image?->width ?? 4) * $scale;
		}

		return $width;
	}

	/**
	 * Draw one horizontally clipped STCFN field and return raw-text caret positions in virtual pixels.
	 * @param list<WadPatch> $patches
	 * @return list<float>
	 */
	private function fieldText(
		GlesMapRenderer $renderer
		, array &$patches
		, string $value
		, float $x
		, float $y
		, float $width
		, ?int $caret
		, bool $showCaret
		, float $scale = 1.0
	): array {
		$characters = BitmapFont::characters(strtoupper($value));
		$positions = [0.0];

		foreach($characters as $character)
		$positions[] = end($positions) + $this->glyphWidth($renderer, $character, $scale);

		$caret = $caret === null ? null : max(0, min(count($characters), $caret));
		$total = end($positions) ?: 0.0;
		$scroll = $caret === null
			? 0.0
			: max(0, min(max(0, $total - $width), $positions[$caret] - $width * .75));

		foreach($characters as $index => $character)
		{
			$left = $positions[$index] - $scroll;
			$right = $positions[$index + 1] - $scroll;

			if($right <= 0 || $left >= $width) continue;

			$this->text($renderer, $patches, $character, $x + max(0, $left), $y, $scale);
		}

		if($showCaret && $caret !== null)
		{
			$caretX = max(0, min($width - 2, $positions[$caret] - $scroll));
			$this->text($renderer, $patches, '_', $x + $caretX, $y + 5 * $scale, $scale);
		}

		return array_map(static fn(float $position): float => max(0, min($width, $position - $scroll)), $positions);
	}

	private function glyphWidth(GlesMapRenderer $renderer, string $character, float $scale): float
	{
		$code = strlen($character) === 1 ? ord($character) : 0;
		$image = $code >= 33 && $code <= 95
			? $renderer->catalog->picture('STCFN' . str_pad((string)$code, 3, '0', STR_PAD_LEFT))
			: null;

		return ($image?->width ?? 4) * $scale;
	}

	private function fitText(GlesMapRenderer $renderer, string $value, float $width, float $scale): string
	{
		$characters = BitmapFont::characters(strtoupper($value));
		$result = '';
		$used = 0.0;

		foreach($characters as $character)
		{
			$next = $this->glyphWidth($renderer, $character, $scale);

			if($used + $next > $width) break;

			$result .= $character;
			$used += $next;
		}

		return $result;
	}

	/** Move the most recently appended heading from x=0 to a centered x coordinate. */
	private function moveLastText(array &$patches, float $x): void
	{
		$first = count($patches) - 1;

		while($first > 0 && str_starts_with($patches[$first - 1]->name, 'STCFN')
			&& $patches[$first - 1]->y === $patches[$first]->y
		) $first--;

		foreach(array_slice($patches, $first) as $patch) $patch->x += $x;
	}

	private function region(
		GlesMapRenderer $renderer
		, string $id
		, float $x
		, float $y
		, float $width
		, float $height
	): void {
		$this->regions[] = [
			'id' => $id
			, 'rect' => [
				$x * $renderer->width / 320
				, $y * $renderer->height / 200
				, $width * $renderer->width / 320
				, $height * $renderer->height / 200
			]
		];
	}

	/** Add a full-row hit target with a narrower horizontal slider track. */
	private function sliderRegion(
		GlesMapRenderer $renderer
		, string $id
		, float $x
		, float $y
		, float $width
		, float $height
		, float $sliderX
		, float $sliderWidth
	): void {
		$xScale = $renderer->width / 320;
		$yScale = $renderer->height / 200;
		$this->regions[] = [
			'id' => $id
			, 'rect' => [$x * $xScale, $y * $yScale, $width * $xScale, $height * $yScale]
			, 'slider' => [$sliderX * $xScale, $sliderWidth * $xScale]
		];
	}

	/**
	 * @param list<float> $carets Caret offsets from the field's text origin in virtual pixels.
	 * @param float $textX Virtual x of the field's text origin.
	 */
	private function fieldRegion(
		GlesMapRenderer $renderer
		, string $id
		, float $x
		, float $y
		, float $width
		, float $height
		, array $carets
		, float $textX
	): void {
		$this->regions[] = [
			'id' => $id
			, 'rect' => [
				$x * $renderer->width / 320
				, $y * $renderer->height / 200
				, $width * $renderer->width / 320
				, $height * $renderer->height / 200
			]
			, 'carets' => array_map(
				static fn(float $caret): float => ($textX + $caret) * $renderer->width / 320
				, $carets
			)
		];
	}
}
