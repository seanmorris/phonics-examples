<?php
declare(strict_types=1);
namespace PhpBilliards\Physics;

/** Deterministic grounded billiards simulation with swept contacts and spin-aware cloth friction. */
final class Simulation
{
	public const STEP = 1 / 60;
	public const SUBSTEPS = 4;
	public const MAX_SPEED = 4.25;
	private const BALL_RESTITUTION = .94;
	private const CUSHION_RESTITUTION = .78;
	private const CLOTH_FRICTION = .19;
	private const ROLLING_RESISTANCE = .013;
	private const GRAVITY = 9.80665;

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
		$spacing = Ball::RADIUS * 2.015;
		$apex = Table::WIDTH * .23;
		$numbers = [1,9,2,10,8,3,11,4,12,5,13,6,14,7,15];
		$index = 0;

		for($row = 0; $row < 5; $row++)
		for($column = 0; $column <= $row; $column++)
		{
			$x = $apex + $row * $spacing * .866025403784;
			$y = ($column - $row / 2) * $spacing;
			$this->balls[] = new Ball($numbers[$index++], $x, $y);
		}

		$this->phase = 'aim';
		$this->scratched = false;
		$this->shots = 0;
		$this->shotSnapshot = null;
		$this->events[] = ['type' => 'rack'];
	}

	public function cue(): Ball
	{
		return $this->balls[0];
	}

	/** Strike the cue ball with normalized power and top/back/side spin. */
	public function shoot(float $angle, float $power, float $top = 0, float $side = 0): bool
	{
		if($this->phase !== 'aim' || $this->moving() || !$this->cue()->active) return false;

		$power = max(.05, min(1, $power));
		$top = max(-1, min(1, $top));
		$side = max(-1, min(1, $side));
		$this->shotSnapshot = $this->snapshot();
		$speed = self::MAX_SPEED * (.16 + .84 * $power ** 1.35);
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

			foreach($this->balls as $ball) if($ball->active) $ball->advance($advance);
			$remaining -= $advance;

			if(!$collision) break;

			if($collision['type'] === 'ball') $this->resolveBalls($collision['a'], $collision['b']);
			else $this->resolveCushion($collision['ball'], $collision['axis']);

			if($advance < 1e-8) $remaining = max(0, $remaining - 1e-7);
		}

		if($remaining > 0)
		foreach($this->balls as $ball) if($ball->active) $ball->advance($remaining);

		foreach($this->balls as $ball) if($ball->active)
		{
			if(Table::pocketed($ball->x, $ball->y))
			{
				$ball->active = false;
				$ball->vx = $ball->vy = $ball->spinX = $ball->spinY = $ball->spinZ = 0;
				$this->events[] = ['type' => 'pocket','ball' => $ball->number];

				if($ball->number === 0) $this->scratched = true;

				continue;
			}

			$this->applyCloth($ball, $seconds);
		}
	}

	/** @return array<string,mixed>|null */
	private function earliest(float $limit): ?array
	{
		$best = null;
		$count = count($this->balls);

		for($i = 0; $i < $count; $i++)
		{
			$a = $this->balls[$i];

			if(!$a->active) continue;

			for($j = $i + 1; $j < $count; $j++)
			{
				$b = $this->balls[$j];

				if(!$b->active) continue;

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
		}

		return $best;
	}

	private function resolveBalls(Ball $a, Ball $b): void
	{
		$dx = $b->x - $a->x;
		$dy = $b->y - $a->y;
		$distance = hypot($dx, $dy);

		if($distance < 1e-9) [$nx,$ny,$distance] = [1.0,0.0,1e-9];
		else [$nx,$ny] = [$dx / $distance,$dy / $distance];

		$overlap = Ball::RADIUS * 2 - $distance;

		if($overlap > 0)
		{
			$a->x -= $nx * ($overlap / 2 + 1e-7);
			$a->y -= $ny * ($overlap / 2 + 1e-7);
			$b->x += $nx * ($overlap / 2 + 1e-7);
			$b->y += $ny * ($overlap / 2 + 1e-7);
		}

		$relative = ($b->vx - $a->vx) * $nx + ($b->vy - $a->vy) * $ny;

		if($relative >= 0) return;

		$impulse = -(1 + self::BALL_RESTITUTION) * $relative / 2;
		$a->vx -= $impulse * $nx;
		$a->vy -= $impulse * $ny;
		$b->vx += $impulse * $nx;
		$b->vy += $impulse * $ny;
		$tx = -$ny;
		$ty = $nx;
		$slip = ($b->vx - $a->vx) * $tx + ($b->vy - $a->vy) * $ty
			+ Ball::RADIUS * ($a->spinY + $b->spinY);
		$tangentImpulse = max(-abs($impulse) * .06, min(abs($impulse) * .06, -$slip / 7));
		$a->vx -= $tangentImpulse * $tx;
		$a->vy -= $tangentImpulse * $ty;
		$b->vx += $tangentImpulse * $tx;
		$b->vy += $tangentImpulse * $ty;
		$spinChange = -2.5 * $tangentImpulse / Ball::RADIUS;
		$a->spinY += $spinChange;
		$b->spinY += $spinChange;
		$this->events[] = ['type' => 'impact','strength' => min(1, abs($relative) / self::MAX_SPEED)];
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
		if($ball->active && (hypot($ball->vx, $ball->vy) >= .006 || abs($ball->spinY) >= .18)) return true;

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
			, round($ball->spinY, 8), round($ball->spinZ, 8));

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
			, 'table' => [Table::WIDTH,Table::HEIGHT,Ball::RADIUS]
			, 'balls' => array_map(static fn(Ball $ball): array => $ball->state($alpha), $this->balls)
		];
	}
}
