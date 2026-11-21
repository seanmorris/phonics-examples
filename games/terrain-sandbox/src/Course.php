<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\WaterSurface;

/**
 * Race gates placed on a landscape, and the order a race passes them in.
 *
 * A course holds at most one start gate, at most one finish gate and up to 32 checkpoints numbered in the order they
 * were placed. A gate stands at x, y, z in meters and faces yaw radians: a vehicle passes through it traveling along
 * (sin yaw, 0, cos yaw), the convention the vehicles use for heading. Each gate's opening is width by height meters,
 * within the ranges of its kind in GateShapes.
 *
 * A course without a finish gate starts and finishes at its start gate, and runs the laps the start gate carries. A
 * course with a finish gate runs once from the start gate to the finish gate.
 */
final class Course
{
	public const ROLES = ['start', 'checkpoint', 'finish'];
	public const MAX_CHECKPOINTS = 32, MAX_LAPS = 20;
	/** Keys a saved gate may carry, beyond laps on the start gate, number on a checkpoint and its size. */
	private const SAVED = ['role', 'kind', 'x', 'y', 'z', 'yaw'];
	/**
	 * How far a road post reaches below the lowest ground under its footprint, in meters, so a slope shows no gap. The
	 * footprint is sampled at its middle and its corners.
	 */
	public const POST_FOOT = 1.0;
	/**
	 * How far below the lowest ground across a road gate a car still counts as passing through it. The ground is
	 * sampled every meter at most, and a car rolling over a hump between samples sits a little under the line
	 * between them.
	 */
	public const ROAD_BELOW = 1.0;
	/** Longest step in meters between ground samples across a gate's span. */
	private const SAMPLE_STEP = 1.0;
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
	/** Each gate's measure() for the revision in $measuredRevision, by id. */
	private array $measured = [];
	private int $measuredRevision = -1;
	/** Solid parts and opening of every gate at its placed height, for the revision in $obstructionsRevision. */
	private array $obstructions = [];
	private int $obstructionsRevision = -1;
	public readonly GateShapes $shapes;

	/** @param GateShapes|null $shapes Gate dimensions; the package's gates.json, or its defaults, when omitted. */
	public function __construct(?GateShapes $shapes = null)
	{
		$this->shapes = $shapes ?? GateShapes::packaged();
	}

	/**
	 * Place a gate. A second start or finish gate moves the existing one, which keeps its id and its laps.
	 *
	 * A finish gate placed within one start gate width of the start gate is not added: the start gate becomes the
	 * course's start / finish gate, and any finish gate standing elsewhere goes. A start gate placed within its own
	 * width of the finish gate does the same. Both distances run between gate origins after the fit to the ground. A
	 * gate whose span would overlap another gate's is refused.
	 * @param string $role start, checkpoint or finish.
	 * @param string $kind road, water or air.
	 * @param float $x World X of the gate origin in meters.
	 * @param float $y World Y of the gate origin in meters; a water gate's water level when it was placed. A road gate
	 * placed with $ground stands on the highest ground across its span instead.
	 * @param float $z World Z of the gate origin in meters.
	 * @param float $yaw Heading of travel through the gate in radians, from -pi to pi.
	 * @param int $laps Laps a new start gate carries, from 1 to 20.
	 * @param float|null $width Opening width in meters, or null for the kind's own. It must lie in the kind's range.
	 * @param float|null $height Opening height in meters, or null for the kind's own. It must lie in the kind's range,
	 * and a water arch's within its stretch, as GateShapes::fit() leaves it.
	 * @param \Closure|null $ground fn(float $x, float $z): float giving the ground height anywhere on the landscape.
	 * Without it the ground under each gate counts as level with the gate's origin.
	 * @return array{gate:?array,moved:bool,merged:bool,removed:?array,refused:?string,with:?array} The placed gate,
	 * or on a merge the start gate; whether it moved an existing gate; whether a finish gate merged into the start
	 * gate, and the finish gate that went. A refused gate leaves the course unchanged, with refused 'full' when 32
	 * checkpoints stand or 'overlap' with the gate in the way.
	 * @throws \InvalidArgumentException For an unknown role or kind, or a size outside the kind's range.
	 */
	public function place(string $role, string $kind, float $x, float $y, float $z, float $yaw, int $laps = 1,
		?float $width = null, ?float $height = null, ?\Closure $ground = null): array
	{
		$result = ['gate' => null, 'moved' => false, 'merged' => false, 'removed' => null, 'refused' => null,
			'with' => null];
		if(!in_array($role, self::ROLES, true) || !in_array($kind, GateShapes::KINDS, true))
			throw new \InvalidArgumentException('Unknown gate role or kind');
		[$defaultWidth, $defaultHeight] = $this->shapes->kind($kind)['size']['default'];
		$width ??= $defaultWidth; $height ??= $defaultHeight;
		if(!$this->shapes->fits($kind, $width, $height))
			throw new \InvalidArgumentException("A $kind gate cannot be $width by $height meters");
		if($role === 'checkpoint' && count($this->checkpoints) >= self::MAX_CHECKPOINTS)
			return ['refused' => 'full'] + $result;
		$existing = $role === 'start' ? $this->start : ($role === 'finish' ? $this->finish : null);
		// The candidate measures as a new gate until it takes its place, so nothing reads the gate it replaces.
		$gate = ['id' => 0, 'role' => $role, 'kind' => $kind,
			'x' => $x, 'y' => $y, 'z' => $z, 'yaw' => $yaw, 'width' => $width, 'height' => $height];
		$gate = $this->fit($gate, $ground);
		// A finish gate this close to the start gate would be passed straight after the start, or rocked through.
		// Both stand where the fit put them: a road gate on a hump rises above the ground the click hit.
		$near = static fn(array $other, float $distance): bool => sqrt(($gate['x'] - $other['x']) ** 2
			+ ($gate['y'] - $other['y']) ** 2 + ($gate['z'] - $other['z']) ** 2) < $distance;
		if($role === 'finish' && $this->start !== null && $near($this->start, $this->start['width']))
		{
			$removed = $this->finish;
			if($removed !== null) $this->remove($removed['id']);
			return ['gate' => $this->pose($this->start), 'merged' => true, 'removed' => $removed] + $result;
		}
		$merge = $role === 'start' && $this->finish !== null && $near($this->finish, $width);
		$ignore = array_filter([$existing['id'] ?? null, $merge ? $this->finish['id'] : null]);
		foreach($this->gates(false) as $other)
			if(!in_array($other['id'], $ignore, true) && $this->overlap($gate, $other))
				return ['refused' => 'overlap', 'with' => $this->pose($other)] + $result;
		if($merge)
		{
			$result['merged'] = true;
			$result['removed'] = $this->finish;
			$this->remove($this->finish['id']);
		}
		$gate['id'] = $existing['id'] ?? $this->nextId++;
		if($role === 'start') $gate['laps'] = $existing['laps'] ?? max(1, min(self::MAX_LAPS, $laps));
		if($role === 'checkpoint') $gate['number'] = count($this->checkpoints) + 1;
		unset($this->afloat[$gate['id']], $this->floating[$gate['id']]);
		if($role === 'start') $this->start = $gate;
		elseif($role === 'finish') $this->finish = $gate;
		else $this->checkpoints[] = $gate;
		$this->revision++;
		return ['gate' => $this->pose($gate), 'moved' => $existing !== null] + $result;
	}

	/**
	 * Whether two gates' spans meet: their footprints seen from above, each the width of its frame and the depth of
	 * its frame along its direction of travel, overlap, and so do their heights from the bottom of the window to the
	 * top of the frame.
	 */
	private function overlap(array $a, array $b): bool
	{
		$boxes = [];
		foreach([$a, $b] as $gate)
		{
			$measure = $this->measure($gate);
			$extent = $measure['extent'];
			$boxes[] = ['x' => $gate['x'], 'z' => $gate['z'], 'cos' => cos($gate['yaw']), 'sin' => sin($gate['yaw']),
				'half' => [$extent['halfWidth'], $extent['halfDepth']],
				'low' => $gate['y'] + min($measure['window']['bottom'], $extent['bottom']),
				'high' => $gate['y'] + $extent['top']];
		}
		[$p, $q] = $boxes;
		if($p['high'] <= $q['low'] || $q['high'] <= $p['low']) return false;
		$dx = $q['x'] - $p['x']; $dz = $q['z'] - $p['z'];
		// Separating axes: each footprint's across (cos, -sin) and along (sin, cos) directions.
		foreach([$p, $q] as $box) foreach([[$box['cos'], -$box['sin']], [$box['sin'], $box['cos']]] as [$ux, $uz])
		{
			$reach = 0.0;
			foreach([$p, $q] as $other)
				$reach += $other['half'][0] * abs($other['cos'] * $ux - $other['sin'] * $uz)
					+ $other['half'][1] * abs($other['sin'] * $ux + $other['cos'] * $uz);
			if(abs($dx * $ux + $dz * $uz) >= $reach) return false;
		}
		return true;
	}

	/**
	 * Fit a gate to the ground under it. A road gate stands on the highest ground across its span, so its board stays
	 * clear of a slope; each post reaches below the ground under it, and the window reaches down to the lowest ground.
	 * @param array $gate Gate record without fitting.
	 * @param \Closure|null $ground Ground height sampler, or null to count the ground as level with the origin.
	 * @return array The gate with ground [lowest, highest] across its span and, on a road gate, posts [-X, +X]: the
	 * ground under each post's middle, where its base plate lies, and footings [-X, +X]: the lowest ground under each
	 * post's footprint. All are heights in meters from the gate's origin. A water gate's ground is [0, 0], and an air
	 * gate placed without a sampler has ground null.
	 */
	private function fit(array $gate, ?\Closure $ground): array
	{
		$gate['ground'] = $gate['kind'] === 'air' && $ground === null ? null : [0.0, 0.0];
		if($gate['kind'] === 'road') $gate['posts'] = $gate['footings'] = [0.0, 0.0];
		if($ground === null || $gate['kind'] === 'water') return $gate;
		$cos = cos($gate['yaw']); $sin = sin($gate['yaw']);
		$at = static fn(float $across, float $along): float
			=> $ground($gate['x'] + $across * $cos + $along * $sin, $gate['z'] - $across * $sin + $along * $cos);
		$shape = $this->shapes->kind($gate['kind']);
		$half = $gate['width'] / 2;
		$heights = [];
		if($gate['kind'] === 'road')
		{
			// The posts' outer faces bound the span. Each post's footprint is sampled at its middle and corners.
			$post = $shape['posts'][1]['halfSize'];
			$reach = $half + 2 * $post;
			$middles = $lows = [];
			foreach([-1, 1] as $side)
			{
				$middle = $side * ($half + $post);
				$footprint = [$at($middle, 0.0)];
				foreach([-1, 1] as $a) foreach([-1, 1] as $b) $footprint[] = $at($middle + $a * $post, $b * $post);
				$middles[] = $footprint[0];
				$lows[] = min($footprint);
				$heights = [...$heights, ...$footprint];
			}
		}
		else $reach = $half;
		$steps = (int)ceil(2 * $reach / self::SAMPLE_STEP);
		for($i = 0; $i <= $steps; $i++) $heights[] = $at(-$reach + 2 * $reach * $i / $steps, 0.0);
		$high = max($heights); $low = min($heights);
		if($gate['kind'] === 'road')
		{
			$gate['y'] = $high;
			$gate['posts'] = [$middles[0] - $high, $middles[1] - $high];
			$gate['footings'] = [$lows[0] - $high, $lows[1] - $high];
		}
		$gate['ground'] = [$low - $gate['y'], $high - $gate['y']];
		return $gate;
	}

	/**
	 * Fit the road and air gates over changed ground to it again, as place() fits a new gate: a road gate stands on
	 * the highest ground across its span and its posts reach the ground under them. Water gates float and stay.
	 * @param \Closure $ground fn(float $x, float $z): float giving the ground height now.
	 * @param array|null $region [minX, minZ, maxX, maxZ] in meters around the ground that changed, as
	 * Landscape::changedRegion() gives it, an empty array when nothing changed, or null when all of it may have.
	 * @return bool Whether any gate moved or now stands over different ground. Only then does the revision change.
	 */
	public function refit(\Closure $ground, ?array $region = null): bool
	{
		if($region === []) return false;
		$changed = false;
		$refit = function(?array $gate) use ($ground, $region, &$changed): ?array {
			if($gate === null || $gate['kind'] === 'water') return $gate;
			$extent = $this->measure($gate)['extent'];
			$reach = hypot($extent['halfWidth'], $extent['halfDepth']);
			if($region !== null && ($gate['x'] + $reach < $region[0] || $gate['x'] - $reach > $region[2]
				|| $gate['z'] + $reach < $region[1] || $gate['z'] - $reach > $region[3])) return $gate;
			$fitted = $this->fit($gate, $ground);
			$changed = $changed || $fitted !== $gate;
			return $fitted;
		};
		$this->start = $refit($this->start);
		$this->finish = $refit($this->finish);
		foreach($this->checkpoints as $index => $gate) $this->checkpoints[$index] = $refit($gate);
		if($changed) $this->revision++;
		return $changed;
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
	 * Set the lap count on the start gate. A course with a finish gate keeps it for when that gate goes.
	 * @param int $laps Laps from 1 to 20.
	 * @return bool Whether a start gate exists and its lap count changed.
	 */
	public function setLaps(int $laps): bool
	{
		$laps = max(1, min(self::MAX_LAPS, $laps));
		if($this->start === null || $this->start['laps'] === $laps) return false;
		$this->start['laps'] = $laps;
		// A course with a finish gate runs once, so its route and a race on it stay as they were.
		if($this->finish === null) $this->revision++;
		return true;
	}

	/** Laps a race runs: the start gate's laps without a finish gate, one with it, or null without a start gate. */
	public function laps(): ?int
	{
		if($this->start === null) return null;
		return $this->finish === null ? $this->start['laps'] : 1;
	}

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
	 * @return list<array> Each gate's id, role, kind, x, y, z, yaw, width, height and ground, with posts on a road
	 * gate, laps on the start gate and number on a checkpoint. y is the origin's height now, so a water gate reports
	 * the wave height at its position.
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
	 * List the gates a race passes after its start, in order. Without a finish gate, every lap passes the
	 * checkpoints by number and returns through the start gate. With one, the race passes the checkpoints once and
	 * ends at the finish gate.
	 * @return list<array{id:int,lap:int,lapEnd:bool}> Gate ids with the lap each belongs to, or an empty list
	 * without a start gate.
	 */
	public function route(): array
	{
		if($this->start === null) return [];
		$route = [];
		$laps = $this->laps();
		for($lap = 1; $lap <= $laps; $lap++)
		{
			foreach($this->checkpoints as $gate) $route[] = ['id' => $gate['id'], 'lap' => $lap, 'lapEnd' => false];
			$route[] = ['id' => ($this->finish ?? $this->start)['id'], 'lap' => $lap, 'lapEnd' => true];
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
	 * Measure a gate at its size, in its own frame: heights from its origin, X across and Z along its travel.
	 * @param array $gate A gate from this course, or one about to join it.
	 * @return array{window:array,extent:array,posts:list<array>,resize:list<float>,feet:list<float>} The window a
	 * vehicle passes through, from the lowest point that still counts (halfWidth, bottom, top); the extent of the
	 * drawn gate (halfWidth, bottom, top, halfDepth); each post (x, z, halfSize, bottom, top); the resize model.vert
	 * applies [half width added, height added, scale across, scale up]; and [foot of the -X post, foot of the +X post,
	 * ground under the middle of the -X post, ground under the middle of the +X post].
	 */
	public function measure(array $gate): array
	{
		if($this->measuredRevision !== $this->revision) { $this->measured = []; $this->measuredRevision = $this->revision; }
		if($gate['id'] > 0 && isset($this->measured[$gate['id']])) return $this->measured[$gate['id']];
		$shape = $this->shapes->kind($gate['kind']);
		[$defaultWidth, $defaultHeight] = $shape['size']['default'];
		$window = $shape['window']; $extent = $shape['extent'];
		$posts = [];
		$feet = [0.0, 0.0, 0.0, 0.0];
		if($gate['kind'] === 'water')
		{
			$across = $gate['width'] / $defaultWidth; $up = $gate['height'] / $defaultHeight;
			$resize = [0.0, 0.0, $across, $up];
			$measure = [
				'window' => ['halfWidth' => $window['halfWidth'] * $across, 'bottom' => $window['bottom'] - $shape['below'],
					'top' => $window['top'] * $up],
				// Below the water line the feet keep their depth.
				'extent' => ['halfWidth' => $extent['halfWidth'] * $across, 'bottom' => $extent['bottom'],
					'top' => $extent['top'] * $up, 'halfDepth' => $extent['halfDepth'] * sqrt($across * $up)],
			];
		}
		else
		{
			$added = $gate['width'] / 2 - $window['halfWidth'];
			$raised = $gate['height'] - $window['top'];
			$resize = [$added, $raised, 1.0, 1.0];
			$bottom = $gate['kind'] === 'road' ? min(0.0, $gate['ground'][0]) - self::ROAD_BELOW : $window['bottom'];
			if($gate['kind'] === 'road')
			{
				[$left, $right] = $gate['posts'];
				$feet = [$gate['footings'][0] - self::POST_FOOT, $gate['footings'][1] - self::POST_FOOT, $left, $right];
				foreach($shape['posts'] as $index => $post)
					$posts[] = ['x' => $post['x'] + ($post['x'] < 0 ? -$added : $added), 'z' => $post['z'],
						'halfSize' => $post['halfSize'], 'bottom' => $feet[$post['x'] < 0 ? 0 : 1],
						'top' => $post['top'] + $raised];
			}
			$measure = [
				'window' => ['halfWidth' => $gate['width'] / 2, 'bottom' => $bottom, 'top' => $gate['height']],
				'extent' => ['halfWidth' => $extent['halfWidth'] + $added,
					'bottom' => $gate['kind'] === 'road' ? min($feet[0], $feet[1]) : $extent['bottom'],
					'top' => $extent['top'] + $raised, 'halfDepth' => $extent['halfDepth']],
			];
		}
		$measure += ['posts' => $posts, 'resize' => $resize, 'feet' => $feet];
		if($gate['id'] > 0) $this->measured[$gate['id']] = $measure;
		return $measure;
	}

	/**
	 * List the solid posts of every road gate in world space, for car collision.
	 * @return list<array> [x, z, half size, cos yaw, sin yaw, bottom y, top y] of each post. Each post is a square
	 * aligned with its gate, beside the window at the gate's size and reaching below the ground under it.
	 */
	public function posts(): array
	{
		if($this->postsRevision === $this->revision) return $this->posts;
		$posts = [];
		foreach($this->gates(false) as $gate)
		{
			if($gate['kind'] !== 'road') continue;
			$cos = cos($gate['yaw']); $sin = sin($gate['yaw']);
			foreach($this->measure($gate)['posts'] as $post)
				$posts[] = [$gate['x'] + $post['x'] * $cos + $post['z'] * $sin,
					$gate['z'] - $post['x'] * $sin + $post['z'] * $cos,
					$post['halfSize'], $cos, $sin, $gate['y'] + $post['bottom'], $gate['y'] + $post['top']];
		}
		$this->posts = $posts;
		$this->postsRevision = $this->revision;
		return $posts;
	}

	/**
	 * List the solid parts and the opening of each gate in world space, for a chase camera to keep clear of.
	 * @param array|null $near [x, y, z] in meters to list only the gates whose extent comes within $reach of it across
	 * the ground, or null for every gate. Only the water gates listed sample the waves.
	 * @param float $reach Distance in meters from $near.
	 * @return list<array{id:int,kind:string,solids:list<array>,opening:array}> Gates in race order. Each solid is a
	 * box {center: [x, y, z], yaw, halfX, halfY, halfZ} with its half sizes along the gate's X (across), Y (up) and Z
	 * (along its travel). A road gate lists its two posts, the two base plates under them and its board with the post
	 * tabs; a water arch its two legs and its crossbar; an air gate its banner. The opening is
	 * {center: [x, y, z], yaw, halfWidth, bottom, top}: the center is the gate's origin, and bottom and top are world
	 * heights. A road gate's opening reaches down to the lowest ground across it, and a water gate's to the water line.
	 * A water gate stands at its height on the waves at the last float() call.
	 */
	public function obstructions(?array $near = null, float $reach = INF): array
	{
		if($this->obstructionsRevision !== $this->revision)
		{
			$this->obstructions = [];
			foreach($this->gates(false) as $gate) $this->obstructions[] = $this->obstruction($gate);
			$this->obstructionsRevision = $this->revision;
		}
		$found = [];
		foreach($this->obstructions as $entry)
		{
			$gate = $entry['gate'];
			if($near !== null && hypot($gate['x'] - $near[0], $gate['z'] - $near[2]) > $reach + $entry['reach']) continue;
			$rise = isset($this->afloat[$gate['id']]) ? $this->pose($gate)['y'] - $gate['y'] : 0.0;
			if($rise != 0)
			{
				foreach($entry['solids'] as $i => $solid) $entry['solids'][$i]['center'][1] += $rise;
				$entry['opening']['center'][1] += $rise;
				$entry['opening']['bottom'] += $rise;
				$entry['opening']['top'] += $rise;
			}
			unset($entry['gate'], $entry['reach']);
			$found[] = $entry;
		}
		return $found;
	}

	/**
	 * Build one gate's entry for obstructions() at the height it was placed at.
	 * @param array $gate A gate from this course, without its height on the waves.
	 * @return array The obstructions() entry, with the gate and its horizontal reach from its origin.
	 */
	private function obstruction(array $gate): array
	{
		$measure = $this->measure($gate);
		['window' => $window, 'extent' => $extent] = $measure;
		$cos = cos($gate['yaw']); $sin = sin($gate['yaw']);
		$box = static fn(float $x, float $bottom, float $top, float $halfX, float $halfZ): array => [
			'center' => [$gate['x'] + $x * $cos, $gate['y'] + ($bottom + $top) / 2, $gate['z'] - $x * $sin],
			'yaw' => $gate['yaw'], 'halfX' => $halfX, 'halfY' => ($top - $bottom) / 2, 'halfZ' => $halfZ];
		$solids = [];
		$bottom = 0.0;
		if($gate['kind'] === 'water')
		{
			$inner = $window['halfWidth']; $outer = $extent['halfWidth'];
			foreach([-1, 1] as $side)
				$solids[] = $box($side * ($inner + $outer) / 2, $extent['bottom'], $window['top'], ($outer - $inner) / 2,
					$extent['halfDepth']);
		}
		elseif($gate['kind'] === 'road')
		{
			foreach($measure['posts'] as $post)
				$solids[] = $box($post['x'], $post['bottom'], $post['top'], $post['halfSize'], $post['halfSize']);
			// The base plates, one tab wide, reach the gate's ends and lie on the ground under each post, as thick as
			// the tabs on the posts' tops.
			$shape = $this->shapes->kind('road');
			$plate = $shape['extent']['top'] - max(array_column($shape['posts'], 'top'));
			foreach([-1, 1] as $index => $side)
			{
				$ground = $measure['feet'][2 + $index];
				$solids[] = $box($side * ($extent['halfWidth'] - $extent['halfDepth']), $ground, $ground + $plate,
					$extent['halfDepth'], $extent['halfDepth']);
			}
			$bottom = min(0.0, $gate['ground'][0]);
		}
		// Every kind spans its full width above the window: the board and post tabs, the crossbar, the banner and tabs.
		$solids[] = $box(0.0, $window['top'], $extent['top'], $extent['halfWidth'], $extent['halfDepth']);
		return ['gate' => $gate, 'reach' => hypot($extent['halfWidth'], $extent['halfDepth']), 'id' => $gate['id'],
			'kind' => $gate['kind'], 'solids' => $solids,
			'opening' => ['center' => [$gate['x'], $gate['y'], $gate['z']], 'yaw' => $gate['yaw'],
				'halfWidth' => $window['halfWidth'], 'bottom' => $gate['y'] + $bottom, 'top' => $gate['y'] + $window['top']]];
	}

	/**
	 * Find the gate a pick ray passes closest to, among those it passes within their half extent.
	 * @param array $origin Ray origin [x, y, z] in meters.
	 * @param array $direction Unit ray direction.
	 * @param float $length Distance along the ray to the ground it hit, or the pick range when it hit nothing.
	 * @return int|null The nearest gate's id, or null when the ray passes no gate within its span.
	 */
	public function nearest(array $origin, array $direction, float $length): ?int
	{
		$end = [$origin[0] + $direction[0] * $length, $origin[1] + $direction[1] * $length,
			$origin[2] + $direction[2] * $length];
		$best = null;
		foreach($this->gates() as $gate)
		{
			['window' => $window, 'extent' => $extent] = $this->measure($gate);
			// The gate's center line runs from the lowest point a vehicle may pass to the top of its frame.
			$foot = [$gate['x'], $gate['y'] + $window['bottom'], $gate['z']];
			$head = [$gate['x'], $gate['y'] + $extent['top'], $gate['z']];
			[$distance, $along] = self::segmentDistance($origin, $end, $foot, $head);
			if($distance > $extent['halfWidth']) continue;
			if($best === null || $distance < $best[1] || $distance === $best[1] && $along < $best[2])
				$best = [$gate['id'], $distance, $along];
		}
		return $best[0] ?? null;
	}

	/**
	 * Measure the closest approach of two segments, after Ericson's Real-Time Collision Detection, section 5.1.9.
	 * @return array{0:float,1:float} The distance in meters and the fraction along the first segment where it occurs.
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
	 * @return array{gates:list<array>} Gates in race order with their sizes, without ids, which loading assigns
	 * afresh, and without the ground under them, which loading measures again.
	 */
	public function export(): array
	{
		$gates = [];
		foreach([$this->start, ...$this->checkpoints, $this->finish] as $gate)
		{
			if($gate === null) continue;
			$saved = [];
			foreach([...self::SAVED, 'laps', 'number', 'width', 'height'] as $key)
				if(array_key_exists($key, $gate)) $saved[$key] = $gate[$key];
			$gates[] = $saved;
		}
		return ['gates' => $gates];
	}

	/** Identify the course by its gates and the laps it runs, so a session keeps one best time per course. */
	public function signature(): string
	{
		if($this->signatureRevision !== $this->revision)
		{
			$course = $this->export();
			// A course with a finish gate runs once, whatever laps its start gate keeps for when that gate goes.
			if($this->finish !== null && $this->start !== null) $course['gates'][0]['laps'] = 1;
			$this->signature = md5(json_encode($course, JSON_THROW_ON_ERROR));
			$this->signatureRevision = $this->revision;
		}
		return $this->signature;
	}

	/**
	 * Rebuild a course from a save file, which is untrusted input.
	 * @param mixed $data The file's course object; null reads as a course without gates.
	 * @param float $half Half the landscape's width in meters. Every gate must stand on it.
	 * @param \Closure|null $ground Ground height sampler for the landscape the course stands on. With it each road gate
	 * stands on the highest ground across its span again, and each gate measures the ground under it.
	 * @param GateShapes|null $shapes Gate dimensions; the package's when omitted.
	 * @return self The course, with ids assigned in race order. A gate saved without a width and height, as files
	 * from before gate sizes are, takes its kind's own.
	 * @throws \InvalidArgumentException When any part of the course is malformed or out of range.
	 */
	public static function import(mixed $data, float $half, ?\Closure $ground = null, ?GateShapes $shapes = null): self
	{
		$course = new self($shapes);
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
			$kind = $gate['kind'] ?? null;
			if(!in_array($kind, GateShapes::KINDS, true)) throw $fail('a gate has an unknown kind');
			$keys = [...self::SAVED,
				...match($role) { 'start' => ['laps'], 'checkpoint' => ['number'], 'finish' => [] }];
			$sized = array_key_exists('width', $gate) || array_key_exists('height', $gate);
			if($sized) $keys = [...$keys, 'width', 'height'];
			$present = array_keys($gate);
			sort($present); sort($keys);
			if($present !== $keys) throw $fail("a $role gate must hold exactly " . implode(', ', $keys));
			foreach(['x', 'y', 'z', 'yaw', ...($sized ? ['width', 'height'] : [])] as $key)
				if(!$number($gate[$key])) throw $fail("a gate's $key is not a number");
			if(abs($gate['x']) > $half || abs($gate['z']) > $half) throw $fail('a gate stands off the landscape');
			if(abs($gate['y']) > 20000) throw $fail('a gate stands out of height range');
			if(abs($gate['yaw']) > M_PI) throw $fail('a gate yaw lies outside -pi to pi');
			[$width, $height] = $course->shapes->kind($kind)['size']['default'];
			if($sized)
			{
				[$width, $height] = [(float)$gate['width'], (float)$gate['height']];
				if(!$course->shapes->fits($kind, $width, $height)) throw $fail("a $kind gate's size is out of range");
			}
			if($role === 'start' && (!is_int($gate['laps']) || $gate['laps'] < 1 || $gate['laps'] > self::MAX_LAPS))
				throw $fail('laps must be a whole number from 1 to 20');
			if($role === 'checkpoint' && !is_int($gate['number']))
				throw $fail('a checkpoint number must be a whole number');
			if($role === 'start' && $course->start !== null || $role === 'finish' && $course->finish !== null)
				throw $fail("the course has two $role gates");
			$record = $course->fit(['id' => 0, 'role' => $role, 'kind' => $kind, 'x' => (float)$gate['x'],
				'y' => (float)$gate['y'], 'z' => (float)$gate['z'], 'yaw' => (float)$gate['yaw'], 'width' => $width,
				'height' => $height], $ground);
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
