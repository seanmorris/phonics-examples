<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Camera;

/**
 * Keeps a chase camera's eye out of the solid parts of race gates. Near a gate the eye ducks through the opening,
 * below the board, banner or arch and above the ground or water, then rises back once it has passed.
 *
 * A gate's solid parts lie in a thin slab across its direction of travel. The eye moves across the gate and up or
 * down, never along that direction. Within CLEARANCE of the slab it stands in one of three passages: through the
 * opening, over the gate or under it. Beyond the gate's ends it passes no solid part and stays where it is. When the
 * eye comes within the lead distance of the slab, the passage whose heights lie nearest the followed vehicle is held
 * until the eye leaves, so the camera follows the vehicle through. Over the lead distance either side of the slab
 * the eye blends back to where the camera put it. The blend depends only on the eye's position, so an eye that
 * moves smoothly stays smooth.
 *
 * One case jumps: an eye that crosses a post or an arch leg sideways within the slab goes to the nearer side of it,
 * so it changes sides halfway across. No continuous rule can carry an eye round a post's footprint.
 *
 * Callers apply the result to the eye they draw from. The camera's own state does not change, so the vehicle and the
 * camera's damping run exactly as they do without gates.
 */
final class GateDuck
{
	/** Distance in metres the eye keeps from every solid part. The near plane's corners are 0.39 m from the eye at 16:9. */
	public const CLEARANCE = .5;
	/**
	 * Distance in metres over which a push across the gate fades in along the direction of travel, and over which the
	 * duck fades out beyond the gate's ends.
	 */
	public const SIDE = 2.0;
	/** Passage held for each gate id while the eye is within the lead distance of the gate's slab. */
	private array $held = [];
	/** Eye offset [x, y, z] in metres at the last step, for the status. */
	public array $offset = [0.0, 0.0, 0.0];

	/**
	 * @param float $lead Distance in metres before and after a gate's slab over which the eye ducks and rises again.
	 * @throws \InvalidArgumentException When the lead is shorter than SIDE.
	 */
	public function __construct(public readonly float $lead)
	{
		if(!($lead >= self::SIDE)) throw new \InvalidArgumentException('A camera ducks over at least ' . self::SIDE . ' m');
	}

	/**
	 * Radius in metres to ask Course::obstructions() for. A gate whose solid parts lie farther from the eye cannot move
	 * it: the duck fades out within SIDE beyond a gate's ends and the lead distance beyond its slab.
	 */
	public function reach(): float { return $this->lead + 2 * self::CLEARANCE + self::SIDE; }

	/**
	 * Hold a passage for each gate the eye has come near, and release the gates it has left. Call once per fixed step.
	 * @param list<array> $gates Gates near the eye, from Course::obstructions().
	 * @param array $eye Eye position [x, y, z] in metres, as the camera placed it.
	 * @param array $target Position [x, y, z] of the vehicle the camera follows.
	 * @return void Updates the held passages and the offset the status reports.
	 */
	public function step(array $gates, array $eye, array $target): void
	{
		$held = [];
		foreach($gates as $gate)
		{
			$frame = self::measure($gate);
			[$u, $v] = self::local($frame, $eye);
			$distance = self::outside($frame, $v);
			if($distance >= $this->lead) continue;
			$held[$gate['id']] = $this->held[$gate['id']]
				?? self::choose($frame, $u, $eye[1], $target[1], $distance < $this->lead / 2);
		}
		$this->held = $held;
		$moved = $this->place($gates, $eye, $target);
		$this->offset = [$moved[0] - $eye[0], $moved[1] - $eye[1], $moved[2] - $eye[2]];
	}

	/**
	 * Move an eye out of the solid parts of nearby gates.
	 * @param list<array> $gates Gates near the eye, from Course::obstructions(), applied in this order.
	 * @param array $eye Eye position [x, y, z] in metres.
	 * @param array $target Position [x, y, z] of the vehicle the camera follows. It chooses the passage past a gate
	 * the last step held none for.
	 * @return array The eye position, the same array when no gate moves it.
	 */
	public function place(array $gates, array $eye, array $target): array
	{
		foreach($gates as $gate)
		{
			$frame = self::measure($gate);
			[$u, $v] = self::local($frame, $eye);
			$distance = self::outside($frame, $v);
			if($distance >= $this->lead) continue;
			$passage = $this->held[$gate['id']]
				?? self::choose($frame, $u, $eye[1], $target[1], $distance < $this->lead / 2);
			[$du, $dy] = $this->shift($frame, $passage, $u, $eye[1], $distance);
			if($du == 0 && $dy == 0) continue;
			$eye = [$eye[0] + $du * $frame['cos'], $eye[1] + $dy, $eye[2] - $du * $frame['sin']];
		}
		return $eye;
	}

	/**
	 * Move a camera's eye out of the solid parts of nearby gates and turn it to keep looking at the same point.
	 * @param Camera $camera Camera as its owner placed it. It is not changed.
	 * @param array $target Position [x, y, z] of the vehicle the camera follows. The view keeps looking at the point
	 * it looked at this far from the eye.
	 * @param list<array> $gates Gates near the eye, from Course::obstructions().
	 * @return Camera The same camera when no gate moves it, otherwise a moved copy.
	 */
	public function apply(Camera $camera, array $target, array $gates): Camera
	{
		$eye = $camera->position;
		$moved = $this->place($gates, $eye, $target);
		if($moved === $eye) return $camera;
		$distance = max(1.0, sqrt(($target[0] - $eye[0]) ** 2 + ($target[1] - $eye[1]) ** 2 + ($target[2] - $eye[2]) ** 2));
		[$forward] = $camera->basis();
		$look = [];
		for($i = 0; $i < 3; $i++) $look[] = $eye[$i] + $forward[$i] * $distance - $moved[$i];
		$view = clone $camera;
		$view->position = $moved;
		$yaw = atan2($look[0], -$look[2]);
		$view->yaw = $camera->yaw + atan2(sin($yaw - $camera->yaw), cos($yaw - $camera->yaw));
		$view->pitch = atan2($look[1], sqrt($look[0] * $look[0] + $look[2] * $look[2]));
		return $view;
	}

	/**
	 * Report the passage held for each gate.
	 * @return array<int,string> through, over or under, by gate id.
	 */
	public function held(): array { return $this->held; }

	/**
	 * Work out a gate's frame, the box its solid parts fill and the box of its opening that keeps CLEARANCE.
	 * @param array $gate One gate from Course::obstructions().
	 * @return array Opening centre and heading, the solid parts' bounds across the gate (u), up (y) and along its
	 * travel (v) from the opening's centre, and the clear opening's bounds, or null bounds when it has no room.
	 */
	private static function measure(array $gate): array
	{
		$opening = $gate['opening'];
		$cos = cos($opening['yaw']); $sin = sin($opening['yaw']);
		[$ox, , $oz] = $opening['centre'];
		$c = self::CLEARANCE;
		$parts = [];
		$low = [INF, INF, INF]; $high = [-INF, -INF, -INF];
		foreach($gate['solids'] as $box)
		{
			$dx = $box['centre'][0] - $ox; $dz = $box['centre'][2] - $oz;
			$u = $dx * $cos - $dz * $sin; $v = $dx * $sin + $dz * $cos;
			$turn = $box['yaw'] - $opening['yaw'];
			$across = abs(cos($turn)); $along = abs(sin($turn));
			$halfU = $across * $box['halfX'] + $along * $box['halfZ'];
			$halfV = $along * $box['halfX'] + $across * $box['halfZ'];
			$part = [$u - $halfU, $box['centre'][1] - $box['halfY'], $v - $halfV,
				$u + $halfU, $box['centre'][1] + $box['halfY'], $v + $halfV];
			$parts[] = $part;
			for($i = 0; $i < 3; $i++) { $low[$i] = min($low[$i], $part[$i]); $high[$i] = max($high[$i], $part[$i + 3]); }
		}
		// The clear opening keeps CLEARANCE inside the window. A solid part that still reaches into it, such as a base
		// plate at a corner, trims whichever edge leaves the most room.
		$clear = [-$opening['halfWidth'] + $c, $opening['bottom'] + $c, $opening['halfWidth'] - $c, $opening['top'] - $c];
		foreach($parts as [$u0, $y0, , $u1, $y1])
		{
			if($clear === null) break;
			if($u0 - $c >= $clear[2] - 1e-9 || $u1 + $c <= $clear[0] + 1e-9
				|| $y0 - $c >= $clear[3] - 1e-9 || $y1 + $c <= $clear[1] + 1e-9) continue;
			$best = null; $room = 0.0;
			foreach([[0, $u1 + $c], [1, $y1 + $c], [2, $u0 - $c], [3, $y0 - $c]] as [$edge, $value])
			{
				$trimmed = $clear;
				$trimmed[$edge] = $value;
				$area = ($trimmed[2] - $trimmed[0]) * ($trimmed[3] - $trimmed[1]);
				if($trimmed[2] > $trimmed[0] && $trimmed[3] >= $trimmed[1] && $area >= $room) { $best = $trimmed; $room = $area; }
			}
			$clear = $best;
		}
		if($clear !== null && ($clear[2] <= $clear[0] || $clear[3] < $clear[1])) $clear = null;
		return ['x' => $ox, 'z' => $oz, 'cos' => $cos, 'sin' => $sin, 'low' => $low, 'high' => $high, 'clear' => $clear];
	}

	/**
	 * Express an eye position in a gate's frame.
	 * @return array{0:float,1:float} Metres across the gate (u) and along its travel (v) from the opening's centre.
	 */
	private static function local(array $frame, array $eye): array
	{
		$dx = $eye[0] - $frame['x']; $dz = $eye[2] - $frame['z'];
		return [$dx * $frame['cos'] - $dz * $frame['sin'], $dx * $frame['sin'] + $dz * $frame['cos']];
	}

	/** Distance in metres along a gate's travel from v to the slab that keeps CLEARANCE from its solid parts. */
	private static function outside(array $frame, float $v): float
	{
		return max(0.0, $frame['low'][2] - self::CLEARANCE - $v, $v - $frame['high'][2] - self::CLEARANCE);
	}

	/**
	 * Choose a passage past a gate.
	 * @param array $frame The gate's measurements.
	 * @param float $u The eye's position across the gate.
	 * @param float $eye The eye's height.
	 * @param float $vehicle The followed vehicle's height.
	 * @param bool $deep Whether the eye is already more than halfway into the blend. An eye that stands inside the
	 * opening's span at the height of a passage keeps that passage, so a gate placed beside it does not move it.
	 * @return string through, over or under: the passage whose heights lie nearest the vehicle, preferring that order.
	 */
	private static function choose(array $frame, float $u, float $eye, float $vehicle, bool $deep): string
	{
		$c = self::CLEARANCE;
		$clear = $frame['clear'];
		$ranges = ['through' => $clear === null ? null : [$clear[1], $clear[3]], 'over' => [$frame['high'][1] + $c, INF],
			'under' => [-INF, $frame['low'][1] - $c]];
		if($deep && $clear !== null && $u >= $clear[0] && $u <= $clear[2])
			foreach($ranges as $passage => $range) if($range !== null && $eye >= $range[0] && $eye <= $range[1]) return $passage;
		$best = 'through'; $nearest = INF;
		foreach($ranges as $passage => $range)
		{
			if($range === null) continue;
			$gap = max(0.0, $range[0] - $vehicle, $vehicle - $range[1]);
			if($gap < $nearest) { $best = $passage; $nearest = $gap; }
		}
		return $best;
	}

	/**
	 * Work out how far one gate moves the eye.
	 * @param array $frame The gate's measurements.
	 * @param string $passage through, over or under.
	 * @param float $u The eye's position across the gate.
	 * @param float $y The eye's height.
	 * @param float $distance Distance along the travel from the eye to the gate's slab.
	 * @return array{0:float,1:float} Metres across the gate and up.
	 */
	private function shift(array $frame, string $passage, float $u, float $y, float $distance): array
	{
		$c = self::CLEARANCE;
		$clear = $frame['clear'];
		if($passage === 'through' && $clear === null) $passage = $y > $frame['high'][1] ? 'over' : 'under';
		$du = 0.0;
		if($passage === 'through' && $distance < self::SIDE)
		{
			// Beside the opening, a post or a leg fills the gate's width out to its ends. An eye there goes to the
			// nearer of the opening's edge and the gate's end.
			$right = 2 * $u >= $clear[0] + $clear[2];
			[$inner, $outer] = $right ? [$clear[2], $frame['high'][0] + $c] : [$clear[0], $frame['low'][0] - $c];
			if($right ? $u > $inner && $u < $outer : $u < $inner && $u > $outer)
				$du = self::smooth(1 - $distance / self::SIDE) * (abs($u - $inner) <= abs($outer - $u) ? $inner - $u : $outer - $u);
		}
		$u += $du;
		$target = match($passage) {
			'through' => max($clear[1], min($clear[3], $y)),
			'over' => max($y, $frame['high'][1] + $c),
			'under' => min($y, $frame['low'][1] - $c),
		};
		if($target == $y) return [$du, 0.0];
		// Beyond the gate's ends the eye passes no solid part, so the duck fades out there.
		$beyond = max(0.0, $frame['low'][0] - $c - $u, $u - $frame['high'][0] - $c);
		if($beyond >= self::SIDE) return [$du, 0.0];
		return [$du, self::smooth(1 - $distance / $this->lead) * self::smooth(1 - $beyond / self::SIDE) * ($target - $y)];
	}

	/** Smoothstep from 0 at 0 to 1 at 1, flat at both ends. */
	private static function smooth(float $t): float
	{
		if($t >= 1) return 1.0;
		return $t <= 0 ? 0.0 : $t * $t * (3 - 2 * $t);
	}
}
