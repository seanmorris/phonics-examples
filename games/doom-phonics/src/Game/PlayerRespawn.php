<?php
declare(strict_types=1);

namespace DoomPhonics\Game;

use DoomPhonics\Adapter\ActorFactory;
use DoomPhonics\Component\ActorState;
use DoomPhonics\Component\Body;
use DoomPhonics\Component\ChargeAttack;
use DoomPhonics\Component\FloorClip;
use DoomPhonics\Component\Transform;
use DoomPhonics\Component\Velocity;
use DoomPhonics\Component\ViewEffects;
use Phonics\Ecs\Registry;
use Phonics\Network\PlayerSlot;

/**
 * Doom's netgame reborn for one player: a fresh loadout at a start chosen for the match's mode.
 *
 * Online, one death never restarts the level. The host rebuilds the dead player's health and inventory, which it
 * owns, and picks where they stand. Each peer then places its copy of that player, because the player's own peer
 * owns its pose.
 */
final class PlayerRespawn
{
	/** A start this close to a living player would put the respawn inside them. */
	public const CLEARANCE = 64.0;

	/**
	 * Return the state a player is reborn with: the starting loadout, keeping the level's tallies.
	 *
	 * @param PlayerState $dead State of the player who died.
	 * @return PlayerState Fresh state with the dead player's kills, items, and secrets.
	 */
	public static function reborn(PlayerState $dead): PlayerState
	{
		$player = new PlayerState;
		$player->kills = $dead->kills;
		$player->items = $dead->items;
		$player->secrets = $dead->secrets;

		return $player;
	}

	/**
	 * Choose where a player respawns.
	 *
	 * Co-op returns the player to their own start when it is clear. Deathmatch picks a random clear deathmatch start,
	 * or the one farthest from other players when none is clear. Team-only arenas use team starts.
	 *
	 * @param Level $level Active level, used to place the spot on its floor.
	 * @param MultiplayerMode $mode Match rules.
	 * @param PlayerSlot $slot Player being respawned.
	 * @param list<array{float,float}> $occupied Positions of other living players.
	 * @return array{x: float, y: float, z: float, angle: float} Spot in map units, with the angle in radians.
	 */
	public static function spot(Level $level, MultiplayerMode $mode, PlayerSlot $slot, array $occupied): array
	{
		$starts = ActorFactory::playerStarts($level->map);
		$type = static fn(array $thing): int => (int)($thing['type'] ?? 0);
		$of = static fn(callable $test): array => array_values(array_filter($starts, $test));
		$deathmatch = $of(static fn(array $thing): bool => $type($thing) === 11);
		$teams = $of(static fn(array $thing): bool => in_array($type($thing), [5080,5081,5082], true));

		$candidates = $mode->cooperative()
			? [
				...$of(static fn(array $thing): bool => $type($thing) === $slot->value())
				, ...$of(static fn(array $thing): bool => $type($thing) >= 1 && $type($thing) <= 4
					&& $type($thing) !== $slot->value())
				, ...$deathmatch
			]
			: ($deathmatch ?: ($teams ?: $starts));

		if(!$candidates) throw new \UnexpectedValueException("Map {$level->map->name} has no start to respawn at");

		$clear = array_values(array_filter(
			$candidates
			, static fn(array $thing): bool => self::distance($thing, $occupied) >= self::CLEARANCE
		));

		if($mode->cooperative()) $thing = $clear[0] ?? $candidates[0];
		elseif($clear) $thing = $clear[random_int(0, count($clear) - 1)];
		else
		{
			usort(
				$candidates
				, static fn(array $left, array $right): int => self::distance($right, $occupied) <=> self::distance(
					$left
					, $occupied
				)
			);

			$thing = $candidates[0];
		}

		$planes = ActorFactory::spawnPlanes($level->map, $thing, $level);

		return [
			'x' => (float)$thing['x']
			, 'y' => (float)$thing['y']
			, 'z' => (float)($thing['z'] ?? 0) + (float)$planes['floor']
			, 'angle' => deg2rad((float)($thing['angle'] ?? 0))
		];
	}

	/**
	 * Stand a player on a spot as a live, solid, shootable actor again.
	 *
	 * @param Registry $registry World holding the player.
	 * @param int $entity Player entity.
	 * @param array{x: float, y: float, z: float, angle: float} $spot Respawn spot from spot().
	 * @return void
	 */
	public static function place(Registry $registry, int $entity, array $spot): void
	{
		$pose = $registry->get($entity, Transform::class);

		// Move both endpoints so rendering does not sweep the player across the map.
		$pose->x = $pose->previousX = $spot['x'];
		$pose->y = $pose->previousY = $spot['y'];
		$pose->z = $pose->previousZ = $spot['z'];
		$pose->angle = $spot['angle'];

		if($registry->has($entity, Velocity::class))
		{
			$velocity = $registry->get($entity, Velocity::class);
			$velocity->x = $velocity->y = $velocity->z = 0;
		}

		if($registry->has($entity, FloorClip::class)) $registry->remove($entity, FloorClip::class);

		if($registry->has($entity, ChargeAttack::class)) $registry->remove($entity, ChargeAttack::class);

		if($registry->has($entity, ViewEffects::class)) $registry->get($entity, ViewEffects::class)->revive();

		if(!$registry->has($entity, ActorState::class)) return;

		$state = $registry->get($entity, ActorState::class);

		if($registry->has($entity, Body::class))
		{
			$body = $registry->get($entity, Body::class);
			$body->solid = $state->definition->hasFlag('SOLID');
			$body->shootable = $state->definition->hasFlag('SHOOTABLE');
			$body->grounded = true;
		}

		$state->enter('Spawn');
	}

	/** Distance from a start to the nearest occupied position, or infinity when nobody else is alive. */
	private static function distance(array $thing, array $occupied): float
	{
		$nearest = INF;

		foreach($occupied as [$x, $y]) $nearest = min($nearest, hypot((float)$thing['x'] - $x, (float)$thing['y'] - $y));

		return $nearest;
	}
}
