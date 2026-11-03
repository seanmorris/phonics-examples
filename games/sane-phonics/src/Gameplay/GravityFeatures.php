<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use Phonics\Ecs\Registry;
use SanePhonics\Effects\Rumble;
use SanePhonics\Physics\Body;
use SanePhonics\Physics\Terrain;
use Phonics\Render\Sprite;
use Phonics\Tiled\Loader;
use Phonics\Tiled\TileMap;

/** Tiled gravity switches and oriented springs promoted to ECS components and terrain edges. */
final class GravityFeatures
{
	/** @var array<int,GravitySwitch> Switches keyed by Tiled object ID. */
	public array $switches = [];
	/** @var array<int,Spring> Springs keyed by Tiled object ID. */
	public array $springs = [];

	/** @var list<int> Registry entities owned by this manager. */
	private array $owned = [];
	/** @var array<string,float> Remaining spring lockout keyed by body entity and spring source. */
	private array $cooldowns = [];
	private bool $closed = false;

	/**
	 * Load gravity switches and springs from Tiled point objects.
	 *
	 * Object rotation defines the visible edge tangent: zero degrees faces up. `normalX` and
	 * `normalY` can override that direction. Gravity switches accept `gravity` values down, right,
	 * up, or left; springs accept positive `power`. Both accept `length`, `collisionLayer`, and
	 * `spriteGid` properties.
	 *
	 * @param Registry $registry Registry receiving feature and sprite components.
	 * @param Terrain $terrain Collision index receiving directed one-way contact edges.
	 * @param TileMap $map Map containing annotated GravitySwitch and Spring objects.
	 * @return void Creates script-addressable components keyed by their Tiled object IDs.
	 */
	public function __construct(
		private Registry $registry
		, private Terrain $terrain
		, TileMap $map
	){
		try
		{
			foreach($map->layers as $layer)
			{
				foreach($layer->objects as $object)
				{
					$class = strtolower((string)(($object['class'] ?? '') ?: ($object['type'] ?? '')));

					if(!in_array($class, ['gravityswitch', 'gravity-switch', 'spring'], true))
					{
						continue;
					}

					$properties = Loader::properties($object['properties'] ?? []);
					$x = (float)($object['x'] ?? 0) + $map->x + $layer->offsetX;
					$y = (float)($object['y'] ?? 0) + $map->y + $layer->offsetY;
					$rotation = deg2rad((float)($object['rotation'] ?? 0));
					$normalX = (float)($properties['normalX'] ?? sin($rotation));
					$normalY = (float)($properties['normalY'] ?? -cos($rotation));
					$length = array_key_exists('length', $properties)
						? (float)$properties['length']
						: max(32, (float)($object['width'] ?? 0));

					$layerId = (int)($properties['collisionLayer'] ?? 0);

					if($class === 'spring')
					{
						$this->spawnSpring(
							$object
							, $x
							, $y
							, $normalX
							, $normalY
							, $length
							, (float)($properties['power'] ?? 960)
							, $layerId
							, (int)($properties['spriteGid'] ?? 4)
						);
					}
					else
					{
						[$gravityX, $gravityY] = self::direction((string)($properties['gravity'] ?? 'down'));

						$this->spawnSwitch(
							$object
							, $x
							, $y
							, $normalX
							, $normalY
							, $length
							, (int)($properties['gravityX'] ?? $gravityX)
							, (int)($properties['gravityY'] ?? $gravityY)
							, $layerId
							, (int)($properties['spriteGid'] ?? 4)
						);
					}
				}
			}
		}
		catch(\Throwable $error)
		{
			$this->close();

			throw $error;
		}
	}

	/**
	 * Report whether the map supplied no gravity switches or springs.
	 *
	 * @return bool True when the manager has no simulation or rendering work.
	 */
	public function isEmpty(): bool
	{
		return !$this->switches && !$this->springs;
	}

	/**
	 * Activate one gravity switch from gameplay code or a supported-body contact.
	 *
	 * @param int $sourceId Tiled switch object ID present in the public switches map.
	 * @param Body $body Body receiving this switch's target gravity direction.
	 * @return bool True when the body changed direction; false when it already matched.
	 */
	public function activate(int $sourceId, Body $body): bool
	{
		if($this->closed)
		{
			throw new \LogicException('Gravity feature manager is closed');
		}

		$switch = $this->switches[$sourceId] ?? throw new \OutOfBoundsException('Unknown gravity switch');

		if(!$switch->activate($body))
		{
			return false;
		}

		$this->registry->get($switch->entity, Sprite::class)->tint = [1, 1, 1, 1];

		return true;
	}

	/**
	 * Launch one body from a spring by its stable Tiled object ID.
	 *
	 * @param int $sourceId Tiled spring object ID present in the public springs map.
	 * @param Body $body Body whose velocity and support state are replaced.
	 * @return void Applies the spring immediately, independent of contact detection.
	 */
	public function launch(int $sourceId, Body $body): void
	{
		if($this->closed)
		{
			throw new \LogicException('Gravity feature manager is closed');
		}

		$spring = $this->springs[$sourceId] ?? throw new \OutOfBoundsException('Unknown spring');
		$spring->launch($body);
		$this->registry->get($spring->entity, Sprite::class)->tint = [1, 1, 1, 1];
	}

	/**
	 * Advance activation timers and trigger supported bodies after movement collision resolution.
	 *
	 * @param Registry $registry Registry containing player bodies and owned feature sprites.
	 * @param float $dt Finite positive fixed simulation delta in seconds.
	 * @return void Changes gravity or launches each eligible body at most once per contact.
	 */
	public function update(Registry $registry, float $dt): void
	{
		if($this->closed)
		{
			return;
		}

		if(!is_finite($dt) || $dt <= 0)
		{
			throw new \InvalidArgumentException('Gravity feature delta must be positive');
		}

		foreach($this->cooldowns as $key => $remaining)
		{
			$remaining -= $dt;

			if($remaining > 0)
			{
				$this->cooldowns[$key] = $remaining;
			}
			else
			{
				unset($this->cooldowns[$key]);
			}
		}

		foreach($this->switches as $switch)
		{
			$this->fade($switch, $dt, self::switchTint($switch));
		}

		foreach($this->springs as $spring)
		{
			$this->fade($spring, $dt, [1, .25, .25, 1]);
		}

		foreach($registry->query(Body::class) as $bodyId)
		{
			$body = $registry->get($bodyId, Body::class);

			foreach($this->switches as $switch)
			{
				if($switch->pressedBy($body) && $this->activate($switch->sourceId, $body))
				{
					break;
				}
			}

			foreach($this->springs as $spring)
			{
				$key = $bodyId . ':' . $spring->sourceId;

				if(!isset($this->cooldowns[$key]) && $spring->pressedBy($body))
				{
					$this->launch($spring->sourceId, $body);
					$this->cooldowns[$key] = .12;
					$rumbleId = $bodyId;

					if(!$registry->has($rumbleId, Rumble::class) && $registry->has($bodyId, Vehicle::class))
					{
						$rumbleId = $registry->get($bodyId, Vehicle::class)->occupant ?? 0;
					}

					if($rumbleId > 0 && $registry->alive($rumbleId) && $registry->has($rumbleId, Rumble::class))
					{
						$registry->get($rumbleId, Rumble::class)->spring();
					}

					break;
				}
			}
		}
	}

	/**
	 * Return serialization-safe gravity switch and spring diagnostics.
	 *
	 * @return array<string,array<int,array<string,int|float|bool>>> Current feature state keyed by
	 * Tiled object ID.
	 */
	public function snapshot(): array
	{
		$switches = [];
		$springs = [];

		foreach($this->switches as $id => $switch)
		{
			$switches[$id] = [
				'active' => $switch->active
				, 'gravityX' => $switch->gravityX
				, 'gravityY' => $switch->gravityY
			];
		}

		foreach($this->springs as $id => $spring)
		{
			$springs[$id] = ['active' => $spring->active, 'power' => $spring->power];
		}

		return ['switches' => $switches, 'springs' => $springs];
	}

	/**
	 * Remove every owned contact edge and ECS entity.
	 *
	 * @return void Leaves map terrain and unrelated registry entries untouched.
	 */
	public function close(): void
	{
		if($this->closed)
		{
			return;
		}

		$this->closed = true;

		foreach([...$this->switches, ...$this->springs] as $feature)
		{
			if($feature->shape !== null)
			{
				$this->terrain->removeShape($feature->shape);
				$feature->shape = null;
			}
		}

		foreach($this->owned as $entity)
		{
			if($this->registry->alive($entity))
			{
				$this->registry->destroy($entity);
			}
		}

		$this->owned = [];
		$this->cooldowns = [];
	}

	/**
	 * Parse a named cardinal gravity direction.
	 *
	 * @param string $direction Case-insensitive down, right, up, or left name.
	 * @return array{int,int} Horizontal and vertical cardinal components.
	 */
	private static function direction(string $direction): array
	{
		return match(strtolower($direction))
		{
			'down' => [0, 1],
			'right' => [1, 0],
			'up' => [0, -1],
			'left' => [-1, 0],
			default => throw new \InvalidArgumentException('Unknown gravity direction')
		};
	}

	/**
	 * Create one gravity switch, its sprite, and its directed collision edge.
	 *
	 * @param array<string,mixed> $object Raw Tiled object containing the source ID.
	 * @param float $x Contact-edge center X coordinate.
	 * @param float $y Contact-edge center Y coordinate.
	 * @param float $normalX Authored outward horizontal normal.
	 * @param float $normalY Authored outward vertical normal.
	 * @param float $length Contact-edge length in pixels.
	 * @param int $gravityX Target horizontal gravity component.
	 * @param int $gravityY Target vertical gravity component.
	 * @param int $collisionLayer Terrain collision plane.
	 * @param int $gid Sprite tile GID from the containing map.
	 * @return void Adds the feature under its Tiled source ID.
	 */
	private function spawnSwitch(
		array $object
		, float $x
		, float $y
		, float $normalX
		, float $normalY
		, float $length
		, int $gravityX
		, int $gravityY
		, int $collisionLayer
		, int $gid
	): void {
		$id = (int)($object['id'] ?? 0);
		$switch = new GravitySwitch($id, $x, $y, $normalX, $normalY, $length, $gravityX, $gravityY, $collisionLayer);

		$switch->shape = $this->terrain->addShape($switch->points(), true, $collisionLayer, false, true);
		$sprite = $this->sprite($gid, $x, $y, $switch->normalX, $switch->normalY, $length);
		$sprite->tint = self::switchTint($switch);

		$switch->entity = $this->registry->create($switch, $sprite);
		$this->switches[$id] = $switch;
		$this->owned[] = $switch->entity;
	}

	/**
	 * Create one spring, its sprite, and its directed collision edge.
	 *
	 * @param array<string,mixed> $object Raw Tiled object containing the source ID.
	 * @param float $x Contact-edge center X coordinate.
	 * @param float $y Contact-edge center Y coordinate.
	 * @param float $normalX Authored launch horizontal normal.
	 * @param float $normalY Authored launch vertical normal.
	 * @param float $length Contact-edge length in pixels.
	 * @param float $power Launch speed in pixels per second.
	 * @param int $collisionLayer Terrain collision plane.
	 * @param int $gid Sprite tile GID from the containing map.
	 * @return void Adds the feature under its Tiled source ID.
	 */
	private function spawnSpring(
		array $object
		, float $x
		, float $y
		, float $normalX
		, float $normalY
		, float $length
		, float $power
		, int $collisionLayer
		, int $gid
	): void {
		$id = (int)($object['id'] ?? 0);
		$spring = new Spring($id, $x, $y, $normalX, $normalY, $length, $power, $collisionLayer);
		$spring->shape = $this->terrain->addShape($spring->points(), true, $collisionLayer, false, true);
		$sprite = $this->sprite($gid, $x, $y, $spring->normalX, $spring->normalY, $length);
		$sprite->tint = [1, .25, .25, 1];
		$spring->entity = $this->registry->create($spring, $sprite);
		$this->springs[$id] = $spring;
		$this->owned[] = $spring->entity;
	}

	/**
	 * Create a thin atlas-backed pad aligned to an authored contact normal.
	 *
	 * @param int $gid Sprite tile GID from the containing map.
	 * @param float $x World X coordinate at the pad center.
	 * @param float $y World Y coordinate at the pad center.
	 * @param float $normalX Horizontal outward unit normal.
	 * @param float $normalY Vertical outward unit normal.
	 * @param float $length Visible pad length in world pixels.
	 * @return Sprite Center-anchored sprite scaled and rotated along the contact edge.
	 */
	private function sprite(
		int $gid
		, float $x
		, float $y
		, float $normalX
		, float $normalY
		, float $length
	): Sprite {
		$sprite = new Sprite($gid, $x, $y, .5, .5);
		$sprite->rotation = atan2($normalX, -$normalY);
		$sprite->scaleX = $length / 32;
		$sprite->scaleY = .5;

		return $sprite;
	}

	/**
	 * Advance one feature's active flash and restore its sprite tint.
	 *
	 * @param GravitySwitch|Spring $feature Feature carrying an activation timer and entity ID.
	 * @param float $dt Fixed simulation delta in seconds.
	 * @param array{float,float,float,float} $base Inactive sprite color.
	 * @return void Clears expired activation and restores the supplied color.
	 */
	private function fade(GravitySwitch|Spring $feature, float $dt, array $base): void
	{
		if(!$feature->active)
		{
			return;
		}

		$feature->activeTime = max(0, $feature->activeTime - $dt);

		if($feature->activeTime === 0.0)
		{
			$feature->active = false;
			$this->registry->get($feature->entity, Sprite::class)->tint = $base;
		}
	}

	/**
	 * Select a persistent color that identifies a switch's target direction.
	 *
	 * @param GravitySwitch $switch Switch carrying a cardinal target direction.
	 * @return array{float,float,float,float} RGBA multiplier used by the sprite renderer.
	 */
	private static function switchTint(GravitySwitch $switch): array
	{
		return match([$switch->gravityX, $switch->gravityY])
		{
			[1, 0] => [.3, .75, 1, 1],
			[0, -1] => [.75, .45, 1, 1],
			[-1, 0] => [.3, 1, .65, 1],
			default => [1, .8, .25, 1]
		};
	}
}
