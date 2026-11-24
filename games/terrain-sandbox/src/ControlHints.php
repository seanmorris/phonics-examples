<?php
declare(strict_types=1);
namespace TerrainSandbox;

/**
 * The controls for what the player is doing, shown only for the device in use.
 *
 * Entries use the engine's button glyphs, in the active theme: ⓿ is A on an Xbox pad, Cross on a
 * PlayStation pad and Space on a keyboard. On a pad ⬲ and ▦ are the left and right sticks, ❿ and ⓫ their clicks, and
 * 🡨 🡪 🡩 🡫 the left stick's directions. On a keyboard ⬲ is W A S D, 🡩 🡫 🡨 🡪 are W, S, A and D, ❹ and ❺ are Q
 * and E, ❷ is Shift. Keys outside the button sheet, such as F or L, are plain letters.
 */
final class ControlHints
{
	/** Input families the page reports: a keyboard with a mouse, a pad by its maker, or a touchscreen. */
	public const THEMES = ['keyboard', 'xbox', 'playstation', 'generic', 'touch'];

	/**
	 * List the controls for a mode on one input family.
	 * @param string $mode fly, car, craft or jet.
	 * @param string $theme One of THEMES.
	 * @param string $handling The F5's novice or expert handling.
	 * @param bool $balloons Whether there are balloons to cycle through.
	 * @param bool $roadRecovery Whether a car can return to a highway or race course.
	 * @return list<string> Entries, each a button or key followed by its action.
	 */
	public static function entries(string $mode, string $theme, string $handling = 'novice', bool $balloons = false,
		bool $roadRecovery = false): array
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
				? ['❼ Accelerate', '❻ Brake, reverse', '⬲ Steer', '⓿ Handbrake', '❹ Nitrous', '❷ Lights', ...($roadRecovery ? ['⓫ Return to road'] : []), '❽ ↓ Fly']
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
}
