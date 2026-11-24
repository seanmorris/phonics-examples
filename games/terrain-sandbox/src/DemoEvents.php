<?php
declare(strict_types=1);
namespace TerrainSandbox;

/** Changes to race, delivery and balloon presentation, keyed to the same sample as the vehicle. */
final class DemoEvents
{
	private array $changes = [], $last = [], $shown = [];
	private int $cursor = 0, $sample = -1;
	public int $bytes = 0;

	public function capture(Session $s, ?array $vehicle, int $index, int $budget): bool
	{
		$race = $s->race->status($s->course,$s->time,$s->mode,$s->water->level());
		if($race['state'] === 'running') $race['time'] = round($race['time']-$s->time,5);
		$range = $s->balloons->status();
		if($s->mode === 'jet') $range['elapsed'] = round($range['elapsed']-$s->time,5);
		$shots = [];
		foreach($s->balloons->shots as $shot) $shots[] = [(int)$shot['tracer'],...$shot['position'],...$shot['direction'],$shot['roll']];
		$state = ['race'=>$race,'target'=>$s->navigationTarget($vehicle),
			'sites'=>$s->delivery?->beacons($s->time) ?? [],
			'delivery'=>$s->delivery ? ['delivered'=>$s->delivery->delivered,'carrying'=>$s->delivery->carrying,'target'=>$s->delivery->target()] : null,
			'balloons'=>$s->balloons->targets(),'slots'=>$s->balloons->readySlots(),'shots'=>$shots,'range'=>$range,
			'contacts'=>$s->mode === 'car' ? $s->car->dustContacts : []];
		$delta = [];
		foreach($state as $key=>$value) if(!array_key_exists($key,$this->last) || $this->last[$key] !== $value) $delta[$key]=$value;
		if(!$delta) return true;
		$encoded = json_encode($delta,JSON_THROW_ON_ERROR);
		if(strlen($encoded)+24>$budget) return false;
		$this->last = $state;
		$this->append($index,$encoded);
		return true;
	}

	private function append(int $index, string $encoded): void
	{
		$this->changes[] = [$index,$encoded];
		$this->bytes += strlen($encoded)+24;
	}

	public function at(int $index, float $clock, string $mode): array
	{
		if($index < $this->sample) { $this->cursor=0; $this->shown=[]; }
		while(isset($this->changes[$this->cursor]) && $this->changes[$this->cursor][0] <= $index) {
			$this->shown = json_decode($this->changes[$this->cursor++][1],true,32,JSON_THROW_ON_ERROR) + $this->shown;
		}
		$this->sample=$index;
		$view=$this->shown;
		if(($view['race']['state'] ?? null) === 'running') $view['race']['time'] += $clock;
		if($mode === 'jet' && isset($view['range'])) $view['range']['elapsed'] += $clock;
		return $view;
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
		if(in_array($key,['contacts','shots','balloons','slots','sites'],true)) {
			$limit = match($key) { 'contacts'=>4, 'slots'=>2, 'balloons'=>33, default=>1024 };
			if(!is_array($v) || count($v)>$limit) return false;
			foreach($v as $item) {
				$ok = match($key) {
					'contacts'=>self::vector($item,5), 'shots'=>self::vector($item,8),
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
		if($key==='race') return is_array($v) && in_array($v['state']??null,['idle','running','finished'],true)
			&& ($v['state']!=='running' || self::vector([$v['time']??null],1));
		if($key==='range') return is_array($v) && (!isset($v['selected']) || is_int($v['selected']))
			&& self::vector([$v['elapsed']??null,$v['ground']??null,$v['ready']??null,$v['lockProgress']??null],4);
		if($key==='delivery') return $v===null || (is_array($v) && is_bool($v['carrying']??null) && is_int($v['delivered']??null)
			&& (!isset($v['target']) || self::site($v['target'])));
		return false;
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
			$events->append($previous,$encoded);
		}
		return $events;
	}
}
