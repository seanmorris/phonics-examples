<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/packages/terrain-phonics/autoload.php';
require_once dirname(__DIR__).'/autoload.php';
use TerrainSandbox\{SkidMarks,SkidTile};

function checkSkid(bool $condition,string $reason): void { if(!$condition) throw new RuntimeException($reason); }
function ink(SkidMarks $marks,float $x,float $z): int
{
	$x-=$marks->offset[0]; $z-=$marks->offset[2];
	$i=(int)floor($x/SkidTile::METERS); $j=(int)floor($z/SkidTile::METERS);
	$tile=$marks->tiles["$i,$j"] ?? null;
	if(!$tile) return 0;
	$u=SkidTile::BORDER+(int)floor(($x-$tile->x)*SkidTile::SCALE); $v=SkidTile::BORDER+(int)floor(($z-$tile->z)*SkidTile::SCALE);
	return ord($tile->pixels([$u,$v,$u+1,$v+1]));
}
/** Sample as the renderer does when a tire is close enough to magnify. */
function filteredInk(SkidMarks $marks,float $x,float $z): float
{
	$tile=$marks->tiles[floor($x/SkidTile::METERS).','.floor($z/SkidTile::METERS)];
	$u=($x-$tile->x)*SkidTile::SCALE+SkidTile::BORDER-.5;
	$v=($z-$tile->z)*SkidTile::SCALE+SkidTile::BORDER-.5;
	$i=(int)floor($u); $j=(int)floor($v);
	$bytes=$tile->pixels([$i,$j,$i+2,$j+2]);
	$u-=$i; $v-=$j;
	return (1-$v)*((1-$u)*ord($bytes[0])+$u*ord($bytes[1]))
		+$v*((1-$u)*ord($bytes[2])+$u*ord($bytes[3]));
}

foreach([.27,-.75,1.] as $slope) {
	$marks=new SkidMarks;
	foreach([-2.,2.] as $x) $marks->step([[0,$x,0.,4.+$slope*$x,.65]],$x,4.);
	$norm=hypot(1.,$slope);
	for($i=-100;$i<=100;$i++) foreach([-.145,.145] as $edge) {
		$x=$i/80.; $z=4.+$slope*$x;
		$coverage=filteredInk($marks,$x-$slope*$edge/$norm,$z+$edge/$norm);
		checkSkid(abs($coverage/(.65*255)-.5)<.022,'Both tread edges hold half coverage across diagonal angles and texel phases.');
	}
	$left=$marks->tiles['-1,0']; $right=$marks->tiles['0,0'];
	$border=2*SkidTile::BORDER; $end=SkidTile::SIZE;
	checkSkid($left->pixels([$end-$border,0,$end,$end])===$right->pixels([0,0,$border,$end]),
		'Neighboring tiles filter the same samples on either side of a boundary.');
}
$m=new SkidMarks;
$stamp=static fn(float $x,float $z,float $strength=1.)=>$m->step([[2,$x,.08*$x,$z,$strength]],$x,$z);
$stamp(-2.,.0625,.35); $stamp(2.,.0625,.35);
checkSkid(ink($m,-.0625,.0625)>75 && ink($m,.0625,.0625)>75,'Coverage meets across the world origin on banked ground.');
$stamp(-2.,.0625); $stamp(2.,.0625);
checkSkid(ink($m,1.,.0625)===255 && ink($m,1.,.5)===0,'Full slip darkens the same narrow tread.');
$stamp(-2.,.0625); $stamp(2.,.0625);
$versions=array_map(fn($t)=>$t->revision,$m->tiles);
for($i=0;$i<100;$i++) { $stamp(-2.,.0625); $stamp(2.,.0625); }
checkSkid($versions===array_map(fn($t)=>$t->revision,$m->tiles),'Repeated full passes neither allocate new tiles nor dirty the GPU coverage.');
$m->step([],2.,.0625); $stamp(6.,.0625);
checkSkid(ink($m,4.,.0625)===0,'Lifting a wheel breaks its track.');
$stamp(30.,.0625);
checkSkid(ink($m,20.,.0625)===0,'Recovery does not draw a rubber stripe across the map.');
$m->translate([128.,30.,-64.]);
checkSkid(ink($m,129.,-63.9375)===255,'A journey rebase keeps the mark in the same place on the road.');
$m->prune(128.+SkidMarks::RADIUS+1.5,-63.9375);
checkSkid(ink($m,129.,-63.9375)===0 && ink($m,130.,-63.9375)>0,'The half-mile circle clips individual texels within one tile.');
$m->prune(128.,-64.);
checkSkid(ink($m,129.,-63.9375)===0,'Returning cannot resurrect clipped rubber.');
$m->prune(2000.,-64.);
checkSkid(!$m->tiles,'Leaving the neighborhood releases all tiles.');
echo "Skid coverage, repeated passes, breaks, rebasing and retention passed.\n";
