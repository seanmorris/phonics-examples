<?php
declare(strict_types=1);
namespace PhpTris\System;
use Phonics\Ecs\Registry;
use PhpTris\Component\Board;
use PhpTris\MatchState;

/** Consume one input edge per fixed tick; successful movement may reset locking indefinitely. */
final class MovementSystem
{

	/**
	 * Apply logical input actions to active pieces.
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

			if($s->phase !== 'playing') continue;

			$p = $m->input->presses($dt);

			if(isset($p['hold'])) $m->hold();

			if($s->phase !== 'playing') continue;

			if(isset($p['left']) && empty($m->input->held['right'])) $m->move(-1, 0);

			if(isset($p['right']) && empty($m->input->held['left'])) $m->move(1, 0);

			if(isset($p['cw'])) $m->rotate(1);
			elseif(isset($p['ccw'])) $m->rotate(-1);

			if(isset($p['drop']))
			{
				$y = $m->ghost();
				$s->score += 2 * ($y - $m->active->y);
				$m->active->y = $y;
				$m->lock();
			}
		}
	}
}
