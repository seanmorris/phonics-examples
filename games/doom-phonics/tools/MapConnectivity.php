<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

require_once __DIR__ . '/TraversalGraph.php';
require_once __DIR__ . '/RectangularMap.php';
require_once __DIR__ . '/LiftTraversal.php';
require_once __DIR__ . '/DoorTraversal.php';

use DoomPhonics\Archive\Archive;
use DoomPhonics\Map\MapData;
use DoomPhonics\Component\Body;
use DoomPhonics\Component\Transform;
use DoomPhonics\Component\Velocity;
use DoomPhonics\Game\Collision;
use DoomPhonics\Game\Level;
use DoomPhonics\Game\LineSpecials;
use DoomPhonics\Game\PlayerState;
use DoomPhonics\Game\VerticalPhysics;

/**
 * Compile a small generated map into physically checked, conditional connections.
 *
 * The scope is empty convex polygons with flat floors, one liquid model and
 * settled switch states, repeatable doors and callable lift cycles. Walking openings and portals use game
 * collision; floor portals are tested using game gravity. This is not a general
 * navmesh, timing solver or guarantee for arbitrary obstacle-filled WADs.
 */
final class MapConnectivity
{

	/** Load the emitted bytes through the same map parser and structural setup as the game. */
	public static function level(array $prototype): Level
	{
		$name = $prototype['map'] ?? 'LAYERS';
		$archive = new Archive(RectangularMap::archive($prototype['lumps']),$name . '.WAD');
		return new Level(new MapData($name,$archive->mapLumps($name)));
	}

	/** Summarize entrance-to-exit paths inside each room at each settled water level. */
	public static function rooms(array $layers,TraversalGraph $graph): array
	{
		$result=[];
		foreach($layers as $name=>$layer) {
			if($layer['plane']==='connector') continue;
			$ids=array_fill_keys(array_column($layer['cells'],'id'),true); $entrances=[];
			foreach($layer['anchors'] as $port=>$anchor) {
				[$x,$y]=$anchor['point']; [$nx,$ny]=$anchor['normal'];
				foreach($layer['cells'] as $cell)
				if(PolygonGeometry::contains(PolygonGeometry::points($cell),$x-$nx*18,$y-$ny*18)) {
					$entrances[$port]=$cell['id']; break;
				}
			}
			$edges=array_values(array_filter($graph->edges,static fn($e)=>isset($ids[$e['from']],$ids[$e['to']]) && !$e['effects']));
			$routes=$oneWay=[];
			foreach(['dry','shallow','full'] as $water) {
				$adjacency=[];
				foreach($edges as $edge) if(!isset($edge['requires']['water']) || in_array($water,$edge['requires']['water'],true))
				$adjacency[$edge['from']][$edge['to']]=$edge['mode'];
				foreach($adjacency as $from=>$targets) foreach($targets as $to=>$mode)
				if(!isset($adjacency[$to][$from])) $oneWay[$water][]=compact('from','to','mode');
				foreach($entrances as $port=>$region) {
					$queue=[$region]; $seen=[$region=>[]];
					for($i=0;$i<count($queue);$i++) foreach($adjacency[$queue[$i]]??[] as $to=>$mode) {
						if(isset($seen[$to])) continue;
						$seen[$to]=array_merge($seen[$queue[$i]],[$mode]); $queue[]=$to;
					}
					foreach($entrances as $exit=>$target) if($exit!==$port)
					$routes[$water][$port][$exit]=isset($seen[$target]) ? array_values(array_unique($seen[$target])) : null;
				}
			}
			$result[$name]=compact('entrances','routes','oneWay');
		}
		return $result;
	}

	/** Build a graph with runtime-tested opening witnesses for the selected player profile. */
	public static function compile(array $prototype, array $profile = []): TraversalGraph
	{
		$profile += ['radius' => 16,'height' => 56,'step' => 24,'drop' => 256,'swim' => true];
		if($profile['radius'] <= 0 || $profile['height'] <= 0 || $profile['step'] < 0 || $profile['drop'] < 0)
		throw new \InvalidArgumentException('Invalid traversal dimensions');
		$level = self::level($prototype);
		$graph = new TraversalGraph(['water' => ['dry','shallow','full']]);
		$names = array_flip($prototype['regions']);
		foreach($prototype['regions'] as $name => $id) $graph->region($name,$prototype['cells'][$id]);
		$collision = new Collision($level,$profile['step']);
		$waterStates = $prototype['waterStates'] ?? ['dry' => -$prototype['depth'],'shallow' => -intdiv($prototype['depth'],2),'full' => 0];
		$doorRegions=[];
		foreach($prototype['doors'] ?? [] as $door)
		{
			$id=$prototype['regions'][$door['region']];
			$height=DoorTraversal::verify($level,$door,$prototype['regions'],$profile);
			// Probe passage geometry at the height just proven attainable by Use.
			$level->sectors[$id]->ceiling=$height;
			$doorRegions[$id]=true;
		}
		$fits = static function(int $id) use ($prototype,$profile,$level): bool {
			$c = $prototype['cells'][$id];
			return min($c['x1'] - $c['x0'],$c['y1'] - $c['y0']) > 2 * $profile['radius']
				&& $level->sectors[$id]->ceiling - $c['floor'] >= $profile['height'];
		};
		foreach($level->map->linedefs as $lineId => $line)
		{
			$sides = $level->sides($lineId);
			$a = $sides['front']; $b = $sides['back'];
			if($b === null || $a === $b || !isset($names[$a],$names[$b])) continue;
			$p = $level->map->vertices[$line['from']]; $q = $level->map->vertices[$line['to']];
			$length = hypot($q['x'] - $p['x'],$q['y'] - $p['y']);
			if($length <= 2 * $profile['radius'] || !$fits($a) || !$fits($b)) continue;
			$x = ($p['x'] + $q['x']) / 2; $y = ($p['y'] + $q['y']) / 2;
			$nx = ($q['y'] - $p['y']) / $length; $ny = ($p['x'] - $q['x']) / $length;
			$inset = $profile['radius'] + 2;
			foreach([[$a,$b,$nx,$ny],[$b,$a,-$nx,-$ny]] as [$from,$to,$dx,$dy])
			{
				// An open floor is a falling region, not a fictitious standing bridge.
				if($level->cameraPortalForPlane($from,0)?->link->passable()) continue;
				$start = [$x + $dx * $inset,$y + $dy * $inset];
				$end = [$x - $dx * $inset,$y - $dy * $inset];
				// A thin convex fragment may share its floor with several neighbors. A
				// witness must actually end in the region the graph claims it enters.
				$located=false;
				foreach([.5,.25,.75] as $fraction) foreach([$inset,8,2,.5] as $distance) {
					$wx=$p['x']+($q['x']-$p['x'])*$fraction; $wy=$p['y']+($q['y']-$p['y'])*$fraction;
					$start=[$wx+$dx*$distance,$wy+$dy*$distance]; $end=[$wx-$dx*$distance,$wy-$dy*$distance];
					if($level->sectorAt(...$start)->index===$from && $level->sectorAt(...$end)->index===$to) { $located=true; break 2; }
				}
				if(!$located) continue;
				$source = $level->sectors[$from]; $destination = $level->sectors[$to];
				$floor = $source->floorZ(...$start); $target = $destination->floorZ(...$end);
				if($floor - $target > $profile['drop']) continue;
				$allowed = $witnesses = [];
				foreach($waterStates as $state => $surface)
				{
					$level->sectors[$prototype['model']]->ceiling = $surface;
					$z = $floor;
					$swimming = false;
					if($target - $z > $profile['step'])
					{
						if(!$profile['swim'] || $source->waterSurface(...[...$start,$z,$profile['height']]) === null) continue;
						$z = $surface - min(16,$profile['step']);
						$swimming = true;
					}
					if($target - $z > $profile['step'] || $source->ceilingZ(...$start) - $z < $profile['height']) continue;
					$pose = new Transform(...[...$start,$z]);
					$body = new Body($profile['radius'],$profile['height'],grounded: !$swimming);
					$hit = $collision->move($pose,$body,$end[0] - $start[0],$end[1] - $start[1]);
					if($hit || hypot($pose->x - $end[0],$pose->y - $end[1]) > .01) continue;
					$allowed[] = $state;
					$witnesses[$state] = ['start' => [...$start,$z],'end' => [...$end,$target],'line' => $lineId];
				}
				if(!$allowed) continue;
				$mode = $target - $floor > $profile['step'] ? 'swim' : ($floor - $target > $profile['step'] ? 'drop' : 'walk');
				$graph->connect($names[$from],$names[$to],isset($doorRegions[$to]) ? 'door' : $mode,count($allowed) === 3 ? [] : ['water' => $allowed],[], $witnesses);
			}
		}
		$level->sectors[$prototype['model']]->ceiling = -$prototype['depth'];
		foreach($prototype['connections'] as [$a,$b])
		foreach([[$a,$b],[$b,$a]] as [$from,$to])
		{
			[$x,$y,$z] = $from['point']; [$nx,$ny] = $from['normal'];
			[$tx,$ty,$tz] = $to['point']; [$tnx,$tny] = $to['normal'];
			$inset = $profile['radius'] + 2;
			$start = [$x - $nx * $inset,$y - $ny * $inset,$z];
			$end = [$tx - $tnx * $inset,$ty - $tny * $inset,$tz];
			$sourceId = $level->sectorAt($start[0],$start[1])->index;
			$targetId = $level->sectorAt($end[0],$end[1])->index;
			if(!$fits($sourceId) || !$fits($targetId) || $from['width'] <= 2 * $profile['radius']) continue;
			$pose = new Transform(...$start);
			$collision->moveAndSlide($pose,new Body($profile['radius'],$profile['height']),$nx * 2 * $inset,$ny * 2 * $inset);
			(new LineSpecials($level))->cross($start[0],$start[1],$pose,new PlayerState);
			if(hypot($pose->x - $end[0],$pose->y - $end[1]) > .1 || abs($pose->z - $tz) > .1)
			throw new \RuntimeException('Emitted wall portal does not realize its spatial pin');
			$graph->connect($names[$sourceId],$names[$targetId],'portal',[],[],['start' => $start,'end' => $end]);
		}
		foreach($prototype['falls'] ?? [['upper.well','building.well']] as [$upperName,$lowerName])
		{
			$upper = $prototype['cells'][$prototype['regions'][$upperName]];
			$lower = $prototype['cells'][$prototype['regions'][$lowerName]];
			$link = $level->cameraPortalForPlane($prototype['regions'][$upperName],0)?->link;
			$landingHeight = $link?->cameraZ($upper['floor'],$level) ?? $upper['floor'];
			$dropLimit=$profile['drop'];
			foreach($prototype['verticalConnections']??[] as $connection)
			if($connection['from']===$upperName && $connection['kind']==='gallery') $dropLimit=max($dropLimit,$lower['ceiling']-$lower['floor']);
			if($fits($prototype['regions'][$upperName]) && $fits($prototype['regions'][$lowerName])
				&& $landingHeight - $lower['floor'] <= $dropLimit)
			{
				$pose = new Transform(($upper['x0'] + $upper['x1']) / 2,($upper['y0'] + $upper['y1']) / 2,$upper['floor']);
				$start = [$pose->x,$pose->y,$pose->z];
				$velocity = new Velocity;
				$body = new Body($profile['radius'],$profile['height'],grounded: false);
				$physics = new VerticalPhysics($level,$profile['step']);
				for($i = 0; $i < 100; $i++) $physics->step($pose,$velocity,$body,1 / 35);
				// A stacked gallery can pass straight through intermediate apertures.
				// Only the actual grounded destination is a navigable landing.
				$destination=$lowerName; $seen=[];
				while($level->cameraPortalForPlane($prototype['regions'][$destination],0)?->link->passable()) {
					if(isset($seen[$destination])) throw new \RuntimeException('Cyclic floor portal stack');
					$seen[$destination]=true; $next=null;
					foreach($prototype['falls'] as [$a,$b]) if($a===$destination) { $next=$b; break; }
					if($next===null) throw new \RuntimeException('Unregistered gallery drop');
					$destination=$next;
				}
				if($level->sectorAt($pose->x,$pose->y)->index !== $prototype['regions'][$destination] || !$body->grounded)
				throw new \RuntimeException("Floor portal $upperName has no safe landing in $destination");
				$graph->connect($upperName,$destination,'floor-portal',[],[],['start' => $start,'end' => [$pose->x,$pose->y,$pose->z]]);
			}
		}
		foreach($prototype['lifts'] ?? [] as $lift)
		{
			if(!$fits($prototype['regions'][$lift['platform']]) || !$fits($prototype['regions'][$lift['low']])) continue;
			$witness = LiftTraversal::verify($level,$lift,$prototype['regions'],$profile);
			$graph->connect($lift['low'],$lift['platform'],'elevator',[],[],$witness['up']);
			$graph->connect($lift['platform'],$lift['high'],'elevator',[],[],$witness['up']);
			$graph->connect($lift['high'],$lift['low'],'elevator',[],[],$witness['down']);
		}
		foreach($prototype['actions'] as $action)
		{
			$id = $prototype['regions'][$action['region']];
			if(!$fits($id)) continue;
			if($level->sectorAt($action['point'][0],$action['point'][1])->index !== $id)
			throw new \RuntimeException('Control is assigned to the wrong traversal region');
			// Execute the actual repeatable switch, including its settled destination.
			$target = $waterStates[$action['effects']['water']];
			$level->sectors[$prototype['model']]->ceiling = $target === 0 ? -$prototype['depth'] : 0;
			$specials = new LineSpecials($level);
			if(!$specials->use(new Transform(...[...$action['point'],$action['angle']]),new PlayerState))
			throw new \RuntimeException('Generated water control cannot be used');
			for($i = 0; $i < 200; $i++) $specials->update(1 / 35);
			if(abs($level->sectors[$prototype['model']]->ceiling - $target) > .01)
			throw new \RuntimeException('Water control does not produce its declared state');
			$graph->connect($action['region'],$action['region'],$action['id'],[],$action['effects'],['point' => $action['point']]);
		}
		self::certifyInteriors($level,$prototype,$graph,$profile,$waterStates);
		return $graph;
	}
	/** Verify that crossing witnesses can be joined continuously inside their convex region.
	 * Pillars are solid voids and are decomposed into separate regions, so a graph
	 * route must physically go around them. A local crossing alone is insufficient.
	 */
	private static function certifyInteriors(Level $level,array $prototype,TraversalGraph $graph,array $profile,array $waterStates): void
	{
		$collision=new Collision($level,$profile['step']);
		$blocked=[]; $moving=[]; foreach($prototype['lifts']??[] as $lift) $moving[$lift['platform']]=true;
		foreach($waterStates as $state=>$surface) {
			$level->sectors[$prototype['model']]->ceiling=$surface;
			$points=[];
			foreach($graph->edges as $edge) {
				if(isset($edge['requires']['water']) && !in_array($state,$edge['requires']['water'],true)) continue;
				if($edge['mode']==='elevator') continue;
				$w=$edge['witness'][$state]??$edge['witness'];
				if(isset($w['start'])) $points[$edge['from']][]=$w['start'];
				if(isset($w['end'])) $points[$edge['to']][]=$w['end'];
				if(isset($w['point'])) $points[$edge['from']][]=$w['point'];
			}
			foreach($points as $name=>$entries) {
				$id=$prototype['regions'][$name]; $cell=$prototype['cells'][$id];
				if(isset($moving[$name]) || $level->cameraPortalForPlane($id,0)?->link->passable()) continue;
				$entries=array_values(array_unique($entries,SORT_REGULAR));
				foreach($entries as $i=>$a) foreach($entries as $j=>$b) {
					if($i===$j) continue;
					$z=max($cell['floor'],$a[2]??$cell['floor'],$b[2]??$cell['floor']);
					if($z+$profile['height']>$level->sectors[$id]->ceiling) continue;
					$pose=new Transform($a[0],$a[1],$z);
					$hit=$collision->move($pose,new Body($profile['radius'],$profile['height']),$b[0]-$a[0],$b[1]-$a[1]);
					if($hit || hypot($pose->x-$b[0],$pose->y-$b[1])>.01)
					$blocked[$name][$state]=true;
				}
			}
		}
		// A different player profile may not fit an otherwise valid map. Keep its
		// graph conservative instead of treating incompatible witnesses as a clique.
		foreach($graph->edges as $i=>&$edge) if(isset($blocked[$edge['from']])) {
			$allowed=$edge['requires']['water']??array_keys($waterStates);
			$allowed=array_values(array_diff($allowed,array_keys($blocked[$edge['from']])));
			if(!$allowed) { unset($graph->edges[$i]); continue; }
			$edge['requires']['water']=$allowed;
		} unset($edge);
		$graph->edges=array_values($graph->edges);

	}

}
