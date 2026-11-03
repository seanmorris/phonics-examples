<?php
declare(strict_types=1);
namespace DoomPhonics\Definition;

/** Layered ZDoom LANGUAGE strings used by DECORATE and native gameplay messages. */
final class LanguageCatalog
{

	/** @param array<string,string> $strings Case-folded token to translated text. */
	private function __construct(private array $strings = []) {}

	/**
	 * @param array<string,string> $documents LANGUAGE documents in increasing precedence.
	 */
	public static function documents(array $documents, string $locale = 'enu', string $game = 'doom'): self
	{
		$strings = [];

		foreach($documents as $source => $text)
		{
			if(!is_string($text)) throw new \InvalidArgumentException('LANGUAGE documents must contain text');

			try
			{
				$strings = array_replace($strings, self::parse($text, $locale, $game));
			}
			catch(\Throwable $error)
			{
				$label = is_string($source) ? $source . ': ' : '';
				throw new \UnexpectedValueException($label . $error->getMessage(), 0, $error);
			}
		}

		return new self($strings);
	}

	public function has(string $token): bool
	{
		return array_key_exists(self::key($token), $this->strings);
	}

	/** Resolve a token with or without its DECORATE `$` prefix. */
	public function text(string $token, ?string $fallback = null): string
	{
		$key = self::key($token);

		if(!array_key_exists($key, $this->strings)) return $fallback ?? $token;

		$value = $this->strings[$key];

		// LANGUAGE values may themselves be aliases. Bound the traversal so a bad
		// add-on cannot create an infinite alias cycle.
		for($depth = 0; $depth < 16 && str_starts_with($value, '$'); $depth++)
		{
			$next = self::key($value);

			if(!array_key_exists($next, $this->strings) || $next === $key) break;

			$key = $next;
			$value = $this->strings[$key];
		}

		return $value;
	}

	/** Resolve a literal-or-token DECORATE property without hiding missing tokens. */
	public function resolve(string $value): string
	{
		return str_starts_with($value, '$') ? $this->text($value, $value) : $value;
	}

	/** Best available classic title for a map marker. */
	public function mapTitle(string $map): string
	{
		$map = strtoupper($map);
		$candidates = ['LEVELNAME_' . $map];

		if(preg_match('/^E[1-9]M[1-9]$/', $map)) $candidates[] = 'HUSTR_' . $map;
		elseif(preg_match('/^MAP(\d\d)$/', $map, $match)) $candidates[] = 'HUSTR_' . (int)$match[1];

		$candidates[] = $map;

		foreach($candidates as $candidate)
		if($this->has($candidate)) return $this->text($candidate);

		return $map;
	}

	/** @return array<string,string> */
	private static function parse(string $text, string $locale, string $game): array
	{
		$text = self::withoutComments($text);
		$locale = strtolower(trim($locale));
		$game = strtolower(trim($game));
		$fallback = [];
		$exact = [];
		$rank = 1;
		$pending = '';

		foreach(preg_split('/\R/', $text) ?: [] as $line)
		{
			$trimmed = trim($line);

			if($pending === '' && preg_match('/^\[([^]]+)]$/', $trimmed, $section))
			{
				$names = preg_split('/[\s,]+/', strtolower(trim($section[1])), -1, PREG_SPLIT_NO_EMPTY) ?: [];
				$rank = in_array($locale, $names, true) ? 2 : (in_array('default', $names, true) ? 1 : 0);

				continue;
			}

			if($trimmed === '' && $pending === '') continue;

			$pending .= ($pending === '' ? '' : "\n") . $line;

			while(($end = self::semicolon($pending)) !== null)
			{
				$statement = trim(substr($pending, 0, $end));
				$pending = ltrim(substr($pending, $end + 1));

				if($statement === '' || $rank === 0) continue;

				if(!preg_match(
					'/^(?:\$ifgame\s*\(\s*([^)]+)\s*\)\s*)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/s'
					, $statement
					, $match
				)) throw new \UnexpectedValueException('invalid LANGUAGE assignment: ' . substr($statement, 0, 80));

				if(($match[1] ?? '') !== '')
				{
					$games = preg_split('/[\s,|]+/', strtolower(trim($match[1])), -1, PREG_SPLIT_NO_EMPTY) ?: [];

					if(!in_array($game, $games, true)) continue;
				}

				preg_match_all('/"((?:\\\\.|[^"\\\\])*)"/s', $match[3], $parts);

				if(!$parts[0]) throw new \UnexpectedValueException("LANGUAGE value {$match[2]} is not a quoted string");

				$value = '';

				foreach($parts[1] as $part) $value .= self::unescape($part);
				$key = strtoupper($match[2]);

				if($rank === 2) $exact[$key] = $value;
				else $fallback[$key] = $value;
			}
		}

		if(trim($pending) !== '') throw new \UnexpectedValueException('unterminated LANGUAGE assignment');

		return array_replace($fallback, $exact);
	}

	private static function key(string $token): string
	{
		return strtoupper(ltrim(trim($token), '$'));
	}

	private static function semicolon(string $text): ?int
	{
		$quoted = false;
		$escaped = false;

		for($index = 0, $length = strlen($text); $index < $length; $index++)
		{
			$character = $text[$index];

			if($quoted)
			{
				if($escaped) $escaped = false;
				elseif($character === '\\') $escaped = true;
				elseif($character === '"') $quoted = false;

				continue;
			}

			if($character === '"') $quoted = true;
			elseif($character === ';') return $index;
		}

		return null;
	}

	private static function unescape(string $value): string
	{
		return (string)preg_replace_callback('/\\\\(.)/s', static fn(array $match): string => match($match[1])
		{
			'n' => "\n", 'r' => "\r", 't' => "\t", '"' => '"', '\\' => '\\',
			default => '\\' . $match[1]
		}, $value
		);
	}

	private static function withoutComments(string $text): string
	{
		$result = '';
		$quoted = false;
		$escaped = false;
		$line = false;
		$block = false;

		for($index = 0, $length = strlen($text); $index < $length; $index++)
		{
			$character = $text[$index];
			$next = $index + 1 < $length ? $text[$index + 1] : '';

			if($line)
			{
				if($character === "\n")
				{
					$line = false;
					$result .= $character;
				}

				continue;
			}

			if($block)
			{
				if($character === '*' && $next === '/')
				{
					$block = false;
					$index++;
				}
				elseif($character === "\n") $result .= $character;

				continue;
			}

			if($quoted)
			{
				$result .= $character;

				if($escaped) $escaped = false;
				elseif($character === '\\') $escaped = true;
				elseif($character === '"') $quoted = false;

				continue;
			}

			if($character === '"')
			{
				$quoted = true;
				$result .= $character;
			}
			elseif($character === '/' && $next === '/')
			{
				$line = true;
				$index++;
			}
			elseif($character === '/' && $next === '*')
			{
				$block = true;
				$index++;
			}
			else $result .= $character;
		}

		return $result;
	}
}
