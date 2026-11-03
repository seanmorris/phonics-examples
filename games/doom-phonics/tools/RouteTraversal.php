<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;
require_once __DIR__.'/PlayerWalk.php';

/** Certify complete generated routes using ordinary player commands and runtime physics. */
class RouteTraversal
{
	/** Follow one graph route, returning its physical endpoints for native/browser checks. */
	public static function run(\DoomPhonics\Game\Level $level,array $prototype,array $route,string $initialWater='dry'): array
	{
		$c = $prototype['cells'][$prototype['regions'][$prototype['start']]];
		$center = $c['center'] ?? [($c['x0']+$c['x1'])/2,($c['y0']+$c['y1'])/2];
		$walk = new PlayerWalk($level,[...$center,$c['floor']]);
		$walk->travelStrength = .2;
		$state = $initialWater; $points = [];
		$level->sectors[$prototype['model']]->ceiling=$prototype['waterStates'][$state]??-$prototype['depth'];
		foreach($route as $edge)
		{
			if($edge['effects'])
			{
				$action = array_values(array_filter($prototype['actions'],static fn($a)=>$a['id'] === $edge['mode']))[0];
				$walk->walkTo($action['point'][0],$action['point'][1]);
				$walk->pose->angle = $action['angle'];
				$walk->command->forward = 0;
				if(!$walk->specials->use($walk->pose,new \DoomPhonics\Game\PlayerState)) throw new \RuntimeException('Route pump is unreachable');
				$walk->tick(200); $state = $edge['effects']['water'];
			}
			elseif($edge['mode'] === 'floor-portal')
			{
				[$x,$y] = $edge['witness']['start'];
				$target = $prototype['regions'][$edge['to']];
				// Approach the well center until gravity changes packed map space.
				$walk->command->pitch = 0;
				for($t=0;$t<1200;$t++)
				{
					if($level->sectorAt($walk->pose->x,$walk->pose->y)->index === $target) break;
					$walk->pose->angle = atan2($y-$walk->pose->y,$x-$walk->pose->x);
					$walk->command->forward = min(.15,hypot($x-$walk->pose->x,$y-$walk->pose->y)/100);
					$walk->tick();
				}
				$walk->command->forward = 0;
				$walk->tick(35);
			}
			elseif($edge['mode'] === 'elevator')
			{
				$lift = array_values(array_filter($prototype['lifts'],static fn($l)=>in_array($edge['from'],[$l['low'],$l['platform'],$l['high']],true)))[0];
				if($edge['from'] === $lift['low'])
				{
					$walk->walkTo(...array_slice($lift['lowPoint'],0,2));
					$walk->pose->angle = $lift['angle'];
					$walk->command->forward = 0;
					if(!$walk->specials->use($walk->pose,new \DoomPhonics\Game\PlayerState)) throw new \RuntimeException('Route lift cannot be called');
					for($t=0;$t<600;$t++) { $walk->tick(); if($level->sectors[$prototype['regions'][$lift['platform']]]->floor === (float)$lift['lowPoint'][2]) break; }
					$walk->walkTo(...array_slice($lift['platformPoint'],0,2));
					$walk->command->forward = 0; $walk->velocity->x = $walk->velocity->y = 0;
					$walk->tick(200);
				}
				elseif($edge['from'] === $lift['high'])
				{
					$walk->walkTo(...array_slice($lift['highPoint'],0,2));
					$walk->pose->angle = $lift['angle'] + M_PI;
					$walk->command->forward = 0;
					if(!$walk->specials->use($walk->pose,new \DoomPhonics\Game\PlayerState)) throw new \RuntimeException('Route lift cannot be called from above');
					for($t=0;$t<600;$t++) { $walk->tick(); if($level->sectors[$prototype['regions'][$lift['platform']]]->floor === (float)$lift['lowPoint'][2]) break; }
					$walk->walkTo(...array_slice($lift['platformPoint'],0,2));
					$walk->walkTo(...array_slice($lift['lowPoint'],0,2));
					$walk->command->forward = 0; $walk->velocity->x = $walk->velocity->y = 0;
					$walk->tick(200);
				}
				else $walk->walkTo(...array_slice($lift['highPoint'],0,2));
			}
			else
			{
				$witness = $edge['witness'][$state] ?? $edge['witness'];
				[$x,$y] = $witness['start']; [$tx,$ty] = $witness['end'];
				$walk->walkTo($x,$y);
				if($edge['mode'] === 'portal')
				{
					$pair = array_values(array_filter(array_merge(...$prototype['connections']),static fn($p)=>
						abs($p['point'][0]-$x)+abs($p['point'][1]-$y) < 19))[0];
					$angle = atan2($pair['normal'][1],$pair['normal'][0]);
				}
				else $angle = atan2($ty-$y,$tx-$x);
				if($edge['mode'] === 'door')
				{
					$door=$level->sectors[$prototype['regions'][$edge['to']]];
					$walk->pose->angle=$angle;
					$walk->command->forward=0;
					// An early return can find a door still open from the outward trip.
					// Wait outside for that cycle to finish, then begin a fresh use cycle.
					for($t=0;$t<600 && $door->ceiling>$door->floor;$t++) $walk->tick();
					$walk->specials->use($walk->pose,new \DoomPhonics\Game\PlayerState);
					for($t=0;$t<600;$t++) {
						$walk->tick();
						if($door->ceiling-$door->floor >= 120) break;
					}
					if($door->ceiling-$door->floor < 120) throw new \RuntimeException('Route door does not open');
				}
				try { $walk->enter($prototype['regions'][$edge['to']],$angle,$edge['mode'] === 'swim' ? .75 : 0); }
				catch(\RuntimeException $error) { throw new \RuntimeException($error->getMessage().' on '.$edge['from'].' -> '.$edge['to'].' '.json_encode([$witness,'target'=>[$level->sectors[$prototype['regions'][$edge['to']]]->floor,$level->sectors[$prototype['regions'][$edge['to']]]->ceiling],'source'=>[$level->sectors[$prototype['regions'][$edge['from']]]->floor,$level->sectors[$prototype['regions'][$edge['from']]]->ceiling]]),0,$error); }
			}
			$position = $walk->position();
			if($position['sector'] !== $prototype['regions'][$edge['to']])
			throw new \RuntimeException('Route missed ' . $edge['to'] . ': ' . json_encode($position));
			$points[] = ['region'=>$edge['to'],'mode'=>$edge['mode']] + $position;
		}
		return $points;
	}

}
