<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

require_once __DIR__ . '/SeedSequence.php';

/**
 * Grow a planar room graph with branches, loops and reserved connector space.
 *
 * Grid positions describe room envelopes, not a grid of floor sectors. Missing
 * connections are repaired by adding hallways before geometry is emitted.
 */
final class PlanarRooms
{
	public const DIRECTIONS = ['east'=>[1,0],'north'=>[0,1],'west'=>[-1,0],'south'=>[0,-1]];
	public const OPPOSITE = ['east'=>'west','west'=>'east','north'=>'south','south'=>'north'];

	/** Return room coordinates, hallway edges and the connections added during repair. */
	public static function build(SeedSequence $random, int $count, array $reserved = [], array $blocked = []): array
	{
		if($count < 2 || $count > 16) throw new \InvalidArgumentException('Use 2–16 rooms per layer');
		$rooms = [[0,0]];
		$occupied = ['0:0'=>0];
		$blocked = array_fill_keys(array_map(static fn($p)=>implode(':',$p),$blocked),true);
		for($i = 1; $i < $count; $i++)
		{
			$frontier = [];
			foreach($rooms as $id => [$x,$y])
			foreach(self::DIRECTIONS as $direction => [$dx,$dy])
			{
				if($id === 0 && in_array($direction,$reserved,true)) continue;
				$key = ($x + $dx) . ':' . ($y + $dy);
				if(!isset($occupied[$key]) && !isset($blocked[$key])) $frontier[$key] = [$x+$dx,$y+$dy];
			}
			if(!$frontier) throw new \InvalidArgumentException('Reservations leave no room for another room');
			// Prefer compact growth with several neighbors, while varying the hull.
			// This leaves actual alternate hallway routes instead of a sprawling tree.
			$ranked=[];
			foreach($frontier as [$x,$y]) {
				$neighbors=0;
				foreach(self::DIRECTIONS as [$dx,$dy]) if(isset($occupied[($x+$dx).':'.($y+$dy)])) $neighbors++;
				$ranked[]=['room'=>[$x,$y],'score'=>$neighbors*16-abs($x)-abs($y)+$random->integer(0,12)];
			}
			usort($ranked,static fn($a,$b)=>$b['score']<=>$a['score']);
			$room = $ranked[0]['room'];
			$occupied[implode(':',$room)] = count($rooms);
			$rooms[] = $room;
		}
		$candidates = [];
		foreach($rooms as $a => [$x,$y])
		foreach(self::DIRECTIONS as $direction => [$dx,$dy])
		{
			$b = $occupied[($x+$dx) . ':' . ($y+$dy)] ?? null;
			if($b === null || $b <= $a || ($a === 0 && in_array($direction,$reserved,true))) continue;
			$candidates[] = ['from'=>$a,'to'=>$b,'direction'=>$direction];
		}
		$groups = range(0,$count-1);
		$join = static function(int $a,int $b) use (&$groups): void {
			$old = $groups[$b]; $new = $groups[$a];
			foreach($groups as &$group) if($group === $old) $group = $new;
		};
		$edges = $omitted = $repairs = [];
		foreach($random->shuffle($candidates) as $edge)
		{
			if($random->integer(0,99) < 55)
			{ $edges[] = $edge; $join($edge['from'],$edge['to']); }
			else $omitted[] = $edge;
		}
		foreach($omitted as $edge)
		if($groups[$edge['from']] !== $groups[$edge['to']])
		{
			$edges[] = $edge;
			$repairs[] = $edge;
			$join($edge['from'],$edge['to']);
		}
		// Retain enough extra edges for loops, rather than discarding every
		// connection that is unnecessary for a spanning tree.
		$loops=max(0,count($edges)-$count+1);
		foreach($omitted as $edge)
		{
			if($loops>=intdiv($count,4)) break;
			if(in_array($edge,$edges,true)) continue;
			$edges[]=$edge; $loops++;
		}
		return compact('rooms','edges','repairs','loops');
	}

	/** Size connection bays before shaping independent room footprints inside them. */
	public static function dimensions(array $network,string $seed,string $plane,int $hubSize): array
	{
		$columns=$rows=$result=[];
		foreach($network['rooms'] as [$x,$y]) {
			$columns[$x] ??= $x===0 ? $hubSize : 1280+128*(new SeedSequence("bay:$seed:$plane:x:$x"))->integer(0,1);
			$rows[$y] ??= $y===0 ? $hubSize : 1280+128*(new SeedSequence("bay:$seed:$plane:y:$y"))->integer(0,1);
		}
		foreach($network['rooms'] as $i=>[$x,$y]) $result[$i]=[$columns[$x],$rows[$y]];
		return $result;
	}
}
