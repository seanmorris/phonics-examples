<?php
declare(strict_types=1);
namespace TerrainSandbox;

/** Changes to race, delivery and balloon presentation, keyed to the same sample as the vehicle. */
final class DemoEvents
{
	private array $changes = [], $last = [], $shown = [];
	private array $anchors = [];
	private int $cursor = 0, $sample = -1;
	public int $bytes = 0;

	public function capture(Session $s, ?array $vehicle, int $index, int $budget): bool
	{
		$race = $s->race->status($s->course,$s->time,$s->mode,$s->water->level());
		if($race['state'] === 'running') $race['time'] = round($race['time']-$s->time,5);
		$range = $s->balloons->status();
		if($s->mode === 'jet') $range['elapsed'] = round($range['elapsed']-$s->time,5);
		$shots = [];
		foreach($s->balloons->shots as $shot) $shots[] = [(int)$shot['tracer'],...$shot['position'],...$shot['direction'],$shot['roll'],$shot['speed'],$shot['expires'],$shot['target'] ?? -1];
		$dynamics = match($s->mode) {
			'car'=>$s->car->motion(), 'jet'=>$s->jet->motion(), 'craft'=>$s->craft->angularMotion(), default=>[]
		};
		$state = ['motion'=>base64_encode(pack('g*',...$dynamics)),
			'raceDistance'=>$s->race->distanceFromStart(), 'rangeTimers'=>$s->mode==='jet' ? $s->balloons->timers() : null,
			'race'=>$race,'target'=>$s->navigationTarget($vehicle),
			'sites'=>$s->delivery?->beacons($s->time) ?? [],
			'delivery'=>$s->delivery ? ['delivered'=>$s->delivery->delivered,'carrying'=>$s->delivery->carrying,'target'=>$s->delivery->target()] : null,
			'beachBalls'=>$s->balloons->beachBalls->states(),'balloons'=>$s->balloons->targets(),'slots'=>$s->balloons->readySlots(),'shots'=>$shots,'range'=>$range,
			'contacts'=>$s->mode === 'car' ? $s->car->dustContacts : []];
		$delta = [];
		foreach($state as $key=>$value) if(!array_key_exists($key,$this->last) || $this->last[$key] !== $value) $delta[$key]=$value;
		if(!$delta) return true;
		$encoded = json_encode($delta,JSON_THROW_ON_ERROR);
		if(strlen($encoded)+24>$budget) return false;
		$this->append($index,$encoded,$delta);
		return true;
	}

	private function append(int $index, string $encoded, array $delta): void
	{
		$n = count($this->changes);
		if($n % 256 === 0) $this->anchors[$n] = $this->last;
		$this->last = $delta + $this->last;
		$this->changes[] = [$index,$encoded];
		$this->bytes += strlen($encoded)+24;
	}

	public function at(int $index, float $clock, string $mode): array
	{
		if($index < $this->sample || ($this->changes[$this->cursor+256][0] ?? PHP_INT_MAX) <= $index) {
			$lo=0; $hi=count($this->anchors);
			while($lo+1<$hi) {
				$mid=intdiv($lo+$hi,2);
				if($this->changes[$mid*256][0]<=$index) $lo=$mid;
				else $hi=$mid;
			}
			$this->cursor=$lo*256; $this->shown=$this->anchors[$this->cursor] ?? [];
		}
		while(isset($this->changes[$this->cursor]) && $this->changes[$this->cursor][0] <= $index) {
			$this->shown = json_decode($this->changes[$this->cursor++][1],true,32,JSON_THROW_ON_ERROR) + $this->shown;
		}
		$this->sample=$index;
		$view=$this->shown;
		if(($view['race']['state'] ?? null) === 'running') $view['race']['time'] += $clock;
		if($mode === 'jet' && isset($view['range'])) $view['range']['elapsed'] += $clock;
		return $view;
	}

	public function through(int $index): self
	{
		$this->at($index,0.,'fly');
		$copy = clone $this;
		$copy->changes = array_slice($this->changes,0,$this->cursor);
		$copy->anchors = array_filter($this->anchors,fn($key)=>$key<$this->cursor,ARRAY_FILTER_USE_KEY);
		$copy->last = $this->shown;
		$copy->bytes = 0;
		foreach($copy->changes as [, $encoded]) $copy->bytes += strlen($encoded)+24;
		return $copy;
	}

	public function export(): array { return $this->changes; }

	private static function vector(mixed $p, int $n): bool
	{
		if(!is_array($p) || !array_is_list($p) || count($p)!==$n) return false;
		foreach($p as $v) if((!is_int($v) && !is_float($v)) || !is_finite($v) || abs($v)>1e9) return false;
		return true;
	}

	private static function site(mixed $p): bool
	{
		return is_array($p) && self::vector([$p['x']??null,$p['y']??null,$p['z']??null],3)
			&& in_array($p['kind']??null,['pickup','drop','smoke','checkpoint'],true)
			&& is_string($p['color']??null) && preg_match('/^#[0-9a-f]{6}$/iD',$p['color'])
			&& (!isset($p['puffs']) || (is_int($p['puffs']) && $p['puffs']>=0 && $p['puffs']<=DeliveryRun::PUFF_COUNT));
	}

	private static function valid(string $key, mixed $v): bool
	{
		if($key==='motion') {
			if(!is_string($v) || strlen($v)>144) return false;
			$bytes=base64_decode($v,true);
			if($bytes===false || !in_array(strlen($bytes),[0,12,40,80,104],true)) return false;
			foreach(unpack('g*',$bytes) as $value) if(!is_finite($value) || abs($value)>1e9) return false;
			return true;
		}
		if($key==='beachBalls') return BeachBalls::valid($v);
		if($key==='rangeTimers') return $v===null || self::vector($v,7);
		if($key==='raceDistance') return self::vector([$v],1);
		if(in_array($key,['contacts','shots','balloons','slots','sites'],true)) {
			$limit = match($key) { 'contacts'=>4, 'slots'=>2, 'balloons'=>33, default=>1024 };
			if(!is_array($v) || count($v)>$limit) return false;
			foreach($v as $item) {
				$ok = match($key) {
					'contacts'=>self::vector($item,5), 'shots'=>self::vector($item,8) || self::vector($item,11),
					'balloons'=>self::vector($item,3), 'slots'=>in_array($item,[0,1],true),
					default=>self::site($item)
				};
				if(!$ok) return false;
			}
			return true;
		}
		if($key==='target') {
			if($v===null) return true;
			if(!self::site($v)) return false;
			if($v['kind'] !== 'checkpoint') return !isset($v['waypoint']);
			$a = $v['waypoint'] ?? $v;
			return is_array($a) && self::vector([$a['x']??null,$a['z']??null,$a['yaw']??null,$a['halfWidth']??null],4);
		}
		if($key==='race') {
			if(!is_array($v) || !is_array($v['splits'] ?? null) || !is_array($v['lapTimes'] ?? null)
				|| !is_string($v['message'] ?? '') || !array_is_list($v['splits']) || !array_is_list($v['lapTimes'])) return false;
			foreach($v['splits'] as $split) if(!is_array($split) || !is_int($split['gate'] ?? null) || !self::vector([$split['time'] ?? null],1)) return false;
			foreach($v['lapTimes'] as $t) if(!self::vector([$t],1)) return false;
			return in_array($v['state']??null,['idle','running','finished'],true)
				&& ($v['state']==='idle' || self::vector([$v['time']??null],1));
		}
		if($key==='range') return is_array($v) && (!isset($v['selected']) || is_int($v['selected']))
			&& self::vector([$v['elapsed']??null,$v['ground']??null,$v['ready']??null,$v['lockProgress']??null],4);
		if($key==='delivery') return $v===null || (is_array($v) && is_bool($v['carrying']??null) && is_int($v['delivered']??null)
			&& (!isset($v['target']) || self::site($v['target'])));
		return $key==='driver' && is_bool($v);
	}

	public static function import(mixed $changes, int $samples): self
	{
		if(!is_array($changes) || !array_is_list($changes)) throw new \InvalidArgumentException('Invalid demo events.');
		$events = new self;
		$previous = 0;
		foreach($changes as $change) {
			if(!is_array($change) || !array_is_list($change) || count($change)!==2 || !is_int($change[0])
				|| $change[0]<$previous || $change[0]>=$samples || !is_string($change[1])) throw new \InvalidArgumentException('Invalid demo event order.');
			[$previous,$encoded] = $change;
			$delta = json_decode($encoded,true,32,JSON_THROW_ON_ERROR);
			if(!is_array($delta)) throw new \InvalidArgumentException('Invalid demo event.');
			foreach($delta as $key=>$value)
				if(!self::valid((string)$key,$value)) throw new \InvalidArgumentException('Invalid demo event field.');
			$events->append($previous,$encoded,$delta);
		}
		return $events;
	}
}
