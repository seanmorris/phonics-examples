<?php
declare(strict_types=1);
namespace TerrainPhonics;

/**
 * Perspective flight camera with camera-relative GPU coordinates.
 *
 * World positions remain in metres. Only the rotational view matrix reaches the GPU;
 * mesh origins subtract the eye before projection to preserve precision during travel.
 */
final class Camera
{
	/** World-space eye position in metres. */
	public array $position = [0,700,1600];
	/** Heading and elevation in radians. */
	public float $yaw = 0, $pitch = -.22;
	/** Vertical field of view in radians. */
	public float $fov = 1.0471975512;

	/**
	 * Apply relative look motion, clamping pitch away from the poles.
	 * @param float $dx Horizontal angle change in radians.
	 * @param float $dy Vertical angle change in radians.
	 * @return void Updates camera orientation.
	 */
	public function look(float $dx, float $dy): void
	{
		$this->yaw = fmod($this->yaw + $dx, M_PI * 2);
		$this->pitch = max(-1.52, min(1.52, $this->pitch + $dy));
	}

	/**
	 * Move using the horizontal heading and an independent vertical axis.
	 * @param float $forward Forward input, normally -1 to 1.
	 * @param float $right Rightward input, normally -1 to 1.
	 * @param float $up Vertical input, normally -1 to 1.
	 * @param float $seconds Elapsed seconds.
	 * @param float $speed Movement speed in metres per second.
	 * @return void Updates world position without changing terrain residency directly.
	 */
	public function move(float $forward, float $right, float $up, float $seconds, float $speed): void
	{
		$length = max(1, sqrt($forward ** 2 + $right ** 2 + $up ** 2));
		$scale = max(0, $seconds) * $speed / $length;
		$this->position[0] += (sin($this->yaw) * $forward + cos($this->yaw) * $right) * $scale;
		$this->position[1] += $up * $scale;
		$this->position[2] += (-cos($this->yaw) * $forward + sin($this->yaw) * $right) * $scale;
	}

	/**
	 * Return the viewing basis.
	 * @return array Forward, right and up unit vectors.
	 */
	public function basis(): array
	{
		$s = sin($this->yaw);
		$c = cos($this->yaw);
		$p = sin($this->pitch);
		$q = cos($this->pitch);

		return [[$s * $q,$p,-$c * $q],[$c,0,$s],[-$s * $p,$q,$c * $p]];
	}

	/**
	 * Construct the projection times rotation matrix for relative coordinates.
	 * @param int $width Drawing-buffer width.
	 * @param int $height Drawing-buffer height.
	 * @return list<float> Column-major 4 by 4 matrix.
	 */
	public function viewProjection(int $width, int $height): array
	{
		[$f,$r,$u] = $this->basis();
		$v = [$r[0],$u[0],-$f[0],0,$r[1],$u[1],-$f[1],0,$r[2],$u[2],-$f[2],0,0,0,0,1];
		$t = 1 / tan($this->fov / 2);
		$near = .25;
		$far = 90000.0;
		$p = [
			$t / ($width / max(1, $height)),0,0,0,0,$t,0,0,0,0
			, ($far + $near) / ($near - $far)
			, -1
			, 0
			, 0
			, 2 * $far * $near / ($near - $far)
			, 0
		];

		$result = array_fill(0, 16, 0.0);

		for($column = 0; $column < 4; $column++)
		for($row = 0; $row < 4; $row++)
		for($k = 0; $k < 4; $k++) $result[$column * 4 + $row] += $p[$k * 4 + $row] * $v[$column * 4 + $k];

		return $result;
	}

	/**
	 * Reconstruct a picking ray from normalized viewport coordinates.
	 * @param float $nx Horizontal coordinate, zero at the left edge.
	 * @param float $ny Vertical coordinate, zero at the top edge.
	 * @param float $aspect Viewport width divided by height.
	 * @return array Unit direction in world coordinates.
	 */
	public function ray(float $nx, float $ny, float $aspect): array
	{
		[$f,$r,$u] = $this->basis();
		$x = (2 * $nx - 1) * tan($this->fov / 2) * $aspect;
		$y = (1 - 2 * $ny) * tan($this->fov / 2);
		$d = [];

		for($i = 0; $i < 3; $i++) $d[] = $f[$i] + $r[$i] * $x + $u[$i] * $y;
		$l = sqrt($d[0] ** 2 + $d[1] ** 2 + $d[2] ** 2);

		return [$d[0] / $l,$d[1] / $l,$d[2] / $l];
	}
}
