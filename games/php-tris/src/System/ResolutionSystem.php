<?php
declare(strict_types=1);
namespace PhpTris\System;
use Phonics\Ecs\Registry;
use PhpTris\Component\Board;
use PhpTris\
{
	MatchState,Rules
};

/** Bounded grid phases: flash, clear, settle, and repeat. Persistent rows never loop. */
final class ResolutionSystem
{

	/**
	 * Advance one timed clear or fall phase per board.
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
			$b = $m->board;

			if(!in_array($s->phase, ['resolve','flash','fall'], true)) continue;

			$s->delay -= $dt;

			if($s->delay > 1e-9) continue;

			$s->motions = [];

			if($s->phase === 'flash')
			{
				$this->clear($m);
				$s->phase = 'fall';
			}

			if($s->mode === 'reactor') $s->goal += Rules::connect($b);

			if($s->mode === 'bonds' && ($mass = Rules::mass($b)))
			{
				$s->score += $mass * 10 * $s->level;
				$s->events[] = 'mass';
				$s->delay = .12;
				$s->phase = 'resolve';
				continue;
			}

			if($s->phase === 'resolve' && ($s->rows = Rules::rows($b)))
			{
				$s->phase = 'flash';
				$s->delay = .12;
				continue;
			}

			if($s->cascade || $s->avalanche)
			{
				$motions = Rules::fall($b, $s->mode, $s->avalanche);

				if($motions)
				{
					$s->motions = $motions;
					$s->phase = 'fall';
					$s->delay = .04;
					continue;
				}
			}

			$s->rows = Rules::rows($b);

			if($s->rows)
			{
				$s->phase = 'flash';
				$s->delay = .12;
				continue;
			}

			if($s->spin && !$s->lastClear)
			{
				$s->score += ($s->spin === 'mini'?100:400) * $s->level;

				if($s->mode === 'squares') $s->goal++;
			}

			if(!$s->lastClear) $s->combo = -1;

			if(!$b->cells && $s->lastClear) $s->score += 2000 * $s->level;

			if($s->mode === 'bonds')
			{
				$hasGoal = false;

				foreach($b->cells as $cell) if($cell['kind'] === 'goal') $hasGoal = true;

				if(!$hasGoal) $s->goal = 1;
			}

			$s->phase = 'settled';
		}
	}

	/**
	 * Score and remove a clear wave, preserving reactor atoms.
	 *
	 * @param MatchState $m Current board entity component view.
	 * @return void Updates owned state without returning a value.
	 */
	private function clear(MatchState $m): void
	{
		$s = $m->run;
		$b = $m->board;
		$n = count($s->rows);
		$s->wave++;
		$base = [0,100,300,500,800][min(4, $n)];
		$spin = $s->wave === 1?$s->spin:'';

		if($spin === 'full') $base = [400,800,1200,1600][min(3, $n)];

		if($spin === 'mini') $base = [100,200,400][min(2, $n)];

		$difficult = $n >= 4 || $spin !== '';

		if($difficult && $s->backToBack) $base = (int)($base * 1.5);

		$s->backToBack = $difficult;

		if($s->wave === 1) $s->combo++;

		$s->score += ($base + 50 * max(0, $s->combo) + 100 * ($s->wave - 1)) * $s->level;
		$s->lines += $n;
		$s->lastClear += $n;
		$units = [0,1,3,5,8][min(4, $n)];

		if($spin) $units = [1,3,7,6][min(3, $n)];

		if($s->mode === 'cascade') $units = 2 * ($n + min(20, $s->wave)) - 3;

		if($s->mode === 'targets')
		{
			$units = 0;

			foreach($s->rows as $y) $units += [5 => 1,10 => 2,14 => 3,17 => 4,19 => 5,20 => 6][40 - $y] ?? 0;
		}

		$marked = array_fill_keys($s->rows, true);
		$squareRows = [];
		$atom = false;

		foreach($b->cells as $key => $c)
		{
			$y = intdiv($key, 10);

			if(!isset($marked[$y])) continue;

			if($c['square']) $squareRows[$c['square'] . ':' . $y] = $c['bonus'];

			if(in_array($c['kind'], ['core','charge'], true))
			{
				$atom = true;
				continue;
			}

			unset($b->cells[$key]);
		}

		if($s->mode === 'squares') $units += array_sum($squareRows);

		if(in_array($s->mode, ['squares','cascade','targets'], true)) $s->goal += $units;

		if($s->mode === 'squares' && $spin)
		{
			$s->avalanche = true;

			foreach($b->cells as &$c)
			{
				$c['square'] = $c['bonus'] = 0;
			}

			unset($c);
		}

		if(($s->mode === 'reactor' && $atom) || in_array($s->mode, ['cascade','targets','bonds'], true))
		$s->cascade = true;

		if(!$s->cascade && !$s->avalanche)
		{
			$cells = [];

			foreach($b->cells as $key => $c)
			{
				$y = intdiv($key, 10);
				$shift = 0;

				foreach($s->rows as $row) if($row > $y) $shift++;
				$cells[$key + $shift * 10] = $c;
			}

			$b->cells = $cells;
		}

		$b->revision++;
		$s->rows = [];
		$s->events[] = 'clear';
		$s->notice = $s->wave > 1?'CHAIN ' . $s->wave:($spin?'SPIN':$n . ' LINES');
	}
}
