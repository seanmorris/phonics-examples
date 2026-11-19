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
		[$forward,$right,$up] = $this->basis();
		$scaleY = 1 / tan($this->fov / 2);
		$scaleX = $scaleY / ($width / max(1, $height));
		$near = .25;
		$far = 90000.0;
		$depth = ($far + $near) / ($far - $near);

		// Scale the camera basis by the four nonzero projection coefficients.
		return [
			$scaleX*$right[0], $scaleY*$up[0], $depth*$forward[0], $forward[0],
			$scaleX*$right[1], $scaleY*$up[1], $depth*$forward[1], $forward[1],
			$scaleX*$right[2], $scaleY*$up[2], $depth*$forward[2], $forward[2],
			0.0, 0.0, -2*$far*$near / ($far - $near), 0.0
		];
	}

	/**
	 * Extract the six inward clip planes from the same column-major matrix used by the shaders.
	 * @param int $width Drawing-buffer width.
	 * @param int $height Drawing-buffer height.
	 * @return array Camera-relative plane equations.
	 */
	public function clipPlanes(int $width, int $height): array
	{
		$m = $this->viewProjection($width,$height);
		$planes = [];
		foreach([0,1,2] as $row) foreach([-1,1] as $sign)
			$planes[] = [$m[3]+$sign*$m[$row],$m[7]+$sign*$m[4+$row],
				$m[11]+$sign*$m[8+$row],$m[15]+$sign*$m[12+$row]];
		return $planes;
	}

	/**
	 * Test the farthest box corner against each plane, allowing for GPU float rounding.
	 * @param array $planes Camera-relative clip planes.
	 * @param array $eye World camera position.
	 * @param array $node Axis-aligned patch footprint.
	 * @param float $low Minimum rendered vertex height including morph.
	 * @param float $high Maximum rendered vertex height including morph.
	 * @return bool Whether the patch can intersect the frustum.
	 */
	public static function inView(array $planes, array $eye, array $node, float $low, float $high): bool
	{
		$x = $node['x'] - $eye[0];
		$z = $node['z'] - $eye[2];
		$size = $node['size'];
		// GPU clip coordinates are rounded before adding/subtracting w at each plane.
		$roundoff = 1e-5 * (1 + abs($x) + abs($z) + 2*$size + max(abs($low-$eye[1]), abs($high-$eye[1])));
		foreach($planes as [$a,$b,$c,$d])
		{
			$px = $a*($x + ($a>0 ? $size : 0));
			$py = $b*(($b>0 ? $high : $low)-$eye[1]);
			$pz = $c*($z + ($c>0 ? $size : 0));
			if($px+$py+$pz+$d < -$roundoff - 1e-5*(abs($px)+abs($py)+abs($pz)+abs($d))) return false;
		}
		return true;
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
