<?php
declare(strict_types=1);
namespace DoomPhonics\Definition;

/** Small, data-preserving parser for the classic DECORATE actor syntax used by the game data. */
final class DecorateParser
{

	/** Parse one or more DECORATE documents into a shared inheritance catalog. */
	public static function catalog(array $documents): ActorCatalog
	{
		$catalog = new ActorCatalog;

		foreach($documents as $source => $text)
		{
			if(!is_string($text)) throw new \InvalidArgumentException('DECORATE documents must contain text');

			self::parseDocument($catalog, $text, is_string($source) ? $source : null);
		}

		return $catalog;
	}

	private static function parseDocument(ActorCatalog $catalog, string $text, ?string $source): void
	{
		$text = self::stripComments($text);
		$offset = 0;

		while(preg_match('/\bACTOR\b/i', $text, $match, PREG_OFFSET_CAPTURE, $offset))
		{
			$start = $match[0][1];
			$open = strpos($text, '{', $start);

			if($open === false) throw new \UnexpectedValueException(self::where($source) . 'unterminated ACTOR header');

			$close = self::matchingBrace($text, $open, $source);
			$header = trim(substr($text, $start + strlen($match[0][0]), $open - $start - strlen($match[0][0])));
			$actor = self::actorHeader($header, $source);
			self::actorBody($actor, substr($text, $open + 1, $close - $open - 1), $source);
			$catalog->add($actor);
			$offset = $close + 1;
		}
	}

	private static function actorHeader(string $header, ?string $source): ActorDefinition
	{
		if(!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*(.*)$/s', $header, $m))
		throw new \UnexpectedValueException(self::where($source) . "invalid ACTOR header: $header");

		$name = $m[1];
		$tail = trim($m[2]);
		$native = (bool)preg_match('/(?:^|\s)native(?:\s|$)/i', $tail);
		$tail = trim((string)preg_replace('/(?:^|\s)native(?:\s|$)/i', ' ', $tail));
		$parent = null;
		$number = null;

		if(str_starts_with($tail, ':'))
		{
			$tail = trim(substr($tail, 1));

			if(preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\b(.*)$/s', $tail, $m))
			{
				$parent = $m[1];
				$tail = trim($m[2]);
			}
		}

		if(preg_match('/(?:^|\s)(-?\d+)(?:\s|$)/', $tail, $m)) $number = (int)$m[1];

		return new ActorDefinition($name, $parent, $number, $native, $source);
	}

	private static function actorBody(ActorDefinition $actor, string $body, ?string $source): void
	{
		if(preg_match('/\bStates\s*\{/i', $body, $m, PREG_OFFSET_CAPTURE))
		{
			$statesStart = $m[0][1];
			$statesOpen = strpos($body, '{', $statesStart);
			$statesClose = self::matchingBrace($body, $statesOpen, $source);
			self::parseStates($actor, substr($body, $statesOpen + 1, $statesClose - $statesOpen - 1));
			$body = substr($body, 0, $statesStart) . substr($body, $statesClose + 1);
		}

		foreach(preg_split('/\R/', $body) ?: [] as $line)
		{
			$line = trim($line);

			if($line === '') continue;

			if(preg_match('/^([+-])\s*([A-Za-z_][A-Za-z0-9_.]*)$/', $line, $m))
			{
				$actor->flags[strtolower($m[2])] = $m[1] === '+';
				continue;
			}

			if(!preg_match('/^([A-Za-z_][A-Za-z0-9_.]*)\b(.*)$/', $line, $m)) continue;

			$name = $m[1];
			$rest = trim($m[2]);

			if($rest === '' && in_array(strtolower($name), ['monster','projectile'], true))
			{
				$shortcut = strtolower($name);
				$actor->flags[$shortcut] = true;
				$expanded = $shortcut === 'monster'
					? [
						'solid'
						, 'shootable'
						, 'countkill'
						, 'canpushwalls'
						, 'canusewalls'
						, 'activatemcross'
						, 'canpass'
						, 'ismonster'
					]
					: ['noblockmap','nogravity','dropoff','missile','activateimpact','activatepcross','noteleport'];

				foreach($expanded as $flag) $actor->flags[$flag] = true;
				continue;
			}

			$actor->addProperty($name, self::values($rest));
		}
	}

	private static function parseStates(ActorDefinition $actor, string $body): void
	{
		$current = null;
		$pending = [];
		$terminated = false;

		foreach(preg_split('/\R/', $body) ?: [] as $line)
		{
			$line = trim($line);

			if($line === '') continue;

			if(preg_match('/^([A-Za-z_][A-Za-z0-9_.]*):\s*$/', $line, $m))
			{
				$label = strtolower($m[1]);

				// DECORATE labels name positions in one state table. With no explicit
				// Loop/Stop/Wait/Goto, execution falls through into the next label.
				if($current && $current->frames && !$terminated)
				{
					$current->flow = 'goto';
					$current->target = $label;
					$last = $current->frames[array_key_last($current->frames)];
					$last->flow = 'goto';
					$last->target = $label;
				}

				$pending[] = $label;
				$current = null;
				$terminated = false;
				continue;
			}

			if($pending)
			{
				$current = new StateSequence;

				foreach($pending as $label) $actor->states[$label] = $current;
				$pending = [];
				$terminated = false;
			}

			if(!$current) continue;

			if(preg_match('/^(Loop|Stop|Wait)\b/i', $line, $m))
			{
				$current->flow = strtolower($m[1]);

				if($current->frames) $current->frames[array_key_last($current->frames)]->flow = $current->flow;

				$terminated = true;
				continue;
			}

			if(preg_match('/^Goto\s+([^\s]+)(?:\s.*)?$/i', $line, $m))
			{
				$current->flow = 'goto';
				$current->target = $m[1];

				if($current->frames)
				{
					$last = $current->frames[array_key_last($current->frames)];
					$last->flow = 'goto';
					$last->target = $m[1];
				}

				$terminated = true;
				continue;
			}

			if(!preg_match('/^("[^"]*"|\S+)\s+("[^"]*"|\S+)\s+(-?\d+)\s*(.*)$/', $line, $m)) continue;

			$sprite = trim($m[1], '"');
			$frames = trim($m[2], '"');
			$ticks = (int)$m[3];
			$tail = trim($m[4]);
			$bright = (bool)preg_match('/(?:^|\s)Bright(?:\s|$)/i', $tail);
			$tail = trim((string)preg_replace('/(?:^|\s)(?:Bright|Fast|Slow|NoDelay|CanRaise)(?:\s|$)/i', ' ', $tail));
			$action = null;
			$arguments = [];

			if($tail !== '' && preg_match('/^([A-Za-z_][A-Za-z0-9_]*)(?:\s*\((.*)\))?/', $tail, $actionMatch))
			{
				$action = $actionMatch[1];
				$arguments = isset($actionMatch[2]) ? self::values($actionMatch[2]) : [];
			}

			foreach(str_split($frames) as $frame)
			$current->frames[] = new StateFrame($sprite, $frame, $ticks, $bright, $action, $arguments);
		}
	}

	/** @return list<string|int|float> */
	private static function values(string $text): array
	{
		if($text === '') return [];

		preg_match_all('/"(?:\\\\.|[^"\\\\])*"|[^\s,]+/', $text, $matches);
		$result = [];

		foreach($matches[0] as $token)
		{
			if(strlen($token) >= 2 && $token[0] === '"') $result[] = stripcslashes(substr($token, 1, -1));
			elseif(preg_match('/^[+-]?\d+$/', $token)) $result[] = (int)$token;
			elseif(is_numeric($token)) $result[] = (float)$token;
			else $result[] = $token;
		}

		return $result;
	}

	private static function matchingBrace(string $text, int $open, ?string $source): int
	{
		$depth = 0;
		$quoted = false;
		$escaped = false;

		for($i = $open, $length = strlen($text); $i < $length; $i++)
		{
			$character = $text[$i];

			if($quoted)
			{
				if($escaped) $escaped = false;
				elseif($character === '\\') $escaped = true;
				elseif($character === '"') $quoted = false;

				continue;
			}

			if($character === '"')
			{
				$quoted = true;
				continue;
			}

			if($character === '{') $depth++;

			if($character === '}' && --$depth === 0) return $i;
		}

		throw new \UnexpectedValueException(self::where($source) . 'unterminated brace');
	}

	private static function stripComments(string $text): string
	{
		return (string)preg_replace_callback(
			'/"(?:\\\\.|[^"\\\\])*"|\/\/[^\r\n]*|\/\*[\s\S]*?\*\//'
			, static function(array $m): string {
				return $m[0][0] === '"' ? $m[0] : str_repeat("\n", substr_count($m[0], "\n"));
			}
			, $text
		);
	}

	private static function where(?string $source): string { return $source === null ? '' : "$source: "; }
}
