<?php
declare(strict_types=1);
namespace PhpBilliards\Render;

use PhpBilliards\Geometry\TableGeometry;
use PhpBilliards\Physics\Ball;
use PhpBilliards\Physics\Table;

/**
 * Orbit camera and shared 3D transform math for rendering and pointer projection.
 *
 * It owns the title and gameplay poses, their eased opening orbit, user-controlled orbit and zoom, view and
 * model matrices, cloth-plane picking, and the pitched cue pose that clears solid rails near the cue ball.
 */
final class Camera
{
	public const FOV_Y = .63;
	private const GAME_YAW = -.42;
	private const GAME_PITCH = .72;
	private const GAME_DISTANCE = 3.35;
	private const TRANSITION_SECONDS = 1.35;
	/** Horizontal orbit angle in radians. */
	public float $yaw = M_PI;
	/** Vertical orbit angle in radians. */
	public float $pitch = .12;
	/** Distance from the camera eye to its target in world units. */
	public float $distance = 1.5;
	/** @var array{float,float,float} World-space point around which the camera orbits. */
	public array $target = [.1,0.0,0.0];
	/** @var array{yaw:float,pitch:float,distance:float,target:array}|null */
	private ?array $transitionFrom = null;
	private ?array $transitionTo = null;
	private float $transitionTime = 0.0;
	/** Keep the opening's chosen arc when an orbit edit moves its destination across the wrap boundary. */
	private float $transitionYawDelta = 0.0;
	/** Fraction of the cue-ball offset followed by the established table view. */
	private const FOLLOW_TARGET = .55;
	private bool $followEnabled = false;
	private bool $followHeld = false;
	private bool $followSuspended = false;
	/** @var array{yaw:float,target:array}|null Latest requested follow pose, including while suspended. */
	private ?array $desiredFollow = null;
	private float $pullback = 0.0;
	private float $desiredPullback = 0.0;

	/**
	 * Orbit with pointer movement in pixels; its starting height fixes the horizontal direction.
	 * @param float $deltaX Horizontal pointer movement in pixels.
	 * @param float $deltaY Vertical pointer movement in pixels.
	 * @param float $pointerY Starting pointer height normalized to the canvas.
	 */
	public function orbit(float $deltaX, float $deltaY, float $pointerY): void
	{
		if(!is_finite($deltaX) || !is_finite($deltaY) || !is_finite($pointerY)) return;
		$this->adjustOrbit($deltaX * .006 * ($pointerY < .5 ? -1 : 1), $deltaY * .005);
	}

	/**
	 * Orbit at a frame-rate-independent rate from a standard SDL right stick.
	 * @param float $x Horizontal right-stick axis.
	 * @param float $y Vertical right-stick axis.
	 * @param float $seconds Display-frame duration in seconds.
	 */
	public function orbitStick(float $x, float $y, float $seconds): void
	{
		if(!is_finite($x) || !is_finite($y) || !is_finite($seconds) || $seconds <= 0) return;
		$x = max(-1, min(1, $x));
		$y = max(-1, min(1, $y));
		if(abs($x) <= 1e-6 && abs($y) <= 1e-6) return;
		$this->adjustOrbit($x * 1.9 * $seconds, $y * 1.45 * $seconds);
	}

	private function adjustOrbit(float $yaw, float $pitch): void
	{
		$this->hold();
		if($this->transitionTo !== null)
		{
			$this->transitionTo['yaw'] += $yaw;
			$this->transitionYawDelta += $yaw;
			$this->transitionTo['pitch'] = max(.28, min(1.18, $this->transitionTo['pitch'] + $pitch));
		}
		else
		{
			$this->yaw += $yaw;
			$this->pitch = max(.28, min(1.18, $this->pitch + $pitch));
		}
	}

	/** Begin the opening orbit from the exact current pose to the canonical gameplay view. */
	public function beginGameplayView(): void
	{
		$this->transitionFrom = [
			'yaw' => $this->yaw,'pitch' => $this->pitch,'distance' => $this->distance,'target' => $this->target
		];
		$this->transitionTo = [
			'yaw' => self::GAME_YAW,'pitch' => self::GAME_PITCH,'distance' => self::GAME_DISTANCE,'target' => [0.0,0.0,0.0]
		];
		$this->transitionTime = 0.0;
		$this->transitionYawDelta = self::wrap(self::GAME_YAW - $this->yaw);
		$this->followEnabled = $this->followHeld = $this->followSuspended = false;
		$this->desiredFollow = null;
	}

	/**
	 * Advance opening and motion easing without changing the player's stored zoom distance.
	 * @param float $seconds Display-frame duration in seconds.
	 * @param bool $moving Whether the balls currently require shot pullback.
	 */
	public function animate(float $seconds, bool $moving = false): void
	{
		if(!is_finite($seconds) || $seconds <= 0) return;
		$this->desiredPullback = $moving ? .32 : 0.0;
		$this->pullback = self::ease($this->pullback, $this->desiredPullback,
			1 - 2 ** (-$seconds / ($moving ? .10 : .16)), .001);
		if($this->transitionFrom === null) return;

		$this->transitionTime = min(self::TRANSITION_SECONDS, $this->transitionTime + $seconds);
		$t = $this->transitionTime / self::TRANSITION_SECONDS;
		$eased = $t ** 3 * ($t * ($t * 6 - 15) + 10);
		$this->yaw = $this->transitionFrom['yaw']
			+ $this->transitionYawDelta * $eased;
		foreach(['pitch','distance'] as $name)
		$this->{$name} = $this->transitionFrom[$name] + ($this->transitionTo[$name] - $this->transitionFrom[$name]) * $eased;
		foreach($this->target as $index => $_)
		$this->target[$index] = $this->transitionFrom['target'][$index]
			+ ($this->transitionTo['target'][$index] - $this->transitionFrom['target'][$index]) * $eased;

		if($this->transitionTime >= self::TRANSITION_SECONDS)
		{
			foreach(['yaw','pitch','distance','target'] as $name) $this->{$name} = $this->transitionTo[$name];
			$this->transitionFrom = $this->transitionTo = null;
		}
	}

	public function transitioning(): bool { return $this->transitionFrom !== null; }

	/** Only an actual, currently permitted animation can keep rendering active. */
	public function settling(): bool
	{
		if($this->transitioning() || $this->pullback !== $this->desiredPullback) return true;
		if(!$this->following() || $this->desiredFollow === null) return false;
		if(abs(self::wrap($this->desiredFollow['yaw'] - $this->yaw)) > 1e-4) return true;
		foreach($this->desiredFollow['target'] as $index => $value)
		if(abs($value - $this->target[$index]) > 1e-5) return true;
		return false;
	}

	/** Aim input requests follow without overriding a manual orbit held until a shot boundary. */
	public function requestFollow(): void { $this->followEnabled = true; }

	/** A new shot, rack, placement or camera reset releases manual orbit control. */
	public function resumeFollow(): void { $this->followEnabled = true; $this->followHeld = false; }

	/**
	 * Gestures and phases can temporarily suspend follow without losing the player's intent.
	 * @param bool $suspended Whether the current gesture or phase blocks follow.
	 */
	public function suspendFollow(bool $suspended): void { $this->followSuspended = $suspended; }

	private function following(): bool { return $this->followEnabled && !$this->followHeld && !$this->followSuspended; }

	/**
	 * Record the desired pose and, when permitted, ease behind the cue along the shortest yaw arc.
	 * @param float $cueX Cue-ball cloth X.
	 * @param float $cueY Cue-ball cloth Y.
	 * @param float $aim Cue direction in cloth-plane radians.
	 * @param float $seconds Display-frame duration in seconds.
	 */
	public function follow(float $cueX, float $cueY, float $aim, float $seconds): void
	{
		if(!is_finite($cueX) || !is_finite($cueY) || !is_finite($aim) || !is_finite($seconds) || $seconds < 0) return;
		$this->desiredFollow = [
			'yaw' => self::wrap($aim + M_PI),'target' => [$cueX * self::FOLLOW_TARGET,0.0,$cueY * self::FOLLOW_TARGET]
		];
		if($this->transitioning() || !$this->following() || $seconds === 0.0) return;
		$amount = 1 - exp(-$seconds * 6.5);
		$yawDelta = self::wrap($this->desiredFollow['yaw'] - $this->yaw);
		$this->yaw = abs($yawDelta * (1 - $amount)) <= 1e-4
			? $this->desiredFollow['yaw'] : $this->yaw + $yawDelta * $amount;
		foreach($this->desiredFollow['target'] as $index => $value)
		$this->target[$index] = self::ease($this->target[$index], $value, $amount, 1e-5);
	}

	private static function ease(float $current, float $desired, float $amount, float $epsilon): float
	{
		$value = $current + ($desired - $current) * $amount;
		return abs($desired - $value) <= $epsilon ? $desired : $value;
	}

	/**
	 * Snap behind the cue at the canonical gameplay pitch and distance.
	 * @param float $cueX Cue-ball cloth X.
	 * @param float $cueY Cue-ball cloth Y.
	 * @param float $aim Cue direction in cloth-plane radians.
	 */
	public function behind(float $cueX, float $cueY, float $aim): void
	{
		$this->reset();
		$this->resumeFollow();
		$this->follow($cueX, $cueY, $aim, 0);
		$this->yaw = $this->desiredFollow['yaw'];
		$this->target = $this->desiredFollow['target'];
	}

	/** Manual orbit holds follow until the next explicit boundary. */
	public function hold(): void { $this->followHeld = true; }

	private static function wrap(float $angle): float
	{
		$angle = fmod($angle + M_PI, M_PI * 2);
		if($angle < 0) $angle += M_PI * 2;
		return $angle - M_PI;
	}

	public function reset(): void
	{
		$this->yaw = self::GAME_YAW;
		$this->pitch = self::GAME_PITCH;
		$this->distance = self::GAME_DISTANCE;
		$this->target = [0.0,0.0,0.0];
		$this->transitionFrom = $this->transitionTo = $this->desiredFollow = null;
		$this->followEnabled = $this->followHeld = $this->followSuspended = false;
		$this->pullback = $this->desiredPullback = 0.0;
	}

	/**
	 * Wheel zoom retains follow intent and adjusts the destination of an unfinished opening.
	 * @param float $deltaY Signed wheel movement in pixels.
	 */
	public function zoom(float $deltaY): void
	{
		if(!is_finite($deltaY)) return;
		$distance = $this->transitionTo['distance'] ?? $this->distance;
		$distance = max(.85, min(5.2, $distance * exp($deltaY * .001)));
		if($this->transitionTo !== null) $this->transitionTo['distance'] = $distance;
		else $this->distance = $distance;
	}

	/**
	 * @param array<string,mixed> $patch Finite camera fields supplied by diagnostics.
	 */
	public function patch(array $patch): void
	{
		$changed = false;
		foreach(['yaw' => [-1000,1000],'pitch' => [.05,1.5],'distance' => [.05,5.2]] as $name => [$minimum,$maximum])
		if(isset($patch[$name]) && is_numeric($patch[$name]) && is_finite((float)$patch[$name]))
		{
			$this->{$name} = max($minimum, min($maximum, (float)$patch[$name]));
			$changed = true;
		}
		if(isset($patch['target']) && is_array($patch['target']) && count($patch['target']) === 3)
		{
			$target = array_map('floatval', array_values($patch['target']));
			if(count(array_filter($target, 'is_finite')) === 3) {$this->target = $target; $changed = true;}
		}
		if($changed) $this->transitionFrom = $this->transitionTo = $this->desiredFollow = null;
	}

	/** @return array{yaw:float,pitch:float,distance:float,target:array,transitioning:bool} */
	public function state(): array
	{
		return [
			'yaw' => $this->yaw,'pitch' => $this->pitch,'distance' => $this->distance,'target' => $this->target
			, 'transitioning' => $this->transitioning()
		];
	}

	/** @return array{float,float,float} Eye pose with eased shot pullback and rail clearance. */
	public function eye(): array
	{
		$distance = $this->distance + $this->pullback;
		return [
			$this->target[0] + cos($this->yaw) * cos($this->pitch) * $distance
			, max(TableGeometry::RAIL_TOP + .03, $this->target[1] + sin($this->pitch) * $distance)
			, $this->target[2] + sin($this->yaw) * cos($this->pitch) * $distance
		];
	}

	/** Presentation pose used by the renderer's frame signature, without changing diagnostic state fields. */
	public function viewPose(): array { return [$this->eye(),$this->target]; }

	/**
	 * Build the column-major view-projection matrix for the physical drawing-buffer aspect ratio.
	 * @param int $width Viewport width in pixels.
	 * @param int $height Viewport height in pixels.
	 */
	public function viewProjection(int $width, int $height): array
	{
		return self::multiply(
			self::perspective(self::FOV_Y, $width / max(1, $height), .05, 20)
			, self::lookAt($this->eye(), $this->target, [0,1,0])
		);
	}

	/**
	 * Project a normalized canvas point onto the cloth plane.
	 *
	 * A ray reconstructed from the exact camera basis and field of view intersects the requested horizontal plane,
	 * giving scratch placement the same perspective as the rendered table.
	 *
	 * @param float $x Horizontal pointer coordinate normalized to the viewport.
	 * @param float $y Vertical pointer coordinate normalized to the viewport.
	 * @param int $width Viewport width in pixels.
	 * @param int $height Viewport height in pixels.
	 * @param float $planeY World height of the target table plane.
	 * @return array{float,float}|null
	 */
	public function tablePoint(float $x, float $y, int $width, int $height, float $planeY = .012): ?array
	{
		if(!is_finite($x) || !is_finite($y) || $width < 1 || $height < 1) return null;

		$eye = $this->eye();
		$forward = self::normalize([
			$this->target[0] - $eye[0],$this->target[1] - $eye[1],$this->target[2] - $eye[2]
		]);
		$right = self::normalize(self::cross($forward, [0,1,0]));
		$up = self::normalize(self::cross($right, $forward));
		$ndcX = $x * 2 - 1;
		$ndcY = 1 - $y * 2;
		$halfHeight = tan(self::FOV_Y / 2);
		$halfWidth = $halfHeight * $width / $height;
		$direction = self::normalize([
			$forward[0] + $right[0] * $ndcX * $halfWidth + $up[0] * $ndcY * $halfHeight
			, $forward[1] + $right[1] * $ndcX * $halfWidth + $up[1] * $ndcY * $halfHeight
			, $forward[2] + $right[2] * $ndcX * $halfWidth + $up[2] * $ndcY * $halfHeight
		]);

		if(abs($direction[1]) < 1e-9) return null;

		$distance = ($planeY - $eye[1]) / $direction[1];
		if($distance <= 0) return null;

		return [$eye[0] + $direction[0] * $distance,$eye[2] + $direction[2] * $distance];
	}

	/**
	 * Position a cue over the first rail behind the ball while retaining tip contact.
	 *
	 * The nearest rearward rail determines the minimum shaft pitch, producing a complete transform whose tip remains
	 * on the aiming axis and whose butt clears the solid cushion and wood at every legal cue-ball position.
	 *
	 * @param float $cueX Cue-ball X coordinate.
	 * @param float $cueY Cue-ball Y coordinate.
	 * @param float $angle Cue direction in cloth-plane radians.
	 */
	public static function cuePose(float $cueX, float $cueY, float $angle): array
	{
		$length = 1.35;
		$buttDiameter = .0285;
		$dx = cos($angle);
		$dz = sin($angle);
		$ballCenter = [$cueX,Ball::RADIUS + .012,$cueY];
		$contactDistance = Ball::RADIUS + .008;
		$backX = -$dx;
		$backZ = -$dz;
		$distances = [];

		if(abs($backX) > 1e-8)
		{
			$distance = (($backX > 0 ? Table::WIDTH / 2 : -Table::WIDTH / 2) - $cueX) / $backX;
			if($distance > 0) $distances[] = $distance;
		}

		if(abs($backZ) > 1e-8)
		{
			$distance = (($backZ > 0 ? Table::HEIGHT / 2 : -Table::HEIGHT / 2) - $cueY) / $backZ;
			if($distance > 0) $distances[] = $distance;
		}

		// Pocket jaws can leave a legal ball centre just beyond a nominal cushion
		// plane. In that case the rearward cue ray has no positive intersection
		// with either plane; use the minimum clearance distance and pitch the cue
		// over the rail instead of asking min() to reduce an empty list.
		$railDistance = max(.001, $distances === [] ? .001 : min($distances));
		$railClearance = TableGeometry::RAIL_TOP + $buttDiameter / 2 + .006;
		$pitch = max(deg2rad(8), atan2($railClearance - $ballCenter[1], $railDistance));
		$sinYaw = sin($angle / 2);
		$cosYaw = cos($angle / 2);
		$sinPitch = sin($pitch / 2);
		$cosPitch = cos($pitch / 2);
		$direction = [$dx * cos($pitch),-sin($pitch),$dz * cos($pitch)];
		$tip = [];
		foreach($ballCenter as $index => $value) $tip[] = $value - $direction[$index] * $contactDistance;

		return [
			'position' => array_map(static fn(float $value, int $index): float => $value - $direction[$index] * $length / 2, $tip, array_keys($tip))
			, 'scale' => [$length,$buttDiameter,$buttDiameter]
			, 'rotation' => [$sinYaw * $sinPitch,-$sinYaw * $cosPitch,-$cosYaw * $sinPitch,$cosYaw * $cosPitch]
			, 'tip' => $tip
			, 'direction' => $direction
			, 'pitch' => $pitch
			, 'railDistance' => $railDistance
			, 'railClearance' => $railClearance
			, 'ballCenter' => $ballCenter
			, 'contactDistance' => $contactDistance
		];
	}

	/**
	 * @param array{float,float,float} $position World-space translation.
	 * @param array{float,float,float} $scale Per-axis model scale.
	 * @param array{float,float,float,float} $rotation Normalized rotation quaternion.
	 * @return list<float>
	 */
	public static function model(array $position, array $scale, array $rotation = [0,0,0,1]): array
	{
		[$x,$y,$z,$w] = $rotation;
		[$sx,$sy,$sz] = $scale;
		$x2 = $x + $x; $y2 = $y + $y; $z2 = $z + $z;
		$xx = $x * $x2; $xy = $x * $y2; $xz = $x * $z2;
		$yy = $y * $y2; $yz = $y * $z2; $zz = $z * $z2;
		$wx = $w * $x2; $wy = $w * $y2; $wz = $w * $z2;

		return [
			(1 - ($yy + $zz)) * $sx,($xy + $wz) * $sx,($xz - $wy) * $sx,0
			, ($xy - $wz) * $sy,(1 - ($xx + $zz)) * $sy,($yz + $wx) * $sy,0
			, ($xz + $wy) * $sz,($yz - $wx) * $sz,(1 - ($xx + $yy)) * $sz,0
			, $position[0],$position[1],$position[2],1
		];
	}

	/**
	 * @param array{float,float,float,float} $rotation Normalized rotation quaternion.
	 * @param array{float,float,float} $scale Per-axis model scale.
	 * @return list<float>
	 */
	public static function normalMatrix(array $rotation, array $scale): array
	{
		$matrix = self::model([0,0,0], array_map(static fn(float $value): float => 1 / $value, $scale), $rotation);

		return [$matrix[0],$matrix[1],$matrix[2],$matrix[4],$matrix[5],$matrix[6],$matrix[8],$matrix[9],$matrix[10]];
	}

	/** @return list<float> */
	private static function multiply(array $a, array $b): array
	{
		$out = array_fill(0, 16, 0.0);
		for($column = 0; $column < 4; $column++) for($row = 0; $row < 4; $row++)
		$out[$column * 4 + $row] = $a[$row] * $b[$column * 4]
			+ $a[4 + $row] * $b[$column * 4 + 1]
			+ $a[8 + $row] * $b[$column * 4 + 2]
			+ $a[12 + $row] * $b[$column * 4 + 3];

		return $out;
	}

	/** @return list<float> */
	private static function perspective(float $fovy, float $aspect, float $near, float $far): array
	{
		$f = 1 / tan($fovy / 2);
		$out = array_fill(0, 16, 0.0);
		$out[0] = $f / $aspect;
		$out[5] = $f;
		$out[10] = ($far + $near) / ($near - $far);
		$out[11] = -1;
		$out[14] = 2 * $far * $near / ($near - $far);

		return $out;
	}

	/** @return list<float> */
	private static function lookAt(array $eye, array $target, array $up): array
	{
		$z = self::normalize([$eye[0] - $target[0],$eye[1] - $target[1],$eye[2] - $target[2]]);
		$x = self::normalize(self::cross($up, $z));
		$y = self::cross($z, $x);
		$dot = static fn(array $a, array $b): float => $a[0] * $b[0] + $a[1] * $b[1] + $a[2] * $b[2];

		return [
			$x[0],$y[0],$z[0],0,$x[1],$y[1],$z[1],0,$x[2],$y[2],$z[2],0
			, -$dot($x, $eye),-$dot($y, $eye),-$dot($z, $eye),1
		];
	}

	/** @return array{float,float,float} */
	private static function normalize(array $vector): array
	{
		$length = sqrt($vector[0] ** 2 + $vector[1] ** 2 + $vector[2] ** 2) ?: 1;
		return [$vector[0] / $length,$vector[1] / $length,$vector[2] / $length];
	}

	/** @return array{float,float,float} */
	private static function cross(array $a, array $b): array
	{
		return [
			$a[1] * $b[2] - $a[2] * $b[1]
			, $a[2] * $b[0] - $a[0] * $b[2]
			, $a[0] * $b[1] - $a[1] * $b[0]
		];
	}
}
