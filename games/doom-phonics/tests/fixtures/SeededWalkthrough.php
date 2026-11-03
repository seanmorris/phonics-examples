<?php
declare(strict_types=1);
namespace DoomPhonics\Tests;

require_once __DIR__ . '/LayeredWalkthrough.php';
require_once dirname(__DIR__,2).'/tools/RouteTraversal.php';

/**
 * Exercise complete generated routes through ordinary fixed-step movement.
 *
 * The fixture starts once, then walks, uses pumps, swims and rides the generated
 * lifts. Region witnesses guide the player; positions are never patched mid-route.
 */
final class SeededWalkthrough extends \DoomPhonics\Tools\RouteTraversal
{
	/** Swim through a flooded ceiling seam and walk onto the destination room's rim. */
	public static function swimPortal(\DoomPhonics\Game\Level $level,array $prototype,array $portal): array
	{
		$source=$prototype['cells'][$prototype['regions'][$portal['to']]];
		$target=$prototype['cells'][$prototype['regions'][$portal['from']]];
		$walk=new LayeredWalkthrough($level,[...$source['center'],$source['ceiling']-45]);
		// Other routes may already have filled this live level. Drain through its
		// control first so every ascent exercises an actual refill operation.
		$drain=array_values(array_filter($prototype['actions'],static fn($a)=>$a['id']==='drain'))[0];
		$control=new \DoomPhonics\Component\Transform(...[...$drain['point'],$drain['angle']]);
		$walk->specials->use($control,new \DoomPhonics\Game\PlayerState);
		for($i=0;$i<200;$i++) $walk->specials->update(1/35);
		$fill=array_values(array_filter($prototype['actions'],static fn($a)=>$a['id']==='fill'))[0];
		$control=new \DoomPhonics\Component\Transform(...[...$fill['point'],$fill['angle']]);
		if(!$walk->specials->use($control,new \DoomPhonics\Game\PlayerState)) throw new \RuntimeException('Flood control did not activate');
		for($i=0;$i<200;$i++) $walk->specials->update(1/35);
		$walk->command->forward=.6; $walk->command->pitch=1.3; $walk->velocity->z=100;
		$frames=[];
		// Turn in a small circle to stay within the aperture during the ascent.
		for($i=0;$i<50;$i++) {
			$walk->pose->angle=$i*M_PI/4; $walk->tick();
			$frames[]=$walk->position()+['previousZ'=>$walk->pose->previousZ,'vz'=>$walk->velocity->z];
		}
		$room=preg_replace('/\.(shaft|gallery-aperture)$/','.core',$portal['from']);
		$rim=$prototype['cells'][$prototype['regions'][$room]];
		// Keep swimming upward while approaching the recessed escape ledge.
		$walk->walkTo($rim['center'][0],$rim['center'][1],.75); $walk->command->forward=0; $walk->tick(20);
		return ['frames'=>$frames,'end'=>$walk->position(),'rim'=>$prototype['regions'][$room],
			'target'=>$prototype['regions'][$portal['from']],'source'=>$prototype['regions'][$portal['to']]];
	}

	/** Follow each successive curved tread without teleporting or patching height. */
	public static function spiral(\DoomPhonics\Game\Level $level,array $layer): array
	{
		[$ox,$oy,$oz] = $layer['packed'];
		$first = $layer['cells'][0];
		$walk = new LayeredWalkthrough($level,[$ox+$first['center'][0],$oy+$first['center'][1],$oz]);
		$trace = [];
		foreach($layer['cells'] as $cell)
		{
			$walk->walkTo($ox+$cell['center'][0],$oy+$cell['center'][1]);
			$trace[] = $walk->position();
			if(abs($walk->pose->z-($oz+$cell['floor']))>.01) throw new \RuntimeException('Spiral tread cannot be climbed');
		}
		return $trace;
	}
}
