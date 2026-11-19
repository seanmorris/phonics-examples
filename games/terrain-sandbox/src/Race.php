<?php
declare(strict_types=1);
namespace TerrainSandbox;

/**
 * A timed run through a course's gates by one vehicle.
 *
 * The clock starts as the vehicle passes forward through the start gate's window, without stopping first. After
 * that the vehicle must pass each gate of the course's route in order and in the gate's direction of travel; any
 * other crossing is ignored. Passing the gate passed last again, which a course without checkpoints asks for at the
 * end of each lap, counts only once the vehicle has been well away from that gate. Each fixed step tests the
 * straight segment between the vehicle's positions before and after the step, so a jet at 260 m/s cannot skip a gate
 * between two steps. Times are simulation seconds, so pausing stops the clock, and each crossing is timed at the
 * point along its step where the segment meets the gate.
 */
final class Race
{
	/** idle waits for a start, running is timing a lap and finished holds the last result. */
	public string $state = 'idle';
	/** Latest race event, worded for the status line. */
	public string $message = '';
	private float $startedAt = 0.0, $lapStartedAt = 0.0;
	private ?float $result = null;
	/** Gates still to pass, as Course::route() lists them, and the position of the next one. */
	private array $route = [];
	private int $index = 0, $laps = 0;
	/** The course revision, vehicle and boarding the race began with. A change to any of them ends it. */
	private int $courseRevision = -1, $boarding = -1;
	private string $vehicle = '', $signature = '';
	private array $splits = [], $lapTimes = [];
	/** Latest and best finishing times this session, by course signature. */
	private array $latest = [], $best = [];
	private const VEHICLES = ['car' => 'the car', 'craft' => 'the jet ski', 'jet' => 'the F5'];
	/**
	 * How far a vehicle must go from a gate, in half widths of the gate's frame, before passing that same gate again
	 * counts: 23 m for a road gate, 18 m for a water gate and 68 m for an air gate. A course without checkpoints
	 * ends each lap at the gate it began from, and without this a car could rock back and forth through that gate
	 * and count a lap each time it rolled forward.
	 */
	public const LEAVE = 3.0;
	/**
	 * Id and ground position of the gate passed last, and the farthest the vehicle has been from it across the ground
	 * since, in metres. Changing the course stops the race, so the position stays the gate's own. Keeping it here
	 * spares a step from asking the course for that gate, which would sample the waves for a second floating gate.
	 */
	private int $lastGate = 0;
	private float $lastX = 0.0, $lastZ = 0.0, $farthest = 0.0;

	/**
	 * Find where a step's segment passes forward through a gate's window.
	 * @param array $gate Gate with x, y, z and yaw.
	 * @param array $shape The gate kind's shape from GateShapes.
	 * @param array $from Vehicle position [x, y, z] before the step.
	 * @param array $to Vehicle position [x, y, z] after the step.
	 * @return float|null Fraction of the step, above 0 and at most 1, where the segment reaches the gate's plane from
	 * behind inside its window, or null when it does not.
	 */
	public static function crossing(array $gate, array $shape, array $from, array $to): ?float
	{
		$sin = sin($gate['yaw']); $cos = cos($gate['yaw']);
		$behind = ($from[0] - $gate['x']) * $sin + ($from[2] - $gate['z']) * $cos;
		$ahead = ($to[0] - $gate['x']) * $sin + ($to[2] - $gate['z']) * $cos;
		if(!($behind < 0 && $ahead >= 0)) return null;
		$t = $behind / ($behind - $ahead);
		$x = $from[0] + ($to[0] - $from[0]) * $t - $gate['x'];
		$z = $from[2] + ($to[2] - $from[2]) * $t - $gate['z'];
		$y = $from[1] + ($to[1] - $from[1]) * $t - $gate['y'];
		$window = $shape['window'];
		if(abs($x * $cos - $z * $sin) > $window['halfWidth']) return null;
		if($y < $window['bottom'] - $shape['below'] || $y > $window['top']) return null;
		return $t;
	}

	/**
	 * Test one fixed step of the racing vehicle against the gates it may pass next.
	 * @param Course $course Course being raced.
	 * @param GateShapes $shapes Gate dimensions.
	 * @param array $from Vehicle position [x, y, z] before the step.
	 * @param array $to Vehicle position [x, y, z] after the step.
	 * @param float $time Simulation time at the start of the step, in seconds.
	 * @param float $dt Step length in seconds.
	 * @param string $vehicle Session mode of the vehicle: car, craft or jet.
	 * @param int $boarding Session count of vehicle boardings, which identifies this ride.
	 * @return string|null Message for the last event in the step, or null when nothing happened.
	 */
	public function advance(Course $course, GateShapes $shapes, array $from, array $to, float $time, float $dt,
		string $vehicle, int $boarding): ?string
	{
		$event = null;
		// Several gates can stand within one step of a jet. Each later one must be met further along the segment.
		$reached = 0.0;
		while(true)
		{
			$target = $this->state === 'running' ? $course->gate($this->route[$this->index]['id']) : $course->start();
			if($target === null) break;
			$shape = $shapes->kind($target['kind']);
			$fraction = self::crossing($target, $shape, $from, $to);
			if($fraction === null || $fraction <= $reached) break;
			if($this->state === 'running' && $target['id'] === $this->lastGate
				&& $this->farthest < self::LEAVE * $shape['extent']['halfWidth']) break;
			$reached = $fraction;
			$at = $time + $fraction * $dt;
			$event = $this->state === 'running' ? $this->pass($course, $at)
				: $this->begin($course, $at, $vehicle, $boarding);
			$this->lastGate = $target['id'];
			$this->lastX = $target['x']; $this->lastZ = $target['z'];
			$this->farthest = 0.0;
		}
		if($this->state === 'running')
			$this->farthest = max($this->farthest, hypot($to[0] - $this->lastX, $to[2] - $this->lastZ));
		if($event !== null) $this->message = $event;
		return $event;
	}

	private function begin(Course $course, float $at, string $vehicle, int $boarding): string
	{
		$this->state = 'running';
		$this->startedAt = $this->lapStartedAt = $at;
		$this->route = $course->route();
		$this->index = 0;
		$this->laps = (int)$course->laps();
		$this->courseRevision = $course->revision;
		$this->signature = $course->signature();
		$this->vehicle = $vehicle;
		$this->boarding = $boarding;
		$this->splits = $this->lapTimes = [];
		$this->result = null;
		return 'Race started. Next: ' . $this->describe($course, $this->route[0]) . '.';
	}

	private function pass(Course $course, float $at): string
	{
		$step = $this->route[$this->index];
		$gate = $course->gate($step['id']);
		$elapsed = $at - $this->startedAt;
		$this->splits[] = ['gate' => $gate['id'], 'role' => $gate['role'], 'number' => $gate['number'] ?? null,
			'lap' => $step['lap'], 'time' => $elapsed];
		$this->index++;
		if(!$step['lapEnd'])
			return ucfirst($this->describe($course, $step)) . ' at ' . self::clock($elapsed) . '. Next: '
				. $this->describe($course, $this->route[$this->index]) . '.';
		$this->lapTimes[] = $at - $this->lapStartedAt;
		$this->lapStartedAt = $at;
		if($this->index < count($this->route))
			return 'Lap ' . ($step['lap'] + 1) . " of {$this->laps} after " . self::clock($elapsed) . '. Next: '
				. $this->describe($course, $this->route[$this->index]) . '.';
		$this->state = 'finished';
		$this->result = $elapsed;
		$previous = $this->best[$this->signature] ?? null;
		$this->latest[$this->signature] = $elapsed;
		if($previous === null || $elapsed < $previous) $this->best[$this->signature] = $elapsed;
		if($previous === null) return 'Finished in ' . self::clock($elapsed) . '.';
		return $elapsed < $previous ? 'Finished in ' . self::clock($elapsed) . ', a new best by '
			. self::clock($previous - $elapsed) . '.'
			: 'Finished in ' . self::clock($elapsed) . '. Best ' . self::clock($previous) . '.';
	}

	/**
	 * End the race when the course or the ride it began with has changed.
	 * @param Course $course The session's course.
	 * @param string $mode Current session mode.
	 * @param int $boarding Current count of vehicle boardings.
	 * @return string|null Why a running race stopped, or null when it continues or was not running.
	 */
	public function guard(Course $course, string $mode, int $boarding): ?string
	{
		if($this->state === 'finished' && $course->revision !== $this->courseRevision) $this->reset();
		if($this->state !== 'running') return null;
		$why = match(true) {
			$course->revision !== $this->courseRevision => 'The race stopped because the course changed.',
			$mode !== $this->vehicle => 'The race stopped when you left ' . self::VEHICLES[$this->vehicle] . '.',
			$boarding !== $this->boarding
				=> 'The race stopped because ' . self::VEHICLES[$this->vehicle] . ' moved to a new spot.',
			default => null,
		};
		if($why === null) return null;
		$this->reset();
		$this->message = $why;
		return $why;
	}

	/** Return to waiting for a start, keeping the session's times. */
	public function reset(): void
	{
		$this->state = 'idle';
		$this->route = $this->splits = $this->lapTimes = [];
		$this->index = 0;
		$this->result = null;
		$this->courseRevision = -1;
		$this->message = '';
	}

	/**
	 * Report the race for the page.
	 * @param Course $course The session's course.
	 * @param float $now Simulation time in seconds.
	 * @return array State, clock, laps, next gate, splits, lap times, latest and best times, and the last message.
	 */
	public function status(Course $course, float $now): array
	{
		$running = $this->state === 'running';
		$signature = $course->signature();
		$next = $running ? $this->route[$this->index] : null;
		$start = $course->start();
		return ['state' => $this->state,
			'time' => $running ? $now - $this->startedAt : $this->result,
			'lap' => $running ? $next['lap'] : ($this->state === 'finished' ? $this->laps : null),
			'laps' => $this->state === 'idle' ? $course->laps() : $this->laps,
			'vehicle' => $this->state === 'idle' ? null : $this->vehicle,
			'next' => $next !== null ? $this->summary($course, $course->gate($next['id']))
				: ($start !== null ? $this->summary($course, $start) : null),
			'remaining' => $running ? count($this->route) - $this->index : null,
			'splits' => $this->splits, 'lastSplit' => $this->splits ? end($this->splits)['time'] : null,
			'lapTimes' => $this->lapTimes,
			'last' => $this->latest[$signature] ?? null, 'best' => $this->best[$signature] ?? null,
			'message' => $this->message];
	}

	private function summary(Course $course, array $gate): array
	{
		return ['id' => $gate['id'], 'role' => $gate['role'], 'print' => $course->print($gate),
			'number' => $gate['number'] ?? null];
	}

	/** Name a route step: checkpoint 3, the start gate or the finish gate. */
	private function describe(Course $course, array $step): string
	{
		$gate = $course->gate($step['id']);
		return match($gate['role']) {
			'checkpoint' => 'checkpoint ' . $gate['number'],
			'start' => $step['lap'] === $this->laps && $step['lapEnd'] ? 'the start gate to finish' : 'the start gate',
			'finish' => 'the finish gate',
		};
	}

	/**
	 * Format a race time.
	 * @param float $seconds Time in seconds.
	 * @return string Hundredths of a second, with minutes from one minute up: 12.34 s or 1:02.35.
	 */
	public static function clock(float $seconds): string
	{
		$hundredths = (int)round($seconds * 100);
		if($hundredths < 6000) return sprintf('%d.%02d s', intdiv($hundredths, 100), $hundredths % 100);
		return sprintf('%d:%02d.%02d', intdiv($hundredths, 6000), intdiv($hundredths % 6000, 100), $hundredths % 100);
	}
}
