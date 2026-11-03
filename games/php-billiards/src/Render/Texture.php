<?php
declare(strict_types=1);
namespace PhpBilliards\Render;

/**
 * Restorable raw RGBA texture owned by the PHP renderer.
 *
 * Raw checked pixels are retained beside their sampling policy so the texture can upload with matching mip and
 * wrapping behavior, survive a WebGL context restoration, and release its GL handle exactly once.
 */
final class Texture
{
	private array $handles = [];
	private bool $closed = false;

	/**
	 * Upload checked raw pixels with explicit axis-wrapping behavior.
	 *
	 * Dimensions must match the four-channel byte count before storage, filtering, wrap modes, and mip generation are
	 * configured; retained bytes later recreate the identical texture after context loss.
	 *
	 * @param string $pixels Raw four-channel texture bytes.
	 * @param int $width Texture width in pixels.
	 * @param int $height Texture height in pixels.
	 * @param bool $repeatS Whether the horizontal axis repeats.
	 * @param bool $repeatT Whether the vertical axis repeats.
	 * @param int $layers Layer count; more than one uploads a 2D texture array of equally sized layers.
	 */
	public function __construct(
		private string $pixels, private int $width, private int $height
		, private bool $repeatS = true, private bool $repeatT = true, private int $layers = 1
	){
		if($width < 1 || $height < 1 || $layers < 1 || strlen($pixels) !== $width * $height * 4 * $layers)
		throw new \InvalidArgumentException('Invalid raw RGBA texture');

		$this->create();
	}

	private function create(): void
	{
		$target = $this->target();
		glGenTextures(1, $this->handles);
		$this->bind();
		glTexParameteri($target, GL_TEXTURE_MIN_FILTER, GL_LINEAR_MIPMAP_LINEAR);
		glTexParameteri($target, GL_TEXTURE_MAG_FILTER, GL_LINEAR);
		glTexParameteri($target, GL_TEXTURE_WRAP_S, $this->repeatS ? GL_REPEAT : GL_CLAMP_TO_EDGE);
		glTexParameteri($target, GL_TEXTURE_WRAP_T, $this->repeatT ? GL_REPEAT : GL_CLAMP_TO_EDGE);
		// Array layers keep independent mip chains, so stacked ball skins never bleed into one another.
		if($this->layers > 1) glTexImage3D($target, 0, GL_RGBA8, $this->width, $this->height, $this->layers, 0
			, GL_RGBA, GL_UNSIGNED_BYTE, $this->pixels);
		else glTexImage2D($target, 0, GL_RGBA8, $this->width, $this->height, 0, GL_RGBA, GL_UNSIGNED_BYTE, $this->pixels);
		glGenerateMipmap($target);
	}

	public function bind(): void { glBindTexture($this->target(), $this->handles[0]); }

	private function target(): int { return $this->layers > 1 ? GL_TEXTURE_2D_ARRAY : GL_TEXTURE_2D; }

	private function release(): void
	{
		if($this->handles) glDeleteTextures(count($this->handles), $this->handles);
		$this->handles = [];
	}

	public function restore(): void
	{
		if($this->closed) return;
		$this->release();
		$this->create();
	}

	public function close(): void
	{
		if($this->closed) return;
		$this->closed = true;
		$this->release();
	}
}
