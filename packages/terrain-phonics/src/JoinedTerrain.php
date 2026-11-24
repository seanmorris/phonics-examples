<?php
declare(strict_types=1);
namespace TerrainPhonics;

/** Two full-width maps and a connecting strip. A divided east end has a second, perpendicular strip. */
final class JoinedTerrain
{
	public const GAP = 4096.;
	private array $fields = [], $origins, $offsets = [], $parameters;

	public function __construct(array $source)
	{
		$join = $source['join'];
		unset($source['join']);
		$span = $join['span'] ?? null;
		$east = $join['destinations'] ?? null;
		if(!in_array($span,[32768,65536],true) || !is_array($east) || !array_is_list($east)
			|| count($east)<1 || count($east)>2) throw new \InvalidArgumentException('Invalid landscape junction.');
		$extent = 2*$span + self::GAP;
		$source['worldSize'] = $span;
		$first = new Recipe($source);
		$r = $first->values();
		if($extent / (2*$r['spacing']*$r['tileSize']) > Landscape::TILE_REACH)
			throw new \InvalidArgumentException('Terrain tiles are too fine for the joined map.');
		$this->fields[] = $first;
		$this->offsets[] = 0.;
		$this->origins = self::origins($span,count($east));
		foreach($east as $i=>$recipe) {
			if(!is_array($recipe)) throw new \InvalidArgumentException('Invalid destination recipe.');
			$field = new Recipe(['worldSize'=>$span] + $recipe);
			$this->fields[] = $field;
			$east[$i] = $field->values();
			$this->offsets[] = $r['waterLevel'] - $east[$i]['waterLevel'];
		}
		$r['worldSize'] = $extent;
		$r['join'] = ['span'=>$span,'destinations'=>$east];
		$this->parameters = $r;
	}

	public static function origins(int $span, int $destinations): array
	{
		$center = ($span + self::GAP)/2;
		return $destinations === 1 ? [[-$center,0.],[$center,0.]] : [[-$center,0.],[$center,-$center],[$center,$center]];
	}

	public function values(): array { return $this->parameters; }

	private static function blend(float $axis): float
	{
		$t = $axis/self::GAP + .5;
		return $t*$t*$t*($t*($t*6.-15.)+10.);
	}

	private function at(int $index, float $x, float $z): float
	{
		[$cx,$cz] = $this->origins[$index];
		return $this->fields[$index]->height($x-$cx,$z-$cz) + $this->offsets[$index];
	}

	public function height(float $x, float $z): float
	{
		$edge = self::GAP/2;
		if($x <= -$edge) return $this->at(0,$x,$z);
		if(count($this->fields) === 2 || $z <= -$edge) $east = $this->at(1,$x,$z);
		elseif($z >= $edge) $east = $this->at(2,$x,$z);
		else {
			$north = $this->at(1,$x,$z);
			$east = $north + self::blend($z)*($this->at(2,$x,$z)-$north);
		}
		if($x >= $edge) return $east;
		$west = $this->at(0,$x,$z);
		return $west + self::blend($x)*($east-$west);
	}

	public function regionalBounds(array $box): array { return $this->interval($box); }
	public function bounds(): array { return $this->interval(null); }

	private function interval(?array $box): array
	{
		$low = INF; $high = -INF;
		$edge = self::GAP/2;
		foreach($this->fields as $i=>$field) {
			if($box === null) [$a,$b] = $field->bounds();
			else {
				if(($i===0 && $box[0]>$edge) || ($i>0 && $box[2]<-$edge)
					|| (count($this->fields)===3 && (($i===1 && $box[1]>$edge) || ($i===2 && $box[3]<-$edge)))) continue;
				[$x,$z] = $this->origins[$i];
				[$a,$b] = $field->regionalBounds([$box[0]-$x,$box[1]-$z,$box[2]-$x,$box[3]-$z]);
			}
			$low = min($low,$a+$this->offsets[$i]); $high = max($high,$b+$this->offsets[$i]);
		}
		return [$low,$high];
	}
}
