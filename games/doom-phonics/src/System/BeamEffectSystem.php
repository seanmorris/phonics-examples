<?php
declare(strict_types=1);
namespace DoomPhonics\System;

use DoomPhonics\Component\BeamEffect;
use DoomPhonics\Component\RemoteBeam;
use Phonics\Ecs\Registry;

/** Advances and retires transient beam entities without coupling combat to rendering. */
final class BeamEffectSystem
{

	public function update(Registry $registry, float $dt): void
	{
		foreach($registry->query(BeamEffect::class) as $entity)
		{
			$effect = $registry->get($entity, BeamEffect::class);
			$effect->advance($dt);

			if($effect->expired() && !$registry->has($entity, RemoteBeam::class))
			$registry->defer(static function(Registry $registry) use ($entity): void {
				if($registry->alive($entity)) $registry->destroy($entity);
			});
		}
	}
}
