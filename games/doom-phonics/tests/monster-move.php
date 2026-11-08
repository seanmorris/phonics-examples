<?php
declare(strict_types=1);

use DoomPhonics\Adapter\WadSource;
use DoomPhonics\Archive\Archive;
use DoomPhonics\Archive\Stack;
use DoomPhonics\Component\Body;
use DoomPhonics\Component\MonsterBrain;
use DoomPhonics\Component\PlayerStart;
use DoomPhonics\Component\SectorMover;
use DoomPhonics\Component\Transform;
use DoomPhonics\Game\GameSettings;
use DoomPhonics\Game\MonsterMove;
use DoomPhonics\System\CombatSystem;
use DoomPhonics\System\EpisodeSystems;
use Phonics\Ecs\Registry;
use Phonics\Ecs\Scheduler;
use Phonics\Input\Controls;
use Phonics\World\ScopeContext;

// Monsters move as Doom's do (P_Move, P_TryMove, P_NewChaseDir): a step is one position test at the destination,
// a blocked step turns the monster instead of sliding it, and the doors it bumps open for it.
(static function(): void {
	$source = new WadSource(new Stack(new Archive(file_get_contents(dirname(__DIR__) . '/demo/DOOM1.WAD'), 'DOOM1.WAD')));
	$registry = new Registry;
	$scheduler = new Scheduler($registry);
	$descriptor = null;

	foreach($source->catalog() as $candidate) if($candidate->id === 'E1M3') $descriptor = $candidate;

	$scope = new ScopeContext('episode', $descriptor, $registry, $scheduler, services: ['controls' => new Controls, 'doom-settings' => new GameSettings]);
	$scope->setContent($source->activate('E1M3', $scope)->content());
	(new EpisodeSystems)->activate($scope);
	$level = $scope->content()->level;
	$map = $level->map;
	$combat = null;

	foreach((new ReflectionProperty($scheduler, 'systems'))->getValue($scheduler) as [$callable])
	if(is_array($callable) && $callable[0] instanceof CombatSystem) $combat = $callable[0];

	$obstacles = $combat->collision->actors;
	$specials = $combat->specials;
	$build = static fn(): MonsterMove => new MonsterMove(
		$level
		, $obstacles
		, static fn(int $lineId, int $sector, Transform $pose): bool => $specials->monsterUse($lineId, $sector, $pose)
		, 24
	);
	$mover = $build();
	$tick = static function(int $count) use ($scheduler): void { for(; $count > 0; $count--) $scheduler->update(1 / 35); };
	$tick(5);

	foreach($registry->query(PlayerStart::class) as $player) break;

	// The monster with the most room around it.
	$roomiest = null;

	foreach($registry->query(MonsterBrain::class) as $entity)
	{
		$pose = $registry->get($entity, Transform::class);
		$radius = $registry->get($entity, Body::class)->radius + 24;
		$lines = count($level->spatial->near($pose->x - $radius, $pose->y - $radius, $pose->x + $radius, $pose->y + $radius));

		if($roomiest === null || $lines < $roomiest[1]) $roomiest = [$entity, $lines];
	}

	[$monster] = $roomiest;
	$pose = $registry->get($monster, Transform::class);
	$body = $registry->get($monster, Body::class);
	$brain = $registry->get($monster, MonsterBrain::class);
	$home = [$pose->x, $pose->y, $pose->z];
	$sector = $level->sectorAt($pose->x, $pose->y);

	verifyWad($mover->available(), 'E1M3 has the BSP Doom\'s monster movement needs');
	verifyWad($mover->sectorIndexAt($pose->x, $pose->y) === $map->sectorAt($pose->x, $pose->y), 'the flattened BSP finds the sector the map does');
	verifyWad($mover->tryMove($monster, $pose, $body, $pose->x, $pose->y, false) && $mover->floor === $pose->z, 'a monster may stand where it stands, on its floor');

	// A one-sided wall: the destination box straddling it is refused.
	$wall = null;

	foreach($map->linedefs as $line) if($line['left'] < 0 && (int)$map->sidedefs[$line['right']]['sector'] === $sector->index) { $wall = $line; break; }

	$a = $map->vertices[$wall['from']];
	$b = $map->vertices[$wall['to']];
	verifyWad(!$mover->tryMove($monster, $pose, $body, ($a['x'] + $b['x']) / 2, ($a['y'] + $b['y']) / 2, false), 'a step whose box straddles a one-sided wall is blocked');

	// A two-sided line between level floors, tested from its midpoint: step, drop-off, and monster-blocking rules.
	// Both ceilings count at a destination on the line, so the pair must leave head-room over a 24-unit step.
	$portal = null;

	foreach($map->linedefs as $line)
	{
		if($line['left'] < 0 || !($line['flags'] & 4) || ($line['flags'] & 3)) continue;

		$sides = [(int)$map->sidedefs[$line['right']]['sector'], (int)$map->sidedefs[$line['left']]['sector']];

		if($sides[0] === $sides[1]) continue;

		[$near, $other] = [$level->sectors[$sides[0]], $level->sectors[$sides[1]]];

		if($other->floor === $near->floor && min($other->ceiling, $near->ceiling) - $other->floor >= $body->height + 25) { $portal = [$line, $other, $near]; break; }
	}

	verifyWad($portal !== null, 'E1M3 has a two-sided line between level floors with head-room for a step');
	[$line, $other, $near] = $portal;
	$pose->z = $near->floor;
	$a = $map->vertices[$line['from']];
	$b = $map->vertices[$line['to']];
	$midX = ($a['x'] + $b['x']) / 2;
	$midY = ($a['y'] + $b['y']) / 2;
	$mid = static fn() => $mover->tryMove($monster, $pose, $body, $midX, $midY, false);
	$cleared = $mid();
	$other->floor += 24;
	verifyWad($cleared && $mid() && $mover->floor === $other->floor, 'a step up to 24 units is taken and ends on the higher floor');
	$other->floor += 1;
	verifyWad(!$mid() && $mover->floatOk, 'a step of 25 units is blocked, though a floater could fit');
	$other->floor -= 50;
	verifyWad(!$mid(), 'a monster stops at a ledge deeper than 24 units');
	verifyWad($mover->tryMove($monster, $pose, $body, $midX, $midY, true), 'a monster that may drop off, or floats, steps over the ledge');
	$other->floor += 25;
	$originalCeiling = $other->ceiling;
	$other->ceiling = $other->floor + $body->height - 1;
	verifyWad(!$mid() && !$mover->floatOk, 'too low a ceiling blocks the step with no height a floater could use');
	$other->ceiling = $originalCeiling;
	$map->linedefs[$line['index']]['flags'] |= 2;
	$flagged = $build();
	verifyWad(!$flagged->tryMove($monster, $pose, $body, $midX, $midY, false) && $mid(), 'the block-monsters flag stops a monster where the same line otherwise lets it through');
	$map->linedefs[$line['index']]['flags'] &= ~2;
	$pose->z = $home[2];

	// A solid body on the destination blocks; a non-solid one, or one above the monster, does not.
	$blocker = $registry->create(new Transform($pose->x + 8, $pose->y, $pose->z), new Body($body->radius, $body->height, solid: true));
	$blockerBody = $registry->get($blocker, Body::class);
	$blockerPose = $registry->get($blocker, Transform::class);
	verifyWad(!$mover->tryMove($monster, $pose, $body, $pose->x + 8, $pose->y, false), 'a solid body on the destination blocks the step');
	$blockerBody->solid = false;
	verifyWad($mover->tryMove($monster, $pose, $body, $pose->x + 8, $pose->y, false), 'a non-solid body does not');
	$blockerBody->solid = true;
	$blockerPose->z = $pose->z + $body->height + 1;
	verifyWad($mover->tryMove($monster, $pose, $body, $pose->x + 8, $pose->y, false), 'nor a body wholly above the monster');
	$registry->destroy($blocker);
	$tick(1);

	// P_NewChaseDir heads toward the target when the way is open, and a successful walk starts a step count.
	[$pose->x, $pose->y, $pose->z] = $home;
	$target = new Transform($pose->x + 300, $pose->y, $pose->z);
	$brain->moveDir = MonsterMove::NO_DIRECTION;
	$mover->newChaseDirection($monster, $pose, $body, $brain, $target, 8.0, false, false);
	verifyWad($brain->moveDir === 0 && $pose->x === $home[0] + 8 && $pose->y === $home[1], 'a target due east sends the monster east by its speed');
	verifyWad($brain->moveCount >= 0 && $brain->moveCount <= 15, 'a successful walk sets a step count of up to fifteen');
	$target = new Transform($pose->x + 300, $pose->y + 300, $pose->z);
	$brain->moveDir = MonsterMove::NO_DIRECTION;
	$mover->newChaseDirection($monster, $pose, $body, $brain, $target, 8.0, false, false);
	verifyWad($brain->moveDir === 1, 'a target to the north-east is approached diagonally');

	// A_Chase steps without choosing while the count lasts, and never slides: a blocked step changes direction.
	$brain->moveDir = 0;
	$brain->moveCount = 5;
	$before = [$pose->x, $pose->y];
	$mover->chase($monster, $pose, $body, $brain, $target, 8.0, false, false);
	verifyWad($brain->moveDir === 0 && $brain->moveCount === 4 && $pose->x === $before[0] + 8, 'a chase step keeps the direction and counts down');
	// A body two radii and a little away: the monster is clear of it now, but an eastward step would meet it.
	$blocker = $registry->create(new Transform($pose->x + 2 * $body->radius + 4, $pose->y, $pose->z), new Body($body->radius, $body->height, solid: true));
	$before = [$pose->x, $pose->y];
	$mover->chase($monster, $pose, $body, $brain, $target, 8.0, false, false);
	verifyWad($brain->moveDir !== 0 && [$pose->x, $pose->y] !== $before, 'a step blocked by a body turns the monster to another open direction instead of sliding');
	$registry->destroy($blocker);
	$tick(1);

	// The monster turns toward its movement direction an eighth of a turn per chase, the shorter way round.
	$brain->moveDir = 2;
	$pose->angle = 0;
	$mover->face($brain, $pose);
	$first = $pose->angle;
	$mover->face($brain, $pose);
	$second = $pose->angle;
	$mover->face($brain, $pose);
	verifyWad(abs($first - M_PI / 4) < 1e-9 && abs($second - M_PI / 2) < 1e-9 && abs($pose->angle - M_PI / 2) < 1e-9, 'facing north from east takes two eighth-turns and then holds');
	$brain->moveDir = 6;
	$pose->angle = 0;
	$mover->face($brain, $pose);
	verifyWad(abs($pose->angle - 7 * M_PI / 4) < 1e-9, 'facing south from east turns clockwise, the shorter way');

	// A monster bumping a closed manual door opens it, and leaves an opening or open door alone.
	$door = null;

	foreach($map->linedefs as $line)
	{
		if((int)$line['action'] !== 1 || $line['left'] < 0) continue;

		$back = $level->sectors[(int)$map->sidedefs[$line['left']]['sector']];
		$front = $level->sectors[(int)$map->sidedefs[$line['right']]['sector']];

		if($back->ceiling === $back->floor && $front->ceiling - $front->floor > 100) { $door = [$line, $front, $back]; break; }
	}

	verifyWad($door !== null, 'E1M3 has a closed manual door with its front on an open sector');
	[$line, $front, $back] = $door;
	$a = $map->vertices[$line['from']];
	$b = $map->vertices[$line['to']];
	$length = hypot($b['x'] - $a['x'], $b['y'] - $a['y']);
	// The right side's outward normal points into the front sector.
	$nx = ($b['y'] - $a['y']) / $length;
	$ny = -($b['x'] - $a['x']) / $length;
	$pose->x = ($a['x'] + $b['x']) / 2 + $nx * ($body->radius + 2);
	$pose->y = ($a['y'] + $b['y']) / 2 + $ny * ($body->radius + 2);
	$pose->z = $front->floor;
	$obstacles->moved($monster, $pose);
	$brain->moveDir = ((int)round(atan2(-$ny, -$nx) / (M_PI / 4)) + 8) % 8;
	$before = [$pose->x, $pose->y];
	$moved = $mover->move($monster, $pose, $body, $brain, 8.0, false, false);
	verifyWad($moved && [$pose->x, $pose->y] === $before && $back->ceilingMover !== null, 'a step into a closed door does not move the monster but opens the door');
	verifyWad($brain->moveDir === MonsterMove::NO_DIRECTION, 'the monster then waits for a new direction, as P_Move leaves it');
	$doorMover = $registry->get($back->ceilingMover, SectorMover::class);
	$target = $doorMover->target;
	$brain->moveDir = ((int)round(atan2(-$ny, -$nx) / (M_PI / 4)) + 8) % 8;
	verifyWad(!$mover->move($monster, $pose, $body, $brain, 8.0, false, false) && $doorMover->target === $target, 'bumping the door again while it opens neither closes it nor counts as a move');
	$tick(40);
	verifyWad($back->ceiling > $back->floor + $body->height, 'the door has opened');
	$brain->moveDir = ((int)round(atan2(-$ny, -$nx) / (M_PI / 4)) + 8) % 8;
	$steps = 0;

	while($steps < 8 && $level->sectorAt($pose->x, $pose->y) !== $back && $mover->move($monster, $pose, $body, $brain, 8.0, false, false)) $steps++;

	verifyWad($steps >= 2 && $level->sectorAt($pose->x, $pose->y) === $back, 'the monster walks through the open door into its sector');
	verifyWad($mover->sectorIndexAt($pose->x, $pose->y) === $obstacles->sectorOf($monster, $pose), 'the step reported the monster\'s new sector to the actor grid without a BSP walk');

	// Spatial index keys stay integers in 32-bit PHP.
	$cells = (new ReflectionProperty($level->spatial, 'cells'))->getValue($level->spatial);
	verifyWad($cells && max(array_keys($cells)) < 2 ** 31 && min(array_keys($cells)) >= 0, 'spatial cell keys fit 32-bit integers');
})();
