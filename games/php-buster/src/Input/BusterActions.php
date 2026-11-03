<?php
declare(strict_types=1);
namespace PhpBuster\Input;

use Phonics\Input\Bindings;
use Phonics\Input\InputCode;
use Phonics\Input\Key;
use Phonics\Input\Pad;
use Phonics\Input\Source;

/** Named movement and fire actions for independently editable physical devices. */
final class BusterActions
{
	public const MOVE_X = InputCode::GAME_START + 0x100;
	public const MOVE_Y = InputCode::GAME_START + 0x101;
	public const FIRE = InputCode::GAME_START + 0x102;

	/**
	 * Describe editable actions for the shared binding menu.
	 *
	 * @return array<string,array{0:int,1:int,2:string}> Named binding rows.
	 */
	public static function rows(): array
	{
		return [
			'left' => [self::MOVE_X, -1, 'Move left']
			, 'right' => [self::MOVE_X, 1, 'Move right']
			, 'up' => [self::MOVE_Y, -1, 'Move up']
			, 'down' => [self::MOVE_Y, 1, 'Move down']
			, 'fire' => [self::FIRE, 1, 'Fire']
		];
	}

	/**
	 * Build the default keyboard, mouse, and controller bindings.
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
			self::MOVE_X => [
				-1 => [$key(Key::LEFT), $key('a'), new Source(Source::PAD_AXIS, Pad::LEFT_X, -1)]
				, 1 => [$key(Key::RIGHT), $key('d'), new Source(Source::PAD_AXIS, Pad::LEFT_X, 1)]
			]
			, self::MOVE_Y => [
				-1 => [$key(Key::UP), $key('w'), new Source(Source::PAD_AXIS, Pad::LEFT_Y, -1)]
				, 1 => [$key(Key::DOWN), $key('s'), new Source(Source::PAD_AXIS, Pad::LEFT_Y, 1)]
			]
			, self::FIRE => [
				1 => [
					$key(Key::SPACE)
					, new Source(Source::MOUSE_BUTTON, Source::PRIMARY)
					, new Source(Source::PAD_BUTTON, Pad::RB)
					, new Source(Source::PAD_BUTTON, Pad::A)
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
