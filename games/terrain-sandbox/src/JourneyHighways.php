<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\{JoinedTerrain,Landscape};

/** Compose adjacent map windows from the same surveyed road curves in each named square. */
final class JourneyHighways
{
	private array $surveys=[], $landings=[], $windows=[], $prepared=[], $merges=[];
	private \WeakMap $generated;

	public function __construct(public readonly JourneyNetwork $network,public readonly int $span,public readonly int $version=2)
	{
		$this->generated=new \WeakMap;
	}

	public function recipe(string $id): array
	{
		$r=$this->network->recipe($id,$this->span);
		if($this->version===1) unset($r['join']['version']);
		return $r;
	}

	/** Place each named square's local origin in the terrain window, including the common sea level. */
	private function origins(string $id,array $r): array
	{
		$exits=$this->network->nodes[$id]['exits'];
		if(!$exits) return [$id=>[0.,0.,0.]];
		$sites=JoinedTerrain::origins($this->span,count($exits));
		$origins=[$id=>[$sites[0][0],0.,$sites[0][1]]];
		foreach($exits as $i=>$child) $origins[$child]=[$sites[$i+1][0],
			$r['waterLevel']-$r['join']['destinations'][$i]['waterLevel'],$sites[$i+1][1]];
		return $origins;
	}

	private function landing(string $id): \Generator
	{
		if($id===$this->network->start) return null;
		if(isset($this->landings[$id])) return $this->landings[$id];
		$parent=$this->network->parents[$id][0]; $r=$this->recipe($parent);
		$slot=array_search($id,$this->network->nodes[$parent]['exits'],true);
		$before=new Landscape($r);
		try { $end=yield from JourneyRoad::landing($before,$slot); }
		finally { $before->close(); }
		$shift=$this->network->translation($parent,$id,$r);
		return $this->landings[$id]=array_map(static fn($p,$s)=>$p+$s,$end,$shift);
	}

	/** Survey the roads owned by this departure. A noninitial stage begins at the landing
	 * already reached in its parent's window. No ancestor chain needs to be generated. */
	private function survey(string $id,?Landscape $target,?callable $report,?array $actualEntry=null): \Generator
	{
		$entry=yield from $this->landing($id);
		$edited=$actualEntry!==null && max(array_map(static fn($a,$b)=>abs($a-$b),$entry,$actualEntry))>.001;
		if(!$edited && isset($this->surveys[$id])) {
			$data=$this->surveys[$id];
			unset($this->surveys[$id]); $this->surveys[$id]=$data;
			return $data;
		}
		$land=$target ?? new Landscape($this->recipe($id));
		try {
			$data=yield from JourneyRoad::survey($land,$report,$edited ? $actualEntry : $entry);
			if(!$edited) {
				$this->surveys[$id]=$data;
				if(count($this->surveys)>6) unset($this->surveys[array_key_first($this->surveys)]);
			}
			return $data;
		} finally { if(!$target) $land->close(); }
	}

	private function approach(string $parent,string $child,?callable $report): \Generator
	{
		if(isset($this->surveys[$parent]['squares'][$child])) return $this->surveys[$parent]['squares'][$child];
		if($report) $report("Surveying the approach from $parent into {$child}…");
		$data=yield from $this->survey($parent,null,$report);
		$origin=$this->origins($parent,$this->recipe($parent))[$child];
		$roads=yield from JourneyPavement::square($data['roads'],$origin,$this->span/2);
		if(isset($this->surveys[$parent])) $this->surveys[$parent]['squares'][$child]=$roads;
		return $roads;
	}

	/** Use parent order and square-local coordinates so all three windows select the same through road. */
	private function mergedSquare(string $stage,string $id,array $base,array $origin,bool $custom,?callable $report): \Generator
	{
		if(!$custom && array_key_exists($stage,$this->merges)) return $this->merges[$stage];
		$roads=[];
		foreach($this->network->parents[$stage] as $parent) {
			$group=$parent===$id
				? (yield from JourneyPavement::square($base,$origin,$this->span/2))
				: (yield from $this->approach($parent,$stage,$report));
			foreach($group as $road) $roads[JourneyPavement::key($road)]=$road;
		}
		$result=yield from JourneyMerge::apply(array_values($roads));
		if(!$custom) {
			$this->merges[$stage]=$result;
			if(count($this->merges)>6) unset($this->merges[array_key_first($this->merges)]);
		}
		return $result;
	}

	/** An edited arrival only describes the route reached through that particular landscape revision. */
	private function customSource(string $id,?string $from,?Landscape $active): ?Landscape
	{
		if(!$active || !in_array($from,$this->network->parents[$id],true)) return null;
		return ($this->generated[$active] ?? null)===$active->revision() ? null : $active;
	}

	/** Build each stage before boarding. Keep its editable splines, not its terrain or tire samples. */
	public function prepare(Landscape $departure,?array $departureViews,callable $stage,callable $report): \Generator
	{
		foreach($this->network->order as $done=>$id) {
			$stage($id,$done);
			$first=$id===$this->network->start;
			if(!$first && isset($this->prepared[$id])) { yield; continue; }
			$land=$first ? $departure : new Landscape($this->recipe($id));
			try {
				$views=$first && $departureViews!==null ? $departureViews : (yield from $this->build($land,$id,$report));
				if(!isset($this->prepared[$id])) {
					$roads=[];
					foreach($land->roadRecords() as $road) {
						$roads[]=gzdeflate(json_encode($road,JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR),1);
						yield;
					}
					$this->prepared[$id]=['roads'=>$roads,'views'=>$views];
				}
				if($first) { $departureViews=$views; $this->generated[$departure]=$departure->revision(); }
			} finally { if(!$first) $land->close(); }
		}
		// The compact stage archive now supplies both driving and map previews.
		$this->surveys=$this->windows=$this->merges=[];
		return $departureViews;
	}

	private static function unpackRoad(string $bytes): array
	{
		return json_decode(gzinflate($bytes),true,flags:JSON_THROW_ON_ERROR);
	}

	/** The map can draw a completed window directly from its control points, without grading it again. */
	public function completedRoads(string $id,?string $from=null,?Landscape $active=null): ?array
	{
		$window=$this->windows[$id] ?? null;
		$custom=$this->customSource($id,$from,$active);
		if($custom===null && isset($this->prepared[$id]) && ($window===null || $window['source']!==null))
			return array_map(self::unpackRoad(...),$this->prepared[$id]['roads']);
		if($window===null) return null;
		if($window['source']===null) return $custom===null ? $window['roads'] : null;
		return $custom && $window['source']->get()===$custom && $window['revision']===$custom->revision() ? $window['roads'] : null;
	}

	/** A live incoming window carries the player's actual pavement, including applied road edits. */
	public function build(Landscape $land,string $id,?callable $report=null,?string $from=null,?Landscape $active=null): \Generator
	{
		if(isset($this->prepared[$id]) && $this->customSource($id,$from,$active)===null) {
			foreach($this->prepared[$id]['roads'] as $bytes) yield from $land->queueSteps(self::unpackRoad($bytes));
			$land->commit();
			$this->generated[$land]=$land->revision();
			return $this->prepared[$id]['views'];
		}
		$r=$land->recipe();
		$sites=$this->origins($id,$r); $actualEntry=null; $arrived=null;
		if($active && $from!==null && in_array($from,$this->network->parents[$id],true)) {
			$before=$active->recipe();
			$origin=$this->origins($from,$before)[$id];
			$arrived=yield from JourneyPavement::square($active->roadRecords(),$origin,$this->span/2);
			if(isset($r['join'])) {
				$previous=JourneyNetwork::arrival($active,$this->network->translation($from,$id,$before));
				$last=end($previous)['points']; $actualEntry=end($last);
			}
		}
		$base=isset($r['join']) ? (yield from $this->survey($id,$land,$report,$actualEntry)) : ['roads'=>[],'views'=>[]];
		$roads=$base['roads']; $revised=[];
		$custom=$this->customSource($id,$from,$active);
		foreach($sites as $stage=>$origin) {
			$groups=[];
			if($stage===$id && $arrived!==null) $groups[]=$arrived;
			else {
				$parents=$this->network->parents[$stage];
				$merged=count($parents)>1 ? (yield from $this->mergedSquare($stage,$id,$base['roads'],$origin,
					$custom!==null && $stage!==$id,$report)) : null;
				if($merged!==null) {
					if($stage!==$id) {
						$outside=yield from JourneyPavement::square($roads,$origin,$this->span/2,true);
						$roads=array_map(static fn($road)=>JourneyPavement::translated($road,$origin),$outside);
					}
					$groups[]=$merged;
					$revised[]=[$origin,$merged];
				} else foreach($parents as $parent) {
					if($parent!==$id) $groups[]=yield from $this->approach($parent,$stage,$report);
				}
			}
			foreach($groups as $group) foreach($group as $road) $roads[]=JourneyPavement::translated($road,$origin);
		}
		$seen=[];
		foreach($roads as $road) {
			$key=JourneyPavement::key($road);
			if(isset($seen[$key])) continue;
			$seen[$key]=true;
			yield from $land->queueSteps($road);
		}
		$land->commit();
		foreach($base['views'] as &$view) {
			[$x,$z]=$view;
			foreach($revised as [[$ox,$oy,$oz],$merged]) {
				if(max(abs($x-$ox),abs($z-$oz))>$this->span/2 || $land->material($x,$z)==='road') continue;
				$survey=new JourneySurvey($r);
				$points=[];
				foreach($merged as $road) foreach($road['points'] as [$px,$py,$pz]) $points[]=[$px+$ox,$py+$oy,$pz+$oz];
				$view=(yield from $survey->roadsideView($points,[$ox,$oz])) ?? $view;
			}
		}
		unset($view);
		if(!$custom) $this->generated[$land]=$land->revision();
		unset($this->windows[$id]);
		$this->windows[$id]=['roads'=>$land->roadRecords(),'source'=>$custom ? \WeakReference::create($custom) : null,'revision'=>$custom?->revision()];
		if(count($this->windows)>6) unset($this->windows[array_key_first($this->windows)]);
		return $base['views'];
	}
}
