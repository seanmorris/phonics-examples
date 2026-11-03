<?php
declare(strict_types=1);
namespace PhpTris\System;
use Phonics\Ecs\Registry;
use PhpTris\Component\Board;
use PhpTris\MatchState;

/** Gravity and locking use simulation seconds, including during repeat input. */
final class GravitySystem
{

	/**
	 * Advance gravity and grounded lock delay.
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
			$a = $m->active;

			if($s->phase !== 'playing') continue;

			$level = min(19, $s->level);
			$interval = max(1 / 1200, pow(.8 - .007 * ($level - 1), $level - 1));
			$soft = !empty($m->input->held['soft']) && empty($m->input->blocked['soft']);

			if($soft) $interval = max(1 / 1200, $interval / 20);

			$a->fall += $dt;

			for($i = 0; $i < 20 && $a->fall + 1e-9 >= $interval; $i++)
			{
				$a->fall -= $interval;

				if(!$m->move(0, 1))
				{
					$a->fall = 0;
					break;
				}

				if($soft) $s->score++;
			}

			if(!$m->board->fits($a->shape, $a->rotation, $a->x, $a->y + 1))
			{
				$a->lock += $dt;

				if($a->lock + 1e-9 >= .5) $m->lock();
			}
		}
	}
}
