<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

use DoomPhonics\Adapter\ActorFactory;
use DoomPhonics\Component\ActorState;
use DoomPhonics\Component\Body;
use DoomPhonics\Component\Gravity;
use DoomPhonics\Component\Transform;
use DoomPhonics\Component\TransientEffect;
use DoomPhonics\Component\Velocity;
use DoomPhonics\Definition\ActorCatalog;
use DoomPhonics\Definition\ActorDefinition;
use DoomPhonics\Definition\StateFrame;
use DoomPhonics\Definition\StateSequence;
use Phonics\Ecs\Registry;

/** Spawns short-lived DECORATE blood actors for successful damage events. */
final class BloodEffects
{
	private ?ActorDefinition $fallback = null;

	public function __construct(
		private Level $level
		, private ActorCatalog $actors
		, private ActorFactory $factory
		, private string $fallbackSprite = 'BLUD'
		, private ?GameSettings $settings = null
	){
		$this->fallbackSprite = strtoupper(substr($fallbackSprite, 0, 4));

		if($this->fallbackSprite === '') $this->fallbackSprite = 'BLUD';
	}

	/** Return false for actors whose DECORATE flags explicitly suppress blood. */
	public function bleeds(Registry $registry, int $target): bool
	{
		return $registry->has($target, ActorState::class)
			&& !$registry->get($target, ActorState::class)->definition->hasFlag('NOBLOOD');
	}

	/**
	 * Spawn a renderer-style droplet spray at a confirmed hit.
	 *
	 * BloodType's first class is the ordinary hit actor. An attack actor carrying
	 * +BLOODSPLATTER selects the second class, matching Raven/ZDoom projectiles.
	 *
	 * @return list<int> Spawned effect entities.
	 */
	public function spawn(
		Registry $registry
		, int $target
		, HitscanImpact $impact
		, Transform $source
		, ?int $attack = null
	): array {
		if(!$this->bleeds($registry, $target)) return [];

		$count = (int)round(5 * ($this->settings?->bloodSplatter() ?? 1));

		if($count === 0) return [];

		$targetDefinition = $registry->get($target, ActorState::class)->definition;
		$splatter = $attack !== null
			&& $registry->alive($attack)
			&& $registry->has($attack, ActorState::class)
			&& $registry->get($attack, ActorState::class)->definition->hasFlag('BLOODSPLATTER');

		$name = $this->bloodType($targetDefinition, $splatter ? 1 : 0);

		if($name === '' || strcasecmp($name, 'none') === 0) return [];

		$definition = $this->actors->named($name) ?? $this->fallbackDefinition();
		$sector = $this->level->sectorAt($impact->x, $impact->y);
		$angle = atan2($source->y - $impact->y, $source->x - $impact->x);
		$result = [];

		for($index = 0; $index < $count; $index++)
		{
			$entity = $this->factory->spawn(
				$registry
				, $definition
				, [
					'x' => $impact->x
					, 'y' => $impact->y
					, 'z' => $impact->z - $sector->floor
					, 'angle' => rad2deg($angle)
					, 'type' => 0
					, 'index' => -1
					, 'flags' => []
				]
				, ['floor' => $sector->floor, 'ceiling' => $sector->ceiling]
			);

			$pose = $registry->get($entity, Transform::class);
			$pose->x = $pose->previousX = $impact->x;
			$pose->y = $pose->previousY = $impact->y;
			$pose->z = $pose->previousZ = $impact->z;
			$pose->angle = $angle;

			// Blood actors still need a non-blocking physics body in this ECS so their
			// DECORATE gravity and initial spray velocity can be integrated.
			if(!$registry->has($entity, Body::class))
			$registry->add($entity, new Body(2, 4));

			if(!$registry->has($entity, Gravity::class) && !$definition->hasFlag('NOGRAVITY'))
			$registry->add($entity, new Gravity((float)$definition->value('Gravity', 1)));

			$velocity = $registry->get($entity, Velocity::class);
			$velocity->x = mt_rand(-200, 200) / 100 * DoomGravity::TICS_PER_SECOND;
			$velocity->y = mt_rand(-200, 200) / 100 * DoomGravity::TICS_PER_SECOND;
			$velocity->z = mt_rand(100, 300) / 100 * DoomGravity::TICS_PER_SECOND;
			$registry->add($entity, new TransientEffect);
			$result[] = $entity;
		}

		return $result;
	}

	/** Resolve one of the three ordered BloodType classes from its last declaration. */
	private function bloodType(ActorDefinition $definition, int $index): string
	{
		$occurrences = $definition->values('BloodType');
		$values = $occurrences ? $occurrences[array_key_last($occurrences)] : [];

		if(isset($values[$index])) return trim((string)$values[$index]);

		if(isset($values[0])) return trim((string)$values[0]);

		return $index === 1 ? 'BloodSplatter' : 'Blood';
	}

	/** Build an actor when the requested BloodType class is absent from DECORATE. */
	private function fallbackDefinition(): ActorDefinition
	{
		if($this->fallback) return $this->fallback;

		$definition = new ActorDefinition('__DoomPhonicsBloodSplatter');
		$definition->flags = [
			'noblockmap' => true
			, 'dropoff' => true
			, 'noteleport' => true
		];

		$sequence = new StateSequence;

		foreach(str_split('CBA') as $frame)
		$sequence->frames[] = new StateFrame($this->fallbackSprite, $frame, 8);

		$definition->states['spawn'] = $sequence;

		return $this->fallback = $definition;
	}
}
