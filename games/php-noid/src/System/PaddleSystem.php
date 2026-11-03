<?php
declare(strict_types=1);
namespace PhpNoid\System;
use PhpNoid\Playfield;
use PhpNoid\Component\Paddle;
use PhpNoid\Component\Projectile;
use Phonics\Ecs\Registry;

/** Applies player movement, timed powers, and paddle-mounted weapon fire. */
final class PaddleSystem
{

	/**
	 * Initialize PaddleSystem state and its owned services.
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
		$axis = $this->field->input->axis('left', 'right');

		if($axis) $this->field->pointer->x = null;

		$target = $this->field->pointer->x ?? $paddle->x + $axis * $paddle->speed * $dt;

		$paddle->invisible = max(0, $paddle->invisible - $dt);
		$paddle->catchFlash = max(0, $paddle->catchFlash - $dt);
		$paddle->respawnProtection = max(0, $paddle->respawnProtection - $dt);
		$paddle->width = $paddle->baseWidth;

		foreach($paddle->powerFlashes as $kind => $remaining)
		{
			if($remaining <= $dt) unset($paddle->powerFlashes[$kind]);
			else $paddle->powerFlashes[$kind] = $remaining - $dt;
		}

		foreach($paddle->powers as $kind => $remaining)
		{
			if($remaining <= $dt) unset($paddle->powers[$kind]);
			else
			{
				$paddle->powers[$kind] = $remaining - $dt;
				$paddle->width = $this->field->capsules[$kind]['width'] ?? $paddle->width;
			}
		}

		$paddle->x = max($paddle->width / 2 + 8, min($this->field->state->width - $paddle->width / 2 - 8, $target));

		$paddle->cooldown -= $dt;

		$weapon = isset($paddle->powers['laser']) ? 'laser' : (isset($paddle->powers['missile']) ? 'missile' : '');

		if($weapon !== '' && $this->field->firing()
			&& $paddle->cooldown <= 0
		){
			$x = $weapon === 'missile' ? $paddle->x : $paddle->x - $paddle->width / 2 + 8;
			$registry->create(new Projectile($x, $paddle->y - 10, $weapon));

			if($weapon === 'laser')
			$registry->create(new Projectile($paddle->x + $paddle->width / 2 - 8, $paddle->y - 10));

			$paddle->cooldown = $weapon === 'missile' ? .8 : .2;
			$this->field->state->events[] = 'shoot';
		}
	}
}
