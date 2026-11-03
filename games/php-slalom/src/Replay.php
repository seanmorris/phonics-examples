<?php
declare(strict_types=1);
namespace PhpSlalom;

/** Compact versioned replay with two input bits per fixed tick and an integrity checksum. */
final class Replay
{
	public const MAGIC = 'PSLR4';
	public const VERSION = 1;
	public const MIME = 'application/vnd.phonics.slalom-replay';
	public const MAX_BYTES = 1048576;

	/**
	 * Encode a complete deterministic run.
	 *
	 * @param int $stage Zero-based starting checkpoint.
	 * @param int $seed Nonnegative 31-bit course seed.
	 * @param int $startingScore Score associated with the starting checkpoint.
	 * @param list<int> $inputs Values from zero through three: left bit one, right bit two.
	 * @return string Versioned binary replay including a CRC-32 checksum.
	 */
	public static function encode(int $stage, int $seed, int $startingScore, array $inputs): string
	{
		self::validateHeader($stage, $startingScore);
		self::validateSeed($seed);

		if(count($inputs) > (self::MAX_BYTES - 24) * 4)
		throw new \LengthException('Replay is too long');

		$payload = str_repeat("\0", (int)ceil(count($inputs) / 4));

		foreach($inputs as $tick => $value)
		{
			if(!is_int($value) || $value < 0 || $value > 3)
			throw new \InvalidArgumentException('Replay inputs must contain two-bit integers');

			$byte = ord($payload[intdiv($tick, 4)]);
			$payload[intdiv($tick, 4)] = chr($byte | ($value << (($tick % 4) * 2)));
		}

		$body = self::MAGIC . chr(self::VERSION) . chr($stage) . pack('nNNN', 0, $seed, $startingScore, count($inputs))
			. $payload;

		return $body . hash('crc32b', $body, true);
	}

	/**
	 * Validate and unpack a replay before it can drive the simulation.
	 *
	 * @param string $bytes Complete binary `.pslr` contents.
	 * @return array{stage:int,seed:int,startingScore:int,tickCount:int,inputs:list<int>} Validated run metadata and
	 * tick inputs.
	 */
	public static function decode(string $bytes): array
	{
		$length = strlen($bytes);

		if($length < 25 || $length > self::MAX_BYTES) throw new \UnexpectedValueException('Invalid replay size');

		$body = substr($bytes, 0, -4);

		if(!hash_equals(substr($bytes, -4), hash('crc32b', $body, true)))
		throw new \UnexpectedValueException('Replay checksum does not match');

		if(substr($body, 0, 5) !== self::MAGIC || ord($body[5]) !== self::VERSION)
		throw new \UnexpectedValueException('Unsupported replay format');

		$stage = ord($body[6]);

		if(ord($body[9]) >= 128) throw new \UnexpectedValueException('Replay seed exceeds 31 bits');

		$data = unpack('nflags/Nseed/Nscore/Nticks', substr($body, 7, 14));

		if($data['flags'] !== 0) throw new \UnexpectedValueException('Unsupported replay flags');

		self::validateHeader($stage, $data['score']);
		self::validateSeed($data['seed']);
		$payload = substr($body, 21);
		$needed = (int)ceil($data['ticks'] / 4);

		if(strlen($payload) !== $needed) throw new \UnexpectedValueException('Replay payload length does not match');

		$inputs = [];

		for($tick = 0; $tick < $data['ticks']; $tick++)
		$inputs[] = (ord($payload[intdiv($tick, 4)]) >> (($tick % 4) * 2)) & 3;

		return [
			'stage' => $stage
			, 'seed' => $data['seed']
			, 'startingScore' => $data['score']
			, 'tickCount' => $data['ticks']
			, 'inputs' => $inputs
		];
	}

	/**
	 * Require a replay header to name a real starting stage with its matching score.
	 *
	 * @param int $stage Starting stage index from the replay.
	 * @param int $startingScore Score recorded for that starting stage.
	 * @return void Mismatched headers throw before any input is decoded.
	 */
	private static function validateHeader(int $stage, int $startingScore): void
	{
		if(!isset(Simulation::START_SCORES[$stage]) || Simulation::START_SCORES[$stage] !== $startingScore)
		throw new \InvalidArgumentException('Replay stage and starting score do not match');
	}

	/**
	 * Keep seeds portable across native and 32-bit WebAssembly PHP.
	 *
	 * @param int $seed Candidate replay seed.
	 * @return void Accepts only values the simulation can reproduce on both runtimes.
	 */
	private static function validateSeed(int $seed): void
	{
		if($seed < 0 || $seed > 2147483647)
		throw new \InvalidArgumentException('Replay seed must be a nonnegative 31-bit integer');
	}
}
