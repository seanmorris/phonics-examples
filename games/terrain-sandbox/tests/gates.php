<?php
declare(strict_types=1);

// The race gate assets: gates.json against the three gate meshes that scripts/import_terrain_gates.py bakes.

$checks = 0;
function expect(bool $condition, string $message): void
{
	global $checks;
	$checks++;
	if(!$condition) throw new RuntimeException($message);
}

/** Whether a triangle meets an axis-aligned box, by the separating axis test. */
function triangleMeetsBox(array $triangle, array $centre, array $half): bool
{
	$v = array_map(fn(array $p): array => [$p[0] - $centre[0], $p[1] - $centre[1], $p[2] - $centre[2]], $triangle);
	$edges = [];
	foreach([[0, 1], [1, 2], [2, 0]] as [$a, $b])
		$edges[] = [$v[$b][0] - $v[$a][0], $v[$b][1] - $v[$a][1], $v[$b][2] - $v[$a][2]];
	$cross = fn(array $a, array $b): array
		=> [$a[1]*$b[2] - $a[2]*$b[1], $a[2]*$b[0] - $a[0]*$b[2], $a[0]*$b[1] - $a[1]*$b[0]];
	$axes = [[1, 0, 0], [0, 1, 0], [0, 0, 1], $cross($edges[0], $edges[1])];
	foreach($edges as $edge) foreach([[1, 0, 0], [0, 1, 0], [0, 0, 1]] as $unit) $axes[] = $cross($edge, $unit);

	foreach($axes as $axis)
	{
		$reach = abs($axis[0]) * $half[0] + abs($axis[1]) * $half[1] + abs($axis[2]) * $half[2];
		$projections = array_map(fn(array $p): float => $p[0]*$axis[0] + $p[1]*$axis[1] + $p[2]*$axis[2], $v);
		if(min($projections) > $reach + 1e-9 || max($projections) < -$reach - 1e-9) return false;
	}

	return true;
}

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
	// The same limit and checks as VehicleModel, which the gates' renderer can share.
	$data = gzdecode(file_get_contents($folder . $kind['mesh']), 4194304);
	expect($data !== false, "The $name mesh decompresses within 4 MiB");
	$header = unpack('Vvertices/Vindices/Vwidth/Vheight', $data);
	$vertexBytes = $header['vertices'] * 28;
	expect($header['vertices'] <= 65536 && $header['indices'] > 0 && $header['indices'] % 3 === 0,
		"The $name mesh has whole triangles");
	expect(strlen($data) === 16 + $vertexBytes + $header['indices'] * 2 + $header['width'] * $header['height'] * 4,
		"The $name mesh has its atlas after the indices");
	expect($header['height'] % 4 === 0, "The $name atlas divides into four role bands");

	$points = [];
	$textured = $outsideBand = $otherTags = 0;
	for($i = 0; $i < $header['vertices']; $i++)
	{
		$record = substr($data, 16 + $i * 28, 28);
		$points[] = array_values(unpack('g3', $record));
		$tag = ord($record[27]);
		if($tag === 255)
		{
			$textured++;
			if(unpack('v', $record, 20)[1] > 65535 / 4) $outsideBand++;
		}
		else if($tag !== 0) $otherTags++;
	}
	expect($textured > 0 && $outsideBand === 0, "Every textured $name vertex samples the first role band");
	expect($otherTags === 0, "No $name vertex carries a wheel or hinge tag");
	$corners = array_values(unpack('v*', substr($data, 16 + $vertexBytes, $header['indices'] * 2)));
	expect(max($corners) < $header['vertices'], "Every $name index names a vertex");

	['window' => $window, 'extent' => $extent] = $kind;
	$low = [min(array_column($points, 0)), min(array_column($points, 1)), min(array_column($points, 2))];
	$high = [max(array_column($points, 0)), max(array_column($points, 1)), max(array_column($points, 2))];
	expect(abs(max(-$low[0], $high[0]) - $extent['halfWidth']) < .001 && abs($high[1] - $extent['top']) < .001
		&& abs($low[1] - $extent['bottom']) < .001 && abs(max(-$low[2], $high[2]) - $extent['halfDepth']) < .001,
		"The $name extent bounds its mesh");
	expect($window['bottom'] === 0 && $window['halfWidth'] < $extent['halfWidth'] && $window['top'] < $extent['top'],
		"The $name window starts at the origin, inside the extent");

	// Nothing stands in the window. Its lowest 15 centimetres are left out: there the road gate's base plates
	// reach 13 centimetres past the posts, as they do past the source towers.
	$box = [$window['halfWidth'] - .01, ($window['top'] - .15) / 2 - .005, $extent['halfDepth'] + 1];
	$centre = [0, .15 + $box[1], 0];
	$blocked = 0;
	for($k = 0; $k < count($corners); $k += 3)
	{
		$triangle = [$points[$corners[$k]], $points[$corners[$k + 1]], $points[$corners[$k + 2]]];
		if(triangleMeetsBox($triangle, $centre, $box)) $blocked++;
	}
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
expect($gates['kinds']['road']['posts'][0]['bottom'] <= -8, 'The road posts reach 8 metres below the base');

echo "Landscape sandbox gates: $checks checks passed\n";
