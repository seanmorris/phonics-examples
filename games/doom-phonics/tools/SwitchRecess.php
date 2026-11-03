<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

use DoomPhonics\Graphics\TextureCatalog;

require_once __DIR__ . '/PolygonGeometry.php';

/**
 * Build a shallow switch alcove with a bounded, cropped panel on its back wall.
 *
 * The opening extends into reserved solid space outside an axis-aligned room
 * wall. Its sill and ceiling bound the panel independently of the room height.
 * Callers retain the returned control point and check the combined map for overlaps.
 */
final class SwitchRecess
{
	/**
	 * Size a panel from the full texture, a named patch, or an explicit [x,y,width,height] crop.
	 *
	 * Patch bounds are clipped to the composite image. The resulting sidedef still uses
	 * the composite texture, so normal SW1/SW2 animation continues to work. A patch name
	 * must identify one placement; use an explicit crop to select repeated placements.
	 * @return array{width:int,height:int,crop:array{int,int}}
	 */
	public static function panel(TextureCatalog $textures,string $texture,?string $patch=null,?array $crop=null): array
	{
		$definition=$textures->textureDefinition($texture);
		if(!$definition || ($patch!==null && $crop!==null))
		throw new \InvalidArgumentException('Choose a known texture and at most one patch or crop');
		if($patch!==null)
		{
			$placements=array_values(array_filter($definition['patches'],static fn($p)=>$p['name']===strtoupper($patch)));
			if(count($placements)!==1) throw new \InvalidArgumentException('Switch patch must identify one texture placement');
			$p=$placements[0]; $image=$textures->picture($p['name']);
			if(!$image) throw new \InvalidArgumentException('Missing switch patch image');
			$x=max(0,$p['x']); $y=max(0,$p['y']);
			$crop=[$x,$y,min($definition['width'],$p['x']+$image->width)-$x,
				min($definition['height'],$p['y']+$image->height)-$y];
		}
		$crop??=[0,0,$definition['width'],$definition['height']];
		if(count($crop)!==4 || array_values($crop)!==$crop || count(array_filter($crop,'is_int'))!==4)
		throw new \InvalidArgumentException('Panel crop must contain four integer coordinates/dimensions');
		[$x,$y,$width,$height]=$crop;
		if($x<0 || $y<0 || $width<=0 || $height<=0 || $x+$width>$definition['width'] || $y+$height>$definition['height'])
		throw new \InvalidArgumentException('Switch panel extends outside its texture');
		return ['width'=>$width,'height'=>$height,'crop'=>[$x,$y]];
	}

	/** Return the decorative sector, switch boundary and a reachable control witness. */
	public static function build(array $room,array $point,array $normal,string $name,string $texture,
		int $action,array $args,int $width,int $height,int $sill=24,int $depth=8,array $crop=[0,0]): array
	{
		[$x,$y]=$point; [$nx,$ny]=$normal;
		if(abs($nx)+abs($ny)!==1 || $nx*$ny!==0 || $width<=0 || $height<=0
			|| $sill<0 || $depth<=0 || $depth>24 || count($crop)!==2 || min($crop)<0
			|| array_values($crop)!==$crop || count(array_filter($crop,'is_int'))!==2
			|| $room['floor']+$sill+$height>$room['ceiling'])
		throw new \InvalidArgumentException('Invalid switch recess dimensions or crop');
		if($action<0 || $action>255 || count($args)>5 || array_values($args)!==$args
			|| count(array_filter($args,static fn($v)=>is_int($v) && $v>=0 && $v<=255))!==count($args))
		throw new \InvalidArgumentException('Switch action and arguments must fit Hexen bytes');
		$args=array_pad($args,5,0);
		$polygon=PolygonGeometry::points($room);
		$half=intdiv($width,2); $points=[];
		foreach([[0,-$half],[0,$width-$half],[$depth,$width-$half],[$depth,-$half]] as [$n,$t])
		$points[]=[$x+$nx*$n-$ny*$t,$y+$ny*$n+$nx*$t];
		foreach(array_slice($points,0,2) as [$px,$py])
		if(!PolygonGeometry::contains($polygon,$px-$nx,$py-$ny) || PolygonGeometry::contains($polygon,$px+$nx,$py+$ny))
		throw new \InvalidArgumentException('Switch recess does not fit an exterior wall');
		$floor=$room['floor']+$sill; $ceiling=$floor+$height;
		$cell=PolygonGeometry::cell(['id'=>$name,'floor'=>$floor,'ceiling'=>$ceiling,'tag'=>0,
			'light'=>$room['light'],'floorTexture'=>$room['floorTexture'],'ceilingTexture'=>$room['ceilingTexture'],
			'wallTexture'=>$room['wallTexture'] ?? 'STARTAN3','wallYOffset'=>$room['ceiling']-$ceiling,
			'alignTextures'=>true,'traversable'=>false],$points);
		$boundary=['from'=>$points[2],'to'=>$points[3],'action'=>$action,'args'=>$args,'flags'=>1537,
			'texture'=>$texture,'xOffset'=>$crop[0],'yOffset'=>$crop[1]];
		$control=['region'=>$room['id'],'point'=>[$x-$nx*32,$y-$ny*32,$room['floor']],
			'angle'=>atan2($ny,$nx)];
		return compact('cell','boundary','control');
	}
}
