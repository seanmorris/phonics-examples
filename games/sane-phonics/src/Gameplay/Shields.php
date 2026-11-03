<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use Phonics\Ecs\Query;
use Phonics\Ecs\Registry;
use SanePhonics\Effects\Rumble;
use Phonics\Input\Controls;
use SanePhonics\Physics\Body;
use Phonics\Render\Sprite;
use Phonics\Tiled\TileMap;

/** Shield grants, movement abilities, temporary star timing, aura sprites, and owned cleanup. */
final class Shields
{
	/** @var array<string,int> Optional aura GIDs keyed by canonical shield type. */
	private array $gids = [];
	/** @var array<int,int> Owned aura entity IDs keyed by player entity ID. */
	private array $auras = [];
	private bool $closed = false;

	/**
	 * Replace velocity components along a body's logical tangent and gravity axes.
	 *
	 * @param Body $body Body receiving reconstructed world velocity.
	 * @param float $tangent Signed logical-right velocity.
	 * @param float $gravity Signed velocity in the active gravity direction.
	 * @return void Updates world X and Y velocity without changing gravity orientation.
	 */
	private static function velocity(Body $body, float $tangent, float $gravity): void
	{
		$body->vx = $body->tangentX() * $tangent + $body->gravityX * $gravity;
		$body->vy = $body->tangentY() * $tangent + $body->gravityY * $gravity;
	}

	/**
	 * Bind shield state to a registry and discover optional annotated aura art.
	 *
	 * @param Registry $registry Registry containing players, shield components, and aura entities.
	 * @param TileMap|null $map Optional map whose tiles define shield-aura-* classes.
	 * @return void Retains no map resources and allocates no aura until a shield becomes active.
	 */
	public function __construct(private Registry $registry, ?TileMap $map = null)
	{
		if($map !== null)
		{
			$this->gids = $this->resolveGids($map);
		}
	}

	/**
	 * Add or replace a player's active shield and ensure its aura can be rendered.
	 *
	 * @param int $player Live player entity receiving the shield.
	 * @param string $type One of the Shield type constants.
	 * @param float $starDuration Positive star duration in seconds, ignored for other types.
	 * @return Shield Mutable inventory component attached to the player.
	 */
	public function grant(int $player, string $type, float $starDuration = 26): Shield
	{
		if($this->closed)
		{
			throw new \LogicException('Shield manager is closed');
		}

		if(!$this->registry->alive($player))
		{
			throw new \OutOfBoundsException('Shield recipient is not a live entity');
		}

		$shield = $this->registry->has($player, Shield::class)
			? $this->registry->get($player, Shield::class)
			: new Shield;

		if(!$this->registry->has($player, Shield::class))
		{
			$this->registry->add($player, $shield);
		}

		$shield->grant($type, $starDuration);
		$this->syncAura($player, $shield);

		return $shield;
	}

	/**
	 * Apply active shield controls before the platformer consumes this input snapshot.
	 *
	 * Electric shields allow three extra jump presses per airtime. Fire shields use buttons 4 and
	 * 5 for left and right aerial thrust during a quarter-second fuel budget. Water shields turn an
	 * airborne jump press into a downward bounce attack. Flight-capable profiles retain an
	 * unmodified jump for flight or glide. A vertical direction selects electric jump, while Down
	 * selects water bounce. Fire thrust remains additive to an active flight or glide.
	 *
	 * @param int $player Player entity owning Body and Shield components.
	 * @param Controls $input Device-neutral controls for the current fixed simulation step.
	 * @param float $dt Positive fixed simulation delta in seconds.
	 * @return bool True when a shield consumed the jump press and profile movement must ignore it.
	 */
	public function control(int $player, Controls $input, float $dt): bool
	{
		if($this->closed || !$this->registry->alive($player)
			|| !$this->registry->has($player, Body::class)
			|| !$this->registry->has($player, Shield::class)
		){
			return false;
		}

		if(!is_finite($dt) || $dt <= 0)
		{
			throw new \InvalidArgumentException('Shield controls require a positive finite delta');
		}

		$body = $this->registry->get($player, Body::class);
		$shield = $this->registry->get($player, Shield::class);

		if($body->grounded)
		{
			$shield->electricJumps = 3;
			$shield->firePower = .25;
		}

		if($body->grounded || in_array($body->ability, ['climb', 'riding'], true))
		{
			return false;
		}

		$canFly = in_array($body->profile, ['flyer', 'glider'], true);
		$flightActive = in_array($body->ability, ['fly', 'glide'], true);
		$vertical = $input->axis('up', 'down');

		if($shield->active === Shield::ELECTRIC && $input->pressed('jump'))
		{
			if($canFly && ($flightActive || abs($vertical) < .55))
			{
				return false;
			}

			if($body->jumping && $shield->electricJumps > 0)
			{
				self::velocity($body, $body->tangentVelocity(), -600);
				$body->rolling = false;
				$body->ability = 'electric-jump';
				$shield->electricJumps--;

				return true;
			}

			return $canFly;
		}

		if($shield->active === Shield::FIRE && $shield->firePower > 0)
		{
			$direction = $input->axis(4, 5);

			if($direction !== 0.0)
			{
				$tangent = $body->tangentVelocity() + $direction * 1800 * $dt;
				self::velocity($body, max(-900, min(900, $tangent)), $body->gravityVelocity());

				if(!$canFly)
				{
					$body->ability = 'fire-dash';
				}

				$shield->firePower = max(0, $shield->firePower - $dt);
			}
		}

		if($shield->active === Shield::WATER && $input->pressed('jump'))
		{
			if($canFly && $vertical < .55)
			{
				return false;
			}

			if($body->jumping && !$shield->bubbleArmed)
			{
				self::velocity($body, $body->tangentVelocity(), 840);
				$body->rolling = false;
				$body->ability = 'bubble-bounce';
				$shield->bubbleArmed = true;
			}

			return $body->jumping || $vertical >= .55;
		}

		return false;
	}

	/**
	 * Advance star timers, finish bubble rebounds, and synchronize every active aura.
	 *
	 * @param Registry $registry Bound registry supplied by the scheduler after movement and damage.
	 * @param float $dt Positive fixed simulation delta in seconds.
	 * @return void Retires stale aura entities and updates live aura positions and animation types.
	 */
	public function update(Registry $registry, float $dt): void
	{
		if($this->closed)
		{
			return;
		}

		if($registry !== $this->registry || !is_finite($dt) || $dt <= 0)
		{
			throw new \InvalidArgumentException('Shield update requires its registry and a positive delta');
		}

		$active = [];

		foreach($registry->query(Query::all(Body::class, Shield::class)) as $player)
		{
			$body = $registry->get($player, Body::class);
			$shield = $registry->get($player, Shield::class);
			$shield->update($dt);

			if($shield->bubbleArmed && $body->grounded)
			{
				$height = $body->rollingHeight;
				$body->y += ($body->halfHeight - $height) * cos($body->angle);
				$body->x -= ($body->halfHeight - $height) * sin($body->angle);
				$body->halfHeight = $height;
				$body->grounded = false;
				$body->rolling = false;
				$body->jumping = true;
				self::velocity($body, $body->tangentVelocity(), -840);
				$body->x -= $body->gravityX * .05;
				$body->y -= $body->gravityY * .05;
				$body->ability = 'bubble-rebound';
				$shield->bubbleArmed = false;

				if($registry->has($player, Rumble::class))
				{
					$registry->get($player, Rumble::class)->bubbleRebound();
				}
			}

			if($shield->active === '')
			{
				$this->retireAura($player);

				continue;
			}

			$this->syncAura($player, $shield);
			$aura = $this->auras[$player] ?? null;

			if($aura !== null && $registry->alive($aura))
			{
				$sprite = $registry->get($aura, Sprite::class);
				$sprite->x = $body->x;
				$sprite->y = $body->y;
				$active[$player] = $aura;
			}
		}

		foreach(array_keys($this->auras) as $player)
		{
			if(!isset($active[$player]))
			{
				$this->retireAura($player);
			}
		}
	}

	/**
	 * Count active shield types and live aura entities for scripts and diagnostics.
	 *
	 * @return array{active:int,auras:int,types:array<string,int>} Current registry shield totals.
	 */
	public function counts(): array
	{
		$types = [];
		$active = $auras = 0;

		foreach($this->registry->query(Shield::class) as $player)
		{
			$type = $this->registry->get($player, Shield::class)->active;

			if($type !== '')
			{
				$active++;
				$types[$type] = ($types[$type] ?? 0) + 1;
			}
		}

		foreach($this->auras as $id)
		{
			if($this->registry->has($id, ShieldAura::class))
			{
				$auras++;
			}
		}

		return ['active' => $active, 'auras' => $auras, 'types' => $types];
	}

	/**
	 * Create, replace, or remove the aura matching one player's active shield.
	 *
	 * @param int $player Live entity owning the shield.
	 * @param Shield $shield Current shield component for the player.
	 * @return void Uses no visual entity when the map has no matching annotation.
	 */
	private function syncAura(int $player, Shield $shield): void
	{
		$gid = $this->gids[$shield->active] ?? 0;

		if($gid < 1 || !$this->registry->has($player, Body::class))
		{
			$this->retireAura($player);

			return;
		}

		$id = $this->auras[$player] ?? null;

		if($id === null || !$this->registry->alive($id))
		{
			$body = $this->registry->get($player, Body::class);
			$sprite = new Sprite($gid, $body->x, $body->y, .5, .5);
			$sprite->scaleX = $sprite->scaleY = 1.25;
			$id = $this->registry->create(new ShieldAura($player), $sprite);
			$this->auras[$player] = $id;
		}
		else
		{
			$sprite = $this->registry->get($id, Sprite::class);

			if($sprite->gid !== $gid)
			{
				$sprite->gid = $gid;
				$sprite->time = 0;
			}
		}
	}

	/**
	 * Resolve optional aura tile classes from all map tilesets.
	 *
	 * @param TileMap $map Map whose atlas may contain shield-aura-* annotations.
	 * @return array<string,int> Canonical shield types mapped to global tile IDs.
	 */
	private function resolveGids(TileMap $map): array
	{
		$classes = [
			'shield-aura-normal' => Shield::NORMAL
			, 'shield-aura-electric' => Shield::ELECTRIC
			, 'shield-aura-fire' => Shield::FIRE
			, 'shield-aura-water' => Shield::WATER
			, 'shield-aura-star' => Shield::STAR
		];

		$result = [];

		foreach($map->tilesets as $set)
		{
			foreach($set['definitions'] as $local => $tile)
			{
				$class = $tile['class'] ?? $tile['type'] ?? '';

				if(isset($classes[$class]) && !isset($result[$classes[$class]]))
				{
					$result[$classes[$class]] = $set['firstgid'] + $local;
				}
			}
		}

		return $result;
	}

	/**
	 * Destroy one map-specific aura without changing its player's inventory.
	 *
	 * @param int $player Player entity used as the owned aura key.
	 * @return void Clears dead and live aura entries safely.
	 */
	private function retireAura(int $player): void
	{
		$id = $this->auras[$player] ?? null;

		if($id !== null && $this->registry->alive($id))
		{
			$this->registry->destroy($id);
		}

		unset($this->auras[$player]);
	}

	/**
	 * Retire all map-specific visuals while preserving player-owned shield inventory.
	 *
	 * @return void Makes repeated level cleanup safe.
	 */
	public function close(): void
	{
		if($this->closed)
		{
			return;
		}

		$this->closed = true;

		foreach(array_keys($this->auras) as $player)
		{
			$this->retireAura($player);
		}
	}
}
