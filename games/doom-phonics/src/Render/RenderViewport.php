<?php
declare(strict_types=1);
namespace DoomPhonics\Render;

/** Output and scene framebuffer dimensions resolved as one immutable rendering value. */
final class RenderViewport
{
	public readonly int $sceneWidth;
	public readonly int $sceneHeight;

	public function __construct(
		public readonly int $outputWidth
		, public readonly int $outputHeight
		, public readonly float $sceneScale = 1.0
	){
		if($outputWidth < 1 || $outputHeight < 1)
		throw new \InvalidArgumentException('Render viewport dimensions must be positive');

		if(!is_finite($sceneScale) || $sceneScale <= 0 || $sceneScale > 1)
		throw new \InvalidArgumentException('Scene scale must be within (0, 1]');

		$this->sceneWidth = max(1, (int)round($outputWidth * $sceneScale));
		$this->sceneHeight = max(1, (int)round($outputHeight * $sceneScale));
	}

	public function equals(self $other): bool
	{
		return $this->outputWidth === $other->outputWidth
			&& $this->outputHeight === $other->outputHeight
			&& $this->sceneWidth === $other->sceneWidth
			&& $this->sceneHeight === $other->sceneHeight
			&& $this->sceneScale === $other->sceneScale;
	}
}
