<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

/** Incrementally recognizes cheat codes without taking normal movement keys away from the game. */
final class CheatSequence
{
	/** @var array<string,array{string,?string}> Longest forms come first for explicit auditability. */
	private const CODES = [
		'idoopsallgibs' => [CheatCommand::OOPS_ALL_GIBS, null]
		, 'idspispopd' => [CheatCommand::NO_CLIP, null]
		, 'idbeholdv' => [CheatCommand::POWER, 'Invulnerable']
		, 'idbeholds' => [CheatCommand::POWER, 'Strength']
		, 'idbeholdi' => [CheatCommand::POWER, 'Invisibility']
		, 'idbeholdr' => [CheatCommand::POWER, 'IronFeet']
		, 'idbeholda' => [CheatCommand::POWER, 'Allmap']
		, 'idbeholdl' => [CheatCommand::POWER, 'LightAmp']
		, 'idchoppers' => [CheatCommand::CHOPPERS, null]
		, 'idbehold' => [CheatCommand::BEHOLD, null]
		, 'idmypos' => [CheatCommand::POSITION, null]
		, 'idclip' => [CheatCommand::NO_CLIP, null]
		, 'idkfa' => [CheatCommand::ARSENAL_AND_KEYS, null]
		, 'idffa' => [CheatCommand::ARSENAL, null]
		, 'iddqd' => [CheatCommand::GOD, null]
		, 'idfa' => [CheatCommand::ARSENAL, null]
	];

	private string $buffer = '';

	/** @return list<CheatCommand> */
	public function feed(string $characters): array
	{
		$commands = [];

		foreach(str_split(strtolower($characters)) as $character)
		{
			if(!ctype_alnum($character)) continue;

			$this->buffer = substr($this->buffer . $character, -16);

			if(preg_match('/idclev([1-9])([1-9])$/', $this->buffer, $match))
			{
				$commands[] = CheatCommand::warp('E' . $match[1] . 'M' . $match[2]);
				$this->buffer = '';
				continue;
			}

			foreach(self::CODES as $code => [$type,$argument])
			{
				if(!str_ends_with($this->buffer, $code)) continue;

				$commands[] = $type === CheatCommand::POWER
					? CheatCommand::power((string)$argument)
					: CheatCommand::named($type);

				// IDBEHOLD is both a help code and the prefix for six power codes.
				if($type !== CheatCommand::BEHOLD) $this->buffer = '';

				break;
			}
		}

		return $commands;
	}

	public function reset(): void { $this->buffer = ''; }

	/** True while the current suffix can still become a cheat on a later key press. */
	public function awaiting(): bool
	{
		for($offset = 0, $length = strlen($this->buffer); $offset < $length; $offset++)
		{
			$suffix = substr($this->buffer, $offset);

			foreach(array_keys(self::CODES) as $code)
			if(str_starts_with($code, $suffix)) return true;

			if(preg_match('/^idclev(?:[1-9])?$/', $suffix)) return true;
		}

		return false;
	}
}
