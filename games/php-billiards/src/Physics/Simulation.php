<?php
declare(strict_types=1);
namespace PhpBilliards\Physics;

use PhpBilliards\Online\BinaryState;

/**
 * Deterministic grounded billiards simulation with swept contacts and spin-aware cloth friction.
 *
 * Fixed-step updates resolve ball contacts, cushions, jaws, pocket shelves, drops, rolling, and sliding while
 * exposing repeatable shots, projected paths, placement rules, events, and interpolated state to the game shell.
 */
final class Simulation
{
	public const STEP = 1 / 60;
	public const SUBSTEPS = 4;
	public const MAX_SPEED = 8.75;
	private const MIN_SHOT_SPEED = .5;
	private const POWER_EXPONENT = 2.55;
	private const CUSHION_RESTITUTION = .78;
	private const CLOTH_FRICTION = .19;
	private const ROLLING_RESISTANCE = .013;
	private const GRAVITY = 9.80665;
	private const RACK_GAP = .00004;
	private const POCKET_DRAFT = .24007875908011603; // tan(13.5 degrees)
	private const POCKET_RESTITUTION = .52;
	private const POCKET_SINK_DEPTH = .115;

	/** @var list<Ball> Complete cue and object-ball set in deterministic storage order. */
	public array $balls = [];
	/** Current interaction phase: aiming, moving, or scratch placement. */
	public string $phase = 'aim';
	/** Whether the latest shot pocketed the cue ball. */
	public bool $scratched = false;
	/** Number of accepted cue strikes since the current rack began. */
	public int $shots = 0;
	/** @var list<array<string,mixed>> */
	private array $events = [];
	/** Presentation-only latch: one sound per continuous physical contact, never read by the force model. */
	private array $impactContacts = [];
	/** @var array<string,mixed>|null */
	private ?array $shotSnapshot = null;
	/** @var list<array{float,float}>|null Exact cue contacts captured by a simulation-backed aim preview. */
	private ?array $projectionPoints = null;
	private bool $projectionStop = false;
	/**
	 * Whether this is an aim preview, which stops at the cue's first contact with another ball. Until then only the cue
	 * moves, so the preview steps and searches the cue alone, with exactly the result stepping every ball would give.
	 */
	private bool $cueOnly = false;
	/** Cue travel, step count and limits of an aim preview in progress. */
	private float $projectionTravel = 0.0;
	private int $projectionIterations = 0;
	private float $projectionLength = 0.0;
	private float $projectionStartOffset = 0.0;
	/** @var list<Ball> Balls sorted by number for the swept search, rebuilt when `$balls` changes. */
	private array $numberOrder = [];
	/** @var list<Ball> The `$balls` list that `$numberOrder` was sorted from. */
	private array $numberOrderSource = [];
	/** @var array<string,mixed>|null First cue/object contact seen while projecting the aiming guide. */
	private ?array $projectionContact = null;
	/** Match seed used for the current online rack, or null for the established free-play rack. */
	private ?string $rackSeed = null;
	/** Derived rack number used when an eight ball on the break requires a rerack. */
	private int $rackIndex = 0;

	/**
	 * Create the established free-play rack or a seed-derived legal online rack.
	 *
	 * @param string|null $rackSeed Commit/reveal-derived match seed, or null for free play.
	 * @param int $rackIndex Nonnegative derived rack number for an eight on the break.
	 */
	public function __construct(?string $rackSeed = null, int $rackIndex = 0)
	{
		$this->rack($rackSeed, $rackIndex);
	}

	/**
	 * Restore a standard fifteen-ball rack and cue ball.
	 *
	 * Regulation spacing receives a seeded sub-millimetre perturbation and stable independent ball orientations,
	 * preventing symmetric break artifacts without giving repeated runs any nondeterministic input.
	 *
	 * @param string|null $rackSeed Commit/reveal-derived match seed, or null for free play.
	 * @param int $rackIndex Nonnegative derived rack number.
	 */
	public function rack(?string $rackSeed = null, int $rackIndex = 0): void
	{
		$this->rackSeed = $rackSeed;
		$this->rackIndex = max(0, $rackIndex);
		$this->balls = [new Ball(0, -Table::WIDTH * .28, 0)];
		$spacing = Ball::RADIUS * 2 + self::RACK_GAP;
		$apex = Table::FOOT_SPOT;
		$numbers = $rackSeed === null
			? [1,9,2,10,8,3,11,4,12,5,13,6,14,7,15]
			: self::seededRack($rackSeed, $this->rackIndex);
		$index = 0;

		for($row = 0; $row < 5; $row++)
		for($column = 0; $column <= $row; $column++)
		{
			$number = $numbers[$index++];
			$idealX = $apex + $row * $spacing * .866025403784;
			$idealY = ($column - $row / 2) * $spacing;
			$noiseX = self::rackNoise($number, 0, $rackSeed, $this->rackIndex, $index - 1);
			$noiseY = self::rackNoise($number, 1, $rackSeed, $this->rackIndex, $index - 1);
			$scale = 1.0;

			do
			{
				$x = $idealX + $noiseX * $scale;
				$y = $idealY + $noiseY * $scale;
				$separated = true;

				foreach($this->balls as $placed) if($placed->number !== 0
					&& hypot($x - $placed->x, $y - $placed->y) < Ball::RADIUS * 2 + .000005)
				{
					$separated = false;
					break;
				}

				$scale *= .5;
			}
			while(!$separated && $scale >= .03125);

			if(!$separated) [$x,$y] = [$idealX,$idealY];

			$this->balls[] = new Ball($number, $x, $y);
		}

		foreach($this->balls as $ball) self::orientBall($ball, $rackSeed, $this->rackIndex);

		$this->impactContacts = [];
		$this->phase = 'aim';
		$this->scratched = false;
		$this->shots = 0;
		$this->shotSnapshot = null;
		$this->events[] = ['type' => 'rack'];
	}

	/** Fixed sub-pixel variation prevents a perfectly symmetric break without changing replay. */
	private static function rackNoise(
		int $number, int $axis, ?string $seed = null, int $rackIndex = 0, int $slot = 0
	): float
	{
		if($seed !== null)
		{
			$bytes = hash('sha256', "PHP Billiards rack noise v1\0$seed\0$rackIndex\0$slot\0$number\0$axis", true);
			$value = (ord($bytes[0]) << 16) | (ord($bytes[1]) << 8) | ord($bytes[2]);
			return (($value + .5) / 16777216 - .5) * .00002;
		}
		$hash = (($number + 11) * 48271 + ($axis + 3) * 69621 + 0x35a7bd) % 2147483647;

		return (($hash % 2001) / 2000 - .5) * .00002;
	}

	/** Give every racked ball a stable independent orientation instead of a camera-visible uniform alignment. */
	private static function orientBall(Ball $ball, ?string $seed = null, int $rackIndex = 0): void
	{
		$sample = static function(int $salt) use ($ball, $seed, $rackIndex): float {
			if($seed !== null)
			{
				$bytes = hash('sha256', "PHP Billiards ball orientation v1\0$seed\0$rackIndex\0{$ball->number}\0$salt", true);
				$value = (ord($bytes[0]) << 16) | (ord($bytes[1]) << 8) | ord($bytes[2]);
				return ($value + .5) / 16777216;
			}
			// Keep the largest intermediate (1008 * 2,000,003) inside signed 32-bit PHP.
			$value = (($ball->number + 1) * 97 + ($salt + 1) * 193 + 17) % 1009;
			$hash = ($value * 2000003) % 2147483629;

			return ($hash + .5) / 2147483629;
		};
		$u1 = $sample(0);
		$u2 = $sample(1) * M_PI * 2;
		$u3 = $sample(2) * M_PI * 2;
		$lower = sqrt(1 - $u1);
		$upper = sqrt($u1);
		$ball->qx = $lower * sin($u2);
		$ball->qy = $lower * cos($u2);
		$ball->qz = $upper * sin($u3);
		$ball->qw = $upper * cos($u3);
	}

	/** @return list<int> Regulation rack with a seeded legal permutation. */
	private static function seededRack(string $seed, int $rackIndex): array
	{
		$solids = self::shuffle(range(1, 7), $seed, "rack:$rackIndex:solid");
		$stripes = self::shuffle(range(9, 15), $seed, "rack:$rackIndex:stripe");
		$rearSolid = array_pop($solids);
		$rearStripe = array_pop($stripes);
		$remaining = self::shuffle([...$solids,...$stripes], $seed, "rack:$rackIndex:remaining");
		$result = array_fill(0, 15, 0);
		$result[4] = 8;
		$result[10] = $rearSolid;
		$result[14] = $rearStripe;
		foreach($result as $slot => $number) if($number === 0) $result[$slot] = array_shift($remaining);
		return $result;
	}

	/** @param list<int> $values @return list<int> */
	private static function shuffle(array $values, string $seed, string $domain): array
	{
		for($index = count($values) - 1; $index > 0; $index--)
		{
			$bytes = hash('sha256', "PHP Billiards shuffle v1\0$seed\0$domain\0$index", true);
			$sample = ((ord($bytes[0]) & 0x7f) << 24) | (ord($bytes[1]) << 16)
				| (ord($bytes[2]) << 8) | ord($bytes[3]);
			$other = $sample % ($index + 1);
			[$values[$index],$values[$other]] = [$values[$other],$values[$index]];
		}
		return $values;
	}

	/**
	 * @param float $power Normalized cue-power setting.
	 */
	public static function shotSpeed(float $power): float
	{
		$power = max(.05, min(1, $power));

		return self::MIN_SHOT_SPEED + (self::MAX_SPEED - self::MIN_SHOT_SPEED) * $power ** self::POWER_EXPONENT;
	}

	public function cue(): Ball
	{
		foreach($this->balls as $ball) if($ball->number === 0) return $ball;

		throw new \LogicException('Simulation has no cue ball');
	}

	/**
	 * Strike the cue ball with normalized power and top, back, or side spin.
	 *
	 * A valid resting aim state is snapshotted for reset before clamped controls become linear velocity and angular
	 * velocity, then the simulation enters its moving phase and publishes one shot event.
	 *
	 * @param float $angle Cue direction in cloth-plane radians.
	 * @param float $power Normalized cue-power setting.
	 * @param float $top Normalized draw or follow offset.
	 * @param float $side Normalized left or right English offset.
	 */
	public function shoot(float $angle, float $power, float $top = 0, float $side = 0): bool
	{
		if($this->phase !== 'aim' || $this->moving() || !$this->cue()->active) return false;

		$power = max(.05, min(1, $power));
		$top = max(-1, min(1, $top));
		$side = max(-1, min(1, $side));
		$this->shotSnapshot = $this->snapshot();
		$speed = self::shotSpeed($power);
		$dx = cos($angle);
		$dy = sin($angle);
		$cue = $this->cue();
		$cue->vx = $dx * $speed;
		$cue->vy = $dy * $speed;
		$cue->spinX = $dy * $speed / Ball::RADIUS * $top;
		$cue->spinZ = -$dx * $speed / Ball::RADIUS * $top;
		$cue->spinY = $side * $speed / Ball::RADIUS * .42;
		$this->phase = 'moving';
		$this->shots++;
		$this->events[] = ['type' => 'shot','power' => $power];

		return true;
	}

	/**
	 * Predict the visible cue-ball centre path for an aimed shot.
	 *
	 * A private snapshot clone runs the same contact, cushion, jaw, and pocket solver as a real shot, then clips and
	 * simplifies its travelled path. The source simulation is never mutated.
	 *
	 * @param float $angle Cue direction in cloth-plane radians.
	 * @param float $power Normalized cue-power setting.
	 * @param float $top Normalized draw or follow offset.
	 * @param float $side Normalized left or right English offset.
	 * @param float $length Maximum projected path length.
	 * @param float $startOffset Distance omitted from the start of the guide.
	 * @return list<array{float,float}>
	 */
	public function projectPath(
		float $angle, float $power = .58, float $top = 0, float $side = 0
		, float $length = .71, float $startOffset = .05
	): array {
		$preview = $this->beginProjection($angle, $power, $top, $side, $length, $startOffset);
		if($preview === null) return [];

		$preview->advanceProjection(PHP_INT_MAX);
		$result = $preview->projectionResult();
		$this->projectionContact = $result['contact'];

		return $result['path'];
	}

	/**
	 * Start an aim preview that can be advanced a few substeps at a time.
	 *
	 * @param float $angle Cue direction in cloth-plane radians.
	 * @param float $power Normalized cue power.
	 * @param float $top Normalized draw or follow offset.
	 * @param float $side Normalized left or right English offset.
	 * @param float $length Longest guide length in metres.
	 * @param float $startOffset Guide length hidden under the cue ball, in metres.
	 * @return self|null The preview table, or null when no shot can be previewed.
	 */
	public function beginProjection(
		float $angle, float $power = .58, float $top = 0, float $side = 0, float $length = 1.8, float $startOffset = .05
	): ?self {
		if($this->phase !== 'aim' || $this->moving() || !$this->cue()->active
			|| !is_finite($angle) || $length <= 0 || !is_finite($length)) return null;

		$preview = new self;
		$preview->restore($this->snapshot());
		$preview->events = [];
		$preview->shotSnapshot = null;

		if(!$preview->shoot($angle, $power, $top, $side)) return null;

		$cue = $preview->cue();
		$preview->events = [];
		$preview->projectionPoints = [[$cue->x,$cue->y]];
		$preview->projectionStop = false;
		$preview->cueOnly = true;
		$preview->projectionTravel = 0.0;
		$preview->projectionIterations = 0;
		$preview->projectionLength = $length;
		$preview->projectionStartOffset = $startOffset;

		return $preview;
	}

	/**
	 * Advance an aim preview by at most a number of substeps.
	 *
	 * @param int $substeps Most substeps to run now.
	 * @return bool Whether the preview has finished.
	 */
	public function advanceProjection(int $substeps): bool
	{
		if($this->projectionPoints === null) return true;
		$cue = $this->cue();

		for($step = 0; $step < $substeps; $step++)
		{
			if(!(!$this->projectionStop && $this->moving() && $cue->active && $cue->pocket === null
				&& $this->projectionTravel < $this->projectionLength && $this->projectionIterations++ < 2400)) return true;

			$before = count($this->projectionPoints);
			$this->substep(1 / 240);
			$this->appendProjectionPoint($cue->x, $cue->y);

			for($index = max(1, $before); $index < count($this->projectionPoints); $index++)
			{
				[$x1,$y1] = $this->projectionPoints[$index - 1];
				[$x2,$y2] = $this->projectionPoints[$index];
				$this->projectionTravel += hypot($x2 - $x1, $y2 - $y1);
			}
		}

		return false;
	}

	/** @return array{path:list<array{float,float}>,contact:array<string,mixed>|null} The preview's guide so far. */
	public function projectionResult(): array
	{
		if($this->projectionPoints === null) return ['path' => [],'contact' => null];
		$path = self::clipPath($this->projectionPoints, $this->projectionLength);
		$path = self::trimPathStart($path, max(0, $this->projectionStartOffset));

		return ['path' => self::simplifyPath($path, .00005),'contact' => $this->projectionContact];
	}

	/**
	 * Predict the cue-ball path together with its first object-ball contact.
	 *
	 * The contact carries the ghost-ball centre and the object ball's departure direction along the line of centres,
	 * which is exact for the instant of contact before throw and spin transfer act.
	 *
	 * @return array{path:list<array{float,float}>,contact:array{ball:int,x:float,y:float,objectX:float,objectY:float,direction:array{float,float}}|null}
	 */
	public function projectShot(
		float $angle, float $power = .58, float $top = 0, float $side = 0, float $length = 1.8, float $startOffset = .05
	): array {
		$this->projectionContact = null;
		$path = $this->projectPath($angle, $power, $top, $side, $length, $startOffset);
		$contact = $this->projectionContact;
		$this->projectionContact = null;

		return ['path' => $path,'contact' => $contact];
	}

	/**
	 * Advance one fixed tick through four 240 Hz integration slices.
	 *
	 * Previous poses are retained for rendering before swept contacts, rail and pocket interactions, cloth friction,
	 * and rest detection advance the phase to aiming or scratch placement.
	 *
	 * @param float $seconds Fixed simulation interval in seconds.
	 */
	public function step(float $seconds = self::STEP): void
	{
		if($seconds <= 0 || !is_finite($seconds)) return;

		foreach($this->balls as $ball) $ball->remember();
		$slice = $seconds / self::SUBSTEPS;

		for($i = 0; $i < self::SUBSTEPS; $i++) $this->substep($slice);

		if($this->phase === 'moving' && !$this->moving())
		{
			$this->clearRestingCompression();
			foreach($this->balls as $ball) $ball->remember();
			$this->phase = $this->scratched ? 'placing' : 'aim';
		}
	}

	private function substep(float $seconds): void
	{
		$remaining = $seconds;
		$events = 0;
		$contactPending = false;

		while($remaining > 1e-12)
		{
			// Sweep during free flight; resolve compression at material time scales. After many boundary events
			// use the same bounded microsteps, rather than advancing the unresolved remainder through collisions.
			if(!$this->cueOnly && ($contactPending || $events >= 32
				|| ContactModel::elasticEnergy($this->contactBalls()) > ContactModel::SLEEP_ENERGY))
			{
				$duration = min($remaining, ContactModel::MAX_STEP);
				$boundary = $this->earliest($duration, false);
				if($boundary && $boundary['time'] <= 1e-12)
				{
					$this->resolveBoundary($boundary);
					$events++;
					$boundary = null;
				}
				elseif($boundary) $duration = $boundary['time'];
				$this->advanceContacts($duration);
				$remaining -= $duration;
				if($boundary) {$this->resolveBoundary($boundary); $events++;}
				$contactPending = false;
				continue;
			}

			$collision = $this->earliest($remaining);
			$advance = $collision ? max(0, $collision['time']) : $remaining;
			$this->advanceActive($advance);
			$remaining -= $advance;
			if(!$collision) break;

			$cueCollision = $this->projectionPoints !== null && $this->collisionTouchesCue($collision);
			if($cueCollision)
			{
				$cue = $this->cue();
				$this->appendProjectionPoint($cue->x, $cue->y);

				if($collision['type'] === 'ball' && $this->projectionContact === null)
				{
					$object = $collision['a']->number === 0 ? $collision['b'] : $collision['a'];
					$dx = $object->x - $cue->x;
					$dy = $object->y - $cue->y;
					$distance = max(1e-12, hypot($dx, $dy));
					$this->projectionContact = [
						'ball' => $object->number,'x' => $cue->x,'y' => $cue->y,'objectX' => $object->x,'objectY' => $object->y
						, 'direction' => [$dx / $distance,$dy / $distance]
					];
				}
			}

			// Preview terminates at the swept cue contact, before evaluating any elastic contact forces.
			if($cueCollision && ($collision['type'] === 'ball' || $collision['type'] === 'pocket'))
			{
				$this->projectionStop = true;
				return;
			}
			if($collision['type'] === 'ball') $contactPending = true;
			else {$this->resolveBoundary($collision); $events++;}
		}

		foreach($this->balls as $ball) if($ball->active && $ball->pocket === null && (!$this->cueOnly || $ball->number === 0))
		{
			$pocket = Table::pocketEntry($ball->x, $ball->y);

			if($pocket !== null)
			{
				$this->enterPocket($ball, $pocket['index']);
				continue;
			}

			$this->applyCloth($ball, $seconds);
		}
	}

	/** @param array<string,mixed> $collision */
	private function collisionTouchesCue(array $collision): bool
	{
		if($collision['type'] === 'ball')
		return $collision['a']->number === 0 || $collision['b']->number === 0;

		return isset($collision['ball']) && $collision['ball']->number === 0;
	}

	private function appendProjectionPoint(float $x, float $y): void
	{
		if($this->projectionPoints === null || !is_finite($x) || !is_finite($y)) return;

		$last = $this->projectionPoints[array_key_last($this->projectionPoints)];
		if(hypot($x - $last[0], $y - $last[1]) > 1e-10) $this->projectionPoints[] = [$x,$y];
	}

	/** @param list<array{float,float}> $points @return list<array{float,float}> */
	private static function clipPath(array $points, float $length): array
	{
		if(count($points) < 2 || $length <= 0) return [];

		$result = [$points[0]];
		$remaining = $length;

		for($index = 1; $index < count($points) && $remaining > 1e-12; $index++)
		{
			[$x1,$y1] = $points[$index - 1];
			[$x2,$y2] = $points[$index];
			$segment = hypot($x2 - $x1, $y2 - $y1);
			if($segment <= 1e-12) continue;

			if($segment <= $remaining + 1e-12)
			{
				$result[] = [$x2,$y2];
				$remaining -= $segment;
				continue;
			}

			$amount = $remaining / $segment;
			$result[] = [$x1 + ($x2 - $x1) * $amount,$y1 + ($y2 - $y1) * $amount];
			$remaining = 0;
		}

		return $result;
	}

	/** @param list<array{float,float}> $points @return list<array{float,float}> */
	private static function trimPathStart(array $points, float $skip): array
	{
		if(count($points) < 2 || $skip <= 0) return $points;

		for($index = 1; $index < count($points); $index++)
		{
			[$x1,$y1] = $points[$index - 1];
			[$x2,$y2] = $points[$index];
			$segment = hypot($x2 - $x1, $y2 - $y1);
			if($segment <= 1e-12) continue;

			if($skip >= $segment - 1e-12)
			{
				$skip -= $segment;
				continue;
			}

			$amount = $skip / $segment;

			return array_merge([[$x1 + ($x2 - $x1) * $amount,$y1 + ($y2 - $y1) * $amount]]
				, array_slice($points, $index));
		}

		return [];
	}

	/** @param list<array{float,float}> $points @return list<array{float,float}> */
	private static function simplifyPath(array $points, float $tolerance): array
	{
		if(count($points) <= 2) return $points;

		$first = $points[0];
		$last = $points[array_key_last($points)];
		$dx = $last[0] - $first[0];
		$dy = $last[1] - $first[1];
		$denominator = hypot($dx, $dy);
		$furthest = 0.0;
		$split = 0;

		for($index = 1; $index < count($points) - 1; $index++)
		{
			$distance = $denominator < 1e-12
				? hypot($points[$index][0] - $first[0], $points[$index][1] - $first[1])
				: abs($dy * $points[$index][0] - $dx * $points[$index][1]
					+ $last[0] * $first[1] - $last[1] * $first[0]) / $denominator;

			if($distance > $furthest) [$furthest,$split] = [$distance,$index];
		}

		if($furthest <= $tolerance) return [$first,$last];

		$left = self::simplifyPath(array_slice($points, 0, $split + 1), $tolerance);
		$right = self::simplifyPath(array_slice($points, $split), $tolerance);

		return array_merge(array_slice($left, 0, -1), $right);
	}

	/** Transfer a ball to its liner at the exact swept crossing of the circular clearance. */
	private function enterPocket(Ball $ball, int $index): void
	{
		if($ball->pocket !== null) return;

		$ball->pocket = $index;
		$ball->drop = $ball->previousDrop = 0;
		$ball->vDrop = 0;
		$this->events[] = ['type' => 'pocket-enter','ball' => $ball->number,'pocket' => $index];
	}

	/** Advance cloth balls and balls rattling inside the conical pocket liners. */
	private function advanceActive(float $seconds): void
	{
		if($seconds <= 0) return;

		foreach($this->orderedBalls() as $ball) if($ball->active && (!$this->cueOnly || $ball->number === 0))
		{
			$ball->advance($seconds);

			if($ball->pocket !== null) $this->advancePocket($ball, $seconds);
		}
		foreach($this->impactContacts as $key => [$a,$b])
		if(!$a->active || !$b->active || ($a->x - $b->x) ** 2 + ($a->y - $b->y) ** 2
			+ ($a->drop - $b->drop) ** 2 > (2 * Ball::RADIUS) ** 2) unset($this->impactContacts[$key]);
	}

	/** Gravity, the rim of the slate cut, and the conical liner decide whether a ball rattles out or sinks. */
	private function advancePocket(Ball $ball, float $seconds, bool $gravity = true): void
	{
		$index = $ball->pocket;
		[$pocketX,$pocketY] = Table::pockets()[$index];
		if($gravity)
		{
			$ball->drop += $ball->vDrop * $seconds + .5 * self::GRAVITY * $seconds ** 2;
			$ball->vDrop += self::GRAVITY * $seconds;
		}
		$relativeX = $ball->x - $pocketX;
		$relativeY = $ball->y - $pocketY;
		$radial = hypot($relativeX, $relativeY);
		[$radialX,$radialY] = $radial < 1e-9 ? [1.0,0.0] : [$relativeX / $radial,$relativeY / $radial];
		$normal = null;
		$onRim = $ball->drop < Ball::RADIUS;
		[$outwardX,$outwardY] = Table::pocketOutward($index);

		if($onRim && $relativeX * $outwardX + $relativeY * $outwardY > 0 && $radial > Table::LINER_RADIUS)
		{
			// Behind the pocket centre the cut is closed by the rail and the back of the liner: a wall that returns
			// the ball rather than a rim it could tip over or a cloth it could roll back onto. The leather hangs at the
			// lip, inside the cut, so a ball pressed against it is already past the rim edge and tips in rather than
			// balancing on top of it.
			$radial = Table::LINER_RADIUS;
			$normal = [$radialX,$radialY,0.0];
			$onRim = false;
		}
		elseif($onRim)
		{
			// Rolling over the rim of the cut: the centre keeps one radius from the rim edge, so pushing back along
			// the line to that edge lets the ball tip inward and down rather than fall straight through the cloth.
			$inward = Table::THROAT_RADIUS - $radial;
			$above = Ball::RADIUS - $ball->drop;
			$distance = hypot(max(0, $inward), $above);

			if($inward <= 0 && $ball->drop > 0)
			{
				$ball->drop = 0;
				$normal = [0.0,0.0,1.0];
			}
			elseif($inward > 0 && $distance < Ball::RADIUS)
			{
				$radial = Table::THROAT_RADIUS - $inward * Ball::RADIUS / $distance;
				$ball->drop = Ball::RADIUS - $above * Ball::RADIUS / $distance;
				$normal = [$radialX * $inward / $distance,$radialY * $inward / $distance,$above / $distance];
			}
		}
		else
		{
			// Below the rim the liner cone narrows at the draft angle from where the rim left off.
			$allowed = max(.001, Table::THROAT_RADIUS - Ball::RADIUS - self::POCKET_DRAFT * ($ball->drop - Ball::RADIUS));

			if($radial > $allowed)
			{
				$radial = $allowed;
				$normalLength = hypot(1, self::POCKET_DRAFT);
				$normal = [$radialX / $normalLength,$radialY / $normalLength,self::POCKET_DRAFT / $normalLength];
			}
		}

		if($normal !== null)
		{
			[$nx,$ny,$nz] = $normal;
			$ball->x = $pocketX + $radialX * $radial;
			$ball->y = $pocketY + $radialY * $radial;
			$normalSpeed = $ball->vx * $nx + $ball->vy * $ny + $ball->vDrop * $nz;

			if($normalSpeed > 0)
			{
				// The rim supports slow roll; the compliant lining still returns soft pocket knocks.
				$restitution = $onRim && $normalSpeed < .3 ? 0.0 : self::POCKET_RESTITUTION;
				$impulse = (1 + $restitution) * $normalSpeed;
				$ball->vx -= $impulse * $nx;
				$ball->vy -= $impulse * $ny;
				$ball->vDrop -= $impulse * $nz;
				// A supported ball can touch on every microstep. Apply the strike losses only to a rebound,
				// not repeatedly to resting contact as gravity or another ball presses it into the lining.
				if($normalSpeed >= .3)
				{
					$ball->vx *= .985;
					$ball->vy *= .985;
					$ball->spinY *= .92;
				}
				// Depth, in ball radii below resting on the cloth, lets the sound tell a ball rolling around the edge
				// from one dropping out of sight down the liner.
				if($restitution > 0) $this->events[] = ['type' => 'pocket-rattle','ball' => $ball->number,'pocket' => $index
					,'strength' => min(1, $normalSpeed / self::MAX_SPEED),'depth' => $ball->drop / Ball::RADIUS];
			}
		}

		// A ball that rises onto the shelf meets the two tangent facings as solid lateral walls before it goes back to
		// the cloth.
		if($onRim && $ball->drop <= 1e-8 && hypot($ball->x - $pocketX, $ball->y - $pocketY) >= Table::THROAT_RADIUS)
		$this->clearPocketFacingOverlap($ball, $index);
		[$mouthX,$mouthY] = Table::pocketMouthCenter($index);
		$crossedMouth = ($ball->x - $mouthX) * $outwardX + ($ball->y - $mouthY) * $outwardY <= 0;
		$leaving = $ball->vx * $outwardX + $ball->vy * $outwardY < -1e-6;
		// Once its centre is past the cut on the table side, the ball rests on the shelf cloth whatever its speed. Only
		// cloth friction turns its leftover spin into roll or stops it; kept here, a resting ball would never end the shot.
		$relativeX = $ball->x - $pocketX;
		$relativeY = $ball->y - $pocketY;
		$onShelf = $onRim && $ball->drop <= 1e-8 && hypot($relativeX, $relativeY) > Table::THROAT_RADIUS
			&& $relativeX * $outwardX + $relativeY * $outwardY <= 0;
		if(!$onShelf && (!$crossedMouth || !$leaving) && $ball->drop < 0)
		{
			$ball->drop = 0;
			$ball->vDrop = max(0, $ball->vDrop);
		}
		elseif($onShelf || ($crossedMouth && $leaving && $ball->drop < 0))
		{
			// The facing correction can push the centre back over the cut; it stays in the pocket until it clears both.
			if(!$this->clearPocketFacingOverlap($ball, $index)
				|| hypot($ball->x - $pocketX, $ball->y - $pocketY) <= Table::THROAT_RADIUS)
			{
				$ball->drop = 0;
				$ball->vDrop = max(0, $ball->vDrop);
				return;
			}
			$ball->drop = $ball->previousDrop = 0;
			$ball->vDrop = 0;
			$ball->pocket = null;
			$this->events[] = ['type' => 'pocket-reject','ball' => $ball->number,'pocket' => $index];

			return;
		}

		if($ball->drop >= self::POCKET_SINK_DEPTH)
		{
			$ball->drop = $ball->previousDrop = self::POCKET_SINK_DEPTH;
			$ball->active = false;
			$ball->vx = $ball->vy = $ball->vDrop = $ball->spinX = $ball->spinY = $ball->spinZ = 0;
			$this->events[] = ['type' => 'pocket','ball' => $ball->number,'pocket' => $index];

			if($ball->number === 0) $this->scratched = true;
		}
	}

	/**
	 * Keep a rattling ball on the open side of both tangent pocket facings before returning it to cloth physics.
	 *
	 * Pocket integration owns the ball while it is above the cut, so the ordinary facing sweep cannot protect it.
	 * Projecting a penetration to the ball-centre offset and reflecting the inward velocity supplies that missing
	 * contact. The ball may leave pocket physics only once it clears both solid facings by one radius.
	 */
	private function clearPocketFacingOverlap(Ball $ball, int $index): bool
	{
		[$pocketX,$pocketY] = Table::pockets()[$index];
		$faces = array_slice(Table::facings(), $index * 2, 2);

		for($iteration = 0; $iteration < 3; $iteration++)
		{
			$corrected = false;
			foreach($faces as [$startX,$startY,$endX,$endY])
			{
				$segmentX = $endX - $startX;
				$segmentY = $endY - $startY;
				$length = hypot($segmentX, $segmentY);
				$nx = -$segmentY / $length;
				$ny = $segmentX / $length;
				if(($pocketX - $startX) * $nx + ($pocketY - $startY) * $ny < 0)
				[$nx,$ny] = [-$nx,-$ny];
				$distance = ($ball->x - $startX) * $nx + ($ball->y - $startY) * $ny;
				if($distance >= Ball::RADIUS) continue;

				$correction = Ball::RADIUS - $distance + 1e-7;
				$ball->x += $nx * $correction;
				$ball->y += $ny * $correction;
				$normalSpeed = $ball->vx * $nx + $ball->vy * $ny;
				if($normalSpeed < 0)
				{
					$impulse = (1 + self::POCKET_RESTITUTION) * $normalSpeed;
					$ball->vx -= $impulse * $nx;
					$ball->vy -= $impulse * $ny;
				}
				$corrected = true;
			}
			if(!$corrected) return true;
		}

		foreach($faces as [$startX,$startY,$endX,$endY])
		{
			$segmentX = $endX - $startX;
			$segmentY = $endY - $startY;
			$length = hypot($segmentX, $segmentY);
			$nx = -$segmentY / $length;
			$ny = $segmentX / $length;
			if(($pocketX - $startX) * $nx + ($pocketY - $startY) * $ny < 0) [$nx,$ny] = [-$nx,-$ny];
			if(($ball->x - $startX) * $nx + ($ball->y - $startY) * $ny < Ball::RADIUS - 1e-8) return false;
		}
		return true;
	}

	/** @return array<string,mixed>|null */
	private function earliest(float $limit, bool $includeBalls = true): ?array
	{
		$best = null;
		$this->orderedBalls();
		$balls = [];
		$resting = [];
		$reaches = [];
		foreach($this->numberOrder as $ball) if($ball->active)
		{
			$balls[] = $ball;
			// applyCloth() stops balls with exact zeros; every velocity-dependent test below rejects such a ball.
			$resting[] = $ball->vx == 0.0 && $ball->vy == 0.0 && $ball->pocket === null;
			// Farthest this ball can travel before the limit, plus a micrometre of slack far beyond rounding error.
			// A target outside that reach cannot produce an event, so box tests below skip only certain misses.
			$reaches[] = sqrt($ball->vx ** 2 + $ball->vy ** 2 + $ball->vDrop ** 2) * $limit
				+ ($ball->pocket !== null ? .5 * self::GRAVITY * $limit ** 2 : 0) + 1e-6;
		}
		$count = count($balls);
		[$pockets,$facings] = self::sweptGeometry();
		$halfX = Table::WIDTH / 2 - Ball::RADIUS;
		$halfY = Table::HEIGHT / 2 - Ball::RADIUS;

		// An aim preview's cue sorts first; the other balls stay at rest, so their own searches find nothing.
		for($i = 0; $i < ($this->cueOnly ? min(1, $count) : $count); $i++)
		{
			$a = $balls[$i];
			$restingA = $resting[$i];
			$speedSquared = $a->vx ** 2 + $a->vy ** 2;
			$reach = $reaches[$i];

			for($j = $i + 1; $includeBalls && $j < $count; $j++)
			{
				// Two resting balls have zero relative velocity and can never meet.
				if($restingA && $resting[$j]) continue;
				$b = $balls[$j];
				// Contact needs the centres within two radii plus both travel reaches.
				$gap = Ball::RADIUS * 2 + $reach + $reaches[$j];
				if(abs($b->x - $a->x) > $gap || abs($b->y - $a->y) > $gap) continue;

				$rx = $b->x - $a->x;
				$ry = $b->y - $a->y;
				$rz = $b->drop - $a->drop;
				$vx = $b->vx - $a->vx;
				$vy = $b->vy - $a->vy;
				$vz = $b->vDrop - $a->vDrop;
				$az = (($b->pocket !== null ? 1 : 0) - ($a->pocket !== null ? 1 : 0)) * self::GRAVITY;
				$radius = Ball::RADIUS * 2;
				$c = $rx * $rx + $ry * $ry + $rz * $rz - $radius * $radius;
				$bq = 2 * ($rx * $vx + $ry * $vy + $rz * $vz);
				$aq = $vx * $vx + $vy * $vy + $vz * $vz;

				if($az != 0.0)
				{
					$time = self::fallingContactTime($rx, $ry, $rz, $vx, $vy, $vz, $az, $limit);
					if($time === null) continue;
				}
				elseif($c <= 0 && $bq < 0) $time = 0;
				elseif($aq <= 1e-12 || $bq >= 0) continue;
				else
				{
					$disc = $bq * $bq - 4 * $aq * $c;

					if($disc < 0) continue;

					$time = (-$bq - sqrt($disc)) / (2 * $aq);
				}

				if($time >= -1e-9 && $time <= $limit && ($best === null || $time < $best['time']))
				$best = ['type' => 'ball','time' => max(0, $time),'a' => $a,'b' => $b];
			}

			if($a->pocket !== null)
			{
				// Split at each sink crossing so sink events from different pockets keep their physical order.
				$depth = max(0, self::POCKET_SINK_DEPTH - $a->drop);
				$time = (-$a->vDrop + sqrt($a->vDrop ** 2 + 2 * self::GRAVITY * $depth)) / self::GRAVITY;
				if($time <= $limit && ($best === null || $time < $best['time']))
				$best = ['type' => 'sink','time' => $time,'ball' => $a];
				continue; // Pocket liners own boundaries below the cloth.
			}

			// Pocket entry is a swept event, just like a rail or ball impact. Checking
			// only after a substep lets a fast ball cross the narrow clearance circle
			// and reach the back of the table, especially when a contact cluster
			// redirects it inside the mouth.
			foreach($pockets as $index => [$pocketX,$pocketY,$radius])
			{
				if(abs($a->x - $pocketX) > $radius + $reach || abs($a->y - $pocketY) > $radius + $reach) continue;
				$relativeX = $a->x - $pocketX;
				$relativeY = $a->y - $pocketY;
				$separation = $relativeX ** 2 + $relativeY ** 2 - $radius ** 2;
				$approach = 2 * ($relativeX * $a->vx + $relativeY * $a->vy);

				if($separation <= 0) $time = 0.0;
				elseif($speedSquared <= 1e-12 || $approach >= 0) continue;
				else
				{
					$discriminant = $approach ** 2 - 4 * $speedSquared * $separation;

					if($discriminant < 0) continue;

					$time = (-$approach - sqrt($discriminant)) / (2 * $speedSquared);
				}

				if($time < -1e-9 || $time > $limit || $best !== null && $time >= $best['time']) continue;

				$best = ['type' => 'pocket','time' => max(0, $time),'ball' => $a,'index' => $index];
			}

			// Only a pocket overlap can involve a resting ball; the backstop, rail, jaw, and facing sweeps all
			// require motion, so skipping them returns exactly what evaluating them would.
			if($restingA) continue;

			// A ball whose trajectory misses the drop clearance can still enter the
			// visible throat. The far half of the round liner must return it through
			// the mouth instead of allowing it to continue behind the table.
			foreach($pockets as $index => [$pocketX,$pocketY,$clearance,$outwardX,$outwardY])
			{
				$lateralX = -$outwardY;
				$lateralY = $outwardX;
				$relativeX = $a->x - $pocketX;
				$relativeY = $a->y - $pocketY;
				$distance = $relativeX * $outwardX + $relativeY * $outwardY;
				$outwardSpeed = $a->vx * $outwardX + $a->vy * $outwardY;

				if($outwardSpeed <= 1e-12) continue;

				$time = $distance >= 0 ? 0.0 : -$distance / $outwardSpeed;

				if($time < -1e-9 || $time > $limit || $best !== null && $time >= $best['time']) continue;

				$lateral = ($relativeX + $a->vx * max(0, $time)) * $lateralX
					+ ($relativeY + $a->vy * max(0, $time)) * $lateralY;

				if(abs($lateral) < $clearance - 1e-9
					|| abs($lateral) > Table::THROAT_RADIUS + Ball::RADIUS) continue;

				$best = ['type' => 'backstop','time' => max(0, $time),'ball' => $a,'index' => $index
					,'x' => $pocketX,'y' => $pocketY,'nx' => -$outwardX,'ny' => -$outwardY];
			}

			foreach([
				['x',$a->vx > 0 ? $halfX : -$halfX,$a->x,$a->vx,$a->y]
				, ['y',$a->vy > 0 ? $halfY : -$halfY,$a->y,$a->vy,$a->x]
			] as [$axis,$edge,$position,$velocity,$along])
			{
				if(abs($velocity) < 1e-12) continue;

				$time = ($edge - $position) / $velocity;

				if($time < -1e-9 || $time > $limit) continue;

				$coordinate = $along + ($axis === 'x' ? $a->vy : $a->vx) * $time;

				if(!Table::cushion($axis, $coordinate)) continue;

				if($best === null || $time < $best['time'])
				$best = ['type' => 'cushion','time' => max(0, $time),'ball' => $a,'axis' => $axis];
			}

			foreach(Table::jaws() as [$jawX,$jawY])
			{
				if(abs($a->x - $jawX) > Ball::RADIUS + $reach || abs($a->y - $jawY) > Ball::RADIUS + $reach) continue;
				$relativeX = $a->x - $jawX;
				$relativeY = $a->y - $jawY;
				$approach = 2 * ($relativeX * $a->vx + $relativeY * $a->vy);
				$separation = $relativeX ** 2 + $relativeY ** 2 - Ball::RADIUS ** 2;

				if($separation <= 0 && $approach < 0) $time = 0.0;
				elseif($speedSquared <= 1e-12 || $approach >= 0) continue;
				else
				{
					$discriminant = $approach ** 2 - 4 * $speedSquared * $separation;

					if($discriminant < 0) continue;

					$time = (-$approach - sqrt($discriminant)) / (2 * $speedSquared);
				}

				if($time < -1e-9 || $time > $limit || $best !== null && $time >= $best['time']) continue;

				$contactX = $a->x + $a->vx * max(0, $time) - $jawX;
				$contactY = $a->y + $a->vy * max(0, $time) - $jawY;
				$length = hypot($contactX, $contactY);

				if($length < 1e-9) continue;

				$best = ['type' => 'jaw','time' => max(0, $time),'ball' => $a
					,'x' => $jawX,'y' => $jawY,'nx' => $contactX / $length,'ny' => $contactY / $length];
			}

			foreach($facings as [$startX,$startY,$segmentLength,$tx,$ty,$lineX,$lineY,$minX,$maxX,$minY,$maxY])
			{
				$margin = Ball::RADIUS + $reach;
				if($a->x < $minX - $margin || $a->x > $maxX + $margin || $a->y < $minY - $margin || $a->y > $maxY + $margin) continue;
				$distance = ($a->x - $startX) * $lineX + ($a->y - $startY) * $lineY;
				$normalVelocity = $a->vx * $lineX + $a->vy * $lineY;

				if(abs($normalVelocity) < 1e-12) continue;

				foreach([-1.0,1.0] as $side)
				{
					$nx = $lineX * $side;
					$ny = $lineY * $side;
					$time = ($side * Ball::RADIUS - $distance) / $normalVelocity;

					if($time < -1e-9 || $time > $limit || $a->vx * $nx + $a->vy * $ny >= -1e-9) continue;

					$contactX = $a->x + $a->vx * max(0, $time);
					$contactY = $a->y + $a->vy * max(0, $time);
					$along = ($contactX - $startX) * $tx + ($contactY - $startY) * $ty;

					if($along <= 1e-8 || $along >= $segmentLength - 1e-8
						|| $best !== null && $time >= $best['time']) continue;

					$best = ['type' => 'facing','time' => max(0, $time),'ball' => $a
						,'x' => $startX,'y' => $startY,'nx' => $nx,'ny' => $ny];
				}
			}
		}

		return $best;
	}

	/** Conservative sweep with relative gravity; near a grazing root the bounded contact integrator takes over. */
	private static function fallingContactTime(float $x, float $y, float $z, float $vx, float $vy, float $vz,
		float $az, float $limit): ?float
	{
		$bound = sqrt($vx * $vx + $vy * $vy + $vz * $vz) + abs($az) * $limit;
		if($bound <= 0) return null;
		$time = 0.0;
		for($i = 0; $i < 40; $i++)
		{
			$dx = $x + $vx * $time; $dy = $y + $vy * $time;
			$dz = $z + $vz * $time + .5 * $az * $time * $time;
			$gap = sqrt($dx * $dx + $dy * $dy + $dz * $dz) - 2 * Ball::RADIUS;
			if($gap <= 1e-10) return $time;
			$time += $gap / $bound;
			if($time > $limit) return null;
		}
		return $time;
	}

	/**
	 * Per-pocket and per-facing constants for the swept search, derived once with the same arithmetic it used inline.
	 *
	 * @return array{list<array{float,float,float,float,float}>,list<list<float>>} Pockets as centre, clearance, and
	 * outward normal; facings as start, length, tangent, normal, and bounding box.
	 */
	private static function sweptGeometry(): array
	{
		static $geometry = null;
		if($geometry !== null) return $geometry;

		$pockets = [];
		foreach(Table::pockets() as $index => [$pocketX,$pocketY])
		{
			[$outwardX,$outwardY] = Table::pocketOutward($index);
			// A ball drops once its centre crosses the cloth edge, so the swept drop circle is the slate cut itself.
			$pockets[$index] = [$pocketX,$pocketY,Table::THROAT_RADIUS,$outwardX,$outwardY];
		}

		$facings = [];
		foreach(Table::facings() as [$startX,$startY,$endX,$endY])
		{
			$segmentX = $endX - $startX;
			$segmentY = $endY - $startY;
			$segmentLength = hypot($segmentX, $segmentY);
			$tx = $segmentX / $segmentLength;
			$ty = $segmentY / $segmentLength;
			$facings[] = [$startX,$startY,$segmentLength,$tx,$ty,-$ty,$tx
				, min($startX, $endX),max($startX, $endX),min($startY, $endY),max($startY, $endY)];
		}

		return $geometry = [$pockets,$facings];
	}

	/** @return list<Ball> */
	private function orderedBalls(): array
	{
		if($this->numberOrderSource !== $this->balls)
		{
			$this->numberOrder = $this->balls;
			usort($this->numberOrder, static fn(Ball $a, Ball $b): int => $a->number <=> $b->number);
			$this->numberOrderSource = $this->balls;
		}
		return $this->numberOrder;
	}

	/** @return list<Ball> */
	private function contactBalls(): array
	{
		return array_values(array_filter($this->orderedBalls(), static fn(Ball $ball): bool => $ball->active));
	}

	private function advanceContacts(float $seconds): void
	{
		$balls = $this->contactBalls();
		$this->recordImpacts($balls);
		ContactModel::advance($balls, $seconds);
		$this->recordImpacts($balls);
		foreach($balls as $ball) if($ball->pocket !== null) $this->advancePocket($ball, $seconds, false);
	}

	/** Aggregate simultaneous new contacts; persistent compression does not generate a sound per RK step. */
	private function recordImpacts(array $balls): void
	{
		$touching = [];
		$strength = 0.0;
		$count = count($balls);
		for($i = 0; $i < $count; $i++)
		for($j = $i + 1; $j < $count; $j++)
		{
			$a = $balls[$i]; $b = $balls[$j];
			$dx = $b->x - $a->x; $dy = $b->y - $a->y; $dz = $b->drop - $a->drop;
			if(abs($dx) > Ball::RADIUS * 2 || abs($dy) > Ball::RADIUS * 2 || abs($dz) > Ball::RADIUS * 2) continue;
			$distance = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
			if($distance > Ball::RADIUS * 2 + 1e-12 || $distance < 1e-15) continue;
			$key = $a->number . ':' . $b->number;
			$touching[$key] = [$a,$b];
			if(isset($this->impactContacts[$key])) continue;
			$approach = (($a->vx - $b->vx) * $dx + ($a->vy - $b->vy) * $dy
				+ ($a->vDrop - $b->vDrop) * $dz) / $distance;
			$strength = max($strength, $approach / self::MAX_SPEED);
		}
		$this->impactContacts = $touching;
		if($strength > 0) $this->events[] = ['type' => 'impact','strength' => min(1, $strength)];
	}

	/** Remove only the negligible stored compression left after all motion has gone to sleep. */
	private function clearRestingCompression(): void
	{
		$balls = array_values(array_filter($this->contactBalls(), static fn(Ball $b): bool => $b->pocket === null));
		$count = count($balls);
		for($pass = 0; $pass < 16; $pass++)
		{
			$changed = false;
			for($i = 0; $i < $count; $i++)
			for($j = $i + 1; $j < $count; $j++)
			{
				$a = $balls[$i]; $b = $balls[$j];
				$dx = $b->x - $a->x; $dy = $b->y - $a->y;
				$distance = hypot($dx, $dy);
				if($distance >= Ball::RADIUS * 2 || $distance < 1e-15) continue;
				$correction = (Ball::RADIUS * 2 - $distance + 1e-12) / (2 * $distance);
				$a->x -= $dx * $correction; $a->y -= $dy * $correction;
				$b->x += $dx * $correction; $b->y += $dy * $correction;
				$changed = true;
			}
			if(!$changed) break;
		}
	}

	private function resolveBoundary(array $collision): void
	{
		if($collision['type'] === 'sink')
		{
			$ball = $collision['ball'];
			if($ball->active && $ball->drop >= self::POCKET_SINK_DEPTH - 1e-10)
			{
				$ball->drop = self::POCKET_SINK_DEPTH;
				$this->advancePocket($ball, 0, false);
			}
		}
		elseif($collision['type'] === 'pocket') $this->enterPocket($collision['ball'], $collision['index']);
		elseif($collision['type'] === 'backstop') $this->resolvePocketBackstop($collision['ball'], $collision);
		elseif($collision['type'] === 'jaw') $this->resolveJaw($collision['ball'], $collision);
		elseif($collision['type'] === 'facing') $this->resolveFacing($collision['ball'], $collision);
		else $this->resolveCushion($collision['ball'], $collision['axis']);
	}

	private function resolveCushion(Ball $ball, string $axis): void
	{
		$normalSpeed = abs($axis === 'x' ? $ball->vx : $ball->vy);

		if($axis === 'x')
		{
			$ball->vx *= -self::CUSHION_RESTITUTION;
			$ball->vy *= .96;
			$ball->spinY *= .90;
			$limit = Table::WIDTH / 2 - Ball::RADIUS;
			$ball->x = max(-$limit, min($limit, $ball->x));
		}
		else
		{
			$ball->vy *= -self::CUSHION_RESTITUTION;
			$ball->vx *= .96;
			$ball->spinY *= .90;
			$limit = Table::HEIGHT / 2 - Ball::RADIUS;
			$ball->y = max(-$limit, min($limit, $ball->y));
		}

		$this->events[] = ['type' => 'cushion','strength' => min(1, $normalSpeed / self::MAX_SPEED)];
	}

	/** Resolve a rounded rubber jaw rather than treating its pocket gap as empty space. */
	private function resolveJaw(Ball $ball, array $collision): void
	{
		$nx = $collision['nx'];
		$ny = $collision['ny'];
		$normalSpeed = $ball->vx * $nx + $ball->vy * $ny;

		if($normalSpeed >= 0) return;

		$tangentX = $ball->vx - $normalSpeed * $nx;
		$tangentY = $ball->vy - $normalSpeed * $ny;
		$reflected = -$normalSpeed * self::CUSHION_RESTITUTION;
		$ball->vx = $tangentX * .96 + $reflected * $nx;
		$ball->vy = $tangentY * .96 + $reflected * $ny;
		$ball->spinY *= .90;

		$offsetX = $ball->x - $collision['x'];
		$offsetY = $ball->y - $collision['y'];
		$distance = hypot($offsetX, $offsetY);

		if($distance < Ball::RADIUS)
		{
			$ball->x = $collision['x'] + $nx * (Ball::RADIUS + 1e-8);
			$ball->y = $collision['y'] + $ny * (Ball::RADIUS + 1e-8);
		}

		$this->events[] = ['type' => 'cushion','strength' => min(1, -$normalSpeed / self::MAX_SPEED)];
	}

	/** Resolve a ball against one of the same tangent pocket facings used by the renderer. */
	private function resolveFacing(Ball $ball, array $collision): void
	{
		$nx = $collision['nx'];
		$ny = $collision['ny'];
		$normalSpeed = $ball->vx * $nx + $ball->vy * $ny;

		if($normalSpeed >= 0) return;

		$tangentX = $ball->vx - $normalSpeed * $nx;
		$tangentY = $ball->vy - $normalSpeed * $ny;
		$reflected = -$normalSpeed * self::CUSHION_RESTITUTION;
		$ball->vx = $tangentX * .96 + $reflected * $nx;
		$ball->vy = $tangentY * .96 + $reflected * $ny;
		$ball->spinY *= .90;
		$distance = ($ball->x - $collision['x']) * $nx + ($ball->y - $collision['y']) * $ny;

		if($distance < Ball::RADIUS)
		{
			$correction = Ball::RADIUS - $distance + 1e-8;
			$ball->x += $nx * $correction;
			$ball->y += $ny * $correction;
		}

		$this->events[] = ['type' => 'cushion','strength' => min(1, -$normalSpeed / self::MAX_SPEED)];
	}

	/** Resolve the closed rear half of a pocket liner after a ball misses its drop clearance. */
	private function resolvePocketBackstop(Ball $ball, array $collision): void
	{
		$nx = $collision['nx'];
		$ny = $collision['ny'];
		$normalSpeed = $ball->vx * $nx + $ball->vy * $ny;

		if($normalSpeed < 0)
		{
			$tangentX = $ball->vx - $normalSpeed * $nx;
			$tangentY = $ball->vy - $normalSpeed * $ny;
			$reflected = -$normalSpeed * self::CUSHION_RESTITUTION;
			$ball->vx = $tangentX * .96 + $reflected * $nx;
			$ball->vy = $tangentY * .96 + $reflected * $ny;
			$ball->spinY *= .90;
		}

		$distance = ($ball->x - $collision['x']) * -$nx + ($ball->y - $collision['y']) * -$ny;

		if($distance > 0)
		{
			$ball->x += $nx * ($distance + 1e-8);
			$ball->y += $ny * ($distance + 1e-8);
		}

		$this->events[] = ['type' => 'cushion','strength' => min(1, max(0, -$normalSpeed) / self::MAX_SPEED)];
	}

	private function applyCloth(Ball $ball, float $seconds): void
	{
		// A ball already at rest always leaves this pass with all five rates reset to zero; skip the friction math.
		if($ball->vx == 0.0 && $ball->vy == 0.0 && $ball->spinX == 0.0 && $ball->spinY == 0.0 && $ball->spinZ == 0.0)
		{
			$ball->vx = $ball->vy = $ball->spinX = $ball->spinY = $ball->spinZ = 0;
			return;
		}

		$r = Ball::RADIUS;
		$slipX = $ball->vx + $r * $ball->spinZ;
		$slipY = $ball->vy - $r * $ball->spinX;
		$slip = hypot($slipX, $slipY);

		if($slip > .012)
		{
			// Each unit of velocity change moves the slip by 3.5: once for the centre and 2.5 for a solid ball's spin.
			// Kinetic friction stops at pure rolling; overshooting it flipped a small slip back and forth for good, and
			// a ball caught in that never slowed or ended the shot.
			$change = min($slip / 3.5, self::CLOTH_FRICTION * self::GRAVITY * $seconds);
			$dvx = -$slipX / $slip * $change;
			$dvy = -$slipY / $slip * $change;
			$ball->vx += $dvx;
			$ball->vy += $dvy;
			$ball->spinX -= 2.5 * $dvy / $r;
			$ball->spinZ += 2.5 * $dvx / $r;
		}
		else
		{
			$speed = hypot($ball->vx, $ball->vy);

			if($speed > 0)
			{
				$next = max(0, $speed - self::ROLLING_RESISTANCE * self::GRAVITY * $seconds);
				$scale = $next / $speed;
				$ball->vx *= $scale;
				$ball->vy *= $scale;
			}

			$ball->spinX = $ball->vy / $r;
			$ball->spinZ = -$ball->vx / $r;
		}

		$ball->spinY *= exp(-.72 * $seconds);

		if(hypot($ball->vx, $ball->vy) < .006 && abs($ball->spinY) < .18)
		$ball->vx = $ball->vy = $ball->spinX = $ball->spinY = $ball->spinZ = 0;
	}

	public function moving(): bool
	{
		foreach($this->balls as $ball)
		if($ball->active && ($ball->pocket !== null || hypot($ball->vx, $ball->vy) >= .006
			|| abs($ball->spinY) >= .18)) return true;

		return ContactModel::elasticEnergy($this->contactBalls()) > ContactModel::SLEEP_ENERGY;
	}

	/**
	 * Check scratch placement without mutating the current game.
	 *
	 * A candidate must remain inside the playable rail limits, outside every pocket opening, and separated from all
	 * active object balls by the physical ball diameter plus placement tolerance.
	 *
	 * @param float $x Candidate cue-ball X coordinate.
	 * @param float $y Candidate cue-ball Y coordinate.
	 */
	public function canPlaceCue(float $x, float $y): bool
	{
		return $this->phase === 'placing' && $this->placementProblem($x, $y) === null;
	}

	/**
	 * Explain why a cue-ball placement is illegal, independent of the current phase.
	 *
	 * @return array{reason:string,ball?:int}|null `cloth`, `pocket`, or `ball` with the blocking ball number.
	 */
	public function placementProblem(float $x, float $y): ?array
	{
		if(!is_finite($x) || !is_finite($y)) return ['reason' => 'cloth'];

		[$limitX,$limitY] = self::placementLimits();

		if(abs($x) > $limitX || abs($y) > $limitY) return ['reason' => 'cloth'];
		if(Table::pocketed($x, $y)) return ['reason' => 'pocket'];

		foreach($this->balls as $ball)
		if($ball->number !== 0 && $ball->active && hypot($x - $ball->x, $y - $ball->y) < Ball::RADIUS * 2.05)
		return ['reason' => 'ball','ball' => $ball->number];

		return null;
	}

	/** @return array{float,float} Largest legal absolute cue-ball X and Y offsets from the table centre. */
	public static function placementLimits(): array
	{
		return [Table::WIDTH / 2 - Ball::RADIUS * 1.2, Table::HEIGHT / 2 - Ball::RADIUS * 1.2];
	}

	/**
	 * Return the nearest object ball which will receive an effectively immediate cue contact.
	 *
	 * @param float $angle Cue direction in cloth-plane radians.
	 * @param float $threshold Maximum forward clearance treated as nearly frozen.
	 */
	public function contactWarning(float $angle, float $threshold = .0012): ?int
	{
		if($this->phase !== 'aim' || !is_finite($angle) || !is_finite($threshold) || $threshold < 0)
		return null;

		$cue = $this->cue();
		$directionX = cos($angle);
		$directionY = sin($angle);
		$nearest = null;
		$nearestGap = INF;

		foreach($this->balls as $ball) if($ball->number !== 0 && $ball->active)
		{
			$offsetX = $ball->x - $cue->x;
			$offsetY = $ball->y - $cue->y;
			$distance = hypot($offsetX, $offsetY);

			if($distance < 1e-9) continue;

			$gap = $distance - Ball::RADIUS * 2;
			$alignment = ($offsetX * $directionX + $offsetY * $directionY) / $distance;

			if($gap <= $threshold && $alignment > .7 && $gap < $nearestGap)
			{
				$nearest = $ball->number;
				$nearestGap = $gap;
			}
		}

		return $nearest;
	}

	/**
	 * Place the cue ball after a scratch, rejecting rails, pockets, and overlaps.
	 *
	 * Successful placement resets all cue-ball motion and pocket state, clears the scratch, resumes aiming, and emits
	 * a placement event from the same rules used by the visual preview.
	 *
	 * @param float $x Requested cue-ball X coordinate.
	 * @param float $y Requested cue-ball Y coordinate.
	 */
	public function placeCue(float $x, float $y): bool
	{
		if(!$this->canPlaceCue($x, $y)) return false;

		$cue = $this->cue();
		$cue->x = $cue->previousX = $x;
		$cue->y = $cue->previousY = $y;
		$cue->vx = $cue->vy = $cue->spinX = $cue->spinY = $cue->spinZ = 0;
		$cue->pocket = null;
		$cue->drop = $cue->previousDrop = $cue->vDrop = 0;
		$cue->active = true;
		$this->scratched = false;
		$this->phase = 'aim';
		$this->events[] = ['type' => 'place'];

		return true;
	}

	public function resetShot(): bool
	{
		if(!$this->shotSnapshot) return false;

		$this->restore($this->shotSnapshot);
		$this->events[] = ['type' => 'reset'];

		return true;
	}

	/** @return list<array<string,mixed>> */
	public function consumeEvents(): array
	{
		$events = $this->events;
		$this->events = [];

		return $events;
	}

	/** @return array<string,mixed> */
	public function snapshot(): array
	{
		return [
			'balls' => array_map(static fn(Ball $ball): array => $ball->snapshot(), $this->balls)
			, 'phase' => $this->phase
			, 'scratched' => $this->scratched
			, 'shots' => $this->shots
			, 'rackSeed' => $this->rackSeed
			, 'rackIndex' => $this->rackIndex
		];
	}

	/**
	 * Restore the complete deterministic simulation checkpoint.
	 *
	 * Ball poses, phase, rack identity, and shot counters are replaced together, while transient events and the
	 * local free-play undo point are cleared so replay resumes from a clean action boundary.
	 *
	 * @param array<string,mixed> $snapshot Canonical simulation state previously returned by snapshot().
	 */
	public function restore(array $snapshot): void
	{
		$this->balls = array_map(static fn(array $ball): Ball => Ball::restore($ball), $snapshot['balls']);
		$this->phase = (string)$snapshot['phase'];
		$this->scratched = (bool)$snapshot['scratched'];
		$this->shots = (int)$snapshot['shots'];
		$this->rackSeed = isset($snapshot['rackSeed']) ? (string)$snapshot['rackSeed'] : null;
		$this->rackIndex = (int)($snapshot['rackIndex'] ?? 0);
		$this->events = [];
		$this->impactContacts = [];
		$this->shotSnapshot = null;
	}

	/**
	 * Compute a stable digest of the exact versioned binary simulation checkpoint.
	 *
	 * @return string Lowercase SHA-256 digest.
	 */
	public function exactHash(): string { return BinaryState::hash($this->snapshot()); }

	/** Stable digest used by deterministic replay tests. */
	public function hash(): string
	{
		$values = [$this->phase,$this->scratched,$this->shots];

		foreach($this->balls as $ball)
		array_push($values, $ball->number, $ball->active, round($ball->x, 9), round($ball->y, 9)
			, round($ball->vx, 9), round($ball->vy, 9), round($ball->spinX, 8)
			, round($ball->spinY, 8), round($ball->spinZ, 8), $ball->pocket, round($ball->drop, 9)
			, round($ball->vDrop, 9));

		return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
	}

	/**
	 * Return interpolated public simulation state for rendering and diagnostics.
	 *
	 * Phase, motion, physical table measurements, and all ball poses are emitted without exposing internal collision
	 * queues or changing any deterministic state.
	 *
	 * @param float $alpha Interpolation fraction for visible ball state.
	 * @return array<string,mixed>
	 */
	public function state(float $alpha = 1): array
	{
		return [
			'phase' => $this->phase
			, 'scratched' => $this->scratched
			, 'shots' => $this->shots
			, 'moving' => $this->moving()
			, 'table' => [Table::WIDTH,Table::HEIGHT,Ball::RADIUS,Table::CORNER_MOUTH,Table::SIDE_MOUTH]
			, 'balls' => array_map(static fn(Ball $ball): array => $ball->state($alpha), $this->balls)
		];
	}
}
