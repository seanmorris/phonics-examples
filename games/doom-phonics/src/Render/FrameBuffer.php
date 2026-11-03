<?php
declare(strict_types=1);
namespace DoomPhonics\Render;

/** CPU-rendered RGBA image and per-pixel depth used as a GLES upload source. */
final class FrameBuffer
{
	/** @var list<int> Little-endian RGBA pixels. */
	public array $pixels;
	/** @var list<float> Corrected camera-space depth. */
	public array $depth;

	public function __construct(public int $width, public int $height, int $clear = 0xff000000)
	{
		if($width < 1 || $height < 1) throw new \InvalidArgumentException('Framebuffer dimensions must be positive');

		$this->pixels = array_fill(0, $width * $height, $clear);
		$this->depth = array_fill(0, $width * $height, INF);
	}

	public function set(int $x, int $y, int $rgba, float $depth = INF): void
	{
		if($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) return;

		$offset = $y * $this->width + $x;

		if($depth > $this->depth[$offset]) return;

		$this->pixels[$offset] = $rgba;
		$this->depth[$offset] = $depth;
	}

	public function bytes(): string
	{
		$bytes = '';

		foreach(array_chunk($this->pixels, 4096) as $chunk) $bytes .= pack('V*', ...$chunk);

		return $bytes;
	}
}
