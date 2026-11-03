<?php
declare(strict_types=1);

/** Build a separate seeded PWAD, browser world and interactive navigation report. */
require_once dirname(__DIR__,3) . '/autoload.php';
require_once dirname(__DIR__) . '/autoload.php';
require_once __DIR__ . '/SeededLevel.php';
require_once __DIR__ . '/WaterFlat.php';

use DoomPhonics\Tools\{SeededLevel,RectangularMap,WaterFlat};

$started = hrtime(true);
$options = ['seed'=>'first-light','rooms'=>'14','layout'=>'organic','portal-doors'=>'seeded','switch-panels'=>'patch','door-chance'=>'30','gallery-halls'=>'seeded','timings'=>'','output'=>dirname(__DIR__) . '/demo'];
foreach(array_slice($argv,1) as $argument)
{
	if($argument === '--help')
	{
		fwrite(STDOUT,"Usage: php tools/generate-seeded.php [--seed=TEXT] [--rooms=2..16] [--layout=organic|building] [--portal-doors=seeded|open|doors] [--door-chance=0..100] [--gallery-halls=seeded|all|none] [--switch-panels=patch|texture] [--output=DIRECTORY] [--timings=FILE.json]\n"
			. "Outputs SEEDED.WAD, seeded-level.phonics-world.json and seeded-navigation.html.\n"
			. "Prints phase timings; --timings saves a separate JSON measurement.\n"
			. "The browser world expects DOOM1.WAD beside it and ../phonics.json.\n");
		exit(0);
	}
	if(!preg_match('/^--(seed|rooms|layout|portal-doors|switch-panels|door-chance|gallery-halls|output|timings)=(.*)$/sD',$argument,$match))
	throw new InvalidArgumentException('Unknown argument: ' . $argument);
	$options[$match[1]] = $match[2];
}
if(!preg_match('/^(?:[2-9]|1[0-6])$/D',$options['rooms'])) throw new InvalidArgumentException('Use 2–16 rooms per layer');
if(!preg_match('/^(?:[0-9]|[1-9][0-9]|100)$/D',$options['door-chance'])) throw new InvalidArgumentException('Door chance must be 0–100');
if($options['output'] === '') throw new InvalidArgumentException('Output directory cannot be empty');
$prototype = SeededLevel::build($options['seed'],(int)$options['rooms'],$options['layout'],$options['portal-doors'],$options['switch-panels'],(int)$options['door-chance'],$options['gallery-halls']);
$metadata = ['generator'=>'phonics-layered','version'=>SeededLevel::VERSION,'seed'=>$options['seed'],'roomsPerLayer'=>(int)$options['rooms'],'layout'=>$options['layout'],'portalDoors'=>$options['portal-doors'],'switchPanels'=>$options['switch-panels'],'doorChance'=>(int)$options['door-chance'],'galleryHalls'=>$options['gallery-halls']];
$metadata['requestedCandidates']=3*(int)$options['rooms'];
$metadata['survivingRooms']=array_sum(array_map(static fn($n)=>$n['surviving']??count($n['rooms']),$prototype['topology']));
$metadata['waterStates']=$prototype['waterStates'];
$wad = RectangularMap::archive(['F_START'=>'','P3WATER'=>WaterFlat::bytes(),'F_END'=>'',
	'GENINFO'=>json_encode($metadata,JSON_THROW_ON_ERROR)] + $prototype['lumps']);
$world = json_decode(file_get_contents(dirname(__DIR__) . '/demo/layered-navigation.phonics-world.json'),true,512,JSON_THROW_ON_ERROR);
$source = $world['sources']['layered-navigation'];
$source['resources'][1]['url'] = './SEEDED.WAD';
$source['resources'][1]['integrity'] = 'sha256-' . base64_encode(hash('sha256',$wad,true));
$world['sources'] = ['seeded-level'=>$source];
$world['bindings'][0]['id'] = 'seeded-level-gameplay';
$world['bindings'][0]['on']['source'] = 'seeded-level';
$world['start'] = ['source'=>'seeded-level','scope'=>'SEEDED'];
$graph = $prototype['graph'];
$report = $metadata + array_intersect_key($prototype,array_flip([
	'cells','layers','positions','topology','repairs','checks','start','goal','depth','model','lifts','doors','connections','verticalConnections','falls','actions','merged','galleryStack','roomNavigation','roles','recovery'
]));
$report['outlines'] = [];
foreach(['basement','ground','upper','connector'] as $plane)
{
	$floorCells = [];
	foreach($prototype['layers'] as $name=>$layer)
	if($layer['plane'] === $plane)
	foreach($layer['cells'] as $cell)
	{
		$cell = DoomPhonics\Tools\PolygonGeometry::translate($cell,...$layer['architectural']);
		$floorCells[] = $cell;
	}
	$report['outlines'][$plane] = DoomPhonics\Tools\RoomOutline::edges($floorCells);
}
$report['regions'] = $graph->regions;
$report['sectorIds'] = $prototype['regions'];
$report['edges'] = $graph->edges;
$report['goalRoute'] = $graph->search($prototype['start'],['water'=>'dry'])['routes'][$prototype['goal']];
$json = json_encode($report,JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$html = str_replace('__REPORT_DATA__',$json,file_get_contents(__DIR__ . '/seeded-report.html'));
$destination = $options['output'];
if(!is_dir($destination) && !mkdir($destination,0777,true)) throw new RuntimeException('Cannot create output directory');
foreach(['SEEDED.WAD'=>$wad,'seeded-level.phonics-world.json'=>json_encode($world,JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
	'seeded-navigation.html'=>$html] as $name=>$bytes)
if(file_put_contents($destination . '/' . $name,$bytes) !== strlen($bytes)) throw new RuntimeException('Cannot write ' . $name);
fwrite(STDOUT,'Generated ' . $destination . '/SEEDED.WAD: ' . json_encode($options['seed']) . ', '
	. $metadata['requestedCandidates'] . ' requested candidates, ' . $metadata['survivingRooms'] . ' surviving logical rooms, ' . count($graph->regions) . ' traversable regions, '
	. count($prototype['doors']) . ' doors, ' . count($prototype['verticalConnections']) . ' vertical portal pairs, ' . count($prototype['lifts']) . ' lifts, ' . count($prototype['repairs']) . " repairs; no unreachable regions or traps.\n");

$generation = $prototype['timings'];
$elapsed = (hrtime(true)-$started)/1e6;
$measurement = $metadata + ['timings'=>['layoutMs'=>$generation['layoutMs'],'wadMs'=>$generation['wadMs'],
	'validationMs'=>$generation['validationMs'],'recoveryMs'=>$generation['recoveryMs'],'outputMs'=>max(0,$elapsed-$generation['totalMs']),'totalMs'=>$elapsed],
	'regions'=>count($graph->regions),'wadBytes'=>strlen($wad),'peakMemoryBytes'=>memory_get_peak_usage(true)];
fwrite(STDOUT,sprintf("Time: %.2f ms total (layout %.2f, WAD/BSP %.2f, validation %.2f, recovery %.2f, output %.2f ms).\n",
	$elapsed,$generation['layoutMs'],$generation['wadMs'],$generation['validationMs'],$generation['recoveryMs'],$measurement['timings']['outputMs']));
if($options['timings'] !== '')
{
	$path = $options['timings'];
	if(in_array(realpath($path),array_map('realpath',[$destination.'/SEEDED.WAD',$destination.'/seeded-level.phonics-world.json',
		$destination.'/seeded-navigation.html']),true)) throw new InvalidArgumentException('Timing output must be a separate file');
	$bytes = json_encode($measurement,JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
	if(file_put_contents($path,$bytes)!==strlen($bytes)) throw new RuntimeException('Cannot write timing report');
}
