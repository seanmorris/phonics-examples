<?php
declare(strict_types=1);

use DoomPhonics\Archive\Archive;
use DoomPhonics\Archive\Stack;
use DoomPhonics\Component\Body;
use DoomPhonics\Component\Transform;
use DoomPhonics\Component\Velocity;
use DoomPhonics\Game\Collision;
use DoomPhonics\Game\Level;
use DoomPhonics\Game\LineSpecials;
use DoomPhonics\Game\PlayerState;
use DoomPhonics\Game\ProjectileTracer;
use DoomPhonics\Game\VerticalPhysics;
use DoomPhonics\Tests\PortMap;

require_once dirname(__DIR__) . '/tools/Staircase.php';
require_once dirname(__DIR__) . '/tools/RectangularCells.php';

$mergeCells = [];
for($y = 0; $y < 2; $y++) for($x = 0; $x < 2; $x++)
$mergeCells[] = ['x0' => $x * 64,'y0' => $y * 64,'x1' => ($x + 1) * 64,'y1' => ($y + 1) * 64,
	'floor' => -192,'ceiling' => 320,'floorTexture' => 'FLOOR0_1','ceilingTexture' => 'CEIL3_5',
	'light' => 192,'tag' => 100];
$mergedCells = \DoomPhonics\Tools\RectangularCells::merge($mergeCells);
verifyWad($mergedCells === [array_replace($mergeCells[0], ['x1' => 128,'y1' => 128])],
	'authoring-time merging replaces four matching sectors with one rectangle');
verifyWad(\DoomPhonics\Tools\RectangularCells::merge(array_reverse($mergeCells)) === $mergedCells,
	'map-cell merging is deterministic regardless of input order');
foreach(['floor' => -176,'ceiling' => 300,'floorTexture' => 'FLAT5_4','ceilingTexture' => 'F_SKY1',
	'light' => 160,'tag' => 101,'owner' => 'independent mover'] as $property => $different)
{
	$pair = [$mergeCells[0],array_replace($mergeCells[1], [$property => $different])];
	verifyWad(count(\DoomPhonics\Tools\RectangularCells::merge($pair)) === 2,
		"map-cell merging preserves differing $property boundaries");
}
$ringCells = [];
for($y = 0; $y < 3; $y++) for($x = 0; $x < 3; $x++)
if($x !== 1 || $y !== 1)
$ringCells[] = array_replace($mergeCells[0], ['x0' => $x * 64,'y0' => $y * 64,'x1' => ($x + 1) * 64,'y1' => ($y + 1) * 64]);
$ringCells = \DoomPhonics\Tools\RectangularCells::merge($ringCells);
$area = 0;
foreach($ringCells as $cell)
{
	$area += ($cell['x1'] - $cell['x0']) * ($cell['y1'] - $cell['y0']);
	verifyWad(!(96 > $cell['x0'] && 96 < $cell['x1'] && 96 > $cell['y0'] && 96 < $cell['y1']),
		'merging around a hole never fills its center');
}
verifyWad(count($ringCells) < 8 && $area === 8 * 64 * 64, 'merged cells retain the exact occupied area');
verifyWad(\DoomPhonics\Tools\RectangularCells::merge([]) === [], 'an empty authoring layout remains empty');

// Stair dimensions are independent of the surrounding grid, including the last rise.
foreach(['east','west','north','south'] as $direction)
foreach([[0,53],[53,0]] as [$from,$to])
{
	$treads = \DoomPhonics\Tools\Staircase::treads(0,0,$from,$to,16,40,96,$direction);
	verifyWad(count($treads) === 3, 'a nonmultiple height keeps the final landing within the requested rise');
	$height = $from;
	foreach($treads as $tread)
	{
		verifyWad(abs($tread['floor'] - $height) === 16, 'each tread uses the configured rise in either vertical direction');
		verifyWad(($tread['x1'] - $tread['x0']) * ($tread['y1'] - $tread['y0']) === 40 * 96, 'tread depth and width are independent');
		$height = $tread['floor'];
	}
	verifyWad(abs($to - $height) === 5, 'the final partial rise reaches the destination without overshooting');
	$last = $treads[2];
	verifyWad(match($direction) {
		'east' => $last['x1'] === 120, 'west' => $last['x0'] === -120,
		'north' => $last['y1'] === 120, 'south' => $last['y0'] === -120
	}, 'stair direction rotates the flight without changing its dimensions');
}
$carved = \DoomPhonics\Tools\Staircase::carve([
	['x0' => 0,'y0' => 0,'x1' => 128,'y1' => 128,'floor' => 0,'tag' => 99]
], \DoomPhonics\Tools\Staircase::treads(24,16,0,32,16,40,96));
$area = $raisedArea = 0;
foreach($carved as $cell)
{
	$cellArea = ($cell['x1'] - $cell['x0']) * ($cell['y1'] - $cell['y0']);
	$area += $cellArea;
	if($cell['floor'] === 16) $raisedArea += $cellArea;
	verifyWad($cell['tag'] === 99, 'carving stairs preserves volume control tags');
}
verifyWad($area === 128 * 128 && $raisedArea === 40 * 96, 'carving preserves surrounding area without gaps or overlapping cells');
foreach([[0,64,128,'east'],[16,0,128,'east'],[16,64,0,'east'],[16,64,128,'invalid']] as [$rise,$run,$width,$direction])
{
	$rejected = false;
	try { \DoomPhonics\Tools\Staircase::treads(0,0,0,64,$rise,$run,$width,$direction); }
	catch(\InvalidArgumentException) { $rejected = true; }
	verifyWad($rejected, 'invalid stair dimensions and directions are rejected');
}

$poolStack = new Stack(PortMap::archive([]), new Archive(file_get_contents(dirname(__DIR__) . '/demo/PORTALS.WAD'), 'PORTALS.WAD'));
$poolTextures = new \DoomPhonics\Graphics\TextureCatalog($poolStack);
$poolFlat = $poolTextures->flat('P3WATER');
verifyWad($poolFlat !== null && $poolFlat->width === 64 && $poolFlat->height === 64, 'the demo supplies its own water flat without relying on registered-IWAD assets');
$poolLevel = new Level($poolStack->map('POOL3D'));
verifyWad(count($poolLevel->sectors) <= 38 && count($poolLevel->map->linedefs) <= 134,
	'POOL3D adds two five-sector galleries while retaining the merged lower room');
$up = $poolLevel->cameraPortalForPlane($poolLevel->sectorAt(320,320)->index,1)?->link;
$down = $poolLevel->cameraPortalForPlane($poolLevel->sectorAt(320,1856)->index,0)?->link;
$topUp = $poolLevel->cameraPortalForPlane($poolLevel->sectorAt(320,1856)->index,1)?->link;
$topDown = $poolLevel->cameraPortalForPlane($poolLevel->sectorAt(320,3392)->index,0)?->link;
verifyWad(count($poolLevel->cameraPortalLinks()) === 4 && $up?->passable() && $down?->passable()
	&& $up->reciprocalId === $down->id && $down->reciprocalId === $up->id,
	'the entire pool shares one reciprocal pair of linked ceiling/floor portals');
verifyWad($topUp?->passable() && $topDown?->passable() && $topUp->reciprocalId === $topDown->id
	&& $topDown->reciprocalId === $topUp->id && $topUp->point(320,1856) === [320.0,3392.0]
	&& $topDown->point(320,3392) === [320.0,1856.0] && $topUp->cameraZ(576,$poolLevel) === 576.0,
	'the second reciprocal pair aligns the middle ceiling and top floor at height 576');
verifyWad($up->point(320,320) === [320.0,1856.0] && $down->point(320,1856) === [320.0,320.0]
	&& $up->cameraZ(320,$poolLevel) === 320.0 && $down->cameraZ(320,$poolLevel) === 320.0,
	'pool portal anchors align both rooms without rotating or shifting their physical heights');
// Check the entire room against the authored floor/tag layout, including every tread.
for($y = 16; $y < 1152; $y += 32)
for($x = 16; $x < 1152; $x += 32)
{
	$row = intdiv($y,128);
	$column = intdiv($x,128);
	$inside = $row > 0 && $row < 8 && $column > 0 && $column < 8;
	$floor = $inside ? -192 : 16;
	$tag = $inside ? 100 : 0;
	if($inside && $row >= 4 && $row <= 5 && $column >= 4 && $column <= 5) $tag = 101;
	if($row === 7 && $column === 4) $tag = 102;
	if($row === 6 && $column === 4) $tag = 103;
	if($row === 4 && $x >= 128 && $x < 896) $floor = -16 * intdiv($x - 128,64);
	$sector = $poolLevel->sectorAt($x,$y);
	$record = $poolLevel->map->sectors[$sector->index];
	verifyWad($sector->floor === (float)$floor && $sector->ceiling === 320.0 && $record['tag'] === $tag,
		"merged BSP retains heights and volume ownership at $x,$y");
	verifyWad($poolLevel->cameraPortalForPlane($sector->index,1)?->link === ($inside ? $up : null),
		'the ceiling portal covers every pool tag and stops at the solid rim');
	$upper = $poolLevel->sectorAt($x,$y + 1536);
	verifyWad($upper->floor === 320.0 && $upper->ceiling === 576.0 && $upper->volumes === []
		&& $poolLevel->cameraPortalForPlane($upper->index,0)?->link === ($inside ? $down : null)
		&& $poolLevel->cameraPortalForPlane($upper->index,1)?->link === ($inside ? $topUp : null),
		'the upper gallery has a matching opening with solid walkable edges and no copied water volumes');
	$top = $poolLevel->sectorAt($x,$y + 3072);
	verifyWad($top->floor === 576.0 && $top->ceiling === 832.0 && $top->volumes === []
		&& $poolLevel->cameraPortalForPlane($top->index,0)?->link === ($inside ? $topDown : null)
		&& $poolLevel->cameraPortalForPlane($top->index,1) === null,
		'the top gallery has a matching floor opening and a solid roof');
}
verifyWad($poolLevel->boundsWithinCameraPortalPlane(576,640,16,$poolLevel->sectorAt(576,640)->index,1),
	'a body straddling merged pool sectors remains inside the shared ceiling opening');
verifyWad(!$poolLevel->boundsWithinCameraPortalPlane(136,320,16,$poolLevel->sectorAt(136,320)->index,1),
	'a body overlapping the rim cannot traverse the ceiling opening early');
$switches = array_values(array_filter($poolLevel->map->linedefs, static fn($line) => $line['action'] === 47));
verifyWad(count($switches) === 2, 'merging preserves exactly two drain/refill switches');
foreach($switches as $line)
{
	$a = $poolLevel->map->vertices[$line['from']];
	$b = $poolLevel->map->vertices[$line['to']];
	verifyWad($a['y'] === 1152 && $b['y'] === 1152 && abs($a['x'] - $b['x']) === 128
		&& in_array(min($a['x'],$b['x']), [256,768], true) && $line['flags'] === 1537,
		'merged rim retains each original switch face and repeatable Use activation');
}
$pool = $poolLevel->sectorAt(704,704);
$water = $pool->volumes[0];
$platform = $pool->volumes[1];
verifyWad($poolLevel->unsupportedSpecials === [], 'POOL3D uses implemented Hexen specials');
verifyWad(count($pool->volumes) === 2 && $water->swimmable() && !$water->solid() && $platform->solid(), 'a solid platform and liquid share one pool column');
verifyWad($water->top(704,704) === 0.0 && $water->bottom(704,704) === -192.0, 'water occupies the full pool depth');
verifyWad($pool->supportZ(704,704,90) === 80.0 && $pool->supportZ(704,704,-80) === -192.0, 'support selects the platform or pool bottom based on actor height');
verifyWad($pool->headroomZ(704,704,0) === 56.0 && $pool->headroomZ(704,704,80) === 320.0, 'platform underside and room ceiling are separate headroom limits');

$physics = new VerticalPhysics($poolLevel);
$pose = new Transform(704,704,100);
$body = new Body(16,56);
$velocity = new Velocity;
for($i = 0; $i < 25; $i++) $physics->step($pose, $velocity, $body, 1 / 35);
verifyWad($pose->z === 80.0 && $body->grounded, 'falling actors land on the upper slab');
$pose = new Transform(704,704,-20);
$velocity = new Velocity(0,0,600);
$physics->step($pose, $velocity, $body, 1 / 35, 0);
$physics->step($pose, $velocity, $body, 1 / 35, 0);
verifyWad($pose->z === 0.0 && $velocity->z === 0.0, 'upward swimming stops at the platform underside');

$pose = new Transform(832,704,280);
$velocity = new Velocity(0,0,700);
$body = new Body(16,56);
$physics->step($pose,$velocity,$body,1 / 35,0);
verifyWad($pose->y === 2240.0 && abs($pose->z - 300) < .001 && $velocity->z === 700.0,
	'rising through the pool ceiling preserves height and vertical momentum in the gallery');
$velocity->z = -700;
$physics->step($pose,$velocity,$body,1 / 35,0);
verifyWad($pose->y === 704.0 && abs($pose->z - 280) < .001 && $velocity->z === -700.0,
	'the reciprocal gallery floor returns the body to the same pool column');
$pose = new Transform(704,3776,600);
$velocity = new Velocity;
$fallRooms = [];
for($i = 0; $i < 65; $i++)
{
	$fallRooms[(int)$pose->y] = true;
	$physics->step($pose,$velocity,$body,1 / 35);
}
verifyWad($pose->y === 704.0 && $pose->z === 80.0 && $body->grounded,
	'falling through both gallery openings lands on the floating platform below');
verifyWad(array_keys($fallRooms) === [3776,2240,704], 'falling traverses each room once in order');
$pose = new Transform(832,704,280);
$velocity = new Velocity(0,0,700);
$body->grounded = false;
for($i = 0; $i < 16; $i++) $physics->step($pose,$velocity,$body,1 / 35,0);
verifyWad($pose->y === 3776.0 && abs($pose->z - 600) < .001 && $velocity->z === 700.0,
	'rising through both ceiling portals preserves total displacement and momentum');

// A slab above a floor portal must remain support, just as an underside below
// a ceiling portal remains a headroom limit. Keep this variant out of the demo.
$floorPortalLevel = new Level($poolStack->map('POOL3D'));
$gallerySlab = $floorPortalLevel->sectorAt(704,704)->volumes[1];
$gallerySlab->model->floor = 400;
$gallerySlab->model->ceiling = 424;
$floorPortalLevel->sectorAt(704,2240)->volumes[] = $gallerySlab;
$floorPortalPhysics = new VerticalPhysics($floorPortalLevel);
$pose = new Transform(704,2240,450);
$velocity = new Velocity;
for($i = 0; $i < 25; $i++) $floorPortalPhysics->step($pose,$velocity,$body,1 / 35);
verifyWad($pose->y === 2240.0 && $pose->z === 424.0 && $body->grounded,
	'a solid slab above a floor portal prevents falling through its support surface');

$collision = new Collision($poolLevel);
$pose = new Transform(576,1088,16);
$body = new Body(16,56);
$body->grounded = true;
foreach([32.0,56.0,80.0] as $height)
{
	verifyWad(!$collision->move($pose,$body,0,-128) && $pose->z === $height, 'the approach stones provide a walkable route onto the platform');
}
$pose = new Transform(832,704,-80);
$body->grounded = false;
verifyWad(!$collision->move($pose,$body,-128,0) && $pose->x === 704.0, 'a submerged player can swim underneath the solid platform');
$pose = new Transform(832,704,32);
verifyWad($collision->move($pose,$body,-128,0) && $pose->x > 768, 'a player intersecting the slab is stopped at its side');

$wetPose = new Transform(832,704,-80);
$dryPose = new Transform(960,704,100);
$wetVelocity = new Velocity;
$dryVelocity = new Velocity;
for($i = 0; $i < 5; $i++)
{
	$physics->step($wetPose,$wetVelocity,new Body(16,56),1 / 35);
	$physics->step($dryPose,$dryVelocity,new Body(16,56),1 / 35);
}
verifyWad(abs($wetVelocity->z) < abs($dryVelocity->z), 'liquid drag and buoyancy slow descent compared with open air');

$tracer = new ProjectileTracer($poolLevel);
foreach([new Body(0,0),new Body(6,8)] as $shotBody)
{
	$hit = $tracer->trace(new Transform(704,704,120), $shotBody, 0,0,-100);
	verifyWad($hit && abs($hit->z - 80) < .001, 'bullets and rockets strike the upper platform surface');
	$hit = $tracer->trace(new Transform(704,704,-80), $shotBody, 0,0,200);
	verifyWad($hit && abs($hit->z + $shotBody->height - 56) < .001, 'bullets and rockets strike the underside through water');
	verifyWad($tracer->trace(new Transform(832,704,40), $shotBody, 0,0,-100) === null, 'water is transparent to shots');
	$hit = $tracer->trace(new Transform(832,704,64), $shotBody, -200,0,0);
	verifyWad($hit !== null && $hit->x >= 768, 'horizontal shots strike the platform edge');
	$path = $tracer->tracePath(new Transform(832,704,200),$shotBody,0,0,1000);
	verifyWad($path['portals'] === [$up,$topUp]
		&& $path['impact']?->y === 3776.0 && abs($path['impact']->z + $shotBody->height - 832) < .001,
		'bullets and rockets cross both ceilings once and strike the top roof');
	foreach([[704,80],[832,-192]] as [$x,$height])
	{
		$path = $tracer->tracePath(new Transform($x,2240,400),$shotBody,0,0,-800);
		verifyWad(count($path['portals']) === 1 && $path['portals'][0] === $down
			&& $path['impact']?->y === 704.0 && abs($path['impact']->z - $height) < .001,
			'shots through the gallery hit the actual platform or pool bottom through the water');
		$path = $tracer->tracePath(new Transform($x,3776,650),$shotBody,0,0,-1000);
		verifyWad($path['portals'] === [$topDown,$down]
			&& $path['impact']?->y === 704.0 && abs($path['impact']->z - $height) < .001,
			'shots through both gallery floors reach the same platform or pool bottom exactly once');
	}
	$path = $tracer->tracePath(new Transform(64,1088,200),$shotBody,0,0,600);
	verifyWad($path['portals'] === [] && abs($path['impact']->z + $shotBody->height - 320) < .001,
		'the rim ceiling still blocks bullets and rockets');
}
verifyWad($tracer->trace(new Transform(704,704,80), new Body(0,0), 0,0,50) === null, 'a ray leaving a slab surface does not hit it again');

$specials = new LineSpecials($poolLevel);
$player = new PlayerState;
$drainPose = new Transform(320,1120,16,M_PI / 2);
$refillPose = new Transform(832,1120,16,M_PI / 2);
for($cycle = 0; $cycle < 2; $cycle++)
{
	verifyWad($specials->use($drainPose, $player), 'drain switch is reachable from the rim and repeatable');
	$specials->update(1 / 35);
	verifyWad($water->top(704,704) === -2.0, 'water ceiling moves at the Hexen speed in eighth-units per tic');
	verifyWad($water->top(704,704,.5) === -1.0, 'water rendering interpolates the same moving control plane');
	for($i = 1; $i < 110; $i++) $specials->update(1 / 35);
	verifyWad($water->top(704,704) === -192.0 && $water->model->ceilingMover === null, 'drain stops exactly at the bottom and releases its thinker');
	verifyWad($pool->waterSurface(704,704,-192,56) === null, 'empty pool no longer applies swimming physics');
	verifyWad(!$specials->use($drainPose, $player), 'pressing drain while empty creates no mover');
	// Walk all the way out using the actual player system, without swimming or jumping.
	$stairPose = new Transform(960,576,-192,M_PI);
	$stairBody = new Body(16,56);
	$stairRegistry = new \Phonics\Ecs\Registry;
	$stairRegistry->create($stairPose,$stairBody,new Velocity,new PlayerState,
		new \DoomPhonics\Component\PlayerStart,new \DoomPhonics\Component\PlayerCommand(forward: 1));
	$movement = new \DoomPhonics\System\PlayerMovementSystem($collision,$specials,new \Phonics\Input\Controls);
	$visitedHeights = [];
	for($i = 0; $i < 250 && $stairPose->x > 64; $i++)
	{
		$before = $stairPose->z;
		$movement->update($stairRegistry,1 / 35);
		verifyWad($stairPose->z >= $before && $stairPose->z - $before <= 16,
			'the drained staircase never requires more than a 16-unit rise');
		$visitedHeights[(int)$stairPose->z] = true;
	}
	verifyWad($stairPose->x <= 64 && $stairPose->z === 16.0 && $stairBody->grounded,
		'the player walks from the drained pool bottom onto the rim');
	verifyWad(count($visitedHeights) === 14, 'the route visits both landings and all twelve intermediate treads');
	verifyWad($specials->use($refillPose, $player), 'refill switch is independently reachable');
	for($i = 0; $i < 110; $i++) $specials->update(1 / 35);
	verifyWad($water->top(704,704) === 0.0 && $pool->waterSurface(704,704,-80,56) === 0.0, 'refill restores swimming to the original height');
	verifyWad($platform->top(704,704) === 80.0 && $platform->bottom(704,704) === 56.0, 'draining and refilling never move the platform');
}

// Transfer_Heights gets a separate fixture: it must not compete with a solid slab.
foreach([['HEXEN',209,8],['DOOM',242,0],['DOOM',280,12],['DOOM',350,2],['DOOM',351,6]] as [$format,$special,$flags])
{
	$map = PortMap::map();
	$map->format = $format;
	$map->linedefs[0]['action'] = $special;
	$map->linedefs[0]['args'] = [2,$flags,0,0,0];
	$map->linedefs[0]['tag'] = 2;
	$map->sectors[0]['floor'] = 0;
	$map->sectors[0]['ceiling'] = 128;
	$map->sectors[1]['floor'] = -128;
	$map->sidedefs[0]['lower'] = 'WATERMAP';
	$level = new Level($map);
	$sector = $level->sectors[1];
	$t = $sector->heightTransfer;
	verifyWad($t !== null && $t->flags === $flags, "height transfer $special retains its format's flags");
	verifyWad($sector->floor === -128.0 && $sector->supportZ(192,64,-32) === -128.0, 'fake floor leaves physical collision at the real bottom');
	verifyWad($t->region(192,64,-20) === -1 && $t->region(192,64,40) === 0, 'eye crossing selects underwater or normal rendering');
	verifyWad($t->blend(-1) === [0,79 / 255,165 / 255,128 / 255], 'WATERMAP resolves to its documented blue blend');
	verifyWad(($sector->waterSurface(192,64,-80,56) !== null) === (bool)($flags & 8), 'swimming is opt-in for transferred heights');
	$normal = $t->view($sector,0);
	$submerged = $t->view($sector,-1);
	verifyWad($normal['floor'][0] === $level->sectors[0] && $submerged['floor'][0] === $sector, 'fake and real floor sources switch without mutating either sector');
	verifyWad($normal['light'] === 240 && $submerged['light'] === 160, 'height-transfer light follows the viewer region');
	$t->flags |= 32;
	verifyWad($t->view($sector,-1)['light'] === 240, 'no-light-transfer flag preserves the target light');
	$t->flags |= 16;
	verifyWad($t->view($sector,0)['floor'][0] === $sector, 'invisible transfer keeps real planes');
}

// A moving model carries riders in remote target sectors, including descent.
$motionLevel = new Level($poolStack->map('POOL3D'));
$motionRegistry = new \Phonics\Ecs\Registry;
$model = $motionLevel->sectorAt(704,704)->volumes[1]->model;
$riderPose = new Transform(704,704,80);
$rider = $motionRegistry->create($riderPose, new Body(16,56), new \DoomPhonics\Component\ActorState(new \DoomPhonics\Definition\ActorDefinition('Rider')));
$motion = new \DoomPhonics\System\SectorMotionSystem($motionLevel, new \DoomPhonics\Audio\NullSoundEmitter);
foreach([88,72] as $target)
{
	$mover = \DoomPhonics\Component\SectorMover::ceiling($model->index, $target, 70);
	$id = $motionRegistry->create($mover);
	$model->lockMover($mover,$id);
	for($i = 0; $i < 12; $i++) $motion->update($motionRegistry,1 / 35);
	verifyWad($riderPose->z === (float)$target, 'solid control plane carries a rider in either direction');
}

$atlas = \DoomPhonics\Graphics\IndexedAtlas::pack([
	'flat:P3WATER' => $poolFlat,
	'flat:FLAT5_4' => new \DoomPhonics\Graphics\IndexedImage(64,64,str_repeat("\2",4096),str_repeat("\xff",4096)),
	'wall:STARTAN3' => new \DoomPhonics\Graphics\IndexedImage(64,128,str_repeat("\3",8192),str_repeat("\xff",8192))
]);
$mesh = (new \DoomPhonics\Render\MapMeshBuilder($poolLevel, $atlas))->build();
$portalProjection = new \DoomPhonics\Render\ViewProjection(640,360,90);
verifyWad($mesh->cameraPortals->visibleLinks($poolLevel,576,896,32,-M_PI / 2,8,90,null,$portalProjection,.8) === [$up],
	'looking up captures one shared ceiling view across all pool sectors');
verifyWad($mesh->cameraPortals->visibleLinks($poolLevel,576,2624,320,-M_PI / 2,8,90,null,$portalProjection,-.65) === [$down],
	'the gallery captures the reciprocal floor view of the lower room');
verifyWad($mesh->cameraPortals->visibleLinks($poolLevel,576,4112,576,-M_PI / 2,8,90,null,$portalProjection,-.65) === [$topDown],
	'the top gallery starts its nested view through the middle gallery floor opening');
$view = [576,1088,57,1];
$opaque = \DoomPhonics\Render\VolumeDrawList::build($mesh,$poolLevel,$view,false);
$liquid = \DoomPhonics\Render\VolumeDrawList::build($mesh,$poolLevel,$view,true);
verifyWad(count($opaque) === 1 && count($liquid) === 1, 'the pool batches all opaque slabs and all water into two draws');
verifyWad(count($liquid[0]['indices']) > 100, 'the liquid batch includes many BSP-cell surfaces');
$water->model->ceiling = $water->model->previousCeiling = -192;
verifyWad(\DoomPhonics\Render\VolumeDrawList::build($mesh,$poolLevel,$view,true) === [], 'drained liquid disappears from rendering as well as physics');
verifyWad(\DoomPhonics\Render\VolumeDrawList::build($mesh,$poolLevel,$view,false) === $opaque, 'draining leaves the platform render batch unchanged');

$rect = static fn($x,$y) => [[$x,$y],[$x + 64,$y],[$x + 64,$y + 64],[$x,$y + 64]];
$merged = \DoomPhonics\Render\PlaneRectangles::merge([$rect(0,0),$rect(64,0),$rect(0,64),$rect(64,64)]);
verifyWad($merged === [[[0,0],[128,0],[128,128],[0,128]]], 'greedy meshing combines a square grid into one rectangle');
$ring = [];
for($y = 0; $y < 3; $y++) for($x = 0; $x < 3; $x++) if($x !== 1 || $y !== 1) $ring[] = $rect($x * 64,$y * 64);
$merged = \DoomPhonics\Render\PlaneRectangles::merge($ring);
$area = 0;
foreach($merged as $points) $area += (max(array_column($points,0)) - min(array_column($points,0))) * (max(array_column($points,1)) - min(array_column($points,1)));
verifyWad(count($merged) < count($ring) && $area === 8 * 64 * 64, 'greedy meshing preserves the unoccupied center of a ring');
verifyWad(\DoomPhonics\Render\PlaneRectangles::merge([[[0,0],[64,0],[0,64]]]) === [[[0,0],[64,0],[0,64]]], 'clipped triangles remain intact');
$waterPlanes = array_filter($mesh->volumeSpans, static fn($span) => $span['volume']?->swimmable() && !isset($span['projection']) && $span['count'] === 6 && $mesh->vertices[$span['first'] * 16 + 8] < 0);
verifyWad(count(array_unique(array_column($waterPlanes, 'sector'))) === 20,
	'water faces preserve each target sector so its live floor and ceiling can clip them independently');
verifyWad($liquid[0]['clipBounds'] === 3 && $opaque[0]['clipBounds'] === 3,
	'original liquid and solid faces stop at their own sector boundaries, preserving solid-floor contact');
$water->model->ceiling = $water->model->previousCeiling = 352;
$overflow = \DoomPhonics\Render\VolumeDrawList::build($mesh,$poolLevel,$view,true);
$projected = array_values(array_filter($overflow,static fn($b)=>$b['projected']));
verifyWad($projected && array_unique(array_column($projected,'clipBounds')) === [7],
	'water above a portal ceiling draws in the destination with inclusive floor contact');
$up->active = false;
$closed = \DoomPhonics\Render\VolumeDrawList::build($mesh,$poolLevel,$view,true);
verifyWad(!array_filter($closed,static fn($b)=>$b['projected']),
	'disabling the portal removes projected liquid from the destination');
$up->active = true;
$water->model->ceiling = $water->model->previousCeiling = 320;
$contact = \DoomPhonics\Render\VolumeDrawList::build($mesh,$poolLevel,$view,true);
verifyWad((bool)array_filter($contact,static fn($b)=>$b['projected']),
	'exact water contact is owned by the projected half rather than vanishing at the seam');
$water->model->ceiling = $water->model->previousCeiling = -192;
verifyWad(\DoomPhonics\Render\VolumeDrawList::build($mesh,$poolLevel,$view,true) === [],
	'draining removes source and projected water together');

// A smaller, rotated destination must crop the projection and preserve source UVs.
$projectionLevel = new Level(PortMap::map());
$projectionMesh = new \DoomPhonics\Render\MapMesh;
$projectionLink = new \DoomPhonics\Game\CameraPortalLink(0,3,2,0,1,64,64,192,64,M_PI/2,reciprocalId:1,destinationAlignment:1);
$projectionReturn = new \DoomPhonics\Game\CameraPortalLink(1,3,1,1,0,192,64,64,64,-M_PI/2,reciprocalId:0,destinationAlignment:2);
$projectionLevel->addCameraPortalSurface(new \DoomPhonics\Game\CameraPortalSurface('ceiling',0,$projectionLink));
$projectionSurface = new \DoomPhonics\Game\CameraPortalSurface('floor',1,$projectionReturn);
$projectionLevel->addCameraPortalSurface($projectionSurface);
foreach([[[160,32],[224,32],[224,96]],[[160,32],[224,96],[160,96]]] as $triangle)
$projectionMesh->cameraPortals->plane($triangle,1,0,$projectionSurface);
foreach([[0,0],[128,0],[128,128],[0,0],[128,128],[0,128]] as [$x,$y])
array_push($projectionMesh->vertices,...array_map('floatval',[$x,$y,$x,$x,0,0,0,1,-1,0,-$y,-1,0,-$y,0,0]));
$projectionMesh->volumeSpans[] = ['first'=>0,'count'=>6,'sector'=>0,'model'=>0,'plane'=>1,'x'=>64,'y'=>64,'opacity'=>.75,'additive'=>false,
	'volume'=>new \DoomPhonics\Game\SectorVolume($projectionLevel->sectors[0],0,2,0,192,'STARTAN3')];
\DoomPhonics\Render\VolumePortalFaces::append($projectionMesh,$projectionLevel);
$projectedArea = 0;
foreach(array_slice($projectionMesh->volumeSpans,1) as $span)
{
	verifyWad($span['projection']===$projectionLink && $span['sector']===1,'projected faces retain the live link and destination visibility sector');
	for($i=$span['first'];$i<$span['first']+$span['count'];$i+=3) {
		$points=[];
		for($j=0;$j<3;$j++) {
			$v=array_slice($projectionMesh->vertices,($i+$j)*16,16); $points[]=[$v[0],$v[1]];
			verifyWad($v[0]>=160-.001 && $v[0]<=224+.001 && $v[1]>=32-.001 && $v[1]<=96+.001,
				'liquid is cropped to the remote aperture instead of leaking onto surrounding floors');
			[$x,$y]=$projectionLink->point($v[2],-$v[10]);
			verifyWad(abs($x-$v[0])+abs($y-$v[1])<.001,'rotated projections retain source UVs for textures and sloped model evaluation');
		}
		[$a,$b,$c]=$points;
		$projectedArea+=abs(($b[0]-$a[0])*($c[1]-$a[1])-($b[1]-$a[1])*($c[0]-$a[0]))/2;
	}
}
verifyWad(abs($projectedArea-64*64)<.001,'clipped projected triangles cover the aperture exactly once');
$projectionLevel->sectors[0]->previousCeiling=128; $projectionLevel->sectors[0]->ceiling=160;
$projectionLevel->sectors[0]->ceilingSlope=[0,0,0];
$projectionLevel->sectors[1]->floorSlope=[0,0,0];
verifyWad(abs(\DoomPhonics\Render\VolumePortalFaces::heightOffset($projectionLink,$projectionLevel,.5)-(64-144))<.001,
	'projected height translation follows interpolated portal planes');
