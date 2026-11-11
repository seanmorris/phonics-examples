<?php
declare(strict_types=1);
$framework = dirname(__DIR__, 3);
require is_file($framework . '/autoload.php')
	? $framework . '/autoload.php'
	: $framework . '/node_modules/@seanmorris/phonics/autoload.php';
require dirname(__DIR__) . '/autoload.php';

use Phonics\Package\Context;
use Phonics\Tiled\
{
	Adapter, Loader
};
use Phonics\World\SourceDefinition;
use PhpTris\
{
	Module, MatchState, StageSystems
};
use PhpTris\Component\Board;

$context = new Context;
Module::registerComponents($context);

foreach(['Board', 'Active', 'Queue', 'Input', 'Run'] as $name)
$context->components->alias('@php-tris/' . strtolower($name) . '-component', 'PhpTris.' . $name);
$runtime = $context->runtime;
$runtime->adapter('tiled', new Adapter);
$runtime->systemSet('@php-tris/stage', new StageSystems);

$runtime->source(new SourceDefinition('arcade', 'tiled', [
	['url' => dirname(__DIR__) . '/worlds/arcade.world']
], ['lifecycle' => 'reset']));

$world = $runtime->activate('arcade', 'world');
$checks = 0;

$expect = static function(bool $ok, string $message) use (&$checks): void {
	$checks++;

	if(!$ok) throw new RuntimeException($message);
};

$scopes = array_slice($runtime->catalog('arcade'), 1);

foreach($scopes as $scope)
{
	$lease = $runtime->activate('arcade', $scope->id);
	$ids = iterator_to_array($lease->context->query(Board::class));
	$expect(count($ids) === 1, 'Authored map creates one board');
	$m = MatchState::get($context->entities, $ids[0]);
	$p = Loader::properties($lease->context->content()->data['properties']);
	$expect($m->run->mode === $p['mode'] && $m->run->seed === $p['seed'], 'Map config reaches run');
	$old = Module::components($p);
	$expect($m->queue->take() === $old[2]->take(), 'Authored queue preserves the deterministic bag');
	$context->systems->update(1 / 60);
	$expect(abs($m->run->delay - (.75 - 1 / 60)) < 1e-10, 'Map systems advance exactly once');
	$lease->release();
	$expect(count(iterator_to_array($context->entities->query(Board::class))) === 0, 'Board is released with its map');

	$expect(
		!$context->systems->has('arcade:' . $scope->id . '/' . PhpTris\System\MovementSystem::class)
		, 'Map releases systems'
	);
}

// Simultaneously active maps must never advance each other's board twice.
$first = $runtime->activate('arcade', $scopes[0]->id);
$second = $runtime->activate('arcade', $scopes[1]->id);
$context->systems->update(1 / 60);

foreach([$first, $second] as $lease)
{
	$id = iterator_to_array($lease->context->query(Board::class))[0];
	$m = MatchState::get($context->entities, $id);
	$expect(abs($m->run->delay - (.75 - 1 / 60)) < 1e-10, 'Independent scopes advance only their own board');
}

$first->release();
$second->release();
$again = $runtime->activate('arcade', $scopes[0]->id);
$id = iterator_to_array($again->context->query(Board::class))[0];
$expect(MatchState::get($context->entities, $id)->run->delay === .75, 'Restart recreates fresh state');
$context->close();
echo "PHPTris composition: $checks checks passed.\n";
