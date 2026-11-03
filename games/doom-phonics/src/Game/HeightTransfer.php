<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

/**
 * Boom's fake floor and ceiling, separate from the sector's physical boundaries.
 *
 * The viewer selects a lower, middle or upper region. Control-sector textures,
 * lighting and the transfer line's palette blends describe those regions.
 */
final class HeightTransfer
{
	/** Retain the model sector and upper/middle/lower blend names. */
	public function __construct(public SectorState $model, public int $flags, public array $blends) {}

	/** Classify the eye without treating a fake surface as collision geometry. */
	public function region(float $x, float $y, float $eye, float $alpha = 1): int
	{
		if($eye < $this->model->floorZ($x, $y, $alpha)) return -1;
		return !($this->flags & 2) && $eye > $this->model->ceilingZ($x, $y, $alpha) ? 1 : 0;
	}

	/** Decode the documented WATERMAP name or AARRGGBB palette blend. */
	public function blend(int $region): array
	{
		$name = strtoupper($this->blends[$region < 0 ? 'lower' : ($region > 0 ? 'upper' : 'middle')] ?? '-');
		if($name === 'WATERMAP') $name = '80004FA5';
		if(!preg_match('/^[0-9A-F]{8}$/D', $name)) return [0.0,0.0,0.0,0.0];
		return [hexdec(substr($name, 2, 2)) / 255,hexdec(substr($name, 4, 2)) / 255,
			hexdec(substr($name, 6, 2)) / 255,hexdec(substr($name, 0, 2)) / 255];
	}

	/** Resolve the two rendered boundaries without changing physical sector state. */
	public function view(SectorState $sector, int $region): array
	{
		$m = $this->model;
		$improved = (bool)($this->flags & 4);
		$floorOnly = (bool)($this->flags & 2);
		$floor = [$sector,0,$sector->floorTexture];
		$ceiling = [$sector,1,$sector->ceilingTexture];
		$light = $sector->light;
		if($this->flags & 16) return compact('floor', 'ceiling', 'light');
		if($region < 0)
		{
			$floor = [$sector,0,$improved ? $sector->floorTexture : $m->floorTexture];
			$ceiling = $floorOnly ? $ceiling : [$m,0,$improved ? $m->floorTexture : $m->ceilingTexture];
		}
		elseif($region > 0 && !$floorOnly)
		{
			$floor = [$m,1,$improved ? $m->ceilingTexture : $m->floorTexture];
			$ceiling = [$sector,1,$improved ? $sector->ceilingTexture : $m->ceilingTexture];
		}
		else
		{
			$floor = [$m,0,$improved ? $m->floorTexture : $sector->floorTexture];
			if(!$floorOnly) $ceiling = [$m,1,$improved ? $m->ceilingTexture : $sector->ceilingTexture];
		}
		if($region !== 0 && !($this->flags & 32)) $light = $m->light;
		return compact('floor', 'ceiling', 'light');
	}
}
