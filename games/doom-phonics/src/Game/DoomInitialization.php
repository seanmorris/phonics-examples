<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

/** Validated Doom-specific values supplied by a world's game-owned init object. */
final class DoomInitialization
{

	/**
	 * @param ?string $startMap Optional WAD map name overriding the generic start scope for the initial game.
	 * @param list<string> $cheats Classic cheat sequences applied after a new player is created.
	 */
	public function __construct(public ?string $startMap = null, public array $cheats = []) {}

	/** @param array<string,mixed> $world */
	public static function fromWorld(array $world): self
	{
		$value = $world['init'] ?? [];

		if(!is_array($value) || array_is_list($value) && $value !== [])
		throw new \UnexpectedValueException('Doom world init must be an object');

		$startMap = $value['startMap'] ?? null;

		if($startMap !== null && (!is_string($startMap) || trim($startMap) === ''))
		throw new \UnexpectedValueException('Doom world init.startMap must be a non-empty string');

		if(is_string($value['cheats'] ?? null)) $cheats = [$value['cheats']];
		else $cheats = $value['cheats'] ?? [];

		if(!is_array($cheats) || !array_is_list($cheats))
		throw new \UnexpectedValueException('Doom world init.cheats must be a string or list of strings');

		foreach($cheats as $cheat)
		if(!is_string($cheat) || trim($cheat) === '')
		throw new \UnexpectedValueException('Doom world init.cheats must contain non-empty strings');

		return new self($startMap === null ? null : strtoupper(trim($startMap)), array_map('trim', $cheats));
	}
}
