<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

use DoomPhonics\Adapter\ActorFactory;
use DoomPhonics\Audio\NullSoundEmitter;
use DoomPhonics\Audio\SoundEmitter;
use DoomPhonics\Audio\SoundCue;
use DoomPhonics\Audio\SoundEvent;
use DoomPhonics\Audio\SoundListener;
use DoomPhonics\Audio\SoundSource;
use DoomPhonics\Component\PlayerStart;
use DoomPhonics\Component\Projectile;
use DoomPhonics\Component\SectorMover;
use DoomPhonics\Component\Transform;
use DoomPhonics\Component\Velocity;
use DoomPhonics\Definition\LineAction;
use DoomPhonics\Definition\LanguageCatalog;
use DoomPhonics\System\SectorMotionSystem;
use Phonics\Ecs\Query;
use Phonics\Ecs\Registry;

/** Data-driven dispatcher for supported Doom and Hexen linedef actions. */
final class LineSpecials
{
	/** Keep a freshly warped render endpoint beyond the portal renderer's near plane. */
	private const PORTAL_EXIT_INSET = .02;
	/** @var array<int,bool> */
	public array $consumed = [];
	/** @var array<int,float> Horizontal texture offsets for scrolling walls. */
	public array $scroll = [];
	/** @var array<int,float> Texture columns per Doom tic. */
	private array $scrollSpeed = [];
	/** @var (callable(bool):void)|null */
	public $onExit = null;

	/** @var array<int,array{trigger:string,repeat:bool,type:string,key?:string,mode?:string}> */
	public const ACTIONS = [
		1 => ['trigger' => 'use','repeat' => true,'type' => 'local-door','mode' => 'open-close']
		, 2 => ['trigger' => 'walk','repeat' => false,'type' => 'door','mode' => 'open']
		, 5 => ['trigger' => 'walk','repeat' => false,'type' => 'floor','mode' => 'raise-ceiling']
		, 7 => ['trigger' => 'use','repeat' => false,'type' => 'stairs']
		, 8 => ['trigger' => 'walk','repeat' => false,'type' => 'stairs']
		, 9 => ['trigger' => 'use','repeat' => false,'type' => 'donut']
		, 11 => ['trigger' => 'use','repeat' => false,'type' => 'exit']
		, 16 => ['trigger' => 'walk','repeat' => false,'type' => 'door','mode' => 'close-open']
		, 18 => ['trigger' => 'use','repeat' => false,'type' => 'floor','mode' => 'raise-next']
		, 20 => ['trigger' => 'use','repeat' => false,'type' => 'floor','mode' => 'raise-next-change']
		, 22 => ['trigger' => 'walk','repeat' => false,'type' => 'floor','mode' => 'raise-next-change']
		, 23 => ['trigger' => 'use','repeat' => false,'type' => 'floor','mode' => 'lower-lowest']
		, 26 => ['trigger' => 'use','repeat' => true,'type' => 'local-door','mode' => 'open-close','key' => 'blue']
		, 27 => ['trigger' => 'use','repeat' => true,'type' => 'local-door','mode' => 'open-close','key' => 'yellow']
		, 28 => ['trigger' => 'use','repeat' => true,'type' => 'local-door','mode' => 'open-close','key' => 'red']
		, 31 => ['trigger' => 'use','repeat' => false,'type' => 'local-door','mode' => 'open']
		, 32 => ['trigger' => 'use','repeat' => false,'type' => 'local-door','mode' => 'open','key' => 'blue']
		, 33 => ['trigger' => 'use','repeat' => false,'type' => 'local-door','mode' => 'open','key' => 'red']
		, 34 => ['trigger' => 'use','repeat' => false,'type' => 'local-door','mode' => 'open','key' => 'yellow']
		, 35 => ['trigger' => 'walk','repeat' => false,'type' => 'light','mode' => 'dark']
		, 36 => ['trigger' => 'walk','repeat' => false,'type' => 'floor','mode' => 'lower-highest-fast']
		, 46 => ['trigger' => 'shoot','repeat' => false,'type' => 'local-door','mode' => 'open']
		, 48 => ['trigger' => 'automatic','repeat' => true,'type' => 'scroll']
		, 51 => ['trigger' => 'use','repeat' => false,'type' => 'secret-exit']
		, 62 => ['trigger' => 'use','repeat' => true,'type' => 'lift']
		, 63 => ['trigger' => 'use','repeat' => true,'type' => 'door','mode' => 'open-close']
		, 70 => ['trigger' => 'use','repeat' => true,'type' => 'floor','mode' => 'lower-highest-fast']
		, 76 => ['trigger' => 'walk','repeat' => true,'type' => 'door','mode' => 'close-open']
		, 82 => ['trigger' => 'walk','repeat' => true,'type' => 'floor','mode' => 'lower-lowest']
		, 86 => ['trigger' => 'walk','repeat' => true,'type' => 'door','mode' => 'open']
		, 88 => ['trigger' => 'walk','repeat' => true,'type' => 'lift']
		, 90 => ['trigger' => 'walk','repeat' => true,'type' => 'door','mode' => 'open-close']
		, 91 => ['trigger' => 'walk','repeat' => true,'type' => 'floor','mode' => 'raise-ceiling']
		, 97 => ['trigger' => 'walk','repeat' => true,'type' => 'teleport']
		, 98 => ['trigger' => 'walk','repeat' => true,'type' => 'floor','mode' => 'lower-highest-fast']
		, 102 => ['trigger' => 'use','repeat' => false,'type' => 'floor','mode' => 'lower-highest']
		, 103 => ['trigger' => 'use','repeat' => false,'type' => 'door','mode' => 'open']
	];

	private Registry $registry;
	private SectorMotionSystem $motion;
	private SoundEmitter $sound;
	private ?ActorFactory $factory;
	private ?\Closure $own;
	private LanguageCatalog $language;
	private float $buttonTics = 0;
	/** @var array<int,LineAction> */
	private array $actions = [];
	/** @var list<array{side:int,slot:string,texture:string,tics:int,line:int}> */
	private array $buttons = [];

	public function __construct(
		public Level $level
		, ?Registry $registry = null
		, ?callable $own = null
		, ?SoundEmitter $sound = null
		, ?ActorFactory $factory = null
		, ?LanguageCatalog $language = null
	){
		$this->registry = $registry ?? new Registry;
		$this->sound = $sound ?? new NullSoundEmitter;
		$this->motion = new SectorMotionSystem($level, $this->sound);
		$this->factory = $factory;
		$this->own = $own ? \Closure::fromCallable($own) : null;
		$this->language = $language ?? LanguageCatalog::documents([]);

		foreach(self::ACTIONS as $number => $definition)
		$this->actions[$number] = LineAction::fromArray($number, $definition);

		foreach($level->map->linedefs as $line)
		{
			$hexen = $level->map->format === 'HEXEN';

			if(!$hexen && $line['action'] === 48)
			$this->scrollSpeed[$line['index']] = 1;
			elseif($hexen && in_array($line['action'], [100,101], true))
			$this->scrollSpeed[$line['index']] = ($line['action'] === 100 ? 1 : -1) * $line['args'][0] / 64;
		}

		foreach($this->scrollSpeed as $id => $speed) $this->scroll[$id] = 0;
	}

	public function update(float $dt): void
	{
		$this->level->beginStep();
		// Compatibility movers may still be created by old game modules.
		$this->level->update($dt);
		$this->motion->update($this->registry, $dt);

		foreach($this->scroll as $line => $offset)
		{
			$this->scroll[$line] = $offset + $this->scrollSpeed[$line] * 35 * $dt;
			$this->level->setWallScroll($line, $this->scroll[$line]);
		}

		$this->buttonTics += max(0, $dt) * 35;
		$tics = (int)floor($this->buttonTics + 1e-9);

		if($tics < 1) return;

		$this->buttonTics -= $tics;

		foreach($this->buttons as $index => &$button)
		{
			$button['tics'] -= $tics;

			if($button['tics'] > 0) continue;

			$this->level->setSideTexture($button['side'], $button['slot'], $button['texture']);
			$this->playAtLine(SoundCue::switch(), $button['line']);
			unset($this->buttons[$index]);
		}

		unset($button);
		$this->buttons = array_values($this->buttons);
	}

	/** Activate the first usable line within Doom's 64-unit use range. */
	public function use(Transform $actor, PlayerState $player): bool
	{
		$sector = $this->level->sectorAt($actor->x, $actor->y)->index;

		foreach($this->level->spatial->cast($actor->x, $actor->y, cos($actor->angle), sin($actor->angle), 64) as $hit)
		{
			$lineId = $hit['line'];
			$line = $this->level->map->linedefs[$lineId];

			if($line['action'])
			{
				if($this->level->map->format === 'HEXEN'
					&& !in_array(($line['flags'] >> 10) & 7, [1,6], true)
				) continue;

				// The use ray can cross an open sector border before reaching the switch.
				// Its front side is geometric, not the sector the player is standing in.
				$a = $this->level->map->vertices[$line['from']];
				$b = $this->level->map->vertices[$line['to']];

				if(self::orient($a['x'], $a['y'], $b['x'], $b['y'], $actor->x, $actor->y) > 0) return false;

				return $this->activate($lineId, 'use', $sector, $player, $actor);
			}

			$portal = $this->level->portal($lineId);

			if(!$portal || $portal->ceiling($hit['x'], $hit['y']) <= $portal->floor($hit['x'], $hit['y'])) break;
		}

		return false;
	}

	/** Activate every crossed walk-trigger line in travel order. */
	public function cross(float $fromX, float $fromY, Transform $actor, PlayerState $player): int
	{
		$hits = [];

		foreach($this->level->spatial->near(
			min($fromX, $actor->x)
			, min($fromY, $actor->y)
			, max($fromX, $actor->x)
			, max($fromY, $actor->y)
		) as $lineId
		){
			$line = $this->level->map->linedefs[$lineId];

			if((!$line['action'] && !$this->level->cameraPortalForLine($lineId))
				|| !self::segmentsCross($fromX, $fromY, $actor->x, $actor->y, $line, $this->level)
			)
			continue;

			$hits[$lineId] = self::intersectionFraction($fromX, $fromY, $actor->x, $actor->y, $line, $this->level);
		}

		asort($hits);
		$count = 0;
		$sector = $this->level->sectorAt($fromX, $fromY)->index;

		foreach(array_keys($hits) as $lineId)
		{
			$line = $this->level->map->linedefs[$lineId];
			$a = $this->level->map->vertices[$line['from']];
			$b = $this->level->map->vertices[$line['to']];
			$frontCrossing = self::orient($a['x'], $a['y'], $b['x'], $b['y'], $fromX, $fromY) <= 0;
			$portal = $this->level->cameraPortalForLine($lineId)?->link;

			if($portal && $portal->passable() && $frontCrossing)
			{
				$this->crossCameraPortal($portal, $actor, $fromX, $fromY, $hits[$lineId]);
				$count++;
				// Remaining fractions belong to the coordinate space before the portal.
				break;
			}

			if($this->activate($lineId, 'walk', $sector, $player, $actor, $frontCrossing)) $count++;
		}

		return $count;
	}

	public function shoot(int $lineId, int $sector, PlayerState $player, ?Transform $actor = null): bool
	{
		return $this->activate($lineId, 'shoot', $sector, $player, $actor);
	}

	public function definition(int $number): ?LineAction { return $this->actions[$number] ?? null; }

	public function activate(
		int $lineId
		, string $trigger
		, int $actorSector
		, PlayerState $player
		, ?Transform $actor = null
		, bool $frontCrossing = true
	): bool {
		$line = $this->level->map->linedefs[$lineId] ?? null;

		if($line && $this->level->map->format === 'HEXEN')
		return $this->activateHexen($line, $trigger, $actor, $frontCrossing);

		$number = $line ? (int)$line['action'] : 0;
		$action = $line ? ($this->actions[$number] ?? null) : null;

		if(!$action || $action->trigger !== $trigger || isset($this->consumed[$lineId])) return false;

		if($trigger === LineAction::WALK && $action->operation === LineAction::TELEPORT && !$frontCrossing)
		return false;

		if($action->key !== null && !$this->hasKey($player, $action->key))
		{
			$player->showMessage($this->language->text(match(strtolower($action->key))
			{
				'blue' => 'PD_BLUEK', 'red' => 'PD_REDK', 'yellow' => 'PD_YELLOWK',
				default => ''
			}, 'YOU NEED A ' . strtoupper($action->key) . ' KEY'
			));

			$this->playAtActor(SoundCue::locked(), $actor);

			return false;
		}

		$sectors = $action->operation === LineAction::LOCAL_DOOR
			? array_values(array_filter([$this->level->sides($lineId)['back']], static fn($v): bool => $v !== null))
			: ($this->level->tags[$line['tag']] ?? []);

		$activated = match($action->operation)
		{
			LineAction::LOCAL_DOOR, LineAction::DOOR => $this->doors(
				$sectors
				, (string)$action->mode
				, $action->operation === LineAction::LOCAL_DOOR
			),
			LineAction::FLOOR => $this->floors($sectors, (string)$action->mode, $lineId),
			LineAction::LIFT => $this->lifts($sectors),
			LineAction::STAIRS => $this->stairs($sectors),
			LineAction::DONUT => $this->donut($sectors),
			LineAction::LIGHT => $this->light($sectors),
			LineAction::TELEPORT => $actor ? $this->teleport((int)$line['tag'], $actor) : false,
			LineAction::EXIT => $this->exit($player, false),
			LineAction::SECRET_EXIT => $this->exit($player, true),
			default => false,
		};

		// Action 46 still counts as triggered when another action already owns its door.
		$triggered = $activated || $number === 46;

		if($triggered && $action->isSwitch())
		$this->switchTexture($lineId, $action->repeatable, in_array($number, [11,51], true));

		// W1 triggers consume the crossing even when their thinker is busy; other
		// one-shot actions consume the first accepted trigger.
		if(!$action->repeatable && ($triggered || $trigger === LineAction::WALK)) $this->consumed[$lineId] = true;

		return $triggered;
	}

	/** Hexen trigger flags and five arguments belong to a distinct action namespace. */
	private function activateHexen(array $line, string $trigger, ?Transform $actor, bool $frontCrossing): bool
	{
		$expected = match(($line['flags'] >> 10) & 7) { 0 => 'walk', 1,6 => 'use', 3 => 'shoot', default => '' };
		$id = $line['index'];

		if($expected !== $trigger || isset($this->consumed[$id])) return false;

		[$tag,$speed,$delay,$lip] = $line['args'];
		$number = $line['action'];
		$activated = false;

		if($number === 70)
		{
			if(!$actor || !$frontCrossing) return false;

			$activated = $this->teleport($speed, $actor, $tag, (bool)$delay);
		}
		elseif($number === 12 || $number === 206)
		{
			$sectors = $tag
				? ($this->level->tags[$tag] ?? [])
				: array_values(array_filter([$this->level->sides($id)['back']], static fn($id): bool => $id !== null));

			if($number === 12)
			{
				$activated = $this->doors($sectors, LineAction::OPEN_CLOSE, $tag === 0);

				foreach($sectors as $sectorId)
				{
					$entity = $this->level->sectors[$sectorId]->ceilingMover;

					if($entity === null || !$this->registry->alive($entity)) continue;

					$mover = $this->registry->get($entity, SectorMover::class);
					$mover->speed = $speed * 35 / 8;
					$mover->waitTics = $delay;
				}
			}
			else
			foreach($sectors as $sectorId)
			{
				$sector = $this->level->sectors[$sectorId];

				$mover = SectorMover::lift(
					$sectorId
					, min($sector->floor, $this->level->lowestNeighborFloor($sectorId) + $lip)
					, $sector->floor
				);

				$mover->speed = $speed * 35 / 8;
				$mover->waitTics = $delay;
				$activated = $this->startMover($mover) || $activated;
			}
		}
		elseif($number === 47 || $number === 69)
		{
			// Ceiling_MoveToValue[Times8]: tag, speed, height, negative, change.
			// A control-sector ceiling also moves every 3D floor that references it.
			$target = $delay * ($number === 69 ? 8 : 1) * ($lip ? -1 : 1);

			foreach($this->level->tags[$tag] ?? [] as $sectorId)
			{
				if($speed <= 0 || $this->level->sectors[$sectorId]->ceiling === (float)$target) continue;
				$activated = $this->startMover(SectorMover::ceiling($sectorId, $target, $speed * 35 / 8)) || $activated;
			}
		}
		elseif($number === 107)
		{
			$activated = $this->level->retargetCameraPortals($tag, $speed, $id);
		}
		elseif($number === 80)
		{
			$activated = $this->level->runDomTextureScript($tag);
		}

		if($activated)
		{
			$repeat = (bool)($line['flags'] & 512);

			if(!$repeat) $this->consumed[$id] = true;

			if($trigger === 'use') $this->switchTexture($id, $repeat, false);
		}

		return $activated;
	}

	/** Move an actor continuously through a passable line camera portal. */
	private function crossCameraPortal(
		CameraPortalLink $portal
		, Transform $actor
		, float $fromX
		, float $fromY
		, float $fraction
	): void {
		// The pre-crossing pose belongs to the origin component. Transforming that
		// whole point places render interpolation behind the destination portal,
		// even though the renderer has already switched to the destination
		// component. Begin destination-space history at the crossing instead.
		$fraction = max(0.0, min(1.0, $fraction));
		$crossingX = $fromX + ($actor->x - $fromX) * $fraction;
		$crossingY = $fromY + ($actor->y - $fromY) * $fraction;
		$crossingZ = $actor->previousZ + ($actor->z - $actor->previousZ) * $fraction;
		[$travelX,$travelY] = $portal->vector($actor->x - $fromX, $actor->y - $fromY);
		[$previousX,$previousY] = $portal->point($crossingX, $crossingY);
		[$x,$y] = $portal->point($actor->x, $actor->y);
		$travelDistance = hypot($travelX, $travelY);

		if($travelDistance > 0)
		{
			$directionX = $travelX / $travelDistance;
			$directionY = $travelY / $travelDistance;
			$exitDepth = ($x - $previousX) * $directionX + ($y - $previousY) * $directionY;
			$previousX += $directionX * self::PORTAL_EXIT_INSET;
			$previousY += $directionY * self::PORTAL_EXIT_INSET;

			if($exitDepth < self::PORTAL_EXIT_INSET)
			{
				$x += $directionX * (self::PORTAL_EXIT_INSET - $exitDepth);
				$y += $directionY * (self::PORTAL_EXIT_INSET - $exitDepth);
			}
		}

		$actor->previousX = $previousX;
		$actor->previousY = $previousY;
		$actor->previousZ = $portal->z($crossingZ, $this->level);
		$actor->x = $x;
		$actor->y = $y;
		$actor->z = $portal->z($actor->z, $this->level);
		$actor->angle = $portal->angle($actor->angle);
		$entity = $this->entityForTransform($actor);

		if($entity !== null && $this->registry->has($entity, Velocity::class))
		{
			$velocity = $this->registry->get($entity, Velocity::class);
			[$velocity->x,$velocity->y] = $portal->vector($velocity->x, $velocity->y);
		}
	}

	/** Used by E1M8's boss-death action without manufacturing a linedef trigger. */
	public function lowerBossFloor(int $tag): bool
	{
		return $this->floors($this->level->tags[$tag] ?? [], 'lower-lowest', -1);
	}

	/** @param list<int> $sectors */
	private function doors(array $sectors, string $mode, bool $local): bool
	{
		$changed = false;

		foreach($sectors as $id)
		{
			$sector = $this->level->sectors[$id];

			if($sector->ceilingMover !== null && $this->registry->alive($sector->ceilingMover))
			{
				// Only raise/wait/lower manual doors may reverse while active. An
				// open-and-stay door must keep opening when its line is hit again.
				if(!$local || $mode !== LineAction::OPEN_CLOSE) continue;

				$mover = $this->registry->get($sector->ceilingMover, SectorMover::class);

				if(!$mover->isDoor()) continue;

				$open = max($sector->floor, $this->level->lowestNeighborCeiling($id) - 4);

				if(!$mover->isWaiting() && $mover->target <= $sector->ceiling)
				{
					$mover->target = $open;
					$mover->returnTarget = $sector->originalCeiling;
					$mover->waitTics = 150;
					$mover->resume();
					$this->playAtSector(SoundCue::doorOpen(), $id);
				}
				else
				{
					$mover->target = $sector->originalCeiling;
					$mover->returnTarget = null;
					$mover->resume();
					$this->playAtSector(SoundCue::doorClose(), $id);
				}

				$changed = true;
				continue;
			}

			$open = max($sector->floor, $this->level->lowestNeighborCeiling($id) - 4);

			if($mode === LineAction::CLOSE_OPEN)
			$changed = $this->startMover(
				SectorMover::delayedOpenDoor($id, $sector->floor, $sector->ceiling)
			) || $changed;
			elseif($mode === LineAction::OPEN_CLOSE)
			$changed = $this->startMover(SectorMover::normalDoor($id, $open, $sector->ceiling)) || $changed;
			else $changed = $this->startMover(SectorMover::openDoor($id, $open)) || $changed;
		}

		return $changed;
	}

	/** @param list<int> $sectors */
	private function floors(array $sectors, string $mode, int $lineId): bool
	{
		$changed = false;

		foreach($sectors as $id)
		{
			$sector = $this->level->sectors[$id];
			$speed = str_contains($mode, 'fast') ? 140 : (str_contains($mode, 'change') ? 17.5 : 35);
			$target = match($mode)
			{
				LineAction::RAISE_CEILING => $this->level->lowestNeighborCeiling($id),
				LineAction::RAISE_NEXT, LineAction::RAISE_NEXT_CHANGE => $this->level->nextHigherFloor($id),
				LineAction::LOWER_LOWEST => $this->level->lowestNeighborFloor($id),
				LineAction::LOWER_HIGHEST => $this->level->highestNeighborFloor($id),
				LineAction::LOWER_HIGHEST_FAST => min($sector->floor, $this->level->highestNeighborFloor($id) + 8),
				default => $sector->floor,
			};

			$started = $this->startMover(SectorMover::platform($id, $target, $speed));

			if($started && str_contains($mode, 'change'))$this->copyTriggerTexture($sector, $lineId);

			$changed = $started || $changed;
		}

		return $changed;
	}

	/** @param list<int> $sectors */
	private function lifts(array $sectors): bool
	{
		$changed = false;

		foreach($sectors as $id)
		{
			$sector = $this->level->sectors[$id];

			$changed = $this->startMover(
				SectorMover::lift($id, $this->level->lowestNeighborFloor($id), $sector->floor)
			) || $changed;
		}

		return $changed;
	}

	/** @param list<int> $starts */
	private function stairs(array $starts): bool
	{
		$visited = [];
		$queue = [];

		foreach($starts as $id) $queue[] = [$id,1,$this->level->sectors[$id]->floorTexture];

		while($queue)
		{
			[$id,$step,$texture] = array_shift($queue);

			if(isset($visited[$id]) || $this->level->sectors[$id]->floorMover !== null) continue;

			$visited[$id] = true;
			$sector = $this->level->sectors[$id];
			$this->startMover(SectorMover::stairs($id, $sector->floor + 8 * $step));

			foreach($this->level->map->neighbors[$id] ?? [] as $neighbor)
			if(!isset($visited[$neighbor]) && $this->level->sectors[$neighbor]->floorTexture === $texture)
			$queue[] = [$neighbor,$step + 1,$texture];
		}

		return (bool)$visited;
	}

	/** @param list<int> $pillars */
	private function donut(array $pillars): bool
	{
		$changed = false;

		foreach($pillars as $id)
		foreach($this->level->map->neighbors[$id] ?? [] as $ringId)
		foreach($this->level->map->neighbors[$ringId] ?? [] as $outerId)
		if($outerId !== $id)
		{
			$outer = $this->level->sectors[$outerId];
			$a = $this->startMover(SectorMover::donut($id, $outer->floor));
			$b = $this->startMover(SectorMover::donut($ringId, $outer->floor, $outer->floorTexture, 0));
			$changed = $a || $b || $changed;
			break 2;
		}

		return $changed;
	}

	/** @param list<int> $sectors */
	private function light(array $sectors): bool
	{
		foreach($sectors as $id) $this->level->sectors[$id]->setLight(35);

		return (bool)$sectors;
	}

	private function teleport(int $tag, Transform $actor, ?int $tid = null, bool $noSourceFog = false): bool
	{
		$entity = $this->entityForTransform($actor);

		if($entity !== null && $this->registry->has($entity, Projectile::class)) return false;

		foreach($this->level->map->things as $thing)
		{
			if($thing['type'] !== 14 || ($tid !== null && $tid !== 0 && ($thing['id'] ?? 0) !== $tid)) continue;

			if(($tid === null || $tag !== 0) && !in_array(
				$this->level->map->sectorAt($thing['x'], $thing['y'])
				, $this->level->tags[$tag] ?? []
				, true
			))
			continue;

			$sourceX = $actor->x;
			$sourceY = $actor->y;
			$sourceZ = $actor->z;
			$angle = deg2rad((float)$thing['angle']);
			$actor->x = $actor->previousX = (float)$thing['x'];
			$actor->y = $actor->previousY = (float)$thing['y'];
			$actor->angle = $angle;

			if($entity !== null && $this->registry->has($entity, \DoomPhonics\Component\ViewInput::class))
			$this->registry->get($entity, \DoomPhonics\Component\ViewInput::class)->consumeYaw();

			$actor->z = $actor->previousZ = $this->level->sectorAt($actor->x, $actor->y)->floorZ($actor->x, $actor->y);

			if($entity !== null && $this->registry->has($entity, Velocity::class))
			{
				$velocity = $this->registry->get($entity, Velocity::class);
				$velocity->x = $velocity->y = $velocity->z = 0;
			}

			if($entity !== null && $this->registry->has($entity, PlayerState::class))
			$this->registry->get($entity, PlayerState::class)->reactionTics = 18;

			if(!$noSourceFog) $this->spawnFog($sourceX, $sourceY, $sourceZ, 0);

			$this->spawnFog($actor->x + cos($angle) * 20, $actor->y + sin($angle) * 20, $actor->z, $angle);
			$this->playAt(SoundCue::teleport(), new SoundSource($sourceX, $sourceY));
			$this->playAt(SoundCue::teleport(), new SoundSource($actor->x, $actor->y));

			return true;
		}

		return false;
	}

	private function exit(PlayerState $player, bool $secret): bool
	{
		$player->exited = true;
		$player->secretExit = $secret;

		if($this->onExit) ($this->onExit)($secret);

		return true;
	}

	private function startMover(SectorMover $mover): bool
	{
		$sector = $this->level->sectors[$mover->sector];
		$active = $sector->activeMoverFor($mover);

		if($active !== null && $this->registry->alive($active)) return false;

		$entity = $this->registry->create($mover);
		$sector->lockMover($mover, $entity);

		if($this->own) ($this->own)($entity);

		$cue = $mover->isLift()
			? SoundCue::platformStart()
			: ($mover->movesCeiling() && $mover->target < $sector->ceiling
				? SoundCue::doorClose()
				: ($mover->movesCeiling()?SoundCue::doorOpen():SoundCue::stoneMove())
			);

		$this->playAtSector($cue, $mover->sector);

		return true;
	}

	private function copyTriggerTexture(SectorState $sector, int $lineId): void
	{
		if($lineId < 0) return;

		$line = $this->level->map->linedefs[$lineId];
		$sideId = $line['right'];

		if($sideId < 0) return;

		$source = $this->level->sectors[$this->level->map->sidedefs[$sideId]['sector']];
		$sector->setFloorTexture($source->floorTexture);
		$sector->setSpecial(0);
	}

	private function switchTexture(int $lineId, bool $button, bool $exit): void
	{
		$line = $this->level->map->linedefs[$lineId];
		$side = (int)$line['right'];

		if($side < 0)return;

		foreach(['upper','middle','lower'] as $slot)
		{
			$old = $this->level->sideTextures[$side][$slot];
			$new = $this->switchPair($old);

			if($new === null)continue;

			$this->level->setSideTexture($side, $slot, $new);
			$this->playAtLine($exit?SoundCue::exitSwitch():SoundCue::switch(), $lineId);

			if($button && !array_filter($this->buttons, static fn(array $active): bool => $active['line'] === $lineId))
			$this->buttons[] = ['side' => $side,'slot' => $slot,'texture' => $old,'tics' => 35,'line' => $lineId];

			return;
		}
	}

	private function switchPair(string $texture): ?string
	{
		if(str_starts_with($texture, 'SW1')) return 'SW2' . substr($texture, 3);

		if(str_starts_with($texture, 'SW2')) return 'SW1' . substr($texture, 3);

		return null;
	}

	private function hasKey(PlayerState $player, string $color): bool
	{
		return !empty($player->keys[ucfirst($color) . 'Card']) || !empty($player->keys[ucfirst($color) . 'Skull']);
	}

	private function entityForTransform(Transform $transform): ?int
	{
		foreach($this->registry->query(Transform::class) as $entity)
		if($this->registry->get($entity, Transform::class) === $transform)return $entity;

		return null;
	}

	private function spawnFog(float $x, float $y, float $z, float $angle): void
	{
		if(!$this->factory || !($definition = $this->factory->catalog->named('TeleportFog')))return;

		$sector = $this->level->sectorAt($x, $y);

		$entity = $this->factory->spawn($this->registry, $definition, [
			'x' => $x
			, 'y' => $y
			, 'z' => $z - $sector->floor
			, 'angle' => rad2deg($angle)
			, 'type' => 0
			, 'index' => -1
			, 'flags' => []
		], [
			'floor' => $sector->floor
			, 'ceiling' => $sector->ceiling
		]);

		if($this->own)($this->own)($entity);
	}

	private function playAtSector(SoundCue $cue, int $sector): void
	{
		[$x,$y] = $this->level->sectorCenter($sector);
		$this->playAt($cue, new SoundSource($x, $y));
	}

	private function playAtLine(SoundCue $cue, int $lineId): void
	{
		$line = $this->level->map->linedefs[$lineId];
		$a = $this->level->map->vertices[$line['from']];
		$b = $this->level->map->vertices[$line['to']];
		$this->playAt($cue, new SoundSource(($a['x'] + $b['x']) / 2, ($a['y'] + $b['y']) / 2));
	}

	private function playAtActor(SoundCue $cue, ?Transform $actor): void
	{
		if($actor)$this->playAt($cue, new SoundSource($actor->x, $actor->y));
	}

	private function playAt(SoundCue $cue, SoundSource $source): void
	{
		$listener = null;

		foreach($this->registry->query(Query::all(PlayerStart::class, Transform::class))as$id)
		{
			$listener = $this->registry->get($id, Transform::class);
			break;
		}

		$listenerPose = $listener?new SoundListener($listener->x, $listener->y, $listener->angle):null;
		$this->sound->play(new SoundEvent($cue, $source, $listenerPose));
	}

	/** @param array<string,mixed> $line */
	private static function segmentsCross(float $ax, float $ay, float $bx, float $by, array $line, Level $level): bool
	{
		$c = $level->map->vertices[$line['from']];
		$d = $level->map->vertices[$line['to']];
		$one = self::orient($ax, $ay, $bx, $by, $c['x'], $c['y']);
		$two = self::orient($ax, $ay, $bx, $by, $d['x'], $d['y']);
		$three = self::orient($c['x'], $c['y'], $d['x'], $d['y'], $ax, $ay);
		$four = self::orient($c['x'], $c['y'], $d['x'], $d['y'], $bx, $by);

		return (($one < 0 && $two > 0) || ($one > 0 && $two < 0) || $one === 0.0 || $two === 0.0)
			&& (($three < 0 && $four > 0) || ($three > 0 && $four < 0) || $three === 0.0 || $four === 0.0)
			&& !($three === 0.0 && $four === 0.0);
	}

	/** @param array<string,mixed> $line */
	private static function intersectionFraction(
		float $ax
		, float $ay
		, float $bx
		, float $by
		, array $line
		, Level $level
	): float {
		$c = $level->map->vertices[$line['from']];
		$d = $level->map->vertices[$line['to']];
		$rx = $bx - $ax;
		$ry = $by - $ay;
		$sx = $d['x'] - $c['x'];
		$sy = $d['y'] - $c['y'];
		$den = $rx * $sy - $ry * $sx;

		if(abs($den) < 1e-9)return 0;

		return (($c['x'] - $ax) * $sy - ($c['y'] - $ay) * $sx) / $den;
	}

	private static function orient(float $ax, float $ay, float $bx, float $by, float $cx, float $cy): float
	{
		return ($bx - $ax) * ($cy - $ay) - ($by - $ay) * ($cx - $ax);
	}
}
