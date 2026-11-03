<?php
declare(strict_types=1);
namespace DoomPhonics\Render;

use DoomPhonics\Game\Level;
use DoomPhonics\Graphics\IndexedImage;
use DoomPhonics\Graphics\Palette;
use DoomPhonics\Graphics\TextureCatalog;
use DoomPhonics\Graphics\ViewColorState;

/** Portal-aware software raycaster that preserves WAD textures, light maps, and moving sectors. */
final class Raycaster
{
	private float $projection;
	private ViewColorState $viewColor;
	/** @var array<string,IndexedImage|null> */
	private array $images = [];

	public function __construct(
		public Level $level
		, public TextureCatalog $textures
		, public Palette $palette
		, public int $width = 320
		, public int $height = 180
		, public float $fov = 90
	){
		$this->projection = ($width / 2) / tan(deg2rad($fov / 2));
		$this->viewColor = new ViewColorState;
	}

	public function render(
		float $x
		, float $y
		, float $z
		, float $angle
		, float $pitch = 0
		, ?ViewColorState $viewColor = null
	): FrameBuffer {
		$this->viewColor = $viewColor ?? new ViewColorState;
		$frame = new FrameBuffer($this->width, $this->height);
		$eye = $z + 41;
		$center = $this->height / 2 + $pitch;
		$start = $this->level->sectorAt($x, $y);
		$this->background($frame, $start, $x, $y, $eye, $angle, $center);

		for($column = 0; $column < $this->width; $column++)
		{
			$camera = (($column + .5) - $this->width / 2) / $this->projection;
			// Positive screen X points clockwise in canonical WAD coordinates.
			$rayAngle = $angle - atan($camera);
			$dx = cos($rayAngle);
			$dy = sin($rayAngle);
			$correction = cos($rayAngle - $angle);
			$current = $start->index;
			$top = 0;
			$bottom = $this->height - 1;

			foreach($this->level->spatial->cast($x, $y, $dx, $dy, 8192) as $hit)
			{
				$sides = $this->level->sides($hit['line'], $current);

				if($sides['front'] !== $current) continue;

				$front = $this->level->sectors[$sides['front']];
				$back = $sides['back'] === null ? null : $this->level->sectors[$sides['back']];
				$distance = max(.001, $hit['distance'] * $correction);
				$side = $sides['frontSide'] === null ? null : $this->level->map->sidedefs[$sides['frontSide']];
				$line = $this->level->map->linedefs[$hit['line']];
				$along = $sides['frontSide'] === $line['left'] ? 1 - $hit['along'] : $hit['along'];
				$a = $this->level->map->vertices[$line['from']];
				$b = $this->level->map->vertices[$line['to']];
				$wallX = $along * hypot($b['x'] - $a['x'], $b['y'] - $a['y']) + (float)($side['xOffset'] ?? 0);

				if(!$back)
				{
					$lowerUnpegged = (bool)($line['flags'] & 16);

					$this->wall(
						$frame
						, $column
						, $front->ceiling
						, $front->floor
						, $eye
						, $center
						, $distance
						, $top
						, $bottom
						, $side['middle'] ?? '-'
						, $wallX
						, $lowerUnpegged ? $front->floor : $front->ceiling
						, $lowerUnpegged
						, $side['yOffset'] ?? 0
						, $front->source['light']
					);

					break;
				}

				$sharedSky = $front->source['ceilingTexture'] === 'F_SKY1'
					&& $back->source['ceilingTexture'] === 'F_SKY1';

				$upperUnpegged = (bool)($line['flags'] & 8);
				$lowerUnpegged = (bool)($line['flags'] & 16);

				if($back->ceiling < $front->ceiling && !$sharedSky)
				$this->wall(
					$frame
					, $column
					, $front->ceiling
					, $back->ceiling
					, $eye
					, $center
					, $distance
					, $top
					, $bottom
					, $side['upper'] ?? '-'
					, $wallX
					, $upperUnpegged ? $front->ceiling : $back->ceiling
					, !$upperUnpegged
					, $side['yOffset'] ?? 0
					, $front->source['light']
				);

				if($back->floor > $front->floor)
				$this->wall(
					$frame
					, $column
					, $back->floor
					, $front->floor
					, $eye
					, $center
					, $distance
					, $top
					, $bottom
					, $side['lower'] ?? '-'
					, $wallX
					, $lowerUnpegged ? $front->ceiling : $back->floor
					, false
					, $side['yOffset'] ?? 0
					, $front->source['light']
				);

				if(($side['middle'] ?? '-') !== '-')
				$this->wall(
					$frame
					, $column
					, min($front->ceiling, $back->ceiling)
					, max($front->floor, $back->floor)
					, $eye
					, $center
					, $distance
					, $top
					, $bottom
					, $side['middle']
					, $wallX
					, $lowerUnpegged ? max($front->floor, $back->floor) : min($front->ceiling, $back->ceiling)
					, $lowerUnpegged
					, $side['yOffset'] ?? 0
					, $front->source['light']
					, true
				);

				$openingTop = $this->screenY(min($front->ceiling, $back->ceiling), $eye, $center, $distance);
				$openingBottom = $this->screenY(max($front->floor, $back->floor), $eye, $center, $distance);
				$top = max($top, (int)ceil($openingTop));
				$bottom = min($bottom, (int)floor($openingBottom));

				if($top > $bottom) break;

				$current = $back->index;
			}
		}

		return $frame;
	}

	private function background(
		FrameBuffer $frame
		, object $sector
		, float $x
		, float $y
		, float $eye
		, float $angle
		, float $center
	): void {
		$floor = $this->flat($sector->source['floorTexture']);
		$ceilingName = $sector->source['ceilingTexture'];
		$ceiling = $ceilingName === 'F_SKY1' ? null : $this->flat($ceilingName);
		$sky = $ceilingName === 'F_SKY1' ? ($this->wallTexture('SKY1') ?? $this->textures->picture('SKY1')) : null;
		$light = (int)$sector->source['light'];
		$pitchOffset = ($center - $this->height / 2) * 2 / $this->height;

		for($sy = 0; $sy < $this->height; $sy++)
		{
			$floorSide = $sy >= $center;
			$planeZ = $floorSide ? $sector->floor : $sector->ceiling;
			$denominator = $sy - $center;
			$distance = abs($denominator) < .5 ? 8192 : abs(($eye - $planeZ) * $this->projection / $denominator);
			$map = $this->palette->lightMap($light, $distance);

			for($sx = 0; $sx < $this->width; $sx++)
			{
				if(!$floorSide && $sky)
				{
					$rayAngle = $angle - atan((($sx + .5) - $this->width / 2) / $this->projection);
					$screenY = 1 - 2 * ($sy + .5) / $this->height;
					$u = (int)floor($rayAngle * 4 / (2 * M_PI) * $sky->width);
					$v = (int)floor(max(0, min(.99999, .725 - ($screenY + $pitchOffset) * .425)) * $sky->height);
					$frame->pixels[$sy * $this->width + $sx] = $this->rgba($sky->index($u, $v), 0);
					continue;
				}

				$image = $floorSide ? $floor : $ceiling;

				if(!$image) continue;

				$rayAngle = $angle - atan((($sx + .5) - $this->width / 2) / $this->projection);
				$worldX = $x + cos($rayAngle) * $distance;
				$worldY = $y + sin($rayAngle) * $distance;

				$frame->pixels[$sy * $this->width + $sx] = $this->rgba(
					$image->index((int)floor($worldX), (int)floor(-$worldY))
					, $map
				);
			}
		}
	}

	private function wall(
		FrameBuffer $frame
		, int $x
		, float $topZ
		, float $bottomZ
		, float $eye
		, float $center
		, float $distance
		, int $clipTop
		, int $clipBottom
		, string $name
		, float $textureX
		, float $textureAnchor
		, bool $addTextureHeight
		, float $offsetY
		, int $light
		, bool $masked = false
	): void {
		if($name === '-' || $topZ <= $bottomZ) return;

		$image = $this->wallTexture($name);

		if(!$image) return;

		$textureMid = $textureAnchor + $offsetY + ($addTextureHeight ? $image->height : 0);
		$screenTop = $this->screenY($topZ, $eye, $center, $distance);
		$screenBottom = $this->screenY($bottomZ, $eye, $center, $distance);
		$from = max($clipTop, (int)ceil($screenTop));
		$to = min($clipBottom, (int)floor($screenBottom));

		if($from > $to) return;

		$span = max(.001, $screenBottom - $screenTop);
		$map = $this->palette->lightMap($light, $distance);

		for($y = $from; $y <= $to; $y++)
		{
			$worldZ = $topZ - (($y + .5 - $screenTop) / $span) * ($topZ - $bottomZ);
			$textureY = $textureMid - $worldZ;
			$tx = (int)floor($textureX);
			$ty = (int)floor($textureY);

			if($masked && !$image->opaque(
				(($tx % $image->width) + $image->width) % $image->width
				, (($ty % $image->height) + $image->height) % $image->height
			))
			continue;

			$frame->set($x, $y, $this->rgba($image->index($tx, $ty), $map), $distance);
		}
	}

	private function screenY(float $worldZ, float $eye, float $center, float $distance): float
	{
		return $center - ($worldZ - $eye) * $this->projection / $distance;
	}

	private function rgba(int $index, int $lightMap): int
	{
		$map = $this->viewColor->fixedColormap !== null
			&& $this->palette->hasMap($this->viewColor->fixedColormap)
			? $this->viewColor->fixedColormap
			: $lightMap;

		return $this->palette->rgba($index, $map, $this->viewColor->palettePage);
	}

	private function wallTexture(string $name): ?IndexedImage
	{
		$key = 'wall:' . strtoupper($name);

		return array_key_exists($key, $this->images)
			? $this->images[$key]
			: ($this->images[$key] = $this->textures->texture($name));
	}

	private function flat(string $name): ?IndexedImage
	{
		$key = 'flat:' . strtoupper($name);

		return array_key_exists($key, $this->images)
			? $this->images[$key]
			: ($this->images[$key] = $this->textures->flat($name));
	}
}
