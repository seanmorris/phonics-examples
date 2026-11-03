<?php
declare(strict_types=1);

/**
 * Measure authored sector contours and export an annotated plan without copying a WAD.
 *
 * Sector boundaries include holes, trims and height/light changes, not just rooms.
 * One-sided lines are reported separately so parallel wall faces can be compared
 * without mistaking every sector division for a solid wall.
 */
require_once dirname(__DIR__) . '/autoload.php';
require_once __DIR__ . '/PolygonGeometry.php';

use DoomPhonics\Archive\Archive;
use DoomPhonics\Map\MapData;
use DoomPhonics\Tools\PolygonGeometry;

if(count($argv)!==4) {
	fwrite(STDERR,"Usage: php tools/measure-map.php FILE.wad MAP OUTPUT_PREFIX\nWrites OUTPUT_PREFIX.json and OUTPUT_PREFIX.svg; sector numbers are zero-based.\n");
	exit(1);
}
[, $path,$name,$prefix]=$argv;
$bytes=file_get_contents($path);
if($bytes===false) throw new RuntimeException('Cannot read WAD');
$archive=new Archive($bytes,$path); $name=strtoupper($name);
$lumps=$archive->mapLumps($name);
if(!$lumps) throw new InvalidArgumentException("Map $name not found");
$map=new MapData($name,$lumps);
$vertices=array_map(static fn($v)=>[$v['x'],$v['y']],$map->vertices);
$edges=$walls=[];
foreach($map->linedefs as $line) {
	$a=$line['from']; $b=$line['to'];
	$right=$line['right']<0 ? null : $map->sidedefs[$line['right']]['sector'];
	$left=$line['left']<0 ? null : $map->sidedefs[$line['left']]['sector'];
	if($right===$left) continue;
	if($right!==null) $edges[$right][]=[$a,$b,$line['index']];
	if($left!==null) $edges[$left][]=[$b,$a,$line['index']];
	if($right!==null && $left!==null) continue;
	[$ax,$ay]=$vertices[$a]; [$bx,$by]=$vertices[$b]; $length=hypot($bx-$ax,$by-$ay);
	if($length===0.0) continue;
	$nx=-($by-$ay)/$length; $ny=($bx-$ax)/$length;
	$walls[]=['line'=>$line['index'],'sector'=>$right??$left,'from'=>[$ax,$ay],'to'=>[$bx,$by],
		'length'=>$length,'normal'=>[$nx,$ny],'distance'=>$nx*$ax+$ny*$ay];
}
$sectors=[]; $paths=$labels=[];
foreach($map->sectors as $sector) {
	$id=$sector['index']; $remaining=$edges[$id]??[]; $loops=[]; $all=[]; $d='';
	while($remaining) {
		[$start,$next,$line]=array_shift($remaining); $loop=[$start]; $lines=[$line];
		while($next!==$start) {
			$previous=$loop[count($loop)-1]; $loop[]=$next; $matches=[];
			$reverse=atan2($vertices[$previous][1]-$vertices[$next][1],$vertices[$previous][0]-$vertices[$next][0]);
			foreach($remaining as $i=>[$a,$b,$line]) if($a===$next) {
				$angle=atan2($vertices[$b][1]-$vertices[$a][1],$vertices[$b][0]-$vertices[$a][0]);
				// At a point contact, follow the next face with the sector on our right.
				$matches[$i]=fmod($angle-$reverse+4*M_PI,2*M_PI);
			}
			if(!$matches) throw new UnexpectedValueException("Sector $id has an open contour at vertex $next");
			asort($matches,SORT_NUMERIC); $i=array_key_first($matches);
			[, $next,$line]=$remaining[$i]; unset($remaining[$i]); $lines[]=$line;
		}
		$points=PolygonGeometry::clean(array_map(static fn($v)=>$vertices[$v],$loop));
		$area=0;
		foreach($points as $i=>[$x,$y]) { [$a,$b]=$points[($i+1)%count($points)]; $area+=($x*$b-$y*$a)/2; }
		$bounds=[min(array_column($points,0)),min(array_column($points,1)),max(array_column($points,0)),max(array_column($points,1))];
		$lengths=[];
		foreach($points as $i=>$a) { $b=$points[($i+1)%count($points)]; $lengths[]=hypot($b[0]-$a[0],$b[1]-$a[1]); }
		$loops[]=['points'=>$points,'lines'=>$lines,'signedArea'=>$area,'hole'=>$area>0,'bounds'=>$bounds,'edgeLengths'=>$lengths];
		array_push($all,...$points);
		$d.='M'.implode(' L',array_map(static fn($p)=>$p[0].','.(-$p[1]),$points)).' Z ';
	}
	if(!$loops) continue;
	$bounds=[min(array_column($all,0)),min(array_column($all,1)),max(array_column($all,0)),max(array_column($all,1))];
	$sectors[]=$sector+['bounds'=>$bounds,'size'=>[$bounds[2]-$bounds[0],$bounds[3]-$bounds[1]],'loops'=>$loops];
	$hue=fmod($id*137.5,360);
	$paths[]='<path data-sector="'.$id.'" d="'.$d.'" fill="hsl('.$hue.' 30% 28%)" fill-rule="evenodd" stroke="#8794ab" stroke-width="2"><title>Sector '.$id.'; floor '.$sector['floor'].'; ceiling '.$sector['ceiling'].'</title></path>';
	$largest=$loops[array_search(min(array_column($loops,'signedArea')),array_column($loops,'signedArea'),true)]['points'];
	$x=array_sum(array_column($largest,0))/count($largest); $y=array_sum(array_column($largest,1))/count($largest);
	$labels[]='<text x="'.$x.'" y="'.(-$y).'" fill="white" font-family="sans-serif" font-size="24" text-anchor="middle">'.$id.'</text>';
}
$bounds=$map->bounds; $title=htmlspecialchars($name,ENT_QUOTES|ENT_XML1);
$svg='<svg xmlns="http://www.w3.org/2000/svg" role="img" aria-label="'.$title.' sector plan" viewBox="'.($bounds['minX']-64).' '.(-$bounds['maxY']-64).' '.($bounds['width']+128).' '.($bounds['height']+128).'" style="background:#171b24"><title>'.$title.' measured sector contours</title>'.implode('',$paths).implode('',$labels).'</svg>';
$data=['map'=>$name,'wadSha256'=>hash('sha256',$bytes),'format'=>$map->format,'bounds'=>$bounds,'sectorCount'=>count($map->sectors),'sectors'=>$sectors,'solidFaces'=>$walls];
if(!is_dir(dirname($prefix)) && !mkdir(dirname($prefix),0777,true)) throw new RuntimeException('Cannot create measurement directory');
foreach(['json'=>json_encode($data,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n",'svg'=>$svg] as $extension=>$content)
if(file_put_contents("$prefix.$extension",$content)===false) throw new RuntimeException('Cannot write measurement');
fwrite(STDOUT,"Measured $name: ".count($sectors)." sectors, ".count($walls)." one-sided wall faces. Wrote $prefix.json and $prefix.svg\n");
