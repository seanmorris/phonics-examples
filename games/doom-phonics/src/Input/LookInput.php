<?php
declare(strict_types=1);
namespace DoomPhonics\Input;

use DoomPhonics\Component\ViewInput;
use DoomPhonics\Game\GameSettings;
use Phonics\Input\ActionFrame;
use Phonics\Input\Source;

/** Integrates local look at display rate; the fixed step consumes exactly the previewed yaw. */
final class LookInput
{

	public static function advance(ViewInput $view, ActionFrame $frame, float $dt, GameSettings $settings): void
	{
		$keyboard = $frame->value(DoomActions::TURN, Source::KEYBOARD) * 2.5;
		$controller = $frame->value(DoomActions::TURN, Source::CONTROLLER) * M_PI * $settings->controllerHorizontal;
		// Opposing devices cancel; alternatives in the same direction use the strongest rate.
		$yawRate = max(0, $keyboard, $controller) + min(0, $keyboard, $controller);
		$pitchRate = $frame->value(DoomActions::LOOK_PITCH, Source::CONTROLLER) * (2 * M_PI / 3)
			* $settings->controllerVertical * ($settings->controllerInvertY ? -1 : 1);

		$pitchRate += $frame->value(DoomActions::LOOK_PITCH, Source::KEYBOARD) * (2 * M_PI / 3);

		$view->look(
			-$yawRate * $dt - $frame->delta(DoomActions::TURN) * .0025 * $settings->lookSensitivity()
			, $pitchRate * $dt + $frame->delta(
				DoomActions::LOOK_PITCH
			) * .002 * $settings->lookSensitivity() * ($settings->invertVerticalLook() ? -1 : 1)
		);
	}
}
