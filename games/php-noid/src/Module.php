<?php
declare(strict_types=1);
namespace PhpNoid;
use Phonics\Package\Context;
use PhpNoid\Component\Brick;
use PhpNoid\Component\Paddle;
use PhpNoid\Component\Enemy;
use PhpNoid\System\PaddleSystem;
use PhpNoid\System\BallSystem;
use PhpNoid\System\CapsuleSystem;
use PhpNoid\System\EnemySystem;
use PhpNoid\System\ProjectileSystem;
use PhpNoid\System\StageSystem;
use PhpNoid\System\TransitionSystem;

/** Register authoring factories independently from game startup. */
final class Module
{

	/**
	 * Register the package entity factories with the session.
	 *
	 * @param Context $context Session registration and lifecycle services.
	 * @return void Completes the operation.
	 */
	public static function factories(Context $context): void
	{
		$context->factory('PhpNoid.Brick', /**
		 * Apply the registered session callback.
		 *
		 * @param array $o O value used by this operation.
		 * @return array Result of this operation.
		 */
			static function(array $o): array {
				$p = $o['properties'];

				return [
					new Brick(
						(float)$o['x']
						, (float)$o['y']
						, (float)($o['width'] ?? 54)
						, (float)($o['height'] ?? 20)
						, (int)($p['hits'] ?? 1)
						, (bool)($p['unbreakable'] ?? false)
						, (string)($p['capsule'] ?? '')
						, (int)($p['score'] ?? 100)
						, (string)($p['material'] ?? 'normal')
					)
				];
			}
		);

		$context->factory('PhpNoid.Paddle', /**
		 * Apply the registered session callback.
		 *
		 * @param array $o O value used by this operation.
		 * @return mixed Result of this operation.
		 */
			static fn(array $o) => [new Paddle((float)$o['x'], (float)$o['y'])]
		);

		$context->factory('PhpNoid.Enemy', /**
		 * Apply the registered session callback.
		 *
		 * @param array $o O value used by this operation.
		 * @return mixed Result of this operation.
		 */
			static fn(array $o) => [
				new Enemy(
					(float)$o['x']
					, (float)$o['y']
					, $o['properties']['family'] ?? 'menacer'
					, $o['properties']['effect'] ?? 'copper'
					, (float)($o['properties']['interval'] ?? 5)
					, (int)($o['properties']['hits'] ?? 3)
					, (string)($o['properties']['capsule'] ?? 'expand')
				)
			]
		);
	}

	/** Register independently composable Tiled components alongside legacy factories. */
	public static function components(Context $context): void
	{
		$context->component('PhpNoid.Brick', static function(array $p, $scope, $target): Brick {
			$o = $target->value;

			return new Brick(
				(float)($o['x'] ?? 0) + ($target->layer?->offsetX ?? 0)
				, (float)($o['y'] ?? 0) + ($target->layer?->offsetY ?? 0)
				, (float)($o['width'] ?? 54)
				, (float)($o['height'] ?? 20)
				, (int)$p['hits']
				, (bool)$p['unbreakable']
				, (string)$p['capsule']
				, (int)$p['score']
				, (string)$p['material']
			);
		});

		$context->component('PhpNoid.Paddle', static function(array $p, $scope, $target): Paddle {
			return new Paddle(
				(float)($target->value['x'] ?? 0) + ($target->layer?->offsetX ?? 0)
				, (float)($target->value['y'] ?? 0) + ($target->layer?->offsetY ?? 0)
			);
		});

		$context->component('PhpNoid.Enemy', static function(array $p, $scope, $target): Enemy {
			return new Enemy(
				(float)($target->value['x'] ?? 0) + ($target->layer?->offsetX ?? 0)
				, (float)($target->value['y'] ?? 0) + ($target->layer?->offsetY ?? 0)
				, $p['family']
				, $p['effect']
				, (float)$p['interval']
				, (int)$p['hits']
				, $p['capsule']
			);
		});
	}

	/**
	 * Register independently ordered gameplay systems.
	 *
	 * @param Playfield $field Playfield state shared by this system.
	 * @return void Completes the operation.
	 */
	public static function systems(Playfield $field, ?\Phonics\World\ScopeContext $scope = null): void
	{
		$previous = [];

		foreach([
			PaddleSystem::class
			, EnemySystem::class
			, BallSystem::class
			, CapsuleSystem::class
			, ProjectileSystem::class, StageSystem::class, TransitionSystem::class
		] as $class)
		{
			$system = new $class($field);
			($scope ?? $field->context)->system($class, [$system, 'update'], $previous);
			$previous = [$class];
		}
	}
}
