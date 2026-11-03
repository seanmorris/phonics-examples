<?php
declare(strict_types=1);
namespace PhpNoid\System;
use PhpNoid\Playfield;
use PhpNoid\Component\Capsule;
use PhpNoid\Component\Paddle;
use Phonics\Ecs\Registry;

/** Advances falling power capsules and applies those collected by the paddle. */
final class CapsuleSystem
{

	/**
	 * Initialize CapsuleSystem state and its owned services.
	 *
	 * @param Playfield $field Playfield state shared by this system.
	 * @return void Completes the operation.
	 */
	public function __construct(private Playfield $field) {}

	/**
	 * Advance the current simulation or UI state.
	 *
	 * @param Registry $registry Entity registry to update.
	 * @param float $dt Elapsed simulation time in seconds.
	 * @return void Completes the operation.
	 */
	public function update(Registry $registry, float $dt): void
	{
		if($this->field->state->phase !== 'playing') return;

		$paddle = $registry->get($this->field->paddle, Paddle::class);

		foreach($registry->query(Capsule::class) as $id)
		{
			$capsule = $registry->get($id, Capsule::class);
			$oldY = $capsule->y;
			$capsule->y += $capsule->speed * $dt;

			if(abs($capsule->x - $paddle->x) < $paddle->width / 2 + 8
				&& $oldY <= $paddle->y + 12 && $capsule->y >= $paddle->y - 12
			){
				$this->field->power($capsule->kind);
				$registry->destroy($id);
			}
			elseif($capsule->y > $this->field->state->height + 12) $registry->destroy($id);
		}
	}
}
