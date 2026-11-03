<?php
declare(strict_types=1);
namespace PhpTris\System;
use Phonics\Ecs\Registry;
use PhpTris\Component\Board;
use PhpTris\MatchState;

/** Progress only after a stable board; phase clocks are frozen by the engine's pause. */
final class ProgressSystem
{

	/**
	 * Advance timers and rank transitions only after resolution.
	 *
	 * @param Registry $r Registry containing board entities.
	 * @param float $dt Fixed simulation step in seconds.
	 * @return void Updates owned state without returning a value.
	 */
	public function update(Registry $r, float $dt): void
	{
		foreach($r->query(Board::class) as $id)
		{
			$m = MatchState::get($r, $id);
			$s = $m->run;

			if($s->phase === 'countdown')
			{
				$s->delay -= $dt;

				if($s->delay <= 0)
				{
					$s->phase = 'playing';
					$m->spawn();
				}

				continue;
			}

			if(in_array($s->phase, ['playing','resolve','flash','fall','settled'], true))
			{
				$s->elapsed += $dt;

				if($s->mode === 'time' && $s->elapsed >= $s->duration)
				{
					$s->expired = true;
					$s->elapsed = $s->duration;

					if($s->phase === 'playing')
					{
						$m->finish('finished');
						continue;
					}
				}
			}

			if($s->phase !== 'settled') continue;

			if($s->expired)
			{
				$m->finish('finished');
				continue;
			}

			if($s->mode === 'race' && $s->lines >= $s->lineTarget)
			{
				$m->finish('complete');
				continue;
			}

			if($s->challenge() && $s->goal >= $s->target())
			{
				if($s->level >= 15)
				{
					$m->finish('complete');
					continue;
				}

				$preserve = !in_array($s->mode, ['bonds','reactor'], true);
				$s->goal = $preserve ? $s->goal - $s->target() : 0;
				$s->level++;
				$data = $s->catalog[$s->mode][$s->level] ?? null;

				if(!$data) throw new \RuntimeException('Missing PHPTris rank ' . $s->mode . ':' . $s->level);

				$m->stage($data, $preserve);
				$s->events[] = 'rank';
				continue;
			}

			if($s->mode === 'endless') $s->level = 1 + intdiv($s->lines, 10);

			$s->phase = 'playing';
			$m->queue->usedHold = false;
			$m->spawn();
		}
	}
}
