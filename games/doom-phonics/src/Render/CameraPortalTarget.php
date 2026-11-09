<?php
declare(strict_types=1);
namespace DoomPhonics\Render;

use Phonics\Render\RenderTarget;

/** Offscreen colour-and-depth target a camera texture, mirror or portal view is rendered into. */
final class CameraPortalTarget
{
	private RenderTarget $target;

	public function __construct(public int $width, public int $height)
	{
		$this->target = new RenderTarget($width, $height, GL_LINEAR, true, 'Doom-Phonics camera portal');
	}

	public function texture(): int { return $this->target->texture(); }

	public function resize(int $width, int $height): void
	{
		if($width === $this->width && $height === $this->height) return;

		if($this->target->bound()) throw new \LogicException('Camera portal target cannot resize while capturing');

		$this->target->resize($width, $height);
		$this->width = $width;
		$this->height = $height;
	}

	public function begin(): void { $this->target->bind([0, 0, 0, 1]); }

	public function end(): void { $this->target->unbind(); }

	public function restore(): void { $this->target->restore(); }

	public function close(): void { $this->target->close(); }
}
