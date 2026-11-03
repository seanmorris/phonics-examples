<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

/** One of the five campaign difficulty choices, with its WAD thing-filter tier. */
final class SkillLevel
{
	private const LABELS = [
		'I am too young to die'
		, 'Hey, not too rough'
		, 'Hurt me plenty'
		, 'Ultra-violence'
		, 'Nightmare'
	];

	private function __construct(private int $index) {}

	/** @return list<self> Ordered choices shown by the new-game menu. */
	public static function all(): array
	{
		return array_map(static fn(int $index): self => new self($index), array_keys(self::LABELS));
	}

	/** Convert a serialized menu/world value into a validated difficulty. */
	public static function fromIndex(int $index): self
	{
		if(!isset(self::LABELS[$index])) throw new \OutOfRangeException("Unknown skill level $index");

		return new self($index);
	}

	public function index(): int { return $this->index; }

	public function label(): string { return self::LABELS[$this->index]; }

	/** Classic THING flags collapse the five choices into easy, medium, and hard tiers. */
	public function actorTier(): int
	{
		return $this->index <= 1 ? 0 : ($this->index >= 3 ? 3 : 2);
	}
}
