<?php
declare(strict_types=1);
namespace PhpBilliards\Physics;

/**
 * Stateless Hertz contacts for phenolic spheres.
 *
 * Positions, velocities and spin fully determine the force; there is no contact history to lose
 * when restoring a checkpoint midway through compression.
 *
 * The normal law is hertz/material with coeff_restitution damping; tangential damping is linear_nohistory
 * with multiplier one, limited by Coulomb friction. See https://docs.lammps.org/pair_granular.html.
 */
final class ContactModel
{
	public const YOUNG_MODULUS = 3.8e9;
	public const POISSON_RATIO = .375;
	public const RESTITUTION = .94;
	public const FRICTION = .06;
	public const MAX_STEP = 1 / 240000;
	public const SLEEP_ENERGY = 1e-9;
	public const GRAVITY = 9.80665;

	/** @return array{float,float} Hertz coefficient and restitution damping coefficient. */
	public static function coefficients(): array
	{
		static $coefficients = null;
		if($coefficients !== null) return $coefficients;
		$effectiveModulus = self::YOUNG_MODULUS / (2 * (1 - self::POISSON_RATIO ** 2));
		$hertz = 4 / 3 * $effectiveModulus * sqrt(Ball::RADIUS / 2);
		$log = log(self::RESTITUTION);
		$beta = $log / sqrt(M_PI ** 2 + $log ** 2);
		return $coefficients = [$hertz,-2 * sqrt(5 / 6) * $beta * sqrt(1.5 * $hertz * Ball::MASS / 2)];
	}

	/**
	 * @param float $compression Positive centre overlap in metres.
	 * @param float $approach Relative normal approach speed in metres per second.
	 * @return array{float,float} Repulsive normal force (N), and tangential damping (kg/s).
	 */
	public static function response(float $compression, float $approach): array
	{
		if($compression <= 0) return [0.0,0.0];
		[$hertz,$coefficient] = self::coefficients();
		$root = sqrt($compression);
		$damping = $coefficient * sqrt($root);
		return [max(0.0, $hertz * $compression * $root + $damping * $approach),$damping];
	}

	/**
	 * @param list<Ball> $balls Balls whose active pairs contribute stored contact energy in joules.
	 */
	public static function elasticEnergy(array $balls): float
	{
		[$hertz] = self::coefficients();
		$energy = 0.0;
		$count = count($balls);
		for($i = 0; $i < $count; $i++) if($balls[$i]->active)
		for($j = $i + 1; $j < $count; $j++) if($balls[$j]->active)
		{
			$a = $balls[$i]; $b = $balls[$j];
			$dx = $b->x - $a->x; $dy = $b->y - $a->y; $dz = $b->drop - $a->drop;
			if(abs($dx) >= 2 * Ball::RADIUS || abs($dy) >= 2 * Ball::RADIUS || abs($dz) >= 2 * Ball::RADIUS) continue;
			$compression = 2 * Ball::RADIUS - sqrt($dx * $dx + $dy * $dy + $dz * $dz);
			if($compression > 0) $energy += .4 * $hertz * $compression * $compression * sqrt($compression);
		}
		return $energy;
	}

	/**
	 * Integrate one contact microstep. The caller supplies active balls in number order and handles boundaries
	 * after this accepted step, never during an RK stage. On-cloth centres have no vertical degree of freedom.
	 * @param list<Ball> $balls Active balls in ascending number order.
	 * @param float $seconds Accepted contact microstep duration.
	 */
	public static function advance(array $balls, float $seconds): void
	{
		$state = $free = [];
		foreach($balls as $ball)
		{
			// World coordinates: X, up, Z. The table's y and drop are Z and minus up respectively.
			$state[] = [$ball->x,Ball::RADIUS - $ball->drop,$ball->y,
				$ball->vx,-$ball->vDrop,$ball->vy,$ball->spinX,$ball->spinY,$ball->spinZ];
			$free[] = $ball->pocket !== null;
		}
		$a = self::derivative($state, $free);
		$b = self::derivative(self::offset($state, $a, $seconds / 2), $free);
		$c = self::derivative(self::offset($state, $b, $seconds / 2), $free);
		$d = self::derivative(self::offset($state, $c, $seconds), $free);
		foreach($balls as $i => $ball)
		{
			$next = $state[$i];
			for($k = 0; $k < 9; $k++) $next[$k] += $seconds / 6 * ($a[$i][$k] + 2 * $b[$i][$k] + 2 * $c[$i][$k] + $d[$i][$k]);
			$ball->x = $next[0]; $ball->y = $next[2];
			$ball->vx = $next[3]; $ball->vy = $next[5];
			if($free[$i]) {$ball->drop = Ball::RADIUS - $next[1]; $ball->vDrop = -$next[4];}
			// Orientation is presentation state, integrated with the mean angular velocity of the accepted step.
			$ball->spinX = ($state[$i][6] + $next[6]) / 2;
			$ball->spinY = ($state[$i][7] + $next[7]) / 2;
			$ball->spinZ = ($state[$i][8] + $next[8]) / 2;
			$ball->advanceRotation($seconds);
			[$ball->spinX,$ball->spinY,$ball->spinZ] = [$next[6],$next[7],$next[8]];
		}
	}

	private static function offset(array $state, array $derivative, float $seconds): array
	{
		foreach($state as $i => &$row)
		for($k = 0; $k < 9; $k++) $row[$k] += $seconds * $derivative[$i][$k];
		return $state;
	}

	/** Pure simultaneous force evaluation: no ball or boundary state is mutated by an RK stage. */
	private static function derivative(array $state, array $free): array
	{
		$result = [];
		foreach($state as $i => $s)
		$result[] = [$s[3],$free[$i] ? $s[4] : 0.0,$s[5],0.0,$free[$i] ? -self::GRAVITY : 0.0,0.0,0.0,0.0,0.0];
		$count = count($state);
		$diameter = Ball::RADIUS * 2;
		$inertia = .4 * Ball::MASS * (Ball::RADIUS * Ball::RADIUS);
		for($i = 0; $i < $count; $i++)
		for($j = $i + 1; $j < $count; $j++)
		{
			$a = $state[$i]; $b = $state[$j];
			$x = $b[0] - $a[0]; $y = $b[1] - $a[1]; $z = $b[2] - $a[2];
			if(abs($x) >= $diameter || abs($y) >= $diameter || abs($z) >= $diameter) continue;
			$distance = sqrt($x * $x + $y * $y + $z * $z);
			$compression = $diameter - $distance;
			if($compression <= 0) continue;
			if($distance < 1e-15) { $nx = 1.0; $ny = $nz = 0.0; }
			else { $nx = $x / $distance; $ny = $y / $distance; $nz = $z / $distance; }
			$lever = $distance / 2;
			$wx = $a[6] + $b[6]; $wy = $a[7] + $b[7]; $wz = $a[8] + $b[8];
			$vx = $a[3] - $b[3] + $lever * ($wy * $nz - $wz * $ny);
			$vy = $a[4] - $b[4] + $lever * ($wz * $nx - $wx * $nz);
			$vz = $a[5] - $b[5] + $lever * ($wx * $ny - $wy * $nx);
			$approach = $vx * $nx + $vy * $ny + $vz * $nz;
			[$normal,$damping] = self::response($compression, $approach);
			$tx = $vx - $approach * $nx; $ty = $vy - $approach * $ny; $tz = $vz - $approach * $nz;
			$slip = sqrt($tx * $tx + $ty * $ty + $tz * $tz);
			$friction = $slip > 0 ? min($damping, self::FRICTION * $normal / $slip) : 0.0;
			$tx *= -$friction; $ty *= -$friction; $tz *= -$friction;
			$fx = (-$normal * $nx + $tx) / Ball::MASS;
			$fy = (-$normal * $ny + $ty) / Ball::MASS;
			$fz = (-$normal * $nz + $tz) / Ball::MASS;
			$result[$i][3] += $fx; $result[$j][3] -= $fx;
			if($free[$i]) $result[$i][4] += $fy;
			if($free[$j]) $result[$j][4] -= $fy;
			$result[$i][5] += $fz; $result[$j][5] -= $fz;
			$ax = $lever * ($ny * $tz - $nz * $ty) / $inertia;
			$ay = $lever * ($nz * $tx - $nx * $tz) / $inertia;
			$az = $lever * ($nx * $ty - $ny * $tx) / $inertia;
			$result[$i][6] += $ax; $result[$j][6] += $ax;
			$result[$i][7] += $ay; $result[$j][7] += $ay;
			$result[$i][8] += $az; $result[$j][8] += $az;
		}
		return $result;
	}
}
