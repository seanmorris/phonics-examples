<?php
declare(strict_types=1);
namespace DoomPhonics\Graphics;

use DoomPhonics\Game\Level;
use DoomPhonics\Definition\ActorCatalog;

/** Shelf-packed palette-index/alpha atlas with shader-readable rectangle metadata. */
final class IndexedAtlas
{
	/** @var array<string,int> */
	private array $ids = [];
	/** @var list<array{x:int,y:int,width:int,height:int,key:string}> */
	public array $rects = [];
	public int $width = 1;
	public int $height = 1;
	/** Two bytes per pixel: palette index then alpha. */
	public string $pixels = "\0\0";

	/** Build the minimal atlas used by one map's walls, floors, ceiling, and sky. */
	public static function forLevel(
		Level $level
		, TextureCatalog $textures
		, int $targetWidth = 1024
		, ?ActorCatalog $actors = null
		, ?Palette $palette = null
	): self {
		$images = [
			'ui:backdrop' => new IndexedImage(1, 1, "\0", "\xff")
		];

		if($palette)
		$images['ui:reticle'] = new IndexedImage(1, 1, chr($palette->closestIndex(255, 128, 0)), "\xff");

		foreach($level->map->sidedefs as $side)
		foreach(['upper','lower','middle'] as $position)
		if(($name = strtoupper($side[$position])) !== '-' && !isset($images['wall:' . $name]))
		{
			$image = $textures->texture($name);

			if($image) $images['wall:' . $name] = $image;

			$pair = str_starts_with($name, 'SW1')
				? 'SW2' . substr($name, 3)
				: (str_starts_with($name, 'SW2') ? 'SW1' . substr($name, 3) : null);

			if($pair && !isset($images['wall:' . $pair]) && ($paired = $textures->texture($pair)))
			$images['wall:' . $pair] = $paired;
		}

		foreach($level->map->sectors as $sector)
		foreach(['floorTexture','ceilingTexture'] as $position)
		{
			$name = strtoupper($sector[$position]);

			if($name === 'F_SKY1' || isset($images['flat:' . $name])) continue;

			$image = $textures->flat($name);

			if($image) $images['flat:' . $name] = $image;
		}

		if($sky = $textures->texture('SKY1')) $images['wall:SKY1'] = $sky;

		$hud = ['STBAR','STTPRCNT','STFGOD0','STFDEAD0'];

		for($pain = 0; $pain < 5; $pain++)
		{
			for($straight = 0; $straight < 3; $straight++) $hud[] = 'STFST' . $pain . $straight;

			foreach(['STFTL','STFTR'] as $turn) $hud[] = $turn . $pain . '0';

			foreach(['STFOUCH','STFEVL','STFKILL'] as $expression) $hud[] = $expression . $pain;
		}

		for($digit = 0; $digit <= 9; $digit++)
		{
			$hud[] = 'STTNUM' . $digit;
			$hud[] = 'STYSNUM' . $digit;
		}

		for($key = 0; $key <= 5; $key++) $hud[] = 'STKEYS' . $key;

		foreach($hud as $name)
		if($image = $textures->picture($name)) $images['picture:' . $name] = $image;
		$menu = [
			'TITLEPIC','M_DOOM','M_NGAME','M_OPTION','M_RDTHIS','M_NEWG','M_SKILL'
			, 'M_JKILL','M_ROUGH','M_HURT','M_ULTRA','M_NMARE','M_SKULL1','M_SKULL2'
			, 'M_OPTTTL','M_MSENS','M_THERML','M_THERMR','M_THERMM','M_THERMO','M_PAUSE'
			, 'INTERPIC','WIF','WIENTER','WIOSTK','WIOSTI','WISCRT2','WITIME','WIPAR'
			, 'WIPCNT','WICOLON','WIMINUS'
		];

		for($episode = 0; $episode < 9; $episode++)
		{
			$menu[] = 'WIMAP' . $episode;

			for($mission = 0; $mission < 9; $mission++) $menu[] = 'WILV' . $episode . $mission;
		}

		for($map = 0; $map < 99; $map++) $menu[] = 'CWILV' . str_pad((string)$map, 2, '0', STR_PAD_LEFT);

		for($digit = 0; $digit <= 9; $digit++) $menu[] = 'WINUM' . $digit;

		for($character = 33; $character <= 95; $character++)
		$menu[] = 'STCFN' . str_pad((string)$character, 3, '0', STR_PAD_LEFT);

		if($font = $textures->menuFont())
		foreach($font->glyphCodes() as $character) $menu[] = Fon2Font::pictureName($character);

		foreach($menu as $name)
		if($image = $textures->picture($name)) $images['picture:' . $name] = $image;

		$frames = [];

		if($actors)
		foreach($actors->all() as $actor)
		foreach($actor->states as $sequence)
		foreach($sequence->frames as $frame)
		{
			if(strlen($frame->sprite) !== 4 || str_contains('#-', $frame->sprite[0])) continue;

			$key = strtoupper($frame->sprite . $frame->frame);

			if(isset($frames[$key])) continue;

			$frames[$key] = true;

			for($rotation = 0; $rotation <= 8; $rotation++)
			if($sprite = $textures->sprite($frame->sprite, $frame->frame, $rotation))
			$images['sprite:' . $sprite['name']] = $sprite['image'];
		}

		return self::pack($images, $targetWidth);
	}

	/** @param array<string,IndexedImage> $images */
	public static function pack(array $images, int $targetWidth = 1024): self
	{
		$self = new self;
		$targetWidth = max(64, $targetWidth);
		$x = $y = 1;
		$row = 0;
		$usedWidth = 1;
		$placed = [];

		foreach($images as $key => $image)
		{
			if($x + $image->width + 1 > $targetWidth)
			{
				$x = 1;
				$y += $row + 2;
				$row = 0;
			}

			$placed[$key] = [$x,$y,$image];
			$self->ids[strtolower($key)] = count($self->rects);
			$self->rects[] = [
				'x' => $x
				, 'y' => $y
				, 'width' => $image->width
				, 'height' => $image->height
				, 'key' => $key
			];

			$x += $image->width + 2;
			$row = max($row, $image->height);
			$usedWidth = max($usedWidth, $x + 1);
		}

		$self->width = max(1, min($targetWidth, max($usedWidth, 64)));
		$self->height = max(1, $y + $row + 1);
		$rows = array_fill(0, $self->height, '');

		foreach($placed as [$px,$py,$image])
		{
			$bytes = $image->indexAlphaBytes();
			$stride = $image->width * 2;

			// Shelves visit images from left to right. Append whole rows and their
			// transparent gutters without copying the entire atlas for each image.
			for($iy = 0; $iy < $image->height; $iy++)
			{
				$row = $py + $iy;
				$rows[$row] .= str_repeat("\0", $px * 2 - strlen($rows[$row]))
					. substr($bytes, $iy * $stride, $stride);
			}
		}

		$self->pixels = '';

		foreach($rows as $row) $self->pixels .= str_pad($row, $self->width * 2, "\0");

		return $self;
	}

	public function id(string $kind, string $name): int
	{
		return $this->ids[strtolower($kind . ':' . $name)] ?? -1;
	}

	/** RGBA float rectangles in atlas pixels, one texel per image. */
	public function metadata(): string
	{
		$bytes = '';

		foreach($this->rects as $rect) $bytes .= pack('f*', $rect['x'], $rect['y'], $rect['width'], $rect['height']);

		return $bytes ?: pack('f*', 0, 0, 1, 1);
	}
}
