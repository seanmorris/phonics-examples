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
		$this->readThisPage($renderer, $menu, $patches, $seconds, $controllerTheme, $hints);
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
			case 'Networking': $this->networkingPage($renderer, $menu, $patches, $seconds);
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
	private function networkingPage(
		GlesMapRenderer $renderer
		, Menu $menu
		, array &$patches
		, float $seconds
	): void {
		[$heading] = $this->text($renderer, $patches, 'NETWORKING', 0, 17, 1.5);
		$this->moveLastText($patches, (320 - $heading) / 2);
		$rows = $menu->rows();

		foreach(['homeserver' => 43.0,'lobby-channel' => 84.0] as $id => $y)
		{
			if(!isset($rows[$id])) continue;

			$row = $rows[$id];
			$selected = $menu->selected === $id;
			$this->text($renderer, $patches, (string)$row['label'], 32, $y, 1.0);
			$renderer->renderMenuPanel(27, $y + 11, 266, 21, $selected ? .72 : .45);
			$editor = $selected ? $menu->editor : null;
			$value = $editor?->text ?? (string)($row['value'] ?? '');

			$carets = $this->fieldText(
				$renderer
				, $patches
				, $value
				, 32
				, $y + 17
				, 256
				, $editor?->caret
				, $editor !== null && fmod($seconds, 1) < .55
			);

			$this->fieldRegion($renderer, $id, 27, $y + 11, 266, 21, $carets);

			if($selected) $this->skull($patches, 5, $y + 10, $seconds);
		}

		if(isset($rows['apply-networking']))
		$this->row($renderer, $menu, $patches, 'apply-networking', $rows['apply-networking'], 48, 126, $seconds, 1.25);

		if(($message = (string)($menu->value('network-status') ?? '')) !== '')
		{
			$this->text($renderer, $patches, $this->fitText($renderer, $message, 264, .75), 28, 153, .75);
		}

		if(isset($rows['@back']))
		$this->row($renderer, $menu, $patches, '@back', $rows['@back'], 252, 177, $seconds, 1.0);
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
			, '✚ MOVE    ← → CHANGE    ❶ BACK'
			, $message
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
	): void {
		$heading = strtoupper($menu->title);

		$this->menuText(
			$renderer
			, $patches
			, $heading
			, (320 - $this->menuTextWidth($renderer, $heading, 1.25)) / 2
			, 8
			, 1.25
		);

		$y = self::COMPACT_ROWS_Y;

		foreach($this->compactRows($menu) as $id => $row)
		{
			$this->compactRow($renderer, $menu, $patches, $hints, $id, $row, $y, $controllerTheme);
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
	): void {
		$selected = $menu->selected === $id;
		$value = $row['value'] ?? '';
		$range = ($row['input'] ?? '') === 'number' && ($row['subType'] ?? '') === 'range'
			&& isset($row['min'], $row['max']) && is_numeric($row['min']) && is_numeric($row['max'])
			&& (float)$row['max'] > (float)$row['min'];

		$hasValue = $value !== '' || $range;
		$labelWidth = $range ? 125.0 : ($hasValue ? 150.0 : 255.0);

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

	/** @param array<string,mixed> $row */
	private function compactValue(array $row): string
	{
		$value = $row['value'] ?? '';

		if(is_bool($value)) return $value ? 'On' : 'Off';

		return ($row['format'] ?? '') === 'percent' && is_numeric($value)
			? (string)round((float)$value * 100) . '%'
			: (string)$value;
	}

	/** @param list<WadPatch> $patches @param list<array<string,mixed>> $hints */
	private function readThisPage(
		GlesMapRenderer $renderer
		, Menu $menu
		, array &$patches
		, float $seconds
		, string $controllerTheme
		, array &$hints
	): void {
		$heading = 'READ THIS';
		$headingWidth = $this->menuTextWidth($renderer, $heading, 1.5);
		$this->menuText($renderer, $patches, $heading, (320 - $headingWidth) / 2, 8, 1.5);
		$this->centerText($renderer, $patches, 'ACTION', 56, 29, .65);
		$this->centerText($renderer, $patches, 'KEYBOARD + MOUSE', 157, 29, .65);
		$this->centerText($renderer, $patches, 'CONTROLLER', 266, 29, .65);

		$lines = [
			['MOVE + STRAFE', '⬲', '⬲']
			, ['LOOK', 'MOUSE', '▦']
			, ['FIRE', 'MOUSE 1', '❼']
			, ['ALT FIRE', 'MOUSE 2', '❻']
			, ['USE', 'E', '⓿']
			, ['WEAPONS', 'WHEEL  0-9', '✚']
		];

		foreach($lines as $index => [$action,$keyboard,$controller])
		{
			$y = 43 + $index * 15;
			$this->text($renderer, $patches, $action, 19, $y + 3, .65);
			$this->buttonHint($renderer, $hints, $keyboard, 'keyboard', 103, $y, 104, 15, .53);
			$this->buttonHint($renderer, $hints, $controller, $controllerTheme, 230, $y, 72, 15, .53);
		}

		$rows = $menu->rows();
		$ids = array_keys($rows);
		$index = (int)array_search($menu->selected, $ids, true);
		$visible = 2;
		$lastScroll = max(0, count($ids) - $visible);
		$menu->scroll = max(0, min($lastScroll, $menu->scroll));

		if($index < $menu->scroll) $menu->scroll = $index;

		if($index >= $menu->scroll + $visible) $menu->scroll = $index - $visible + 1;

		$position = 0;

		foreach(array_slice($rows, $menu->scroll, $visible, true) as $id => $row)
		{
			if($id === '@back') $row = ['label' => 'Go Back'] + $row;

			$this->readThisChoice($renderer, $menu, $patches, $id, $row, 18 + $position * 150, 137, 134, $seconds);
			$position++;
		}

		if($menu->scroll > 0) $this->text($renderer, $patches, '<', 5, 144, .8);

		if($menu->scroll < $lastScroll) $this->text($renderer, $patches, '>', 309, 144, .8);

		$this->buttonHint($renderer, $hints, '← → MOVE  ⓿ SELECT  ❶ BACK', 'keyboard', 8, 169, 145, 23, .5);
		$this->buttonHint($renderer, $hints, '← → MOVE  ⓿ SELECT  ❶ BACK', $controllerTheme, 167, 169, 145, 23, .5);
	}

	/** @param list<WadPatch> $patches @param array<string,mixed> $row */
	private function readThisChoice(
		GlesMapRenderer $renderer
		, Menu $menu
		, array &$patches
		, string $id
		, array $row
		, float $x
		, float $y
		, float $width
		, float $seconds
	): void {
		$selected = $menu->selected === $id;

		if($selected) $renderer->renderMenuPanel($x, $y - 3, $width, 24, .65);

		$label = (string)($row['label'] ?? $id);
		$labelWidth = $this->menuTextWidth($renderer, $label, 1.25);
		$selectorWidth = 29.0;
		$labelArea = $width - $selectorWidth - 4;
		$labelX = $x + $selectorWidth + max(0, ($labelArea - $labelWidth) / 2);
		$this->menuText($renderer, $patches, $label, $labelX, $y + 2, 1.25);
		$this->region($renderer, $id, $x, $y - 3, $width, 24);

		if($selected) $this->skull($patches, $x + 3, $y, $seconds);
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
	): array {
		$characters = BitmapFont::characters(strtoupper($value));
		$positions = [0.0];

		foreach($characters as $character)
		$positions[] = end($positions) + $this->glyphWidth($renderer, $character, 1.0);

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

			$this->text($renderer, $patches, $character, $x + max(0, $left), $y, 1.0);
		}

		if($showCaret && $caret !== null)
		{
			$caretX = max(0, min($width - 2, $positions[$caret] - $scroll));
			$this->text($renderer, $patches, '_', $x + $caretX, $y + 5, 1.0);
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

	/** @param list<float> $carets Caret offsets from the field's text origin in virtual pixels. */
	private function fieldRegion(
		GlesMapRenderer $renderer
		, string $id
		, float $x
		, float $y
		, float $width
		, float $height
		, array $carets
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
				static fn(float $caret): float => ($x + 5 + $caret) * $renderer->width / 320
				, $carets
			)
		];
	}
}
