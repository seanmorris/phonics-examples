<?php
declare(strict_types=1);
namespace DoomPhonics\Effects;

use Phonics\Input\Gamepads;

/** DOOM-specific dual-motor envelopes layered on the engine's device-neutral haptic queue. */
final class Rumble extends \Phonics\Effects\Rumble
{
	private bool $playing = false;

	/** Queue a tactile signature for one local weapon attack. */
	public function weapon(string $name, bool $alternate = false): void
	{
		$name = strtolower($name);
		$gain = $alternate ? 1.15 : 1.0;

		if(str_contains($name, 'bfg'))
		{
			// The BFG winds up through the hands before its heavy discharge.
			$this->pulse(90, .15, .45);
			$this->pulse(120, .4, .6, 180);
			$this->pulse(160, 1, .8, 480);
		}
		elseif(str_contains($name, 'super') && str_contains($name, 'shotgun'))
		{
			$this->pulse(150, 1, .65);
			$this->pulse(65, .4, .25, 420);
			$this->pulse(75, .55, .2, 720);
		}
		elseif(str_contains($name, 'shotgun'))
		{
			$this->pulse(110, .78, .48);
			$this->pulse(60, .28, .36, 340);
		}
		elseif(str_contains($name, 'rocket') || str_contains($name, 'grenade')
			|| str_contains($name, 'bazooka')
		){
			$this->pulse(130, min(1, .9 * $gain), min(1, .42 * $gain));
		}
		elseif(str_contains($name, 'rail'))
		{
			$this->pulse(95, min(1, .25 * $gain), 1);
			$this->pulse(110, min(1, .6 * $gain), min(1, .35 * $gain), 60);
		}
		elseif(str_contains($name, 'plasma') || str_contains($name, 'laser'))
		$this->pulse(55, min(1, .12 * $gain), min(1, .9 * $gain));
		elseif(str_contains($name, 'chaingun') || str_contains($name, 'minigun')
			|| str_contains($name, 'machinegun')
		) $this->pulse(65, min(1, .32 * $gain), min(1, .8 * $gain));
		elseif(str_contains($name, 'saw'))
		$this->pulse(90, min(1, .38 * $gain), min(1, .85 * $gain));
		elseif(str_contains($name, 'fist') || str_contains($name, 'punch'))
		$this->pulse(70, min(1, .35 * $gain), min(1, .35 * $gain));
		elseif(str_contains($name, 'pistol'))
		$this->pulse(55, min(1, .18 * $gain), min(1, .72 * $gain));
		else $this->pulse(70, min(1, .4 * $gain), min(1, .6 * $gain));
	}

	/** Queue a damage impulse from the velocity actually imparted by damage thrust. */
	public function damage(float $knockback, bool $fatal = false): void
	{
		if(!is_finite($knockback) || $knockback < 0)
		throw new \InvalidArgumentException('Invalid damage knockback');

		// About 220 map units/second is already a forceful player shove. Keep tiny or
		// thrustless hazards perceptible without letting large explosions pin both motors.
		$weight = min(1, $knockback / 220);
		$this->pulse(
			65 + (int)round(115 * $weight)
			, .2 + .75 * $weight
			, .35 + .55 * $weight
		);

		if($fatal) $this->pulse(220, .45 + .35 * $weight, .2, 180);
		elseif($weight >= .7) $this->pulse(80, .25 + .3 * $weight, .15, 145);
	}

	/** Queue a restrained confirmation, with a second low-motor catch for a newly acquired weapon. */
	public function pickup(bool $weapon = false): void
	{
		$this->pulse(35, 0, $weapon ? .55 : .25);

		if($weapon) $this->pulse(75, .35, .2, 35);
	}

	/** Give the settings range a representative two-motor preview. */
	public function preview(): void
	{
		$this->reset();
		$this->pulse(55, .25, .7);
		$this->pulse(70, .55, .25, 70);
	}

	/** Advance the queue and send every ready pulse at the configured intensity. */
	public function dispatch(Gamepads $gamepads, float $elapsed, float $intensity): void
	{
		if(!is_finite($intensity) || $intensity < 0 || $intensity > 1)
		throw new \InvalidArgumentException('Rumble intensity must be between zero and one');

		if($intensity === 0.0)
		{
			$this->reset();

			if($this->playing) $gamepads->rumble(0, 0, 0);

			$this->playing = false;

			return;
		}

		foreach($this->update($elapsed) as $pulse)
		{
			$gamepads->rumble(
				$pulse['duration']
				, $pulse['strong'] * $intensity
				, $pulse['weak'] * $intensity
			);

			$this->playing = true;
		}
	}

	/** Drop delayed effects and stop a motor that may still be running. */
	public function stop(Gamepads $gamepads): void
	{
		$this->reset();

		if($this->playing) $gamepads->rumble(0, 0, 0);

		$this->playing = false;
	}
}
