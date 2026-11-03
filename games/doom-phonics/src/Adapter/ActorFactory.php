<?php
declare(strict_types=1);
namespace DoomPhonics\Adapter;

use Phonics\Ecs\Registry;
use DoomPhonics\Component\ActorState;
use DoomPhonics\Component\Body;
use DoomPhonics\Component\Gravity;
use DoomPhonics\Component\HitPoints;
use DoomPhonics\Component\InventoryPickup;
use DoomPhonics\Component\MapThing;
use DoomPhonics\Component\MonsterBrain;
use DoomPhonics\Component\NetworkPlayer;
use DoomPhonics\Component\PlayerCommand;
use DoomPhonics\Component\PlayerStart;
use DoomPhonics\Component\ProjectileBounce;
use DoomPhonics\Component\SpawnPoint;
use DoomPhonics\Component\StatusFace;
use DoomPhonics\Component\Transform;
use DoomPhonics\Component\Velocity;
use DoomPhonics\Component\ViewLighting;
use DoomPhonics\Component\ViewEffects;
use DoomPhonics\Definition\ActorCatalog;
use DoomPhonics\Definition\ActorDefinition;
use DoomPhonics\Game\PlayerState;
use DoomPhonics\Game\Level;
use DoomPhonics\Game\DoomMatch;
use DoomPhonics\Game\MultiplayerMode;
use DoomPhonics\Game\HexenMapSetup;
use DoomPhonics\Map\MapData;
use Phonics\Network\PlayerSlot;

/** Converts WAD THING records and DECORATE archetypes into composable ECS entities. */
final class ActorFactory
{

	public function __construct(public ActorCatalog $catalog) {}

	/** Spawn every eligible thing; unresolved editor numbers are returned for diagnostics. */
	public function populate(
		Registry $registry
		, MapData $map
		, int $skill = 2
		, bool $multiplayer = false
		, ?DoomMatch $match = null
		, ?int $playerStartType = null
		, ?Level $level = null
		, ?MultiplayerMode $mode = null
	): array {
		if($playerStartType !== null && $match)
		throw new \LogicException('A custom player start is only supported for local map inspection');

		$mode = $match?->mode ?? $mode ?? ($multiplayer ? MultiplayerMode::from(MultiplayerMode::COOPERATIVE) : null);
		$level ??= $map->format === 'HEXEN' ? new Level($map) : null;
		$entities = [];
		$missing = [];
		$starts = [];
		$customStart = null;

		foreach($map->things as $thing)
		{
			$type = (int)($thing['type'] ?? 0);

			if($type === 9045 || ($map->format === 'HEXEN' && HexenMapSetup::structuralThing($type))) continue;

			if($playerStartType === $type && $customStart === null) $customStart = $thing;

			if($playerStartType === $type) continue;

			$teamStart = in_array($type, [5080,5081,5082], true);

			// Spawn markers select occupied slots; they never create extra player actors.
			if($mode && ($teamStart || $type === 11 || ($type >= 1 && $type <= 4)))
			{
				$starts[] = $thing;
				continue;
			}

			if($teamStart) continue;

			if($playerStartType !== null && $type >= 1 && $type <= 4) continue;

			if(!$this->eligible($thing, $skill, $mode !== null)) continue;

			if($map->format === 'HEXEN')
			{
				if(empty($thing['flags'][$mode?->value() ?? 'single'])) continue;
			}

			$definition = $this->catalog->numbered((int)$thing['type']);

			if(!$definition)
			{
				$missing[(int)$thing['type']] = true;
				continue;
			}

			$entities[] = $this->spawn($registry, $definition, $thing, $this->spawnPlanes($map, $thing, $level));
		}

		if($playerStartType !== null)
		{
			if($customStart === null) throw new \UnexpectedValueException("Map has no start of type $playerStartType");

			$definition = $this->catalog->numbered(1);

			if(!$definition) $missing[1] = true;
			else
			$entities[] = $this->spawn(
				$registry
				, $definition
				, $customStart
				, $this->spawnPlanes($map, $customStart, $level)
			);
		}

		if($mode && $playerStartType === null)
		{
			$definition = $this->catalog->numbered(1);

			if(!$definition) $missing[1] = true;
			else
			foreach($match ? [new PlayerSlot(1),new PlayerSlot(2)] : [new PlayerSlot(1)] as $slot)
			{
				$thing = $this->playerStart($starts, $slot, $mode);

				$entity = $this->spawn(
					$registry
					, $definition
					, $thing + ['flags' => [],'index' => -1]
					, $this->spawnPlanes($map, $thing, $level)
				);

				$registry->add($entity, new PlayerStart($slot->value()));

				if($match)
				{
					$registry->add($entity, new NetworkPlayer($slot, $match->peer($slot), $match->local($slot)));
					$registry->add($entity, new PlayerCommand);
				}

				$entities[] = $entity;
			}
		}

		return ['entities' => $entities, 'missing' => array_keys($missing)];
	}

	/**
	 * List a map's player starts: co-op starts 1 to 4, deathmatch starts, and team starts.
	 *
	 * @param MapData $map Parsed map.
	 * @return list<array<string,mixed>> Start things in map order.
	 */
	public static function playerStarts(MapData $map): array
	{
		return array_values(array_filter($map->things, static function(array $thing): bool {
			$type = (int)($thing['type'] ?? 0);

			return ($type >= 1 && $type <= 4) || $type === 11 || in_array($type, [5080,5081,5082], true);
		}));
	}

	/**
	 * Sample the floor and ceiling under a thing, using the same sloped planes as movement and rendering.
	 *
	 * @param MapData $map Parsed map.
	 * @param array<string,mixed> $thing Thing with map-unit `x` and `y`.
	 * @param ?Level $level Built level, whose sectors carry slopes; null samples flat sector heights.
	 * @return array{floor: float|int, ceiling: float|int} Plane heights under the thing.
	 */
	public static function spawnPlanes(MapData $map, array $thing, ?Level $level): array
	{
		if($level)
		{
			$sector = $level->sectorAt($thing['x'], $thing['y']);

			return [
				'floor' => $sector->floorZ($thing['x'], $thing['y'])
				, 'ceiling' => $sector->ceilingZ($thing['x'], $thing['y'])
			];
		}

		return $map->sectors[$map->sectorAt((int)$thing['x'], (int)$thing['y'])] ?? ['floor' => 0, 'ceiling' => 128];
	}

	/** @param list<array<string,mixed>> $starts @return array<string,mixed> */
	private function playerStart(array $starts, PlayerSlot $slot, MultiplayerMode $mode): array
	{
		if($mode->cooperative())
		{
			foreach($starts as $thing)
			if((int)($thing['type'] ?? 0) === $slot->value()) return $thing;

			foreach($starts as $thing)
			if((int)($thing['type'] ?? 0) === 1) return $thing;
		}
		else
		{
			$deathmatch = array_values(
				array_filter($starts, static fn(array $thing): bool => (int)($thing['type'] ?? 0) === 11)
			);

			if($deathmatch) return $deathmatch[($slot->value() - 1) % count($deathmatch)];
		}

		// Team-only arenas such as D2ST9 have no ordinary starts. Alternate bases so
		// both peers choose distinct origins even when all blue markers precede red.
		$teams = [];

		foreach($starts as $thing)
		if(in_array((int)($thing['type'] ?? 0), [5080,5081,5082], true))
		$teams[(int)$thing['type']][] = $thing;

		if($teams)
		{
			ksort($teams);
			$teams = array_values($teams);
			$team = $teams[($slot->value() - 1) % count($teams)];

			return $team[intdiv($slot->value() - 1, count($teams)) % count($team)];
		}

		foreach($starts as $thing)
		if((int)($thing['type'] ?? 0) === 1) return $thing;

		throw new \UnexpectedValueException("Map has no $mode spawn point for player slot $slot");
	}

	/** @param array<string,mixed> $thing @param array<string,mixed> $sector */
	public function spawn(Registry $registry, ActorDefinition $definition, array $thing, array $sector): int
	{
		$height = (float)$definition->value('Height', 16);
		$z = (float)($thing['z'] ?? 0) + (float)$sector['floor'];

		if($definition->hasFlag('SPAWNCEILING')) $z = (float)$sector['ceiling'] - $height;

		$components = [
			new Transform((float)$thing['x'], (float)$thing['y'], $z, deg2rad((float)($thing['angle'] ?? 0)))
			, new SpawnPoint((float)$thing['x'], (float)$thing['y'], $z, deg2rad((float)($thing['angle'] ?? 0)))
			, new Velocity
			, new ActorState($definition)
			, new MapThing(
				(int)($thing['index'] ?? -1)
				, (int)($thing['type'] ?? $definition->editorNumber ?? 0)
				, $thing['flags'] ?? []
			)
		];

		$solid = $definition->hasFlag('SOLID');
		$shootable = $definition->hasFlag('SHOOTABLE');

		if($solid || $shootable || $definition->hasFlag('MISSILE'))
		{
			$components[] = new Body(
				(float)$definition->value('Radius', 20)
				, $height
				, $solid
				, $shootable
				, $definition->hasFlag('MISSILE')
			);

			if(!$definition->hasFlag('NOGRAVITY'))
			$components[] = new Gravity((float)$definition->value('Gravity', 1));
		}

		if($shootable)
		{
			$health = (int)$definition->value('Health', 1000);
			$components[] = new HitPoints($health, $health, (int)$definition->value('PainChance', 0));
		}

		if($definition->hasFlag('MONSTER'))
		$components[] = new MonsterBrain((bool)($thing['flags']['ambush'] ?? false));

		$bounceType = (string)$definition->value('BounceType', '');

		if($bounceType !== '' && strcasecmp($bounceType, 'None') !== 0)
		{
			$bounceCount = (int)$definition->value('BounceCount', -1);

			// The reference GRENADETRAIL thinker detonates on the update after five contacts.
			if($bounceCount < 0 && $definition->hasFlag('GRENADETRAIL')) $bounceCount = 5;

			$components[] = new ProjectileBounce(
				$bounceType
				, (float)$definition->value('BounceFactor', .7)
				, (float)$definition->value('WallBounceFactor', .75)
				, $bounceCount
			);
		}

		if($this->catalog->isA($definition, 'Inventory'))
		$components[] = new InventoryPickup($definition->name, (int)$definition->value('Inventory.Amount', 1));

		if(($definition->editorNumber ?? 0) >= 1 && ($definition->editorNumber ?? 0) <= 4)
		{
			$components[] = new PlayerStart((int)$definition->editorNumber);

			if($definition->editorNumber === 1)
			{
				$components[] = new PlayerState;
				$components[] = new StatusFace;
				$components[] = new ViewLighting;
				$components[] = new ViewEffects;
			}
		}

		$entity = $registry->create(...$components);
		$state = $registry->get($entity, ActorState::class);
		$frame = $state->current();

		if($frame && $frame->ticks > 1)
		{
			// Doom offsets initial actor tics. Keep map populations from executing every DECORATE
			// action on the same fixed update while retaining deterministic replays.
			$seed = ((int)($thing['index'] ?? $entity) + 1) * 1103
				+ (int)($thing['type'] ?? $definition->editorNumber ?? 0) * 97;

			$state->entered = true;
			$state->ticks = 1 + abs($seed % $frame->ticks);
		}

		return $entity;
	}

	/** @param array<string,mixed> $thing */
	private function eligible(array $thing, int $skill, bool $multiplayer): bool
	{
		$flags = $thing['flags'] ?? [];
		$type = (int)($thing['type'] ?? 0);

		if(!$multiplayer && ($type === 11 || ($type >= 2 && $type <= 4))) return false;

		if(!$multiplayer && !empty($flags['multiplayer'])) return false;

		$key = $skill <= 1 ? 'easy' : ($skill >= 3 ? 'hard' : 'medium');

		return !array_key_exists($key, $flags) || (bool)$flags[$key];
	}
}
