<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use SanePhonics\Physics\Body;
use Phonics\Render\Camera;

/** Smoothly frames a platformer actor, scripted focus point, and optional world bounds. */
final class PlatformCamera
{
	/** Resolved camera mode used during the last update. */
	public string $mode = 'normal';
	/** Current smoothed horizontal viewport anchor from zero through one. */
	public float $anchorX = .5;
	/** Current smoothed vertical viewport anchor from zero through one. */
	public float $anchorY = .5;
	/** Desired horizontal viewport anchor before smoothing. */
	public float $targetAnchorX = .5;
	/** Desired vertical viewport anchor before smoothing. */
	public float $targetAnchorY = .5;
	/** Vertical viewport anchor used by boss mode. */
	public float $bossAnchorY = .72;
	private string $requestedMode = 'auto';
	/** @var array{x:float,y:float,mode:string}|null */
	private ?array $focus = null;
	/** @var array{left:float,right:float,top:?float,bottom:?float}|null */
	private ?array $worldBounds = null;
	/** @var array{minX:?float,maxX:?float,minY:?float,maxY:?float} */
	private array $cameraLimits = ['minX' => null, 'maxX' => null, 'minY' => null, 'maxY' => null];
	private float $airTime = 0;
	private float $lookTime = 0;
	private int $lookDirection = 0;

	/**
	 * Select automatic actor framing or one of the supported scripted camera modes.
	 *
	 * Modes mirror the reusable framing modes in moving-boxes-2: normal, aerial, climbing, tube,
	 * panning, boss, perspective, bridge, cliff, walker, airplane, boost-ring, cross-cannon,
	 * cross-cannon-quick, cutScene, corkscrew, popping, hooked, cinematic, draggable, and locked.
	 *
	 * @param string $mode Mode name, or auto to derive a mode from the actor state.
	 * @return void Changes the desired framing on the next update without moving the camera now.
	 */
	public function setMode(string $mode): void
	{
		if(!in_array($mode, self::modes(), true))
		{
			throw new \InvalidArgumentException('Unknown platform camera mode');
		}

		$this->requestedMode = $mode;
	}

	/**
	 * Pan toward a world point instead of the tracked body until focus is cleared.
	 *
	 * @param float $x Focus point world X coordinate in pixels.
	 * @param float $y Focus point world Y coordinate in pixels.
	 * @param string $mode Camera mode used to frame and approach the point.
	 * @return void Stores the new target; the next update begins a smooth transition.
	 */
	public function focus(float $x, float $y, string $mode = 'panning'): void
	{
		if(!is_finite($x) || !is_finite($y) || $mode === 'auto' || !in_array($mode, self::modes(), true))
		{
			throw new \InvalidArgumentException('Invalid platform camera focus');
		}

		$this->focus = compact('x', 'y', 'mode');
	}

	/**
	 * Return focus to the tracked actor.
	 *
	 * @return void Leaves the current camera pose intact so the return transition pans smoothly.
	 */
	public function clearFocus(): void
	{
		$this->focus = null;
	}

	/**
	 * Restrict the visible span to a world rectangle, centering axes narrower than the viewport.
	 *
	 * @param float $left Left edge of the allowed world rectangle.
	 * @param float $right Right edge of the allowed world rectangle.
	 * @param float|null $top Optional top edge; null leaves vertical movement unbounded.
	 * @param float|null $bottom Optional bottom edge; null leaves vertical movement unbounded.
	 * @return void Constrains future destinations without snapping the current camera pose.
	 */
	public function setWorldBounds(float $left, float $right, ?float $top = null, ?float $bottom = null): void
	{
		if(!is_finite($left) || !is_finite($right) || $right <= $left
			|| ($top === null) !== ($bottom === null)
			|| ($top !== null && (!is_finite($top) || !is_finite($bottom) || $bottom <= $top))
		){
			throw new \InvalidArgumentException('Invalid platform camera world bounds');
		}

		$this->worldBounds = compact('left', 'right', 'top', 'bottom');
	}

	/**
	 * Remove the active world rectangle.
	 *
	 * @return void Leaves the current camera pose intact so release from a lock pans smoothly.
	 */
	public function clearWorldBounds(): void
	{
		$this->worldBounds = null;
	}

	/**
	 * Restrict destination top-left coordinates independently from world-span bounds.
	 *
	 * @param float|null $minX Optional minimum camera X coordinate.
	 * @param float|null $maxX Optional maximum camera X coordinate.
	 * @param float|null $minY Optional minimum camera Y coordinate.
	 * @param float|null $maxY Optional maximum camera Y coordinate.
	 * @return void Replaces camera-coordinate limits without moving the current pose immediately.
	 */
	public function setCameraLimits(
		?float $minX = null
		, ?float $maxX = null
		, ?float $minY = null
		, ?float $maxY = null
	): void {
		$limits = compact('minX', 'maxX', 'minY', 'maxY');

		foreach($limits as $value)
		{
			if($value !== null && !is_finite($value))
			{
				throw new \InvalidArgumentException('Invalid platform camera coordinate limit');
			}
		}

		if(($minX !== null && $maxX !== null && $maxX < $minX)
			|| ($minY !== null && $maxY !== null && $maxY < $minY)
		){
			throw new \InvalidArgumentException('Inverted platform camera coordinate limits');
		}

		$this->cameraLimits = $limits;
	}

	/**
	 * Reset transition-only input state after a spawn, teleport, or level change.
	 *
	 * @param Body $body Actor whose current grounded state seeds automatic mode selection.
	 * @return void Keeps explicit mode, focus, bounds, and camera pose while clearing stale holds.
	 */
	public function sync(Body $body): void
	{
		$this->airTime = 0;
		$this->lookTime = 0;
		$this->lookDirection = 0;
		$this->mode = $this->resolveMode($body);
		[$x, $y] = $this->preset($body, self::surface($body->angle));
		$this->anchorX = $this->targetAnchorX = max(0, min(1, $x));
		$this->anchorY = $this->targetAnchorY = max(0, min(1, $y));
	}

	/**
	 * Advance framing and pan the supplied camera toward its constrained destination.
	 *
	 * @param Camera $camera Mutable rendered viewport.
	 * @param Body $body Actor supplying support position, speed, surface, and movement state.
	 * @param float $dt Elapsed simulation time in seconds.
	 * @param float $look Signed look input from minus one for up through one for down.
	 * @return void Smooths anchors and camera position with frame-rate-independent damping.
	 */
	public function update(Camera $camera, Body $body, float $dt, float $look = 0): void
	{
		if(!is_finite($dt) || $dt < 0 || !is_finite($look))
		{
			throw new \InvalidArgumentException('Invalid platform camera update');
		}

		$this->airTime = $body->grounded ? 0 : $this->airTime + $dt;
		$this->mode = $this->resolveMode($body);
		$surface = self::surface($body->angle);
		[$anchorX, $anchorY, $anchorFrames] = $this->preset($body, $surface);
		[$anchorX, $anchorY] = $this->applySpeedBias($body, $surface, $anchorX, $anchorY);
		$anchorY += $this->lookBias($look, $dt);
		$this->targetAnchorX = max(0, min(1, $anchorX));
		$this->targetAnchorY = max(0, min(1, $anchorY));

		$anchorBlend = self::frameBlend($anchorFrames, $dt);
		$this->anchorX += ($this->targetAnchorX - $this->anchorX) * $anchorBlend;
		$this->anchorY += ($this->targetAnchorY - $this->anchorY) * $anchorBlend;

		$x = $this->focus['x'] ?? $body->supportX();
		$y = $this->focus['y'] ?? $body->supportY();
		$destinationX = $x - $camera->width / $camera->zoom * $this->anchorX;
		$destinationY = $y - $camera->height / $camera->zoom * $this->anchorY;
		[$destinationX, $destinationY] = $this->constrain($camera, $destinationX, $destinationY);
		$positionFrames = $this->mode === 'panning' ? 6 : 12;

		$camera->panTo($destinationX, $destinationY, $dt, self::response($positionFrames));
	}

	/**
	 * Resolve an explicit, focused, or actor-derived mode.
	 *
	 * @param Body $body Actor whose state selects an automatic framing mode.
	 * @return string Supported concrete camera mode.
	 */
	private function resolveMode(Body $body): string
	{
		if($this->focus !== null)
		{
			return $this->focus['mode'];
		}

		if($this->requestedMode !== 'auto')
		{
			return $this->requestedMode;
		}

		if($body->ability === 'hanging')
		{
			return 'hooked';
		}

		if($body->ability === 'climb')
		{
			return 'climbing';
		}

		if($body->grounded && $body->rolling && self::surface($body->angle) !== 0)
		{
			return 'tube';
		}

		return !$body->grounded && $this->airTime >= .75 ? 'aerial' : 'normal';
	}

	/**
	 * Resolve the reference framing target and transition divisor for one mode.
	 *
	 * @param Body $body Actor contributing direction, velocity, angle, and jumping state.
	 * @param int $surface Quantized floor, left-wall, ceiling, or right-wall orientation.
	 * @return array{float,float,float} Horizontal anchor, vertical anchor, and reference frames.
	 */
	private function preset(Body $body, int $surface): array
	{
		return match($this->mode)
		{
			'panning' => [.5, .85, 8.0],
			'boost-ring' => $this->boostRingPreset($body),
			'cross-cannon' => [.5, .4, 18.0],
			'cross-cannon-quick' => [.5, .4, 5.0],

			'cutScene' => [[.5, .25, .5, .75][$surface], [.75, .5, .25, .5][$surface], 15.0],
			'corkscrew' => [.5, .75, 64.0],

			'normal' => [[.5, .45, .5, .55][$surface], .5, $surface === 0 ? 9.0 : 12.0],
			'walker' => $this->walkerPreset($body, $surface),
			'perspective' => [[.5, .6, .5, .4][$surface], .5, 12.0],
			'climbing' => [[.5, .33, .5, .66][$surface], .5, 24.0],

			'airplane' => [.5 - max(abs($body->vx) / 6000, .45) * self::sign($body->vx), .5, 20.0],
			'aerial' => $this->aerialPreset($body),

			'popping' => [.5, .25, 6.0],
			'hooked' => [.5, .6, 25.0],
			'tube' => [.5, .5, 24.0],
			'cinematic' => [.5, .5, 0.0],

			'cliff' => [.5 - .1 * $body->facing, .3, 9.0],
			'bridge' => [.5, .35, 9.0],
			'boss' => [.5, $this->bossAnchorY, 24.0],

			'draggable' => [.5, .5, 3.0],
			'locked' => [.5, .25, 16.0]
		};
	}

	/**
	 * Frame a jump along the actor's active gravity axis.
	 *
	 * Rising jumps leave more viewport space opposite gravity; falls leave more space in the
	 * gravity direction. Wall gravity therefore changes only the horizontal anchor, while ceiling
	 * gravity mirrors the ordinary floor-mode vertical framing.
	 *
	 * @param Body $body Airborne actor supplying gravity-relative velocity and jump state.
	 * @return array{float,float,float} Horizontal anchor, vertical anchor, and reference frames.
	 */
	private function aerialPreset(Body $body): array
	{
		if(!$body->jumping)
		{
			return [.5, .5, 24.0];
		}

		$offset = $body->gravityVelocity() < 0 ? .25 : -.15;

		return [
			.5 + $body->gravityX * $offset
			, .5 + $body->gravityY * $offset
			, 24.0
		];
	}

	/**
	 * Frame boost-ring movement from the actor's launch angle.
	 *
	 * @param Body $body Actor supplying the launch angle.
	 * @return array{float,float,float} Horizontal anchor, vertical anchor, and reference frames.
	 */
	private function boostRingPreset(Body $body): array
	{
		if(abs($body->angle) < .1 || abs(abs($body->angle) - M_PI) < .1)
		{
			return [.5, .5, 24.0];
		}

		return [.5 - .5 * cos($body->angle), .5 - .2 * sin($body->angle), 16.0];
	}

	/**
	 * Frame walker movement ahead of its facing direction while retaining wall presets.
	 *
	 * @param Body $body Actor supplying facing and grounded state.
	 * @param int $surface Quantized support orientation.
	 * @return array{float,float,float} Horizontal anchor, vertical anchor, and reference frames.
	 */
	private function walkerPreset(Body $body, int $surface): array
	{
		$airborne = !$body->grounded;
		$x = .5 - .35 * $body->facing * ($airborne ? .65 : 1);
		$y = .5 + ($airborne ? -.15 : 0);

		return [[$x, .45, .5, .55][$surface], [$y, .5, .5, .5][$surface], 16.0];
	}

	/**
	 * Bias selected modes toward high-speed travel along the active surface.
	 *
	 * @param Body $body Actor supplying ground or air speed.
	 * @param int $surface Quantized support orientation.
	 * @param float $x Horizontal anchor before look-ahead.
	 * @param float $y Vertical anchor before look-ahead.
	 * @return array{float,float} Anchors after directional look-ahead.
	 */
	private function applySpeedBias(Body $body, int $surface, float $x, float $y): array
	{
		if(!in_array(
			$this->mode
			, ['normal', 'perspective', 'bridge', 'cliff', 'aerial', 'tube', 'hooked', 'cutScene', 'corkscrew']
			, true
		)){
			return [$x, $y];
		}

		$speed = $body->grounded ? $body->groundSpeed : $body->tangentVelocity();
		$bias = -self::sign($speed) * min(abs($speed) / 1020, 1) * .4;

		return match($surface)
		{
			0 => [$x + $bias, $y],
			1 => [$x, $y + $bias],
			2 => [$x - $bias, $y],
			3 => [$x, $y - $bias]
		};
	}

	/**
	 * Convert a held vertical input into delayed up or down framing.
	 *
	 * @param float $look Signed input from minus one for up through one for down.
	 * @param float $dt Elapsed simulation time in seconds.
	 * @return float Vertical anchor adjustment matching the reference character behavior.
	 */
	private function lookBias(float $look, float $dt): float
	{
		$direction = abs($look) < .5 ? 0 : ($look > 0 ? 1 : -1);

		if($direction === 0)
		{
			$this->lookDirection = 0;
			$this->lookTime = 0;

			return 0;
		}

		if($direction !== $this->lookDirection)
		{
			$this->lookDirection = $direction;
			$this->lookTime = 0;
		}

		$this->lookTime += $dt;

		if($this->lookTime < .75)
		{
			return 0;
		}

		return $direction > 0 ? -.5 : .25;
	}

	/**
	 * Clamp a destination through world-span bounds and direct camera-coordinate limits.
	 *
	 * @param Camera $camera Viewport supplying visible world dimensions.
	 * @param float $x Unconstrained destination X coordinate.
	 * @param float $y Unconstrained destination Y coordinate.
	 * @return array{float,float} Constrained top-left destination coordinates.
	 */
	private function constrain(Camera $camera, float $x, float $y): array
	{
		if($this->worldBounds !== null)
		{
			$width = $camera->width / $camera->zoom;
			$minimum = $this->worldBounds['left'];
			$maximum = $this->worldBounds['right'] - $width;
			$x = $minimum <= $maximum
				? max($minimum, min($maximum, $x))
				: $minimum + ($this->worldBounds['right'] - $minimum - $width) / 2;

			if($this->worldBounds['top'] !== null)
			{
				$height = $camera->height / $camera->zoom;
				$minimum = $this->worldBounds['top'];
				$maximum = $this->worldBounds['bottom'] - $height;
				$y = $minimum <= $maximum
					? max($minimum, min($maximum, $y))
					: $minimum + ($this->worldBounds['bottom'] - $minimum - $height) / 2;
			}
		}

		$x = $this->cameraLimits['minX'] === null ? $x : max($this->cameraLimits['minX'], $x);
		$x = $this->cameraLimits['maxX'] === null ? $x : min($this->cameraLimits['maxX'], $x);
		$y = $this->cameraLimits['minY'] === null ? $y : max($this->cameraLimits['minY'], $y);
		$y = $this->cameraLimits['maxY'] === null ? $y : min($this->cameraLimits['maxY'], $y);

		return [$x, $y];
	}

	/**
	 * Quantize a support angle into floor, left-wall, ceiling, or right-wall orientation.
	 *
	 * @param float $angle Body support angle in radians.
	 * @return int Surface index from zero through three.
	 */
	private static function surface(float $angle): int
	{
		return ((int)floor(($angle + M_PI / 4) / (M_PI / 2)) % 4 + 4) % 4;
	}

	/**
	 * Convert reference frames into an exponential response rate at sixty frames per second.
	 *
	 * @param float $frames Frame divisor used by the reference camera.
	 * @return float Per-second stiffness with matching sixty-Hz first-step response.
	 */
	private static function response(float $frames): float
	{
		return $frames <= 0 ? INF : -60 * log(1 - 1 / $frames);
	}

	/**
	 * Resolve a frame-rate-independent blend from a reference frame divisor.
	 *
	 * @param float $frames Frame divisor used by the reference anchor transition.
	 * @param float $dt Elapsed simulation time in seconds.
	 * @return float Blend from zero through one for the elapsed interval.
	 */
	private static function frameBlend(float $frames, float $dt): float
	{
		return $frames <= 0 ? 1 : 1 - pow(1 - 1 / $frames, $dt * 60);
	}

	/**
	 * Return a stable numeric sign without treating zero as positive.
	 *
	 * @param float $value Numeric value to classify.
	 * @return int Minus one, zero, or one.
	 */
	private static function sign(float $value): int
	{
		return $value <=> 0;
	}

	/**
	 * List accepted automatic and concrete camera modes.
	 *
	 * @return list<string> Stable public mode names accepted by setMode and focus.
	 */
	public static function modes(): array
	{
		return [
			'auto'
			, 'normal'
			, 'aerial'
			, 'climbing'
			, 'tube'

			, 'panning'
			, 'boss'
			, 'perspective'
			, 'bridge'
			, 'cliff'
			, 'walker'
			, 'airplane'

			, 'boost-ring'
			, 'cross-cannon'
			, 'cross-cannon-quick'
			, 'cutScene'
			, 'corkscrew'
			, 'popping'
			, 'hooked'
			, 'cinematic'

			, 'draggable'
			, 'locked'
		];
	}
}
