<?php
declare(strict_types=1);
namespace DoomPhonics\Render;

/** Classic 35 Hz staggered-column motion, independent of framebuffer and GPU ownership. */
final class MeltColumns
{
	private const TICS_PER_SECOND = 35;
	/** @var list<int> Logical top-edge offsets for each two-pixel-style column. */
	private array $positions = [];
	private float $remainder = 0;
	private int $randomState = 1;
	private int $revision = 0;
	private bool $complete = true;

	public function __construct(
		public readonly int $columns = 160
		, public readonly int $height = 200
	){
		if($columns < 1 || $height < 1)
		throw new \InvalidArgumentException('Melt dimensions must be positive');
	}

	/** Rebuild the staggered delays for one new transition. */
	public function restart(int $seed = 1): void
	{
		$this->randomState = $seed & 0x7fffffff;
		$this->positions = [-($this->randomByte() % 16)];

		for($column = 1; $column < $this->columns; $column++)
		{
			$position = $this->positions[$column - 1] + ($this->randomByte() % 3) - 1;

			if($position > 0) $position = 0;
			elseif($position === -16) $position = -15;

			$this->positions[] = $position;
		}

		$this->remainder = 0;
		$this->complete = false;
		$this->revision++;
	}

	/** Advance whole classic tics while retaining fractional browser-frame time. */
	public function advance(float $elapsed): void
	{
		if(!is_finite($elapsed) || $elapsed < 0)
		throw new \InvalidArgumentException('Melt elapsed time must be finite and nonnegative');

		if($this->complete) return;

		$this->remainder += $elapsed * self::TICS_PER_SECOND;
		$tics = (int)floor($this->remainder + 1e-9);

		if($tics < 1) return;

		$this->remainder -= $tics;

		while($tics-- > 0 && !$this->complete) $this->tick();
	}

	public function complete(): bool { return $this->complete; }

	public function revision(): int { return $this->revision; }

	/** @return list<int> Current logical offsets, including negative startup delays. */
	public function positions(): array { return $this->positions; }

	/** Normalized R32F values ready for the column-offset texture. */
	public function textureBytes(): string
	{
		$values = array_map(
			fn(int $position): float => max(0, min($this->height, $position)) / $this->height
			, $this->positions
		);

		return pack('f*', ...$values);
	}

	/** Mean revealed height, useful for diagnostics without exposing render resources. */
	public function progress(): float
	{
		if(!$this->positions) return $this->complete ? 1.0 : 0.0;

		$revealed = array_sum(
			array_map(fn(int $position): int => max(0, min($this->height, $position)), $this->positions)
		);

		return $revealed / ($this->columns * $this->height);
	}

	private function tick(): void
	{
		$this->complete = true;

		foreach($this->positions as &$position)
		{
			if($position < 0) $position++;
			elseif($position < $this->height)
			{
				$distance = $position < 16 ? $position + 1 : 8;
				$position += min($distance, $this->height - $position);
			}

			if($position < $this->height) $this->complete = false;
		}

		unset($position);
		$this->revision++;
	}

	/** Small private generator keeps wipe layout deterministic without mutating gameplay RNG state. */
	private function randomByte(): int
	{
		// PHP-WASM integers are 32-bit. Split the ANSI LCG into 16-bit limbs so the
		// multiplication never promotes to an imprecise float before modulo 2^31.
		$low = $this->randomState & 0xffff;
		$high = ($this->randomState >> 16) & 0x7fff;
		$lowProduct = $low * 20077 + 12345;
		$upper = ($low * 16838 + $high * 20077 + ($lowProduct >> 16)) & 0x7fff;
		$this->randomState = ($upper << 16) | ($lowProduct & 0xffff);

		return ($this->randomState >> 16) & 0xff;
	}
}
