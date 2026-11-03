<?php
declare(strict_types=1);
namespace PhpNoid\System;

use Phonics\Ecs\Registry;
use PhpNoid\Playfield;

/** Simulation-clock fades with a covered asynchronous map swap and a level title hold. */
final class TransitionSystem
{
	public const FADE_OUT = .6;
	public const TITLE_HOLD = .6;
	public const FADE_IN = .45;

	/**
	 * Share the campaign lifecycle with the presentation system.
	 * @param Playfield $field Current game and its stage loader.
	 * @return void Creates the independently scheduled transition system.
	 */
	public function __construct(private Playfield $field) {}

	/**
	 * Advance only transition phases; all gameplay systems remain frozen until playing.
	 * @param Registry $registry Session registry, retained by the other gameplay systems.
	 * @param float $dt Elapsed simulation seconds; the engine stops this clock while paused.
	 * @return void Covers a single map load and resumes after its title and reveal.
	 */
	public function update(Registry $registry, float $dt): void
	{
		$state = $this->field->state;

		if(!in_array($state->phase, ['clearing', 'entering'], true)) return;

		$this->field->pointer->reset();
		$state->transitionTime += $dt;
		$clearing = $state->phase === 'clearing';
		$progress = $clearing
			? $state->transitionTime / self::FADE_OUT
			: ($state->transitionTime - self::TITLE_HOLD) / self::FADE_IN;

		$progress = max(0, min(1, $progress));
		$eased = $progress * $progress * (3 - 2 * $progress);
		$state->fade = $clearing ? $eased : 1 - $eased;

		if($progress < 1) return;

		$state->transitionTime = 0;

		if(!$clearing)
		{
			$state->phase = 'playing';

			return;
		}

		if($state->nextStage >= $state->stageCount)
		{
			$state->phase = 'complete';
			$state->nextStage = null;

			return;
		}

		$state->phase = 'loading';
		($this->field->loadStage)($state->nextStage);
	}
}
