<?php
declare(strict_types=1);

namespace TerrainSandbox;

use TerrainPhonics\Edits;

/** A working road in the Lab. Only Apply publishes a terrain transaction. */
final class RoadEditor
{
	public bool $open = false;
	public ?array $view = null;
	private ?int $target = null;
	private array $proposed = [], $generated = []; // Negative selection IDs address unpublished circuit sections.
	private ?int $publishingSection = null;
	private array $draft = [], $original = [], $sketchOptions = [];
	private int $selection = -1, $generation = -1, $revision = -1, $sequence = 0, $sent = -1;
	private bool $catalog = true, $applying = false;
	private array $lastSketch = [], $lastDefaults = [];
	public ?array $captured = null;
	private string $message = '';

	private function defaults(Session $s): array
	{
		return ['type'=>'road', 'width'=>$s->settings['roadWidth'], 'shoulder'=>$s->settings['roadShoulder'],
			'crown'=>.12, 'material'=>'road'];
	}

	private function working(Session $s): array
	{
		return $this->target === null
			? ['points'=>$s->road] + $this->sketchOptions + $this->defaults($s)
			: $this->draft;
	}

	private function store(Session $s, array $road): void
	{
		if($this->target !== null) {
			$this->draft = $road;
			if($this->target < 0) {
				// Adjacent draft sections share a station, including across the start/finish line.
				$n = count($this->proposed);
				if($n > 1) {
					$section = -1-$this->target;
					foreach([-1,1] as $direction) {
						$id = -1-(($section+$direction+$n)%$n);
						$source = $direction < 0 ? 0 : array_key_last($road['points']);
						$dest = $direction < 0 ? array_key_last($this->proposed[$id]['points']) : 0;
						$this->proposed[$id]['points'][$dest] = $road['points'][$source];
						$this->proposed[$id]['banks'][$dest] = $road['banks'][$source];
					}
				}
				$this->proposed[$this->target] = $road;
				$this->catalog = true;
			}
			return;
		}
		$s->road = $road['points'];
		unset($road['points']);
		foreach(['width'=>'roadWidth','shoulder'=>'roadShoulder'] as $key=>$setting)
			if($road[$key] == $s->settings[$setting]) unset($road[$key]);
		$this->sketchOptions = $road;
	}

	/** Used by the original Shape controls as well as the map. */
	public function sketch(Session $s): array
	{
		return ['points'=>$s->road] + $this->sketchOptions + $this->defaults($s);
	}

	private static function groundPoint(float $offset): array
	{
		return ['offset'=>$offset,'in'=>true,'out'=>true];
	}

	public function sketchPointAdded(int $count, float $offset): void
	{
		$this->sketchOptions['_ground'] ??= array_fill(0,$count-1,null);
		$this->sketchOptions['_ground'][] = self::groundPoint($offset);
		if(isset($this->sketchOptions['handles'])) $this->sketchOptions['handles'][] = null;
		if(isset($this->sketchOptions['banks'])) $this->sketchOptions['banks'][] = 0.;
	}

	public function clearSketch(): void
	{
		$this->sketchOptions = [];
		if($this->target === null) $this->selection = -1;
		$this->sequence++;
	}

	/** A new file or recipe has its own edit indices, camera and map extent. */
	public function reset(): void
	{
		$this->target = $this->publishingSection = null;
		$this->proposed = $this->generated = [];
		$this->original = $this->draft = $this->sketchOptions = [];
		$this->selection = -1;
		$this->captured = $this->view = null;
		$this->applying = false;
		$this->catalog = true;
		$this->message = '';
		$this->sequence++;
	}

	public function circuitDrafts(array $sections): void
	{
		$keepManual = $this->target !== null && $this->target >= 0 && $this->draft !== $this->original;
		if($this->target !== null && $this->target < 0) $this->select([],null);
		$this->generated = $this->proposed = [];
		foreach($sections as $i=>$road) $this->proposed[-1-$i] = $road;
		$this->generated = $this->proposed;
		if($sections && !$keepManual) $this->select($this->proposed,-1);
		$this->catalog = true;
		$this->sequence++;
	}

	public function circuitRoads(): array { return array_values($this->proposed); }
	public function circuitEdited(): bool { return $this->proposed !== $this->generated; }
	public function onCircuit(): bool { return $this->target !== null && $this->target < 0; }

	public function publishingCircuit(): void
	{
		$this->publishingSection = $this->onCircuit() ? -1-$this->target : 0;
	}

	private function select(array $records, ?int $index): void
	{
		if($index !== null && !isset($records[$index])) throw new \InvalidArgumentException('This road has changed.');
		$this->target = $index;
		$this->draft = $index === null ? [] : $records[$index];
		$this->original = $this->generated[$index] ?? $this->draft;
		$this->selection = $index === null ? -1 : 0;
	}

	public function published(Session $s): void
	{
		if($this->proposed) {
			$id = null;
			if($this->publishingSection !== null) {
				$added = array_slice(array_keys($s->landscape->roadRecords()),-count($this->proposed));
				$id = $added[$this->publishingSection] ?? null;
			}
			$this->publishingSection = null;
			$this->circuitDrafts([]);
			if($id !== null) $this->select($s->landscape->roadRecords(),$id);
		}
		if($this->applying) $this->sync($s);
	}

	private function sync(Session $s): void
	{
		$revision = $s->landscape->revision();
		if($this->generation === $s->generation && $this->revision === $revision) return;
		$this->generation = $s->generation;
		$this->revision = $revision;
		$this->catalog = true;
		if(!$this->onCircuit() && ($this->target !== null || $this->applying)) {
			$records = $s->landscape->roadRecords();
			$id = $this->applying && $this->target === null ? array_key_last($records) : $this->target;
			if($this->applying && $this->target === null) { $s->road = []; $this->clearSketch(); }
			if($this->applying || ($records[$id] ?? []) !== $this->original)
				$this->select($records, isset($records[$id]) ? $id : null);
		}
		if($this->applying) $this->message = 'Road applied.';
		$this->applying = false;
	}

	private static function number(mixed $value, float $min, float $max): float
	{
		if((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value < $min || $value > $max)
			throw new \InvalidArgumentException("Enter a number between $min and $max.");
		return (float)$value;
	}

	private static function mapPoint(Session $s, array $action): array
	{
		$half = $s->landscape->recipe()['worldSize']/2;
		return [self::number($action['x'] ?? null, -$half, $half), self::number($action['z'] ?? null, -$half, $half)];
	}

	/** Split a cubic without changing its path, including the automatic handles on the neighboring spans. */
	private static function split(array &$road, int $i, float $t): void
	{
		$points = $road['points'];
		$handles = $road['handles'] ?? array_fill(0,count($points),null);
		$a = $points[$i]; $d = $points[$i+1];
		$left = Edits::roadHandles($points,$handles,$i);
		$right = Edits::roadHandles($points,$handles,$i+1);
		$plus = static fn($p,$q) => array_map(static fn($x,$y) => $x+$y,$p,$q);
		$minus = static fn($p,$q) => array_map(static fn($x,$y) => $x-$y,$p,$q);
		$mix = static fn($p,$q) => array_map(static fn($x,$y) => $x+($y-$x)*$t,$p,$q);
		$b = $plus($a,$left['out']); $c = $plus($d,$right['in']);
		$ab = $mix($a,$b); $bc = $mix($b,$c); $cd = $mix($c,$d);
		$abc = $mix($ab,$bc); $bcd = $mix($bc,$cd); $point = $mix($abc,$bcd);
		$handles[$i] = ['out'=>$minus($ab,$a)];
		if($i > 0) $handles[$i]['in'] = $left['in'];
		$handles[$i+1] = ['in'=>$minus($cd,$d)];
		if($i+2 < count($points)) $handles[$i+1]['out'] = $right['out'];
		array_splice($handles,$i+1,0,[['in'=>$minus($abc,$point),'out'=>$minus($bcd,$point)]]);
		array_splice($road['points'],$i+1,0,[$point]);
		$road['handles'] = $handles;
		if(isset($road['banks'])) array_splice($road['banks'],$i+1,0,[$road['banks'][$i]*(1-$t)+$road['banks'][$i+1]*$t]);
	}

	public function action(Session $s, array $a): ?array
	{
		$this->sync($s);
		$op = $a['op'] ?? '';
		if($op !== 'view') $this->sequence++;
		try {
			if($op === 'open' || $op === 'close') {
				$this->open = $op === 'open';
				$this->catalog = true; $this->view = $this->captured = null;
				return null;
			}
			if(!$this->open) return null;
			if(($a['generation'] ?? null) !== $s->generation || ($a['revision'] ?? null) !== $s->landscape->revision())
				throw new \RuntimeException('The landscape changed. Select the road again.');
			if($op === 'view') {
				[$x,$z] = self::mapPoint($s,$a);
				$this->view = [$x,$z,self::number($a['span'] ?? null,1,$s->landscape->recipe()['worldSize'])];
				return null;
			}
			if($this->applying || $s->preparing || $s->picking) throw new \RuntimeException('Wait for the terrain edit to finish.');
			if($op === 'select') {
				$this->select($s->landscape->roadRecords()+$this->proposed,$a['id'] ?? null);
				$this->message = '';
				return null;
			}
			if(($a['id'] ?? null) !== $this->target) throw new \RuntimeException('This road has changed.');
			$road = $this->working($s);
			$count = count($road['points']);
			$closed = $count>=4 && $road['points'][0] === $road['points'][$count-1];
			$i = $a['index'] ?? -1;
			$this->message = '';
			if($op === 'circuit') {
				if($this->onCircuit() || $s->mode !== 'fly') throw new \RuntimeException('Choose a road in free flight to create a circuit.');
				$road = CircuitRoad::closeLoop($road);
				return $road + ($this->target === null ? [] : ['replaceAt'=>$this->target]);
			}
			if($op === 'apply') {
				if($count < 2) throw new \RuntimeException('Place at least two road points.');
				return $road + ($this->target === null || $this->onCircuit() ? [] : ['replaceAt'=>$this->target]);
			}
			if($op === 'revert') {
				if($this->target === null) { $s->road = []; $this->clearSketch(); }
				elseif($this->onCircuit()) {
					$this->store($s,$this->original);
					$this->select($this->proposed,$this->target);
				} else $this->select($s->landscape->roadRecords(),$this->target);
				return null;
			}
			if($op === 'section') {
				$key = $a['key'] ?? '';
				$bounds = ['width'=>[.5,1024.],'shoulder'=>[.01,1024.],'crown'=>[-1024.,1024.]];
				if(!isset($bounds[$key])) throw new \InvalidArgumentException('Unknown road dimension.');
				$road[$key] = self::number($a['value'] ?? null,...$bounds[$key]);
			} elseif($op === 'add') {
				if($count >= 128) throw new \LengthException('A road can have up to 128 points.');
				[$x,$z] = self::mapPoint($s,$a);
				$this->selection = $closed ? $count-1 : $count;
				$offset = $s->settings['roadRaise'];
				$point = [$x,$s->landscape->height($x,$z)+$offset,$z];
				if(!$this->onCircuit()) {
					// A newly extended saved road meets its old deck at the existing end.
					if(!isset($road['_ground'])) {
						$road['_ground'] = array_fill(0,$count,null);
						$ends = $closed ? [$count-2=>'out',$count-1=>'in'] : [$count-1=>'out'];
						$half = $s->landscape->recipe()['worldSize']/2;
						foreach($ends as $end=>$side) {
							if(!isset($road['points'][$end])) continue;
							[$px,$py,$pz] = $road['points'][$end];
							if(max(abs($px),abs($pz))<=$half)
								$road['_ground'][$end] = [$side=>true,'offset'=>$py-$s->landscape->height($px,$pz)];
						}
					}
					array_splice($road['_ground'],$this->selection,0,[self::groundPoint($offset)]);
				}
				array_splice($road['points'],$this->selection,0,[$point]);
				if(isset($road['handles'])) array_splice($road['handles'],$this->selection,0,[null]);
				if(isset($road['banks'])) array_splice($road['banks'],$this->selection,0,[0.]);
			} else {
				if(!is_int($i) || $i < 0 || $i >= $count) throw new \InvalidArgumentException('Select a point on the road.');
				$this->selection = $i;
				switch($op) {
					case 'insert':
						if($i+1 >= $count || $count >= 128) throw new \InvalidArgumentException('Select a span with room for another point.');
						$t = self::number($a['t'] ?? .5,.000001,.999999);
						self::split($road,$i,$t);
						if(isset($road['_ground'])) {
							$mark = null;
							if(isset($road['_ground'][$i]['out'],$road['_ground'][$i+1]['in'])) {
								$offset = (1-$t)*$road['_ground'][$i]['offset']+$t*$road['_ground'][$i+1]['offset'];
								[$x,,$z] = $road['points'][$i+1];
								$half = $s->landscape->recipe()['worldSize']/2;
								if(max(abs($x),abs($z))<=$half) {
									$road['points'][$i+1][1] = $s->landscape->height($x,$z)+$offset;
									$mark = self::groundPoint($offset);
								}
							}
							array_splice($road['_ground'],$i+1,0,[$mark]);
						}
						$this->selection = $i+1;
						break;
					case 'remove':
						if($this->onCircuit() && $count <= ($closed ? 4 : 2))
							throw new \InvalidArgumentException('Keep the circuit section connected.');
						if($closed && $count>4 && ($i===0 || $i===$count-1)) {
							foreach(['points','handles','banks','_ground'] as $key) if(isset($road[$key])) {
								array_shift($road[$key]);
								$road[$key][$count-2] = $road[$key][0];
							}
							$this->selection = 0;
						} else {
							foreach(['points','handles','banks','_ground'] as $key) if(isset($road[$key])) array_splice($road[$key],$i,1);
							$this->selection = min($i,$count-2);
						}
						break;
					case 'point':
						[$x,$z] = self::mapPoint($s,$a);
						[$px,$py,$pz] = $road['points'][$i];
						$y = self::number($a['y'] ?? $py,-1e6,1e6);
						if(isset($road['_ground'][$i])) {
							if(($a['vertical'] ?? false) || abs($y-$py)>1e-7) $road['_ground'][$i] = null;
							elseif($x!=$px || $z!=$pz) $y = $s->landscape->height($x,$z)+$road['_ground'][$i]['offset'];
						}
						$road['points'][$i] = [$x,$y,$z];
						break;
					case 'ground':
						[$x,,$z] = $road['points'][$i];
						$offset = $s->settings['roadRaise'];
						$road['points'][$i][1] = $s->landscape->height($x,$z)+$offset;
						if(!$this->onCircuit()) {
							$road['_ground'] ??= array_fill(0,$count,null);
							$road['_ground'][$i] = self::groundPoint($offset);
						}
						break;
					case 'bank':
						$road['banks'] ??= array_fill(0,$count,0.);
						$road['banks'][$i] = self::number($a['value'] ?? null,-35.,35.)/100.;
						break;
					case 'handle':
					case 'auto':
						$side = $a['side'] ?? '';
						if(!(($side === 'in' && $i > 0) || ($side === 'out' && $i+1 < $count)))
							throw new \InvalidArgumentException('Choose a handle on a connected span.');
						$road['handles'] ??= array_fill(0,$count,null);
						if($op === 'auto') {
							unset($road['handles'][$i][$side]);
							if(isset($road['_ground'][$i])) $road['_ground'][$i][$side] = true;
						}
						else {
							$vector = $a['vector'] ?? null;
							if(!is_array($vector) || !array_is_list($vector) || count($vector) !== 3)
								throw new \InvalidArgumentException('A curve handle needs three offsets.');
							$vector = array_map(static fn($v) => self::number($v,-1e6,1e6),$vector);
							$old = Edits::roadHandles($road['points'],$road['handles'],$i)[$side];
							if(($a['vertical'] ?? false) || abs($vector[1]-$old[1])>1e-7) unset($road['_ground'][$i][$side]);
							$road['handles'][$i][$side] = $vector;
						}
						if(empty($road['handles'][$i])) $road['handles'][$i] = null;
						if(!array_filter($road['handles'])) unset($road['handles']);
						break;
					default: throw new \InvalidArgumentException('Unknown road editor action.');
				}
			}
			if($closed && ($i===0 || $i===$count-1)) {
				$other = $i===0 ? $count-1 : 0;
				if($op==='point' || $op==='ground') {
					$road['points'][$other] = $road['points'][$i];
					if(isset($road['_ground']) && ($op==='ground' || $road['_ground'][$i]===null))
						$road['_ground'][$other] = $road['_ground'][$i];
				}
				if($op==='bank') $road['banks'][$other] = $road['banks'][$i];
			}
			$this->store($s,$road);
		} catch(\Throwable $error) {
			$this->message = $error->getMessage();
			if($op === 'view') $this->sequence++;
		}
		return null;
	}

	public function applyResult(?string $error): void
	{
		$this->sequence++;
		$this->message = $error ?? 'Preparing road.';
		$this->applying = $error === null;
	}

	/** An error in another action in the same transaction discards the entire terrain candidate. */
	public function cancelPending(string $error): void
	{
		$this->publishingSection = null;
		if($this->applying) $this->applyResult($error);
	}

	/** Send road records at revision boundaries and working geometry only when an editor input changes it. */
	public function packet(Session $s): ?array
	{
		if(!$this->open) return null;
		$this->sync($s);
		$defaults = $this->defaults($s);
		if(!$this->catalog && $this->sent === $this->sequence && $this->lastSketch === $s->road && $this->lastDefaults === $defaults) return null;
		$road = $this->working($s);
		$changed = $this->target === null ? (bool)$s->road : $road !== $this->original;
		$fit = false;
		if(isset($road['_ground']) && RoadGrading::availableKnots($road['points'])) {
			foreach($road['_ground'] as $i=>$point) {
				if(isset($point['out'],$road['_ground'][$i+1]['in'])) { $fit = true; break; }
			}
		}
		unset($road['_ground']);
		$packet = ['id'=>$this->target,'road'=>$road,'selection'=>min($this->selection,count($road['points'])-1),
			'changed'=>$changed,'fitOnApply'=>$fit,'proposed'=>$this->onCircuit(),
			'generation'=>$s->generation,'revision'=>$s->landscape->revision(),'extent'=>$s->landscape->recipe()['worldSize'],
			'camera'=>[$s->camera->position[0],$s->camera->position[2]],'message'=>$this->message];
		if($this->catalog) {
			$packet['roads'] = [];
			foreach($s->landscape->roadRecords()+$this->proposed as $id=>$record) $packet['roads'][] = ['id'=>$id,'road'=>$record];
		}
		$this->sent = $this->sequence; $this->lastSketch = $s->road; $this->lastDefaults = $defaults; $this->catalog = false;
		return $packet;
	}
}
