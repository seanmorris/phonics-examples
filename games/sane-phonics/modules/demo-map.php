<?php
declare(strict_types=1);

/** Game-specific scene assembly selected by a Tiled map's system list. */
return new class implements \Phonics\World\SystemSet {

	public function activate(\Phonics\World\ScopeContext $scope, array $options = []): void
	{
		$level = $scope->service('sane.level');

		if(!$level) throw new \LogicException('The demo map set requires a Sane scene');

		$factories = $scope->service('tiled.factories');

		if($factories) $factories->spawn($scope->content(), $scope->registry, [$scope, 'own']);

		$level->composeMap($scope);
	}
};
