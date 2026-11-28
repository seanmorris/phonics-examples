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
 * the eye blends back to where the camera put it.
 *
 * On either side of the slab, an eye that climbs above the gate's parts, or sinks below them, beyond the height it
 * took its passage at, takes less of the duck the farther it has gone and the farther it is from the slab. Once
 * that height times that distance reaches RELEASE, the gate lets the eye go and chooses its passage afresh. An F5
 * that loops up in front of a gate takes its eye high over the gate this way, instead of pulling it down to the
 * opening. The eye keeps its passage within the slab and can release it after crossing if it climbs away.
 *
 * Within SIDE of the slab, a post or an arch leg stands beside the opening. On coming that near, the eye takes the
 * side of the post nearer to it, the opening or the gate's end, and keeps to that side while it stays near. An eye
 * that carries on sideways into the post, as the look stick swings it, stays at the post's side until it is half
 * way across, then goes round the face of the slab nearer to it and comes back in on the far side of the post as
 * the eye reaches it.
 *
 * Every blend depends only on the eye's position and the passage and side held, which change only where they move
 * the eye by nothing, so an eye that moves smoothly stays smooth. One case jumps: an eye that goes more than half way
 * into a post and then through the slab along the travel changes from one face of the slab to the other as it
 * passes the slab's middle.
 *
 * Callers apply the result to the eye they draw from. The camera's own state does not change, so the vehicle and the
 * camera's damping run exactly as they do without gates.
 */
final class GateDuck
{
	/** Distance in meters the eye keeps from every solid part. The near plane's corners are 0.39 m from the eye at 16:9. */
	public const CLEARANCE = .5;
	/**
	 * Distance in meters over which a push across the gate fades in along the direction of travel, and over which the
	 * duck fades out beyond the gate's ends.
	 */
	public const SIDE = 2.0;
	/**
	 * Square meters at which a gate lets go of an eye that has climbed or sunk past its parts: the height beyond them
	 * and beyond the height the eye held its passage at, times the distance from the slab. 4 m up 10 m out lets go.
	 */
	public const RELEASE = 40.0;
	/** Passage held for each gate id while the eye is within the lead distance of the gate's slab. */
	private array $held = [];
	/** Height of the eye when it took each held passage, by gate id. */
	private array $taken = [];
	/**
	 * Side held for each gate id while the eye is within SIDE of the gate's slab: -1 beyond the -X post or leg, 0 the
	 * opening between them, 1 beyond the +X post or leg.
	 */
	private array $lanes = [];
	/** Eye offset [x, y, z] in meters at the last step, for the status. */
	public array $offset = [0.0, 0.0, 0.0];

	/**
	 * @param float $lead Distance in meters before and after a gate's slab over which the eye ducks and rises again.
	 * @param ?\Closure $ground Road or terrain elevation in meters at the final eye X and Z.
	 * @throws \InvalidArgumentException When the lead is shorter than SIDE.
	 */
	public function __construct(public readonly float $lead, private readonly ?\Closure $ground = null)
	{
		if(!($lead >= self::SIDE)) throw new \InvalidArgumentException('A camera ducks over at least ' . DisplayUnits::feet(self::SIDE) . ' ft');
	}

	/**
	 * Radius in meters to ask Course::obstructions() for. A gate whose solid parts lie farther from the eye cannot move
	 * it: the duck fades out within SIDE beyond a gate's ends and the lead distance beyond its slab.
	 */
	public function reach(): float { return $this->lead + 2 * self::CLEARANCE + self::SIDE; }

	/**
	 * Hold a passage for each gate the eye has come near, and release the gates it has left. Call once per fixed step.
	 * @param list<array> $gates Gates near the eye, from Course::obstructions().
	 * @param array $eye Eye position [x, y, z] in meters, as the camera placed it.
	 * @param array $target Position [x, y, z] of the vehicle the camera follows.
	 * @return void Updates the held passages and the offset the status reports.
	 */
	public function step(array $gates, array $eye, array $target): void
	{
		$held = $taken = $lanes = [];
		foreach($gates as $gate)
		{
			$id = $gate['id'];
			$frame = self::measure($gate);
			[$u, $v] = self::local($frame, $eye);
			$distance = self::outside($frame, $v);
			$from = $this->taken[$id] ?? $eye[1];
			if($this->released($frame, $eye[1], $from, $distance)) continue;
			$held[$id] = $this->held[$id] ?? self::choose($frame, $u, $eye[1], $target[1], $distance < $this->lead / 2);
			$taken[$id] = $from;
			if($distance < self::SIDE) $lanes[$id] = self::lane($frame, $u, $this->lanes[$id] ?? null);
		}
		$this->held = $held;
		$this->taken = $taken;
		$this->lanes = $lanes;
		$moved = $this->place($gates, $eye, $target);
		$this->offset = [$moved[0] - $eye[0], $moved[1] - $eye[1], $moved[2] - $eye[2]];
	}

	/**
	 * Move an eye out of the solid parts of nearby gates.
	 * @param list<array> $gates Gates near the eye, from Course::obstructions(), applied in this order.
	 * @param array $eye Eye position [x, y, z] in meters.
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
			$from = $this->taken[$gate['id']] ?? $eye[1];
			if($this->released($frame, $eye[1], $from, $distance)) continue;
			$passage = $this->held[$gate['id']]
				?? self::choose($frame, $u, $eye[1], $target[1], $distance < $this->lead / 2);
			$lane = self::lane($frame, $u, $this->lanes[$gate['id']] ?? null);
			[$du, $dy, $dv] = $this->shift($frame, $passage, $lane, $u, $v, $eye[1],
				self::share($frame, $eye[1], $from, $distance), $distance);
			if($du != 0 || $dy != 0 || $dv != 0)
				$eye = [$eye[0] + $du * $frame['cos'] + $dv * $frame['sin'], $eye[1] + $dy,
					$eye[2] - $du * $frame['sin'] + $dv * $frame['cos']];
			if($this->ground === null || $u < $frame['low'][0] - self::SIDE - self::CLEARANCE
				|| $u > $frame['high'][0] + self::SIDE + self::CLEARANCE) continue;
			// A bank can rise under a sideways dodge, or between the two chase poses being interpolated.
			$floor = ($this->ground)($eye[0], $eye[2]) + self::CLEARANCE;
			if($eye[1] >= $floor) continue;
			$eye[1] = $floor;
			// If raised ground closes the opening, clear any solid the lift would put the eye inside.
			[$across, $along] = self::local($frame, $eye);
			foreach($frame['parts'] as [$u0, $y0, $v0, $u1, $y1, $v1])
			{
				if($across > $u0 - self::CLEARANCE && $across < $u1 + self::CLEARANCE
					&& $along > $v0 - self::CLEARANCE && $along < $v1 + self::CLEARANCE
					&& $eye[1] > $y0 - self::CLEARANCE && $eye[1] < $y1 + self::CLEARANCE)
				{
					$eye[1] = $frame['high'][1] + self::CLEARANCE;
					break;
				}
			}
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
	 * @return array Opening center and heading, the solid parts' bounds across the gate (u), up (y) and along its
	 * travel (v) from the opening's center, the clear opening's bounds, or null bounds when it has no room, how far
	 * across the parts beside the clear opening reach on each side, and the lowest height of the parts or the opening.
	 */
	private static function measure(array $gate): array
	{
		$opening = $gate['opening'];
		$cos = cos($opening['yaw']); $sin = sin($opening['yaw']);
		[$ox, , $oz] = $opening['center'];
		$c = self::CLEARANCE;
		$parts = [];
		$low = [INF, INF, INF]; $high = [-INF, -INF, -INF];
		foreach($gate['solids'] as $box)
		{
			$dx = $box['center'][0] - $ox; $dz = $box['center'][2] - $oz;
			$u = $dx * $cos - $dz * $sin; $v = $dx * $sin + $dz * $cos;
			$turn = $box['yaw'] - $opening['yaw'];
			$across = abs(cos($turn)); $along = abs(sin($turn));
			$halfU = $across * $box['halfX'] + $along * $box['halfZ'];
			$halfV = $along * $box['halfX'] + $across * $box['halfZ'];
			$part = [$u - $halfU, $box['center'][1] - $box['halfY'], $v - $halfV,
				$u + $halfU, $box['center'][1] + $box['halfY'], $v + $halfV];
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
		// The parts beside the clear opening at its heights, such as posts and legs, reach this far out on each side
		// with the clearance; null where nothing stands there, as beside an air gate's window.
		$sides = [null, null];
		if($clear !== null) foreach($parts as [$u0, $y0, , $u1, $y1])
		{
			if($y0 - $c >= $clear[3] - 1e-9 || $y1 + $c <= $clear[1] + 1e-9) continue;
			if($u0 + $u1 >= $clear[0] + $clear[2]) $sides[1] = max($sides[1] ?? -INF, $u1 + $c);
			else $sides[0] = min($sides[0] ?? INF, $u0 - $c);
		}
		return ['x' => $ox, 'z' => $oz, 'cos' => $cos, 'sin' => $sin, 'low' => $low, 'high' => $high, 'clear' => $clear,
			'sides' => $sides, 'bottom' => min($low[1], $opening['bottom']), 'parts' => $parts, 'kind' => $gate['kind']];
	}

	/**
	 * Express an eye position in a gate's frame.
	 * @return array{0:float,1:float} Meters across the gate (u) and along its travel (v) from the opening's center.
	 */
	private static function local(array $frame, array $eye): array
	{
		$dx = $eye[0] - $frame['x']; $dz = $eye[2] - $frame['z'];
		return [$dx * $frame['cos'] - $dz * $frame['sin'], $dx * $frame['sin'] + $dz * $frame['cos']];
	}

	/** Distance in meters along a gate's travel from v to the slab that keeps CLEARANCE from its solid parts. */
	private static function outside(array $frame, float $v): float
	{
		return max(0.0, $frame['low'][2] - self::CLEARANCE - $v, $v - $frame['high'][2] - self::CLEARANCE);
	}

	/**
	 * Share of its passage's duck that a gate gives an eye. It is 1 within the slab. Outside the slab it
	 * falls to 0 as the height the eye has climbed or sunk past the gate's parts and its opening, with the clearance,
	 * and past the height it took its passage at, times its distance from the slab, grows to RELEASE.
	 * @param float $y The eye's height.
	 * @param float $from Height where the eye took its passage.
	 */
	private static function share(array $frame, float $y, float $from, float $distance): float
	{
		$c = self::CLEARANCE;
		$gone = max(0.0, min($frame['bottom'] - $c, $from) - $y, $y - max($frame['high'][1] + $c, $from));
		return self::smooth(1 - sqrt($gone * $distance / self::RELEASE));
	}

	/** Whether a gate moves an eye by nothing, whatever passage it holds, so it can let go of it. */
	private function released(array $frame, float $y, float $from, float $distance): bool
	{
		return $distance >= $this->lead || self::share($frame, $y, $from, $distance) == 0;
	}

	/**
	 * Choose the side of a gate's posts or legs an eye within SIDE of its slab keeps to.
	 * @param array $frame The gate's measurements.
	 * @param float $u The eye's position across the gate.
	 * @param int|null $held The side held since the eye came near, or null when none is.
	 * @return int -1 beyond the -X post or leg, 0 the opening, 1 beyond the +X post or leg. An eye keeps the side it
	 * holds until it has crossed the whole post or leg to another; otherwise it takes the nearer side of the post or
	 * leg it stands at, or the opening where none stands.
	 */
	private static function lane(array $frame, float $u, ?int $held): int
	{
		$clear = $frame['clear'];
		if($clear === null) return 0;
		[$left, $right] = $frame['sides'];
		$crossed = match($held) {
			null => true,
			-1 => $u > $clear[0],
			1 => $u < $clear[2],
			default => ($left !== null && $u < $left) || ($right !== null && $u > $right),
		};
		if(!$crossed) return $held;
		$side = 2 * $u >= $clear[0] + $clear[2] ? 1 : -1;
		[$inner, $outer] = $side > 0 ? [$clear[2], $right] : [$clear[0], $left];
		if($outer === null || ($u - $inner) * $side <= 0) return 0;
		return ($outer - $u) * $side <= 0 || abs($outer - $u) < abs($u - $inner) ? $side : 0;
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
			'under' => $frame['kind'] === 'road' ? null : [-INF, $frame['low'][1] - $c]];
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
	 * @param int $lane The side of the posts or legs the eye keeps to, as lane() gives it.
	 * @param float $u The eye's position across the gate.
	 * @param float $v The eye's position along the travel.
	 * @param float $y The eye's height.
	 * @param float $share Share of the duck the gate gives the eye, from share().
	 * @param float $distance Distance along the travel from the eye to the gate's slab.
	 * @return array{0:float,1:float,2:float} Meters across the gate, up and along the travel.
	 */
	private function shift(array $frame, string $passage, int $lane, float $u, float $v, float $y, float $share,
		float $distance): array
	{
		$c = self::CLEARANCE;
		$clear = $frame['clear'];
		if($passage === 'through' && $clear === null) $passage = $y > $frame['high'][1] ? 'over' : 'under';
		$du = $dv = 0.0;
		if($passage === 'through' && $distance < self::SIDE)
		{
			[$du, $dv] = self::round($frame, $lane, $u, $v);
			$du *= $share * self::smooth(1 - $distance / self::SIDE);
		}
		$u += $du;
		// Only a gate with parts below its opening, such as posts and legs, raises an eye that is lower than the
		// opening. Nothing stands under an air gate's window.
		$target = match($passage) {
			'through' => max($frame['low'][1] < $clear[1] ? $clear[1] : -INF, min($clear[3], $y)),
			'over' => max($y, $frame['high'][1] + $c),
			'under' => min($y, $frame['low'][1] - $c),
		};
		if($target == $y) return [$du, 0.0, $dv];
		// Beyond the gate's ends the eye passes no solid part, so the duck fades out there.
		$beyond = max(0.0, $frame['low'][0] - $c - $u, $u - $frame['high'][0] - $c);
		if($beyond >= self::SIDE) return [$du, 0.0, $dv];
		return [$du, $share * self::smooth(1 - $distance / $this->lead) * self::smooth(1 - $beyond / self::SIDE)
			* ($target - $y), $dv];
	}

	/**
	 * Work out how an eye within the slab keeps clear of the post or leg beside the side it holds.
	 * @param array $frame The gate's measurements.
	 * @param int $lane The side of the posts or legs the eye keeps to, as lane() gives it.
	 * @param float $u The eye's position across the gate.
	 * @param float $v The eye's position along the travel.
	 * @return array{0:float,1:float} Meters across the gate and along the travel. Up to half way into the post's band,
	 * from the edge of the side held to the far side with the clearance, the eye stays at that edge. Over the next
	 * tenth it moves out to the nearer face of the slab, over the next three tenths it moves along that face to the
	 * far side, and over the last tenth it moves back in, where it meets the eye on the far side of the post.
	 */
	private static function round(array $frame, int $lane, float $u, float $v): array
	{
		$clear = $frame['clear'];
		[$left, $right] = $frame['sides'];
		// The band the eye has gone into from its side, as [edge of the side held, far edge].
		$band = match($lane) {
			-1 => $left === null ? null : [$left, $clear[0]],
			1 => $right === null ? null : [$right, $clear[2]],
			default => $u > $clear[2] && $right !== null ? [$clear[2], $right]
				: ($u < $clear[0] && $left !== null ? [$clear[0], $left] : null),
		};
		if($band === null) return [0.0, 0.0];
		[$edge, $far] = $band;
		$t = ($u - $edge) / ($far - $edge);
		if($t <= 0 || $t >= 1) return [0.0, 0.0];
		$along = $t <= .6 ? 0.0 : ($t >= .9 ? 1.0 : self::smooth(($t - .6) / .3));
		$out = $t <= .5 ? 0.0 : ($t < .6 ? self::smooth(($t - .5) / .1) : ($t <= .9 ? 1.0 : self::smooth((1 - $t) / .1)));
		$c = self::CLEARANCE;
		$face = 2 * $v < $frame['low'][2] + $frame['high'][2] ? min(0.0, $frame['low'][2] - $c - $v)
			: max(0.0, $frame['high'][2] + $c - $v);
		return [$edge + ($far - $edge) * $along - $u, $out * $face];
	}

	/** Smoothstep from 0 at 0 to 1 at 1, flat at both ends. */
	private static function smooth(float $t): float
	{
		if($t >= 1) return 1.0;
		return $t <= 0 ? 0.0 : $t * $t * (3 - 2 * $t);
	}
}
