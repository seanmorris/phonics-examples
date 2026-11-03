<?php
declare(strict_types=1);

/** Generate a self-contained, interactive report of the bounded LAYERS prototype. */
require_once dirname(__DIR__,3) . '/autoload.php';
require_once dirname(__DIR__) . '/autoload.php';
require_once __DIR__ . '/LayeredPrototype.php';
require_once __DIR__ . '/MapConnectivity.php';

use DoomPhonics\Tools\{LayeredPrototype,MapConnectivity};

$prototype = LayeredPrototype::build();
$graph = MapConnectivity::compile($prototype);
$cases = [];
foreach(['basement','courtyard'] as $start)
foreach(['dry','shallow','full'] as $water)
foreach([false,true] as $actions)
{
	$result = $graph->search($start,['water' => $water],$actions);
	$cases[] = ['start' => $start,'water' => $water,'actions' => $actions,
		'reachable' => $result['reachable'],'states' => $result['states'],
		'traps' => array_map(static fn($trap) => ['region' => $trap['region'],'state' => $trap['state']],$result['traps']),
		'route' => $result['routes']['balcony'] ?? null];
}
$report = ['schemaVersion' => 1,'map' => 'LAYERS','depth' => $prototype['depth'],
	'layers' => $prototype['layers'],'pins' => $prototype['pins'],
	'regions' => $graph->regions,'edges' => $graph->edges,'cases' => $cases,
	'scope' => 'Empty convex rectangular regions, translation-only pins, one liquid model, settled water states. '
		. 'These are conditional reachability checks, not a general polygon/navmesh generator or timed fluid simulation.'];
$destination = $argv[1] ?? dirname(__DIR__) . '/demo/layered-navigation.html';
$json = json_encode($report,JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$html = str_replace('__REPORT_DATA__',$json,file_get_contents(__DIR__ . '/layered-report.html'));
if(file_put_contents($destination,$html) !== strlen($html)) throw new RuntimeException("Cannot write $destination");
fwrite(STDOUT,"Generated $destination (" . count($graph->regions) . ' regions, ' . count($graph->edges) . " directed transitions).\n");
