<?php
declare(strict_types=1);
namespace PhpBilliards\Physics;

/** Deterministic grounded billiards simulation with swept contacts and spin-aware cloth friction. */
final class Simulation
{
	public const STEP = 1 / 60;
	public const SUBSTEPS = 4;
	public const MAX_SPEED = 8.75;
	private const MIN_SHOT_SPEED = .5;
	private const POWER_EXPONENT = 2.55;
	private const BALL_RESTITUTION = .94;
	private const CUSHION_RESTITUTION = .78;
	private const CLOTH_FRICTION = .19;
	private const ROLLING_RESISTANCE = .013;
	private const GRAVITY = 9.80665;
	private const RACK_GAP = .00004;
	private const CONTACT_SLOP = .00008;
	private const CONTACT_ITERATIONS = 24;
	private const POCKET_DRAFT = .24007875908011603; // tan(13.5 degrees)
	private const POCKET_RESTITUTION = .52;
	private const POCKET_SINK_DEPTH = .115;

	/** @var list<Ball> */
	public array $balls = [];
	public string $phase = 'aim';
	public bool $scratched = false;
	public int $shots = 0;
	/** @var list<array<string,mixed>> */
	private array $events = [];
	/** @var array<string,mixed>|null */
	private ?array $shotSnapshot = null;

	public function __construct()
	{
		$this->rack();
	}

	/** Restore a standard fifteen-ball rack and cue ball. */
	public function rack(): void
	{
		$this->balls = [new Ball(0, -Table::WIDTH * .28, 0)];
		$spacing = Ball::RADIUS * 2 + self::RACK_GAP;
		$apex = Table::WIDTH * .23;
		$numbers = [1,9,2,10,8,3,11,4,12,5,13,6,14,7,15];
		$index = 0;

		for($row = 0; $row < 5; $row++)
		for($column = 0; $column <= $row; $column++)
		{
			$number = $numbers[$index++];
			$idealX = $apex + $row * $spacing * .866025403784;
			$idealY = ($column - $row / 2) * $spacing;
			$noiseX = self::rackNoise($number, 0);
			$noiseY = self::rackNoise($number, 1);
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

		foreach($this->balls as $ball) self::orientBall($ball);

		$this->phase = 'aim';
		$this->scratched = false;
		$this->shots = 0;
		$this->shotSnapshot = null;
		$this->events[] = ['type' => 'rack'];
	}

	/** Fixed sub-pixel variation prevents a perfectly symmetric break without changing replay. */
	private static function rackNoise(int $number, int $axis): float
	{
		$hash = (($number + 11) * 48271 + ($axis + 3) * 69621 + 0x35a7bd) % 2147483647;

		return (($hash % 2001) / 2000 - .5) * .00002;
	}

	/** Give every racked ball a stable independent orientation instead of a camera-visible uniform alignment. */
	private static function orientBall(Ball $ball): void
	{
		$sample = static function(int $salt) use ($ball): float {
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

	/** Strike the cue ball with normalized power and top/back/side spin. */
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

	/** Advance one display-rate tick through four 240 Hz integration slices. */
	public function step(float $seconds = self::STEP): void
	{
		if($seconds <= 0 || !is_finite($seconds)) return;

		foreach($this->balls as $ball) $ball->remember();
		$slice = $seconds / self::SUBSTEPS;

		for($i = 0; $i < self::SUBSTEPS; $i++) $this->substep($slice);

		if($this->phase === 'moving' && !$this->moving())
		$this->phase = $this->scratched ? 'placing' : 'aim';
	}

	private function substep(float $seconds): void
	{
		$remaining = $seconds;
		$events = 0;

		while($remaining > 1e-8 && $events++ < 32)
		{
			$collision = $this->earliest($remaining);
			$advance = $collision ? max(0, $collision['time']) : $remaining;

			$this->advanceActive($advance);
			$remaining -= $advance;

			if(!$collision) break;

			if($collision['type'] === 'ball') $this->resolveContactIsland($collision['a'], $collision['b']);
			elseif($collision['type'] === 'pocket') $this->enterPocket($collision['ball'], $collision['index']);
			elseif($collision['type'] === 'backstop') $this->resolvePocketBackstop($collision['ball'], $collision);
			elseif($collision['type'] === 'jaw') $this->resolveJaw($collision['ball'], $collision);
			elseif($collision['type'] === 'facing') $this->resolveFacing($collision['ball'], $collision);
			else $this->resolveCushion($collision['ball'], $collision['axis']);

			if($advance < 1e-8) $remaining = max(0, $remaining - 1e-7);
		}

		if($remaining > 0)
		$this->advanceActive($remaining);

		foreach($this->balls as $ball) if($ball->active && $ball->pocket === null)
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

		foreach($this->balls as $ball) if($ball->active)
		{
			$ball->advance($seconds);

			if($ball->pocket !== null) $this->advancePocket($ball, $seconds);
		}
	}

	/** Gravity and inelastic cone contacts decide whether a ball rattles out or sinks. */
	private function advancePocket(Ball $ball, float $seconds): void
	{
		$index = $ball->pocket;
		[$pocketX,$pocketY] = Table::pockets()[$index];
		$ball->vDrop += self::GRAVITY * $seconds;
		$ball->drop += $ball->vDrop * $seconds;
		$relativeX = $ball->x - $pocketX;
		$relativeY = $ball->y - $pocketY;
		$radial = hypot($relativeX, $relativeY);
		$clearance = Table::pocketRadius($index) - Ball::RADIUS;
		$allowed = max(.001, $clearance - self::POCKET_DRAFT * $ball->drop);

		if($radial > $allowed)
		{
			if($radial < 1e-9) [$radialX,$radialY] = [1.0,0.0];
			else [$radialX,$radialY] = [$relativeX / $radial,$relativeY / $radial];

			$normalLength = hypot(1, self::POCKET_DRAFT);
			$nx = $radialX / $normalLength;
			$ny = $radialY / $normalLength;
			$nz = self::POCKET_DRAFT / $normalLength;
			$normalSpeed = $ball->vx * $nx + $ball->vy * $ny + $ball->vDrop * $nz;
			$ball->x = $pocketX + $radialX * $allowed;
			$ball->y = $pocketY + $radialY * $allowed;

			if($normalSpeed > 0)
			{
				$impulse = (1 + self::POCKET_RESTITUTION) * $normalSpeed;
				$ball->vx -= $impulse * $nx;
				$ball->vy -= $impulse * $ny;
				$ball->vDrop -= $impulse * $nz;
				$ball->vx *= .985;
				$ball->vy *= .985;
				$ball->spinY *= .92;
				$this->events[] = ['type' => 'pocket-rattle','ball' => $ball->number,'pocket' => $index
					,'strength' => min(1, $normalSpeed / self::MAX_SPEED)];
			}
		}

		if($ball->drop < 0)
		{
			// Return the centre just behind both cushion planes. This prevents an
			// upward crossing from re-entering the liner in the same integration slice.
			$limitX = Table::WIDTH / 2 - Ball::RADIUS - 1e-5;
			$limitY = Table::HEIGHT / 2 - Ball::RADIUS - 1e-5;
			$ball->x = max(-$limitX, min($limitX, $ball->x));
			$ball->y = max(-$limitY, min($limitY, $ball->y));

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

	/** @return array<string,mixed>|null */
	private function earliest(float $limit): ?array
	{
		$best = null;
		$balls = array_values(array_filter($this->balls,
			static fn(Ball $ball): bool => $ball->active && $ball->pocket === null));
		usort($balls, static fn(Ball $left, Ball $right): int => $left->number <=> $right->number);
		$count = count($balls);

		for($i = 0; $i < $count; $i++)
		{
			$a = $balls[$i];

			for($j = $i + 1; $j < $count; $j++)
			{
				$b = $balls[$j];

				$rx = $b->x - $a->x;
				$ry = $b->y - $a->y;
				$vx = $b->vx - $a->vx;
				$vy = $b->vy - $a->vy;
				$radius = Ball::RADIUS * 2;
				$c = $rx * $rx + $ry * $ry - $radius * $radius;
				$bq = 2 * ($rx * $vx + $ry * $vy);
				$aq = $vx * $vx + $vy * $vy;

				if($c <= 0 && $bq < 0) $time = 0;
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

			// Pocket entry is a swept event, just like a rail or ball impact. Checking
			// only after a substep lets a fast ball cross the narrow clearance circle
			// and reach the back of the table, especially when a contact cluster
			// redirects it inside the mouth.
			foreach(Table::pockets() as $index => [$pocketX,$pocketY])
			{
				$relativeX = $a->x - $pocketX;
				$relativeY = $a->y - $pocketY;
				$speedSquared = $a->vx ** 2 + $a->vy ** 2;
				$radius = Table::pocketRadius($index) - Ball::RADIUS;
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

			// A ball whose trajectory misses the drop clearance can still enter the
			// visible throat. The far half of the round liner must return it through
			// the mouth instead of allowing it to continue behind the table.
			foreach(Table::pockets() as $index => [$pocketX,$pocketY])
			{
				[$outwardX,$outwardY] = Table::pocketOutward($index);
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
				$clearance = Table::pocketRadius($index) - Ball::RADIUS;

				if(abs($lateral) < $clearance - 1e-9
					|| abs($lateral) > Table::THROAT_RADIUS + Ball::RADIUS) continue;

				$best = ['type' => 'backstop','time' => max(0, $time),'ball' => $a,'index' => $index
					,'x' => $pocketX,'y' => $pocketY,'nx' => -$outwardX,'ny' => -$outwardY];
			}

			$halfX = Table::WIDTH / 2 - Ball::RADIUS;
			$halfY = Table::HEIGHT / 2 - Ball::RADIUS;

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
				$relativeX = $a->x - $jawX;
				$relativeY = $a->y - $jawY;
				$speedSquared = $a->vx ** 2 + $a->vy ** 2;
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

			foreach(Table::facings() as [$startX,$startY,$endX,$endY])
			{
				$segmentX = $endX - $startX;
				$segmentY = $endY - $startY;
				$segmentLength = hypot($segmentX, $segmentY);
				$tx = $segmentX / $segmentLength;
				$ty = $segmentY / $segmentLength;
				$lineX = -$ty;
				$lineY = $tx;
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

	/** Resolve every touching ball connected to the first impact as one stable contact island. */
	private function resolveContactIsland(Ball $seedA, Ball $seedB): void
	{
		$balls = array_values(array_filter($this->balls,
			static fn(Ball $ball): bool => $ball->active && $ball->pocket === null));
		usort($balls, static fn(Ball $left, Ball $right): int => $left->number <=> $right->number);
		$candidates = [];

		foreach($balls as $index => $a)
		foreach(array_slice($balls, $index + 1) as $b)
		{
			$dx = $b->x - $a->x;
			$dy = $b->y - $a->y;
			$distance = hypot($dx, $dy);

			if($distance > Ball::RADIUS * 2 + self::CONTACT_SLOP) continue;

			if($distance < 1e-9) [$nx,$ny] = [1.0,0.0];
			else [$nx,$ny] = [$dx / $distance,$dy / $distance];

			$candidates[] = ['a' => $a,'b' => $b,'nx' => $nx,'ny' => $ny];
		}

		// Collect the whole touching graph before applying any impulse. Solving one
		// wave at a time lets a later rack row push an already-resolved row backward.
		$reached = [spl_object_id($seedA) => true,spl_object_id($seedB) => true];
		$island = [];

		for($wave = 0; $wave < count($balls); $wave++)
		{
			$added = false;

			foreach($candidates as $index => $candidate)
			{
				if(isset($island[$index])) continue;
				$a = $candidate['a'];
				$b = $candidate['b'];
				if(!isset($reached[spl_object_id($a)]) && !isset($reached[spl_object_id($b)])) continue;

				$island[$index] = $candidate;
				$reached[spl_object_id($a)] = $reached[spl_object_id($b)] = true;
				$added = true;
			}

			if(!$added) break;
		}

		$contacts = [];

		foreach($island as $index => $candidate)
		{
			$a = $candidate['a'];
			$b = $candidate['b'];
			$relative = ($b->vx - $a->vx) * $candidate['nx'] + ($b->vy - $a->vy) * $candidate['ny'];
			$contacts[] = $candidate + ['index' => $index,'initial' => $relative
				, 'target' => $relative < -1e-7 ? -self::BALL_RESTITUTION * $relative : 0.0,'impulse' => 0.0];
		}

		for($iteration = 0; $iteration < self::CONTACT_ITERATIONS; $iteration++)
		foreach($contacts as &$contact)
		{
			$a = $contact['a'];
			$b = $contact['b'];
			$nx = $contact['nx'];
			$ny = $contact['ny'];
			$relative = ($b->vx - $a->vx) * $nx + ($b->vy - $a->vy) * $ny;
			$nextImpulse = max(0, $contact['impulse'] + ($contact['target'] - $relative) / 2);
			$impulse = $nextImpulse - $contact['impulse'];
			$contact['impulse'] = $nextImpulse;

			$a->vx -= $impulse * $nx;
			$a->vy -= $impulse * $ny;
			$b->vx += $impulse * $nx;
			$b->vy += $impulse * $ny;
		}
		unset($contact);

		$impactStrength = 0.0;

		foreach($contacts as $contact) if($contact['impulse'] > 0)
		{
			$a = $contact['a'];
			$b = $contact['b'];
			$nx = $contact['nx'];
			$ny = $contact['ny'];
			$tx = -$ny;
			$ty = $nx;
			$slip = ($b->vx - $a->vx) * $tx + ($b->vy - $a->vy) * $ty
				+ Ball::RADIUS * ($a->spinY + $b->spinY);
			$tangentImpulse = max(-$contact['impulse'] * .06, min($contact['impulse'] * .06, -$slip / 7));
			$a->vx -= $tangentImpulse * $tx;
			$a->vy -= $tangentImpulse * $ty;
			$b->vx += $tangentImpulse * $tx;
			$b->vy += $tangentImpulse * $ty;
			$spinChange = -2.5 * $tangentImpulse / Ball::RADIUS;
			$a->spinY += $spinChange;
			$b->spinY += $spinChange;
			$impactStrength = max($impactStrength, abs($contact['initial']) / self::MAX_SPEED
				, $contact['impulse'] / self::MAX_SPEED);
		}

		foreach($island as $contact)
		{
			$a = $contact['a'];
			$b = $contact['b'];
			$distance = hypot($b->x - $a->x, $b->y - $a->y);
			$overlap = Ball::RADIUS * 2 - $distance;

			if($overlap <= 0) continue;

			$a->x -= $contact['nx'] * ($overlap / 2 + 1e-8);
			$a->y -= $contact['ny'] * ($overlap / 2 + 1e-8);
			$b->x += $contact['nx'] * ($overlap / 2 + 1e-8);
			$b->y += $contact['ny'] * ($overlap / 2 + 1e-8);
		}

		if($impactStrength > 0)
		$this->events[] = ['type' => 'impact','strength' => min(1, $impactStrength)];
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
		$r = Ball::RADIUS;
		$slipX = $ball->vx + $r * $ball->spinZ;
		$slipY = $ball->vy - $r * $ball->spinX;
		$slip = hypot($slipX, $slipY);

		if($slip > .012)
		{
			$change = min($slip, self::CLOTH_FRICTION * self::GRAVITY * $seconds);
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

		return false;
	}

	/** Place the cue ball after a scratch, rejecting rails, pockets, and overlaps. */
	public function placeCue(float $x, float $y): bool
	{
		if($this->phase !== 'placing') return false;

		$limitX = Table::WIDTH / 2 - Ball::RADIUS * 1.2;
		$limitY = Table::HEIGHT / 2 - Ball::RADIUS * 1.2;

		if(abs($x) > $limitX || abs($y) > $limitY || Table::pocketed($x, $y)) return false;

		foreach($this->balls as $ball)
		if($ball->number !== 0 && $ball->active && hypot($x - $ball->x, $y - $ball->y) < Ball::RADIUS * 2.05)
		return false;

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

		$this->restoreSnapshot($this->shotSnapshot);
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
		];
	}

	/** @param array<string,mixed> $snapshot */
	private function restoreSnapshot(array $snapshot): void
	{
		$this->balls = array_map(static fn(array $ball): Ball => Ball::restore($ball), $snapshot['balls']);
		$this->phase = (string)$snapshot['phase'];
		$this->scratched = (bool)$snapshot['scratched'];
		$this->shots = (int)$snapshot['shots'];
	}

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

	/** @return array<string,mixed> */
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
