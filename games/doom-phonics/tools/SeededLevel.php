<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

require_once __DIR__ . '/PlanarRooms.php';
require_once __DIR__ . '/OrganicLayout.php';
require_once __DIR__ . '/RoomFeatures.php';
require_once __DIR__ . '/RoomOutline.php';
require_once __DIR__ . '/RoomJoin.php';
require_once __DIR__ . '/RoomElevation.php';
require_once __DIR__ . '/SpiralStaircase.php';
require_once __DIR__ . '/CornerStaircase.php';
require_once __DIR__ . '/SpatialLayout.php';
require_once __DIR__ . '/RectangularMap.php';
require_once __DIR__ . '/PolygonMap.php';
require_once __DIR__ . '/RoomCluster.php';
require_once __DIR__ . '/SwitchRecess.php';
require_once __DIR__ . '/PortalRecess.php';
require_once __DIR__ . '/PlanePortalSite.php';
require_once __DIR__ . '/PortalGallery.php';
require_once __DIR__ . '/FloodBasin.php';
require_once __DIR__ . '/MapConnectivity.php';
require_once __DIR__ . '/RouteTraversal.php';

/**
 * Generate reproducible basement, ground and upper planar room networks.
 *
 * Local room elevations are resolved before architectural layer placement. A
 * flooded ledge and a curved stair hall connect the storeys; recessed passages
 * pin adjacent rooms together independently of their packed WAD coordinates.
 * Deterministic repairs preserve connectivity, stair clearance and layer slabs.
 */
final class SeededLevel
{
	/** Changes to the generation algorithm require a new reproducibility version. */
	public const VERSION = 13;
	/** Keep topology stable when refining individual room geometry. */
	private const RANDOM_REVISION = 11;
	/** Reserve space for longer stair flights and bent approaches around each core. */
	public const ROOM_SIZE = 1280;
	/** Minimum open headroom in ordinary rooms, approaches and spiral landings. */
	public const HEADROOM = 192;
	/** Reserve solid separation between ordinary rooms on adjacent storeys. */
	public const STOREY_SLAB = 32;

	/** Build a fresh map and certify all regions with the runtime movement rules. */
	public static function build(string $seed = 'first-light', int $rooms = 14, string $layout = 'organic', string $portalDoors = 'seeded', string $switchPanels = 'patch',int $doorChance=30,string $galleryHalls='seeded'): array
	{
		$failures=[]; $started=hrtime(true);
		for($recovery=0;$recovery<3;$recovery++) {
			try {
				$result=self::attempt($seed,$rooms,$layout,$portalDoors,$switchPanels,$doorChance,$galleryHalls,$recovery);
				foreach($failures as $reason) $result['repairs'][]=['kind'=>'generation-recovery','reason'=>$reason];
				$result['recovery']=$recovery;
				$retryMs=max(0,(hrtime(true)-$started)/1e6-$result['timings']['totalMs']);
				$result['timings']['recoveryMs']=$retryMs; $result['timings']['totalMs']+=$retryMs;
				return $result;
			} catch(\LogicException|\RuntimeException $error) {
				if($layout!=='organic' || $rooms<2 || $rooms>16 || !in_array($portalDoors,['seeded','open','doors'],true)
					|| !in_array($switchPanels,['patch','texture'],true) || !in_array($galleryHalls,['seeded','all','none'],true) || $doorChance<0 || $doorChance>100 || $recovery===2) throw $error;
				$failures[]=$error->getMessage();
			}
		}
		throw new \LogicException('No certified layout');
	}

	/** Each bounded attempt builds and certifies a complete candidate before returning it. */
	private static function attempt(string $seed,int $rooms,string $layout,string $portalDoors,string $switchPanels,int $doorChance,string $galleryHalls,int $recovery): array
	{
		$started = hrtime(true);
		if(!in_array($galleryHalls,['seeded','all','none'],true)) throw new \InvalidArgumentException('Unknown gallery hallway style');
		if($rooms < 2 || $rooms > 16) throw new \InvalidArgumentException('Use 2–16 rooms per layer');
		if(!in_array($layout,['organic','building'],true)) throw new \InvalidArgumentException('Unknown room layout');
		if(!in_array($portalDoors,['seeded','open','doors'],true)) throw new \InvalidArgumentException('Unknown portal entrance style');
		if(!in_array($switchPanels,['patch','texture'],true)) throw new \InvalidArgumentException('Unknown switch panel style');
		if($doorChance<0 || $doorChance>100) throw new \InvalidArgumentException('Door chance must be 0–100');
		$withDoors = $portalDoors === 'doors' || ($portalDoors === 'seeded' && (new SeedSequence('portal-doors:' . $seed))->integer(0,1) === 1);
		$random = new SeedSequence('phonics-seeded-' . self::RANDOM_REVISION . ':' . $seed);
		$repairs = [];
		// Leave a basement slab while keeping the drain height in a Hexen byte.
		$depth = self::HEADROOM+self::STOREY_SLAB+$random->integer(0,1)*16;
		$steps = $random->integer(12,16);
		$mirror = (bool)$random->integer(0,1);
		$stairSweep=(new SeedSequence('stair-sweep:'.$seed))->integer(0,1) ? 90 : 180;
		$stairSweep=$layout==='organic' ? 180 : $stairSweep;
		$upperPort = $stairSweep===180 ? 'north' : ($mirror ? 'west' : 'east');
		if($layout==='organic') {
			$organic=OrganicLayout::build($seed,$rooms,$depth,$upperPort,$withDoors,$doorChance,self::room(...),self::cavern(...),$recovery,$mirror);
			['layers'=>$layers,'pins'=>$pins,'topology'=>$topology,'repairs'=>$repairs,'galleryRooms'=>$galleryRooms]=$organic;
		} else {
		$topology = [
			'basement' => PlanarRooms::build($random,$rooms,['north'],[[0,1]]),
			'ground' => PlanarRooms::build($random,$rooms,['north','south'],[[0,1],[0,-1]]),
			'upper' => PlanarRooms::build($random,$rooms,[$upperPort],[PlanarRooms::DIRECTIONS[$upperPort]])
		];
		$dimensions=[];
		foreach($topology as $plane=>$network) {
			$hubSize=$layout==='organic' ? match($plane) {'ground'=>RoomCluster::ENVELOPE_SIZE,'upper'=>1664,default=>1920} : self::ROOM_SIZE;
			$dimensions[$plane]=PlanarRooms::dimensions($network,$seed,$plane,$hubSize);
		}
		$ports = $pins = [];
		foreach($topology as $plane => $network)
		{
			foreach($network['edges'] as $edge)
			{
				$a = "$plane.{$edge['from']}"; $b = "$plane.{$edge['to']}";
				$direction = $edge['direction']; $opposite = PlanarRooms::OPPOSITE[$direction];
				$width=128;
				$ports[$a][$direction] = $ports[$b][$opposite] = $width;
				$pins[] = [$a,$direction,$b,$opposite];
			}
			foreach($network['repairs'] as $edge)
			$repairs[] = ['kind'=>'hallway','layer'=>$plane,'from'=>$edge['from'],'to'=>$edge['to'],
				'reason'=>'Join disconnected room components'];
		}
		$ports['basement.0']['north'] = $ports['ground.0']['south'] = 128;
		$ports['ground.0']['north'] = $ports['upper.0'][$upperPort] = 160;
		$pins[] = ['basement.0','north','ground.0','south'];
		$pins[] = ['ground.0','north','spiral','in'];
		$pins[] = ['spiral','out','upper.0',$upperPort];
		// Resolve room heights before choosing the elevation of any connecting passage.
		$specs = [];
		foreach($topology as $plane => $network)
		foreach($network['rooms'] as $i => $grid)
		{
			$name = "$plane.$i"; $requested = $random->integer(0,12) * 16;
			$height = $i === 0 ? ($plane !== 'basement' && $layout === 'organic' ? 64 : 0) : min($requested,$plane === 'basement' ? $depth-self::HEADROOM-self::STOREY_SLAB : 192);
			if($height !== $requested) $repairs[] = ['kind'=>'height','room'=>$name,'from'=>$requested,'to'=>$height,
				'reason'=>'Preserve storey headroom and the fixed inter-layer landings'];
			$specs[$name] = ['floor'=>$height,'plane'=>$plane,'grid'=>$grid,'inset'=>$i===0 ? 256 : 128,'variant'=>$random->integer(0,7)];
		}
		$passages = []; $liftTag = 201;
		foreach($pins as [$a,$aa,$b,$ba])
		{
			if(!isset($specs[$a],$specs[$b]) || $specs[$a]['plane'] !== $specs[$b]['plane']) continue;
			$af = $specs[$a]['floor']; $bf = $specs[$b]['floor'];
			$height = max(min($af,$bf),min(max($af,$bf),(int)round(($af+$bf)/32)*16));
			$ceiling = max($af,$bf)+self::HEADROOM;
			foreach([[$a,$aa,$af,$bf],[$b,$ba,$bf,$af]] as [$name,$port,$floor,$otherFloor])
			if($specs[$name]['plane'] !== 'basement' && count($ports[$name]) === 1
				&& $floor-$otherFloor > 96)
			{
				$specs[$name]['liftTag'] = $liftTag++;
				$specs[$name]['inset'] = 384;
				$height = $otherFloor;
				$repairs[] = ['kind'=>'elevator','room'=>$name,'reason'=>'Retain a high terminal room with a callable lift and full-height landing'];
			}
			$passages[$a][$aa] = $passages[$b][$ba] = compact('height','ceiling');
		}
		$passages['basement.0']['north'] = ['height'=>$depth,'ceiling'=>$depth+self::HEADROOM];
		$passages['ground.0']['south'] = $passages['ground.0']['north'] = ['height'=>0,'ceiling'=>self::HEADROOM+64];
		$passages['upper.0'][$upperPort] = ['height'=>0,'ceiling'=>self::HEADROOM+64];
		foreach($specs as $name => &$spec)
		foreach($passages[$name] as $port => &$passage)
		{
			$delta = abs($passage['height']-$spec['floor']);
			$bend = !isset($spec['liftTag']) && $name !== 'basement.0'
				&& !in_array($name,['ground.0','upper.0'],true) && $ports[$name][$port] === 128 && $delta <= 64 && $random->integer(0,2) === 0;
			$passage['bend'] = $bend ? ($random->integer(0,1) ? 128 : -128) : 0;
			if(($delta > 32 || $bend) && !isset($spec['liftTag']) && $name !== 'basement.0')
			{
				$spec['inset'] = max($spec['inset'],$bend ? 576 : max(0,intdiv($delta,16)-1)*64+256);
				$repairs[] = ['kind'=>$bend ? 'bend' : 'lengthen','room'=>$name,'port'=>$port,
					'reason'=>$bend ? 'Route a dogleg with level turning landings' : 'Lengthen the approach to fit a monotonic stair flight'];
			}
		}
		unset($spec,$passage);
		foreach($specs as $name=>$spec) foreach($topology[$spec['plane']]['rooms'] as $i=>$grid) {
			if($grid[0]===$spec['grid'][0]) $dimensions[$spec['plane']][$i][0]=max($dimensions[$spec['plane']][$i][0],2*$spec['inset']+768);
			if($grid[1]===$spec['grid'][1]) $dimensions[$spec['plane']][$i][1]=max($dimensions[$spec['plane']][$i][1],2*$spec['inset']+768);
		}
		// Reserve gallery rooms before footprint sizing; their bays grow together
		// with the same row/column so every spatial pin cycle still closes exactly.
		$galleryRooms=[];
		if($layout==='organic') foreach(['basement','ground','upper'] as $plane) {
			foreach($specs as $name=>&$spec) {
				if($spec['plane']!==$plane || !$spec['grid'][0] || !$spec['grid'][1]
					|| isset($spec['liftTag']) || $name==='basement.1' || $name==='upper.'.($rooms-1)) continue;
				$galleryRooms[$plane]=$name; $spec['gallery']=true;
				foreach($topology[$plane]['rooms'] as $i=>$grid) {
					if($grid[0]===$spec['grid'][0]) $dimensions[$plane][$i][0]=max(2*$spec['inset']+1152,$dimensions[$plane][$i][0]);
					if($grid[1]===$spec['grid'][1]) $dimensions[$plane][$i][1]=max(2*$spec['inset']+1152,$dimensions[$plane][$i][1]);
				}
				break;
			}
			unset($spec);
		}
		if(count($galleryRooms)!==3) { foreach($specs as &$spec) unset($spec['gallery']); unset($spec); $galleryRooms=[]; }
		$footprints=$roomDimensions=[];
		foreach($specs as $name=>$spec) {
			$d=$dimensions[$spec['plane']][(int)substr($name,strrpos($name,'.')+1)]; $roomDimensions[$name]=$d;
			$footprints[$name]=RoomOutline::footprint($name,$d,$spec['inset'],$seed,$layout==='building',$spec['gallery']??false,
				isset($spec['liftTag']) ? array_key_first($ports[$name]) : null);
		}
		RoomJoin::approach($footprints,$specs,$pins,$roomDimensions,$passages);
		// Use existing passage elevations to carry useful routes across room interiors.
		$elevations=[];
		foreach(['ground','upper'] as $plane) {
			$selected=0;
			foreach($specs as $name=>$spec) {
				if($spec['plane']!==$plane || str_ends_with($name,'.0') || $name==='upper.'.($rooms-1)
					|| isset($spec['liftTag']) || isset($spec['gallery']) || ($spec['joinCandidate']??false)) continue;
				$plan=RoomElevation::plan($footprints[$name],$roomDimensions[$name],$passages[$name]);
				if(!$plan) continue;
				$corner=CornerStaircase::connection($footprints[$name],$roomDimensions[$name],$plan);
				$elevations[$name]=$corner ? array_replace($plan,$corner) : $plan;
				foreach($passages[$name] as &$passage) $passage['bend']=0;
				unset($passage);
				if(++$selected===2) break;
			}
		}
		$layers = [];
		foreach($specs as $name => $spec)
		{
			if($name === 'basement.0') continue;
			$ceiling = max($spec['floor']+self::HEADROOM,...array_column($passages[$name],'ceiling'));
			$layers[$name] = self::room($name,$ports[$name],$spec['floor'],$ceiling,$spec['inset'],
				$passages[$name],$spec['variant'],$layout === 'building',$spec['liftTag'] ?? 0, $layout === 'organic' ? match($name) {'ground.0'=>'conforming','upper.0'=>'circular',default=>null} : null,$withDoors,
				// Shared bay dimensions keep cycles aligned while the footprints vary.
				$dimensions[$spec['plane']][(int)substr($name,strrpos($name,'.')+1)][0],$seed,$doorChance,$dimensions[$spec['plane']][(int)substr($name,strrpos($name,'.')+1)],$spec['gallery']??false,$footprints[$name],$elevations[$name]??null)
				+ ['plane'=>$spec['plane'],'grid'=>$spec['grid'],'localFloor'=>$spec['floor'],'merged'=>null,'joinCandidate'=>$spec['joinCandidate']??false];
		}
		// A high north ledge is only reachable from below after using the pump.
		$layers['basement.0'] = self::cavern($ports['basement.0'],$depth,$passages['basement.0'],$layout==='organic' ? 1920 : 1280,$dimensions['basement'][0])
			+ ['plane'=>'basement','grid'=>[0,0],'localFloor'=>0];
		}
		[$poolX,$poolY]=$layers['basement.0']['contentOffset'] ?? [0,0];
		if($layout === 'organic')
		{
			// The central chamber is a recessed portal well, with a return via the pool and spiral.
			$layers['upper.0']['cells'][0]['floor'] = 0;
			$opening = array_map(static fn($p)=>[$p[0]+224+$poolX,$p[1]+32+$poolY],$layers['upper.0']['cells'][0]['polygon']);
			// Start subtraction at the east face so both pump switches stay in basement.0.core.
			$opening = array_merge(array_slice($opening,5),array_slice($opening,0,5));
			$old = $layers['basement.0']['cells'][0];
			$surround = PolygonGeometry::surround(PolygonGeometry::points($old),$opening);
			$layers['basement.0']['cells'][0] = PolygonGeometry::cell($old,$surround[0]);
			foreach(array_slice($surround,1) as $i=>$polygon)
			$layers['basement.0']['cells'][] = PolygonGeometry::cell(array_replace($old,['id'=>'basement.0.pool-'.$i]),$polygon);
			$layers['basement.0']['cells'][] = PolygonGeometry::cell(array_replace($old,['id'=>'basement.0.arrival']),$opening);
		}

		// Reuse the same bounded switch alcove at dry-bottom and water-level landings.
		$switches=[];
		$textures=new \DoomPhonics\Graphics\TextureCatalog(new \DoomPhonics\Archive\Stack(
			new \DoomPhonics\Archive\Archive(file_get_contents(dirname(__DIR__).'/demo/DOOM1.WAD'),'DOOM1.WAD')));
		$poolCells=array_column($layers['basement.0']['cells'],null,'id');
		foreach([
			['fill','basement.0.core',[1024,320],[1,0],0,0,'SW1COMP','SW2S0'],
			['drain','basement.0.core',[1024,448],[1,0],$depth,1,'SW1BRCOM','SW1S0'],
			['fill-high','basement.0.ledge',[1024,944],[1,0],0,0,'SW1COMP','SW2S0'],
			['drain-high','basement.0.ledge',[880,1024],[0,1],$depth,1,'SW1BRCOM','SW1S0']
		] as [$id,$region,$point,$normal,$height,$negative,$texture,$patch])
		{
			if($layout==='organic') $point=array_map(static fn($v)=>(int)($v*1.5),$point);
			$point=[$point[0]+$poolX,$point[1]+$poolY];
			$panel=SwitchRecess::panel($textures,$texture,$switchPanels==='patch' ? $patch : null);
			$switch=SwitchRecess::build($poolCells[$region],$point,$normal,'basement.0.switch-'.$id,
				$texture,47,[200,16,$height,$negative,0],...($panel+['sill'=>$switchPanels==='texture' ? 0 : 24]));
			$layers['basement.0']['cells'][]=$switch['cell'];
			$switches[]=$switch+['id'=>$id,'effects'=>['water'=>$negative ? 'dry' : 'full']];
		}

		foreach($topology as $plane=>$network) foreach($network['history']??[] as $event) $repairs[]=$event+['layer'=>$plane,'reason'=>'Conform room ownership to the planar embedding'];
		$falls=$layout==='organic' ? [['upper.0.core','basement.0.arrival']] : [];
		$verticalConnections=$layout==='organic' ? [['from'=>'upper.0.core','to'=>'basement.0.arrival','kind'=>'hub']] : [];

		// Separate ordinary rooms provide shafts; stair rooms, moving lifts, spawn and goal stay clear.
		$sites=new SeedSequence('vertical-portals:'.$seed);
		$available=[];
		foreach(['basement','ground','upper'] as $plane) {
			$available[$plane]=$sites->shuffle(array_keys(array_filter($layers,static fn($r,$n)=>
				$r['plane']===$plane && !isset($r['cluster']) && !isset($r['lift']) && !isset($r['elevation']) && !($r['joinCandidate']??false) && !($r['ledgeCandidate']??false)
				&& $n!=="$plane.0" && !in_array($r['role']??'', ['spawn','goal']) && $n!=='basement.1' && $n!=='upper.'.($rooms-1),ARRAY_FILTER_USE_BOTH)));
		}
		$galleryStack=[];
		foreach(['upper','ground','basement'] as $plane) if(isset($galleryRooms[$plane])) {
			$name=$galleryRooms[$plane];
			$halls=$galleryHalls==='all' || ($galleryHalls==='seeded' && (new SeedSequence('gallery-halls:'.$seed.':'.$plane))->integer(0,1)===1);
			$id=PortalGallery::carve($layers[$name],$name,$plane!=='basement',$halls);
			$galleryStack[]=$id;
			$available[$plane]=array_values(array_diff($available[$plane],[$name]));
		}
		for($i=1;$i<count($galleryStack);$i++) {
			[$from,$to]=[$galleryStack[$i-1],$galleryStack[$i]];
			$falls[]=[$from,$to]; $verticalConnections[]=compact('from','to')+['kind'=>'gallery','width'=>384,'recess'=>32];
		}
		foreach([['ground','basement'],['upper','ground']] as [$above,$below])
		for($i=0;$i<max(1,intdiv($rooms,4))-($galleryStack ? 1 : 0) && $available[$above] && $available[$below];$i++) {
			$a=array_pop($available[$above]); $b=array_pop($available[$below]);
			$beforeA=$layers[$a]; $beforeB=$layers[$b];
			try {
				$from=PlanePortalSite::carve($layers[$a],$a,true);
				$to=PlanePortalSite::carve($layers[$b],$b,false);
			} catch(\InvalidArgumentException $error) {
				$layers[$a]=$beforeA; $layers[$b]=$beforeB;
				$repairs[]=['kind'=>'optional-portal','rooms'=>[$a,$b],'reason'=>$error->getMessage()];
				continue;
			}
			$falls[]=[$from,$to];
			$verticalConnections[]=compact('from','to')+['kind'=>'shaft','width'=>192,'recess'=>32];
		}

		if($layout==='organic' && !$recovery) {
			require_once __DIR__.'/RaisedLedge.php';
			$terraces=[];
			foreach((new SeedSequence('terrace-rooms:'.$seed))->shuffle(array_keys($layers)) as $name) {
				$plane=$layers[$name]['plane'];
				if(!isset($terraces[$plane]) && RaisedLedge::carve($layers[$name],$name,$seed)) $terraces[$plane]=true;
			}
			foreach($layers as $name=>&$layer) if(!isset($layer['terrace'])) RoomFeatures::apply($layer,$name,$seed);
		}
		unset($layer);
		$merged=$layout==='building' ? RoomJoin::apply($layers,$pins,$seed) : [];
		foreach($merged as $join) $repairs[]=['kind'=>'merge','room'=>implode(' + ',$join['rooms']),
			'reason'=>'Remove a short hallway, retaining the offset polygon union'];
		if($layout==='building') self::connectionDoors($layers,$pins,$seed,$doorChance);
		$required = max(array_column(array_filter($layers,static fn($r)=>$r['plane'] === 'ground'),'ceiling')) + self::STOREY_SLAB;
		if($steps * 16 < $required)
		{
			$newSteps = (int)ceil($required / 16);
			$repairs[] = ['kind'=>'layer-height','layer'=>'upper','from'=>$steps*16,'to'=>$newSteps*16,
				'reason'=>'Keep a solid slab above the tallest ground-floor room'];
			$steps = $newSteps;
		}
		$layers['spiral'] = SpiralStaircase::build(steps:$steps,sweep:$stairSweep,mirror:$mirror,headroom:self::HEADROOM+64) + ['plane'=>'connector'];
		foreach($layers['spiral']['cells'] as &$cell) $cell['id'] = 'spiral.' . $cell['id'];
		unset($cell);
		$positions = SpatialLayout::solve($layers,$pins,'ground.0',[0,0,0]);
		// Keep each planar network physically contiguous; only storey links are portals.
		$packing = [];
		foreach(['basement'=>[0,0],'ground'=>[0,0],'upper'=>[0,16384],'connector'=>[16384,16384]] as $plane => [$px,$py])
		{
			$members = array_keys(array_filter($layers,static fn($r)=>$r['plane'] === $plane));
			$packing[$plane] = [$px-min(array_map(static fn($n)=>$positions[$n][0],$members)),
				$py-min(array_map(static fn($n)=>$positions[$n][1],$members))];
			// Ground rooms pack into negative X, separated from the basement and
			// upper plans. The emitted vertices are checked against WAD coordinate limits.
			if($plane === 'ground')
			$packing[$plane][0]=-1024-max(array_map(static fn($n)=>$positions[$n][0]+max(array_column($layers[$n]['cells'],'x1')),$members));
		}
		$cells = [];
		foreach(array_keys($layers) as $index => $name)
		{
			$layers[$name]['architectural'] = $positions[$name];
			[$px,$py] = $packing[$layers[$name]['plane']];
			$layers[$name]['packed'] = [$positions[$name][0]+$px,$positions[$name][1]+$py,$positions[$name][2]];
			$layers[$name]['outline'] = RoomOutline::edges($layers[$name]['cells']);
			foreach($layers[$name]['cells'] as $cell)
			{
				[$x,$y,$z] = $layers[$name]['packed'];
				$cell = PolygonGeometry::translate($cell,$x,$y,$z);
				$cells[] = $cell + ['layer'=>$name,'alignTextures'=>true];
			}
		}
		$boundaries = $connections = $lifts = $doors = [];
		foreach($layers as $layer)
		foreach(array_merge($layer['cluster']['doors'] ?? [],$layer['doors'] ?? []) as $door)
		{
			[$x,$y] = $layer['packed'];
			foreach($door['faces'] as &$face)
			{
				foreach(['from','to','point'] as $key) { $face[$key][0]+=$x; $face[$key][1]+=$y; }
				// Neighbors precede the door sector, so each usable front faces outwards.
				$boundaries[]=['from'=>$face['from'],'to'=>$face['to'],'action'=>12,'args'=>[0,16,150,0,0],
					'flags'=>1540,'upperTexture'=>$door['texture'],'xOffset'=>$face['xOffset'],'yOffset'=>0,'upperUnpegged'=>false,'lowerUnpegged'=>true];
			}
			unset($face);
			$doors[]=$door;
		}
		foreach($layers as $name => $layer)
		if(isset($layer['lift']))
		{
			$lift = $layer['lift']; [$x,$y,$z] = $layer['packed'];
			foreach(['lowPoint','platformPoint','highPoint'] as $key)
			$lift[$key] = SpatialLayout::point($lift[$key],[$x,$y,$z]);
			foreach($lift['boundaries'] as $boundary)
			{
				foreach(['from','to'] as $key) { $boundary[$key][0] += $x; $boundary[$key][1] += $y; }
				$boundaries[] = $boundary;
			}
			unset($lift['boundaries']);
			$lifts[] = $lift;
		}
		$lineId = 1;
		foreach($pins as [$a,$aa,$b,$ba])
		{
			if($layers[$a]['plane'] === $layers[$b]['plane']) continue;
			$pair = [];
			foreach([[$a,$aa,$lineId,$lineId+1],[$b,$ba,$lineId+1,$lineId]] as [$name,$port,$own,$other])
			{
				$frame = $layers[$name]['anchors'][$port];
				[$x,$y,$z] = SpatialLayout::point($frame['point'],$layers[$name]['packed']);
				[$nx,$ny] = $frame['normal']; $width = $frame['size'][0]; $half = intdiv($width,2);
				$boundaries[] = ['from'=>[$x-$ny*$half,$y+$nx*$half],'to'=>[$x+$ny*$half,$y-$nx*$half],
					'action'=>156,'args'=>[$other,$own,1,0,0],'portal'=>true];
				$pair[] = ['layer'=>$name,'point'=>[$x,$y,$z],'normal'=>$frame['normal'],'width'=>$width,'lineId'=>$own];
			}
			$connections[] = $pair;
			$lineId += 2;
		}
		$regions=[];
		foreach($cells as $id=>$cell) if($cell['traversable'] ?? true) $regions[$cell['id']]=$id;
		$waterLinks = [];
		foreach($connections as [$a,$b])
		$waterLinks[] = [PortalRecess::sector($cells,$a['point'],$a['normal']),PortalRecess::sector($cells,$b['point'],$b['normal'])];
		// The basin describes the connected volume with usable doors open. Closed
		// initial panels must not permanently leave the rooms beyond them dry.
		$floodCells=$cells;
		foreach($doors as $door) {
			$id=$regions[$door['region']]; $floodCells[$id]['ceiling']=$floodCells[$id]['floor']+$door['height']-4;
		}
		$waterSectors = FloodBasin::find($floodCells,$regions['basement.0.core'],0,$waterLinks);
		foreach($waterSectors as $sector) $cells[$sector]['tag'] = 100;
		$model = count($cells);
		$cells[] = self::cell('water-model',18000,16384,18064,16448,-$depth,-$depth,200);
		$cells[$model]['floorTexture'] = $cells[$model]['ceilingTexture'] = 'P3WATER';
		// The basin is bounded by real walls and floors. A control-wall texture
		// would add translucent moving wall copies at wet/dry sector boundaries.
		$boundaries[] = ['from'=>[18000,16384],'to'=>[18000,16448],'action'=>160,'args'=>[100,6,2,166,0],'texture'=>'-'];
		[$x,$y,$z] = $layers['basement.0']['packed'];
		$actions = [];
		foreach($switches as $switch)
		{
			$boundary=$switch['boundary'];
			foreach(['from','to'] as $key) { $boundary[$key][0]+=$x; $boundary[$key][1]+=$y; }
			$boundaries[]=$boundary;
			$control=$switch['control'];
			$control['point']=SpatialLayout::point($control['point'],[$x,$y,$z]);
			$actions[]=$control+['id'=>$switch['id'],'effects'=>$switch['effects'],'panel'=>$switch['cell']['id']];
		}

		$start = 'basement.1.core'; $goal = 'upper.' . ($rooms-1) . '.core';
		if($layout==='organic') foreach($layers as $name=>$layer) {
			if(($layer['role']??null)==='spawn') $start=$layer['cells'][0]['id'];
			if(($layer['role']??null)==='goal') $goal=$layer['cells'][0]['id'];
		}
		$things = [];
		foreach([[$start,1],[$goal,2013]] as [$region,$type])
		{
			$c = $cells[$regions[$region]];
			$center = $c['center'] ?? [($c['x0']+$c['x1'])/2,($c['y0']+$c['y1'])/2];
			$things[] = [(int)round($center[0]),(int)round($center[1]),0,0,$type];
		}
		$map = 'SEEDED'; $controls = [];
		foreach($falls as $i=>[$from,$to])
		{
			$tag=110+$i*2;
			foreach([[$from,$tag,0,0],[$to,$tag,0,1],
				[$to,$tag+1,1,0],[$from,$tag+1,1,1]] as [$name,$tag,$plane,$reference])
			{
				$cell=$cells[$regions[$name]]; [$cx,$cy]=$cell['center'];
				$controls[]=['sector'=>$regions[$name],'from'=>[(int)$cx-16,(int)$cy],
					'to'=>[(int)$cx+16,(int)$cy],'args'=>[$tag,6,$plane,$reference,0]];
				if(!$reference) $controls[]=['sector'=>$regions[$name],'from'=>[(int)$cx-16,(int)$cy+16],
					'to'=>[(int)$cx+16,(int)$cy+16],'args'=>[0,1,$plane,$tag,0]];
			}
		}
		$layoutDone = hrtime(true);
		$lumps = PolygonMap::build($map,$cells,$boundaries,$controls,$things);
		$wadDone = hrtime(true);
		$result = compact('map','seed','rooms','layout','merged','layers','pins','positions','topology','repairs','cells','regions','connections','verticalConnections','actions','model','lumps','depth','waterSectors','start','goal','falls','lifts','doors','portalDoors','switchPanels','doorChance','galleryHalls','galleryStack');
		$result['waterStates']=['dry'=>-$depth,'shallow'=>-intdiv($depth,2),'full'=>0];
		$result['roles']=[]; foreach($layers as $name=>$layer) if(isset($layer['role'])) $result['roles'][$layer['role']][]=$name;
		$result['overlaps'] = self::overlaps($layers);
		if($result['overlaps']) throw new \LogicException('Generator reservations overlap: ' . json_encode($result['overlaps']));
		$graph = MapConnectivity::compile($result,['drop'=>512]);
		$checks = [];
		foreach(['dry','shallow','full'] as $water)
		{
			$check = $graph->search($start,['water'=>$water]);
			if(count($check['reachable']) !== count($regions) || $check['traps'])
			throw new \LogicException('Generator movement invariant failed ['.$seed.','.$rooms.']: ' . json_encode(['unreachable'=>array_diff(array_keys($regions),$check['reachable']),'traps'=>array_values(array_unique(array_column($check['traps'],'region'))),'water'=>$water]));
			$checks[$water] = ['reachable'=>count($check['reachable']),'states'=>$check['states'],'traps'=>count($check['traps'])];
		}
		foreach(['dry','shallow','full'] as $water) {
			$outbound=$graph->search($start,['water'=>$water])['routes'][$goal];
			$endState=$outbound ? end($outbound)['state']['water'] : $water;
			$return=$graph->search($goal,['water'=>$endState])['routes'][$start];
			RouteTraversal::run(MapConnectivity::level($result),$result,array_merge($outbound,$return),$water);
		}
		$result['checks'] = $checks;
		$result['graph'] = $graph;
		$result['roomNavigation'] = MapConnectivity::rooms($layers,$graph);
		foreach($result['roomNavigation'] as $name=>$navigation) {
			if(!in_array($layers[$name]['role']??null,['room','spawn','goal'],true)) continue;
			foreach($navigation['routes'] as $water=>$entrances) foreach($entrances as $from=>$exits) foreach($exits as $to=>$route)
			if($route===null) throw new \LogicException("Disconnected room entrances in $name ($water): $from -> $to");
		}
		$finished = hrtime(true);
		$result['timings'] = ['layoutMs'=>($layoutDone-$started)/1e6,'wadMs'=>($wadDone-$layoutDone)/1e6,
			'validationMs'=>($finished-$wadDone)/1e6,'totalMs'=>($finished-$started)/1e6];
		return $result;
	}

	/** List intersecting architectural air volumes, excluding touching shared boundaries. */
	public static function overlaps(array $layers): array
	{
		$volumes = $overlaps = [];
		foreach($layers as $name => $layer)
		foreach($layer['cells'] as $cell)
		{
			[$x,$y,$z] = $layer['architectural'];
			$volume = [$cell['x0']+$x,$cell['y0']+$y,$cell['floor']+$z,$cell['x1']+$x,$cell['y1']+$y,$cell['ceiling']+$z];
			$polygon=PolygonGeometry::points(PolygonGeometry::translate($cell,$x,$y,$z));
			foreach($volumes as [$other,$bounds,$otherPolygon])
			{
				if($other === $name) continue;
				for($axis=0;$axis<3;$axis++) if(max($bounds[$axis],$volume[$axis]) >= min($bounds[$axis+3],$volume[$axis+3])) continue 2;
				if(!PolygonGeometry::overlaps($polygon,$otherPolygon)) continue;
				$overlaps[] = [$other,$name];
			}
			$volumes[] = [$name,$volume,$polygon];
		}
		return $overlaps;
	}

	/** Build an irregular core and passages whose floors never reverse direction. */
	private static function room(string $name,array $ports,int $floor,int $ceiling,int $inset,
		array $passages,int $variant=0,bool $building=false,int $liftTag=0,?string $clusterKind=null,bool $withDoors=false,int $size=self::ROOM_SIZE,string $seed='',int $doorChance=0,?array $dimensions=null,bool $gallery=false,?array $footprint=null,?array $elevation=null): array
	{
		$dimensions ??= [$size,$size];
		[$spanX,$spanY]=$dimensions;
		$cx=intdiv($spanX,2); $cy=intdiv($spanY,2);
		$cluster = match($clusterKind) {
			'conforming'=>RoomCluster::packed($name,$floor,$ceiling,$variant,$seed,$doorChance),
			'circular'=>RoomCluster::circular($name,$floor,$ceiling,$withDoors,90*(1+(new SeedSequence('arc:'.$seed))->integer(0,2)),2*(new SeedSequence('arc-start:'.$seed))->integer(0,3)),default=>null
		};
		$footprint ??= RoomOutline::footprint($name,[$spanX,$spanY],$inset,$seed,$building,$gallery,$liftTag ? array_key_first($ports) : null);
		$cells=$cluster['cells'] ?? [PolygonGeometry::cell(self::cell("$name.core",0,0,1,1,$floor,$ceiling),$footprint['polygon'])];
		$body=$cells[0];
		if(($elevation['kind']??null)==='corner') $cells[0]['floor']=min($elevation['from'],$elevation['to']);
		elseif($elevation) {
			$cells=RoomElevation::cells($body,$elevation);
			$elevation['route']=array_column($cells,'id');
		}
		$anchors = []; $lift = null; $corridors = [];
		foreach($ports as $direction => $width)
		{
			$entryFloor=$elevation['floors'][$direction]??$floor;
			$passage = $passages[$direction]; $height = $passage['height']; $roof = $passage['ceiling'];
			$size=in_array($direction,['east','west'],true) ? $spanX : $spanY;
			$center=in_array($direction,['east','west'],true) ? $cy : $cx;
			$inset=$cluster ? 256 : $footprint['approaches'][$direction];
			$half = intdiv($width,2); $lo = $center-$half; $hi = $center+$half;
			[$dx,$dy] = PlanarRooms::DIRECTIONS[$direction];
			$point = [$cx+$dx*$spanX/2,$cy+$dy*$spanY/2,$height];
			$point=array_map(static fn($v)=>(int)$v,$point);
			$anchors[$direction] = ['point'=>$point,'normal'=>[$dx,$dy,0],'size'=>[$width,$roof-$height]];
			// u runs out from the room wall; v is the transverse coordinate.
			$rect = static function(int $u0,int $v0,int $u1,int $v1) use ($direction,$inset,$size): array {
				return match($direction) {
					'east'=>[$size-$inset+$u0,$v0,$size-$inset+$u1,$v1],
					'west'=>[$inset-$u1,$v0,$inset-$u0,$v1],
					'north'=>[$v0,$size-$inset+$u0,$v1,$size-$inset+$u1],
					'south'=>[$v0,$inset-$u1,$v1,$inset-$u0]
				};
			};
			$parts = [];
			if($liftTag)
			{
				// Both landings keep the raised floor's headroom, even while the lift is up.
				$platform = $rect(0,$lo,128,$hi); $low = $rect(128,$lo,$inset,$hi);
				$cells[] = self::cell("$name.approach",... [...$low,$height,$roof]);
				$cells[] = self::cell("$name.lift",... [...$platform,$floor,$roof,$liftTag]);
				$ex=$point[0]-$dx*$inset; $ey=$point[1]-$dy*$inset;
				$lx=$ex+$dx*128; $ly=$ey+$dy*128;
				$boundaries = [];
				foreach([[$ex,$ey],[$lx,$ly]] as [$fx,$fy])
				$boundaries[] = ['from'=>[$fx-$dy*$half,$fy+$dx*$half],'to'=>[$fx+$dy*$half,$fy-$dx*$half],
					'action'=>206,'args'=>[$liftTag,32,105,0,0],'flags'=>1540,'lowerTexture'=>'SUPPORT2'];
				$highPoint = [$ex-$dx*32,$ey-$dy*32,$floor];
				$highRegion = array_values(array_filter($cells,static fn($c)=>
					$highPoint[0]>$c['x0'] && $highPoint[0]<$c['x1'] && $highPoint[1]>$c['y0'] && $highPoint[1]<$c['y1']))[0]['id'];
				$lift = ['low'=>"$name.approach",'platform'=>"$name.lift",'high'=>$highRegion,
					'lowPoint'=>[$lx+$dx*32,$ly+$dy*32,$height],
					'platformPoint'=>[$ex+$dx*64,$ey+$dy*64,$floor],
					'highPoint'=>$highPoint,
					'angle'=>atan2(-$dy,-$dx),'boundaries'=>$boundaries];
				continue;
			}
			$bend = $passage['bend'] ?? 0;
			if($bend)
			{
				$turns = [[0,$lo+$bend,64,$hi+$bend],[64,$lo+$bend,128,$hi+$bend],
					[128,min($lo,$lo+$bend),256,max($hi,$hi+$bend)],[256,$lo,320,$hi],[320,$lo,$inset,$hi]];
				foreach($turns as $i=>$bounds)
				{
					$step = $entryFloor + (int)round(($height-$entryFloor)*($i+1)/count($turns)/16)*16;
					$parts[] = self::cell("$name.$direction.$i",... [...$rect(...$bounds),$step,$roof]);
				}
			}
			else
			{
				$count = max(1,intdiv(abs($height-$entryFloor),16));
				for($i=0;$i<$count;$i++)
				{
					$u0 = $i*64; $u1 = $i === $count-1 ? $inset : ($i+1)*64;
					$step = $entryFloor + ($height <=> $entryFloor)*16*($i+1);
					if($entryFloor === $height) $step = $entryFloor;
					$parts[] = self::cell("$name.$direction.$i",... [...$rect($u0,$lo,$u1,$hi),$step,$roof]);
				}
			}
			if(!$cluster) {
				$first=array_shift($parts); $bend=$passage['bend']??0;
				$run=$dx ? $first['x1']-$first['x0'] : $first['y1']-$first['y0'];
				$extension=self::cell($first['id'],... [...$rect(-(int)(($dx ? $body['x1']-$body['x0'] : $body['y1']-$body['y0'])/2),$lo+$bend,(int)$run,$hi+$bend),$first['floor'],$first['ceiling']]);
				$pieces=PolygonGeometry::mergeConvex(PolygonGeometry::surround(PolygonGeometry::points($extension),$body['polygon']));
				$prefix=[];
				foreach($pieces as $i=>$p) $prefix[]=PolygonGeometry::cell(array_replace($first,['id'=>$first['id'].($i ? ".join-$i" : '')]),$p);
				$parts=array_merge($prefix,$parts);
			}

			$corridors[$direction] = ['from'=>$entryFloor,'to'=>$height,'bend'=>$bend,'length'=>$inset+abs($bend),
				'width'=>$width,'cells'=>array_column($parts,'id')];
			array_push($cells,...$parts);
		}
		$ceiling=max($ceiling,...array_column($cells,'ceiling'));
		$result=compact('cells','anchors','ceiling','corridors','footprint') + ($cluster ? ['cluster'=>array_diff_key($cluster,['cells'=>true])] : []) + ($lift ? ['lift'=>$lift] : []);
		if(($elevation['kind']??null)==='corner') {
			if(!CornerStaircase::carve($result,$name,$elevation)) throw new \LogicException('Reserved corner stair no longer fits');
			$elevation['route']=[$name.'.core',...$result['cornerStair']['route'],$name.'.ledge'];
		}
		return $result+($elevation ? ['elevation'=>$elevation] : []);
	}

	/** Add at most one door per physical connection, using an existing flat landing. */
	private static function connectionDoors(array &$layers,array $pins,string $seed,int $chance): void
	{
		foreach($pins as [$a,$aa,$b,$ba])
		{
			if(!isset($layers[$a],$layers[$b]) || $layers[$a]['plane']!==$layers[$b]['plane']
				|| !in_array($layers[$a]['anchors'][$aa]['size'][0],[64,128],true)) continue;
			if(!DoorRecess::selected($seed,"$a:$aa/$b:$ba",$chance)) continue;
			foreach([[$a,$aa,$b,$ba],[$b,$ba,$a,$aa]] as [$name,$port,$other,$otherPort])
			{
				$layer=&$layers[$name]; $corridor=$layer['corridors'][$port]??null;
				// Lift caller regions and short stair/dogleg landings keep their geometry.
				if(!$corridor || isset($layer['lift'])) { unset($layer); continue; }
				$ids=$corridor['cells']; $last=end($ids); $byId=array_column($layer['cells'],null,'id');
				$cell=$byId[$last]; $anchor=$layer['anchors'][$port]; [$nx,$ny]=$anchor['normal'];
				$length=$nx ? $cell['x1']-$cell['x0'] : $cell['y1']-$cell['y0'];
				if(($corridor['from']!==$corridor['to'] && $length<384) || $length<128 || ($length>128 && $length<168)) { unset($layer); continue; }
				[$x,$y]=$anchor['point']; $id="$name.$port.door";
				$p=[];
				$half=intdiv($anchor['size'][0],2);
				foreach([[-128,-$half],[-128,$half],[0,$half],[0,-$half]] as [$u,$v])
				$p[]=[$x+$nx*$u-$ny*$v,$y+$ny*$u+$nx*$v];
				$passage=PolygonGeometry::cell(array_replace($cell,['id'=>$id]),$p);
				$kept=[];
				foreach($layer['cells'] as $c)
				if($c['id']!==$last) $kept[]=$c;
				elseif($length>128)
				{
					$kept[]=PolygonGeometry::cell($c,PolygonGeometry::clip(PolygonGeometry::points($c),$nx,$ny,$nx*$x+$ny*$y-128));
				}
				$adjacent=[];
				// Resolve the actual room/tread touching the inner lintel, not just its envelope.
				foreach($kept as $c)
				if(PolygonGeometry::shared(PolygonGeometry::points($c),[$p[0],$p[1]])) { $adjacent[]=$c['id']; break; }
				$otherAnchor=$layers[$other]['anchors'][$otherPort]['point'];
				foreach($layers[$other]['cells'] as $c)
				if(PolygonGeometry::contains(PolygonGeometry::points($c),$otherAnchor[0]+$nx*18,$otherAnchor[1]+$ny*18))
				{ $adjacent[]=$c['id']; break; }
				if(count($adjacent)!==2) throw new \LogicException('Door reservation has no flat landing neighbor');
				$recess=DoorRecess::build($passage,$adjacent);
				array_push($kept,...$recess['cells']); $layer['cells']=$kept;
				$layer['doors'][]=$recess['door']+['kind'=>'connection','connection'=>[$a,$b]];
				if($length===128) array_pop($ids);
				array_push($ids,"$id.inner",$id,"$id.outer");
				$layer['corridors'][$port]['cells']=$ids;
				unset($layer);
				break;
			}
		}
	}

	/** Give the basement hub a flood-dependent ledge and two reachable pump switches. */
	private static function cavern(array $ports,int $depth,array $passages,int $size=1280,?array $dimensions=null): array
	{
		$passages['north']['height'] = 0;
		$room = self::room('basement.0',$ports,0,$depth+self::HEADROOM+64,$size===1920?384:256,$passages,0,true,size:$size);
		$room['anchors']['north']['point'][2] = $depth;
		$room['anchors']['north']['size'][1] = self::HEADROOM+64;
		// The north arm starts on the high ledge, not on the pool bottom.
		$room['cells'] = array_values(array_filter($room['cells'],static fn($c)=>!str_starts_with($c['id'],'basement.0.north')));
		if($size===1920) {
			$roof=$depth+self::HEADROOM+64;
			$room['cells'][0]=self::cell('basement.0.core',576,384,1536,1152,0,$roof);
			$room['cells'][]=self::cell('basement.0.wing',384,576,576,1152,0,$roof);
			$room['cells'][]=self::cell('basement.0.pool-north',768,1152,960,1344,0,$roof);
			$room['cells'][]=self::cell('basement.0.ledge-west',384,1152,768,1344,$depth,$roof);
			$room['cells'][]=self::cell('basement.0.ledge-east',960,1152,1536,1344,$depth,$roof);
			$room['cells'][]=self::cell('basement.0.ledge',384,1344,1536,1536,$depth,$roof);
			$room['cells'][]=self::cell('basement.0.north.0',896,1536,1024,1920,$depth,$roof);
			$room['corridors']['north']=['from'=>$depth,'to'=>$depth,'bend'=>0,'length'=>384,'width'=>128,'cells'=>['basement.0.north.0']];
			$room['ledges']=['basement.0.ledge-west','basement.0.ledge-east'];
			return self::extendBay($room,$size,$dimensions ?? [$size,$size]);
		}
		$room['cells'][0] = self::cell('basement.0.core',384,256,1024,896,0,$depth+self::HEADROOM+64);
		$room['cells'][] = self::cell('basement.0.wing',256,384,384,896,0,$depth+self::HEADROOM+64);
		$room['cells'][] = self::cell('basement.0.ledge',256,896,1024,1024,$depth,$depth+self::HEADROOM+64);
		$room['cells'][] = self::cell('basement.0.north.0',576,1024,704,1280,$depth,$depth+self::HEADROOM+64);
		$room['corridors']['north'] = ['from'=>$depth,'to'=>$depth,'bend'=>0,'length'=>256,'width'=>128,'cells'=>['basement.0.north.0']];
		return self::extendBay($room,$size,$dimensions ?? [$size,$size]);
	}

	/** Extend a fixed hub's outer passages when neighboring stairs enlarge its shared bay. */
	private static function extendBay(array $room,int $size,array $dimensions): array
	{
		[$w,$h]=$dimensions; $ox=intdiv($w-$size,2); $oy=intdiv($h-$size,2);
		$room['contentOffset']=[$ox,$oy];
		if(!$ox && !$oy) return $room;
		foreach($room['cells'] as &$cell) $cell=PolygonGeometry::translate($cell,$ox,$oy,0);
		unset($cell);
		foreach($room['anchors'] as $port=>&$anchor) {
			[$nx,$ny]=$anchor['normal']; $run=$nx ? $ox : $oy;
			$anchor['point'][0]+=$ox+$nx*$run; $anchor['point'][1]+=$oy+$ny*$run;
			if(!$run) continue;
			[$x,$y,$z]=$anchor['point']; [$width,$headroom]=$anchor['size']; $half=intdiv($width,2);
			$id='basement.0.'.$port.'.extension';
			$room['cells'][]=self::cell($id,$nx ? min($x,$x-$nx*$run) : $x-$half,
				$ny ? min($y,$y-$ny*$run) : $y-$half,$nx ? max($x,$x-$nx*$run) : $x+$half,
				$ny ? max($y,$y-$ny*$run) : $y+$half,$z,$z+$headroom);
			$room['corridors'][$port]['cells'][]=$id;
			$room['corridors'][$port]['length']+=$run;
		}
		unset($anchor);
		return $room;
	}

	/** Supply common sector fields without hiding local floor and ceiling heights. */
	private static function cell(string $id,int $x0,int $y0,int $x1,int $y1,int $floor,int $ceiling,int $tag=0): array
	{
		return compact('id','x0','y0','x1','y1','floor','ceiling','tag')
			+ ['light'=>192,'floorTexture'=>'FLOOR0_1','ceilingTexture'=>'CEIL3_5'];
	}
}
