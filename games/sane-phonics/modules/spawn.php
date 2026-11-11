<?php
declare(strict_types=1);

return static function(
	array $values
	, \Phonics\World\ScopeContext $scope
	, \Phonics\Tiled\Target $target
): \SanePhonics\Gameplay\SpawnPoint {
	return new \SanePhonics\Gameplay\SpawnPoint(
		(float)($target->value['x'] ?? 80)
		, (float)($target->value['y'] ?? 100)
	);
};
