<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Landscape;

/** Contact geometry of the arcade prop and the car's existing chassis box. */
final class SeekerContact
{
	// Dimensions of assets/projectile/mesh.bin.gz about its model origin.
	private const HALF_LENGTH=2., RADIUS=.22;

	/** Minimum origin height keeping the pitched nose, center and fins above the surface. */
	public static function floor(array $p,Landscape $land,float $water,?array &$contact=null): float
	{
		$half=$land->recipe()['worldSize']/2.;
		$basis=VehicleModel::basis($p);
		$floor=-INF;
		foreach([-self::HALF_LENGTH,0.,self::HALF_LENGTH] as $along) {
			$x=$p['x']+$along*$basis[6]; $z=$p['z']+$along*$basis[8];
			$height=max($water,$land->height(max(-$half,min($half,$x)),max(-$half,min($half,$z))));
			$needed=$height-$along*$basis[7]+self::RADIUS;
			if($needed>$floor) { $floor=$needed; $contact=[$x,$height,$z]; }
		}
		return $floor;
	}

	/** Test the whole prop axis against the chassis in the car's pitched and rolled frame. */
	private static function car(array $p,array $c): ?array
	{
		$basis=VehicleModel::basis($c);
		$nose=array_slice(VehicleModel::basis($p),6);
		$d=[$p['x']-$c['x'],$p['y']-$c['y'],$p['z']-$c['z']];
		$lo=-self::HALF_LENGTH; $hi=self::HALF_LENGTH;
		foreach([[-Car::BODY_HALF_WIDTH,Car::BODY_HALF_WIDTH],[0.,Car::BODY_HEIGHT],[-Car::BODY_REAR,Car::BODY_FRONT]] as $i=>[$min,$max]) {
			$j=3*$i;
			$center=$d[0]*$basis[$j]+$d[1]*$basis[$j+1]+$d[2]*$basis[$j+2];
			$axis=$nose[0]*$basis[$j]+$nose[1]*$basis[$j+1]+$nose[2]*$basis[$j+2];
			$a=$min-self::RADIUS-$center; $b=$max+self::RADIUS-$center;
			if(abs($axis)<.000001) { if($a>0. || $b<0.) return null; continue; }
			$a/=$axis; $b/=$axis;
			$lo=max($lo,min($a,$b)); $hi=min($hi,max($a,$b));
			if($lo>$hi) return null;
		}
		$at=($lo+$hi)/2.;
		return [$p['x']+$at*$nose[0],$p['y']+$at*$nose[1],$p['z']+$at*$nose[2]];
	}

	/** Sweep both moving models through the tick. A released prop can also cross the ground. */
	public static function first(array $from,array $to,array $oldCar,array $car,bool $coasting,Landscape $land,float $water): ?array
	{
		$travel=$relative=0.; $near=true;
		foreach(['x','y','z'] as $axis) {
			$a=$from[$axis]-$oldCar[$axis]; $b=$to[$axis]-$car[$axis];
			if(min($a,$b)>5. || max($a,$b)<-5.) $near=false;
			$relative=max($relative,abs($b-$a));
			$travel=max($travel,abs($to[$axis]-$from[$axis]));
		}
		if(!$near && !$coasting) return null;
		$n=(int)max(1,min(32,ceil(max($near ? $relative : 0.,$coasting ? $travel : 0.)/.45)));
		$oldCar=array_intersect_key($oldCar,$from)+['pitch'=>0.,'roll'=>0.];
		$car+=['pitch'=>0.,'roll'=>0.];
		for($i=1;$i<=$n;$i++) {
			$p=$i===$n ? $to : AirPatrol::interpolate($from,$to,$i/$n);
			if($near) {
				$c=$i===$n ? $car : AirPatrol::interpolate($oldCar,$car,$i/$n);
				if(($point=self::car($p,$c))!==null) return ['kind'=>'car','point'=>$point];
			}
			if($coasting && $p['y']<=self::floor($p,$land,$water,$point)) return ['kind'=>'ground','point'=>$point];
		}
		return null;
	}
}
