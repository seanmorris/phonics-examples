<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\WaterSurface;

/**
 * Race gates placed on a landscape, and the order a race passes them in.
 *
 * A course holds at most one start gate, at most one finish gate and up to 32 checkpoints numbered in the order they
 * were placed. The start gate carries the lap count. A gate stands at x, y, z in metres and faces yaw radians: a
 * vehicle passes through it travelling along (sin yaw, 0, cos yaw), the convention the vehicles use for heading.
 */
final class Course
{
	public const ROLES = ['start', 'checkpoint', 'finish'];
	public const MAX_CHECKPOINTS = 32, MAX_LAPS = 20;
	/** Keys a saved gate may carry, beyond laps on the start gate and number on a checkpoint. */
	private const SAVED = ['role', 'kind', 'x', 'y', 'z', 'yaw'];
	private ?array $start = null, $finish = null;
	/** Checkpoints in race order; each one's number is its position plus one. */
	private array $checkpoints = [];
	private int $nextId = 1;
	/**
	 * Counts changes to the gates and the lap count. A water gate rising and falling on the waves is not a change.
	 * Anything that remembers the course, such as a race in progress or a cache of post positions, compares it.
	 */
	public int $revision = 0;
	/** Water surface and simulation time of the last float() call. */
	private ?WaterSurface $surface = null;
	private float $surfaceTime = 0.0;
	/**
	 * Ids of the water gates that stood at the last float() call. Each one's height is sampled from the surface the
	 * first time something asks for it, and kept in $floating until the next call, so a fixed step that only races
	 * through one gate samples the water for that gate alone.
	 */
	private array $afloat = [], $floating = [];
	/** Ids of the water gates for the revision in $waterRevision. */
	private array $waterIds = [];
	private int $waterRevision = -1;
	/** Road posts in world space for the revision in $postsRevision. */
	private array $posts = [];
	private int $postsRevision = -1, $signatureRevision = -1;
	private string $signature = '';

	/**
	 * Place a gate. A second start or finish gate moves the existing one, which keeps its id and its laps.
	 * @param string $role start, checkpoint or finish.
	 * @param string $kind road, water or air.
	 * @param float $x World X of the gate origin in metres.
	 * @param float $y World Y of the gate origin in metres; a water gate's water level when it was placed.
	 * @param float $z World Z of the gate origin in metres.
	 * @param float $yaw Heading of travel through the gate in radians, from -pi to pi.
	 * @param int $laps Laps a new start gate carries, from 1 to 20.
	 * @return array|null The placed gate and whether it moved an existing one, or null when 32 checkpoints stand.
	 */
	public function place(string $role, string $kind, float $x, float $y, float $z, float $yaw, int $laps = 1): ?array
	{
		if(!in_array($role, self::ROLES, true) || !in_array($kind, GateShapes::KINDS, true))
			throw new \InvalidArgumentException('Unknown gate role or kind');
		if($role === 'checkpoint' && count($this->checkpoints) >= self::MAX_CHECKPOINTS) return null;
		$existing = $role === 'start' ? $this->start : ($role === 'finish' ? $this->finish : null);
		$gate = ['id' => $existing['id'] ?? $this->nextId++, 'role' => $role, 'kind' => $kind,
			'x' => $x, 'y' => $y, 'z' => $z, 'yaw' => $yaw];
		if($role === 'start') $gate['laps'] = $existing['laps'] ?? max(1, min(self::MAX_LAPS, $laps));
		if($role === 'checkpoint') $gate['number'] = count($this->checkpoints) + 1;
		unset($this->afloat[$gate['id']], $this->floating[$gate['id']]);
		if($role === 'start') $this->start = $gate;
		elseif($role === 'finish') $this->finish = $gate;
		else $this->checkpoints[] = $gate;
		$this->revision++;
		return ['gate' => $gate, 'moved' => $existing !== null];
	}

	/**
	 * Remove one gate. Checkpoints after a removed one move up a number.
	 * @param int $id Gate id.
	 * @return array|null The removed gate, or null when no gate has that id.
	 */
	public function remove(int $id): ?array
	{
		$removed = null;
		if(($this->start['id'] ?? null) === $id) { $removed = $this->start; $this->start = null; }
		elseif(($this->finish['id'] ?? null) === $id) { $removed = $this->finish; $this->finish = null; }
		else foreach($this->checkpoints as $index => $gate)
		{
			if($gate['id'] !== $id) continue;
			$removed = $gate;
			array_splice($this->checkpoints, $index, 1);
			foreach($this->checkpoints as $i => $checkpoint) $this->checkpoints[$i]['number'] = $i + 1;
			break;
		}
		if($removed === null) return null;
		unset($this->afloat[$id], $this->floating[$id]);
		$this->revision++;
		return $removed;
	}

	/**
	 * Remove every gate.
	 * @return int How many gates there were.
	 */
	public function clear(): int
	{
		$count = $this->count();
		if($count === 0) return 0;
		$this->start = $this->finish = null;
		$this->checkpoints = $this->afloat = $this->floating = [];
		$this->revision++;
		return $count;
	}

	/**
	 * Set the lap count on the start gate.
	 * @param int $laps Laps from 1 to 20.
	 * @return bool Whether a start gate exists and its lap count changed.
	 */
	public function setLaps(int $laps): bool
	{
		$laps = max(1, min(self::MAX_LAPS, $laps));
		if($this->start === null || $this->start['laps'] === $laps) return false;
		$this->start['laps'] = $laps;
		$this->revision++;
		return true;
	}

	/** Laps on the start gate, or null without one. */
	public function laps(): ?int { return $this->start['laps'] ?? null; }

	public function count(): int
	{
		return ($this->start ? 1 : 0) + count($this->checkpoints) + ($this->finish ? 1 : 0);
	}

	public function start(): ?array { return $this->start === null ? null : $this->pose($this->start); }

	public function finish(): ?array { return $this->finish === null ? null : $this->pose($this->finish); }

	/**
	 * Find a gate by id.
	 * @param int $id Gate id.
	 * @return array|null The gate at its current height, or null.
	 */
	public function gate(int $id): ?array
	{
		if(($this->start['id'] ?? null) === $id) return $this->pose($this->start);
		if(($this->finish['id'] ?? null) === $id) return $this->pose($this->finish);
		foreach($this->checkpoints as $gate) if($gate['id'] === $id) return $this->pose($gate);
		return null;
	}

	/**
	 * List the gates: the start gate, the checkpoints by number, then the finish gate.
	 * @param bool $afloat Whether water gates report their height on the waves. Without it each gate reports the
	 * height it was placed at and the water is not sampled; height() then gives the height on the waves.
	 * @return list<array> Each gate's id, role, kind, x, y, z and yaw, with laps on the start gate and number on a
	 * checkpoint. y is the origin's height now, so a water gate reports the wave height at its position.
	 */
	public function gates(bool $afloat = true): array
	{
		$gates = [];
		if($this->start !== null) $gates[] = $afloat ? $this->pose($this->start) : $this->start;
		foreach($this->checkpoints as $gate) $gates[] = $afloat ? $this->pose($gate) : $gate;
		if($this->finish !== null) $gates[] = $afloat ? $this->pose($this->finish) : $this->finish;
		return $gates;
	}

	/**
	 * Report a gate's height now.
	 * @param array $gate A gate from this course.
	 * @return float The wave height at a gate that floated at the last float() call, otherwise its placed height.
	 */
	public function height(array $gate): float { return $this->pose($gate)['y']; }

	private function pose(array $gate): array
	{
		if(isset($this->afloat[$gate['id']]))
			$gate['y'] = $this->floating[$gate['id']]
				??= $this->surface->sample($gate['x'], $gate['z'], $this->surfaceTime)['height'];
		return $gate;
	}

	/**
	 * Name the print a gate shows. A start gate shows START / FINISH when no finish gate stands, since the race
	 * then ends where it began.
	 * @param array $gate A gate from this course.
	 * @return string start, checkpoint, finish or start-finish.
	 */
	public function print(array $gate): string
	{
		return $gate['role'] === 'start' && $this->finish === null ? 'start-finish' : $gate['role'];
	}

	/**
	 * List the gates a race passes after its start, in order. Every lap passes the checkpoints by number. Each lap
	 * then returns through the start gate, except that a finish gate, when one stands, ends the last lap.
	 * @return list<array{id:int,lap:int,lapEnd:bool}> Gate ids with the lap each belongs to, or an empty list
	 * without a start gate.
	 */
	public function route(): array
	{
		if($this->start === null) return [];
		$route = [];
		$laps = $this->start['laps'];
		for($lap = 1; $lap <= $laps; $lap++)
		{
			foreach($this->checkpoints as $gate) $route[] = ['id' => $gate['id'], 'lap' => $lap, 'lapEnd' => false];
			$closing = $lap === $laps && $this->finish !== null ? $this->finish : $this->start;
			$route[] = ['id' => $closing['id'], 'lap' => $lap, 'lapEnd' => true];
		}
		return $route;
	}

	/**
	 * Lift each water gate to the water surface at its position, so it rises and falls with the waves and wakes.
	 * @param WaterSurface $water Shared water surface. Only its sample() is called.
	 * @param float $time Simulation time in seconds.
	 * @return void Updates the heights gates() reports. The course revision does not change. Each gate samples the
	 * surface at this time when its height is first asked for, which gives the height an immediate sample would, as
	 * long as the surface's waves, level and wakes stay as they are until the next call.
	 */
	public function float(WaterSurface $water, float $time): void
	{
		$this->surface = $water;
		$this->surfaceTime = $time;
		$this->afloat = $this->waterIds();
		$this->floating = [];
	}

	/** Whether any gate floats on the water. */
	public function floats(): bool { return $this->waterIds() !== []; }

	/**
	 * Find the water gates, once per revision.
	 * @return array<int,true> Water gate ids as keys.
	 */
	private function waterIds(): array
	{
		if($this->waterRevision === $this->revision) return $this->waterIds;
		$this->waterIds = [];
		foreach($this->gates(false) as $gate) if($gate['kind'] === 'water') $this->waterIds[$gate['id']] = true;
		$this->waterRevision = $this->revision;
		return $this->waterIds;
	}

	/**
	 * List the solid posts of every road gate in world space, for car collision.
	 * @param GateShapes $shapes Gate dimensions.
	 * @return list<array> [x, z, half size, cos yaw, sin yaw, bottom y, top y] of each post. Each post is a square
	 * aligned with its gate.
	 */
	public function posts(GateShapes $shapes): array
	{
		if($this->postsRevision === $this->revision) return $this->posts;
		$posts = [];
		foreach($this->gates(false) as $gate)
		{
			if($gate['kind'] !== 'road') continue;
			$cos = cos($gate['yaw']); $sin = sin($gate['yaw']);
			foreach($shapes->kind('road')['posts'] as $post)
				$posts[] = [$gate['x'] + $post['x'] * $cos + $post['z'] * $sin,
					$gate['z'] - $post['x'] * $sin + $post['z'] * $cos,
					$post['halfSize'], $cos, $sin, $gate['y'] + $post['bottom'], $gate['y'] + $post['top']];
		}
		$this->posts = $posts;
		$this->postsRevision = $this->revision;
		return $posts;
	}

	/**
	 * Find the gate a pick ray passes closest to, among those it passes within their half extent.
	 * @param array $origin Ray origin [x, y, z] in metres.
	 * @param array $direction Unit ray direction.
	 * @param float $length Distance along the ray to the ground it hit, or the pick range when it hit nothing.
	 * @param GateShapes $shapes Gate dimensions.
	 * @return int|null The nearest gate's id, or null when the ray passes no gate within its span.
	 */
	public function nearest(array $origin, array $direction, float $length, GateShapes $shapes): ?int
	{
		$end = [$origin[0] + $direction[0] * $length, $origin[1] + $direction[1] * $length,
			$origin[2] + $direction[2] * $length];
		$best = null;
		foreach($this->gates() as $gate)
		{
			$shape = $shapes->kind($gate['kind']);
			// The gate's centre line runs from the lowest point a vehicle may pass to the top of its frame.
			$foot = [$gate['x'], $gate['y'] + $shape['window']['bottom'] - $shape['below'], $gate['z']];
			$head = [$gate['x'], $gate['y'] + $shape['extent']['top'], $gate['z']];
			[$distance, $along] = self::segmentDistance($origin, $end, $foot, $head);
			if($distance > $shape['extent']['halfWidth']) continue;
			if($best === null || $distance < $best[1] || $distance === $best[1] && $along < $best[2])
				$best = [$gate['id'], $distance, $along];
		}
		return $best[0] ?? null;
	}

	/**
	 * Measure the closest approach of two segments, after Ericson's Real-Time Collision Detection, section 5.1.9.
	 * @return array{0:float,1:float} The distance in metres and the fraction along the first segment where it occurs.
	 */
	private static function segmentDistance(array $p1, array $q1, array $p2, array $q2): array
	{
		$d1 = [$q1[0] - $p1[0], $q1[1] - $p1[1], $q1[2] - $p1[2]];
		$d2 = [$q2[0] - $p2[0], $q2[1] - $p2[1], $q2[2] - $p2[2]];
		$r = [$p1[0] - $p2[0], $p1[1] - $p2[1], $p1[2] - $p2[2]];
		$dot = static fn(array $u, array $v): float => $u[0] * $v[0] + $u[1] * $v[1] + $u[2] * $v[2];
		$clamp = static fn(float $value): float => max(0.0, min(1.0, $value));
		$a = $dot($d1, $d1); $e = $dot($d2, $d2); $f = $dot($d2, $r);
		if($a <= 1e-12) { $s = 0.0; $t = $e <= 1e-12 ? 0.0 : $clamp($f / $e); }
		else
		{
			$c = $dot($d1, $r);
			if($e <= 1e-12) { $t = 0.0; $s = $clamp(-$c / $a); }
			else
			{
				$b = $dot($d1, $d2);
				$denominator = $a * $e - $b * $b;
				$s = $denominator > 1e-12 ? $clamp(($b * $f - $c * $e) / $denominator) : 0.0;
				$t = ($b * $s + $f) / $e;
				if($t < 0) { $t = 0.0; $s = $clamp(-$c / $a); }
				elseif($t > 1) { $t = 1.0; $s = $clamp(($b - $c) / $a); }
			}
		}
		$gap = [];
		for($i = 0; $i < 3; $i++) $gap[] = $p1[$i] + $d1[$i] * $s - $p2[$i] - $d2[$i] * $t;
		return [sqrt($dot($gap, $gap)), $s];
	}

	/**
	 * Describe the course for a save file. Water gates record the water level they were placed at.
	 * @return array{gates:list<array>} Gates in race order without ids, which loading assigns afresh.
	 */
	public function export(): array
	{
		$gates = [];
		foreach([$this->start, ...$this->checkpoints, $this->finish] as $gate)
			if($gate !== null) $gates[] = array_diff_key($gate, ['id' => 0]);
		return ['gates' => $gates];
	}

	/** Identify the course by its gates and laps, so a session keeps one best time per course. */
	public function signature(): string
	{
		if($this->signatureRevision !== $this->revision)
		{
			$this->signature = md5(json_encode($this->export(), JSON_THROW_ON_ERROR));
			$this->signatureRevision = $this->revision;
		}
		return $this->signature;
	}

	/**
	 * Rebuild a course from a save file, which is untrusted input.
	 * @param mixed $data The file's course object; null reads as a course without gates.
	 * @param float $half Half the landscape's width in metres. Every gate must stand on it.
	 * @return self The course, with ids assigned in race order.
	 * @throws \InvalidArgumentException When any part of the course is malformed or out of range.
	 */
	public static function import(mixed $data, float $half): self
	{
		$course = new self;
		if($data === null) return $course;
		$fail = static fn(string $why): \InvalidArgumentException
			=> new \InvalidArgumentException("Invalid saved course: $why.");
		if(!is_array($data) || array_is_list($data) && $data !== [] || array_diff(array_keys($data), ['gates']))
			throw $fail('expected an object holding a list of gates');
		$gates = $data['gates'] ?? [];
		if(!is_array($gates) || !array_is_list($gates)) throw $fail('gates must be a list');
		if(count($gates) > self::MAX_CHECKPOINTS + 2) throw $fail('too many gates');
		$number = static fn(mixed $value): bool => (is_int($value) || is_float($value)) && is_finite((float)$value);
		$checkpoints = [];
		foreach($gates as $gate)
		{
			if(!is_array($gate) || array_is_list($gate)) throw $fail('each gate must be an object');
			$role = $gate['role'] ?? null;
			if(!in_array($role, self::ROLES, true)) throw $fail('a gate has an unknown role');
			if(!in_array($gate['kind'] ?? null, GateShapes::KINDS, true)) throw $fail('a gate has an unknown kind');
			$keys = [...self::SAVED,
				...match($role) { 'start' => ['laps'], 'checkpoint' => ['number'], 'finish' => [] }];
			$present = array_keys($gate);
			sort($present); sort($keys);
			if($present !== $keys) throw $fail("a $role gate must hold exactly " . implode(', ', $keys));
			foreach(['x', 'y', 'z', 'yaw'] as $key)
				if(!$number($gate[$key])) throw $fail("a gate's $key is not a number");
			if(abs($gate['x']) > $half || abs($gate['z']) > $half) throw $fail('a gate stands off the landscape');
			if(abs($gate['y']) > 20000) throw $fail('a gate stands out of height range');
			if(abs($gate['yaw']) > M_PI) throw $fail('a gate yaw lies outside -pi to pi');
			if($role === 'start' && (!is_int($gate['laps']) || $gate['laps'] < 1 || $gate['laps'] > self::MAX_LAPS))
				throw $fail('laps must be a whole number from 1 to 20');
			if($role === 'checkpoint' && !is_int($gate['number']))
				throw $fail('a checkpoint number must be a whole number');
			if($role === 'start' && $course->start !== null || $role === 'finish' && $course->finish !== null)
				throw $fail("the course has two $role gates");
			$record = ['id' => 0, 'role' => $role, 'kind' => $gate['kind'], 'x' => (float)$gate['x'],
				'y' => (float)$gate['y'], 'z' => (float)$gate['z'], 'yaw' => (float)$gate['yaw']];
			if($role === 'start') $course->start = $record + ['laps' => $gate['laps']];
			elseif($role === 'finish') $course->finish = $record;
			else $checkpoints[] = $record + ['number' => $gate['number']];
		}
		if(count($checkpoints) > self::MAX_CHECKPOINTS) throw $fail('more than 32 checkpoints');
		usort($checkpoints, static fn(array $a, array $b): int => $a['number'] <=> $b['number']);
		foreach($checkpoints as $index => $gate)
			if($gate['number'] !== $index + 1) throw $fail('checkpoints must be numbered 1, 2, 3 and so on');
		if($course->start !== null) $course->start['id'] = $course->nextId++;
		foreach($checkpoints as $index => $gate) $checkpoints[$index]['id'] = $course->nextId++;
		$course->checkpoints = $checkpoints;
		if($course->finish !== null) $course->finish['id'] = $course->nextId++;
		return $course;
	}
}
