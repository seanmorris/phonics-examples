<?php
declare(strict_types=1);
namespace TerrainSandbox;

/** The seated nose and the shaft angles in chassis coordinates, shared by the impact flash and model. */
final class SeekerMount
{
	public static function atContact(array $point,array $prop,array $car): array
	{
		$body=VehicleModel::basis($car+['pitch'=>0.,'roll'=>0.]);
		$forward=array_slice(VehicleModel::basis($prop),6);
		$tail=[$prop['x']-2.*$forward[0],$prop['y']-2.*$forward[1],$prop['z']-2.*$forward[2]];
		$seat=$shaft=[];
		// Sink the tip inside the bodywork; the old tail position preserves the incoming side and angle.
		foreach([[-.73,.73],[.35,.70],[-1.76,1.83]] as $i=>[$lo,$hi]) {
			$j=3*$i;
			$nose=($point[0]-$car['x'])*$body[$j]+($point[1]-$car['y'])*$body[$j+1]+($point[2]-$car['z'])*$body[$j+2];
			$seat[$i]=max($lo,min($hi,$nose));
			$shaft[$i]=$seat[$i]-(($tail[0]-$car['x'])*$body[$j]+($tail[1]-$car['y'])*$body[$j+1]+($tail[2]-$car['z'])*$body[$j+2]);
		}
		return [...$seat,atan2($shaft[0],$shaft[2]),atan2($shaft[1],hypot($shaft[0],$shaft[2]))];
	}

	/** Transform a chassis-space vector to world space. */
	private static function rotate(array $body,float $x,float $y,float $z): array
	{
		return [$x*$body[0]+$y*$body[3]+$z*$body[6],$x*$body[1]+$y*$body[4]+$z*$body[7],$x*$body[2]+$y*$body[5]+$z*$body[8]];
	}

	public static function point(array $mount,array $car): array
	{
		[$x,$y,$z]=self::rotate(VehicleModel::basis($car+['pitch'=>0.,'roll'=>0.]),$mount[0],$mount[1],$mount[2]);
		return [$car['x']+$x,$car['y']+$y,$car['z']+$z];
	}

	public static function model(array $mount,array $car): array
	{
		$body=VehicleModel::basis($car+['pitch'=>0.,'roll'=>0.]);
		$local=VehicleModel::basis(['yaw'=>$mount[3],'pitch'=>$mount[4],'roll'=>0.]);
		$basis=[];
		foreach(array_chunk($local,3) as $axis) array_push($basis,...self::rotate($body,...$axis));
		// The mesh origin is two meters aft of its nose.
		[$x,$y,$z]=self::rotate($body,$mount[0],$mount[1],$mount[2]);
		return ['x'=>$car['x']+$x-2.*$basis[6],'y'=>$car['y']+$y-2.*$basis[7],'z'=>$car['z']+$z-2.*$basis[8],'basis'=>$basis];
	}
}
