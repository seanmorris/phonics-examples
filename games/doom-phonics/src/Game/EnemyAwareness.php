<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

use DoomPhonics\Component\Transform;
use Phonics\Ecs\Registry;

/** Doom-compatible sector sound flooding used by dormant monster A_Look states. */
final class EnemyAwareness
{
	private const SOUND_BLOCK = 0x40;

	/** @var array<int,int> Most recent audible target for each reached sector. */
	private array $soundTargets = [];
	/** @var array<int,list<array{sector:int,line:int,blocked:bool}>> */
	private array $adjacent = [];

	public function __construct(public Level $level)
	{
		foreach($level->map->linedefs as $line)
		{
			$portal = $level->portal((int)$line['index']);

			if($portal === null) continue;

			$blocked = ((int)$line['flags'] & self::SOUND_BLOCK) !== 0;
			$this->adjacent[$portal->rightSector][] = [
				'sector' => $portal->leftSector
				, 'line' => (int)$line['index']
				, 'blocked' => $blocked
			];

			$this->adjacent[$portal->leftSector][] = [
				'sector' => $portal->rightSector
				, 'line' => (int)$line['index']
				, 'blocked' => $blocked
			];
		}
	}

	/** Flood one player weapon noise through open sectors and at most one sound-blocking line. */
	public function alert(Registry $registry, int $target, int $emitter): void
	{
		if(!$registry->alive($target) || !$registry->alive($emitter)
			|| !$registry->has($emitter, Transform::class)
		) return;

		$pose = $registry->get($emitter, Transform::class);
		$start = $this->level->sectorAt($pose->x, $pose->y)->index;
		$queue = [[$start,0]];
		$head = 0;
		$visited = [];

		while(isset($queue[$head]))
		{
			[$sector,$blocks] = $queue[$head++];

			if(isset($visited[$sector]) && $visited[$sector] <= $blocks) continue;

			$visited[$sector] = $blocks;
			$this->soundTargets[$sector] = $target;

			foreach($this->adjacent[$sector] ?? [] as $edge)
			{
				if(!$this->open($edge['line'])) continue;

				$nextBlocks = $blocks + ($edge['blocked'] ? 1 : 0);

				if($nextBlocks > 1) continue;

				$queue[] = [$edge['sector'],$nextBlocks];
			}
		}
	}

	public function target(int $sector): ?int { return $this->soundTargets[$sector] ?? null; }

	private function open(int $lineId): bool
	{
		$line = $this->level->map->linedefs[$lineId];
		$from = $this->level->map->vertices[$line['from']];
		$to = $this->level->map->vertices[$line['to']];
		$x = ((float)$from['x'] + (float)$to['x']) / 2;
		$y = ((float)$from['y'] + (float)$to['y']) / 2;
		$portal = $this->level->portal($lineId);

		return $portal !== null && $portal->ceiling($x, $y) > $portal->floor($x, $y);
	}
}
