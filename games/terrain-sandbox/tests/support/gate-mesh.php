<?php
declare(strict_types=1);

// Reads the baked gate meshes and moves their vertices as model.vert does, so the tests can measure a resized gate.
// The functions here repeat the shader's arithmetic line for line; tests/gate-sizes.php checks the shader still
// holds each of these lines.

/** Gate part tags in the low six bits, as scripts/import_terrain_gates.py writes them and model.vert reads them. */
const GATE_LABEL = 1, GATE_FRAME = 2, GATE_SQUARE = 3, GATE_CAP = 4, GATE_POST = 5, GATE_BASE = 6, GATE_ARCH = 7;

/**
 * Read a baked gate mesh.
 * @param string $file Path of a .mesh.bin.gz file.
 * @return array{header:array,vertices:list<array>,corners:list<int>} The header, each vertex's position, normal,
 * texture coordinates and tag, and the triangle corners.
 */
function readGateMesh(string $file): array
{
	$data = gzdecode(file_get_contents($file), 4194304);
	$header = unpack('Vvertices/Vindices/Vwidth/Vheight', $data);
	$vertices = [];
	for($i = 0; $i < $header['vertices']; $i++)
	{
		$v = unpack('g3p/s3n/v2uv/C4paint/Cmetal/Ctag', $data, 16 + $i * 28);
		$vertices[] = ['position' => [$v['p1'], $v['p2'], $v['p3']],
			'normal' => [$v['n1'] / 32767, $v['n2'] / 32767, $v['n3'] / 32767],
			'uv' => [$v['uv1'] / 65535, $v['uv2'] / 65535], 'tag' => $v['tag']];
	}
	$corners = array_values(unpack('v*', substr($data, 16 + $header['vertices'] * 28, $header['indices'] * 2)));
	return ['header' => $header, 'vertices' => $vertices, 'corners' => $corners];
}

/** model.vert's boardX(): where a board vertex lies across a resized gate. */
function gateBoardX(float $x, bool $square, array $uniforms): float
{
	[$labelLeft, $labelRight, $columnLeft, $columnRight] = $uniforms['uGateBoard'];
	[$columns, $baked] = $uniforms['uGateColumns'];
	$added = $uniforms['uGateSize'][0];
	$side = $x < 0 ? -1.0 : 1.0;
	$label = $x < 0 ? $labelLeft : $labelRight;
	$column = $x < 0 ? $columnLeft : $columnRight;
	$along = abs($x) - $label;
	$band = $columns * $column;
	if($along <= 0) return $x;
	if(!$square && $along > $band - .001) return $x + $side * $added;
	$width = $band + $added;
	$shown = max(1.0, min($baked, floor($width / $column + .5)));
	return $side * ($label + min($along, $shown * $column) * $width / ($shown * $column));
}

/**
 * Move one vertex as model.vert moves it for a race gate.
 * @param array $position [x, y, z] in the gate frame.
 * @param int $tag The vertex's tag byte.
 * @param array $uniforms GateModel::uniforms() for the gate.
 * @return array The position in the gate frame once resized.
 */
function resizeGateVertex(array $position, int $tag, array $uniforms): array
{
	[$added, $raised, $across, $up] = $uniforms['uGateSize'];
	$feet = $uniforms['uGateFeet'];
	if(!($across > 0 && $tag >= 128 && $tag < 224)) return $position;
	[$x, $y, $z] = $position;
	$part = $tag & 63;
	$side = $x < 0 ? -1.0 : 1.0;
	if($part === GATE_ARCH) return [$x * $across, $y > 0 ? $y * $up : $y, $z * sqrt($across * $up)];
	if($part === GATE_POST) return [$x + $side * $added, $y > 0 ? $y + $raised : ($side < 0 ? $feet[0] : $feet[1]), $z];
	if($part === GATE_BASE) return [$x + $side * $added, $y + ($side < 0 ? $feet[2] : $feet[3]), $z];
	if($part === GATE_CAP) $x += $side * $added;
	elseif($part !== GATE_LABEL) $x = gateBoardX($x, $part === GATE_SQUARE, $uniforms);
	return [$x, $y + $raised, $z];
}

/**
 * Resize a whole mesh.
 * @param array $mesh readGateMesh() output.
 * @param array $uniforms GateModel::uniforms() for the gate.
 * @return list<array> Triangles of three positions each, without those the resize folds to no area.
 */
function resizeGateMesh(array $mesh, array $uniforms): array
{
	$moved = array_map(fn(array $v): array => resizeGateVertex($v['position'], $v['tag'], $uniforms), $mesh['vertices']);
	$triangles = [];
	for($k = 0; $k < count($mesh['corners']); $k += 3)
	{
		$triangle = [$moved[$mesh['corners'][$k]], $moved[$mesh['corners'][$k + 1]], $moved[$mesh['corners'][$k + 2]]];
		if(triangleArea($triangle) > 1e-9) $triangles[] = $triangle;
	}
	return $triangles;
}

/** Area of a triangle in square meters. */
function triangleArea(array $t): float
{
	$a = [$t[1][0] - $t[0][0], $t[1][1] - $t[0][1], $t[1][2] - $t[0][2]];
	$b = [$t[2][0] - $t[0][0], $t[2][1] - $t[0][1], $t[2][2] - $t[0][2]];
	$c = [$a[1] * $b[2] - $a[2] * $b[1], $a[2] * $b[0] - $a[0] * $b[2], $a[0] * $b[1] - $a[1] * $b[0]];
	return sqrt($c[0] ** 2 + $c[1] ** 2 + $c[2] ** 2) / 2;
}

/** Whether a triangle meets an axis-aligned box, by the separating axis test. */
function triangleMeetsBox(array $triangle, array $center, array $half): bool
{
	$v = array_map(fn(array $p): array => [$p[0] - $center[0], $p[1] - $center[1], $p[2] - $center[2]], $triangle);
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
