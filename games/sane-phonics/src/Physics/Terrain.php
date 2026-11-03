<?php
declare(strict_types=1);
namespace SanePhonics\Physics;
use Phonics\Physics\Hit;

/** Platformer interpretation of neutral collision geometry. */
final class Terrain extends \Phonics\Physics\Terrain
{
	private array $switches = [];

	/**
	 * Preserve the platformer's named grinding argument on permanent geometry.
	 * @param array $points World-space vertices.
	 * @param bool $oneWay Whether passage from behind is allowed.
	 * @param int $collisionLayer Collision plane.
	 * @param array|bool $grinding Rail annotation or neutral surface metadata.
	 * @return void Adds permanent geometry.
	 */
	public function add(
		array $points
		, bool $oneWay = false
		, int $collisionLayer = 0
		, array|bool $grinding = false
	): void {
		parent::add($points, $oneWay, $collisionLayer, $grinding);
	}

	/**
	 * Preserve named platformer arguments on removable geometry.
	 * @param array $points World-space vertices.
	 * @param bool $oneWay Whether passage from behind is allowed.
	 * @param int $collisionLayer Collision plane.
	 * @param array|bool $grinding Rail annotation or neutral metadata.
	 * @param bool $directedOneWay Whether the authored normal is retained.
	 * @param bool $open Whether the vertices form an open path.
	 * @return int Removable geometry handle.
	 */
	public function addShape(
		array $points
		, bool $oneWay = false
		, int $collisionLayer = 0
		, array|bool $grinding = false
		, bool $directedOneWay = false
		, bool $open = false
	): int {
		return parent::addShape($points, $oneWay, $collisionLayer, $grinding, $directedOneWay, $open);
	}

	/**
	 * Preserve named platformer arguments on open paths.
	 * @param array $points World-space vertices.
	 * @param bool $oneWay Whether passage from behind is allowed.
	 * @param int $collisionLayer Collision plane.
	 * @param array|bool $grinding Rail annotation or neutral metadata.
	 * @return int Removable geometry handle.
	 */
	public function addPathShape(
		array $points
		, bool $oneWay = false
		, int $collisionLayer = 0
		, array|bool $grinding = false
	): int {
		return parent::addPathShape($points, $oneWay, $collisionLayer, $grinding);
	}

	/**
	 * Retain legacy shape annotations while the core stores neutral surface tags.
	 *
	 * @param array $shapes Initial collision shapes and surface annotations.
	 * @param int $cellSize Broadphase grid cell size in world pixels.
	 * @return void Completes the operation.
	 */
	public function __construct(array $shapes = [], int $cellSize = 64)
	{
		foreach($shapes as &$shape) $shape['surfaceTag'] = $shape['grinding'] ?? false;
		unset($shape);
		parent::__construct($shapes, $cellSize);
	}

	/**
	 * Add an open scripted rail path as upward-facing one-way collision segments.
	 *
	 * @param list<array{float|int,float|int}> $points Ordered world-space rail vertices.
	 * @param int $collisionLayer Nonnegative plane ID; zero is common to every body.
	 * @return void Adds every consecutive path segment with grinding metadata.
	 */
	public function addRail(array $points, int $collisionLayer = 0): void
	{
		if(count($points) < 2)
		{
			throw new \InvalidArgumentException('Rails require at least two vertices');
		}

		foreach($points as $point)
		{
			if(!is_array($point) || !isset($point[0], $point[1])
				|| !is_numeric($point[0]) || !is_numeric($point[1])
				|| !is_finite((float)$point[0]) || !is_finite((float)$point[1])
			){
				throw new \InvalidArgumentException('Rail vertices must be finite coordinates');
			}
		}

		for($i = 1; $i < count($points); $i++)
		{
			$segment = [$points[$i - 1], $points[$i]];

			if($segment[1][0] < $segment[0][0])
			{
				$segment = array_reverse($segment);
			}

			$this->add($segment, true, $collisionLayer, true);
		}
	}

	/**
	 * Register a nonsolid directional gate.
	 *
	 * @param LayerSwitch $switch Validated world-space gate to retain.
	 * @return void Appends the region for subsequent movement queries.
	 */
	public function addSwitch(LayerSwitch $switch): void { $this->switches[] = $switch; }

	/**
	 * Find the first eligible gate crossed by a body center, independent of object order.
	 *
	 * @param Body $body Actor providing the start point, direction, and selected collision layer.
	 * @param float $dx Full proposed horizontal displacement in pixels.
	 * @param float $dy Full proposed vertical displacement in pixels.
	 * @param array<int,bool> $seen Gate IDs already considered during this sweep, which must be
	 * skipped.
	 * @return array{id:int,t:float,switch:LayerSwitch}|null Earliest gate with its internal ID and
	 * entry fraction, or null when none is eligible.
	 */
	public function nextSwitch(Body $body, float $dx, float $dy, array $seen = []): ?array
	{
		$best = null;

		foreach($this->switches as $id => $switch)
		{
			if(isset($seen[$id]) || $switch->target($body) === null)
			{
				continue;
			}

			$t = $switch->crossing($body->x, $body->y, $dx, $dy);

			if($t !== null && ($best === null || $t < $best['t']))
			{
				$best = ['id' => $id,'t' => $t,'switch' => $switch];
			}
		}

		return $best;
	}

	/**
	 * Apply an eligible route change only when newly solid geometry leaves the body clear.
	 *
	 * @param Body $body Actor to update in place; the caller must already have reached the gate.
	 * @param LayerSwitch $switch Reached gate whose directional rule chooses the destination.
	 * @return bool True when the selected collision layer changed; false when ineligible or
	 * blocked.
	 */
	public function applySwitch(Body $body, LayerSwitch $switch): bool
	{
		$target = $switch->target($body);

		if($target === null || !$this->canSwitch($body, $target))
		{
			return false;
		}

		$body->collisionLayer = $target;

		return true;
	}
}
