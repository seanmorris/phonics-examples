<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Landscape;

/** A standing-start race. The interactive driver and the verifier use the very same step. */
final class CircuitTrial
{
	public const RULES = 'terrain-car-circuit-v1';
	public const HZ = 60;
	public const MAX_TICKS = 864000;
	public readonly Car $car;
	public readonly Race $race;
	public int $tick = 0;
	public ?array $lastSplit = null;
	public bool $running = false;
	public ?string $dnf = null;
	private string $inputs = '';

	/** The PHAR and native fixtures fingerprint the same simulation source from their package manifests. */
	public static function simulator(): string
	{
		static $id = null;
		if($id !== null) return $id;
		$terrain = dirname((new \ReflectionClass(Landscape::class))->getFileName(),2);
		$packages = [
			[dirname(__DIR__), ['CircuitTrial','Race','Car','CarSuspension','CarTumble','CarGearbox','Course','Circuit',
				'CircuitRoad','GateShapes','RoadRecovery','DisplayUnits']],
			[$terrain, ['Landscape','Edits','EditIndex','RoadAtlas','RoadFill','TerrainSurface','HeightTile','JoinedTerrain',
				'Noise','Recipe','Water','WaterSurface']]
		];
		$hash = hash_init('sha256');
		hash_update($hash,self::RULES);
		foreach($packages as [$directory,$classes]) {
			$manifest = json_decode(file_get_contents($directory.'/phonics.json'),true,64,JSON_THROW_ON_ERROR);
			foreach($classes as $class) {
				$signature = $manifest['integrity']['src/'.$class.'.php'] ?? null;
				if(!is_string($signature)) throw new \RuntimeException('Missing race simulation manifest entry for '.$class);
				hash_update($hash,$class.$signature);
			}
		}
		return $id = hash_final($hash);
	}

	public static function laps(mixed $laps): int
	{
		if(!is_int($laps) || $laps < 1 || $laps > Course::MAX_LAPS)
			throw new \InvalidArgumentException('Choose a finite race of 1 to '.Course::MAX_LAPS.' laps.');
		return $laps;
	}

	public static function document(array $spec): array
	{
		if(($spec['rules'] ?? null) !== self::RULES || ($spec['simulator'] ?? null) !== self::simulator()) throw new \InvalidArgumentException('Incompatible race rules.');
		$world = $spec['world'] ?? [];
		$laps = self::laps($spec['laps'] ?? null);
		$gates = $world['course']['gates'] ?? [];
		if(!is_array($gates) || count($gates) < 2 || ($gates[0]['role'] ?? '') !== 'start'
			|| ($gates[0]['laps'] ?? null) !== $laps || array_diff(array_column($gates,'kind'),['road'])
			|| ($laps !== 1 && in_array('finish',array_column($gates,'role'),true))
			|| ($world['circuit']['variant'] ?? 'dry') !== 'dry' || isset($world['journeyNetwork']))
			throw new \InvalidArgumentException('A dry road course with a start and at least one more gate is required.');
		return $world;
	}

	public function __construct(private readonly Landscape $land, private readonly Course $course,
		private readonly float $water, private readonly ?Circuit $circuit = null)
	{
		self::laps($course->laps());
		$gate = $course->start();
		$this->car = new Car;
		$this->race = new Race;
		$yaw = $gate['yaw'];
		$positions = $circuit ? [$circuit->approach()] : [];
		$lane = $course->measure($gate)['window']['halfWidth'] * .45;
		foreach([20.,10.,5.,3.] as $back) foreach([0.,$lane,-$lane] as $side)
			$positions[] = [$gate['x'] - $back*sin($yaw) + $side*cos($yaw),
				$gate['z'] - $back*cos($yaw) - $side*sin($yaw),$yaw];
		foreach($positions as [$x,$z,$heading]) {
			if(($x-$gate['x'])*sin($yaw)+($z-$gate['z'])*cos($yaw) >= -1.) continue;
			$site = $this->car->checkedSite($land,$water,$x,$z,$heading,$course->posts());
			if($site === null) continue;
			$this->car->spawn($site,$land,$water);
			return;
		}
		throw new \RuntimeException('There is no dry grid position behind the start.');
	}

	public static function controls(float $throttle,float $steer,bool $brake,bool $boost,bool $recover): string
	{
		return pack('ccC',(int)round(max(-1.,min(1.,$throttle))*127),(int)round(max(-1.,min(1.,$steer))*127),
			(int)$brake | ((int)$boost << 1) | ((int)$recover << 2));
	}

	private function recover(bool $flooded): bool
	{
		$site = RoadRecovery::site($this->car,$this->land,$this->water,$this->course,$this->race,$this->circuit,$flooded);
		if($site === null) return false;
		$this->car->recoverToRoad($site,$this->land,$this->water,$flooded);
		return true;
	}

	public function step(string $input,bool $record = true): void
	{
		if($this->dnf !== null || $this->race->state === 'finished') throw new \RuntimeException('The attempt has ended.');
		if(strlen($input) !== 3) throw new \InvalidArgumentException('Incomplete race input.');
		['throttle'=>$gas,'steer'=>$steer,'flags'=>$flags] = unpack('cthrottle/csteer/Cflags',$input);
		if($gas < -127 || $steer < -127 || $flags > 7) throw new \InvalidArgumentException('Invalid driving input.');
		if($flags & 4 && $this->race->state === 'running') $this->recover(false);
		$before = $this->car->state();
		$dt = 1/self::HZ;
		$drivable = $this->car->step($dt,$gas/127,$steer/127,$this->land,$this->water,(bool)($flags&1),
			$this->course->posts(),(bool)($flags&2));
		if(!$drivable && !($this->car->engineFlooded($this->water) && $this->recover(true))) $this->dnf = 'No safe recovery.';
		$after = $this->car->state();
		if($drivable && $before['recoveries'] === $after['recoveries']) {
			$event = $this->race->advance($this->course,[$before['x'],$before['y'],$before['z']],[$after['x'],$after['y'],$after['z']],
				$this->tick / self::HZ,$dt,'car',0);
			if($event !== null) {
				$splits = $this->race->status($this->course,$this->tick/self::HZ)['splits'];
				if($splits) $this->lastSplit = ['index'=>count($splits)-1,'at'=>end($splits)['at']];
			}
		}
		if($record) $this->inputs .= $input;
		$this->tick++;
		if($this->tick >= self::MAX_TICKS && $this->race->state !== 'finished') $this->dnf = 'Race time limit reached.';
	}

	/** Only a finish derived from the fixed-step simulation can produce a competitive tape. */
	public function result(): array
	{
		if($this->race->state !== 'finished' || $this->dnf !== null) throw new \RuntimeException('Finish every lap first.');
		return ['ticks'=>$this->tick,'inputs'=>base64_encode($this->inputs),
			'splits'=>$this->race->status($this->course,$this->tick/self::HZ)['splits']];
	}

	public function inputsSince(int $tick): string
	{
		return base64_encode(substr($this->inputs, max(0,$tick)*3));
	}

	public function pose(): array
	{
		$p = $this->car->state();
		return [$p['x'],$p['y'],$p['z'],$p['yaw'],$p['pitch'],$p['roll']];
	}

	/** Verified poses are derived locally. Network poses never determine checkpoint crossings or a win. */
	public function verify(array $run): \Generator
	{
		$ticks = $run['ticks'] ?? null;
		$encoded = $run['inputs'] ?? null;
		if(!is_int($ticks) || $ticks < 1 || $ticks > self::MAX_TICKS || !is_string($encoded) || strlen($encoded) !== $ticks*4)
			throw new \InvalidArgumentException('Invalid race recording length.');
		$bytes = base64_decode($encoded,true);
		if($bytes === false || strlen($bytes) !== $ticks*3) throw new \InvalidArgumentException('Invalid race recording.');
		$poses = [[0,...$this->pose()]];
		for($i=0;$i<$ticks;$i++) {
			$this->step(substr($bytes,3*$i,3),false);
			if($this->tick % 12 === 0 || $this->tick === $ticks) $poses[] = [$this->tick,...$this->pose()];
			yield intdiv(($i+1)*100,$ticks);
		}
		$result = $this->result();
		return ['ticks'=>$this->tick,'splits'=>$result['splits'],'poses'=>$poses];
	}
}
