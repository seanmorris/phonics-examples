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
	$u=(int)floor(($x-$tile->x)*SkidTile::SCALE); $v=(int)floor(($z-$tile->z)*SkidTile::SCALE);
	return ord($tile->pixels([$u,$v,$u+1,$v+1]));
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
