<?php
declare(strict_types=1);
namespace DoomPhonics\Graphics;

use DoomPhonics\Archive\Archive;
use DoomPhonics\Archive\Binary;
use DoomPhonics\Archive\Lump;
use DoomPhonics\Archive\Stack;

/** Layered composite wall textures, flats, pictures, and directional sprite frames. */
final class TextureCatalog
{
	/** @var array<string,array<string,mixed>> */
	private array $definitions = [];
	/** @var array<string,CameraTextureDefinition> */
	private array $cameraTextures = [];
	/** @var array<string,IndexedImage> */
	private array $cache = [];
	/** @var array<string,array{image:IndexedImage,flipped:bool,name:string}|false> */
	private array $spriteCache = [];
	/** @var array<string,array{lump:Lump,flipped:bool}> Sprite/frame/rotation keys, including mirrored pairs. */
	private array $spriteIndex = [];
	/** @var array<string,Lump> */
	private array $patches = [], $flats = [];
	private ?Fon2Font $menuFont = null;
	private bool $menuFontLoaded = false;
	private ?Palette $pngPalette = null;

	/** @param Stack $wad Layered archive stack. @return void Indexes graphics without decoding pixels. */
	public function __construct(public Stack $wad)
	{
		$this->patches = $wad->namespace(['P_START','PP_START'], ['P_END','PP_END']);
		$this->flats = $wad->namespace(['F_START','FF_START'], ['F_END','FF_END']);

		foreach($wad->namespace(['S_START','SS_START'], ['S_END','SS_END']) as $name => $lump)
		{
			// Namespace lookup already applies WAD overrides. Keep the first matching
			// frame in that order, whether it is the primary or mirrored pair.
			$this->spriteIndex[substr($name, 0, 6)] ??= ['lump' => $lump, 'flipped' => false];

			if(strlen($name) >= 8)
			$this->spriteIndex[substr($name, 0, 4) . substr($name, 6, 2)] ??= ['lump' => $lump, 'flipped' => true];
		}

		$this->loadDefinitions();

		foreach($wad->lumps('ANIMDEFS') as $lump)
		foreach(AnimDefsParser::cameraTextures($lump->bytes()) as $name => $definition)
		{
			$this->cameraTextures[$name] = $definition;
			// A transparent placeholder reserves the name and its logical dimensions
			// until SetCameraToTexture supplies live pixels at render time.
			$this->definitions[$name] = [
				'name' => $name
				, 'width' => $definition->displayWidth()
				, 'height' => $definition->displayHeight()
				, 'patches' => []
			];
		}
	}

	/** @return array<string,CameraTextureDefinition> */
	public function cameraTextures(): array { return $this->cameraTextures; }

	public function cameraTexture(string $name): ?CameraTextureDefinition
	{
		return $this->cameraTextures[strtoupper($name)] ?? null;
	}

	/** @return list<string> Available composite wall texture names. */
	public function textureNames(): array { return array_keys($this->definitions); }

	/**
	 * Describe a composite texture without decoding its pixels, including patch placements.
	 *
	 * @return array{name:string,width:int,height:int,patches:list<array{name:string,x:int,y:int}>}|null
	 */
	public function textureDefinition(string $name): ?array
	{
		return $this->definitions[strtoupper($name)] ?? null;
	}

	/** @return list<string> Available flat names. */
	public function flatNames(): array { return array_keys($this->flats); }

	/** @param string $name Composite wall texture name. @return IndexedImage|null Decoded image. */
	public function texture(string $name): ?IndexedImage
	{
		$name = strtoupper($name);

		if(isset($this->cache['texture:' . $name])) return $this->cache['texture:' . $name];

		$definition = $this->definitions[$name] ?? null;

		if(!$definition) return null;

		$width = $definition['width'];
		$height = $definition['height'];
		$pixels = str_repeat("\0", $width * $height);
		$alpha = str_repeat("\0", $width * $height);

		foreach($definition['patches'] as $placement)
		{
			$lump = $this->patches[$placement['name']] ?? $this->wad->lump($placement['name']);

			if(!$lump) continue;

			$patch = $this->picture($lump->name, $lump);

			for($y = 0; $y < $patch->height; $y++)
			for($x = 0; $x < $patch->width; $x++)
			{
				$source = $y * $patch->width + $x;

				if(ord($patch->alpha[$source]) === 0) continue;

				$dx = $placement['x'] + $x;
				$dy = $placement['y'] + $y;

				if($dx < 0 || $dy < 0 || $dx >= $width || $dy >= $height) continue;

				$target = $dy * $width + $dx;
				$pixels[$target] = $patch->pixels[$source];
				$alpha[$target] = "\xff";
			}
		}

		return $this->cache['texture:' . $name] = new IndexedImage($width, $height, $pixels, $alpha);
	}

	/**
	 * @param string $name Floor or ceiling material name.
	 * @return IndexedImage|null Flat, or a composite wall texture when no flat exists.
	 */
	public function flat(string $name): ?IndexedImage
	{
		$name = strtoupper($name);

		if(isset($this->cache['flat:' . $name])) return $this->cache['flat:' . $name];

		$lump = $this->flats[$name] ?? null;

		// Port maps can use wall textures on planes, including non-square images.
		if(!$lump) return $this->texture($name);

		if($lump->size < 4096) return null;

		return $this->cache['flat:' . $name] = new IndexedImage(
			64
			, 64
			, substr($lump->bytes(), 0, 4096)
			, str_repeat("\xff", 4096)
		);
	}

	/**
	 * @param string $name Direct picture lump name.
	 * @param Lump|null $known Pre-resolved lump.
	 * @return IndexedImage|null Decoded picture.
	 */
	public function picture(string $name, ?Lump $known = null): ?IndexedImage
	{
		$name = strtoupper($name);

		if(isset($this->cache['picture:' . $name])) return $this->cache['picture:' . $name];

		if(($code = Fon2Font::pictureCode($name)) !== null)
		{
			$image = $this->menuFont()?->glyph($code);

			if($image) $this->cache['picture:' . $name] = $image;

			return $image;
		}

		$lump = $known ?? $this->wad->lump($name);

		if(!$lump) return null;

		return $this->cache['picture:' . $name] = str_starts_with($lump->bytes(), PngDecoder::SIGNATURE)
			? PngDecoder::decode($lump, $this->pngPalette ??= new Palette($this->wad))
			: PatchDecoder::decode($lump);
	}

	/** Optional FON2 menu font, selected strictly by the conventional DBIGFONT lump. */
	public function menuFont(): ?Fon2Font
	{
		if($this->menuFontLoaded) return $this->menuFont;

		$this->menuFontLoaded = true;
		$lump = $this->wad->lump('DBIGFONT');

		return $this->menuFont = $lump ? Fon2Font::decode($lump, new Palette($this->wad)) : null;
	}

	/** Select the raw blood sprite supplied by the base IWAD, independent of overlays. */
	public function iwadBloodSprite(): string
	{
		$sprites = $this->wad->archives[0]->namespace(['S_START','SS_START'], ['S_END','SS_END']);

		foreach(['BLUD','BLOD'] as $prefix)
		foreach(array_keys($sprites) as $name)
		if(str_starts_with($name, $prefix)) return $prefix;

		// Doom's canonical spelling is the safest fallback for marker-less IWADs.
		return 'BLUD';
	}

	/**
	 * Resolve one sprite frame and closest rotation.
	 *
	 * @param string $sprite Four-character sprite prefix.
	 * @param string $frame Frame letter.
	 * @param int $rotation Direction 0 for rotation-independent or 1-8 around actor.
	 * @return array{image:IndexedImage,flipped:bool,name:string}|null Sprite record.
	 */
	public function sprite(string $sprite, string $frame = 'A', int $rotation = 0): ?array
	{
		$sprite = strtoupper(substr($sprite, 0, 4));
		$frame = strtoupper(substr($frame, 0, 1));
		$key = $sprite . ':' . $frame . ':' . $rotation;

		if(array_key_exists($key, $this->spriteCache)) return $this->spriteCache[$key] ?: null;

		// A rotation-zero lump explicitly applies from every viewpoint. Doom checks it
		// before directional frames, which also keeps pickups independent of camera angle.
		$candidates = $rotation ? [0,$rotation] : [0,1,2,3,4,5,6,7,8];

		foreach($candidates as $direction)
		{
			$entry = $this->spriteIndex[$sprite . $frame . $direction] ?? null;

			if(!$entry) continue;

			$lump = $entry['lump'];

			return $this->spriteCache[$key] = [
				'image' => $this->picture($lump->name, $lump)
				, 'flipped' => $entry['flipped']
				, 'name' => $lump->name
			];
		}

		$this->spriteCache[$key] = false;

		return null;
	}

	/** @return void Parse PNAMES and TEXTURE1/2 with later-archive replacement. */
	private function loadDefinitions(): void
	{
		foreach($this->wad->archives as $archive)
		{
			$pnamesLump = $archive->lump('PNAMES') ?? $this->wad->lump('PNAMES');

			if(!$pnamesLump) continue;

			$pnameBytes = $pnamesLump->bytes();
			$count = Binary::u32($pnameBytes, 0, $pnamesLump->identity());
			Binary::slice($pnameBytes, 4, $count * 8, $pnamesLump->identity());
			$pnames = [];

			for($i = 0; $i < $count; $i++)
			$pnames[] = Binary::name($pnameBytes, 4 + $i * 8, 8, $pnamesLump->identity());

			foreach(['TEXTURE1','TEXTURE2'] as $lumpName)
			if($textureLump = $archive->lump($lumpName)) $this->parseTextureLump($textureLump, $pnames);
		}
	}

	/**
	 * @param Lump $lump TEXTURE1/2 lump.
	 * @param list<string> $pnames Patch-name table.
	 * @return void Adds definitions.
	 */
	private function parseTextureLump(Lump $lump, array $pnames): void
	{
		$bytes = $lump->bytes();
		$count = Binary::u32($bytes, 0, $lump->identity());
		Binary::slice($bytes, 4, $count * 4, $lump->identity());

		for($i = 0; $i < $count; $i++)
		{
			$offset = Binary::u32($bytes, 4 + $i * 4, $lump->identity());
			$name = Binary::name($bytes, $offset, 8, $lump->identity());
			$width = Binary::u16($bytes, $offset + 12, $lump->identity());
			$height = Binary::u16($bytes, $offset + 14, $lump->identity());
			$patchCount = Binary::u16($bytes, $offset + 20, $lump->identity());
			Binary::slice($bytes, $offset + 22, $patchCount * 10, $lump->identity());
			$patches = [];

			for($j = 0; $j < $patchCount; $j++)
			{
				$o = $offset + 22 + $j * 10;
				$index = Binary::u16($bytes, $o + 4, $lump->identity());
				$patches[] = [
					'x' => Binary::s16($bytes, $o, $lump->identity())
					, 'y' => Binary::s16($bytes, $o + 2, $lump->identity())
					, 'name' => $pnames[$index] ?? ''
				];
			}

			$this->definitions[$name] = compact('name', 'width', 'height', 'patches');
		}
	}
}
