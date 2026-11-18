<?php
declare(strict_types=1);
namespace TerrainPhonics;

/** Validated serializable terrain recipe and independent coarse slope guide. */
final class Recipe
{
	/** Parameters are immutable after normalization. */
	private array $values;
	/** One deterministic noise source, sampled with distinct octave offsets. */
	private Noise $noise;

	/**
	 * Validate a bounded world recipe before allocating any terrain samples.
	 * @param array $values Optional recipe fields overriding the documented defaults.
	 * @return void Validates parameters before generating any terrain.
	 */
	public function __construct(array $values = [])
	{
		$defaults = [
			'version' => 1
			, 'seed' => 'first-light'
			, 'preset' => 'mountain'

			, 'worldSize' => 32768.0
			, 'spacing' => 1.0
			, 'tileSize' => 8
			, 'maxTiles' => 2048
			, 'waterLevel' => 0.0
			, 'heightScale' => 420.0
			, 'baseFrequency' => 1 / 6000

			, 'detailOctaves' => 8
			, 'detailAmplitude' => 28.0
			, 'lacunarity' => 2.0
			, 'persistence' => .5
			, 'slopeScale' => .45
			, 'damping' => 2.0

			, 'warpAmplitude' => 200.0
			, 'warpFrequency' => 1 / 10000
			, 'terraceSpacing' => 0.0
			, 'terraceStrength' => .7
		];

		if(array_diff_key($values, $defaults)) throw new \InvalidArgumentException('Unknown terrain recipe field');

		$values += $defaults;

		if($values['version'] !== 1) throw new \InvalidArgumentException('Unsupported terrain recipe version');

		if(!is_string($values['seed']) && !is_int($values['seed']))
		throw new \InvalidArgumentException('Invalid terrain seed');

		$values['seed'] = (string)$values['seed'];

		if(strlen($values['seed']) > 256) throw new \InvalidArgumentException('Terrain seed is too long');

		if(!in_array($values['preset'], ['mountain','canyon','coast'], true))
		throw new \InvalidArgumentException('Terrain preset must be mountain, canyon, or coast');

		foreach([
			'worldSize' => [16,32768]
			, 'spacing' => [.25,32]
			, 'waterLevel' => [-10000,10000]
			, 'heightScale' => [0,10000]
			, 'baseFrequency' => [1 / 1000000,1]

			, 'detailAmplitude' => [0,1000]
			, 'lacunarity' => [1.1,4]
			, 'persistence' => [0,1]
			, 'slopeScale' => [.001,100]
			, 'damping' => [0,100]

			, 'warpAmplitude' => [0,5000]
			, 'warpFrequency' => [1 / 1000000,.01]
			, 'terraceSpacing' => [0,1000]
			, 'terraceStrength' => [0,1]
		] as $key => [$min,$max])
		{
			$value = $values[$key];

			if((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value < $min || $value > $max)
			throw new \InvalidArgumentException('Invalid terrain ' . $key);

			$values[$key] = (float)$value;
		}

		foreach(['tileSize' => [4,32], 'maxTiles' => [4,16384], 'detailOctaves' => [0,12]] as $key => [$min,$max])
		{
			if(!is_int($values[$key]) || $values[$key] < $min || $values[$key] > $max)
			throw new \InvalidArgumentException('Invalid terrain ' . $key);
		}

		$this->values = $values;
		$this->noise = new Noise($values['seed']);
	}

	/**
	 * Return the portable normalized parameters, without cached samples.
	 * @return array Normalized portable recipe parameters.
	 */
	public function values(): array { return $this->values; }

	/**
	 * Evaluate the C2 macro field, including world-space first derivatives.
	 * @param float $x World X coordinate in metres.
	 * @param float $z World Z coordinate in metres.
	 * @return array Coarse height and world-space derivatives [height,dx,dz].
	 */
	public function coarse(float $x, float $z): array
	{
		$r = $this->values;
		$f = $r['baseFrequency'];
		$scale = $r['heightScale'];

		if($scale === 0.0) return [0.0,0.0,0.0];

		$jacobian = [1.0,0.0,0.0,1.0];

		if($r['warpAmplitude'] > 0)
		{
			$wf = $r['warpFrequency'];
			$wa = $r['warpAmplitude'];
			$wx = $this->noise->sample($x * $wf + 271.13, $z * $wf - 197.41);
			$wz = $this->noise->sample($x * $wf - 419.27, $z * $wf + 113.71);
			$x += $wa * $wx[0];
			$z += $wa * $wz[0];

			$jacobian = [
				1 + $wa * $wf * $wx[1], $wa * $wf * $wx[2]
				, $wa * $wf * $wz[1], 1 + $wa * $wf * $wz[2]
			];
		}

		$a = $this->noise->sample($x * $f + 13.17, $z * $f - 9.31);
		$b = $this->noise->sample($x * $f * 2 + 71.43, $z * $f * 2 + 53.11);

		if($r['preset'] === 'mountain')
		{
			$rounded = sqrt($a[0] * $a[0] + .04 * .04);
			$value = .35 - $rounded + .24 * $b[0];
			$factor = -$a[0] / $rounded;
			$dx = $factor * $a[1] * $f + .48 * $b[1] * $f;
			$dz = $factor * $a[2] * $f + .48 * $b[2] * $f;
		}
		elseif($r['preset'] === 'canyon')
		{
			// A narrow contour band connects a distinct floor and plateau. The quintic
			// has zero first/second derivatives at both ends, so clamping remains C2.
			// abs() is harmless at zero because a constant floor surrounds that point.
			$wall = max(0.0, min(1.0, (abs($a[0]) - .055) / .006));
			$value = -.55 + Noise::fade($wall) + .15 * $b[0];
			$factor = ($a[0] < 0 ? -1 : 1) * 30 * $wall * $wall * ($wall - 1) ** 2 / .006;

			$dx = $factor * $a[1] * $f + .3 * $b[1] * $f;
			$dz = $factor * $a[2] * $f + .3 * $b[2] * $f;
		}
		else
		{
			$coast = tanh($x / 4500);
			$value = .6 * $a[0] + .35 * $coast + .15 * $b[0];
			$dx = .6 * $a[1] * $f + .35 * (1 - $coast * $coast) / 4500 + .3 * $b[1] * $f;
			$dz = .6 * $a[2] * $f + .3 * $b[2] * $f;
		}

		$height = $scale * $value;
		[$dx,$dz] = [
			$scale * ($dx * $jacobian[0] + $dz * $jacobian[2])
			, $scale * ($dx * $jacobian[1] + $dz * $jacobian[3])
		];

		if($r['terraceSpacing'] > 0)
		{
			$spacing = $r['terraceSpacing'];
			$strength = $r['terraceStrength'];
			$level = floor($height / $spacing);
			$fraction = $height / $spacing - $level;

			$height += $strength * ($spacing * ($level + Noise::fade($fraction)) - $height);
			$factor = 1 - $strength + $strength * 30 * $fraction * $fraction * ($fraction - 1) ** 2;
			$dx *= $factor;
			$dz *= $factor;
		}

		return [$height,$dx,$dz];
	}

	/**
	 * Sample finite smooth detail, gating every octave from the independent coarse guide.
	 * @param float $x World X coordinate in metres.
	 * @param float $z World Z coordinate in metres.
	 * @return float Final procedural lattice height in metres.
	 */
	public function height(float $x, float $z): float
	{
		$r = $this->values;
		[$height,$dx,$dz] = $this->coarse($x, $z);
		$slope = ($dx * $dx + $dz * $dz) / ($r['slopeScale'] * $r['slopeScale']);
		$frequency = $r['baseFrequency'] * 8;
		$amplitude = $r['detailAmplitude'];

		if($amplitude === 0.0) return $height;

		for($i = 0; $i < $r['detailOctaves']; $i++)
		{
			$weight = 1 / (1 + $r['damping'] * ($i + 1) * ($i + 1) * $slope);

			$height += $amplitude * $weight * $this->noise->value(
				$x * $frequency + 91.73 + $i * 19.17
				, $z * $frequency - 23.49 - $i * 31.29
			);

			$frequency *= $r['lacunarity'];
			$amplitude *= $r['persistence'];
		}

		return $height;
	}

	/**
	 * Bound every procedural node in a world rectangle, including warp, terraces and gated detail.
	 * @param array $box Horizontal bounds [minX,minZ,maxX,maxZ], including any sample halo.
	 * @return array Conservative node height interval in metres.
	 */
	public function regionalBounds(array $box): array
	{
		$r = $this->values;
		$x = ($box[0] + $box[2]) / 2;
		$z = ($box[1] + $box[3]) / 2;
		$rx = ($box[2] - $box[0]) / 2 + 1e-9;
		$rz = ($box[3] - $box[1]) / 2 + 1e-9;
		$wx = $x; $wz = $z; $hx = $rx; $hz = $rz;
		$jac = 1.0;
		$w = $r['warpAmplitude'];
		$f = $r['warpFrequency'];
		if($w > 0)
		{
			$a = $this->noise->envelope($x*$f+271.13, $z*$f-197.41, $rx*$f, $rz*$f);
			$b = $this->noise->envelope($x*$f-419.27, $z*$f+113.71, $rx*$f, $rz*$f);
			$wx += $w * ($a[0]+$a[1])/2; $hx += $w * ($a[1]-$a[0])/2 + 1e-9;
			$wz += $w * ($b[0]+$b[1])/2; $hz += $w * ($b[1]-$b[0])/2 + 1e-9;
			$jac += $w*$f * max($a[2]+$b[2], $a[3]+$b[3]);
		}
		$f = $r['baseFrequency'];
		$a = $this->noise->envelope($wx*$f+13.17, $wz*$f-9.31, $hx*$f, $hz*$f);
		$b = $this->noise->envelope($wx*$f*2+71.43, $wz*$f*2+53.11, $hx*$f*2, $hz*$f*2);
		$absMin = max(0.0, $a[0], -$a[1]);
		$absMax = max(-$a[0], $a[1]);
		$da = max($a[2],$a[3])*$f;
		$db = max($b[2],$b[3])*$f*2;
		switch($r['preset'])
		{
			case 'mountain':
				$lo = .35 - sqrt($absMax**2 + .0016) + .24*$b[0];
				$hi = .35 - sqrt($absMin**2 + .0016) + .24*$b[1];
				$gradient = $da + .24*$db;
				break;
			case 'canyon':
				$wallLo = max(0.0,min(1.0,($absMin-.055)/.006));
				$wallHi = max(0.0,min(1.0,($absMax-.055)/.006));
				$lo = -.55 + Noise::fade($wallLo) + .15*$b[0];
				$hi = -.55 + Noise::fade($wallHi) + .15*$b[1];
				$gradient = ($wallHi === 0.0 || $wallLo === 1.0 ? 0 : 1.875/.006)*$da + .15*$db;
				break;
			default:
				$lo = .6*$a[0] + .35*tanh(($wx-$hx)/4500) + .15*$b[0];
				$hi = .6*$a[1] + .35*tanh(($wx+$hx)/4500) + .15*$b[1];
				$gradient = .6*$da + .35/4500 + .15*$db;
		}
		$lo *= $r['heightScale']; $hi *= $r['heightScale'];
		$gradient *= $r['heightScale']*$jac;
		$spacing = $r['terraceSpacing'];
		if($spacing > 0)
		{
			// Within a terrace the displacement is always less than the terrace spacing.
			// Tiny spacings can lose the fractional part of the level in binary64.
			$lo -= $spacing*$r['terraceStrength'];
			$hi += $spacing*$r['terraceStrength'];
			$gradient *= 1.875;
		}
		$slope = 2 * (($gradient+1e-8) / $r['slopeScale'])**2;
		$f *= 8;
		$amp = $r['detailAmplitude'];
		for($i=0; $i<$r['detailOctaves'] && $amp>0; $i++)
		{
			[$n,$m] = $this->noise->envelope($x*$f+91.73+$i*19.17, $z*$f-23.49-$i*31.29, $rx*$f, $rz*$f);
			$minWeight = 1 / (1+$r['damping']*($i+1)**2*$slope);
			$lo += $amp * min($n,$n*$minWeight);
			$hi += $amp * max($m,$m*$minWeight);
			$f *= $r['lacunarity'];
			$amp *= $r['persistence'];
		}
		return [$lo-1e-6,$hi+1e-6];
	}

	/**
	 * Conservative node extrema include the maximum possible gradient-noise magnitude.
	 * @return array Conservative lower and upper node heights.
	 */
	public function bounds(): array
	{
		$r = $this->values;
		$detail = 0;
		$amplitude = $r['detailAmplitude'];

		for($i = 0; $i < $r['detailOctaves']; $i++)
		{
			$detail += 2 * $amplitude;
			$amplitude *= $r['persistence'];
		}

		$range = 3 * $r['heightScale'] + $detail + $r['terraceSpacing'];

		return [-$range, $range];
	}
}
