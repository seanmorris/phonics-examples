<?php
use PhpWasmEngine\Physics\
{
	Body,Terrain,Platformer,MapTerrain,LayerSwitch
};
use PhpWasmEngine\Tiled\Loader;

function gate(
	float $x
	, float $y
	, float $w
	, float $h
	, int $from = 1
	, int $to = 2
	, bool $back = false
	, bool $roll = false
): LayerSwitch {
	return new LayerSwitch([[$x,$y],[$x + $w,$y],[$x + $w,$y + $h],[$x,$y + $h]], $from, $to, $back, $roll);
}

test('collision planes are per body and always include common terrain', function() {
	$t = new Terrain;
	$t->add([[40,-30],[42,-30],[42,30],[40,30]], false, 1);
	$t->add([[80,-30],[82,-30],[82,30],[80,30]], false, 2);
	$t->add([[-100,100],[200,100],[200,120],[-100,120]]);
	$a = new Body;
	$b = new Body;
	$b->collisionLayer = 2;
	near($t->castBody($a, 200, 0)->x, 40);
	near($t->castBody($b, 200, 0)->x, 80);
	near($t->raycast(0, 0, 200, 0, false, 2)->x, 80);
	near($t->castBody($a, 0, 200)->y, 100);
	near($t->castBody($b, 0, 200)->y, 100);
	check($t->solidAt(41, 0, 1) && !$t->solidAt(41, 0, 2));
});

test('edge cancellation follows the selected collision plane', function() {
	$t = new Terrain;
	$t->add([[0,0],[32,0],[32,32],[0,32]], false, 1);
	$t->add([[32,0],[64,0],[64,32],[32,32]], false, 2);
	near($t->raycast(60, 16, -60, 0, false, 1)->x, 32);
	near($t->raycast(4, 16, 60, 0, false, 2)->x, 32);
	$t = new Terrain;
	$t->add([[0,0],[32,0],[32,32],[0,32]]);
	$t->add([[32,0],[64,0],[64,32],[32,32]], false, 2);
	check($t->raycast(4, 16, 50, 0, false, 2) === null, 'common and selected neighbors must join');
	near($t->raycast(60, 16, -60, 0, false, 1)->x, 32);
});

test('loop gates select by tangent speed and invert the crown gate in the air', function() {
	$normal = gate(-30, -30, 60, 60);
	$crown = gate(-30, -30, 60, 60, 1, 2, true);
	$b = new Body;
	$b->grounded = true;
	$b->groundSpeed = 600;
	$b->vx = -600;
	$b->angle = M_PI;
	check($normal->target($b) === 2);
	$b->collisionLayer = 2;
	check($crown->target($b) === 1, 'ceiling world velocity must not reverse the route');
	$b->grounded = false;
	check($crown->target($b) === 1);
	$b->vx = 600;
	$b->collisionLayer = 1;
	check($crown->target($b) === 2);
	$b->collisionLayer = 3;
	check($normal->target($b) === null, 'unrelated planes must remain selected');
	$b->collisionLayer = 1;
	$rolling = gate(-30, -30, 60, 60, 1, 2, false, true);
	check($rolling->target($b) === null);
	$b->rolling = true;
	$b->halfHeight = 12;
	check($rolling->target($b) === 2);
});

test('continuous gate casts process narrow gates in travel order before terrain', function() {
	$t = new Terrain;

	// Deliberately reverse object order, with more switches than the contact budget.
	for($i = 8; $i >= 1; $i--)
	{
		$t->addSwitch(gate($i * 10, -30, .01, 60, $i, $i + 1));
	}

	$t->add([[100,-30],[100.1,-30],[100.1,30],[100,30]], false, 9);
	$b = new Body;
	$b->vx = 60000;
	$b->gravity = 0;
	tick(new Platformer($t), $b);
	check($b->collisionLayer === 9);
	near($b->x, 92, .01);
	$b = new Body(110, 0);
	$b->collisionLayer = 9;
	$b->vx = -60000;
	$b->gravity = 0;
	// Start on the near side of the wall to exercise all gates in reverse.
	$b->x = 88;
	tick(new Platformer($t), $b);
	check($b->collisionLayer === 1 && $b->x < 0);
});

test('gates behind walls or above the trajectory do not change collision planes', function() {
	$t = new Terrain;
	$t->addSwitch(gate(50, -30, 1, 60));
	$t->addSwitch(gate(10, -100, 1, 20));
	$t->add([[40,-30],[41,-30],[41,30],[40,30]]);
	$b = new Body;
	$b->vx = 60000;
	$b->gravity = 0;
	tick(new Platformer($t), $b);
	check($b->collisionLayer === 1);
	near($b->x, 32, .01);
});

test('blocked switches retry while overlapping without embedding the actor', function() {
	$t = new Terrain;
	$t->addSwitch(gate(-30, -30, 60, 60));
	$t->add([[-5,-25],[5,-25],[5,25],[-5,25]], false, 2);
	$b = new Body;
	$b->gravity = 0;
	tick(new Platformer($t), $b);
	check($b->collisionLayer === 1);
	$b->x = 20;
	tick(new Platformer($t), $b);
	check($b->collisionLayer === 2);
});

test('a switch cannot put a thin destination obstacle inside the body', function() {
	$t = new Terrain;
	$t->addSwitch(gate(50, -30, .1, 60));
	$t->add([[55,1.2],[55.1,1.2],[55.1,1.3],[55,1.3]], false, 2);
	$b = new Body;
	$b->vx = 60000;
	$b->gravity = 0;
	tick(new Platformer($t), $b);
	check($b->collisionLayer === 1 && $b->x > 100);
});

test('map planes and switch objects retain world, group, rotation and tile anchors', function() {
	$object = [
		'id' => 5
		, 'type' => 'layer-switch'
		, 'gid' => 1
		, 'x' => 30
		, 'y' => 40
		, 'width' => 20
		, 'height' => 30
		, 'rotation' => 90
		, 'properties' => [['name' => 'back','value' => '1']]
	];

	$data = mapData([
		[
			'type' => 'group'
			, 'offsetx' => 4
			, 'offsety' => -2
			, 'layers' => [
				layer(['name' => 'Collision 2','data' => [1,0,0,0]])
				, layer([
					'name' => 'Art'
					, 'data' => [0,1,0,0]
					, 'properties' => [
						[
							'name' => 'collision'
							, 'value' => false
						]
					]
				])
				, [
					'id' => 3
					, 'type' => 'objectgroup'
					, 'offsetx' => 3
					, 'objects' => [$object]
				]
			]
		]
	]);

	$map = fixture(['a' => $data,'tiles.tsj' => tiles()])->map('a');
	$map->x = -100;
	$map->y = 200;
	$t = MapTerrain::build($map);
	check($t->solidAt(-90, 205, 2) && !$t->solidAt(-90, 205, 1));
	check(!$t->solidAt(-72, 205, 2), 'art must not make another collision copy');
	$b = new Body(-48, 248);
	$b->grounded = true;
	$b->groundSpeed = 500;
	$b->collisionLayer = 2;
	$crossing = $t->nextSwitch($b, 0, 0);
	check($crossing !== null);
	check($crossing['switch']->target($b) === 1);
	check($t->nextSwitch(new Body(-48, 220), 0, 0) === null);
});

test('the tiled loop enters, changes route at the crown, and exits in both directions', function() {
	$t = MapTerrain::build((new Loader)->map(dirname(__DIR__, 3) . '/examples/assets/loop.tmj'));

	foreach([1,-1] as $direction)
	{
		foreach([false,true] as $rolling)
		{
			foreach($rolling?[900,1200,6000]:[600,900,6000] as $speed)
			{
				$b = new Body($direction > 0?500:1280, 430);
				$b->collisionLayer = $direction > 0?1:2;
				$b->grounded = true;
				$b->rolling = $rolling;
				$b->groundSpeed = $direction * $speed;
				$b->friction = 0;
				$p = new Platformer($t);
				$turn = 0;
				$previous = 0;
				$minY = 448;
				$crownLayer = null;

				for($i = 0; $i < 240; $i++)
				{
					tick($p, $b);
					$turn += atan2(sin($b->angle - $previous), cos($b->angle - $previous));
					$previous = $b->angle;
					$minY = min($minY, $b->y);

					if(($direction > 0?$b->x < 880:$b->x > 912) && $b->y < 250)
					{
						$crownLayer = $b->collisionLayer;
					}

					if(($direction > 0?$b->x > 1152:$b->x < 640))
					{
						break;
					}
				}

				check(
					$i < 240 && $minY < 225
					, "loop traversal: direction=$direction speed=$speed rolling=" . (int)$rolling
				);

				check($crownLayer === ($direction > 0?1:2));
				near($turn, -$direction * 2 * M_PI, .01);
				check($b->grounded && $b->collisionLayer === ($direction > 0?2:1));
				near($b->y + $b->halfHeight, 448, .01);
			}
		}
	}
});

test('the tiled loop releases slow actors and permits a normal jump from its ceiling', function() {
	$t = MapTerrain::build((new Loader)->map(dirname(__DIR__, 3) . '/examples/assets/loop.tmj'));
	$p = new Platformer($t);
	$b = new Body(896, 210);
	$b->grounded = true;
	$b->collisionLayer = 2;
	$b->angle = M_PI;
	tick($p, $b);
	check(!$b->grounded && $b->vy > 0);
	$b = new Body(896, 210);
	$b->grounded = true;
	$b->collisionLayer = 2;
	$b->angle = M_PI;
	$b->groundSpeed = 900;
	tick($p, $b, ['jump' => true]);
	check(!$b->grounded && $b->vy > 600 && $b->vx < 0);
});
