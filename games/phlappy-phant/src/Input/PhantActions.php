<?php
declare(strict_types=1);
namespace PhlappyPhant\Input;

use Phonics\Input\Bindings;
use Phonics\Input\InputCode;
use Phonics\Input\Key;
use Phonics\Input\Pad;
use Phonics\Input\Source;

/** The single flap action, editable per physical device. */
final class PhantActions
{
	public const FLAP = InputCode::GAME_START + 0x100;

	/**
	 * Describe editable actions for the shared binding menu.
	 *
	 * @return array<string,array{0:int,1:int,2:string}> Named binding rows.
	 */
	public static function rows(): array
	{
		return ['flap' => [self::FLAP, 1, 'Flap']];
	}

	/**
	 * Build the default keyboard and controller bindings.
	 *
	 * Pointer taps reach the game through the browser host rather than a mouse binding, so a click is never
	 * counted twice.
	 *
	 * @return Bindings A fresh editable binding profile.
	 */
	public static function defaults(): Bindings
	{
		/**
		 * Convert a named or numeric key into a physical input source.
		 *
		 * @param int|string $code Named special key or printable character.
		 * @return Source The corresponding keyboard source.
		 */
		$key = static fn(int|string $code): Source => new Source(Source::KEY, is_string($code) ? ord($code) : $code);

		return new Bindings([
			self::FLAP => [
				1 => [
					$key(Key::SPACE)
					, $key(Key::UP)
					, $key('w')
					, new Source(Source::PAD_BUTTON, Pad::A)
					, new Source(Source::PAD_BUTTON, Pad::RB)
				]
			]
		]);
	}

	/**
	 * Reserve pause controls so every custom profile retains an escape route.
	 *
	 * @param Source $source Physical source proposed for a gameplay binding.
	 * @return bool True when the source is available for gameplay.
	 */
	public static function allowed(Source $source): bool
	{
		return !($source->kind === Source::KEY && in_array($source->code, [Key::ESCAPE, ord('p')], true))
			&& !($source->kind === Source::PAD_BUTTON && $source->code === Pad::START);
	}
}
