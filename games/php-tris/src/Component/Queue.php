<?php
declare(strict_types=1);
namespace PhpTris\Component;
use PhpTris\Random;
use PhpTris\Shapes;

/** Independent random streams prevent color choices from changing the shape sequence. */
final class Queue
{
	public array $items = [];
	public ?array $held = null;
	public bool $usedHold = false;
	public Random $random, $colors;

	/**
	 * Initialize the component and its owned resources.
	 *
	 * @param string $mode Selected rule track identifier.
	 * @param int $seed Deterministic bag seed.
	 * @return void Updates owned state without returning a value.
	 */
	public function __construct(public string $mode = 'endless', int $seed = 1)
	{
		$this->random = new Random($seed);
		$this->colors = new Random(($seed % 1000000) + 7919);
	}

	/**
	 * Append shuffled bags until six previews remain available after a draw.
	 *
	 * @return void Updates owned state without returning a value.
	 */
	public function fill(): void
	{
		while(count($this->items) < 7)
		{
			$bag = ['I','J','L','O','S','Z','T'];

			if($this->mode === 'reactor') array_push($bag, 'A', 'A', 'A');

			foreach($this->random->shuffle($bag) as $shape)
			{
				$color = array_search($shape, array_keys(Shapes::CELLS), true);
				$colors = array_fill(0, count(Shapes::cells($shape)), $color);
				$chance = $this->mode === 'bonds' ? 20 : ($this->mode === 'targets' ? 10 : 0);

				if($chance && $this->colors->next(100) < $chance)
				{
					$colors[0] = ($color + 1 + $this->colors->next(6)) % 7;
					$colors[1] = $colors[0];
				}

				$this->items[] = ['shape' => $shape, 'colors' => $colors];
			}
		}
	}

	/**
	 * Draw a piece and replenish previews.
	 *
	 * @return array Computed values in iteration order.
	 */
	public function take(): array
	{
		$this->fill();
		$item = array_shift($this->items);
		$this->fill();

		return $item;
	}
}
