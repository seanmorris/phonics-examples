<?php
declare(strict_types=1);
namespace DoomPhonics\Menu;

use DoomPhonics\Input\DoomActions;
use DoomPhonics\Input\InputSettings;
use Phonics\Input\Source;
use Phonics\Ui\BindingMenu;

/** Doom configuration for the engine's shared gameplay binding menu. */
final class DoomControlsMenu extends BindingMenu
{

	public function __construct(InputSettings $settings)
	{
		parent::__construct(
			$settings->bindings
			, DoomActions::rows()
			, $settings->save(...)
			, $settings->reset(...)
			, DoomActions::allowed(...)
			, static fn(string $device): array => self::settings($settings, $device)
			, static fn(): string => $settings->sessionOnly ? 'Changes apply to this session' : ''
		);
	}

	/** Add Doom look preferences below the shared binding rows. */
	private static function settings(InputSettings $input, string $device): array
	{
		$s = $input->settings;

		$choice = static function(string $label, string $name, ?array $options, callable $get) use ($input): array {
			return [
				'label' => $label
				, 'input' => $options === null ? 'boolean' : 'select'
				, 'options' => $options
				, 'get' => $get
				, 'set' => static function(mixed $value) use ($input, $name): void {
					$input->preference($name, $value);
				}
			];
		};

		if($device === Source::CONTROLLER)
		{
			$steps = array_map(static fn(int $i): float => $i / 4, range(1, 12));

			$rows = [
				'horizontal' => $choice(
					'Horizontal sensitivity'
					, 'controllerHorizontal'
					, $steps
					, fn() => $s->controllerHorizontal
				)
				, 'vertical' => $choice(
					'Vertical sensitivity'
					, 'controllerVertical'
					, $steps
					, fn() => $s->controllerVertical
				)
				, 'deadzone' => $choice(
					'Look deadzone'
					, 'controllerDeadZone'
					, array_map(static fn(int $i): float => $i / 100, range(0, 30))
					, fn() => $s->controllerDeadZone
				)
				, 'invert' => $choice('Invert vertical look', 'controllerInvertY', null, fn() => $s->controllerInvertY)
			];

			$rows['deadzone']['format'] = 'percent';

			return $rows;
		}

		return [
			'sensitivity' => $choice(
				'Mouse sensitivity'
				, 'mouseSensitivity'
				, [.5,.75,1.0,1.25,1.5,1.75,2.0]
				, fn() => $s->lookSensitivity()
			)
			, 'invert' => $choice('Invert mouse look', 'mouseInvertY', null, fn() => $s->invertVerticalLook())
		];
	}
}
