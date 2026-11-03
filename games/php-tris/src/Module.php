<?php
declare(strict_types=1);
namespace PhpTris;
use Phonics\Package\Context;
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
	 * Register movement, gravity, resolution, and progression in dependency order.
	 *
	 * @param Context $context Session registration and cleanup context.
	 * @return void Updates owned state without returning a value.
	 */
	public static function systems(Context $context): void
	{
		$after = [];

		foreach([MovementSystem::class,GravitySystem::class,ResolutionSystem::class,ProgressSystem::class] as $class)
		{
			$system = new $class;
			$context->system($class, [$system,'update'], $after);
			$after = [$class];
		}
	}
}
