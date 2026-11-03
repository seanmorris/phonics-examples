<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

use DoomPhonics\Component\{Body,Transform,Velocity};
use DoomPhonics\Game\{Collision,Level,LineSpecials,PlayerState,VerticalPhysics};
use Phonics\Ecs\Registry;

/**
 * Verify a generated lift by calling, boarding, riding and leaving it.
 *
 * This bounded macro uses actual switch ranges, floor thinkers, actor carrying
 * and collision. Its graph edge means waiting for a full cycle is permitted.
 */
final class LiftTraversal
{
	/** Return physical witnesses for both directions, leaving the lift at its home height. */
	public static function verify(Level $level,array $lift,array $regions,array $profile): array
	{
		$registry = new Registry;
		$pose = new Transform(...$lift['lowPoint']);
		$pose->angle = $lift['angle'];
		$body = new Body($profile['radius'],$profile['height']);
		$velocity = new Velocity;
		$registry->create($pose,$body,$velocity);
		$specials = new LineSpecials($level,$registry);
		$collision = new Collision($level,$profile['step']);
		$physics = new VerticalPhysics($level,$profile['step']);
		$platform = $level->sectors[$regions[$lift['platform']]];
		$low = $lift['lowPoint'][2]; $high = $lift['highPoint'][2];
		foreach(['low','platform','high'] as $landing)
		if($level->sectors[$regions[$lift[$landing]]]->ceiling - $high < $profile['height'])
		throw new \LogicException('Generated lift landing traps a player at its raised height');
		$tick = static function() use ($specials,$physics,$pose,$velocity,$body): void {
			$pose->beginStep(); $specials->update(1/35); $physics->step($pose,$velocity,$body,1/35);
		};
		$wait = static function(float $height) use ($platform,$tick): void {
			for($i=0;$i<600;$i++) { $tick(); if(abs($platform->floor-$height)<.01) return; }
			throw new \LogicException('Generated lift does not reach its landing');
		};
		$move = static function(array $target) use ($pose,$collision,$body,$tick): void {
			for($i=0;$i<200;$i++)
			{
				$dx=$target[0]-$pose->x; $dy=$target[1]-$pose->y; $distance=hypot($dx,$dy);
				if($distance<.01) return;
				$scale=min(1,8/$distance);
				$collision->move($pose,$body,$dx*$scale,$dy*$scale); $tick();
			}
			throw new \LogicException('Generated lift cannot be boarded or exited');
		};
		if(!$specials->use($pose,new PlayerState)) throw new \LogicException('Generated lift cannot be called from below');
		$wait($low);
		$move($lift['platformPoint']);
		$wait($high);
		if(abs($pose->z-$high)>.01) throw new \LogicException('Generated lift did not carry its passenger');
		$move($lift['highPoint']);
		// Test the return call from the upper landing, not a teleport onto the lift.
		$pose->angle = $lift['angle'] + M_PI;
		if(!$specials->use($pose,new PlayerState)) throw new \LogicException('Generated lift cannot be called from above');
		$wait($low);
		$move($lift['platformPoint']);
		$move($lift['lowPoint']);
		$wait($high);
		// Repeat the complete visit: a one-shot call must not certify a terminal room.
		$pose->angle = $lift['angle'];
		if(!$specials->use($pose,new PlayerState)) throw new \LogicException('Generated lift cannot be called again from below');
		$wait($low); $move($lift['platformPoint']); $wait($high); $move($lift['highPoint']);
		$pose->angle = $lift['angle'] + M_PI;
		if(!$specials->use($pose,new PlayerState)) throw new \LogicException('Generated lift cannot be called again from above');
		$wait($low); $move($lift['platformPoint']); $move($lift['lowPoint']); $wait($high);
		return ['up'=>['start'=>$lift['lowPoint'],'end'=>$lift['highPoint']],
			'down'=>['start'=>$lift['highPoint'],'end'=>[$pose->x,$pose->y,$pose->z]]];
	}
}
