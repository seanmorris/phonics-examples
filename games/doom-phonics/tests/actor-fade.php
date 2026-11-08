<?php
declare(strict_types=1);

use DoomPhonics\Adapter\WadSource;
use DoomPhonics\Archive\Archive;
use DoomPhonics\Archive\Stack;
use DoomPhonics\Component\ActorFade;
use DoomPhonics\Component\PlayerStart;
use DoomPhonics\Component\Transform;
use DoomPhonics\Definition\StateFrame;
use DoomPhonics\Game\GameSettings;
use DoomPhonics\System\CombatSystem;
use DoomPhonics\System\EpisodeSystems;
use DoomPhonics\Tests\PortMap;
use Phonics\Ecs\Registry;
use Phonics\Ecs\Scheduler;
use Phonics\Input\Controls;
use Phonics\World\ScopeContext;

require_once __DIR__ . '/fixtures/PortMap.php';

// A_FadeOut thins an actor from its Alpha and removes it at zero; the Omega pack's cryo smoke lives by it.
(static function(): void {
	$addon = PortMap::archive(['DECORATE' => <<<'DECORATE'
ACTOR Puff
{
  RenderStyle Shaded
  Alpha 0.3
  +NOINTERACTION
  +NOGRAVITY
  States
  {
  Spawn:
    PLSE A 1
    Wait
  }
}
DECORATE
	]);
	$source = new WadSource(new Stack(new Archive(file_get_contents(dirname(__DIR__) . '/demo/DOOM1.WAD'), 'DOOM1.WAD'), $addon));
	$registry = new Registry;
	$scheduler = new Scheduler($registry);
	$descriptor = null;

	foreach($source->catalog() as $candidate) if($candidate->id === 'E1M1') $descriptor = $candidate;

	$scope = new ScopeContext('episode', $descriptor, $registry, $scheduler, services: ['controls' => new Controls, 'doom-settings' => new GameSettings]);
	$scope->setContent($source->activate('E1M1', $scope)->content());
	(new EpisodeSystems)->activate($scope);
	$combat = null;

	foreach((new ReflectionProperty($scheduler, 'systems'))->getValue($scheduler) as [$callable])
	if(is_array($callable) && $callable[0] instanceof CombatSystem) $combat = $callable[0];

	foreach($registry->query(PlayerStart::class) as $player) break;

	$scheduler->update(1 / 35);
	$pose = $registry->get($player, Transform::class);
	$combat->actions->dispatch($player, new StateFrame('TNT1', 'A', 0, false, 'A_SpawnItem', ['Puff', 32, 16]), $registry);
	$puff = null;

	foreach($registry->query(Transform::class) as $entity)
	if($registry->has($entity, DoomPhonics\Component\ActorState::class) && $registry->get($entity, DoomPhonics\Component\ActorState::class)->definition->name === 'Puff') $puff = $entity;

	verifyWad($puff !== null && !$registry->has($puff, ActorFade::class), 'an actor starts without a running fade');
	$fade = static fn(string $action, array $arguments) => $combat->actions->dispatch($puff, new StateFrame('TNT1', 'A', 0, false, $action, $arguments), $registry);
	$fade('A_FadeOut', [0.1]);
	verifyWad(abs($registry->get($puff, ActorFade::class)->alpha - 0.2) < 1e-9, 'A_FadeOut starts from the definition\'s Alpha and takes the amount off it');
	$fade('A_FadeIn', [0.5]);
	verifyWad(abs($registry->get($puff, ActorFade::class)->alpha - 0.7) < 1e-9, 'A_FadeIn adds the amount back');
	$fade('A_FadeOut', [0.6, 0]);
	$fade('A_FadeOut', [0.6, 0]);
	$scheduler->update(1 / 35);
	verifyWad($registry->alive($puff) && $registry->get($puff, ActorFade::class)->alpha === 0.0, 'without FTF_REMOVE the actor stays at zero alpha');
	$fade('A_FadeOut', []);
	$scheduler->update(1 / 35);
	verifyWad(!$registry->alive($puff), 'A_FadeOut with its default flags removes the actor once it is gone');
})();
