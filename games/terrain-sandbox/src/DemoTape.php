<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Camera;

/** Timed presentation samples. Recording the resolved pose also captures slides, recoveries and CPU driving. */
final class DemoTape
{
	private const MODES = ['fly','car','craft','jet'];
	private const VEHICLE = ['x','y','z','yaw','pitch','roll','speed','power','vx','vy','vz','aileron','stabilator','rudder',
		'steering','wheelSpin','rearWheelSpin','nitrous','rpm','engineLoad','tireSqueal','drift'];
	private const LAMPS = ['brake','reverse','headlamps','pods'];
	// Elapsed time, world, mode, map/view bits, scene time, sunlight, camera, vehicle, suspension and lamps.
	private const VALUES = 14 + 22 + 4 + 4;
	private const STRIDE = 4 * self::VALUES;
	public const MAX_BYTES = 24 * 1024 * 1024;
	private DemoEvents $events;
	private string $samples = '';
	private array $worlds = [], $worldKey = [];
	private int $worldBytes = 0;
	private ?float $origin = null;
	private float $end = 0.;
	public bool $recording = false;
	public ?float $playhead = null;
	public bool $held = false;
	public string $notice = '';

	public function __construct() { $this->events = new DemoEvents; }

	public function count(): int { return intdiv(strlen($this->samples),self::STRIDE); }
	public function duration(): float { return $this->end; }
	public function world(int $index): string { return $this->worlds[$index]; }

	public function status(): array
	{
		return ['recording'=>$this->recording,'available'=>$this->count()>0,'duration'=>$this->end,'playhead'=>$this->playhead,'held'=>$this->held,'notice'=>$this->notice];
	}

	public function start(Session $session): void
	{
		$fresh = new self;
		$fresh->recording = true;
		$fresh->capture($session,true);
		if(!$fresh->recording) throw new \RuntimeException($fresh->notice);
		$this->events = $fresh->events;
		$this->samples = $fresh->samples;
		$this->worlds = $fresh->worlds;
		$this->worldKey = $fresh->worldKey;
		$this->worldBytes = $fresh->worldBytes;
		$this->origin = $fresh->origin;
		$this->end = 0.;
		$this->recording = true;
		$this->playhead = null;
		$this->held = false;
		$this->notice = '';
	}

	private static function worldKey(Session $s): array
	{
		$settings = array_diff_key($s->settings,\TerrainPhonics\Starfield::DEFAULTS);
		unset($settings['azimuth'],$settings['elevation']);
		return [$s->generation,$s->landscape->revision(),$s->course,$s->course->revision,$settings,
			$s->roadEditor->hiddenRoads(),$s->displayedLayers(),$s->atmosphere->layers(),$s->paint];
	}

	public function continueWorld(Session $s): void { $this->worldKey=self::worldKey($s); }

	public function capture(Session $s, bool $force = false): void
	{
		if(!$this->recording) return;
		$this->origin ??= $s->time;
		// Use the file's timestamp precision when deciding whether a forced capture replaces the cut frame.
		$t = unpack('g',pack('g',round($s->time-$this->origin,6)))[1];
		if(!$force && ($s->paused || $t-$this->end < 1/30-.00001)) return;
		$key = self::worldKey($s);
		$document = $key !== $this->worldKey ? $s->save(true) : null;
		$worldSize = strlen($document ?? '');
		$budget = self::MAX_BYTES - $this->worldBytes - $worldSize - (strlen($this->samples)+self::STRIDE)*2 - 4096;
		[$eye,$vehicle] = $s->view(1.);
		$replace = $this->count()>0 && $t === $this->end;
		if($worldSize > 4*1024*1024 || ($document !== null && count($this->worlds)>=256) ||
			$budget<2*$this->events->bytes || !$this->events->capture($s,$vehicle,$this->count()-(int)$replace,intdiv($budget,2)-$this->events->bytes)) {
			$this->finish('Recording stopped at the demo file size limit.');
			$s->message = $this->notice;
			return;
		}
		if($document !== null) {
			$this->worlds[] = $document;
			$this->worldBytes += $worldSize;
			$this->worldKey = $key;
		}
		$flags = (int)($s->jetView === 'cockpit') | ((int)($s->mode === 'car' ? $s->carMap : $s->jetMap) << 1)
			| ((int)($s->mode === 'car' ? $s->carWideMap : $s->jetWideMap) << 2);
		$row = [$t,array_key_last($this->worlds),array_search($s->mode,self::MODES,true),$flags,$s->time,
			$s->settings['azimuth'],$s->settings['elevation'],...$eye->position,$eye->yaw,$eye->pitch,$eye->roll,$eye->fov];
		if($s->mode === 'craft') [$vehicle['vx'],$vehicle['vy'],$vehicle['vz']] = $vehicle['velocity'];
		foreach(self::VEHICLE as $name) $row[] = $vehicle[$name] ?? 0.;
		array_push($row,...($vehicle['wheelTravel'] ?? [0.,0.,0.,0.]));
		$lamps = $s->mode === 'car' ? $s->carLamps(1.) : [];
		foreach(self::LAMPS as $name) $row[] = $lamps[$name] ?? 0.;
		$bytes = pack('g*',...$row);
		if($replace) $this->samples = substr_replace($this->samples,$bytes,-self::STRIDE);
		else $this->samples .= $bytes;
		$this->end = $t;
	}

	public function finish(string $notice = 'Demo recorded.'): void
	{
		$this->recording = false;
		$this->worldKey = [];
		$this->notice = $notice;
	}

	public function export(): string
	{
		if(!$this->count()) throw new \RuntimeException('Record or open a demo first.');
		return json_encode(['format'=>'terrain-sandbox-demo','version'=>2,
			'worlds'=>array_map(static fn($world)=>json_decode($world,true,64,JSON_THROW_ON_ERROR),$this->worlds),
			'cues'=>$this->events->export(),'samples'=>base64_encode($this->samples)], JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
	}

	/** Validate the entire file before replacing a playable recording. */
	public static function import(string $json): self
	{
		if(strlen($json)>self::MAX_BYTES) throw new \LengthException('Demo exceeds 24 MiB.');
		$data = json_decode($json,true,72,JSON_THROW_ON_ERROR);
		if(($data['format'] ?? null) !== 'terrain-sandbox-demo' || !in_array($data['version'] ?? null,[1,2],true)
			|| !is_array($data['worlds'] ?? null) || !array_is_list($data['worlds']) || !$data['worlds'] || count($data['worlds'])>256
			|| !is_string($data['samples'] ?? null)) throw new \InvalidArgumentException('Unsupported terrain demo.');
		$tape = new self;
		foreach($data['worlds'] as $world) {
			if(!is_array($world)) throw new \InvalidArgumentException('Invalid demo landscape.');
			$preview = $world['demoPreview'] ?? ['roads'=>[],'layers'=>[]];
			foreach(['roads','layers'] as $field) {
				$ids = $preview[$field] ?? null;
				if(!is_array($ids) || !array_is_list($ids)) throw new \InvalidArgumentException('Invalid demo layer list.');
				foreach($ids as $id) if($field==='roads' ? !is_int($id) : !is_string($id))
					throw new \InvalidArgumentException('Invalid demo layer.');
			}
			$document = json_encode($world,JSON_THROW_ON_ERROR);
			$check = new Session;
			try { $check->load($document); } finally { $check->close(); }
			$tape->worlds[] = $document;
		}
		$bytes = base64_decode($data['samples'],true);
		if(!$bytes || strlen($bytes)%self::STRIDE) throw new \InvalidArgumentException('Incomplete demo samples.');
		$tape->samples = $bytes;
		$lastWorld = -1;
		for($i=0,$n=$tape->count();$i<$n;$i++) {
			$row = $tape->row($i);
			foreach($row as $v) if(!is_finite($v) || abs($v)>1e9) throw new \InvalidArgumentException('Invalid demo coordinate.');
			[$t,$world,$mode,$flags] = $row;
			if(($i === 0 ? $t !== 0. : $t <= $tape->end) || $world !== floor($world)
				|| $world < $lastWorld || !isset($tape->worlds[(int)$world])
				|| $mode !== floor($mode) || !isset(self::MODES[(int)$mode]) || $flags !== floor($flags)
				|| $flags < 0 || $flags > 7 || $row[13] <= .01 || $row[13] >= M_PI-.01)
				throw new \InvalidArgumentException('Invalid demo timeline or camera.');
			$lastWorld = (int)$world;
			$tape->end = $t;
		}
		$tape->events = DemoEvents::import($data['cues'] ?? [],$tape->count());
		return $tape;
	}

	private function row(int $i): array
	{
		return array_values(unpack('g'.self::VALUES,$this->samples,$i*self::STRIDE));
	}

	private function sampleIndex(float $time): int
	{
		$lo = 0; $hi = $this->count()-1;
		while($lo<$hi) {
			$middle = intdiv($lo+$hi+1,2);
			if(unpack('g',$this->samples,$middle*self::STRIDE)[1] <= $time) $lo=$middle;
			else $hi=$middle-1;
		}
		return $lo;
	}

	/** Repaint saved contacts once per recorded sample, including after a seek or when branching. */
	public function replaySkids(SkidMarks &$marks,float $time,int $cursor=-1): int
	{
		$end=$this->sampleIndex($time);
		if(!$this->events->hasSkids || $cursor===$end) return $end;
		$now=$this->row($end); $world=$now[1];
		if($cursor<0 || $cursor>$end || $this->row($cursor)[1]!==$world) {
			$marks=new SkidMarks; $cursor=$end-1;
			$field=$now[2]==0 ? 7 : 14;
			while($cursor>=0) {
				$past=$this->row($cursor); $at=$past[2]==0 ? 7 : 14;
				// Disjoint half-mile circles cannot share a surviving mark.
				if($past[1]!==$world || hypot($past[$at]-$now[$field],$past[$at+2]-$now[$field+2])>2*SkidMarks::RADIUS) break;
				$cursor--;
			}
		}
		for($i=$cursor+1;$i<=$end;$i++) {
			$r=$this->row($i); $mode=self::MODES[(int)$r[2]]; $at=$mode==='fly' ? 7 : 14;
			$x=$r[$at]; $z=$r[$at+2]; $contacts=[];
			if($mode==='car' && ($bits=$this->events->at($i,$r[4],$mode)['skids'] ?? 0)) {
				$sin=sin($r[17]); $cos=cos($r[17]);
				for($wheel=0;$wheel<4;$wheel++) {
					$ink=($bits >> (4*$wheel)) & 15;
					if(!$ink) continue;
					[$wx,$wz]=Car::wheelPoint($wheel,$x,$z,$sin,$cos);
					$contacts[]=[$wheel,$wx,$r[15],$wz,$ink/15];
				}
			}
			$marks->step($contacts,$x,$z);
		}
		return $end;
	}

	public function seek(float $seconds): void
	{
		if(!$this->count() || $this->recording || !is_finite($seconds)) return;
		$this->playhead = max(0.,min($this->end,$seconds));
		$this->held = true;
	}

	public function stepSample(int $direction): void
	{
		$time = $this->playhead ?? 0.;
		$i = $this->sampleIndex($time);
		if($direction>0) $i++;
		elseif($this->row($i)[0] >= $time-.000001) $i--;
		$this->seek($this->row(max(0,min($this->count()-1,$i)))[0]);
	}

	/** The recorded prefix is independent of the original take. New captures continue its time axis. */
	public function branch(): self
	{
		if($this->playhead === null) throw new \LogicException('Select a demo frame first.');
		$i = $this->sampleIndex($this->playhead);
		[$time,$world,,,$clock] = $this->row($i);
		$child = new self;
		$child->end = $time;
		$child->origin = $clock-$time;
		$child->worlds = array_slice($this->worlds,0,(int)$world+1);
		$child->worldBytes = array_sum(array_map('strlen',$child->worlds));
		$child->samples = substr($this->samples,0,($i+1)*self::STRIDE);
		$child->events = $this->events->through($i);
		$child->recording = true;
		return $child;
	}

	/** Rebuild only the still-visible wingtip vapor, exhaust and impact puffs when the timeline jumps. */
	public function restorePatrolSmoke(AirPatrol $patrol,float $time,array $frame): void
	{
		$patrol->smoke=null;
		if($frame['mode']!=='car' || !isset($frame['events']['airPatrol'])) return;
		$start=max(0.,$time-FlightTrails::SMOKE_LIFE);
		$last=$this->frame($start);
		for($i=1;$start<$time;$i++) {
			$t=min($time,$start+$i/30.);
			$f=$t===$time ? $frame : $this->frame($t);
			$dt=$f['time']-$last['time'];
			if($dt>0. && $dt<=.25 && $f['world']===$last['world'] && $f['mode']==='car' && $last['mode']==='car')
				$patrol->stepSmoke($dt,$last['events']['airPatrol']['seeker'] ?? null,$f['events']['airPatrol']['seeker'] ?? null,$f['vehicle'],$last['vehicle'],
					$last['events']['airPatrol']['pose'] ?? null,$f['events']['airPatrol']['pose'] ?? null,
					($f['events']['airPatrol']['phase'] ?? '')==='glide' ? $f['events']['airPatrol']['flight'][0] : null);
			else $patrol->smoke=null;
			if($t===$time) break;
			$last=$f;
		}
		$patrol->smoke?->takeBursts(); // Seeking rebuilds visible smoke silently.
	}

	/** Interpolate at presentation time. Cuts between maps, vehicles and viewpoints stay instantaneous. */
	public function frame(float $time): array
	{
		$lo = $this->sampleIndex($time);
		$a = $this->row($lo);
		if($lo+1 < $this->count()) {
			$b = $this->row($lo+1);
			if($a[1] === $b[1] && $a[2] === $b[2] && ((int)$a[3]&1) === ((int)$b[3]&1)
				&& ($a[7]-$b[7])**2 + ($a[8]-$b[8])**2 + ($a[9]-$b[9])**2 < 200**2) {
				$f = max(0.,min(1.,($time-$a[0])/($b[0]-$a[0])));
				for($i=4;$i<self::VALUES;$i++) {
					$d = $b[$i]-$a[$i];
					if(in_array($i,[10,11,12,17,18,19,29,30],true)) $d = atan2(sin($d),cos($d));
					if($i === 5 && abs($d)>180) $d -= 360 * ($d>0 ? 1 : -1);
					$a[$i] += $d*$f;
				}
			}
		}
		$eye = new Camera;
		$eye->position = array_slice($a,7,3);
		[$eye->yaw,$eye->pitch,$eye->roll,$eye->fov] = array_slice($a,10,4);
		$vehicle = array_combine(self::VEHICLE,array_slice($a,14,22));
		$vehicle['wheelTravel'] = array_slice($a,36,4);
		$vehicle['lamps'] = array_combine(self::LAMPS,array_slice($a,40,4));
		return ['events'=>$this->events->at($lo,$a[4],self::MODES[(int)$a[2]],$f ?? 0.),'world'=>(int)$a[1],'mode'=>self::MODES[(int)$a[2]],'flags'=>(int)$a[3],'time'=>$a[4],
			'azimuth'=>$a[5],'elevation'=>$a[6],'camera'=>$eye,'vehicle'=>$vehicle];
	}
}
