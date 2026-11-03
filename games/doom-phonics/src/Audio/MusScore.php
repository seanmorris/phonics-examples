<?php
declare(strict_types=1);
namespace DoomPhonics\Audio;

use DoomPhonics\Archive\Binary;
use DoomPhonics\Archive\Lump;

/** Bounds-checked MUS event stream with absolute 140 Hz event times. */
final class MusScore
{
	/** @var list<array<string,int|string>> */
	public array $events = [];
	/** @var list<int> */
	public array $instruments = [];
	public int $ticks = 0;

	public static function decode(Lump|string $source, string $identity = 'MUS'): self
	{
		$bytes = $source instanceof Lump?$source->bytes():$source;
		$identity = $source instanceof Lump?$source->identity():$identity;

		if(substr($bytes, 0, 4) !== "MUS\x1a")throw new \UnexpectedValueException("$identity: invalid MUS signature");

		$length = Binary::u16($bytes, 4, $identity);
		$start = Binary::u16($bytes, 6, $identity);
		$instrumentCount = Binary::u16($bytes, 12, $identity);
		Binary::slice($bytes, 16, $instrumentCount * 2, $identity);
		Binary::slice($bytes, $start, $length, $identity);
		$self = new self;

		for($i = 0; $i < $instrumentCount; $i++)$self->instruments[] = Binary::u16($bytes, 16 + $i * 2, $identity);
		$offset = $start;
		$end = $start + $length;
		$tick = 0;
		$volumes = array_fill(0, 16, 100);

		while($offset < $end)
		{
			$descriptor = ord($bytes[$offset++]);
			$channel = $descriptor & 15;
			$type = ($descriptor >> 4) & 7;
			$last = (bool)($descriptor & 128);
			$event = ['tick' => $tick,'channel' => $channel];

			switch($type)
			{
				case 0:$event += ['type' => 'release','note' => self::byte($bytes, $offset++, $end, $identity) & 127];
					break;
				case 1:
					$note = self::byte($bytes, $offset++, $end, $identity);
					$event += ['type' => 'press','note' => $note & 127];

					if($note & 128)$volumes[$channel] = self::byte($bytes, $offset++, $end, $identity) & 127;

					$event['volume'] = $volumes[$channel];
					break;
				case 2:$event += ['type' => 'pitch','value' => self::byte($bytes, $offset++, $end, $identity)];
					break;
				case 3:$event += [
					'type' => 'system'
					, 'controller' => self::byte($bytes, $offset++, $end, $identity) & 127
				];

					break;
				case 4:$event += [
					'type' => 'control'
					, 'controller' => self::byte($bytes, $offset++, $end, $identity) & 127
					, 'value' => self::byte($bytes, $offset++, $end, $identity) & 127
				];

					break;
				case 6:$event += ['type' => 'end'];
					$self->events[] = $event;
					$self->ticks = $tick;

					return $self;
				default:throw new \UnexpectedValueException("$identity: unsupported MUS event type $type");
			}

			$self->events[] = $event;

			if($last)
			{
				$delay = 0;

				do
				{
					$byte = self::byte($bytes, $offset++, $end, $identity);
					$delay = $delay * 128 + ($byte & 127);
				}while($byte & 128);

				$tick += $delay;
			}
		}

		$self->ticks = $tick;

		return $self;
	}

	public function duration(): float{return $this->ticks / 140;}

	public function json(): string
	{
		return json_encode([
			'ticks' => $this->ticks
			, 'rate' => 140
			, 'instruments' => $this->instruments
			, 'events' => $this->events
		], JSON_THROW_ON_ERROR
		);
	}

	private static function byte(string $bytes, int $offset, int $end, string $identity): int
	{
		if($offset < 0 || $offset >= $end || $offset >= strlen($bytes))
		throw new \UnexpectedValueException("$identity: truncated MUS event");

		return ord($bytes[$offset]);
	}
}
