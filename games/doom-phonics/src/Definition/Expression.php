<?php
declare(strict_types=1);
namespace DoomPhonics\Definition;

/**
 * A DECORATE action argument that is computed when the action runs rather than read as a literal: `random(-3,3)`,
 * `pitch+5`, `SXF_CLIENTSIDE|SXF_NOCHECKPOSITION`, `2*random(1,4)`. ZDoom evaluates these on every call, so a weapon
 * written as `A_FireCustomMissile("Shot", random(-3,3), ...)` spreads its fire; read as tokens, the same text shifted
 * every later argument out of place and the spread was lost.
 *
 * The grammar is the useful part of ZDoom's: integer and decimal numbers, parentheses, unary minus and `!`, the
 * arithmetic operators with C precedence, the bitwise `& ^ |`, the functions `random`, `frandom`, `random2`, `abs`,
 * `min`, and `max`, the action flag constants, `true` and `false`, and named variables the caller supplies. Anything
 * else, such as `CallACS(...)`, evaluates to zero, and a source that does not parse evaluates to zero rather than
 * failing the actor.
 */
final class Expression
{
	private const CONSTANTS = [
		'true' => 1, 'false' => 0
		, 'chan_auto' => 0, 'chan_weapon' => 1, 'chan_voice' => 2, 'chan_item' => 3, 'chan_body' => 4
		, 'sxf_transfertranslation' => 1, 'sxf_absolutemomentum' => 2, 'sxf_absolutevelocity' => 2
		, 'sxf_absoluteposition' => 4, 'sxf_absoluteangle' => 8, 'sxf_nocheckposition' => 16, 'sxf_telefrag' => 32
		, 'sxf_clientside' => 64, 'sxf_transferambushflag' => 128, 'sxf_transferpitch' => 256
		, 'sxf_transferpointers' => 512, 'sxf_usebloodcolor' => 1024, 'sxf_clearcallertid' => 2048
		, 'sxf_multiplyspeed' => 4096, 'sxf_transferscale' => 8192, 'sxf_nopointers' => 16384, 'sxf_setmaster' => 32768
		, 'sxf_settarget' => 65536, 'sxf_settracer' => 131072
		, 'cmf_aimoffset' => 1, 'cmf_aimdirection' => 2, 'cmf_trackowner' => 4, 'cmf_checktargetdead' => 8
		, 'cmf_absolutepitch' => 16, 'cmf_offsetpitch' => 32, 'cmf_savepitch' => 64, 'cmf_absoluteangle' => 128
		, 'fbf_useammo' => 1, 'fbf_norandom' => 2, 'fbf_explicitangle' => 4, 'fbf_nopitch' => 8, 'fbf_noflash' => 16
		, 'fbf_norandompuffz' => 32
		, 'sf_norandom' => 1, 'sf_randomlightmiss' => 2, 'sf_randomlighthit' => 4, 'sf_nouseammomiss' => 8
		, 'sf_nouseammo' => 16, 'sf_nopullin' => 32, 'sf_noturn' => 64, 'sf_stealarmor' => 128
		, 'gff_noextchange' => 1
	];

	/** @var list<array{string,string|int|float}> Token kind and value. */
	private array $tokens = [];
	private int $position = 0;
	/** Parsed once at construction; a source the grammar does not cover evaluates to zero. */
	private bool $valid;
	/** @var null|callable(string):(int|float|null) */
	private $variables = null;

	public function __construct(public readonly string $source)
	{
		$this->valid = $this->tokenize($source);

		if($this->valid)
		{
			try
			{
				$this->position = 0;
				$this->expression(false);
				$this->valid = $this->position === count($this->tokens);
			}
			catch(\UnexpectedValueException)
			{
				$this->valid = false;
			}
		}
	}

	/** Whether a piece of argument text is an expression rather than a literal number, string, or bare name. */
	public static function looksLike(string $text): bool
	{
		return $text !== '' && !is_numeric($text) && !preg_match('/^[A-Za-z_][\w.]*$/', $text) && $text[0] !== '"';
	}

	/** @param null|callable(string):(int|float|null) $variables Named values such as `pitch`; unknown names are 0. */
	public function evaluate(?callable $variables = null): int|float
	{
		if(!$this->valid) return 0;

		$this->variables = $variables;
		$this->position = 0;

		try
		{
			return $this->expression(true);
		}
		catch(\UnexpectedValueException)
		{
			return 0;
		}
		finally
		{
			$this->variables = null;
		}
	}

	private function tokenize(string $source): bool
	{
		$pattern = '/\s*(?:(\d+\.\d*|\.\d+|\d+)|([A-Za-z_][\w.]*)|"((?:\\\\.|[^"\\\\])*)"|(<<|>>|[-+*\/%&|^()!~,<>]))/A';
		$offset = 0;
		$length = strlen($source);

		while($offset < $length)
		{
			if(!preg_match($pattern, $source, $m, 0, $offset))
			{
				return trim(substr($source, $offset)) === '' ? true : false;
			}

			$offset += strlen($m[0]);

			if(isset($m[4]) && $m[4] !== '') $this->tokens[] = ['op', $m[4]];
			elseif(isset($m[3]) && ($m[3] !== '' || str_contains($m[0], '"'))) $this->tokens[] = ['string', stripcslashes($m[3])];
			elseif(isset($m[2]) && $m[2] !== '') $this->tokens[] = ['name', $m[2]];
			elseif($m[1] !== '') $this->tokens[] = ['number', preg_match('/^\d+$/', $m[1]) ? (int)$m[1] : (float)$m[1]];
		}

		return true;
	}

	/** @return array{string,string|int|float}|null */
	private function peek(): ?array { return $this->tokens[$this->position] ?? null; }

	private function take(string $kind, string|int|float|null $value = null): array
	{
		$token = $this->peek();

		if($token === null || $token[0] !== $kind || ($value !== null && $token[1] !== $value))
		throw new \UnexpectedValueException('unexpected token in ' . $this->source);

		$this->position++;

		return $token;
	}

	private function accept(string ...$operators): ?string
	{
		$token = $this->peek();

		if($token !== null && $token[0] === 'op' && in_array($token[1], $operators, true))
		{
			$this->position++;

			return $token[1];
		}

		return null;
	}

	/** C precedence from loosest to tightest: `|`, `^`, `&`, shifts, additive, multiplicative, unary. */
	private function expression(bool $run): int|float
	{
		$value = $this->exclusive($run);

		while($this->accept('|') !== null) $value = self::integer($value) | self::integer($this->exclusive($run));

		return $value;
	}

	private function exclusive(bool $run): int|float
	{
		$value = $this->conjunction($run);

		while($this->accept('^') !== null) $value = self::integer($value) ^ self::integer($this->conjunction($run));

		return $value;
	}

	private function conjunction(bool $run): int|float
	{
		$value = $this->shift($run);

		while($this->accept('&') !== null) $value = self::integer($value) & self::integer($this->shift($run));

		return $value;
	}

	private function shift(bool $run): int|float
	{
		$value = $this->additive($run);

		while(($operator = $this->accept('<<', '>>')) !== null)
		{
			$right = min(31, max(0, self::integer($this->additive($run))));
			$value = $operator === '<<' ? self::integer($value) << $right : self::integer($value) >> $right;
		}

		return $value;
	}

	private function additive(bool $run): int|float
	{
		$value = $this->multiplicative($run);

		while(($operator = $this->accept('+', '-')) !== null)
		{
			$right = $this->multiplicative($run);
			$value = $operator === '+' ? $value + $right : $value - $right;
		}

		return $value;
	}

	private function multiplicative(bool $run): int|float
	{
		$value = $this->unary($run);

		while(($operator = $this->accept('*', '/', '%')) !== null)
		{
			$right = $this->unary($run);

			if($operator === '*') $value *= $right;
			elseif($right == 0) $value = 0;
			elseif($operator === '%') $value = self::integer($value) % self::integer($right);
			// ZDoom divides integers as integers.
			elseif(is_int($value) && is_int($right)) $value = intdiv($value, $right);
			else $value /= $right;
		}

		return $value;
	}

	private function unary(bool $run): int|float
	{
		$operator = $this->accept('-', '+', '!', '~');

		if($operator === '-') return -$this->unary($run);

		if($operator === '+') return $this->unary($run);

		if($operator === '!') return $this->unary($run) == 0 ? 1 : 0;

		if($operator === '~') return ~self::integer($this->unary($run));

		return $this->primary($run);
	}

	private function primary(bool $run): int|float
	{
		$token = $this->peek();

		if($token === null) throw new \UnexpectedValueException('expression ends early: ' . $this->source);

		if($token[0] === 'number')
		{
			$this->position++;

			return $token[1];
		}

		if($token[0] === 'string')
		{
			$this->position++;

			return 0;
		}

		if($token[0] === 'op' && $token[1] === '(')
		{
			$this->position++;
			$value = $this->expression($run);
			$this->take('op', ')');

			return $value;
		}

		$name = strtolower((string)$this->take('name')[1]);

		if($this->accept('(') !== null)
		{
			$arguments = [];

			if($this->accept(')') === null)
			{
				do $arguments[] = $this->expression($run);
				while($this->accept(',') !== null);

				$this->take('op', ')');
			}

			return $run ? self::call($name, $arguments) : 0;
		}

		if(isset(self::CONSTANTS[$name])) return self::CONSTANTS[$name];

		if(!$run) return 0;

		$value = $this->variables !== null ? ($this->variables)($name) : null;

		return $value ?? 0;
	}

	/** @param list<int|float> $arguments */
	private static function call(string $name, array $arguments): int|float
	{
		switch($name)
		{
			case 'random':
				$low = self::integer($arguments[0] ?? 0);
				$high = self::integer($arguments[1] ?? 255);

				return $low <= $high ? mt_rand($low, $high) : mt_rand($high, $low);
			case 'frandom':
				$low = (float)($arguments[0] ?? 0);
				$high = (float)($arguments[1] ?? 1);

				return $low + ($high - $low) * mt_rand(0, 0x7fffffff) / 0x7fffffff;
			case 'random2':
				$mask = self::integer($arguments[0] ?? 255);

				return mt_rand(0, $mask) - mt_rand(0, $mask);
			case 'abs': return abs($arguments[0] ?? 0);
			case 'min': return $arguments ? min($arguments) : 0;
			case 'max': return $arguments ? max($arguments) : 0;
			default: return 0;
		}
	}

	private static function integer(int|float $value): int
	{
		return is_int($value) ? $value : (int)max(-2147483648, min(2147483647, $value));
	}
}
