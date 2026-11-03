<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

/** Supported Doom-Phonics player and progression rules. */
final class MultiplayerMode implements \JsonSerializable
{
	public const COOPERATIVE = 'cooperative';
	public const DEATHMATCH = 'deathmatch';

	private function __construct(private string $value) {}

	public static function from(string $value): self
	{
		$value = strtolower(trim($value));

		if(!in_array($value, [self::COOPERATIVE,self::DEATHMATCH], true))
		throw new \InvalidArgumentException("Unsupported Doom multiplayer mode $value");

		return new self($value);
	}

	public function cooperative(): bool { return $this->value === self::COOPERATIVE; }

	public function deathmatch(): bool { return $this->value === self::DEATHMATCH; }

	public function value(): string { return $this->value; }

	public function jsonSerialize(): string { return $this->value; }

	public function __toString(): string { return $this->value; }
}
