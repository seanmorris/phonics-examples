<?php
declare(strict_types=1);
namespace PhpSlalom\Input;

use Phonics\Input\Bindings;
use Phonics\Input\InputCode;
use Phonics\Input\Key;
use Phonics\Input\Pad;
use Phonics\Input\Source;

/** Named steering actions and their independent device defaults. */
final class SlalomActions
{
	public const LEFT = InputCode::GAME_START;
	public const RIGHT = InputCode::GAME_START + 1;

	/**
	 * Describe the steering rows shown by the shared binding editor.
	 *
	 * @return array<string,array{0:int,1:int,2:string}> Named binding rows.
	 */
	public static function rows(): array
	{
		return [
			'left' => [self::LEFT, 1, 'Steer left']
			, 'right' => [self::RIGHT, 1, 'Steer right']
		];
	}

	/**
	 * Build the original keys and pointer buttons plus standardized D-pad and left-stick input.
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
			self::LEFT => [
				1 => [
					$key(Key::LEFT)
					, $key('a')
					, $key('j')
					, new Source(Source::MOUSE_BUTTON, Source::PRIMARY)
					, new Source(Source::PAD_BUTTON, Pad::LEFT)
					, new Source(Source::PAD_AXIS, Pad::LEFT_X, -1)
				]
			]
			, self::RIGHT => [
				1 => [
					$key(Key::RIGHT)
					, $key('d')
					, $key('l')
					, new Source(Source::MOUSE_BUTTON, Source::SECONDARY)
					, new Source(Source::PAD_BUTTON, Pad::RIGHT)
					, new Source(Source::PAD_AXIS, Pad::LEFT_X, 1)
				]
			]
		]);
	}

	/**
	 * Keep pause and menu controls fixed so every custom profile retains an escape route.
	 *
	 * @param Source $source Physical source proposed for a steering binding.
	 * @return bool True when the source is available for steering.
	 */
	public static function allowed(Source $source): bool
	{
		return !($source->kind === Source::KEY && in_array($source->code, [Key::ESCAPE, ord('p')], true))
			&& !($source->kind === Source::PAD_BUTTON && $source->code === Pad::START);
	}
}
