<?php
declare(strict_types=1);

use PhpBilliards\Geometry\MeshBuilder;
use PhpBilliards\Geometry\TableGeometry;
use PhpBilliards\Physics\Ball;
use PhpBilliards\Physics\Table;

$root = dirname(__DIR__);
require_once $root . '/games/php-billiards/autoload.php';
$output = $root . '/games/php-billiards/assets/generated';
$check = in_array('--check', $argv, true);
$binary = '';
$manifest = [
	'schemaVersion' => 1
	, 'vertexStride' => 32
	, 'geometry' => [
		'Table::WIDTH' => Table::WIDTH
		, 'Table::HEIGHT' => Table::HEIGHT
		, 'Table::CORNER_MOUTH' => Table::CORNER_MOUTH
		, 'Table::SIDE_MOUTH' => Table::SIDE_MOUTH
		, 'Table::CUSHION_WIDTH' => Table::CUSHION_WIDTH
		, 'Table::THROAT_RADIUS' => Table::THROAT_RADIUS
		, 'Table::LINER_RADIUS' => Table::LINER_RADIUS
		, 'Ball::RADIUS' => Ball::RADIUS
		, 'mesher' => [TableGeometry::COLUMNS,TableGeometry::ROWS,TableGeometry::CUSHION_TOP_SEGMENTS,TableGeometry::POCKET_SEGMENTS]
	]
	, 'meshes' => []
];

foreach(MeshBuilder::all() as $name => $mesh)
{
	$mesh->validate();
	$vertexOffset = strlen($binary);
	$binary .= $mesh->vertices;
	$indexOffset = strlen($binary);
	$binary .= $mesh->indices;
	$manifest['meshes'][$name] = [
		'vertexOffset' => $vertexOffset
		, 'vertexLength' => strlen($mesh->vertices)
		, 'vertexCount' => $mesh->vertexCount
		, 'indexOffset' => $indexOffset
		, 'indexLength' => strlen($mesh->indices)
		, 'indexCount' => $mesh->indexCount
		, 'doubleSided' => $mesh->doubleSided
	];
}

$manifest['byteLength'] = strlen($binary);
$manifest['sha256'] = hash('sha256', $binary);
$compressed = gzencode($binary, 9, ZLIB_ENCODING_GZIP);
$manifest['compressedByteLength'] = strlen($compressed);
$manifest['compressedSha256'] = hash('sha256', $compressed);
$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
$binaryPath = $output . '/meshes.bin.gz';
$manifestPath = $output . '/meshes.json';

if($check)
{
	$stored = is_file($binaryPath) ? file_get_contents($binaryPath) : false;
	// zlib versions may encode identical mesh bytes differently. Check the generated geometry exactly,
	// while checking the committed archive against its own compressed size and checksum.
	if($stored !== false)
	{
		$manifest['compressedByteLength'] = strlen($stored);
		$manifest['compressedSha256'] = hash('sha256', $stored);
		$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
	}
	if($stored === false || @gzdecode($stored) !== $binary
		|| !is_file($manifestPath) || file_get_contents($manifestPath) !== $json
	){
		fwrite(STDERR, "Generated billiards meshes are stale. Run npm run generate. Expected geometry SHA256: {$manifest['sha256']}\n");
		exit(1);
	}

	echo 'Verified ' . count($manifest['meshes']) . " generated billiards meshes\n";
	exit(0);
}

if(!is_dir($output)) mkdir($output, 0777, true);
file_put_contents($binaryPath, $compressed);
file_put_contents($manifestPath, $json);
@unlink($output . '/meshes.bin');
echo 'Generated ' . count($manifest['meshes']) . ' billiards meshes (' . strlen($compressed) . " compressed bytes)\n";
