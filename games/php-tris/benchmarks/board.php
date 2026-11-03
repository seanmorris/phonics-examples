<?php
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/autoload.php';
require_once dirname(__DIR__) . '/autoload.php';

use PhpTris\Component\Board;
use PhpTris\Rules;
use PhpTris\Shapes;

$results = [];

foreach(['cascade', 'targets', 'bonds', 'reactor'] as $mode)
{
	$board = new Board;

	for($y = 20; $y < 40; $y++)
	{
		for($x = 0; $x < 10; $x++)
		{
			if(($x + $y) % 7) $board->put($x, $y, ($x + $y) % 7);
		}
	}

	$source = $board->cells;
	$samples = [];

	for($sample = 0; $sample < 101; $sample++)
	{
		$board->cells = $source;
		$start = hrtime(true);
		Rules::fall($board, $mode);
		Rules::rows($board);

		if($mode === 'bonds') Rules::mass($board);

		if($mode === 'reactor') Rules::connect($board);

		for($rotation = 0; $rotation < 4; $rotation++)
		{
			Shapes::cells('T', $rotation);
			$board->fits('T', $rotation, 3, 18);
		}

		if($sample) $samples[] = (hrtime(true) - $start) / 1e6;
	}

	sort($samples);
	$results[$mode] = ['cells' => count($source), 'medianMs' => $samples[50], 'p95Ms' => $samples[95]];
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
