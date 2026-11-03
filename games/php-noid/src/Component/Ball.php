<?php
declare(strict_types=1);
namespace PhpNoid\Component;

/** Mutable ECS ball state. */
final class Ball
{
	public float $vx = 180, $vy = -300;
	public bool $attached = true;
	public float $offset = 0;
	public int $solidBounces = 0;
	/** @var list<array{float,float,float,float,float,float}> Bounded contact history for orbit recovery. */
	public array $solidContacts = [];

	/**
	 * Initialize Ball state and its owned services.
	 *
	 * @param float $x World X coordinate in pixels.
	 * @param float $y World Y coordinate in pixels.
	 * @param string $team Player or enemy ownership, used for life and score rules.
	 * @param string $effect Effect applied by an enemy ball.
	 * @return void Completes the operation.
	 */
	public function __construct(
		public float $x = 320
		, public float $y = 430
		, public string $team = 'player'
		, public string $effect = ''
	){
	}
}
