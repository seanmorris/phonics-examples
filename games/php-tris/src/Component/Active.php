<?php
declare(strict_types=1);
namespace PhpTris\Component;

/** Current controllable piece; color order follows its rotating cell order. */
final class Active
{
	public string $shape = '';
	public array $colors = [];
	public int $x = 3, $y = 18, $rotation = 0, $kick = 0;
	public float $fall = 0, $lock = 0;
	public bool $rotated = false;
}
