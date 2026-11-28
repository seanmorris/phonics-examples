<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Edits;

/** Reconcile roads surveyed independently by the parents of the same destination. */
final class JourneyMerge
{
	/** Work backwards only through ordinary record breaks. Leave forks and other junctions intact. */
	private static function arm(array $roads,int $last,array $starts,array $ends): array
	{
		$chain=[];
		while(!isset($chain[$last])) {
			$chain[$last]=$last;
			$key=JourneySigns::node($roads[$last]['points'][0]);
			if(count($starts[$key])!==1 || count($ends[$key] ?? [])!==1) break;
			$last=$ends[$key][0];
		}
		return array_reverse(array_values($chain));
	}

	private static function delta(array $a,array $b): array
	{
		return [$a[0]-$b[0],$a[1]-$b[1],$a[2]-$b[2]];
	}

	private static function length(array $v): float { return hypot($v[0],$v[2]); }

	private static function curve(array $road,int $i): array
	{
		$points=$road['points']; $handles=$road['handles'] ?? [];
		$a=$points[$i]; $d=$points[$i+1];
		$out=Edits::roadHandles($points,$handles,$i)['out'];
		$in=Edits::roadHandles($points,$handles,$i+1)['in'];
		return [$a,[$a[0]+$out[0],$a[1]+$out[1],$a[2]+$out[2]],
			[$d[0]+$in[0],$d[1]+$in[1],$d[2]+$in[2]],$d];
	}

	/** Bound the sample spacing by the control polygon speed, including tight curves between knots. */
	private static function samples(array $roads,array $chain,float $spacing): \Generator
	{
		$samples=[]; $distance=0.; $previous=null;
		foreach($chain as $id) {
			$road=$roads[$id];
			for($i=0;$i<count($road['points'])-1;$i++) {
				$curve=self::curve($road,$i);
				[$a,$b,$c,$d]=$curve;
				$ab=self::delta($b,$a); $bc=self::delta($c,$b); $cd=self::delta($d,$c);
				$n=max(1,(int)ceil(3.*max(self::length($ab),self::length($bc),self::length($cd))/$spacing));
				for($j=$previous===null ? 0 : 1;$j<=$n;$j++) {
					$t=(float)$j/$n; $u=1.-$t;
					$p=$v=[];
					for($axis=0;$axis<3;$axis++) {
						$p[]=$u**3*$a[$axis]+3.*$t*$u*($u*$b[$axis]+$t*$c[$axis])+$t**3*$d[$axis];
						$v[]=$u*$u*$ab[$axis]+2.*$t*$u*$bc[$axis]+$t*$t*$cd[$axis];
					}
					if($previous!==null) $distance+=self::length(self::delta($p,$previous));
					$previous=$p; $speed=self::length($v);
					if($speed>.0001) $samples[]=['id'=>$id,'span'=>$i,'t'=>$t,'p'=>$p,
						'dir'=>array_map(static fn($x)=>$x/$speed,$v),'s'=>$distance];
					if(($j&63)===0) yield;
				}
				yield;
			}
		}
		return $samples;
	}

	/** A short tangent-matched lane change should travel forward and respect the surveyed grade. */
	private static function connector(array $from,array $to,array $road): ?array
	{
		$d=self::delta($to['p'],$from['p']); $run=self::length($d);
		if($run<1.) return null;
		foreach([$from,$to] as $end) {
			$v=$end['dir'];
			if($v[0]*$d[0]+$v[2]*$d[2]<.6*$run) return null;
		}
		$h=$run/3.;
		$out=array_map(static fn($v)=>$v*$h,$from['dir']);
		$in=array_map(static fn($v)=>-$v*$h,$to['dir']);
		// Bound grade along the whole cubic by its control edges projected onto the chord.
		$mid=[$d[0]+$in[0]-$out[0],$d[1]+$in[1]-$out[1],$d[2]+$in[2]-$out[2]];
		foreach([$out,$mid,array_map(static fn($x)=>-$x,$in)] as $edge) {
			if(abs($edge[1])>.105*($edge[0]*$d[0]+$edge[2]*$d[2])/$run) return null;
		}
		$road['points']=[$from['p'],$to['p']]; $road['handles']=[['out'=>$out],['in'=>$in]];
		return $road;
	}

	/** Find the first forward, at-grade encounter on the joining arm. A spatial grid avoids comparing every pair of samples. */
	private static function meeting(array $through,array $joining,float $width,array $roads,bool $generated): \Generator
	{
		if(!$through || !$joining) return null;
		$cell=$width; $radius=.8*$width; $lead=3.*$width;
		$grid=[];
		foreach($through as $i=>$sample) {
			[$x,,$z]=$sample['p'];
			$grid[(int)floor($x/$cell)][(int)floor($z/$cell)][]=$i;
			if(($i&63)===0) yield;
		}
		$last=end($joining)['s']; $end=end($through)['s']; $before=0;
		foreach($joining as $i=>$sample) {
			if(($i&31)===0) yield;
			if($sample['s']<$lead) continue;
			if($last-$sample['s']<5.*$width) break;
			while($joining[$before+1]['s']<=$sample['s']-$lead) $before++;
			[$x,$y,$z]=$sample['p']; $gx=(int)floor($x/$cell); $gz=(int)floor($z/$cell);
			for($col=$gx-1;$col<=$gx+1;$col++) for($row=$gz-1;$row<=$gz+1;$row++) {
				foreach($grid[$col][$row] ?? [] as $k) {
					$other=$through[$k]; [$ox,$oy,$oz]=$other['p'];
					$alignment=$other['dir'][0]*$sample['dir'][0]+$other['dir'][2]*$sample['dir'][2];
					if($alignment<0. || $other['s']<$width || $end-$other['s']<5.*$width ||
						hypot($ox-$x,$oz-$z)>$radius || (!$generated && abs($oy-$y)>3.)) continue;
					$after=$k;
					$runout=$width*(3.-2.*min(1.,$alignment));
					while($through[$after]['s']<$other['s']+$runout) $after++;
					$from=$joining[$before]; $to=$through[$after];
					$link=self::connector($from,$to,$roads[$from['id']]);
					if($link!==null) return [$from,$to,$link,false];
					if($generated && abs($oy-$y)>3.) {
						$flatFrom=$from; $flatTo=$to;
						$flatFrom['p'][1]=$flatTo['p'][1]=$flatFrom['dir'][1]=$flatTo['dir'][1]=0.;
						$link=self::connector($flatFrom,$flatTo,$roads[$from['id']]);
						if($link!==null) return [$from,$to,$link,true];
					}
				}
			}
		}
		return null;
	}

	/** Split both road records exactly at a surveyed station, preserving manual handles and banks. */
	private static function cut(array $road,array $sample): array
	{
		$i=$sample['span']; $t=$sample['t']; $points=$road['points'];
		$handles=[]; foreach($points as $k=>$_) $handles[]=Edits::roadHandles($points,$road['handles'] ?? [],$k);
		$banks=$road['banks'] ?? null;
		if($t>0. && $t<1.) {
			[[$a,$b,$c,$p],[,$e,$f,$g]]=JourneyPavement::split(self::curve($road,$i),$t);
			$handles[$i]['out']=self::delta($b,$a); $handles[$i+1]['in']=self::delta($f,$g);
			array_splice($handles,$i+1,0,[['in'=>self::delta($c,$p),'out'=>self::delta($e,$p)]]);
			array_splice($points,$i+1,0,[$p]);
			if($banks!==null) array_splice($banks,$i+1,0,[$banks[$i]+($banks[$i+1]-$banks[$i])*$t]);
		}
		$k=$t===0. ? $i : $i+1;
		$parts=[];
		foreach([[0,$k+1],[$k,count($points)-$k]] as [$offset,$count]) {
			$part=$road;
			$part['points']=array_slice($points,$offset,$count);
			$part['handles']=array_slice($handles,$offset,$count);
			if($banks!==null) $part['banks']=array_slice($banks,$offset,$count);
			$parts[]=$part;
		}
		return $parts;
	}

	/** Return null when the existing planned junction is already the first usable meeting. */
	public static function apply(array $roads,?JourneyPass $pass=null): \Generator
	{
		$changed=false; $newJunctions=[];
		do {
			$starts=$ends=[]; $moved=false;
			foreach($roads as $id=>$road) {
				$p=$road['points'];
				$starts[JourneySigns::node($p[0])][]=$id;
				$ends[JourneySigns::node(end($p))][]=$id;
			}
			foreach($ends as $node=>$ids) {
				if(isset($newJunctions[$node]) || count($ids)<2 || count($starts[$node] ?? [])!==1) continue;
				foreach($ids as $k=>$first) foreach(array_slice($ids,$k+1) as $second) {
					$through=self::arm($roads,$first,$starts,$ends);
					$joining=self::arm($roads,$second,$starts,$ends);
					if(JourneySigns::node($roads[$through[0]]['points'][0])===JourneySigns::node($roads[$joining[0]]['points'][0])) continue;
					$width=min($roads[$first]['width'],$roads[$second]['width']);
					$a=yield from self::samples($roads,$through,$width*.4);
					$b=yield from self::samples($roads,$joining,$width*.4);
					$meeting=yield from self::meeting($a,$b,$width,$roads,$pass!==null);
					if(!$meeting) continue;
					[$from,$to,$link,$grade]=$meeting;
					$before=$roads;
					$incident=array_flip([...$through,...$joining]);
					[$prefix]=self::cut($roads[$from['id']],$from);
					[$left,$right]=self::cut($roads[$to['id']],$to);
					foreach(array_slice($joining,array_search($from['id'],$joining,true)) as $id) unset($roads[$id]);
					unset($roads[$to['id']]);
					$link['points']=[end($prefix['points']),end($left['points'])];
					if(isset($prefix['banks']) || isset($left['banks'])) {
						$link['banks']=[isset($prefix['banks']) ? end($prefix['banks']) : 0.,isset($left['banks']) ? end($left['banks']) : 0.];
					}
					foreach([$left,$right,$prefix,$link] as $part) if(count($part['points'])>=2) {
						$roads[]=$part; $incident[array_key_last($roads)]=true;
					}
					if($grade) {
						$graded=yield from $pass->grade(array_intersect_key($roads,$incident),$to['p']);
						if($graded===null) { $roads=$before; continue; }
						$roads=array_replace($roads,$graded);
					}
					$newJunctions[JourneySigns::node(end($roads[array_key_last($roads)]['points']))]=true;
					$moved=$changed=true;
					break 3;
				}
			}
		} while($moved);
		return $changed ? array_values($roads) : null;
	}
}
