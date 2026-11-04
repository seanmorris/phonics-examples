<?php
declare(strict_types=1);
namespace PhpBilliards\Physics;

/**
 * Optional synchronous arithmetic backend for compliant ball contacts.
 *
 * Packed little-endian doubles preserve bits at the bridge. PHP retains authoritative Ball objects,
 * the swept boundary scheduler, and the reference implementation.
 */
final class ContactKernel
{
	private static ?\Closure $backend = null;

	/**
	 * @param callable|null $backend Packed synchronous batch callback, or null to use the PHP reference.
	 */
	public static function install(?callable $backend): void
	{
		self::$backend = $backend === null ? null : \Closure::fromCallable($backend);
	}

	public static function available(): bool { return self::$backend !== null; }

	/**
	 * Raw rows: x, drop, table-y, vx, downward speed, vy, spin XYZ, quaternion XYZW, pocket (-1 for cloth), active.
	 * Store drop directly: converting it to height twice at the bridge can lose a floating-point bit.
	 * @param list<Ball> $balls
	 */
	private static function rows(array $balls): array
	{
		$data = [];
		foreach($balls as $b) array_push($data, $b->x,$b->drop,$b->y,$b->vx,$b->vDrop,$b->vy,
			$b->spinX,$b->spinY,$b->spinZ,$b->qx,$b->qy,$b->qz,$b->qw,(float)($b->pocket ?? -1),$b->active ? 1.0 : 0.0);
		return $data;
	}

	/**
	 * The backend yields before any possible cloth boundary; PHP owns its exact sweep and resolution.
	 * Pocket constraints run every microstep. A zero-time yield leaves all state unchanged.
	 * maxSteps=1 also accelerates an already swept single step, without repeating its sink-time subdivision.
	 * @param list<Ball> $balls Active balls in ascending number order.
	 * @param float $remaining Unconsumed time in the current simulation slice.
	 * @param array<string,array{Ball,Ball}> $contacts Presentation latches for continuous impacts.
	 * @param int $maxSteps Maximum accepted microsteps before returning to PHP.
	 * @return array{float,array<string,array{Ball,Ball}>,list<array>} Remainder, touching pairs, ordered events.
	 */
	public static function advance(array $balls, float $remaining, array $contacts, int $maxSteps): array
	{
		$indices = [];
		foreach($balls as $i => $b) $indices[$b->number] = $i;
		$masks = array_fill(0, 16, 0);
		foreach($contacts as [$a,$b]) if(isset($indices[$a->number], $indices[$b->number]))
		$masks[$indices[$a->number]] |= 1 << $indices[$b->number];
		[$hertz,$damping] = ContactModel::coefficients();
		$input = pack('e*', ...[ContactModel::MAX_STEP,$hertz,$damping,Ball::MASS,Ball::RADIUS,ContactModel::GRAVITY,
			$remaining,$maxSteps,...$masks,...self::rows($balls)]);
		$bytes = base64_decode((self::$backend)(base64_encode($input)), true);
		if($bytes === false || strlen($bytes) % 8 !== 0) throw new \UnexpectedValueException('Invalid contact kernel result');
		$data = array_values(unpack('e*', $bytes));
		if(count($data) < 18) throw new \UnexpectedValueException('Incomplete contact batch');
		// Result header: remainder, 16 contact masks, event count. Each event is type, body index, pocket,
		// strength, depth; codes 1..4 mean impact, rattle, reject, sink. Raw ball rows follow the events.
		$eventCount = (int)$data[17]; $offset = 18 + 5 * $eventCount;
		if($eventCount < 0 || count($data) !== $offset + 15 * count($balls))
		throw new \UnexpectedValueException('Incomplete contact kernel result');
		foreach($balls as $i => $b)
		{
			$row = array_slice($data, $offset + $i * 15, 15);
			[$b->x,$b->drop,$b->y,$b->vx,$b->vDrop,$b->vy,$b->spinX,$b->spinY,$b->spinZ,
				$b->qx,$b->qy,$b->qz,$b->qw,$pocket,$active] = $row;
			$b->pocket = $pocket < 0 ? null : (int)$pocket;
			$b->active = $active !== 0.0;
		}
		$events = [];
		for($i = 0; $i < $eventCount; $i++)
		{
			[$type,$index,$pocket,$strength,$depth] = array_slice($data, 18 + 5 * $i, 5);
			if($type === 1.0) { $events[] = ['type' => 'impact','strength' => (float)min(1, $strength)]; continue; }
			$b = $balls[(int)$index];
			$event = ['type' => match((int)$type) {2 => 'pocket-rattle',3 => 'pocket-reject',4 => 'pocket'},
				'ball' => $b->number,'pocket' => (int)$pocket];
			if($type === 2.0) $event += ['strength' => (float)min(1, $strength),'depth' => $depth];
			else $b->previousDrop = $type === 3.0 ? 0.0 : PocketModel::SINK_DEPTH;
			$events[] = $event;
		}
		$contacts = [];
		foreach($balls as $i => $b)
		foreach($balls as $j => $other) if((int)$data[1 + $i] & (1 << $j))
		$contacts[$b->number . ':' . $other->number] = [$b,$other];
		return [$data[0],$contacts,$events];
	}
}
