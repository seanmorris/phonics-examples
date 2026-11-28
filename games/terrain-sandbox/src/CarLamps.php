<?php
declare(strict_types=1);
namespace TerrainSandbox;

/**
 * The Testarossa's lamps over fixed steps: how brightly the brake, reversing and head lamps glow and how far the
 * pop-up headlights stand open, with the previous step kept for drawing between steps.
 *
 * A bulb takes BULB_SECONDS to light fully or go dark. The pods take POD_SECONDS to open or close, as long as the
 * model's own animation takes, and they open whenever the headlights are switched on. The tail lamps light with the
 * headlights.
 */
final class CarLamps
{
	public const BULB_SECONDS = .1, POD_SECONDS = 1.2;
	/** Every lamp dark and the pods closed. */
	private const DARK = ['brake' => 0.0, 'reverse' => 0.0, 'headlamps' => 0.0, 'pods' => 0.0];
	/** Levels at the latest step and the one before it. */
	private array $now = self::DARK, $before = self::DARK;

	public function resume(array $levels): void
	{
		// view() applies smoothstep to the pod position. Recover the linear actuator travel for the next step.
		$levels['pods']=.5-sin(asin(max(-1.,min(1.,1.-2.*$levels['pods'])))/3.);
		$this->before=$this->now=array_intersect_key($levels,self::DARK);
	}

	/** Put every lamp out and close the pods, as for a car just boarded. */
	public function reset(): void { $this->now = $this->before = self::DARK; }

	/**
	 * Move the lamps one fixed step toward what the switches ask for.
	 * @param float $dt Step in seconds.
	 * @param bool $braking Whether the brake lamps are switched on.
	 * @param bool $reversing Whether the reversing lamps are switched on.
	 * @param bool $headlights Whether the headlights are switched on.
	 * @return bool Whether any lamp or the pods changed.
	 */
	public function step(float $dt, bool $braking, bool $reversing, bool $headlights): bool
	{
		$this->before = $this->now;
		$bulb = $dt / self::BULB_SECONDS;
		foreach(['brake' => $braking, 'reverse' => $reversing, 'headlamps' => $headlights] as $lamp => $on)
			$this->now[$lamp] = max(0.0, min(1.0, $this->now[$lamp] + ($on ? $bulb : -$bulb)));
		$pod = $dt / self::POD_SECONDS;
		$this->now['pods'] = max(0.0, min(1.0, $this->now['pods'] + ($headlights ? $pod : -$pod)));
		return $this->now !== $this->before;
	}

	/**
	 * Light or put out the headlamps and open or close the pods at once, for a paused car whose switch changes. The
	 * brake and reversing lamps keep their levels.
	 * @param bool $headlights Whether the headlights are switched on.
	 * @return bool Whether the headlamps or the pods changed.
	 */
	public function snap(bool $headlights): bool
	{
		$level = $headlights ? 1.0 : 0.0;
		$changed = false;

		foreach(['headlamps', 'pods'] as $part)
		{
			$changed = $changed || $this->now[$part] !== $level || $this->before[$part] !== $level;
			$this->now[$part] = $this->before[$part] = $level;
		}

		return $changed;
	}

	/**
	 * Read the lamps between the last two steps.
	 * @param float $alpha Fraction of the way from the previous step to the latest, from 0 to 1.
	 * @return array Levels from 0 (dark) to 1 (full): 'brake', 'reverse' and 'headlamps', which the tail lamps also
	 * follow; and 'pods', how far the pods have turned open, from 0 (closed) to 1 (open). The pods speed up and slow
	 * down as the model's animation turns them.
	 */
	public function view(float $alpha): array
	{
		$view = [];
		foreach($this->now as $key => $level)
			$view[$key] = $this->before[$key] + $alpha * ($level - $this->before[$key]);
		$open = $view['pods'];
		$view['pods'] = $open * $open * (3 - 2 * $open);
		return $view;
	}
}
