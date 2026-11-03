<?php
declare(strict_types=1);
require dirname(__DIR__, 3) . '/autoload.php';
require dirname(__DIR__) . '/autoload.php';
use Phonics\Package\Context;
use PhpTris\
{
	Module,MatchState,Random,Rules,Shapes
};
use PhpTris\Component\
{
	Board,Input,Queue
};
$checks = 0;

function check(bool $value, string $message): void
{
	global $checks;
	$checks++;

	if(!$value) throw new RuntimeException($message);
}

function matchFor(string $mode = 'endless'): array
{
	$c = new Context;
	Module::systems($c);
	$id = $c->entities->create(...Module::components(['mode' => $mode]));
	$m = MatchState::get($c->entities, $id);
	$m->run->phase = 'playing';
	$m->spawn();

	return [$c,$m];
}

function settle(Context $c, MatchState $m): void
{
	for($i = 0; $i < 3000; $i++)
	{
		$c->systems->update(1 / 60);

		if(in_array($m->run->phase, ['playing','complete','gameover','finished','countdown'], true)) return;
	}

	throw new RuntimeException('Resolution did not terminate');
}

$r = new Random(1);
check($r->next(2147483647) === 16807, 'portable PRNG first value');
check($r->next(2147483647) === 282475249, 'portable PRNG second value');
$q = new Queue;
$seen = [];

for($i = 0; $i < 700; $i++)
{
	$seen[] = $q->take()['shape'];

	if(count($seen) === 7)
	{
		check(count(array_unique($seen)) === 7, 'every bag contains seven shapes');
		$seen = [];
	} }

$q = new Queue('reactor');

for($bag = 0; $bag < 20; $bag++)
{
	$a = [];

	for($i = 0; $i < 10; $i++) $a[] = $q->take()['shape'];
	check(count(array_filter($a, fn($s) => $s === 'A')) === 3, 'three charges per ten-piece bag');
}

foreach(array_keys(Shapes::CELLS) as $shape)
{
	for($rotation = 0; $rotation < 4; $rotation++)
	check(count(Shapes::cells($shape, $rotation)) === ($shape === 'A'?1:4), 'rotations preserve cell count');

	foreach([[0,1],[1,0],[1,2],[2,1],[2,3],[3,2],[3,0],[0,3]] as [$from,$to])
	{
		$forward = Shapes::kicks($shape, $from, $to);
		$reverse = Shapes::kicks($shape, $to, $from);

		foreach($forward as $i => $v) check($reverse[$i] === array_map(fn($x) => -$x, $v), 'kick transitions reverse');
	}
}

[$c,$m] = matchFor();
$first = $m->active->shape;
$next = $m->queue->items[0]['shape'];
$m->hold();
check($m->queue->held['shape'] === $first && $m->active->shape === $next, 'hold stores and advances');
$m->hold();
check($m->active->shape === $next, 'hold once per placement');
$m->active->shape = 'T';
$m->active->colors = [6,6,6,6];
$m->active->y = 38;
$m->active->rotation = 0;
check($m->rotate(1), 'floor kick succeeds');
check($m->board->fits('T', $m->active->rotation, $m->active->x, $m->active->y), 'floor kick leaves valid geometry');
[$c,$m] = matchFor();
$m->active->shape = 'O';
$m->active->colors = [3,3,3,3];
$m->active->y = 38;

for($i = 0; $i < 120; $i++)
{
	check($m->rotate(1), 'unlimited successful resets');

	for($j = 0; $j < 20; $j++) $c->systems->update(1 / 60);
}

check($m->run->placed === 0, 'infinite resets never force a lock');

for($i = 0; $i < 31; $i++) $c->systems->update(1 / 60);
check($m->run->placed === 1, 'release eventually locks');
[$c,$m] = matchFor();
$m->active->shape = 'O';
$m->active->x = -1;
$m->active->y = 38;
$m->active->lock = .4;
check(!$m->move(-1, 0) && $m->active->lock === .4, 'failed movement preserves timer');
$input = new Input;
$input->held = ['cw' => true];
$count = 0;

for($i = 0; $i < 60; $i++) if(isset($input->presses(1 / 60)['cw'])) $count++;
check($count === 10, 'held rotation repeat cadence');
$input = new Input;
$input->held = ['drop' => true];
$count = 0;

for($i = 0; $i < 60; $i++) if(isset($input->presses(1 / 60)['drop'])) $count++;
check($count === 1, 'drop is edge triggered');
$input->clear();
check(!$input->presses(1 / 60), 'held input quarantined');

foreach([1,2,3,4] as $n)
{
	[$c,$m] = matchFor();
	$m->active->shape = '';

	for($y = 40 - $n; $y < 40; $y++) for($x = 0; $x < 10; $x++) $m->board->put($x, $y);
	$m->board->put(0, 20);
	$m->run->phase = 'resolve';
	settle($c, $m);
	check($m->run->lines === $n, 'simultaneous line clear');
	check($m->run->score === [1 => 100,2 => 300,3 => 500,4 => 800][$n], 'clear score table');
	check(isset($m->board->cells[(20 + $n) * 10]), 'standard row compaction');
}

[$c,$m] = matchFor('race');
$m->run->lines = 39;

for($x = 0; $x < 10; $x++) $m->board->put($x, 39);
$m->run->phase = 'resolve';
settle($c, $m);
check($m->run->phase === 'complete', 'race completion');
[$c,$m] = matchFor('time');
$m->run->elapsed = 119.99;
$c->systems->update(1 / 60);
check($m->run->phase === 'finished', 'time limit');
[$c,$m] = matchFor();

foreach(Shapes::cells($m->queue->items[0]['shape']) as [$x,$y]) $m->board->put(3 + $x, 18 + $y);
$m->spawn();
check($m->run->phase === 'gameover', 'blocked spawn');
[$c,$m] = matchFor();
$m->active->y = 15;
$m->lock();
check($m->run->phase === 'gameover', 'hidden lock out');
$b = new Board;
$b->put(0, 30, 0, 'normal', 1);
$b->put(1, 30, 0, 'normal', 1);
$b->put(0, 39, 0, 'normal', 2);

for($i = 0; $i < 20; $i++) Rules::fall($b, 'cascade');
check(isset($b->cells[380], $b->cells[381]), 'connected group rests as a unit');
$b = new Board;
$b->put(0, 30, 0, 'normal', 1);
$b->put(2, 30, 0, 'normal', 1);
$b->put(0, 39, 0, 'normal', 2);

for($i = 0; $i < 20; $i++) Rules::fall($b, 'cascade');
check(isset($b->cells[380], $b->cells[392]), 'severed fragments settle independently');
$b = new Board;

for($y = 30; $y < 35; $y++) for($x = 0; $x < 5; $x++) $b->put($x, $y, 2);
check(Rules::mass($b) === 25 && !$b->cells, 'color critical mass');
$b = new Board;

for($i = 0; $i < 25; $i++) $b->put($i % 5, 30 + intdiv($i, 5), 7, 'garbage');
check(Rules::mass($b) === 0, 'neutral garbage never bonds');
$b = new Board;
$b->put(0, 39, 7, 'core');
$b->put(1, 39, 7, 'charge');
$b->put(2, 39, 7, 'charge');
check(Rules::connect($b) === 2 && Rules::connect($b) === 0, 'connected charges counted once');
[$c,$m] = matchFor('reactor');

for($x = 0; $x < 10; $x++) $m->board->put($x, 39, 7, 'core');
$m->run->phase = 'resolve';
settle($c, $m);
check(count($m->board->cells) === 10 && $m->run->lines === 0, 'persistent row terminates without scoring repeatedly');
$b = new Board;

for($j = 0; $j < 4; $j++)
foreach(Shapes::cells('O') as [$x,$y])
$b->put(($j % 2) * 2 + $x, 34 + intdiv($j, 2) * 2 + $y, 3, 'normal', $j + 1, 'O');
Rules::squares($b);
check(count(array_filter($b->cells, fn($c) => $c['bonus'] === 10)) === 16, 'four whole identical pieces form square');
$before = $b->serial;
Rules::squares($b);
check($b->serial === $before, 'square cannot be claimed twice');
$catalog = [];
$world = json_decode(file_get_contents(dirname(__DIR__) . '/worlds/arcade.world'), true);
check(count($world['maps']) === 78, '78 authored entries');

foreach($world['maps'] as $entry)
{
	$data = json_decode(file_get_contents(dirname(__DIR__) . '/worlds/' . $entry['fileName']), true);
	$p = array_column($data['properties'], 'value', 'name');
	$catalog[$p['mode']][$p['rank']] = $data;
	check(count($data['layers'][0]['data']) === 200, 'finite cell layer');
}

foreach(Module::MODES as $mode => $label)
{
	[$c,$m] = matchFor($mode);
	$m->run->catalog = $catalog;
	$m->stage($catalog[$mode][1]);
	check($m->run->phase === 'countdown', 'stage entry transition');

	if(!$m->run->challenge()) continue;

	for($rank = 1; $rank <= 15; $rank++)
	{
		$m->run->level = $rank;
		$m->run->goal = $m->run->target();
		$m->run->phase = 'settled';
		$c->systems->update(1 / 60);
		check($m->run->phase === ($rank === 15?'complete':'countdown'), 'all challenge ranks advance');
	}

	$c->close();
	check(!$c->systems->has(PhpTris\System\MovementSystem::class), 'system cleanup');
}

try
{
	Module::components(['mode' => 'bad']);
	check(false, 'invalid mode rejected');
} catch(InvalidArgumentException $e)
{
	check(true, 'invalid mode rejected');
}

// Score consecutive placements independently from the falling-piece controls.
[$c, $m] = matchFor();

for($placement = 0; $placement < 2; $placement++)
{
	$m->board->cells = [];
	$m->board->put(0, 20);

	for($y = 36; $y < 40; $y++) for($x = 0; $x < 10; $x++) $m->board->put($x, $y);
	$m->active->shape = '';
	$m->run->phase = 'resolve';
	$m->run->wave = $m->run->lastClear = 0;
	settle($c, $m);
}

check($m->run->score === 2050, 'difficult clear streak and second-placement combo');
check($m->run->combo === 1 && $m->run->backToBack, 'streak state persists across scoring placements');
$m->run->phase = 'resolve';
$m->run->lastClear = 0;
settle($c, $m);

check(
	$m->run->combo === -1 && $m->run->backToBack
	, 'empty placement breaks combo but preserves difficult-clear streak'
);

[$c, $m] = matchFor('targets');

for($x = 0; $x < 10; $x++) $m->board->put($x, 35);
$m->board->put(0, 39);
$m->run->phase = 'resolve';
$m->active->shape = '';
settle($c, $m);
check($m->run->goal === 1, 'fifth row from bottom scores one target unit before falling');

foreach(['mini' => 200, 'full' => 800] as $spin => $points)
{
	[$c, $m] = matchFor();

	for($x = 0; $x < 10; $x++) $m->board->put($x, 39);
	$m->board->put(0, 20);
	$m->run->spin = $spin;
	$m->run->phase = 'resolve';
	$m->active->shape = '';
	settle($c, $m);
	check($m->run->score === $points, 'spin single scoring: ' . $spin);
}

[$c, $m] = matchFor('squares');

for($x = 0; $x < 10; $x++) $m->board->put($x, 39);
$m->board->put(0, 20);
$m->board->cells[200]['square'] = 1;
$m->board->cells[200]['bonus'] = 10;
$m->run->spin = 'full';
$m->run->phase = 'resolve';
$m->active->shape = '';
settle($c, $m);
check($m->run->avalanche && isset($m->board->cells[390]), 'square spin releases individual-cell avalanche');
check($m->board->cells[390]['bonus'] === 0, 'avalanche removes surviving square bonuses');

$input = new Input;
$input->sample(['drop' => true]);
$input->sample(['drop' => false]);
check(isset($input->presses(1 / 60)['drop']), 'touch tap survives frames without a simulation tick');
check(!isset($input->presses(1 / 60)['drop']), 'buffered touch tap is consumed only once');
$input->sample(['hold' => true]);
$input->clear();
check(!isset($input->presses(1 / 60)['hold']), 'page transition discards pending input');
[$c, $m] = matchFor('squares');
$m->run->spin = 'full';
$m->run->phase = 'resolve';
$m->active->shape = '';
settle($c, $m);
check($m->run->goal === 1 && $m->run->score === 400, 'no-line spin contributes one square goal unit');
[$c, $m] = matchFor('reactor');
$m->run->catalog = $catalog;
$m->run->goal = 8;
$m->run->phase = 'settled';
$c->systems->update(1 / 60);
check($m->run->level === 2 && $m->run->goal === 0, 'fresh reactor layouts require a fresh charge goal');
echo "PHPTris: $checks checks passed.\n";
