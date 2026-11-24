<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\{JoinedTerrain,Landscape,Recipe};

/** Named landscape squares with up to two onward roads. Only the current junction needs terrain assets. */
final class JourneyNetwork
{
	public readonly array $nodes;
	public readonly string $start;

	public function __construct(public readonly string $plan)
	{
		$nodes=[];
		foreach(preg_split('/\R/',trim($plan)) as $line) {
			if(trim($line)==='') continue;
			if(!preg_match('/^\s*([A-Za-z][\w-]*)\s+([\w-]+)(?:\s*->\s*([A-Za-z][\w-]*)(?:\s*,\s*([A-Za-z][\w-]*))?)?\s*$/D',$line,$m)
				|| isset($nodes[$m[1]]) || !in_array($m[2],Recipe::PRESETS,true))
				throw new \InvalidArgumentException('Each row needs a unique square name, a landscape preset, and optionally one or two exits.');
			$nodes[$m[1]]=['preset'=>$m[2],'exits'=>array_slice($m,3)];
		}
		if(!$nodes || !$nodes[array_key_first($nodes)]['exits']) throw new \InvalidArgumentException('The first square needs an onward road.');
		$this->start=array_key_first($nodes);
		$incoming=array_fill_keys(array_keys($nodes),0);
		foreach($nodes as $node) foreach($node['exits'] as $child) {
			if(!isset($nodes[$child])) throw new \InvalidArgumentException("Unknown destination square $child.");
			$incoming[$child]++;
		}
		$order=[$this->start];
		for($i=0;$i<count($order);$i++) foreach($nodes[$order[$i]]['exits'] as $child)
			if(--$incoming[$child]===0) $order[]=$child;
		if(count($order)!==count($nodes) || $incoming[$this->start]!==0)
			throw new \InvalidArgumentException('Every square must be reachable from the first one, with exits leading onward.');
		$this->nodes=$nodes;
	}

	public function recipe(string $id,int $span): array
	{
		$n=$this->nodes[$id];
		$destinations=array_map(fn($child)=>$this->nodes[$child]['preset'],array_values(array_unique($n['exits'])));
		$recipe=JourneyRoad::recipe($n['preset'],$destinations,$span);
		$recipe['seed'].='/'.$id;
		foreach(array_values(array_unique($n['exits'])) as $i=>$child) $recipe['join']['destinations'][$i]['seed'].='/'.$child;
		return $recipe;
	}

	/** The two ends of a split occupy separate squares in the eastern part of the window. */
	public function destination(string $id,array $pose): string
	{
		$exits=$this->nodes[$id]['exits'];
		return $exits[$pose['z']>0 && count($exits)>1 ? 1 : 0];
	}

	public function translation(string $id,string $child,array $recipe): array
	{
		$exits=array_values(array_unique($this->nodes[$id]['exits']));
		$i=array_search($child,$exits,true);
		$origins=JoinedTerrain::origins($recipe['join']['span'],count($exits));
		return [$origins[0][0]-$origins[$i+1][0],$recipe['join']['destinations'][$i]['waterLevel']-$recipe['waterLevel'],-$origins[$i+1][1]];
	}

	/** Carry the actual incoming pavement through the handoff, including the player's road edits. */
	public static function arrival(Landscape $land,array $shift): array
	{
		[$dx,$dy,$dz]=$shift;
		$r=$land->recipe(); $half=$r['worldSize']/2;
		$edge=$half-1.; $end=null;
		$records=$land->roadRecords();
		foreach($records as $id=>$road) {
			$p=$road['points'][array_key_last($road['points'])];
			if($p[0] >= $edge && ($end===null || abs($p[2]+$dz)<abs($end[2]+$dz))) $end=$p;
		}
		$keep=[]; $at=$end;
		// Include the approach far enough behind the handoff that scenery and minimap stay continuous.
		while($at!==null && $at[0]>$half-$r['join']['span']*.5) {
			$next=null;
			foreach($records as $id=>$road) {
				if($road['points'][array_key_last($road['points'])]!=$at) continue;
				$next=$road['points'][0];
				foreach($road['points'] as &$p) { $p[0]+=$dx; $p[1]+=$dy; $p[2]+=$dz; }
				unset($p);
				$keep[]=$road;
				unset($records[$id]);
				break;
			}
			$at=$next;
		}
		if(!$keep) throw new \RuntimeException('The onward road has no connected entrance.');
		return array_reverse($keep);
	}
}
