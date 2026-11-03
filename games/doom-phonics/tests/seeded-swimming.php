<?php
declare(strict_types=1);

use DoomPhonics\Tools\MapConnectivity;
use DoomPhonics\Tests\SeededWalkthrough;

$swimCount=0;
foreach($default['verticalConnections'] as $portal) {
 $source=$default['cells'][$default['regions'][$portal['to']]];
 if(in_array($default['regions'][$portal['to']],$default['waterSectors'],true))
 verifyWad($source['ceiling'] >= $default['waterStates']['full'],'the full basin surface never rises above a flooded portal ceiling');
 if($source['ceiling']>0 || !in_array($portal['kind'],['shaft','gallery'],true)) continue;
 $level=MapConnectivity::level($default);
 foreach($level->sectors[$default['regions'][$portal['to']]]->volumes as $volume)
 verifyWad($volume->texture==='-','rising water does not create textured side faces over the real walls');
 $trace=SeededWalkthrough::swimPortal($level,$default,$portal); $crossed=false;
 foreach($trace['frames'] as $frame) {
  if($frame['sector']===$trace['target']) $crossed=true;
  verifyWad(!$crossed || $frame['sector']===$trace['target'],'a swimming ascent does not bounce back through the portal');
  verifyWad(abs($frame['z']-$frame['previousZ'])<8,'portal swimming keeps interpolated camera height continuous');
 }
 verifyWad($crossed && $trace['end']['sector']===$trace['rim'],'swimming through a flooded ceiling portal reaches the dry room rim');
 $link=$level->cameraPortalForPlane($trace['target'],0)->link;
 $target=$default['cells'][$trace['target']]; [$x,$y]=$target['center'];
 verifyWad($level->waterSurface($x,$y,$target['floor']-20,56)!==null,'a body straddling the remote portal stays in contact with water');
 $link->active=false;
 verifyWad($level->waterSurface($x,$y,$target['floor']-20,56)===null,'inactive portals do not transmit liquid contact');
 $link->active=true;
 $level->sectors[$default['model']]->ceiling=-$default['depth'];
 verifyWad($level->waterSurface($x,$y,$target['floor']-20,56)===null,'draining the source removes remote water contact immediately');
 $swimCount++;
}
verifyWad($swimCount>0,'SEEDED tests upward swimming through a submerged plane portal');
