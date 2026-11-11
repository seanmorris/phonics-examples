<?php
declare(strict_types=1);

/** Optional standalone movement for component-authored actors within one map. */
return new class implements \Phonics\World\SystemSet {

	public function activate(\Phonics\World\ScopeContext $scope, array $options = []): void
	{
		$terrain = \SanePhonics\Physics\TerrainCompiler::build($scope->content());
		$movement = new \SanePhonics\Physics\Platformer($terrain);

		$scope->system('movement', static function($registry, float $dt) use ($scope, $movement): void {
			foreach($scope->query([\SanePhonics\Physics\Body::class, \Phonics\Input\Controls::class]) as $id)
			$movement->step(
				$registry->get($id, \SanePhonics\Physics\Body::class)
				, $registry->get($id, \Phonics\Input\Controls::class)
				, $dt
			);
		});
	}
};
