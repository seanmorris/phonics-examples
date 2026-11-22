<?php
declare(strict_types=1);
namespace TerrainSandbox;

use Phonics\Asset\Resource;
use Phonics\Render\BitmapFont;
use Phonics\Render\RectRenderer;
use Phonics\Tiled\Loader;
use Phonics\Ui\MenuRenderer;

/**
 * The controls for what the player is doing, shown only for the device in use.
 *
 * Entries use the engine's button glyphs, which the font draws for the active theme: ⓿ is A on an Xbox pad, Cross on a
 * PlayStation pad and Space on a keyboard. On a pad ⬲ and ▦ are the left and right sticks, ❿ and ⓫ their clicks, and
 * 🡨 🡪 🡩 🡫 the left stick's directions. On a keyboard ⬲ is W A S D, 🡩 🡫 🡨 🡪 are W, S, A and D, ❹ and ❺ are Q
 * and E, ❷ is Shift. Keys outside the button sheet, such as F or L, are plain letters.
 */
final class ControlHints
{
	/** Input families the page reports: a keyboard with a mouse, a pad by its maker, or a touchscreen. */
	public const THEMES = ['keyboard', 'xbox', 'playstation', 'generic', 'touch'];
	/** Gap between entries on one line, as in the engine's menu footers. */
	private const GAP = '   ';
	/** Plate color: the HUD's dark green at the HUD's opacity. */
	private const PLATE = [.063, .157, .133, .68];
	private ?BitmapFont $font = null;
	private ?MenuRenderer $glyphs = null;
	private ?RectRenderer $plates = null;
	private string $key = '';
	/** Hint lines with their rectangles, and the plate behind them. */
	private array $lines = [];
	private array $plate = [];

	/**
	 * List the controls for a mode on one input family.
	 * @param string $mode fly, car, craft or jet.
	 * @param string $theme One of THEMES.
	 * @param string $handling The F5's novice or expert handling.
	 * @param bool $balloons Whether there are balloons to cycle through.
	 * @return list<string> Entries, each a button or key followed by its action.
	 */
	public static function entries(string $mode, string $theme, string $handling = 'novice', bool $balloons = false): array
	{
		if($theme === 'touch')
		return match($mode)
		{
			'fly' => ['Drag to look', 'Tap the ground to use the tool'],
			'jet' => ['Connect a gamepad to fly the F5'],
			default => ['Connect a gamepad to drive'],
		};

		$pad = $theme !== 'keyboard';

		return match($mode)
		{
			'fly' => $pad
				? ['⬲ Fly', '▦ Look', '❻ ❼ Down, up', '❿ Fast']
				: ['⬲ Fly', '❹ ❺ Down, up', '❷ Fast', 'Right-drag Look'],
			'car' => $pad
				? ['❼ Accelerate', '❻ Brake, reverse', '⬲ Steer', '⓿ Handbrake', '❹ Nitrous', '❷ Lights', '❽ ↓ Fly']
				: ['🡩 Accelerate', '🡫 Brake, reverse', '🡨 🡪 Steer', '⓿ Handbrake', '❷ Nitrous', 'L Lights', 'F Fly'],
			'craft' => $pad
				? ['❼ Throttle', '❻ Reverse', '⬲ Steer', '↓ Fly']
				: ['🡩 Throttle', '🡫 Reverse', '🡨 🡪 Steer', 'F Fly'],
			'jet' => self::jet($pad, $handling === 'expert', $balloons),
			default => [],
		};
	}

	/**
	 * List the F5's controls.
	 * @param bool $pad Whether a pad is in use.
	 * @param bool $expert Whether the handling is expert.
	 * @param bool $balloons Whether there are balloons to cycle through.
	 * @return list<string> Flight entries, then weapons, view and leaving.
	 */
	private static function jet(bool $pad, bool $expert, bool $balloons): array
	{
		$flight = match(true)
		{
			$pad && $expert => ['⬲ Roll, pitch', '❻ ❼ Rudder', '❸ ❶ Speed'],
			$pad => ['⬲ Turn, climb', '❼ ❻ Speed'],
			$expert => ['🡨 🡪 Roll', '❺ ❹ Pitch', 'Z X Rudder', '🡩 🡫 Speed'],
			default => ['🡨 🡪 Turn', '❺ ❹ Climb, dive', '🡩 🡫 Speed'],
		};
		$actions = $pad ? ['⓿ Launch', '❷ Vulcan'] : ['⓿ Launch', 'G Vulcan'];

		if($balloons) $actions[] = $pad ? '↑ Next balloon' : 'T Next balloon';

		$actions[] = $pad ? '⓫ View' : 'V View';
		$actions[] = $pad ? '❽ ↓ Fly' : 'F Fly';

		return [...$flight, ...$actions];
	}

	/**
	 * Draw the entries at the top left of the frame on a translucent plate, wrapping between entries.
	 * @param list<string> $entries From entries(); nothing is drawn when empty.
	 * @param string $theme One of THEMES, which picks the button artwork.
	 * @param int $width Drawing-buffer width in pixels.
	 * @param int $height Drawing-buffer height in pixels.
	 * @return void Draws over the finished scene with depth testing off.
	 */
	public function draw(array $entries, string $theme, int $width, int $height): void
	{
		if(!$entries || $width <= 0 || $height <= 0) return;

		$this->font ??= new BitmapFont(
			new Loader
			, Resource::framework('resources/font.tsj')
			, [Resource::framework('resources/buttons.tsj')]
		);
		$this->glyphs ??= new MenuRenderer($this->font);
		$this->plates ??= new RectRenderer;
		$this->font->theme = $theme === 'touch' ? 'keyboard' : $theme;
		$key = json_encode([$entries, $this->font->theme, $width, $height], JSON_THROW_ON_ERROR);

		if($key !== $this->key)
		{
			$this->layout($entries, $width, $height);
			$this->key = $key;
		}

		glDisable(GL_DEPTH_TEST);
		glEnable(GL_BLEND);
		glBlendFunc(GL_SRC_ALPHA, GL_ONE_MINUS_SRC_ALPHA);
		$this->plates->render([$this->plate], $width, $height);
		$this->glyphs->renderHints($this->lines, $width, $height, [.93, .96, .93, 1]);
	}

	/**
	 * Release the glyph atlas and plate buffers. A lost context takes them with it, so the owner closes this and makes
	 * a new one after a restore.
	 * @return void The font's tile data stays; only GL objects go.
	 */
	public function close(): void
	{
		$this->glyphs?->close();
		$this->plates?->close();
		$this->glyphs = $this->plates = null;
		$this->key = '';
	}

	/**
	 * Pack entries into lines within the left third of the frame, clear of the navigation arrow at the top center.
	 * A phone-width frame still gets room for a short entry.
	 * @param list<string> $entries Entries to place.
	 * @param int $width Drawing-buffer width in pixels.
	 * @param int $height Drawing-buffer height in pixels.
	 * @return void Stores the lines and the plate behind them.
	 */
	private function layout(array $entries, int $width, int $height): void
	{
		$scale = max(1., min(1.6, $height / 600));
		$margin = 12.;
		$pad = 8. * $scale;
		$room = max(240., $width * .34) - 2 * $margin - 2 * $pad;
		$rows = [];
		$row = '';

		foreach($entries as $entry)
		{
			$next = $row === '' ? $entry : $row . self::GAP . $entry;

			if($row !== '' && $this->font->measure($next, $scale)['width'] > $room)
			{
				$rows[] = $row;
				$next = $entry;
			}

			$row = $next;
		}

		$rows[] = $row;
		$lineHeight = $this->font->measure('Ag⓿', $scale)['height'] + 4 * $scale;
		$this->lines = [];
		$widest = 0.;

		foreach($rows as $i => $text)
		{
			$size = $this->font->measure($text, $scale);
			$widest = max($widest, $size['width']);
			$this->lines[] = [
				'text' => $text
				, 'rect' => [$margin + $pad, $margin + $pad + $i * $lineHeight, $size['width'], $lineHeight]
				, 'scale' => $scale
			];
		}

		$this->plate = [$margin, $margin, $widest + 2 * $pad, count($rows) * $lineHeight + 2 * $pad - 4 * $scale, ...self::PLATE];
	}
}
