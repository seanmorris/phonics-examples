<?php
declare(strict_types=1);

use Phonics\Package\Context;
use Phonics\Tiled\Adapter;
use Phonics\World\SourceDefinition;
use PhpNoid\Module;
use PhpNoid\Playfield;
use PhpNoid\StageSystems;

/** Exercise the authored campaign through the same component scopes as the browser game. */
function campaign(): Playfield
{
	$context = new Context;
	Module::components($context);

	foreach(['Brick', 'Paddle', 'Enemy'] as $name)
	$context->components->alias('@php-noid/' . strtolower($name) . '-component', 'PhpNoid.' . $name);
	$field = new Playfield($context);
	$context->runtime->service('php-noid.field', $field);
	$context->runtime->systemSet('@php-noid/stage', new StageSystems);
	$context->runtime->adapter('tiled', new Adapter);
	$context->runtime->source(new SourceDefinition('campaign', 'tiled', [
		['url' => dirname(__DIR__) . '/worlds/campaign.world']
	], ['lifecycle' => 'reset']));
	$root = $context->runtime->activate('campaign', 'world');
	$scopes = $context->runtime->catalog('campaign');
	$stage = null;
	$field->loadStage = static function(int $index) use ($context, $scopes, &$stage, $root): void {
		$context->systems->defer(static function() use ($context, $scopes, &$stage, $index): void {
			$stage?->release();
			$context->runtime->service('php-noid.stageIndex', $index);
			$stage = $context->runtime->activate('campaign', $scopes[$index + 1]->id);
		});
	};
	($field->loadStage)(0);

	return $field;
}
