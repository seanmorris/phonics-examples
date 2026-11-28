<?php
declare(strict_types=1);
namespace TerrainPhonics;

/** Two full-width maps and a connecting strip. A divided east end has a second, perpendicular strip. */
final class JoinedTerrain
{
	public const GAP = 4096.;
	private const EDGE_REACH = 256.;
	private array $profiles = [], $profileRange = [], $fields = [], $origins, $offsets = [], $parameters;

	/**
	 * Prepare neighboring terrain recipes and align their elevations to a shared sea level.
	 *
	 * @param array $source Source recipe and the join description containing the destination recipes.
	 * @return void No value.
	 */
	public function __construct(array $source)
	{
		$join = $source['join'];
		unset($source['join']);
		$span = $join['span'] ?? null;
		$east = $join['destinations'] ?? null;

		if(!in_array($span, [16384,32768,65536], true) || !is_array($east) || !array_is_list($east)
			|| count($east) < 1 || count($east) > 2
		) throw new \InvalidArgumentException('Invalid landscape junction.');

		$extent = 2 * $span + self::GAP;
		$source['worldSize'] = $span;
		$first = new Recipe($source);
		$r = $first->values();

		if($extent / (2 * $r['spacing'] * $r['tileSize']) > Landscape::TILE_REACH)
		throw new \InvalidArgumentException('Terrain tiles are too fine for the joined map.');

		$this->fields[] = $first;
		$this->offsets[] = 0.;
		$this->origins = self::origins($span, count($east));

		foreach($east as $i => $recipe)
		{
			if(!is_array($recipe)) throw new \InvalidArgumentException('Invalid destination recipe.');

			$field = new Recipe(['worldSize' => $span] + $recipe);
			$this->fields[] = $field;
			$east[$i] = $field->values();
			$this->offsets[] = $r['waterLevel'] - $east[$i]['waterLevel'];
		}

		$version = $join['version'] ?? 1;

		if(!in_array($version, [1,2], true)) throw new \InvalidArgumentException('Unknown landscape junction version.');

		if($version === 2)
		foreach([$r,...$east] as $form)
		{
			// A landscape with no cliff contributes a gentle shoulder to the shared contour profile.
			$this->profiles[] = [
				$form['ridgeRoundness'],$form['floorWidth']
				, $form['wallHeight'] > 0. ? $form['wallWidth'] : .5
			];
		}

		if($this->profiles)
		foreach([0,1,2] as $k)
		{
			$values = array_column($this->profiles, $k);
			$this->profileRange[0][$k] = min($values);
			$this->profileRange[1][$k] = max($values);
		}

		$r['worldSize'] = $extent;
		$r['join'] = ['span' => $span,'destinations' => $east];

		if($version === 2) $r['join']['version'] = 2;

		$this->parameters = $r;
	}

	/**
	 * Position the source and destination landscapes around the connecting region.
	 *
	 * @param int $span Width of each participating landscape in meters.
	 * @param int $destinations Number of outgoing landscapes.
	 * @return array Local coordinate origins for the participating landscapes.
	 */
	public static function origins(int $span, int $destinations): array
	{
		$center = ($span + self::GAP) / 2;

		return $destinations === 1
			? [[-$center,0.],[$center,0.]]
			: [[-$center,0.],[$center,-$center],[$center,$center]];
	}

	/**
	 * Read the normalized joint landscape definition.
	 *
	 * @return array Parameters needed to rebuild this joined landscape.
	 */
	public function values(): array { return $this->parameters; }

	/**
	 * Ease the transition between the facing landscape edges.
	 *
	 * @param float $axis Position along the transition axis.
	 * @return float Blend weight along the connector.
	 */
	private static function blend(float $axis): float
	{
		$t = $axis / self::GAP + .5;

		return $t * $t * $t * ($t * ($t * 6. - 15.) + 10.);
	}

	/**
	 * Evaluate one landscape in its local coordinates with the shared elevation offset.
	 *
	 * @param int $index Index of the terrain recipe to sample.
	 * @param float $x Horizontal X coordinate.
	 * @param float $z Horizontal Z coordinate.
	 * @param ?array $profile Optional interpolated terrain shape parameters.
	 * @return float Terrain elevation in the joined world.
	 */
	private function at(int $index, float $x, float $z, ?array $profile = null): float
	{
		[$cx,$cz] = $this->origins[$index];

		if($profile !== null)
		{
			$x = self::extendEdge($x, $index === 0 ? -1 : 1);

			if($index > 0 && count($this->fields) === 3) $z = self::extendEdge($z, $index === 1 ? -1 : 1);
		}

		return $this->fields[$index]->height($x - $cx, $z - $cz, $profile) + $this->offsets[$index];
	}

	/**
	 * Carry the facing edge's contours into the connector. The derivative is one at the boundary, then falls smoothly
	 * so distant, unrelated cliffs do not enter the fork.
	 *
	 * @param float $axis Position along the transition axis.
	 * @param int $side Sign of the edge being extended.
	 * @return float Coordinate used to sample the extended edge.
	 */
	private static function extendEdge(float $axis, int $side): float
	{
		$distance = .5 * self::GAP - $side * $axis;

		if($distance <= 0.) return $axis;

		return $side * (.5 * self::GAP - self::EDGE_REACH * $distance / (self::EDGE_REACH + $distance));
	}

	/**
	 * Sample the continuous terrain through the source, fork and destinations.
	 *
	 * @param float $x Horizontal X coordinate.
	 * @param float $z Horizontal Z coordinate.
	 * @return float World elevation in meters.
	 */
	public function height(float $x, float $z): float
	{
		$edge = self::GAP / 2;

		if($x <= -$edge) return $this->at(0, $x, $z);

		if($this->profiles)
		{
			$wx = $x >= $edge ? 1. : min(1., self::blend($x));
			$wz = count($this->fields) === 2 || $z <= -$edge ? 0. : ($z >= $edge ? 1. : min(1., self::blend($z)));

			if($x >= $edge && (count($this->fields) === 2 || abs($z) >= $edge))
			return $this->at(count($this->fields) === 2 || $z <= -$edge ? 1 : 2, $x, $z);

			$weights = [1. - $wx,$wx * (1. - $wz),$wx * $wz];
			$profile = [0.,0.,0.];

			foreach($this->profiles as $i => $p) foreach($p as $k => $value) $profile[$k] += $weights[$i] * $value;
			$h = 0.;

			foreach($weights as $i => $weight) if($weight > 0.) $h += $weight * $this->at($i, $x, $z, $profile);

			return $h;
		}

		if(count($this->fields) === 2 || $z <= -$edge) $east = $this->at(1, $x, $z);
		elseif($z >= $edge) $east = $this->at(2, $x, $z);
		else
		{
			$north = $this->at(1, $x, $z);
			$east = $north + self::blend($z) * ($this->at(2, $x, $z) - $north);
		}

		if($x >= $edge) return $east;

		$west = $this->at(0, $x, $z);

		return $west + self::blend($x) * ($east - $west);
	}

	/**
	 * Bound elevations inside a horizontal rectangle of the joined world.
	 *
	 * @param array $box Horizontal bounds in minimum and maximum X and Z order.
	 * @return array Conservative minimum and maximum elevation.
	 */
	public function regionalBounds(array $box): array { return $this->interval($box); }

	/**
	 * Bound elevations across all participating landscapes.
	 *
	 * @return array Conservative minimum and maximum elevation for the whole join.
	 */
	public function bounds(): array { return $this->interval(null); }

	/**
	 * Combine the elevation intervals of the contributing regions.
	 *
	 * @param ?array $box Optional region for the bound, or null to include the whole join.
	 * @return array Minimum and maximum elevation including the sea-level offsets.
	 */
	private function interval(?array $box): array
	{
		$low = INF;
		$high = -INF;
		$edge = self::GAP / 2;
		$roundness = $this->profileRange[1][0] ?? null;

		foreach($this->fields as $i => $field)
		{
			if($box === null) [$a,$b] = $field->bounds($roundness);
			else
			{
				if(($i === 0 && $box[0] > $edge) || ($i > 0 && $box[2] < -$edge)
					|| (count($this->fields) === 3 && (($i === 1 && $box[1] > $edge) || ($i === 2 && $box[3] < -$edge)))
				)
				continue;

				[$x,$z] = $this->origins[$i];
				$blended = $this->profiles && (($box[0] < $edge && $box[2] > -$edge)
					|| (count($this->fields) === 3 && $box[2] >= $edge && $box[1] < $edge && $box[3] > -$edge)
				);

				$region = $box;

				if($blended)
				{
					// The edge extension is monotone on each axis. Its two ends bound
					// every coordinate sampled inside this region, including the pure side.
					foreach([0,2] as $k) $region[$k] = self::extendEdge($region[$k], $i === 0 ? -1 : 1);

					if($i > 0 && count($this->fields) === 3)
					foreach([1,3] as $k) $region[$k] = self::extendEdge($region[$k], $i === 1 ? -1 : 1);
				}

				[$a,$b] = $field->regionalBounds(
					[$region[0] - $x,$region[1] - $z,$region[2] - $x,$region[3] - $z]
					, $blended ? $this->profileRange : null
				);
			}

			$low = min($low, $a + $this->offsets[$i]);
			$high = max($high, $b + $this->offsets[$i]);
		}

		return [$low,$high];
	}
}
