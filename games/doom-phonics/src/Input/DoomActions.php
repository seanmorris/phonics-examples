<?php
declare(strict_types=1);
namespace DoomPhonics\Input;

use Phonics\Input\Bindings;
use Phonics\Input\InputCode;
use Phonics\Input\Key;
use Phonics\Input\Pad;
use Phonics\Input\Source;

/** Doom gameplay actions occupy the application range; UI uses the shared engine aliases. */
final class DoomActions
{
	public const FORWARD = InputCode::GAME_START, STRAFE = 0x10001, TURN = 0x10002, LOOK_PITCH = 0x10003;
	public const FIRE = 0x10004, USE = 0x10005, PREVIOUS_WEAPON = 0x10006, NEXT_WEAPON = 0x10007;
	public const ALT_FIRE = 0x10008;
	public const SLOT_0 = 0x10010, SLOT_1 = 0x10011, SLOT_2 = 0x10012, SLOT_3 = 0x10013, SLOT_4 = 0x10014;
	public const SLOT_5 = 0x10015, SLOT_6 = 0x10016, SLOT_7 = 0x10017, SLOT_8 = 0x10018, SLOT_9 = 0x10019;
	public const SLOTS = [
		self::SLOT_0
		, self::SLOT_1
		, self::SLOT_2
		, self::SLOT_3
		, self::SLOT_4
		, self::SLOT_5
		, self::SLOT_6
		, self::SLOT_7,self::SLOT_8,self::SLOT_9
	];

	/** Named directional rows used by capture, hints, and conflict descriptions. */
	public static function rows(): array
	{
		$rows = [
			'forward' => [self::FORWARD, 1, 'Forward']
			, 'backward' => [self::FORWARD, -1, 'Backward']
			, 'strafe-left' => [self::STRAFE, -1, 'Strafe left']
			, 'strafe-right' => [self::STRAFE, 1, 'Strafe right']
			, 'turn-left' => [self::TURN, -1, 'Turn left']
			, 'turn-right' => [self::TURN, 1, 'Turn right']
			, 'look-up' => [self::LOOK_PITCH, 1, 'Look up']
			, 'look-down' => [self::LOOK_PITCH, -1, 'Look down']
			, 'fire' => [self::FIRE, 1, 'Fire']
			, 'alt-fire' => [self::ALT_FIRE, 1, 'Alt fire']
			, 'use' => [self::USE, 1, 'Use']
			, 'previous-weapon' => [self::PREVIOUS_WEAPON, 1, 'Previous weapon']
			, 'next-weapon' => [self::NEXT_WEAPON, 1, 'Next weapon']
		];

		foreach(self::SLOTS as $slot => $code) $rows['slot-' . $slot] = [$code, 1, 'Weapon slot ' . $slot];

		return $rows;
	}

	/** Independent physical alternatives for the default keyboard/mouse and controller layouts. */
	public static function defaults(): Bindings
	{

		$key = static fn(int|string $code): Source => new Source(Source::KEY, is_string($code) ? ord($code) : $code);

		$button = static fn(int $code): Source => new Source(Source::PAD_BUTTON, $code);

		$axis = static fn(int $code, int $sign): Source => new Source(Source::PAD_AXIS, $code, $sign);

		$mouse = static fn(int $code, int $sign): Source => new Source(Source::MOUSE_AXIS, $code, $sign);
		$bindings = [
			self::FORWARD => [
				1 => [$key('w'),$key(Key::UP),$axis(Pad::LEFT_Y, -1)]
				, -1 => [$key('s'),$key(Key::DOWN),$axis(Pad::LEFT_Y, 1)]
			]
			, self::STRAFE => [-1 => [$key('a'),$axis(Pad::LEFT_X, -1)], 1 => [$key('d'),$axis(Pad::LEFT_X, 1)]]
			, self::TURN => [
				-1 => [$key(Key::LEFT),$mouse(Source::X, -1),$axis(Pad::RIGHT_X, -1)]
				, 1 => [$key(Key::RIGHT),$mouse(Source::X, 1),$axis(Pad::RIGHT_X, 1)]
			]
			, self::LOOK_PITCH => [
				1 => [$mouse(Source::Y, -1),$axis(Pad::RIGHT_Y, -1)]
				, -1 => [$mouse(Source::Y, 1),$axis(Pad::RIGHT_Y, 1)]
			]
			, self::FIRE => [
				1 => [
					$key(Key::SPACE)
					, $key('z')
					, $key(Key::ENTER)
					, $key(Key::KEYPAD_ENTER)
					, $key(Key::LEFT_CTRL)
					, new Source(Source::MOUSE_BUTTON, Source::PRIMARY)
					, $axis(Pad::RT, 1)
				]
			]
			, self::ALT_FIRE => [
				1 => [new Source(Source::MOUSE_BUTTON, Source::SECONDARY),$axis(Pad::LT, 1)]
			]
			, self::USE => [1 => [$key('e'),$button(Pad::A)]]
			, self::PREVIOUS_WEAPON => [
				1 => [new Source(Source::WHEEL, Source::Y, 1),$button(Pad::LEFT),$button(Pad::DOWN)]
			]
			, self::NEXT_WEAPON => [
				1 => [new Source(Source::WHEEL, Source::Y, -1),$button(Pad::RIGHT),$button(Pad::UP)]
			]
		];

		foreach(self::SLOTS as $slot => $code) $bindings[$code] = [1 => [$key((string)$slot)]];

		return new Bindings($bindings);
	}

	/** Fixed pause/cancel inputs remain available even after gameplay rebinding. */
	public static function allowed(Source $source): bool
	{
		return !($source->kind === Source::KEY && in_array($source->code, [Key::ESCAPE, ord('p')], true))
			&& !($source->kind === Source::PAD_BUTTON && $source->code === Pad::START);
	}
}
