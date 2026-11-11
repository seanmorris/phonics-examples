<?php
declare(strict_types=1);
namespace PhpNoid;

use Phonics\World\ScopeContext;
use Phonics\World\SystemSet;

/** The campaign chooses stages by identity; each selected map owns its gameplay systems. */
final class StageSystems implements SystemSet
{

	/**
	 * Bind one selected stage's gameplay to its ownership scope.
	 * @param ScopeContext $scope Prepared stage scope with shared campaign services.
	 * @param array $options Optional system-set configuration.
	 * @return void Registers stage systems and cleanup for transient entities.
	 */
	public function activate(ScopeContext $scope, array $options = []): void
	{
		$field = $scope->service('php-noid.field') ?? throw new \LogicException('Missing Noid playfield');
		$index = $scope->service('php-noid.stageIndex', 0);
		$field->stage($scope->content(), $index, $scope);

		$scope->onClose(/** Release only this stage's playfield association.
   * @return void Leaves a newer stage association intact.
   */
			static function() use ($field, $scope): void {
				if($field->scope === $scope) $field->scope = null;
			}
		);

		Module::systems($field, $scope);
	}
}
