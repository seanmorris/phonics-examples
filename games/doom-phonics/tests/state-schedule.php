<?php
declare(strict_types=1);

use DoomPhonics\Adapter\WadSource;
use DoomPhonics\Archive\Archive;
use DoomPhonics\Archive\Stack;
use DoomPhonics\Component\ActorState;
use DoomPhonics\Component\HitPoints;
use DoomPhonics\Component\MonsterBrain;
use DoomPhonics\Component\PlayerStart;
use DoomPhonics\Component\Transform;
use DoomPhonics\Game\GameSettings;
use DoomPhonics\Game\PlayerState;
use DoomPhonics\Input\DoomActions;
use DoomPhonics\System\ActorStateSystem;
use DoomPhonics\System\EpisodeSystems;
use Phonics\Ecs\Registry;
use Phonics\Ecs\Scheduler;
use Phonics\Input\Controls;
use Phonics\World\ScopeContext;

// The state system steps only actors whose frame ends in a tic, plus actors changed from outside. It must dispatch
// exactly what a walk over every actor dispatches, in the same order and tics, and leave the same world. E1M3 with
// every monster woken from outside, the player firing, and monsters shooting back exercises waking, pain, weapon
// flashes, puffs, and blood.
(static function(): void {
	$run = static function(bool $scheduled): array {
		mt_srand(2704);
		$source = new WadSource(new Stack(new Archive(file_get_contents(dirname(__DIR__) . '/demo/DOOM1.WAD'), 'DOOM1.WAD')));
		$registry = new Registry;
		$scheduler = new Scheduler($registry);
		$controls = new Controls;
		$descriptor = null;

		foreach($source->catalog() as $candidate) if($candidate->id === 'E1M3') $descriptor = $candidate;

		$scope = new ScopeContext('episode', $descriptor, $registry, $scheduler, services: ['controls' => $controls, 'doom-settings' => new GameSettings]);
		$scope->setContent($source->activate('E1M3', $scope)->content());
		(new EpisodeSystems)->activate($scope);
		$property = new ReflectionProperty($scheduler, 'systems');
		$systems = $property->getValue($scheduler);
		$actions = null;

		foreach($systems as $name => [$callable, $after])
		if(is_array($callable) && $callable[0] instanceof ActorStateSystem)
		{
			$actions = $callable[0]->actions;
			$systems[$name] = [[new ActorStateSystem($actions, $scheduled), 'update'], $after];
		}

		$property->setValue($scheduler, $systems);
		$tic = 0;
		$log = [];
		$table = new ReflectionProperty($actions, 'actions');
		$wrapped = [];

		foreach($table->getValue($actions) as $key => $action)
		$wrapped[$key] = static function(int $entity, ...$rest) use ($action, $key, &$tic, &$log, $registry) {
			$state = $registry->get($entity, ActorState::class);
			$log[] = "$tic $entity $key {$state->label}#{$state->frame}";

			return $action($entity, ...$rest);
		};

		$table->setValue($actions, $wrapped);
		$player = null;

		foreach($registry->query(PlayerStart::class) as $player) $registry->get($player, PlayerState::class)->godMode = true;

		for($tic = 1; $tic <= 140; $tic++)
		{
			if($tic === 3)
			foreach($registry->query(MonsterBrain::class) as $monster)
			{
				$registry->get($monster, MonsterBrain::class)->target = $player;
				$registry->get($monster, ActorState::class)->enter('See');
			}

			$controls->set($tic % 20 < 6 ? [DoomActions::FIRE => 1] : []);
			$scheduler->update(1 / 35);
		}

		$world = [];

		foreach($registry->query(ActorState::class) as $entity)
		{
			$state = $registry->get($entity, ActorState::class);
			$pose = $registry->has($entity, Transform::class) ? $registry->get($entity, Transform::class) : null;
			$world[] = sprintf(
				'%d %s#%d %s %s'
				, $entity
				, $state->label
				, $state->frame
				, $pose ? sprintf('%.3f,%.3f,%.3f', $pose->x, $pose->y, $pose->z) : '-'
				, $registry->has($entity, HitPoints::class) ? $registry->get($entity, HitPoints::class)->current : '-'
			);
		}

		return [$log, $world];
	};

	// One actor's action changing another: the walk reaches a later actor in the same tic and an earlier one in the
	// next, so the schedule must take up each case in that tic.
	$poke = static function(bool $scheduled): array {
		$actors = DoomPhonics\Definition\DecorateParser::catalog(['poke.dec' => <<<'DEC'
ACTOR Poker
{
  States
  {
  Spawn:
    POKE A 7 A_Poke
    Loop
  Hit:
    POKE B 3 A_Hit
    POKE C -1
    Stop
  }
}
DEC]);
		$registry = new Registry;
		$first = $registry->create(new ActorState($actors->named('Poker'), 'Hit'));
		$second = $registry->create(new ActorState($actors->named('Poker'), 'Hit'));
		$registry->get($first, ActorState::class)->enter('Spawn');
		$tic = 0;
		$log = [];
		$dispatcher = new DoomPhonics\Definition\ActionDispatcher;
		$dispatcher->register('A_Poke', static function(int $entity) use (&$tic, &$log, $registry, $first, $second): void {
			$log[] = "$tic poke $entity";
			$registry->get($entity === $first ? $second : $first, ActorState::class)->enter('Hit');
		});
		$dispatcher->register('A_Hit', static function(int $entity) use (&$tic, &$log): void { $log[] = "$tic hit $entity"; });
		$system = new ActorStateSystem($dispatcher, $scheduled);

		for($tic = 0; $tic < 30; $tic++)
		{
			if($tic === 12) $registry->get($second, ActorState::class)->enter('Spawn');

			$system->update($registry, 1 / 35);
		}

		return $log;
	};

	verifyWad(
		$poke(true) === $poke(false) && in_array('0 hit 2', $poke(false), true)
		, 'an actor changed by an earlier actor this tic is stepped this tic, and by a later one next tic'
	);

	[$walkLog, $walkWorld] = $run(false);
	[$scheduledLog, $scheduledWorld] = $run(true);

	verifyWad(count($walkLog) > 1000, 'the E1M3 fight dispatches over a thousand actions in 140 tics');
	verifyWad($scheduledLog === $walkLog, 'stepping only due actors dispatches exactly what walking every actor does');
	verifyWad($scheduledWorld === $walkWorld, 'stepping only due actors leaves the same world');
})();
