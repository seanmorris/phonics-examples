<?php
declare(strict_types=1);
namespace PhpTris;
use Phonics\Ecs\Registry;
use PhpTris\Component\
{
	Board, Active, Queue, Input, Run
};

/** Short-lived view of independently registered board components. No global game state. */
final class MatchState
{

	/**
	 * Initialize the component and its owned resources.
	 *
	 * @param Board $board Settled board component.
	 * @param Active $active Moving piece component.
	 * @param Queue $queue Bag and hold component.
	 * @param Input $input Logical repeat and edge state.
	 * @param Run $run Match phase and objective state.
	 * @return void Updates owned state without returning a value.
	 */
	public function __construct(
		public Board $board
		, public Active $active
		, public Queue $queue
		, public Input $input
		, public Run $run
	){
	}

	/**
	 * Read a board entity through its separately registered ECS components.
	 *
	 * @param Registry $r Registry containing board entities.
	 * @param int $id Board entity identifier or UI action identifier.
	 * @return self Component view for the requested entity.
	 */
	public static function get(Registry $r, int $id): self
	{
		return new self(
			$r->get($id, Board::class)
			, $r->get($id, Active::class)
			, $r->get($id, Queue::class)
			, $r->get($id, Input::class)
			, $r->get($id, Run::class)
		);
	}

	/**
	 * Reset active-piece motion and detect an obstructed spawn.
	 *
	 * @param ?array $item Explicit reserve piece, or null to draw from the queue.
	 * @return void Updates owned state without returning a value.
	 */
	public function spawn(?array $item = null): void
	{
		$item ??= $this->queue->take();
		$a = $this->active;
		$a->shape = $item['shape'];
		$a->colors = $item['colors'];
		$a->x = 3;
		$a->y = 18;
		$a->rotation = $a->kick = 0;
		$a->fall = $a->lock = 0;
		$a->rotated = false;

		if(!$this->board->fits($a->shape, 0, $a->x, $a->y)) $this->finish('gameover');
		elseif($this->board->fits($a->shape, 0, $a->x, $a->y + 1)) $a->y++;
	}

	/**
	 * Enter a terminal phase and quarantine held inputs.
	 *
	 * @param string $phase Terminal match phase.
	 * @return void Updates owned state without returning a value.
	 */
	public function finish(string $phase): void
	{
		$this->run->phase = $phase;
		$this->run->notice = $phase === 'complete' ? 'COMPLETE' : ($phase === 'finished' ? 'TIME UP' : 'GAME OVER');
		$this->run->events[] = $phase;
		$this->input->clear();
	}

	/**
	 * Translate a fitting piece and reset its lock timer on success.
	 *
	 * @param int $dx Horizontal translation in cells.
	 * @param int $dy Vertical translation in cells.
	 * @return bool Whether the operation or predicate succeeded.
	 */
	public function move(int $dx, int $dy): bool
	{
		$a = $this->active;

		if(!$a->shape || !$this->board->fits($a->shape, $a->rotation, $a->x + $dx, $a->y + $dy)) return false;

		$a->x += $dx;
		$a->y += $dy;
		$a->lock = 0;

		if($dx) $a->rotated = false;

		return true;
	}

	/**
	 * Try ordered rotation kicks and record the accepted offset.
	 *
	 * @param int $direction Rotation direction, positive for clockwise.
	 * @return bool Whether the operation or predicate succeeded.
	 */
	public function rotate(int $direction): bool
	{
		$a = $this->active;
		$to = ($a->rotation + $direction + 4) % 4;

		foreach(Shapes::kicks($a->shape, $a->rotation, $to) as $index => [$dx,$dy])
		{
			if(!$this->board->fits($a->shape, $to, $a->x + $dx, $a->y + $dy)) continue;

			$a->x += $dx;
			$a->y += $dy;
			$a->rotation = $to;
			$a->lock = 0;
			$a->rotated = true;
			$a->kick = $index;
			$this->run->events[] = 'rotate';

			return true;
		}

		return false;
	}

	/**
	 * Find the active piece landing row without changing the board.
	 *
	 * @return int Computed count, position, or deterministic value.
	 */
	public function ghost(): int
	{
		$a = $this->active;
		$y = $a->y;

		if(!$a->shape) return $y;

		while($this->board->fits($a->shape, $a->rotation, $a->x, $y + 1)) $y++;

		return $y;
	}

	/**
	 * Exchange the active piece with the once-per-placement reserve.
	 *
	 * @return void Updates owned state without returning a value.
	 */
	public function hold(): void
	{
		if($this->queue->usedHold) return;

		$item = ['shape' => $this->active->shape,'colors' => $this->active->colors];
		$held = $this->queue->held;
		$this->queue->held = $item;
		$this->queue->usedHold = true;
		$this->spawn($held);
		$this->run->events[] = 'hold';
	}

	/**
	 * Classify a rotated T piece using occupied corners and its final kick.
	 *
	 * @return string Classification identifier.
	 */
	public function spin(): string
	{
		$a = $this->active;

		if($a->shape !== 'T' || !$a->rotated) return '';

		$corners = [];

		foreach([[0,0],[2,0],[2,2],[0,2]] as [$x,$y]) $corners[] = $this->board->occupied($a->x + $x, $a->y + $y);

		if(count(array_filter($corners)) < 3) return '';

		$front = [[0,1],[1,2],[2,3],[3,0]][$a->rotation];

		return ($corners[$front[0]] && $corners[$front[1]]) || $a->kick === 4 ? 'full' : 'mini';
	}

	/**
	 * Commit the active piece and begin bounded board resolution.
	 *
	 * @return void Updates owned state without returning a value.
	 */
	public function lock(): void
	{
		$a = $this->active;
		$s = $this->run;
		$b = $this->board;
		$cells = Shapes::cells($a->shape, $a->rotation);

		if(max(array_column($cells, 1)) + $a->y < 20)
		{
			$this->finish('gameover');

			return;
		}

		$s->spin = $this->spin();
		$group = ++$b->serial;

		foreach($cells as $i => [$dx,$dy])
		$b->put($a->x + $dx, $a->y + $dy, $a->colors[$i], $a->shape === 'A' ? 'charge' : 'normal', $group, $a->shape);
		$a->shape = '';
		$s->placed++;
		$s->wave = 0;
		$s->lastClear = 0;
		$s->avalanche = false;
		$s->cascade = in_array($s->mode, ['bonds','targets'], true);
		$s->phase = 'resolve';
		$s->delay = 0;
		$s->events[] = 'lock';

		if($s->mode === 'squares') Rules::squares($b);
	}

	/**
	 * Apply Tiled rank settings and optionally replace the settled board.
	 *
	 * @param array $data Decoded Tiled map resource.
	 * @param bool $preserve Whether settled cells survive the rank transition.
	 * @return void Updates owned state without returning a value.
	 */
	public function stage(array $data, bool $preserve = false): void
	{
		$p = [];

		foreach($data['properties'] ?? [] as $prop) $p[$prop['name']] = $prop['value'];
		$s = $this->run;
		$s->level = (int)($p['rank'] ?? $s->level);
		$s->lineTarget = (int)($p['lineTarget'] ?? 40);
		$s->duration = (int)($p['duration'] ?? 120);
		$s->ghost = (bool)($p['ghost'] ?? true);

		if(!$preserve)
		{
			$this->board->cells = [];
			$this->board->revision++;

			foreach($data['layers'] ?? [] as $layer)
			{
				if(($layer['type'] ?? '') !== 'tilelayer' || $layer['name'] !== 'Initial cells') continue;

				foreach($layer['data'] as $i => $gid)
				{
					if(!$gid) continue;

					$kind = [8 => 'garbage',9 => 'goal',10 => 'core',11 => 'charge'][$gid] ?? 'normal';
					$this->board->put($i % 10, 20 + intdiv($i, 10), min(7, $gid - 1), $kind);
				}
			}
		}

		$this->active->shape = '';
		$this->queue->usedHold = false;
		$this->input->clear();
		$s->phase = 'countdown';
		$s->delay = .75;
		$s->notice = 'RANK ' . $s->level;
	}
}
