<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\{Edits,JoinedTerrain,Landscape,Recipe,Scene,TerrainAppearance};

/** One requested junction map at a time. Preview work never changes the playing landscape. */
final class JourneyAtlas
{
	private ?Landscape $draft = null;
	private ?\Generator $work = null;
	private bool $live = false, $departure = false;
	public bool $needsChart = false;
	private int $id = 0;
	private ?string $plan=null;
	private ?array $completedRoads=null;

	public function cancel(): void
	{
		$this->work = null;
		$this->draft?->close();
		$this->draft = null;
		$this->plan=null;
		$this->completedRoads=null;
		$this->needsChart = $this->live = $this->departure = false;
	}

	public function request(array $request, Session $session, callable $result): void
	{
		$this->cancel();
		$this->id = (int)$request['id'];
		if(!isset($request['span'])) return;
		try {
			$this->live = isset($request['generation']) && $request['generation'] === $session->generation;
			if(!$this->live) {
				$span = $request['span'];
				if(!is_int($span)) throw new \InvalidArgumentException('Choose a landscape width.');
				$highways=null;
				if(isset($request['plan'])) {
					$network=new JourneyNetwork($request['plan']);
					$this->plan=$network->plan;
					$id=$request['stage'];
					if(!isset($network->nodes[$id])) throw new \InvalidArgumentException('Choose a stage in this route.');
					$this->departure=$id===$network->start;
					$active=$session->journeyPlan()===$this->plan && ($session->landscape->recipe()['join']['span'] ?? null)===$span;
					$version=$active ? ($session->landscape->recipe()['join']['version'] ?? 1) : 2;
					$highways=$session->highways($network,$span,$version);
					$recipe=$highways->recipe($id);
					$from=$active ? $session->journeySquare() : null;
					$previous=$active ? $session->landscape : null;
					$this->completedRoads=($active ? $session->preparedJourneyRoads($id) : null) ?? $highways->completedRoads($id,$from,$previous);
					if($this->completedRoads!==null && !empty($request['survey'])) {
						$result($this->id,json_encode(['roads'=>self::roads($this->completedRoads)],JSON_THROW_ON_ERROR));
						$this->cancel();
						return;
					}
				} else {
					$this->departure = true;
					$from = $request['west']; $to = $request['destinations'];
					if(!is_array($to) || count($to)<1 || count($to)>2 || array_diff([$from,...$to],Recipe::PRESETS))
						throw new \InvalidArgumentException('Choose a departure and one or two destinations.');
					$recipe = JourneyRoad::recipe($from,$to,$span);
				}
				$this->draft = new Landscape($recipe);
				if(!empty($request['survey'])) {
					$report=function(string $phase) use ($result) {
						$result($this->id,json_encode(['phase'=>$phase],JSON_THROW_ON_ERROR));
					};
					$this->work=$highways ? $highways->build($this->draft,$id,$report,$from,$previous)
						: JourneyRoad::build($this->draft,report:$report);
					return;
				}
			}
			$this->needsChart = true;
		} catch(\InvalidArgumentException $error) {
			$this->cancel();
			$result($this->id,json_encode(['error'=>$error->getMessage()],JSON_THROW_ON_ERROR));
		}
	}

	public function step(Session $session, callable $result, bool $stationary): void
	{
		if(!$this->work) return;
		try {
			$deadline = hrtime(true)+($stationary ? 4500000 : 1200000);
			while($this->work->valid() && hrtime(true)<$deadline) $this->work->next();
			if($this->work->valid()) return;
			$result($this->id,json_encode(['roads'=>self::roads($this->draft->roadRecords())],JSON_THROW_ON_ERROR));
			if($this->departure) {
				$session->rememberJourneySurvey($this->draft,$this->work->getReturn(),$this->plan);
				$this->draft = null;
			}
		} catch(\RuntimeException|\InvalidArgumentException $error) {
			$result($this->id,json_encode(['error'=>$error->getMessage()],JSON_THROW_ON_ERROR));
		} finally {
			if(!$this->work->valid()) $this->cancel();
		}
	}

	public function capture(Scene $scene, Session $session, int $width, int $height, callable $result): void
	{
		try {
			$this->draw($scene,$session,$width,$height,$result);
		} catch(\RuntimeException $error) {
			$this->cancel();
			$result($this->id,json_encode(['error'=>$error->getMessage()],JSON_THROW_ON_ERROR));
		}
	}

	private function draw(Scene $scene, Session $session, int $width, int $height, callable $result): void
	{
		$size = min(384,$width-10,$height-10);
		if($size<1) return;
		$land = $this->live ? $session->landscape : $this->draft;
		$r = $land->recipe();
		$water = TerrainAppearance::linear($this->live ? $session->settings['waterColor'] : Session::presetWaterColor($r['preset']));
		$span = $this->live
			? $scene->drawChart([0.,0.],0.,false,$width,$height,$water,$size,fixedSpan:$r['worldSize'])
			: $scene->drawRecipeChart($land,$width,$height,$size,$water);
		if($span === null) return;
		$origins = isset($r['join']) ? JoinedTerrain::origins($r['join']['span'],count($r['join']['destinations'])) : [[0.,0.]];
		$map = ['size'=>$size,'extent'=>$span,'pins'=>array_map(static fn($p)=>[.5+$p[0]/$span,.5+$p[1]/$span],$origins)];
		if($this->completedRoads!==null) $map['roads']=self::roads($this->completedRoads);
		$result($this->id,json_encode($map,JSON_THROW_ON_ERROR));
		$this->cancel();
	}

	/** Cubic control points in chart coordinates, preserving road edits and shared endpoints. */
	private static function roads(array $records): array
	{
		$paths = [];
		foreach($records as $road) {
			$points = $road['points'];
			if(count($points)<2) continue;
			$handles = $road['handles'] ?? [];
			$line = [[$points[0][0],$points[0][2]]];
			$previous = Edits::roadHandles($points,$handles,0);
			for($i=1;$i<count($points);$i++) {
				$next = Edits::roadHandles($points,$handles,$i);
				$a = $points[$i-1]; $b = $points[$i];
				$line[] = [$a[0]+$previous['out'][0],$a[2]+$previous['out'][2],
					$b[0]+$next['in'][0],$b[2]+$next['in'][2],$b[0],$b[2]];
				$previous = $next;
			}
			$paths[] = $line;
		}
		return $paths;
	}
}
