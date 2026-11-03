<?php
declare(strict_types=1);
use Phonics\Package\Context;
use SanePhonics\Physics\Body;
use SanePhonics\Physics\MapTerrain;
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

	$map = $context->loader->map($context->world['start']);
	$terrain = MapTerrain::build($map);
	$movement = new Platformer($terrain);

	$context->system('sane.platformer', /**
	 * Apply the registered session callback.
	 *
	 * @param mixed $entities Entity registry shared by the session.
	 * @param float $dt Elapsed simulation time in seconds.
	 * @return mixed Result of this operation.
	 */
		static function($entities, float $dt) use ($movement): void {
			foreach($entities->query(Body::class) as $id)
			{
				if($entities->has($id, Controls::class))
				$movement->step($entities->get($id, Body::class), $entities->get($id, Controls::class), $dt);
			}
		}
	);
};
