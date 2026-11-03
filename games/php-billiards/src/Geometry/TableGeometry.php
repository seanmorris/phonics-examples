<?php
declare(strict_types=1);
namespace PhpBilliards\Geometry;

use PhpBilliards\Physics\Ball;
use PhpBilliards\Physics\Table;

/**
 * Canonical render profiles derived from the same table dimensions used by physics.
 *
 * The class defines the shared CSG boundaries, pocket cuts, rail paths, and material heights that keep the
 * rendered bed, cushions, liners, and wooden body coincident with collision and pocket-entry geometry.
 */
final class TableGeometry
{
	public const COLUMNS = 432;
	public const ROWS = 240;
	public const CUSHION_TOP_SEGMENTS = 6;
	public const POCKET_SEGMENTS = 96;
	public const TABLETOP_TOP = .012;
	public const POCKET_LINER_TOP = .0095;
	/** Height where the apron body ends and the wooden rail begins; the two meet here with no gap. */
	public const APRON_TOP = -.042;
	/** Heights of the liner's narrowest throat ring and its open lower end. */
	public const POCKET_THROAT = -.09;
	public const POCKET_FLOOR = -.235;
	/** Inward draft of the upper liner cone in degrees. */
	public const POCKET_DRAFT_DEGREES = 13.5;
	public const WOOD_EDGE_RADIUS = .018;
	/** Height of the wooden rail's top surface. */
	public const RAIL_TOP = .105;
	/** How far the wood's inner edge sits beyond the playfield, and that edge's corner radius. */
	public const TABLETOP_MARGIN = .052;
	public const TABLETOP_CORNER_RADIUS = .072;
	public const CUSHION_EDGE_RADIUS = .006;
	public const RAIL_SIGHT_OFFSET = .0936625;
	public const RAIL_SIGHT_DIAMETER = .012;

	/** @return list<array<string,mixed>> */
	public static function pockets(): array
	{
		static $pockets = null;

		if($pockets !== null) return $pockets;

		$pockets = [];
		$facings = Table::facings();

		foreach(Table::pockets() as $index => [$x,$z])
		{
			$mouth = Table::pocketMouthHalf($index) * 2;
			$mouthCenter = Table::pocketMouthCenter($index);
			$outward = Table::pocketOutward($index);
			$jaws = Table::pocketJaws($index);
			$faces = [];

			foreach(array_slice($facings, $index * 2, 2) as $facing)
			$faces[] = ['jaw' => [$facing[0],$facing[1]], 'tangent' => [$facing[2],$facing[3]]];

			$bridge = self::convexHull(array_merge($jaws, array_column($faces, 'tangent')));
			$circle = [];

			for($step = 0; $step < 48; $step++)
			{
				$angle = $step / 48 * M_PI * 2;
				$circle[] = [$x + cos($angle) * Table::THROAT_RADIUS,$z + sin($angle) * Table::THROAT_RADIUS];
			}

			$pockets[] = [
				'index' => $index
				, 'x' => $x
				, 'z' => $z
				, 'mouth' => $mouth
				, 'radius' => Table::THROAT_RADIUS
				, 'outward' => $outward
				, 'mouthCenter' => $mouthCenter
				, 'jaws' => $jaws
				, 'faces' => $faces
				, 'bridge' => $bridge
				, 'openingProfile' => self::convexHull(array_merge($circle, $jaws, array_column($faces, 'tangent')))
				, 'type' => in_array($index, [1,4], true) ? 'side' : 'corner'
			];
		}

		return $pockets;
	}

	/** @return list<array{float,float}> */
	public static function railSights(): array
	{
		$sights = [];
		$longRailZ = Table::HEIGHT / 2 + self::RAIL_SIGHT_OFFSET;
		$shortRailX = Table::WIDTH / 2 + self::RAIL_SIGHT_OFFSET;

		foreach([-1,1] as $side)
		{
			for($step = 1; $step < 8; $step++) if($step !== 4)
			$sights[] = [-Table::WIDTH / 2 + $step * Table::WIDTH / 8,$side * $longRailZ];

			for($step = 1; $step < 4; $step++)
			$sights[] = [$side * $shortRailX,-Table::HEIGHT / 2 + $step * Table::HEIGHT / 4];
		}

		return $sights;
	}

	/**
	 * Return six constant-width cushion caps ending at the canonical tangent facings.
	 *
	 * Straight rail offsets and analytic jaw tangencies are joined into one polygon per segment, preserving the same
	 * pocket openings used by collision while preventing the padding from widening behind a pocket.
	 */
	public static function cushionPolygons(): array
	{
		$pockets = self::pockets();
		$polygons = [];
		$faceAt = static function(array $pocket, callable $predicate): array {
			foreach($pocket['faces'] as $face) if($predicate($face['jaw'])) return $face;

			throw new \LogicException('Missing pocket facing');
		};
		$backPoint = static function(array $face, int $axis, float $value): array {
			$amount = ($value - $face['jaw'][$axis]) / ($face['tangent'][$axis] - $face['jaw'][$axis]);

			return [
				$face['jaw'][0] + ($face['tangent'][0] - $face['jaw'][0]) * $amount
				, $face['jaw'][1] + ($face['tangent'][1] - $face['jaw'][1]) * $amount
			];
		};

		foreach([[$pockets[0],$pockets[1],$pockets[2],-1],[$pockets[3],$pockets[4],$pockets[5],1]]
			as [$cornerLeft,$side,$cornerRight,$signZ]
		){
			$edge = $signZ * Table::HEIGHT / 2;
			$back = $edge + $signZ * Table::CUSHION_WIDTH;
			$left = $faceAt($cornerLeft, static fn(array $jaw): bool => abs($jaw[1] - $edge) < 1e-9);
			$middleLeft = $faceAt($side, static fn(array $jaw): bool => $jaw[0] < 0);
			$middleRight = $faceAt($side, static fn(array $jaw): bool => $jaw[0] > 0);
			$right = $faceAt($cornerRight, static fn(array $jaw): bool => abs($jaw[1] - $edge) < 1e-9);
			$polygons[] = [$left['jaw'],$middleLeft['jaw'],$backPoint($middleLeft, 1, $back),$backPoint($left, 1, $back)];
			$polygons[] = [$middleRight['jaw'],$right['jaw'],$backPoint($right, 1, $back),$backPoint($middleRight, 1, $back)];
		}

		foreach([[$pockets[0],$pockets[3],-1],[$pockets[2],$pockets[5],1]] as [$bottom,$top,$signX])
		{
			$edge = $signX * Table::WIDTH / 2;
			$back = $edge + $signX * Table::CUSHION_WIDTH;
			$lower = $faceAt($bottom, static fn(array $jaw): bool => abs($jaw[0] - $edge) < 1e-9);
			$upper = $faceAt($top, static fn(array $jaw): bool => abs($jaw[0] - $edge) < 1e-9);
			$polygons[] = [$lower['jaw'],$backPoint($lower, 0, $back),$backPoint($upper, 0, $back),$upper['jaw']];
		}

		foreach($polygons as &$vertices)
		{
			$area = 0;
			foreach($vertices as $index => [$x,$z])
			{
				[$nextX,$nextZ] = $vertices[($index + 1) % count($vertices)];
				$area += $x * $nextZ - $nextX * $z;
			}
			if($area < 0) $vertices = array_reverse($vertices);
		}
		unset($vertices);

		return $polygons;
	}

	/**
	 * Build the shared constructive profiles for every table material.
	 *
	 * One set of circular pocket cuts is subtracted from the apron, bed, wood, and cushion fields so their visible
	 * boundaries remain coincident with each other and with the physical table model.
	 *
	 * @return array{bounds:array,pockets:array,cushionRails:array,apron:callable,bed:callable,wood:callable,cushion:callable}
	 */
	public static function profiles(): array
	{
		$outerX = Table::WIDTH / 2 + .17;
		$outerZ = Table::HEIGHT / 2 + .17;
		$bounds = [-$outerX - .02,-$outerZ - .02,$outerX + .02,$outerZ + .02];
		$pockets = self::pockets();
		$cushionRails = self::cushionPolygons();
		$pocket = static function(float $x, float $z) use ($pockets): float {
			$distance = INF;
			foreach($pockets as $entry)
			$distance = min($distance, self::circleSdf($x, $z, $entry['x'], $entry['z'], $entry['radius']));
			return $distance;
		};
		$outer = static fn(float $x, float $z): float => self::roundedRectangleSdf($x, $z, $outerX, $outerZ, .16);
		$tabletop = static fn(float $x, float $z): float => self::roundedRectangleSdf(
			$x, $z, Table::WIDTH / 2 + self::TABLETOP_MARGIN, Table::HEIGHT / 2 + self::TABLETOP_MARGIN
			, self::TABLETOP_CORNER_RADIUS
		);
		$cushion = static function(float $x, float $z) use ($cushionRails): float {
			$distance = INF;
			foreach($cushionRails as $vertices) $distance = min($distance, self::polygonSdf($x, $z, $vertices));
			return $distance;
		};

		return [
			'bounds' => $bounds
			, 'pockets' => $pockets
			, 'cushionRails' => $cushionRails
			, 'apron' => static fn(float $x, float $z): float => self::subtractSdf($outer($x, $z), $pocket($x, $z))
			, 'bed' => static fn(float $x, float $z): float => self::subtractSdf($tabletop($x, $z), $pocket($x, $z))
			, 'wood' => static fn(float $x, float $z): float => self::subtractSdf($outer($x, $z), min($tabletop($x, $z), $pocket($x, $z)))
			, 'cushion' => $cushion
		];
	}

	public static function pocketVoidRadius(): float { return Table::LINER_RADIUS; }

	/**
	 * Radius of the liner's lower cylinder, and so of the black floor that closes it.
	 *
	 * The upper cone narrows from the leather lip at the draft angle but never below the clearance a ball needs.
	 *
	 * @param float $ballRadius Physical ball radius the drop channel must clear.
	 */
	public static function pocketFloorRadius(float $ballRadius = Ball::RADIUS): float
	{
		return max($ballRadius * 1.12, self::pocketVoidRadius()
			- (self::POCKET_LINER_TOP - self::POCKET_THROAT) * tan(deg2rad(self::POCKET_DRAFT_DEGREES)));
	}

	/**
	 * @param float $x Sample X coordinate.
	 * @param float $z Sample Z coordinate.
	 * @param float $halfX Rectangle half-width along X.
	 * @param float $halfZ Rectangle half-width along Z.
	 * @param float $radius Corner radius.
	 */
	public static function roundedRectangleSdf(float $x, float $z, float $halfX, float $halfZ, float $radius): float
	{
		$qx = abs($x) - $halfX + $radius;
		$qz = abs($z) - $halfZ + $radius;

		return hypot(max($qx, 0), max($qz, 0)) + min(max($qx, $qz), 0) - $radius;
	}

	/**
	 * @param float $x Sample X coordinate.
	 * @param float $z Sample Z coordinate.
	 * @param float $cx Circle-centre X coordinate.
	 * @param float $cz Circle-centre Z coordinate.
	 * @param float $radius Circle radius.
	 */
	public static function circleSdf(float $x, float $z, float $cx, float $cz, float $radius): float
	{
		return hypot($x - $cx, $z - $cz) - $radius;
	}

	/**
	 * @param float $solid Signed distance of the source solid.
	 * @param float $cuts Signed distances of shapes removed from the solid.
	 */
	public static function subtractSdf(float $solid, float ...$cuts): float { return max($solid, -min($cuts)); }

	/**
	 * @param float $x Sample X coordinate.
	 * @param float $z Sample Z coordinate.
	 * @param list<array{float,float}> $vertices Ordered polygon vertices.
	 */
	public static function polygonSdf(float $x, float $z, array $vertices): float
	{
		$distanceSquared = INF;
		$inside = false;
		$count = count($vertices);

		for($index = 0, $previous = $count - 1; $index < $count; $previous = $index++)
		{
			[$ax,$az] = $vertices[$previous];
			[$bx,$bz] = $vertices[$index];
			$edgeX = $bx - $ax;
			$edgeZ = $bz - $az;
			$pointX = $x - $ax;
			$pointZ = $z - $az;
			$edgeLength = $edgeX ** 2 + $edgeZ ** 2;
			$amount = max(0, min(1, ($pointX * $edgeX + $pointZ * $edgeZ) / $edgeLength));
			$dx = $pointX - $edgeX * $amount;
			$dz = $pointZ - $edgeZ * $amount;
			$distanceSquared = min($distanceSquared, $dx ** 2 + $dz ** 2);

			if(($az > $z) !== ($bz > $z) && $x < ($bx - $ax) * ($z - $az) / ($bz - $az) + $ax)
			$inside = !$inside;
		}

		return sqrt($distanceSquared) * ($inside ? -1 : 1);
	}

	/**
	 * @param list<array{float,float}> $vertices Ordered convex polygon vertices.
	 * @param float $radius Inward offset distance.
	 * @return list<array{float,float}>
	 */
	public static function insetPolygon(array $vertices, float $radius): array
	{
		$area = 0;
		$count = count($vertices);

		foreach($vertices as $index => [$x,$z])
		{
			[$nextX,$nextZ] = $vertices[($index + 1) % $count];
			$area += $x * $nextZ - $nextX * $z;
		}

		$direction = $area <=> 0 ?: 1;
		$lines = [];

		foreach($vertices as $index => [$x,$z])
		{
			[$nextX,$nextZ] = $vertices[($index + 1) % $count];
			$dx = $nextX - $x;
			$dz = $nextZ - $z;
			$length = hypot($dx, $dz);
			$nx = -$dz / $length * $direction;
			$nz = $dx / $length * $direction;
			$lines[] = ['x' => $x + $nx * $radius,'z' => $z + $nz * $radius,'dx' => $dx,'dz' => $dz];
		}

		$result = [];
		foreach($lines as $index => $line)
		{
			$previous = $lines[($index + $count - 1) % $count];
			$determinant = $previous['dx'] * $line['dz'] - $previous['dz'] * $line['dx'];
			$offsetX = $line['x'] - $previous['x'];
			$offsetZ = $line['z'] - $previous['z'];
			$amount = ($offsetX * $line['dz'] - $offsetZ * $line['dx']) / $determinant;
			$result[] = [$previous['x'] + $previous['dx'] * $amount,$previous['z'] + $previous['dz'] * $amount];
		}

		return $result;
	}

	/** @return list<array{float,float}> */
	private static function convexHull(array $points): array
	{
		usort($points, static fn(array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
		$cross = static fn(array $a, array $b, array $c): float => ($b[0] - $a[0]) * ($c[1] - $a[1])
			- ($b[1] - $a[1]) * ($c[0] - $a[0]);
		$half = [];

		foreach($points as $point)
		{
			while(count($half) >= 2 && $cross($half[count($half) - 2], $half[count($half) - 1], $point) <= 1e-12)
			array_pop($half);
			$half[] = $point;
		}

		$lower = $half;
		$half = [];
		foreach(array_reverse($points) as $point)
		{
			while(count($half) >= 2 && $cross($half[count($half) - 2], $half[count($half) - 1], $point) <= 1e-12)
			array_pop($half);
			$half[] = $point;
		}

		array_pop($lower);
		array_pop($half);

		return array_merge($lower, $half);
	}
}
