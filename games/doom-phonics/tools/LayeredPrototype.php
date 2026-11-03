<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

require_once __DIR__ . '/SpatialLayout.php';
require_once __DIR__ . '/RectangularMap.php';
require_once __DIR__ . '/Staircase.php';
require_once __DIR__ . '/PortalRecess.php';
require_once __DIR__ . '/FloodBasin.php';

/**
 * Author a small basement/cavern/building route using separately pinned layers.
 *
 * Each rectangle is one convex traversal region, not an assumption that its whole
 * room is connected. A flood fill selects connected low rooms, including portal
 * passages. Changing the depth extends the ascent without moving upstairs.
 */
final class LayeredPrototype
{

	/** Produce map lumps and shared geometry/state metadata for navigation validation. */
	public static function build(int $depth = 192): array
	{
		if($depth < 64 || $depth > 240) throw new \InvalidArgumentException('Prototype depth must be 64–240 units');
		$anchor = static fn(array $point, array $normal, int $width = 128): array =>
			['point' => $point,'normal' => $normal,'size' => [$width,128]];
		$cell = static fn(string $id, int $x0, int $y0, int $x1, int $y1, int $floor = 0, int $ceiling = 192, int $tag = 0): array =>
			compact('id','x0','y0','x1','y1','floor','ceiling','tag')
			+ ['light' => 192,'floorTexture' => 'FLOOR0_1','ceilingTexture' => 'CEIL3_5'];
		$ring = static function(string $prefix, int $floor, int $ceiling, int $tag) use ($cell): array {
			return [
				$cell("$prefix.south",0,0,384,128,$floor,$ceiling),
				$cell("$prefix.west",0,128,128,256,$floor,$ceiling),
				$cell("$prefix.well",128,128,256,256,$floor,$ceiling,$tag),
				$cell("$prefix.east",256,128,384,256,$floor,$ceiling),
				$cell("$prefix.north",0,256,384,384,$floor,$ceiling)
			];
		};
		$layers = [
			'cavern' => ['anchors' => [
				'lower-west' => $anchor([0,128,0],[-1,0,0],256),
				'lower-east' => $anchor([1024,128,0],[1,0,0],256),
				'upper-west' => $anchor([0,320,$depth],[-1,0,0]),
				'upper-east' => $anchor([1024,320,$depth],[1,0,0])
			], 'cells' => [
				$cell('cavern.lower',0,0,1024,256,0,$depth + 192),
				$cell('cavern.upper',0,256,1024,384,$depth,$depth + 192)
			]],
			'basement' => ['anchors' => ['east' => $anchor([512,128,0],[1,0,0],256)],
				'cells' => [$cell('basement',0,0,512,256)]],
			'pump' => ['anchors' => ['west' => $anchor([0,128,0],[-1,0,0],256)],
				'cells' => [$cell('pump',0,0,256,256)]],
			'building' => ['anchors' => [
				'east' => $anchor([512,320,0],[1,0,0]),
				'landing' => $anchor([384,1152,192],[-1,0,0]),
				'well' => $anchor([192,192,192],[0,0,1])
			], 'cells' => [...$ring('building',0,160,110),
				$cell('building.entry',384,0,512,384,0,384)]],
			'upper' => ['anchors' => [
				'landing' => $anchor([384,1152,0],[1,0,0]),
				'well' => $anchor([192,192,0],[0,0,-1]),
				'balcony' => $anchor([0,64,0],[-1,0,0])
			], 'cells' => [...$ring('upper',0,192,111),
				$cell('upper.hall',256,384,384,1216)]],
			'courtyard' => ['anchors' => ['west' => $anchor([0,64,0],[-1,0,0])],
				'cells' => [$cell('courtyard',0,0,512,512,0,384)]],
			'balcony' => ['anchors' => ['east' => $anchor([256,64,0],[1,0,0])],
				'cells' => [$cell('balcony',0,0,256,384,0,256)]]
		];
		// The lower well recesses its ceiling 32 units into the upstairs floor slab.
		$layers['building']['cells'][2]['ceiling'] = 192;
		foreach(Staircase::treads(384,384,0,192,16,64,128,'north') as $i => $tread)
		$layers['building']['cells'][] = array_replace($cell('building.step-' . ($i + 1),0,0,1,1,0,384),$tread);
		$layers['building']['cells'][] = $cell('building.landing',384,1088,512,1216,192,384);
		foreach(['courtyard','balcony'] as $outdoor)
		$layers[$outdoor]['cells'][0]['ceilingTexture'] = 'F_SKY1';
		$layers['cavern']['cells'][0]['light'] = 160;
		$layers['cavern']['cells'][1]['light'] = 176;

		$pins = [
			['cavern','lower-west','basement','east'],
			['cavern','lower-east','pump','west'],
			['cavern','upper-west','building','east'],
			['cavern','upper-east','courtyard','west'],
			['building','landing','upper','landing'],
			['building','well','upper','well'],
			['upper','balcony','balcony','east']
		];
		$positions = SpatialLayout::solve($layers,$pins,'cavern',[0,0,-$depth]);
		$cells = $regions = [];
		foreach(array_keys($layers) as $index => $name)
		{
			// Architectural height is retained; only horizontal placement is packed.
			$layers[$name]['architectural'] = $positions[$name];
			$layers[$name]['packed'] = [$index * 2048,0,$positions[$name][2]];
			foreach($layers[$name]['cells'] as $local)
			{
				[$ox,$oy,$oz] = $layers[$name]['packed'];
				$mapped = $local;
				foreach(['x0','x1'] as $field) $mapped[$field] += $ox;
				foreach(['y0','y1'] as $field) $mapped[$field] += $oy;
				foreach(['floor','ceiling'] as $field) $mapped[$field] += $oz;
				$mapped['layer'] = $name;
				$regions[$local['id']] = count($cells);
				$cells[] = $mapped;
			}
		}
		$boundaries = $connections = [];
		$lineId = 10;
		foreach($pins as [$from,$fromAnchor,$to,$toAnchor])
		{
			$a = $layers[$from]['anchors'][$fromAnchor];
			$b = $layers[$to]['anchors'][$toAnchor];
			if($a['normal'][2]) continue;
			$ap = SpatialLayout::point($a['point'],$layers[$from]['packed']);
			$bp = SpatialLayout::point($b['point'],$layers[$to]['packed']);
			$ac = $cells[PortalRecess::sector($cells,$ap,$a['normal'])];
			$bc = $cells[PortalRecess::sector($cells,$bp,$b['normal'])];
			$floor = max($ac['floor'],$bc['floor']);
			$ceiling = min($ac['ceiling'],$bc['ceiling']) - 32;
			PortalRecess::wall($cells,$ap,$a['normal'],$a['size'][0],"$from.$fromAnchor-portal",$floor,$ceiling);
			PortalRecess::wall($cells,$bp,$b['normal'],$b['size'][0],"$to.$toAnchor-portal",$floor,$ceiling);
			$pair = [];
			foreach([[$from,$a,$lineId,$lineId + 1],[$to,$b,$lineId + 1,$lineId]] as [$layer,$frame,$own,$other])
			{
				[$x,$y,$z] = SpatialLayout::point($frame['point'],$layers[$layer]['packed']);
				[$nx,$ny] = $frame['normal'];
				$half = intdiv($frame['size'][0],2);
				$boundary = ['from' => [$x - $ny * $half,$y + $nx * $half],
					'to' => [$x + $ny * $half,$y - $nx * $half],
					'action' => 156,'args' => [$other,$own,1,0,0],'portal' => true];
				$boundaries[] = $boundary;
				$pair[] = ['layer' => $layer,'point' => [$x,$y,$z],'normal' => $frame['normal'],
					'width' => $frame['size'][0],'lineId' => $own];
			}
			$connections[] = $pair;
			$lineId += 2;
		}
		$regions = array_flip(array_column($cells,'id'));
		$waterLinks = [];
		foreach($connections as [$a,$b])
		$waterLinks[] = [PortalRecess::sector($cells,$a['point'],$a['normal']),PortalRecess::sector($cells,$b['point'],$b['normal'])];
		$waterSectors = FloodBasin::find($cells,$regions['cavern.lower'],0,$waterLinks);
		foreach($waterSectors as $sector) $cells[$sector]['tag'] = 100;
		// Keep the report's local layout synchronized with the split packed geometry.
		foreach($layers as $name => &$layer)
		{
			$layer['cells'] = [];
			foreach($cells as $mapped)
			if($mapped['layer'] === $name)
			{
				foreach(['x0','x1'] as $field) $mapped[$field] -= $layer['packed'][0];
				foreach(['y0','y1'] as $field) $mapped[$field] -= $layer['packed'][1];
				foreach(['floor','ceiling'] as $field) $mapped[$field] -= $layer['packed'][2];
				$layer['cells'][] = $mapped;
			}
		}
		unset($layer);
		$controls = [];
		foreach([
			['building.well',160,[110,6,1,0,0]],['upper.well',160,[110,6,1,1,0]],
			['upper.well',176,[111,6,0,0,0]],['building.well',176,[111,6,0,1,0]]
		] as [$id,$y,$args])
		{
			$sector = $regions[$id];
			$ox = $cells[$sector]['x0'] - 128;
			$controls[] = ['sector' => $sector,'from' => [160 + $ox,$y],'to' => [224 + $ox,$y],'args' => $args];
		}
		$model = count($cells);
		$cells[] = $cell('water-model',15000,0,15064,64,-$depth,-$depth,200);
		$cells[$model]['floorTexture'] = $cells[$model]['ceilingTexture'] = 'P3WATER';
		$boundaries[] = ['from' => [15000,0],'to' => [15000,64],'action' => 160,'args' => [100,6,2,166,0]];
		$pumpX = $layers['pump']['packed'][0];
		$actions = [];
		foreach([['fill',32,96,0,0,'SW1COMP'],['drain',160,224,$depth,1,'SW1BRCOM']] as [$id,$y0,$y1,$height,$negative,$texture])
		{
			$boundaries[] = ['from' => [$pumpX + 256,$y0],'to' => [$pumpX + 256,$y1],
				'action' => 47,'args' => [200,16,$height,$negative,0],'flags' => 1537,'texture' => $texture];
			$actions[] = ['id' => $id,'region' => 'pump','point' => [$pumpX + 224,($y0 + $y1) / 2,-$depth],
				'angle' => 0,'effects' => ['water' => $id === 'fill' ? 'full' : 'dry']];
		}
		$spawn = [$layers['basement']['packed'][0] + 128,128,0,0,1];
		$things = [$spawn,[$layers['balcony']['packed'][0] + 128,256,0,0,2013]];
		$lumps = RectangularMap::build('LAYERS',$cells,$boundaries,$controls,$things);
		return compact('layers','pins','positions','cells','regions','connections','actions','model','lumps','depth','waterSectors');
	}
}
