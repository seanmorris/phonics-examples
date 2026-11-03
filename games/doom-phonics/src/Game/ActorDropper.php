<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

use DoomPhonics\Adapter\ActorFactory;
use DoomPhonics\Component\ActorState;
use DoomPhonics\Component\InventoryPickup;
use DoomPhonics\Component\Transform;
use Phonics\Ecs\Registry;

/** Materializes DECORATE DropItem declarations when a live actor dies. */
final class ActorDropper
{
	private ?\Closure $randomByte;

	public function __construct(
		public Level $level
		, public ActorFactory $factory
		, ?callable $randomByte = null
	){
		$this->randomByte = $randomByte === null ? null : \Closure::fromCallable($randomByte);
	}

	/** @return list<int> Spawned drop entity IDs. */
	public function spawn(Registry $registry, int $source): array
	{
		if(!$registry->alive($source)
			|| !$registry->has($source, ActorState::class)
			|| !$registry->has($source, Transform::class)
		) return [];

		$state = $registry->get($source, ActorState::class);
		$pose = $registry->get($source, Transform::class);
		$sector = $this->level->sectorAt($pose->x, $pose->y);
		$spawned = [];

		foreach($state->definition->values('DropItem') as $values)
		{
			$name = (string)($values[0] ?? 'None');

			if($name === '' || strcasecmp($name, 'None') === 0) continue;

			$chance = max(0, min(255, (int)($values[1] ?? 255)));
			$roll = $this->randomByte ? ($this->randomByte)() : mt_rand(0, 255);

			if($chance < 255 && $roll > $chance) continue;

			$definition = $this->factory->catalog->named($name);

			if(!$definition) continue;

			$entity = $this->factory->spawn(
				$registry
				, $definition
				, [
					'x' => $pose->x
					, 'y' => $pose->y
					, 'z' => 0
					, 'angle' => rad2deg($pose->angle)
					, 'type' => $definition->editorNumber ?? 0
					, 'index' => -1
					, 'flags' => []
				]
				, [
					'floor' => $sector->floorZ($pose->x, $pose->y)
					, 'ceiling' => $sector->ceilingZ($pose->x, $pose->y)
				]
			);

			if($registry->has($entity, InventoryPickup::class))
			{
				$pickup = $registry->get($entity, InventoryPickup::class);
				$pickup->dropped = true;
				$explicitAmount = (int)($values[2] ?? -1);

				if($explicitAmount > 0) $pickup->amount = $explicitAmount;
				elseif($this->factory->catalog->isA($definition, 'Ammo'))
				$pickup->amount = max(1, intdiv($pickup->amount, 2));
			}

			$spawned[] = $entity;
		}

		return $spawned;
	}
}
