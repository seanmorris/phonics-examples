<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

use DoomPhonics\Component\{Body,Transform};
use DoomPhonics\Game\{Collision,Level,LineSpecials,PlayerState};

/**
 * Certify repeatable door entrances using the game's use rays and ceiling thinkers.
 *
 * Both faces must open and close repeatedly. Collision verifies boarding and
 * leaving from each walkable landing; a recessed portal well is not a landing.
 */
final class DoorTraversal
{

	/** Return the measured open ceiling after checking two cycles from each face. */
	public static function verify(Level $level,array $door,array $regions,array $profile): float
	{
		$sector=$level->sectors[$regions[$door['region']]];
		$specials=new LineSpecials($level);
		$collision=new Collision($level,$profile['step']);
		$body=new Body($profile['radius'],$profile['height']);
		$open=$level->lowestNeighborCeiling($sector->index)-4;
		if($sector->ceiling !== $sector->floor || $open-$sector->floor < $profile['height'])
		throw new \LogicException('Generated door has insufficient opening clearance');
		$wait=static function(float $height) use ($specials,$sector): void {
			for($t=0;$t<600;$t++) {
				$specials->update(1/35);
				if(abs($sector->ceiling-$height)<.01) return;
			}
			throw new \LogicException('Generated door does not complete its open/close cycle');
		};
		foreach([1,2] as $visit)
		foreach($door['faces'] as $face)
		{
			[$x,$y]=$face['point'];
			$neighbor=$level->sectorAt($x,$y);
			if($neighbor->index !== $regions[$face['neighbor']])
			throw new \LogicException('Generated door control is outside its landing');
			$pose=new Transform($x,$y,$neighbor->floor,$face['angle']);
			$dx=cos($face['angle'])*56; $dy=sin($face['angle'])*56;
			$walkable=$sector->floor-$pose->z <= $profile['step']
				&& !$level->cameraPortalForPlane($neighbor->index,0)?->link->passable();
			if($walkable && !$collision->move(clone $pose,$body,$dx,$dy))
			throw new \LogicException('Generated closed door does not block movement');
			if(!$specials->use($pose,new PlayerState))
			throw new \LogicException('Generated door cannot be used repeatedly from both sides');
			$wait($open);
			if($walkable)
			{
				if($collision->move($pose,$body,$dx,$dy) || $level->sectorAt($pose->x,$pose->y)->index !== $sector->index)
				throw new \LogicException('Generated open door cannot be entered');
				if($collision->move($pose,$body,-$dx,-$dy) || hypot($pose->x-$x,$pose->y-$y)>.01)
				throw new \LogicException('Generated open door cannot be exited');
			}
			$wait($sector->floor);
		}
		return $open;
	}
}
