<?php
declare(strict_types=1);

/** Spatial map selection is a Sane world system, not a requirement of a Tiled world asset. */
return new class implements \Phonics\World\SystemSet {

	public function activate(\Phonics\World\ScopeContext $scope, array $options = []): void
	{
		$stream = $scope->service('sane.stream') ?? throw new \LogicException('Missing Sane world stream');
		$camera = $scope->service('sane.camera');

		$scope->system('spatial-maps', static function() use ($stream, $camera): void {
			$stream->update($camera->x, $camera->y, $camera->width / $camera->zoom, $camera->height / $camera->zoom);
		});
	}
};
