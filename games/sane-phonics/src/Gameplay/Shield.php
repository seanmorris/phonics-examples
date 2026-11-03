<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

/** Player shield inventory, active selection, elemental immunity, and star override state. */
final class Shield
{
	public const NORMAL = 'normal';
	public const ELECTRIC = 'electric';
	public const FIRE = 'fire';
	public const WATER = 'water';
	public const STAR = 'star';
	public const TYPES = [self::NORMAL, self::ELECTRIC, self::FIRE, self::WATER, self::STAR];
	public string $active = '';
	public float $remaining = 0;
	public int $electricJumps = 3;
	public float $firePower = .25;
	public bool $bubbleArmed = false;
	/** @var array<string,bool> Persistent, non-star shield types currently held by the player. */
	private array $inventory = [];
	private string $previous = '';

	/**
	 * Create an empty inventory or immediately grant one shield type.
	 *
	 * @param string $type Empty for no shield, or one of the five Shield type constants.
	 * @param float $starDuration Positive star duration in seconds when the initial type is star.
	 * @return void Initializes deterministic inventory and ability state.
	 */
	public function __construct(string $type = '', float $starDuration = 26)
	{
		if($type !== '')
		{
			$this->grant($type, $starDuration);
		}
	}

	/**
	 * Add and select a shield, preserving the current selection beneath a star shield.
	 *
	 * @param string $type One of normal, electric, fire, water, or star.
	 * @param float $starDuration Positive star duration in seconds, ignored for other types.
	 * @return void Refreshes star time or makes a non-star shield available and active.
	 */
	public function grant(string $type, float $starDuration = 26): void
	{
		self::validate($type);

		if(!is_finite($starDuration) || $starDuration <= 0)
		{
			throw new \InvalidArgumentException('Star shield duration must be finite and positive');
		}

		if($type === self::STAR)
		{
			if($this->active !== self::STAR)
			{
				$this->previous = $this->active;
			}

			$this->active = self::STAR;
			$this->remaining = $starDuration;

			return;
		}

		$this->inventory[$type] = true;

		if($this->active !== self::STAR)
		{
			$this->active = $type;
			$this->resetAbilityState();
		}
	}

	/**
	 * Select an acquired non-star shield while no star override is active.
	 *
	 * @param string $type Normal, electric, fire, or water.
	 * @return bool True when the requested shield was acquired and selected.
	 */
	public function select(string $type): bool
	{
		self::validate($type);

		if($type === self::STAR || $this->active === self::STAR || !isset($this->inventory[$type]))
		{
			return false;
		}

		$this->active = $type;
		$this->resetAbilityState();

		return true;
	}

	/**
	 * Report whether a persistent shield type is currently held.
	 *
	 * @param string $type Normal, electric, fire, or water.
	 * @return bool True for an acquired non-star shield; star is represented by active and time.
	 */
	public function has(string $type): bool
	{
		self::validate($type);

		return $type !== self::STAR && isset($this->inventory[$type]);
	}

	/**
	 * List persistent shields in canonical type order for menus and saved state.
	 *
	 * @return list<string> Acquired normal, electric, fire, and water types.
	 */
	public function inventory(): array
	{
		$result = [];

		foreach([self::NORMAL, self::ELECTRIC, self::FIRE, self::WATER] as $type)
		{
			if(isset($this->inventory[$type]))
			{
				$result[] = $type;
			}
		}

		return $result;
	}

	/**
	 * Advance a temporary star shield and restore its prior selection on expiry.
	 *
	 * @param float $dt Nonnegative simulation delta in seconds.
	 * @return void Leaves persistent shields unchanged outside a star override.
	 */
	public function update(float $dt): void
	{
		if(!is_finite($dt) || $dt < 0)
		{
			throw new \InvalidArgumentException('Shield delta must be finite and nonnegative');
		}

		if($this->active !== self::STAR)
		{
			return;
		}

		$this->remaining = max(0, $this->remaining - $dt);

		if($this->remaining <= 0)
		{
			$this->active = isset($this->inventory[$this->previous]) ? $this->previous : '';
			$this->previous = '';
			$this->resetAbilityState();
		}
	}

	/**
	 * Test whether the active shield completely ignores one damage category.
	 *
	 * @param string $damageType Nonempty application-defined category such as fire or projectile.
	 * @return bool True for star, matching elemental damage, or elemental projectile immunity.
	 */
	public function immune(string $damageType): bool
	{
		if($damageType === '')
		{
			throw new \InvalidArgumentException('Shield damage type cannot be empty');
		}

		if($this->active === self::STAR)
		{
			return true;
		}

		return in_array($this->active, [self::ELECTRIC, self::FIRE, self::WATER], true)
			&& ($damageType === $this->active || $damageType === 'projectile');
	}

	/**
	 * Consume the active non-star shield for a hit it does not ignore.
	 *
	 * @param string $damageType Nonempty application-defined category for the incoming hit.
	 * @return bool True when a shield absorbed the hit and was removed from inventory.
	 */
	public function absorb(string $damageType): bool
	{
		if($damageType === '')
		{
			throw new \InvalidArgumentException('Shield damage type cannot be empty');
		}

		if($this->active === '' || $this->immune($damageType))
		{
			return false;
		}

		unset($this->inventory[$this->active]);
		$this->active = '';
		$this->remaining = 0;
		$this->previous = '';
		$this->resetAbilityState();

		return true;
	}

	/**
	 * Determine whether any active shield prevents ring loss for this hit.
	 *
	 * @param string $damageType Nonempty application-defined category for the incoming hit.
	 * @return bool True when the hit is immune or can consume a non-star shield.
	 */
	public function protects(string $damageType): bool
	{
		if($damageType === '')
		{
			throw new \InvalidArgumentException('Shield damage type cannot be empty');
		}

		return $this->active !== '';
	}

	/**
	 * Reset movement ability budgets after acquiring, selecting, consuming, or restoring a shield.
	 *
	 * @return void Restores electric jumps and fire fuel and cancels a pending bubble rebound.
	 */
	private function resetAbilityState(): void
	{
		$this->electricJumps = 3;
		$this->firePower = .25;
		$this->bubbleArmed = false;
	}

	/**
	 * Validate one public shield type.
	 *
	 * @param string $type Candidate type supplied by code or Tiled properties.
	 * @return void Throws when the type is outside the supported shield family.
	 */
	private static function validate(string $type): void
	{
		if(!in_array($type, self::TYPES, true))
		{
			throw new \InvalidArgumentException("Unsupported shield type: $type");
		}
	}
}
