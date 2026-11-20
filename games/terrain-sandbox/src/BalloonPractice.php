<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Landscape;
use TerrainPhonics\RayQuery;

/** Authored balloon placements and the transient fixed-step arcade practice session. */
final class BalloonPractice
{
	private const MAX_BALLOONS = 32;
	/** Ring radius as a fraction of viewport height. The HUD draws this same circle. */
	public const RING = .285;
	private array $placements = [], $positions = [], $popped = [];
	private int $nextId = 1, $practiceId = 0;
	private ?array $practicePoint = null;
	private bool $practice = false, $visible = false;
	private float $respawn = 0, $dwell = 0, $checkAt = 0, $floorAt = 0, $gunAt = 0;
	private ?int $selected = null;
	private ?RayQuery $sight = null;
	private array $readyAt = [0.0,0.0];
	public array $shots = [];
	public float $elapsed = 0, $ground = 0, $hitUntil = 0;
	public int $hits = 0;

	public function export(): array { return array_values($this->placements); }

	public static function load(mixed $items, Landscape $land, float $water): self
	{
		if(!is_array($items) || !array_is_list($items) || count($items) > self::MAX_BALLOONS)
			throw new \InvalidArgumentException('Invalid balloon list.');
		$range = new self;
		foreach($items as $row)
		{
			if(!is_array($row) || !array_is_list($row) || count($row) !== 3)
				throw new \InvalidArgumentException('Invalid balloon placement.');
			foreach($row as $v) if((!is_int($v) && !is_float($v)) || !is_finite((float)$v))
				throw new \InvalidArgumentException('Invalid balloon coordinate.');
			$range->place($row[0],$row[1],$row[2],$land,$water);
		}
		return $range;
	}

	public function place(float $x, float $z, float $height, Landscape $land, float $water): void
	{
		if(count($this->placements) >= self::MAX_BALLOONS) throw new \InvalidArgumentException('The landscape holds 32 balloons.');
		$half = $land->recipe()['worldSize']/2;
		if(abs($x) > $half || abs($z) > $half || $height < 12 || $height > 1200)
			throw new \InvalidArgumentException('Balloon outside the placement area.');
		$id = $this->nextId++;
		$this->placements[$id] = [$x,$z,$height];
		$this->positions[$id] = [$x,max($water,$land->height($x,$z))+$height,$z];
	}

	public function remove(array $origin, array $direction, float $limit): bool
	{
		$id = null;
		foreach($this->positions as $key => $p)
		{
			$d = self::intersection($origin,$direction,$p);
			if($d !== null && $d <= $limit) { $limit = $d; $id = $key; }
		}
		if($id === null) return false;
		unset($this->placements[$id],$this->positions[$id],$this->popped[$id]);
		return true;
	}

	public function refit(Landscape $land, float $water): void
	{
		foreach($this->placements as $id => [$x,$z,$height])
			$this->positions[$id][1] = max($water,$land->height($x,$z))+$height;
		if($this->practicePoint !== null)
		{
			[$x,$y,$z] = $this->practicePoint;
			$this->practicePoint[1] = max($y,max($water,$land->height($x,$z))+16);
		}
		$this->select($this->selected);
		$this->floorAt = 0;
	}

	public function reset(bool $restartClock = true): void
	{
		$this->practice = false; $this->practicePoint = null;
		$this->shots = $this->popped = [];
		$this->select(null);
		if($restartClock) $this->elapsed = 0;
		$this->gunAt = $this->dwell = $this->floorAt = $this->checkAt = $this->hitUntil = 0;
		$this->hits = 0; $this->readyAt = [0.0,0.0];
	}

	public function targets(): array
	{
		$points = array_diff_key($this->positions,$this->popped);
		if($this->practicePoint !== null) $points[$this->practiceId] = $this->practicePoint;
		return $points;
	}

	public function start(array $p, Landscape $land, float $water): bool
	{
		$this->reset(false);
		return $this->practice = $this->spawn($p,$land,$water);
	}

	private function spawn(array $p, Landscape $land, float $water): bool
	{
		$half = $land->recipe()['worldSize']/2;
		$eye = Jet::cockpit($p);
		[$nose] = $eye->basis();
		// Try progressively nearer positions when the map edge or a canyon wall cuts off the first one.
		foreach([650,420,250,140,75,35] as $distance)
		{
			$x = $eye->position[0]+$distance*$nose[0]; $z = $eye->position[2]+$distance*$nose[2];
			if(abs($x)+7 > $half || abs($z)+7 > $half) continue;
			$point = [$x,max($eye->position[1]+$distance*$nose[1],max($land->height($x,$z),$water)+16),$z];
			if(self::blocked($eye->position,$point,$land,$water)) continue;
			$this->practicePoint = $point;
			$this->select(--$this->practiceId);
			return true;
		}
		return false;
	}

	private function select(?int $id): void
	{
		$this->sight = null;
		$this->selected = $id;
		$this->visible = false; $this->dwell = $this->checkAt = 0;
	}

	public function cycle(): void
	{
		$ids = array_keys($this->targets());
		if(!$ids) return;
		$i = array_search($this->selected,$ids,true);
		$this->select($ids[$i === false ? 0 : ($i+1)%count($ids)]);
	}

	/** Distance along a unit segment direction to the scaled balloon envelope. */
	private static function intersection(array $a, array $d, array $p): ?float
	{
		$aa = $bb = 0.0; $cc = -1.0;
		foreach([6.7,10.0,6.7] as $i => $radius)
		{
			$v = $d[$i]/$radius; $r = ($a[$i]-$p[$i])/$radius;
			$aa += $v*$v; $bb += $r*$v; $cc += $r*$r;
		}
		if($cc <= 0) return 0.0;
		$disc = $bb*$bb - $aa*$cc;
		return $disc < 0 || $bb >= 0 ? null : (-$bb-sqrt($disc))/$aa;
	}

	private static function blocked(array $from, array $to, Landscape $land, float $water): bool
	{
		$half = $land->recipe()['worldSize']/2;
		if(max(abs($to[0]),abs($to[2])) > $half || min($from[1],$to[1]) <= $water) return true;
		if(min($from[1],$to[1]) > $land->bounds()['maxY']) return false;
		$delta = [$to[0]-$from[0],$to[1]-$from[1],$to[2]-$from[2]];
		$length = sqrt(array_sum(array_map(static fn($v)=>$v*$v,$delta)));
		if($length < .001) return false;
		$dir = array_map(static fn($v)=>$v/$length,$delta);
		return $land->raycast($from,$dir,$length) !== null;
	}

	private function track(array $p, Landscape $land, float $water, float $dt): void
	{
		$targets = $this->targets();
		if(!$targets) { $this->select(null); return; }
		$eye = Jet::cockpit($p);
		[$nose] = $eye->basis();
		if(!isset($targets[$this->selected]))
		{
			$best = -INF;
			// On acquiring a new target, prefer the balloon closest to the centre of the pilot's view.
			foreach($targets as $id => $point)
			{
				$x = $point[0]-$eye->position[0]; $y = $point[1]-$eye->position[1]; $z = $point[2]-$eye->position[2];
				$alignment = ($x*$nose[0]+$y*$nose[1]+$z*$nose[2])/max(.001,sqrt($x*$x+$y*$y+$z*$z));
				if($alignment > $best) { $best = $alignment; $candidate = $id; }
			}
			$this->select($candidate);
		}
		$id = $this->selected;
		$point = $targets[$id];
		$x = $point[0]-$eye->position[0]; $y = $point[1]-$eye->position[1]; $z = $point[2]-$eye->position[2];
		$range = sqrt($x*$x+$y*$y+$z*$z);
		$angle = ($x*$nose[0]+$y*$nose[1]+$z*$nose[2])/max(.001,$range);
		if($angle <= 0 || $range > 4000 || min($eye->position[1],$targets[$id][1]) <= $water)
		{
			$this->select($id);
			return;
		}
		if($this->sight === null && $this->elapsed >= $this->checkAt)
		{
			if($range < .001 || min($eye->position[1],$targets[$id][1]) > $land->bounds()['maxY'])
			{
				$this->visible = true;
				$this->checkAt = $this->elapsed+.10;
			}
			else $this->sight = $land->rayQuery($eye->position,[$x,$y,$z],$range);
		}
		if($this->sight !== null && $this->sight->advance(.35,16))
		{
			$this->visible = $this->sight->result() === null;
			$this->sight = null;
			$this->checkAt = $this->elapsed+.10;
		}
		$inside = $angle >= 1/sqrt(1+(2*self::RING*tan($eye->fov/2))**2);
		$this->dwell = $this->visible && $inside && $range <= 1200 ? min(.3,$this->dwell+$dt) : 0;
	}

	public function step(float $dt, array $p, bool $launch, bool $gun, Landscape $land, float $water, bool $inCockpit): void
	{
		$this->elapsed += $dt;
		if($this->practice && !$this->practicePoint && $this->elapsed >= $this->respawn)
		{
			$this->spawn($p,$land,$water);
			$this->respawn = $this->elapsed+1;
		}
		$this->track($p,$land,$water,$dt);
		if($inCockpit && $this->elapsed >= $this->floorAt)
		{
			$this->ground = max($water,$land->height($p['x'],$p['z']));
			$this->floorAt = $this->elapsed+.20;
		}
		if($launch)
		{
			foreach($this->readyAt as $i => $time)
			{
				if($time > $this->elapsed) continue;
				$this->emit($p,false,$i === 0 ? -1 : 1);
				$this->readyAt[$i] = $this->elapsed+3;
				break;
			}
		}
		if($gun && $this->elapsed >= $this->gunAt)
		{
			$this->emit($p,true,0);
			$this->gunAt = $this->elapsed+.09;
		}
		$points = $this->targets();
		foreach($this->shots as $i => &$shot)
		{
			$a = $shot['position']; $d = $shot['direction'];
			$target = $shot['target'];
			if($target !== null && isset($points[$target]))
			{
				$offset = array_map(static fn($v,$w)=>$v-$w,$points[$target],$a);
				$length = sqrt(array_sum(array_map(static fn($v)=>$v*$v,$offset)));
				$blend = min(1,$dt*8);
				foreach($d as $j=>$v) $d[$j] = $v*(1-$blend) + $blend*$offset[$j]/max(.001,$length);
				$length = sqrt(array_sum(array_map(static fn($v)=>$v*$v,$d)));
				$d = array_map(static fn($v)=>$v/max(.001,$length),$d);
			}
			$travel = $shot['speed']*$dt; $hit = null;
			foreach($points as $id => $point)
			{
				$t = self::intersection($a,$d,$point);
				if($t !== null && $t <= $travel) { $hit = $id; $travel = $t; }
			}
			$b = array_map(static fn($v,$dir)=>$v+$dir*$travel,$a,$d);
			$obstructed = self::blocked($a,$b,$land,$water);
			if($hit !== null && !$obstructed)
			{
				unset($points[$hit]);
				if($hit === $this->practiceId) { $this->practicePoint = null; $this->respawn = $this->elapsed+.85; }
				else $this->popped[$hit] = true;
				if($this->selected === $hit) $this->select(null);
				$this->hits++; $this->hitUntil = $this->elapsed+.65;
			}
			if($obstructed || $hit !== null || $this->elapsed >= $shot['expires']) unset($this->shots[$i]);
			else { $shot['previous'] = $a; $shot['position'] = $b; $shot['direction'] = $d; }
		}
		unset($shot);
	}

	private function emit(array $p, bool $tracer, int $side): void
	{
		$eye = Jet::cockpit($p);
		[$nose,$right,$up] = $eye->basis();
		$a = $eye->position;
		foreach($a as $i=>$v) $a[$i] += $side*3*$right[$i] - ($tracer ? 1.1 : 2)*$up[$i] + 5*$nose[$i];
		$this->shots[] = ['previous'=>$a,'position'=>$a,'direction'=>$nose,'tracer'=>$tracer,
			'speed'=>$p['speed']+($tracer ? 760 : 490),'expires'=>$this->elapsed+($tracer ? 1.4 : 3.5),
			'target'=>!$tracer && $this->dwell >= .3 ? $this->selected : null];
	}

	public function status(): array
	{
		return ['selected'=>$this->selected,'visible'=>$this->visible,'locked'=>$this->dwell >= .3,'hits'=>$this->hits,
			'hit'=>$this->elapsed < $this->hitUntil,'elapsed'=>$this->elapsed,'practice'=>$this->practice,
			'ready'=>count(array_filter($this->readyAt,fn($t)=>$t <= $this->elapsed)),
			'placements'=>count($this->placements),'shots'=>count($this->shots),'ground'=>$this->ground];
	}
}
