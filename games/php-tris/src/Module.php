<?php
declare(strict_types=1);
namespace PhpTris;
use Phonics\Package\Context;
use Phonics\World\ScopeContext;
use PhpTris\Component\
{
	Board,Active,Queue,Input,Run
};
use PhpTris\System\
{
	MovementSystem,GravitySystem,ResolutionSystem,ProgressSystem
};

/** Independently reusable factories and ordered systems; no browser or demo dependency. */
final class Module
{
	public const MODES = [
		'endless' => 'Endless'
		, 'race' => '40 Lines'
		, 'time' => 'Time Attack'
		, 'squares' => 'Squares'
		, 'cascade' => 'Cascade'
		, 'targets' => 'Target Rows'
		, 'bonds' => 'Color Bonds'
		, 'reactor' => 'Reactor'
	];

	/**
	 * Create independent ECS components from authoring properties.
	 *
	 * @param array $properties Author-supplied component defaults.
	 * @return array Computed values in iteration order.
	 */
	public static function components(array $properties = []): array
	{
		$mode = $properties['mode'] ?? 'endless';

		if(!isset(self::MODES[$mode])) throw new \InvalidArgumentException('Unknown PHPTris mode: ' . $mode);

		$seed = (int)($properties['seed'] ?? 1);
		$s = new Run($mode);
		$s->seed = $seed;

		return [new Board,new Active,new Queue($mode, $seed),new Input,$s];
	}

	/**
	 * Register the board entity factory with the package context.
	 *
	 * @param Context $context Session registration and cleanup context.
	 * @return void Updates owned state without returning a value.
	 */
	public static function factories(Context $context): void
	{

		/**
		 * Build components from the authored board object.
		 *
		 * @param array $object Decoded entity object.
		 * @return array Independent board components.
		 */
		$factory = static fn(array $object) => self::components($object['properties'] ?? []);
		$context->factory('PhpTris.Board', $factory);
	}

	/**
	 * Register individual board components; the map owns mode and seed configuration.
	 * @param Context $context Session owning the component constructors.
	 * @return void Registers constructors without spawning a board.
	 */
	public static function registerComponents(Context $context): void
	{
		foreach([Board::class, Active::class, Input::class] as $class)
		{
			$name = substr($class, strrpos($class, '\\') + 1);

			$context->component('PhpTris.' . $name, /** Create independent component state.
			 * @return object A fresh component for this authored entity.
			 */ static fn(): object => new $class
			);
		}

		$context->component(
			'PhpTris.Queue'
			, /** Construct state from the owning map's configuration.
			 * @param array $values Reserved component overrides.
			 * @param ScopeContext $scope Owning map scope.
			 * @param \Phonics\Tiled\Target $target Authored component target and map.
			 * @return Queue Fresh state using the selected mode and seed.
			 */ static function(array $values, ScopeContext $scope, \Phonics\Tiled\Target $target): Queue {
				$p = \Phonics\Tiled\Loader::properties($target->map->data['properties'] ?? []);

				return new Queue($p['mode'] ?? 'endless', (int)($p['seed'] ?? 1));
			}
		);

		$context->component(
			'PhpTris.Run'
			, /** Construct state from the owning map's configuration.
			 * @param array $values Reserved component overrides.
			 * @param ScopeContext $scope Owning map scope.
			 * @param \Phonics\Tiled\Target $target Authored component target and map.
			 * @return Run Fresh state using the selected mode and seed.
			 */ static function(array $values, ScopeContext $scope, \Phonics\Tiled\Target $target): Run {
				$p = \Phonics\Tiled\Loader::properties($target->map->data['properties'] ?? []);
				$mode = $p['mode'] ?? 'endless';

				if(!isset(self::MODES[$mode])) throw new \InvalidArgumentException('Unknown PHPTris mode: ' . $mode);

				$run = new Run($mode);
				$run->seed = (int)($p['seed'] ?? 1);

				return $run;
			}
		);
	}

	/**
	 * Register movement, gravity, resolution, and progression in dependency order.
	 *
	 * @param Context|ScopeContext $context Session or map registration and cleanup context.
	 * @return void Updates owned state without returning a value.
	 */
	public static function systems(Context|ScopeContext $context): void
	{
		$after = [];

		foreach([MovementSystem::class,GravitySystem::class,ResolutionSystem::class,ProgressSystem::class] as $class)
		{
			$system = new $class($context instanceof ScopeContext ? $context : null);
			$context->system($class, [$system,'update'], $after);
			$after = [$class];
		}
	}
}
