<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

/** Classic episode/map routing and par times shared by exits and intermission screens. */
final class LevelProgression
{

	/** @param list<string> $available */
	public static function next(string $map, bool $secretExit, array $available): ?string
	{
		$map = strtoupper($map);
		$available = array_values(array_unique(array_map('strtoupper', $available)));
		$candidate = null;

		if(preg_match('/^E([1-9])M([1-9])$/', $map, $match))
		{
			$episode = (int)$match[1];
			$mission = (int)$match[2];
			$secretFrom = [1 => 3,2 => 5,3 => 6,4 => 2];
			$secretReturn = [1 => 4,2 => 6,3 => 7,4 => 3];

			if($mission === 8) return null;

			if($mission === 9)
			$candidate = 'E' . $episode . 'M' . ($secretReturn[$episode] ?? 4);
			elseif($secretExit && ($secretFrom[$episode] ?? null) === $mission)
			$candidate = 'E' . $episode . 'M9';
			else $candidate = 'E' . $episode . 'M' . ($mission + 1);
		}
		elseif(preg_match('/^MAP(\d\d)$/', $map, $match))
		{
			$number = (int)$match[1];

			$candidate = match($number)
			{
				15 => $secretExit ? 'MAP31' : 'MAP16',
				31 => $secretExit ? 'MAP32' : 'MAP16',
				32 => 'MAP16',
				30 => null,
				default => sprintf('MAP%02d', $number + 1),
			};
		}
		else
		{
			$index = array_search($map, $available, true);
			$candidate = $index === false ? null : ($available[$index + 1] ?? null);
		}

		return $candidate !== null && in_array($candidate, $available, true) ? $candidate : null;
	}

	public static function par(string $map): ?int
	{
		$map = strtoupper($map);
		$episodes = [
			1 => [30,75,120,90,165,180,180,30,165]
			, 2 => [90,90,90,120,90,360,240,30,170]
			, 3 => [90,45,90,150,90,90,165,30,135]
			, 4 => [165,255,135,150,180,390,135,360,180]
		];

		if(preg_match('/^E([1-4])M([1-9])$/', $map, $match))
		return $episodes[(int)$match[1]][(int)$match[2] - 1];

		$doomTwo = [
			30,90,120,120,90,150,120,120,270,90,210,150,150,150,210,150
			, 420,150,200,150,240,150,180,150,150,300,330,420,300,180,120,30
		];

		if(preg_match('/^MAP(\d\d)$/', $map, $match))
		return $doomTwo[(int)$match[1] - 1] ?? null;

		return null;
	}
}
