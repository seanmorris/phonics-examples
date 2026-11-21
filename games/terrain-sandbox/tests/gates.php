<?php
declare(strict_types=1);

// The race gate assets: gates.json against the three gate meshes that scripts/import_terrain_gates.py bakes, each
// measured at its own size as model.vert draws it.

$checks = 0;
function expect(bool $condition, string $message): void
{
	global $checks;
	$checks++;
	if(!$condition) throw new RuntimeException($message);
}

require __DIR__ . '/support/gate-mesh.php';

$root = dirname(__DIR__);
$folder = $root . '/assets/gates/';
$manifest = json_decode(file_get_contents($root . '/phonics.json'), true, flags: JSON_THROW_ON_ERROR);
$gates = json_decode(file_get_contents($folder . 'gates.json'), true, flags: JSON_THROW_ON_ERROR);
$shipped = fn(string $file): bool => in_array("assets/gates/$file", $manifest['application']['assets'], true);
expect($gates['version'] === 1, 'gates.json is version 1');
expect($gates['roles'] === ['start', 'checkpoint', 'finish', 'start-finish'],
	'gates.json lists the four roles in order');
expect(array_keys($gates['kinds']) === ['road', 'water', 'air'], 'gates.json describes the road, water and air gates');
foreach(['finish-line-license.txt', 'arch-license.txt'] as $license)
{
	expect($shipped($license), "The application ships $license");
	expect(str_contains(file_get_contents($folder . $license), 'CC-BY-4.0'), "$license carries the CC-BY-4.0 notice");
}
expect($shipped('gates.json'), 'The application ships gates.json');

foreach($gates['roles'] as $band => $role)
	expect($gates['atlas']['roles'][$role] == [0, $band / 4], "The $role print is band $band of each atlas");

foreach($gates['kinds'] as $name => $kind)
{
	expect($shipped($kind['mesh']), "The application ships the $name mesh");
	// The same limit and checks as BakedMesh, which loads the vehicles and the gates.
	$data = gzdecode(file_get_contents($folder . $kind['mesh']), 4194304);
	expect($data !== false, "The $name mesh decompresses within 4 MiB");
	$header = unpack('Vvertices/Vindices/Vwidth/Vheight', $data);
	$vertexBytes = $header['vertices'] * 28;
	expect($header['vertices'] <= 65536 && $header['indices'] > 0 && $header['indices'] % 3 === 0,
		"The $name mesh has whole triangles");
	expect(strlen($data) === 16 + $vertexBytes + $header['indices'] * 2 + $header['width'] * $header['height'] * 4,
		"The $name mesh has its atlas after the indices");
	expect($header['height'] % 4 === 0, "The $name atlas divides into four role bands");

	$mesh = readGateMesh($folder . $kind['mesh']);
	$textured = $outsideBand = $otherTags = 0;
	foreach($mesh['vertices'] as $vertex)
	{
		$tag = $vertex['tag'];
		if($tag < 128 || $tag >= 224 || !in_array($tag & 63, [GATE_LABEL, GATE_FRAME, GATE_SQUARE, GATE_CAP, GATE_POST,
			GATE_BASE, GATE_ARCH], true) || ($tag & 64) !== (in_array($tag & 63, [GATE_CAP, GATE_POST, GATE_BASE], true) ? 0 : 64))
			$otherTags++;
		elseif($tag & 64)
		{
			$textured++;
			if($vertex['uv'][1] > .25) $outsideBand++;
		}
	}
	expect($textured > 0 && $outsideBand === 0, "Every textured $name vertex samples the first role band");
	expect($otherTags === 0, "Every $name vertex carries a gate part tag, clear of the wheel and hinge tags");
	$corners = $mesh['corners'];
	expect(max($corners) < $header['vertices'], "Every $name index names a vertex");
	// At its own size the mesh draws as baked, except the spare checker columns, which fold into the end of each band.
	$own = ['uGateSize' => [0.0, 0.0, 1.0, 1.0],
		'uGateBoard' => isset($kind['board']) ? [...$kind['board']['label'], ...$kind['board']['column']] : [1e9, 1e9, 1.0, 1.0],
		'uGateColumns' => isset($kind['board']) ? [$kind['board']['columns'], $kind['board']['baked']] : [1.0, 1.0],
		'uGateFeet' => [$kind['posts'][0]['bottom'] ?? 0.0, $kind['posts'][1]['bottom'] ?? 0.0, 0.0, 0.0]];
	$points = [];
	$spares = 0;
	$moved = 0;
	foreach($mesh['vertices'] as $vertex)
	{
		$at = resizeGateVertex($vertex['position'], $vertex['tag'], $own);
		$points[] = $at;
		if($at === $vertex['position']) continue;
		$band = isset($kind['board']) ? $own['uGateBoard'][$at[0] < 0 ? 0 : 1]
			+ $kind['board']['columns'] * $own['uGateBoard'][$at[0] < 0 ? 2 : 3] : INF;
		if(($vertex['tag'] & 63) === GATE_SQUARE && abs(abs($at[0]) - $band) < 1e-6) $spares++;
		elseif(max(array_map(fn($a, $b) => abs($a - $b), $at, $vertex['position'])) > 1e-6) $moved++;
	}
	expect($moved === 0, "At its own size the $name mesh draws where it was baked ($moved vertices move)");
	if(isset($kind['board']))
		expect($spares > 0 && $kind['board']['baked'] > $kind['board']['columns'],
			"The $name mesh folds $spares spare checker vertices into the ends of its bands");

	['window' => $window, 'extent' => $extent] = $kind;
	$low = [min(array_column($points, 0)), min(array_column($points, 1)), min(array_column($points, 2))];
	$high = [max(array_column($points, 0)), max(array_column($points, 1)), max(array_column($points, 2))];
	expect(abs(max(-$low[0], $high[0]) - $extent['halfWidth']) < .001 && abs($high[1] - $extent['top']) < .001
		&& abs(min(0, $low[1]) - $extent['bottom']) < .001 && abs(max(-$low[2], $high[2]) - $extent['halfDepth']) < .001,
		"The $name extent bounds its mesh and reaches down to the window's bottom edge at least");
	expect($window['bottom'] === 0 && $window['halfWidth'] < $extent['halfWidth'] && $window['top'] < $extent['top'],
		"The $name window starts at the origin, inside the extent");

	// Nothing stands in the window. Its lowest 15 centimeters are left out: there the road gate's base plates
	// reach 13 centimeters past the posts, as they do past the source towers.
	$box = [$window['halfWidth'] - .01, ($window['top'] - .15) / 2 - .005, $extent['halfDepth'] + 1];
	$center = [0, .15 + $box[1], 0];
	$blocked = 0;
	foreach(resizeGateMesh($mesh, $own) as $triangle) if(triangleMeetsBox($triangle, $center, $box)) $blocked++;
	expect($blocked === 0, "The $name window is clear of the mesh ($blocked triangles in it)");

	foreach($kind['posts'] as $post)
	{
		expect(abs($post['x']) - $post['halfSize'] >= $window['halfWidth'] - 1e-6,
			"A $name post stands outside the window");
		$found = 0;
		$corner = fn(array $p, int $sx, int $sz, float $y): bool => abs($p[1] - $y) < 1e-3
			&& abs($p[0] - $post['x'] - $sx * $post['halfSize']) < 1e-4
			&& abs($p[2] - $post['z'] - $sz * $post['halfSize']) < 1e-4;
		foreach([-1, 1] as $sx) foreach([-1, 1] as $sz) foreach([$post['bottom'], $post['top']] as $y)
			foreach($points as $p) if($corner($p, $sx, $sz, $y)) { $found++; break; }
		expect($found === 8, "The $name post at x = {$post['x']} is a box from its bottom to its top");
	}
}
expect(count($gates['kinds']['road']['posts']) === 2, 'The road gate has two posts');
expect($gates['kinds']['road']['posts'][0]['bottom'] <= -8, 'The road posts reach 8 meters below the base');
expect($gates['kinds']['air']['extent']['bottom'] === 0, 'The air gate has no base plates below its board');

echo "Landscape sandbox gates: $checks checks passed\n";
