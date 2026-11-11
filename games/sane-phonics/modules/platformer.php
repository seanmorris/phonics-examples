<?php
declare(strict_types=1);
use Phonics\Package\Context;
use SanePhonics\Physics\Body;
use SanePhonics\Physics\TerrainCompiler;
use SanePhonics\Physics\Platformer;
use Phonics\Input\Controls;

return /**
 * Apply the registered session callback.
 *
 * @param Context $context Session registration and lifecycle services.
 * @return void Completes the operation.
 */
static function(Context $context): void {
	$context->factory('SanePhonics.Player', /**
	 * Apply the registered component factory.
	 *
	 * @param array $object Tiled object definition to resolve.
	 * @return mixed Result of this operation.
	 */
		static fn(array $object) => [
			new Body((float)$object['x'], (float)$object['y'], $object['properties']['profile'] ?? 'runner')
			, new Controls
		]
	);

};
