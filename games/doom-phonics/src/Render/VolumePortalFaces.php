<?php
declare(strict_types=1);
namespace DoomPhonics\Render;

use DoomPhonics\Game\CameraPortalLink;
use DoomPhonics\Game\Level;

/**
 * Continue liquid flats across reciprocal floor and ceiling openings.
 *
 * Each projected triangle stays inside the destination aperture. Its texture and
 * height still come from the original live water model, while the destination
 * sector supplies clipping and visibility. The two halves therefore meet at the
 * portal without drawing the liquid twice or covering nearby solid floors.
 */
final class VolumePortalFaces
{
	/** Append clipped destination faces without changing the source volume's physics. */
	public static function append(MapMesh $mesh, Level $level): void
	{
		$apertures = [];
		foreach($mesh->cameraPortals->spans as $span)
		{
			$surface = $span['surface'];
			if($surface->plane() === null) continue;
			$points = [];
			for($i = $span['first']; $i < $span['first'] + $span['count']; $i++)
			$points[] = array_slice($mesh->cameraPortals->vertices, $i * CameraPortalMesh::FLOATS, 2);
			$apertures[$surface->link->id][] = ['sector'=>$surface->index,'points'=>$points];
		}
		foreach($mesh->volumeSpans as $span)
		{
			if(!$span['volume']?->swimmable() || $mesh->vertices[$span['first'] * MapMesh::FLOATS + 8] >= 0) continue;
			foreach([0,1] as $plane)
			{
				$link = $level->cameraPortalForPlane($span['sector'], $plane)?->link;
				if(!$link || !$link->flipsVertical() || $link->reciprocalId === null) continue;
				foreach($apertures[$link->reciprocalId] ?? [] as $aperture)
				for($i = $span['first']; $i < $span['first'] + $span['count']; $i += 3)
				{
					$triangle = [];
					for($j = 0; $j < 3; $j++) $triangle[] = $link->point(...array_slice($mesh->vertices, ($i + $j) * MapMesh::FLOATS, 2));
					$points = self::intersect($triangle, $aperture['points']);
					if(count($points) < 3 || abs(self::area($points)) < .001) continue;
					$first = $mesh->vertexCount(); $x = $y = $u = $v = 0;
					for($j = 1; $j < count($points) - 1; $j++)
					foreach([$points[0],$points[$j],$points[$j + 1]] as [$px,$py])
					{
						[$sx,$sy] = self::sourcePoint($link, $px, $py);
						$vertex = array_slice($mesh->vertices, $span['first'] * MapMesh::FLOATS, MapMesh::FLOATS);
						$vertex[0] = $px; $vertex[1] = $py;
						$vertex[2] = $vertex[3] = $sx;
						$vertex[10] = $vertex[13] = -$sy;
						$vertex[14] = $vertex[15] = (float)$aperture['sector'];
						array_push($mesh->vertices, ...$vertex);
						$x += $px; $y += $py; $u += $sx; $v += $sy;
					}
					$count = $mesh->vertexCount() - $first;
					$mesh->volumeSpans[] = array_replace($span, ['first'=>$first,'count'=>$count,'sector'=>$aperture['sector'],
						'x'=>$x/$count,'y'=>$y/$count,'modelX'=>$u/$count,'modelY'=>$v/$count,'projection'=>$link]);
				}
			}
		}
	}

	/** Match the portal camera translation using interpolated live control planes. */
	public static function heightOffset(CameraPortalLink $link, Level $level, float $alpha): float
	{
		$source = $level->sectors[$link->originSector]; $target = $level->sectors[$link->destinationSector];
		$from = $link->alignment === CameraPortalLink::ALIGN_FLOOR ? 'floorZ' : 'ceilingZ';
		$to = $link->destinationAlignment === CameraPortalLink::ALIGN_FLOOR ? 'floorZ' : 'ceilingZ';
		return $target->$to($link->destinationX, $link->destinationY, $alpha)
			- $source->$from($link->originX, $link->originY, $alpha);
	}

	/** Reject faces entirely beyond the destination half-space before issuing a draw. */
	public static function visible(array $span, MapMesh $mesh, Level $level, float $alpha, float $offset): bool
	{
		$link = $span['projection'];
		if(!$link->passable()) return false;
		$target = $level->sectors[$span['sector']]; $model = $level->sectors[$span['model']];
		$height = $span['plane'] ? 'ceilingZ' : 'floorZ';
		$floor = $link->destinationAlignment === CameraPortalLink::ALIGN_FLOOR;
		for($i = $span['first']; $i < $span['first'] + $span['count']; $i++)
		{
			$v = array_slice($mesh->vertices, $i * MapMesh::FLOATS, MapMesh::FLOATS);
			$z = $model->$height($v[2], -$v[10], $alpha) + $offset;
			if($floor ? $z >= $target->floorZ($v[0],$v[1],$alpha) - .001
				: $z <= $target->ceilingZ($v[0],$v[1],$alpha) + .001) return true;
		}
		return false;
	}

	/** Recover original world coordinates for seamless flat UVs and sloped model heights. */
	private static function sourcePoint(CameraPortalLink $link, float $x, float $y): array
	{
		$x -= $link->destinationX; $y -= $link->destinationY;
		$c = cos($link->angleDelta); $s = sin($link->angleDelta);
		return [$link->originX + $x*$c + $y*$s, $link->originY - $x*$s + $y*$c];
	}

	/** Clip one convex face against another, preserving its original winding. */
	private static function intersect(array $points, array $clip): array
	{
		$sign = self::area($clip) <=> 0;
		foreach($clip as $i=>$a)
		{
			$b = $clip[($i + 1) % count($clip)]; $output = [];
			$distance = static fn($p) => $sign*(($b[0]-$a[0])*($p[1]-$a[1])-($b[1]-$a[1])*($p[0]-$a[0]));
			foreach($points as $j=>$p)
			{
				$q = $points[($j + 1) % count($points)]; $dp = $distance($p); $dq = $distance($q);
				if($dp >= 0) $output[] = $p;
				if(($dp < 0) !== ($dq < 0)) {
					$t = $dp / ($dp - $dq);
					$output[] = [$p[0]+($q[0]-$p[0])*$t,$p[1]+($q[1]-$p[1])*$t];
				}
			}
			$points = $output;
			if(count($points) < 3) return [];
		}
		return $points;
	}

	/** Return twice the signed polygon area to determine winding and reject collapsed faces. */
	private static function area(array $points): float
	{
		$area = 0;
		foreach($points as $i=>$p) { $q=$points[($i+1)%count($points)]; $area += $p[0]*$q[1]-$p[1]*$q[0]; }
		return $area;
	}
}
