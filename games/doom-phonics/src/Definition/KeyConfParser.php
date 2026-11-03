<?php
declare(strict_types=1);
namespace DoomPhonics\Definition;

/** Parses the weapon-slot subset of layered ZDoom KEYCONF documents. */
final class KeyConfParser
{

	/**
	 * @param array<string,string> $documents Documents in increasing WAD-stack precedence.
	 * @return list<array{command:string,slot:int,weapons:list<string>}>
	 */
	public static function weaponSlots(array $documents): array
	{
		$result = [];

		foreach($documents as $source => $text)
		{
			if(!is_string($text)) throw new \InvalidArgumentException('KEYCONF documents must contain text');

			$text = preg_replace('~/\*.*?\*/~s', '', $text) ?? $text;
			$text = preg_replace('~//[^\r\n]*~', '', $text) ?? $text;

			foreach(preg_split('/\R/', $text) ?: [] as $line => $statement)
			{
				$statement = trim($statement, " \t\0\x0B;");

				if($statement === '') continue;

				if(!preg_match('/^(setslot|addslot|addslotdefault)\b(.*)$/i', $statement, $match)) continue;

				$command = strtolower($match[1]);
				$arguments = preg_split('/[\s,]+/', trim($match[2]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
				$slot = array_shift($arguments);

				if($slot === null || !preg_match('/^[0-9]$/', $slot))
				throw new \UnexpectedValueException(
					(is_string($source) ? "$source:" : '') . ($line + 1) . ": invalid KEYCONF $command slot"
				);

				$weapons = [];

				foreach($arguments as $weapon)
				if(preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $weapon)) $weapons[] = $weapon;

				$result[] = ['command' => $command,'slot' => (int)$slot,'weapons' => $weapons];
			}
		}

		return $result;
	}
}
