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
		$this->validateKnockback($knockback);

		// Even the maximum zombieman bullet stays below 70 map units/second. Keep that
		// whole range sharp while letting replicated high-knockback hits retain a blast tail.
		if($knockback >= 70)
		{
			$this->blast($knockback, $fatal);

			return;
		}

		$weight = min(1, $knockback / 70);
		$this->pulse(
			60 + (int)round(30 * $weight)
			, .58 + .32 * $weight
			, .76 + .22 * $weight
		);

		if($fatal) $this->pulse(220, .45 + .35 * $weight, .2, 100);
	}

	/** Queue the slower low-frequency envelope of an explosion or equivalent shove. */
	public function blast(float $knockback, bool $fatal = false): void
	{
		$this->validateKnockback($knockback);
		$weight = min(1, $knockback / 140);
		$this->pulse(
			130 + (int)round(80 * $weight)
			, .62 + .38 * $weight
			, .7 + .28 * $weight
		);

		if($fatal)
		{
			$this->pulse(260, .65 + .2 * $weight, .25, 210);
			$this->pulse(160, .25 + .15 * $weight, .1, 470);
		}
		else
		{
			$this->pulse(180, .35 + .5 * $weight, .25 + .1 * $weight, 210);
			$this->pulse(150, .15 + .3 * $weight, .08, 390);
		}
	}

	/** Queue the paired low-frequency beat of periodic environmental damage. */
	public function hazard(bool $fatal = false): void
	{
		$this->pulse(80, .72, .12);
		$this->pulse(105, .5, .08, 140);

		if($fatal) $this->pulse(240, .65, .16, 280);
	}

	/** Queue a bounded landing thud proportional to downward impact speed. */
	public function landing(float $verticalSpeed): void
	{
		if(!is_finite($verticalSpeed) || $verticalSpeed < 0)
		throw new \InvalidArgumentException('Invalid landing speed');

		$weight = min(1, $verticalSpeed / 560);
		$this->pulse(
			45 + (int)round(85 * $weight)
			, .2 + .75 * $weight
			, .15 + .45 * $weight
		);
	}

	private function validateKnockback(float $knockback): void
	{
		if(!is_finite($knockback) || $knockback < 0)
		throw new \InvalidArgumentException('Invalid damage knockback');
	}

	/** Queue a restrained confirmation, with a second low-motor catch for a newly acquired weapon. */
	public function pickup(bool $weapon = false): void
	{
		$this->pulse(35, 0, $weapon ? .65 : .35);

		if($weapon) $this->pulse(75, .42, .25, 35);
	}

	/** Represent the settings range literally with an even pulse scaled by the selected level. */
	public function preview(): void
	{
		$this->reset();
		$this->pulse(120, 1, 1);
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
