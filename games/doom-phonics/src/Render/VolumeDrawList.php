<?php
declare(strict_types=1);
namespace DoomPhonics\Render;

use DoomPhonics\Game\Level;
use DoomPhonics\Game\CameraPortalLink;

/**
 * Order model-sector faces and batch adjacent faces with the same material state.
 *
 * Translucency keeps back-to-front ordering, while the shared index buffer avoids
 * one PHP-to-WebGL draw call for every BSP cell covered by a liquid surface.
 */
final class VolumeDrawList
{
	/** Return indexed batches, excluding drained volumes at the rendered height. */
	public static function build(MapMesh $mesh, Level $level, array $view, bool $transparent): array
	{
		[$x,$y,$eye,$alpha] = $view;
		$spans = [];
		foreach($mesh->volumeSpans as $span)
		{
			if(($span['opacity'] < 1 || $span['additive']) !== $transparent) continue;
			$volume = $span['volume'];
			$projection = $span['projection'] ?? null;
			$span['heightOffset'] = $projection ? VolumePortalFaces::heightOffset($projection, $level, $alpha) : 0.0;
			if($projection && !VolumePortalFaces::visible($span, $mesh, $level, $alpha, $span['heightOffset'])) continue;
			$mx = $span['modelX'] ?? $span['x']; $my = $span['modelY'] ?? $span['y'];
			if($volume && !($volume->flags & 8)
				&& $volume->top($mx, $my, $alpha) <= $volume->bottom($mx, $my, $alpha)) continue;
			$span['light'] = $volume && !($volume->flags & 1) ? $volume->model->light : $level->sectors[$span['sector']]->light;
			// Original faces stop at their sector. The projected half owns exact
			// contact with the destination portal, but still clips its other boundary.
			$span['clipBounds'] = $volume ? 3 : 0;
			if($projection) $span['clipBounds'] |= $projection->destinationAlignment === CameraPortalLink::ALIGN_FLOOR ? 4 : 8;
			$span['projected'] = $projection !== null;
			$span['key'] = $span['opacity'] . ':' . $span['light'] . ':' . (int)$span['additive'] . ':' . $span['clipBounds']
				. ':' . ($projection?->id ?? -1);
			$model = $level->sectors[$span['model']];
			$height = ($span['plane'] ? $model->ceilingZ($mx, $my, $alpha) : $model->floorZ($mx, $my, $alpha)) + $span['heightOffset'];
			$span['distance'] = ($span['x'] - $x) ** 2 + ($span['y'] - $y) ** 2 + ($height - $eye) ** 2;
			$spans[] = $span;
		}
		usort($spans, $transparent
			? static fn(array $a, array $b): int => $b['distance'] <=> $a['distance']
			: static fn(array $a, array $b): int => $a['key'] <=> $b['key']);
		$batches = [];
		foreach($spans as $span)
		{
			$last = count($batches) - 1;
			if($last < 0 || $batches[$last]['key'] !== $span['key'])
			{
				$batches[] = ['key' => $span['key'],'opacity' => $span['opacity'],
					'light' => $span['light'],'additive' => $span['additive'],'clipBounds' => $span['clipBounds'],
					'projected' => $span['projected'],'heightOffset' => $span['heightOffset'],'indices' => []];
				$last++;
			}
			array_push($batches[$last]['indices'], ...range($span['first'], $span['first'] + $span['count'] - 1));
		}
		return $batches;
	}
}
