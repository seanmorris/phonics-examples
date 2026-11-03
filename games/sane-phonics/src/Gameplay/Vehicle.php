<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use Phonics\Input\Controls;
use Phonics\Ecs\Registry;
use SanePhonics\Physics\Body;

/** One independently simulated vehicle with a surface-relative seat and optional driver behavior. */
final class Vehicle
{
	public int $entity = 0;
	public ?int $occupant = null;
	public bool $enabled = true;
	public string $pose = 'standing';
	public string $cameraMode = 'auto';
	public bool $holdCombo = false, $scoreGrinding = true;
	/** Whether button zero may reach the shared chassis jump solver. */
	public bool $jumpEnabled = true;
	/** Facing-local presentation angle; horizontal mirroring reverses its world-space rotation. */
	public float $visualAngle = 0;
	public ?int $spriteGid = null;
	public Controls $drive;
	public float $previousX, $previousY;

	/**
	 * Define a vehicle around a body whose dimensions describe its chassis.
	 *
	 * @param Body $body Independent movement body, also attached to the vehicle entity.
	 * @param float $seatHeight Seat height above the body's surface-contact point in world pixels.
	 * @param float $seatForward Forward seat offset multiplied by the vehicle's facing direction.
	 * @param float $seatAngle Additional rider rotation in radians.
	 * @param bool $lockedIn Whether voluntary dismounts are forbidden.
	 * @param bool $quickDrop Whether button zero alone dismounts instead of operating the vehicle.
	 * @param int $sourceId Original Tiled object ID, or zero for scripted vehicles.
	 * @return void Creates an unregistered, unoccupied vehicle with released drive controls.
	 */
	public function __construct(
		public Body $body
		, public float $seatHeight = 12
		, public float $seatForward = 0
		, public float $seatAngle = 0
		, public bool $lockedIn = false
		, public bool $quickDrop = false
		, public int $sourceId = 0
	){
		if(!is_finite($seatHeight) || !is_finite($seatForward) || !is_finite($seatAngle)
			|| $seatHeight < 0 || $sourceId < 0
		){
			throw new \InvalidArgumentException('Invalid vehicle seat');
		}

		$this->drive = new Controls;
		$this->previousX = $body->x;
		$this->previousY = $body->y;
	}

	/**
	 * Resolve a live, mutual rider association for systems that award or damage a passenger.
	 *
	 * @param Registry $registry Registry containing the rider and chassis.
	 * @param int $player Character whose optional Rider component is inspected.
	 * @return self|null Occupied vehicle, or null for detached, damaged, or removed associations.
	 */
	public static function forRider(Registry $registry, int $player): ?self
	{
		if(!$registry->has($player, Rider::class) || !$registry->has($player, Body::class)) return null;

		$id = $registry->get($player, Rider::class)->vehicle;

		if($id === null || !$registry->has($id, self::class) || !$registry->has($id, Body::class)) return null;

		$vehicle = $registry->get($id, self::class);

		return $vehicle->occupant === $player && $registry->get($player, Body::class)->ability === 'riding'
			? $vehicle
			: null;
	}

	/**
	 * Resolve the seated rider's collision center in world coordinates.
	 *
	 * @param Body $rider Character body supplying its standing height.
	 * @return array{float,float,float} Center X, center Y, and rider angle in radians.
	 */
	public function seat(Body $rider): array
	{
		$angle = $this->body->angle + $this->visualAngle * $this->body->facing;
		$forward = $this->seatForward * $this->body->facing;
		$rotation = $angle + $this->seatAngle;

		return [
			$this->body->supportX() + cos($angle) * $forward + sin($angle) * $this->seatHeight
				+ sin($rotation) * $rider->standingHeight
			, $this->body->supportY() + sin($angle) * $forward - cos($angle) * $this->seatHeight
				- cos($rotation) * $rider->standingHeight
			, $rotation
		];
	}
}
