<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

require_once __DIR__ . "/PolygonGeometry.php";

/**
 * Encode convex polygon regions, including diagonal walls, as a Hexen map.
 *
 * Regions keep stable sector IDs while BSP cuts may create several subsectors.
 * Boundary linedefs retain their identity across those cuts so portals and use
 * specials are not duplicated. Internal partition lines have the same sector on
 * both sides and never form an invisible collision barrier.
 */
final class PolygonMap
{
	/** Wrap ordered named lumps in a PWAD for the normal archive/map reader. */
	public static function archive(array $lumps): string
	{
		$data = $directory = '';
		foreach($lumps as $name => $bytes)
		{
			$directory .= pack('V2a8',12 + strlen($data),strlen($bytes),$name);
			$data .= $bytes;
		}
		return 'PWAD' . pack('V2',count($lumps),12 + strlen($data)) . $data . $directory;
	}

	/**
	 * Encode cells, boundary specials, internal controls, and [x,y,z,angle,type] things.
	 *
	 * Cell alignTextures anchors two-sided upper/lower spans to the same ceiling
	 * as middle walls; split edges retain horizontal continuity. wallXOffset and
	 * wallYOffset adjust that run. Boundaries can override xOffset, yOffset and
	 * upperUnpegged/lowerUnpegged for moving panels or deliberate trim.
	 */
	public static function build(string $name, array $cells, array $boundaries = [], array $controls = [], array $thingRows = []): array
	{
		if(!preg_match('/^[A-Z][A-Z0-9_]{0,7}$/D', $name) || !$cells)
		throw new \InvalidArgumentException('Invalid map name or empty map');
		foreach($cells as $i => $cell)
		{
			$points = PolygonGeometry::points($cell);
			if(count($points)<3 || PolygonGeometry::area($points)<1 || $cell['floor']>$cell['ceiling'])
			throw new \InvalidArgumentException("Invalid polygon region $i");
			foreach([...array_merge(...$points),$cell['floor'],$cell['ceiling']] as $value)
			if(abs($value-round($value))>1e-6 || $value < -32768 || $value > 32767)
			throw new \InvalidArgumentException("Polygon $i exceeds the integer WAD coordinate grid: $value");
			foreach($points as $j=>$p) {
				$q=$points[($j+1)%count($points)];
				foreach($points as $v)
				if(($q[0]-$p[0])*($v[1]-$p[1])-($q[1]-$p[1])*($v[0]-$p[0])>1e-6)
				throw new \InvalidArgumentException("Region $i is not clockwise and convex");
			}
			foreach(array_slice($cells,0,$i) as $other)
			if(PolygonGeometry::overlaps($points,PolygonGeometry::points($other)))
			throw new \InvalidArgumentException('Packed polygons overlap: ' . $cell['id'] . ' / ' . $other['id']);
		}

		$vertexIds = $vertexRows = [];

		$vertex = static function(float $x, float $y) use (&$vertexIds, &$vertexRows): int {
			if(abs($x-round($x))>1e-6 || abs($y-round($y))>1e-6) throw new \RuntimeException('Fractional BSP vertex');
			$x=(int)round($x); $y=(int)round($y);
			$key = "$x:$y";

			if(isset($vertexIds[$key])) return $vertexIds[$key];

			$vertexIds[$key] = count($vertexRows);
			$vertexRows[] = [$x,$y];

			return $vertexIds[$key];
		};

		$edgeKey = static function(int $a, int $b): string { return $a < $b ? "$a:$b" : "$b:$a"; };

		$edges = $cellSegments = [];
		foreach($boundaries as $boundary)
		foreach([$boundary['from'],$boundary['to']] as $p) $vertex(...$p);
		foreach($cells as $cell)
		foreach(PolygonGeometry::points($cell) as $p) $vertex(...$p);
		$splitEdge = static function(array $a,array $b) use (&$vertexRows): array {
			$dx=$b[0]-$a[0]; $dy=$b[1]-$a[1]; $length=$dx*$dx+$dy*$dy; $points=[];
			foreach($vertexRows as $id=>$p) {
				if(abs($dx*($p[1]-$a[1])-$dy*($p[0]-$a[0]))>1e-6) continue;
				$t=(($p[0]-$a[0])*$dx+($p[1]-$a[1])*$dy)/$length;
				if($t>=-1e-7 && $t<=1+1e-7) $points[$id]=$t;
			}
			asort($points,SORT_NUMERIC); return array_keys($points);
		};
		foreach($cells as $sector=>$cell)
		{
			$points=PolygonGeometry::points($cell);
			foreach($points as $i=>$p)
			{
				$ids=$splitEdge($p,$points[($i+1)%count($points)]);
				for($j=1;$j<count($ids);$j++) {
					$a=$ids[$j-1]; $b=$ids[$j]; $key=$edgeKey($a,$b);
					if(!isset($edges[$key])) $edges[$key]=['start'=>$a,'end'=>$b,'right'=>$sector,'left'=>null,'action'=>0,'args'=>[0,0,0,0,0]];
					else {
						if($edges[$key]['start']!==$b || $edges[$key]['left']!==null) throw new \RuntimeException('Invalid polygon shared edge');
						$edges[$key]['left']=$sector;
					}
					// Keep split portions of one authored wall on the same horizontal texture run.
					$edges[$key]['offsets'][$sector]=(int)round(hypot($vertexRows[$a][0]-$p[0],$vertexRows[$a][1]-$p[1]));
				}
			}
		}

		foreach($boundaries as $boundary)
		{
			$key = $edgeKey($vertex(...$boundary['from']), $vertex(...$boundary['to']));
			if(!isset($edges[$key])) throw new \RuntimeException('Action boundary must match a complete split edge: '.json_encode($boundary));
			$edges[$key]['action'] = $boundary['action'];
			$edges[$key]['args'] = $boundary['args'];
			if(isset($boundary['flags'])) $edges[$key]['flags'] = $boundary['flags'];
			if(isset($boundary['texture'])) $edges[$key]['switch'] = $boundary['texture'];
			foreach(['upperTexture','lowerTexture','xOffset','yOffset','upperUnpegged','lowerUnpegged'] as $field)
			if(isset($boundary[$field])) $edges[$key][$field] = $boundary[$field];
			if($boundary['portal'] ?? false)
			{
				if($edges[$key]['left'] !== null) throw new \RuntimeException('Wall portal requires an exterior boundary');
				$edges[$key]['left'] = $edges[$key]['right'];
			}
		}
		$pieces=[];
		foreach($cells as $sector=>$cell) $pieces[]=['sector'=>$sector,'polygon'=>PolygonGeometry::points($cell)];
		$leaves=$nodeRows=[];
		self::partition($pieces,$leaves,$nodeRows);
		$originalEdges=$edges;
		foreach($leaves as $piece) foreach($piece['polygon'] as $p) $vertex(...$p);
		foreach($leaves as $leaf=>$piece)
		{
			$cellSegments[$leaf]=[]; $polygon=$piece['polygon']; $sector=$piece['sector'];
			foreach($polygon as $i=>$p) {
				$ids=$splitEdge($p,$polygon[($i+1)%count($polygon)]);
				for($j=1;$j<count($ids);$j++) {
					$a=$ids[$j-1]; $b=$ids[$j]; $key=null;
					foreach($originalEdges as $candidate=>$edge) {
						if($edge['right']!==$sector && $edge['left']!==$sector) continue;
						$u=$vertexRows[$edge['start']]; $v=$vertexRows[$edge['end']];
						$dx=$v[0]-$u[0]; $dy=$v[1]-$u[1]; $length=$dx*$dx+$dy*$dy;
						foreach([$a,$b] as $id) {
							$t=($vertexRows[$id][0]-$u[0])*$dx+($vertexRows[$id][1]-$u[1])*$dy;
							if(abs($dx*($vertexRows[$id][1]-$u[1])-$dy*($vertexRows[$id][0]-$u[0]))>1e-6 || $t<0 || $t>$length) continue 2;
						}
						$key=$candidate; break;
					}
					if($key===null) {
						$key=$edgeKey($a,$b);
						$edges[$key] ??= ['start'=>$a,'end'=>$b,'right'=>$sector,'left'=>$sector,'action'=>0,'args'=>[0,0,0,0,0]];
					}
					$edge=$edges[$key]; $u=$vertexRows[$edge['start']]; $v=$vertexRows[$edge['end']];
					$direction=(($v[0]-$u[0])*($vertexRows[$b][0]-$vertexRows[$a][0])+($v[1]-$u[1])*($vertexRows[$b][1]-$vertexRows[$a][1]))<0 ? 1 : 0;
					$cellSegments[$leaf][]=['edge'=>$key,'direction'=>$direction,'start'=>$a,'end'=>$b];
				}
			}
		}
		$controlLines = [];
		foreach($controls as $control)
		{
			$control['from'] = $vertex(...$control['from']);
			$control['to'] = $vertex(...$control['to']);
			$controlLines[] = $control;
		}
		$vertices = '';

		foreach($vertexRows as [$x,$y]) $vertices .= pack('v2', $x & 0xffff, $y & 0xffff);

		$sides = $lines = '';
		$lineIds = [];

		foreach($edges as $key => $edge)
		{
			$lineIds[$key] = intdiv(strlen($lines), 16);
			$twoSided = $edge['left'] !== null;
			$sideBytes=static function(int $sector,bool $front) use ($edge,$cells,$twoSided): string {
				$cell=$cells[$sector]; $wall=$cell['wallTexture'] ?? 'STARTAN3';
				$x=$edge['xOffset'] ?? (($cell['wallXOffset'] ?? 0)+($edge['offsets'][$sector] ?? 0));
				$y=$edge['yOffset'] ?? ($cell['wallYOffset'] ?? 0);
				return pack('v2a8a8a8v',$x & 0xffff,$y & 0xffff,
					$edge['upperTexture'] ?? $wall,$edge['lowerTexture'] ?? $wall,
					$front ? ($edge['switch'] ?? ($twoSided ? '-' : $wall)) : '-', $sector);
			};
			$right = intdiv(strlen($sides), 30);
			$sides .= $sideBytes($edge['right'],true);
			$left = 0xffff;
			if($twoSided)
			{
				$left = intdiv(strlen($sides), 30);
				$sides .= $sideBytes($edge['left'],false);
			}
			$flags=$edge['flags'] ?? ($twoSided ? 4 : 1);
			$cell=$cells[$edge['right']];
			// Top-anchor structural upper/lower spans like the neighboring middle wall.
			// A moving door explicitly retains the normal bottom-pegged upper face.
			foreach(['upperUnpegged'=>8,'lowerUnpegged'=>16] as $field=>$bit)
			{
				$unpegged=$edge[$field] ?? $cell[$field] ?? ($twoSided && ($cell['alignTextures'] ?? false));
				if($unpegged) $flags|=$bit;
				elseif(array_key_exists($field,$edge) || array_key_exists($field,$cell)) $flags&=~$bit;
			}

			$lines .= pack('v3C6v2', ...[
				$edge['start'],$edge['end'],$flags
				, $edge['action'], ...$edge['args'], $right,$left
			]);
		}

		foreach($controlLines as $control)
		{
			$right = intdiv(strlen($sides), 30);
			$sides .= pack('v2a8a8a8v', 0, 0, '-', '-', '-', $control['sector']);
			$left = intdiv(strlen($sides), 30);
			$sides .= pack('v2a8a8a8v', 0, 0, '-', '-', '-', $control['sector']);
			$lines .= pack('v3C6v2', ...[
				$control['from'],$control['to'],4,$control['action'] ?? 57,...$control['args'],$right,$left
			]);
		}

		$segs = $subsectors = '';

		foreach($cellSegments as $segments)
		{
			$first = intdiv(strlen($segs), 12);

			foreach($segments as $segment)
			{
				$edge = $edges[$segment['edge']];
				$start = $segment['start'];
				$end = $segment['end'];
				$segs .= pack('v6', $start, $end, 0, $lineIds[$segment['edge']], $segment['direction'], 0);
			}

			$subsectors .= pack('v2', count($segments), $first);
		}

		$sectors = '';

		foreach($cells as $sector => $cell)
		$sectors .= pack(
			'v2a8a8v3'
			, $cell['floor'] & 0xffff
			, $cell['ceiling'] & 0xffff
			, $cell['floorTexture']
			, $cell['ceilingTexture']
			, $cell['light']
			, 0
			, $cell['tag']
		);

		$things = '';
		foreach($thingRows as [$x,$y,$z,$angle,$type])
		$things .= pack('v7C6', 0,$x & 0xffff,$y & 0xffff,$z & 0xffff,$angle,$type,1799,0,0,0,0,0,0);

		$bounds = [max(array_column($cells,'y1')),min(array_column($cells,'y0')),
			min(array_column($cells,'x0')),max(array_column($cells,'x1'))];
		$nodes = '';

		foreach($nodeRows as [$x,$y,$dx,$dy,$right,$left])
		$nodes .= pack('v14', ...[
			(int)$x & 0xffff,(int)$y & 0xffff,(int)$dx & 0xffff,(int)$dy & 0xffff
			, ...array_map(static fn($value): int => (int)$value & 0xffff, [...$bounds,...$bounds])
			, $right,$left
		]);

		return [
			$name => ''
			, 'THINGS' => $things
			, 'LINEDEFS' => $lines
			, 'SIDEDEFS' => $sides
			, 'VERTEXES' => $vertices
			, 'SEGS' => $segs
			, 'SSECTORS' => $subsectors
			, 'NODES' => $nodes
			, 'SECTORS' => $sectors
			, 'REJECT' => ''
			, 'BLOCKMAP' => ''
			, 'BEHAVIOR' => "ACS\0" . pack('V2', 8, 0)
		];
	}

	/** Partition convex pieces, adding cuts only when whole-region separators are unavailable. */
	private static function partition(array $pieces,array &$leaves,array &$nodes,int $depth=0): int
	{
		if(count($pieces)===1) { $id=count($leaves); $leaves[]=$pieces[0]; return 0x8000|$id; }
		if($depth>128 || count($leaves)>30000) throw new \RuntimeException('Polygon BSP exceeds classic limits');
		$planes=[];
		foreach($pieces as $piece) foreach($piece['polygon'] as $i=>$p) {
			$q=$piece['polygon'][($i+1)%count($piece['polygon'])]; $dx=(int)round($q[0]-$p[0]); $dy=(int)round($q[1]-$p[1]);
			$g=self::gcd(abs($dx),abs($dy)); $dx=intdiv($dx,$g); $dy=intdiv($dy,$g);
			if($dx<0 || ($dx===0 && $dy<0)) { $dx=-$dx; $dy=-$dy; }
			$d=-$dy*$p[0]+$dx*$p[1]; // Clipping can leave an integer as 17391.999999999996. Vertices
			// are rounded on export; node origins must use the identical grid.
			$x=(int)round($p[0]); $y=(int)round($p[1]); $d=-$dy*$x+$dx*$y;
			$planes["$dx:$dy:$d"]=[$x,$y,$dx,$dy,$d];
		}
		$best=null; $score=INF;
		foreach($planes as [$x,$y,$dx,$dy,$d]) {
			$right=$left=$split=0;
			foreach($pieces as $piece) {
				$values=array_map(static fn($p)=>-$dy*$p[0]+$dx*$p[1]-$d,$piece['polygon']);
				$lo=min($values); $hi=max($values);
				if($lo < -1e-6) $right++;
				if($hi > 1e-6) $left++;
				if($lo < -1e-6 && $hi > 1e-6)
				{
					$split++;
					// Classic nodes store integer vertices; choose a different cut instead of rounding walls.
					foreach($piece['polygon'] as $i=>$p)
					{
						$j=($i+1)%count($values); $a=$values[$i]; $b=$values[$j];
						if($a*$b>=-1e-6) continue;
						$q=$piece['polygon'][$j]; $t=$a/($a-$b);
						$px=$p[0]+($q[0]-$p[0])*$t; $py=$p[1]+($q[1]-$p[1])*$t;
						if(abs($px-round($px))>1e-6 || abs($py-round($py))>1e-6) continue 3;
					}
				}
			}
			if(!$right || !$left) continue;
			$cost=$split*count($pieces)*4+abs($right-$left);
			if($cost<$score) { $score=$cost; $best=[$x,$y,$dx,$dy,$d]; }
		}
		if(!$best) throw new \RuntimeException('No separating polygon partition');
		[$x,$y,$dx,$dy,$d]=$best; $right=$left=[];
		foreach($pieces as $piece) {
			$a=PolygonGeometry::clip($piece['polygon'],-$dy,$dx,$d);
			$b=PolygonGeometry::clip($piece['polygon'],$dy,-$dx,-$d);
			if($a) $right[]=['sector'=>$piece['sector'],'polygon'=>$a];
			if($b) $left[]=['sector'=>$piece['sector'],'polygon'=>$b];
		}
		$r=self::partition($right,$leaves,$nodes,$depth+1); $l=self::partition($left,$leaves,$nodes,$depth+1);
		$nodes[]=[$x,$y,$dx,$dy,$r,$l]; return count($nodes)-1;
	}

	/** Reduce integer line directions before serializing classic BSP vectors. */
	private static function gcd(int $a,int $b): int
	{
		while($b) { [$a,$b]=[$b,$a%$b]; } return max(1,$a);
	}
}
