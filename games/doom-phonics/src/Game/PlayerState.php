<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

/** Persistent single-player inventory and episode statistics. */
final class PlayerState
{
	public const MESSAGE_TICS = 4 * 35;
	public const VIEW_HEIGHT = 41.0;
	public const DEATH_VIEW_HEIGHT = 6.0;
	public const DEATH_VIEW_SPEED = 35.0;
	public const DEATH_RESTART_DELAY = 1.0;
	/** @var array<string,int> */
	public array $ammo = ['Clip' => 50, 'Shell' => 0, 'RocketAmmo' => 0, 'Cell' => 0];
	/** @var array<string,int> */
	public array $ammoMaximum = ['Clip' => 200, 'Shell' => 50, 'RocketAmmo' => 50, 'Cell' => 300];
	/** @var array<string,bool> Ammo types granted without consumption by a cheat or game rule. */
	public array $infiniteAmmo = [];
	/** @var array<string,bool> */
	public array $weapons = ['Fist' => true, 'Pistol' => true];
	/** @var array<string,bool> */
	public array $keys = [];
	/** @var array<string,int> DECORATE counters which are neither ammo, weapons, nor keys. */
	public array $inventory = [];
	/** @var array<string,float> Active power name to remaining seconds. */
	public array $powers = [];
	public string $weapon = 'Pistol';
	public ?string $pendingWeapon = null;
	public ?int $weaponEntity = null;
	public int $armor = 0;
	public int $armorClass = 0;
	public int $kills = 0;
	public int $items = 0;
	public int $secrets = 0;
	public bool $godMode = false;
	public bool $noClip = false;
	public bool $exited = false;
	public bool $secretExit = false;
	public int $reactionTics = 0;
	public string $message = '';
	public int $messageTics = 0;
	public ?float $deathTime = null;
	public float $viewHeight = self::VIEW_HEIGHT;
	public float $previousViewHeight = self::VIEW_HEIGHT;

	/** Show a classic status message for four seconds unless a caller supplies another duration. */
	public function showMessage(string $message, int $tics = self::MESSAGE_TICS): void
	{
		$this->message = $message;
		$this->messageTics = $message === '' ? 0 : max(0, $tics);
	}

	/** Advance the status-message timeout on the shared 35 Hz gameplay clock. */
	public function advanceMessage(int $tics): void
	{
		if($this->message === '')
		{
			$this->messageTics = 0;

			return;
		}

		$this->messageTics = max(0, $this->messageTics - max(0, $tics));

		if($this->messageTics === 0) $this->message = '';
	}

	/** Queue a first-person lowering transition without changing the active weapon early. */
	public function requestWeapon(string $weapon): void
	{
		if($weapon === '') return;

		$this->pendingWeapon = strcasecmp($weapon, $this->weapon) === 0 ? null : $weapon;
	}

	/** Start the local death camera exactly once. */
	public function beginDeath(): void
	{
		if($this->deathTime !== null) return;

		$this->deathTime = 0;
		$this->previousViewHeight = $this->viewHeight = self::VIEW_HEIGHT;
	}

	/** Lower the dead player's view while preserving a render-interpolation endpoint. */
	public function advanceDeath(float $elapsed): void
	{
		$this->beginDeath();
		$this->previousViewHeight = $this->viewHeight;
		$this->deathTime += max(0, $elapsed);
		$this->viewHeight = max(self::DEATH_VIEW_HEIGHT, $this->viewHeight - self::DEATH_VIEW_SPEED * max(0, $elapsed));
	}

	public function viewHeightAt(float $alpha): float
	{
		$alpha = max(0, min(1, $alpha));

		return $this->previousViewHeight + ($this->viewHeight - $this->previousViewHeight) * $alpha;
	}

	public function canRestart(): bool
	{
		return $this->deathTime !== null && $this->deathTime >= self::DEATH_RESTART_DELAY;
	}
}
