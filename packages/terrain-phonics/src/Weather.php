<?php
declare(strict_types=1);
namespace TerrainPhonics;

/** An analytic weather timeline. Each edit anchors the current state so a replay can sample it in either direction. */
final class Weather
{
	public const DEFAULTS = ['coverage'=>0, 'altitude'=>3500, 'shadow'=>45, 'wind'=>12, 'heading'=>90, 'kind'=>'none', 'intensity'=>0];
	public const PRESETS = [
		'clear'=>['coverage'=>0,'kind'=>'none','intensity'=>0],
		'cloudy'=>['coverage'=>55,'kind'=>'none','intensity'=>0],
		'rain'=>['coverage'=>88,'kind'=>'rain','intensity'=>100],
		'snow'=>['coverage'=>78,'kind'=>'snow','intensity'=>100],
	];
	private const LIMITS = ['coverage'=>[0,100],'altitude'=>[100,30000],'shadow'=>[0,100],
		'wind'=>[0,150],'heading'=>[0,360],'intensity'=>[0,100]];
	private array $controls = self::DEFAULTS;
	private float $start = 0., $wet = 0., $snow = 0., $x = 0., $z = 0.;

	private static function checked(array $controls): array
	{
		foreach($controls as $key=>$value) {
			if($key === 'kind' && in_array($value,['none','rain','snow'],true)) continue;
			if(!isset(self::LIMITS[$key]) || (!is_int($value) && !is_float($value)) || !is_finite($value)
				|| $value < self::LIMITS[$key][0] || $value > self::LIMITS[$key][1])
				throw new \InvalidArgumentException('Invalid weather control: '.$key);
		}
		return $controls;
	}

	public function controls(): array { return $this->controls; }

	public function ground(float $time): array
	{
		$t = max(0.,$time-$this->start);
		$rate = $this->controls['intensity']*.01;
		if($rate > 0 && $this->controls['kind']==='snow') return [$this->wet,min(1.,$this->snow+$t*$rate/60.)];
		if($rate > 0 && $this->controls['kind']==='rain') return [min(1.,$this->wet+$t*$rate/20.),max(0.,$this->snow-$t*$rate/60.)];
		$melt = min($this->snow,$t/120.);
		return [max(0.,min(1.,$this->wet+$melt)-max(0.,$t-120.*$this->snow)/90.),max(0.,$this->snow-$melt)];
	}

	public function offset(float $time): array
	{
		$a = deg2rad($this->controls['heading']);
		$distance = ($time-$this->start)*$this->controls['wind']*.44704;
		// Heading zero is north (local −Z). The two cloud scales and the particle volume repeat within this period.
		return [fmod($this->x+$distance*sin($a),65536.),fmod($this->z-$distance*cos($a),65536.)];
	}

	public function snapshot(?float $time = null): array
	{
		return ['controls'=>$this->controls,'time'=>$time ?? $this->start,
			'ground'=>$time === null ? [$this->wet,$this->snow] : $this->ground($time),
			'offset'=>$time === null ? [$this->x,$this->z] : $this->offset($time)];
	}

	public static function valid(mixed $s): bool
	{
		if(!is_array($s) || !is_array($s['controls'] ?? null) || array_diff_key(self::DEFAULTS,$s['controls'])) return false;
		try { self::checked($s['controls']); } catch(\InvalidArgumentException) { return false; }
		foreach(['time'=>[-1e12,1e12],'ground'=>[0.,1.],'offset'=>[-65536.,65536.]] as $key=>[$lo,$hi]) {
			$v = $key==='time' ? [$s[$key] ?? null] : ($s[$key] ?? null);
			if(!is_array($v) || !array_is_list($v) || count($v)!==($key==='time' ? 1 : 2)) return false;
			foreach($v as $n) if((!is_int($n) && !is_float($n)) || !is_finite($n) || $n<$lo || $n>$hi) return false;
		}
		return true;
	}

	public function restore(array $s, ?float $time = null): void
	{
		if(!self::valid($s)) throw new \InvalidArgumentException('Invalid saved weather.');
		$this->controls=$s['controls']; $this->start=$time ?? $s['time'];
		[$this->wet,$this->snow]=$s['ground']; [$this->x,$this->z]=$s['offset'];
	}

	private function anchor(float $time): void
	{
		[$this->wet,$this->snow]=$this->ground($time);
		[$this->x,$this->z]=$this->offset($time);
		$this->start=$time;
	}

	public function edit(array $controls, float $time, bool $resetGround=false): void
	{
		$next=self::checked($controls)+$this->controls;
		if(!$resetGround && $next===$this->controls) return;
		$this->anchor($time);
		$this->controls=$next;
		if($resetGround) $this->wet=$this->snow=0.;
	}

	public function translate(float $x,float $z,float $time): void
	{
		$this->anchor($time);
		$this->x=fmod($this->x+$x,65536.); $this->z=fmod($this->z+$z,65536.);
	}

	public function moving(float $time,float $previous): bool
	{
		if($time===$previous) return false;
		return ($this->controls['coverage']>0 && $this->controls['wind']>0)
			|| ($this->controls['kind']!=='none' && $this->controls['intensity']>0)
			|| (($this->wet>0. || $this->snow>0.) && $this->ground($time)!==$this->ground($previous));
	}
}
