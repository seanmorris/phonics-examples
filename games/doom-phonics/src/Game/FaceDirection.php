<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

use DoomPhonics\Component\Transform;

/** Typed direction from the player toward a source that can drive a HUD face reaction. */
final class FaceDirection
{
	private const FRONT = 'front';
	private const LEFT = 'left';
	private const RIGHT = 'right';

	private function __construct(private string $value) {}

	public static function front(): self { return new self(self::FRONT); }

	/** Resolve screen-relative direction using the same clockwise right basis as the renderer. */
	public static function toward(Transform $viewer, Transform $source): self
	{
		$angle = atan2($source->y - $viewer->y, $source->x - $viewer->x);
		$relative = atan2(sin($angle - $viewer->angle), cos($angle - $viewer->angle));

		if(abs($relative) <= M_PI / 4) return self::front();

		return new self($relative > 0 ? self::LEFT : self::RIGHT);
	}

	/** Status-face expression used for an ordinary directional hit. */
	public function expression(): string
	{
		return match($this->value)
		{
			self::LEFT => 'left',
			self::RIGHT => 'right',
			default => 'rampage',
		};
	}
}
