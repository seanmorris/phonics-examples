<?php
declare(strict_types=1);
namespace DoomPhonics\Graphics;

/** One ANIMDEFS camera/canvas texture and its capture/display dimensions. */
final readonly class CameraTextureDefinition
{
	public const CAMERA = 'camera';
	public const CANVAS = 'canvas';
	public const DOM = 'dom';

	public string $name;

	public function __construct(
		string $name
		, public int $width
		, public int $height
		, public int $fitWidth = 0
		, public int $fitHeight = 0
		, public bool $worldPanning = false
		, public string $kind = self::CAMERA
	){
		if($width < 1 || $height < 1 || $width > 4096 || $height > 4096)
		throw new \InvalidArgumentException('Camera texture dimensions must be within 1..4096');

		$this->name = strtoupper(substr($name, 0, 8));

		if($this->name === '') throw new \InvalidArgumentException('Camera texture name cannot be empty');

		if(!in_array($this->kind, [self::CAMERA,self::CANVAS,self::DOM], true))
		throw new \InvalidArgumentException("Unknown dynamic texture kind {$this->kind}");
	}

	public function displayWidth(): int { return $this->fitWidth > 0 ? $this->fitWidth : $this->width; }

	public function displayHeight(): int { return $this->fitHeight > 0 ? $this->fitHeight : $this->height; }
}
