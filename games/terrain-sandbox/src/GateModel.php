<?php
declare(strict_types=1);

namespace TerrainSandbox;

use Phonics\Render\Program;
use TerrainPhonics\Atmosphere;
use TerrainPhonics\Camera;
use TerrainPhonics\Water;

/**
 * Draws a course's race gates with the vehicle shaders: one baked mesh per gate kind, each holding four role prints
 * stacked in its atlas, so a gate's role is a texture offset chosen per draw.
 *
 * A gate is drawn only within its kind's range of the eye and when its extent's box meets the view. The program and
 * each kind's mesh are uploaded on the first frame that draws them.
 */
final class GateModel
{
	/**
	 * Distance in metres beyond which a gate is not drawn. An air gate is three times the size of the others, and an
	 * F5 at 260 m/s covers this range in about 17 seconds.
	 */
	public const RANGE = ['road' => 1500.0, 'water' => 1500.0, 'air' => 4500.0];
	private ?Program $program = null;
	/** Meshes by kind, uploaded on first use. */
	private array $meshes = [];
	/** Per-gate drawing data for $course at $revision: basis, texture offset and culling box. */
	private array $placed = [];
	private ?Course $course = null;
	private int $revision = -1;
	/** Clip plane that last rejected each gate, by id. It is tested first on the next frame. */
	private array $cull = [];
	/** Gates drawn in the last frame. */
	public int $drawn = 0;
	/** Milliseconds spent culling and submitting gates in the last frame. */
	public float $ms = 0.0;

	/**
	 * @param GateShapes $shapes Gate dimensions and the texture offset of each print.
	 * @param string $folder Directory that holds the meshes gates.json names.
	 */
	public function __construct(private readonly GateShapes $shapes, private readonly string $folder) {}

	/**
	 * Draw every gate of a course that is in range and in view.
	 * @param Course $course The session's course. Water gates report their height on the waves now.
	 * @param Camera $camera Viewing camera.
	 * @param Atmosphere $sky Sunlight and sky state.
	 * @param Water $water Water level and appearance.
	 * @param int $width Viewport width in pixels.
	 * @param int $height Viewport height in pixels.
	 * @return void Submits at most one draw per gate, grouped by kind.
	 */
	public function draw(Course $course, Camera $camera, Atmosphere $sky, Water $water, int $width, int $height): void
	{
		$started = hrtime(true);
		$this->drawn = 0;
		// Loading a file or generating a landscape replaces the course, whose revision count starts again.
		if($course !== $this->course || $course->revision !== $this->revision) $this->place($course);
		$eye = $camera->position;
		$planes = $camera->clipPlanes($width, $height);
		$visible = [];
		$nearest = INF;
		$altitude = $eye[1];
		$surface = null;
		foreach($course->gates(false) as $gate)
		{
			$placed = $this->placed[$gate['id']];
			// A floating gate stands somewhere within the water's height range. Culling against that range means only
			// the gates that draw sample the waves for their exact height.
			$low = $high = $gate['y'];
			if($placed['kind'] === 'water')
			{
				$surface ??= $water->heightRange();
				$low = min($low, $surface[0]); $high = max($high, $surface[1]);
			}
			$dx = $gate['x'] - $eye[0]; $dz = $gate['z'] - $eye[2];
			$dy = ($low + $high) / 2 + $placed['middle'] - $eye[1];
			$distance = $dx * $dx + $dy * $dy + $dz * $dz;
			if($distance > $placed['range']) continue;
			$hidden = Camera::rejectingPlane($planes, $eye, $placed['box'], $low + $placed['low'],
				$high + $placed['high'], $this->cull[$gate['id']] ?? -1);
			if($hidden >= 0) { $this->cull[$gate['id']] = $hidden; continue; }
			unset($this->cull[$gate['id']]);
			if($placed['kind'] === 'water') $gate['y'] = $course->height($gate);
			$y = $gate['y'];
			$visible[$placed['kind']][] = [$placed, $gate];
			if($distance < $nearest) { $nearest = $distance; $altitude = $y; }
		}
		if($visible)
		{
			$p = $this->program ??= VehicleModel::program();
			VehicleModel::light($p, $camera, $sky, $water, $width, $height, $altitude);
			$rotation = $p->location('uRotation');
			$origin = $p->location('uOrigin');
			$offset = $p->location('uUvOffset');
			foreach($visible as $kind => $gates)
			{
				$mesh = $this->meshes[$kind] ??= new BakedMesh($this->folder . $this->shapes->kind($kind)['mesh']);
				$mesh->bind();
				foreach($gates as [$placed, $gate])
				{
					glUniformMatrix3fv($rotation, 1, false, $placed['basis']);
					glUniform3f($origin, $gate['x'] - $eye[0], $gate['y'] - $eye[1], $gate['z'] - $eye[2]);
					glUniform2f($offset, ...$placed['uv']);
					$mesh->draw();
					$this->drawn++;
				}
			}
			glBindVertexArray(0);
		}
		$this->ms = (hrtime(true) - $started) / 1e6;
	}

	/**
	 * Work out what each gate needs for drawing once per course revision.
	 * @param Course $course The session's course.
	 * @return void Replaces the per-gate drawing data.
	 */
	private function place(Course $course): void
	{
		$this->placed = $this->cull = [];
		$atlas = $this->shapes->atlas;
		foreach($course->gates(false) as $gate)
		{
			$extent = $this->shapes->kind($gate['kind'])['extent'];
			$cos = cos($gate['yaw']); $sin = sin($gate['yaw']);
			// A square around the gate holds its footprint at any heading.
			$reach = sqrt($extent['halfWidth'] ** 2 + $extent['halfDepth'] ** 2);
			$this->placed[$gate['id']] = [
				'kind' => $gate['kind'],
				// Column-major, as VehicleModel's basis with no pitch or roll: X across, Y up, Z along the travel.
				'basis' => [$cos, 0.0, -$sin, 0.0, 1.0, 0.0, $sin, 0.0, $cos],
				'uv' => $atlas[$course->print($gate)] ?? [0.0, 0.0],
				'box' => ['x' => $gate['x'] - $reach, 'z' => $gate['z'] - $reach, 'size' => 2 * $reach],
				'low' => $extent['bottom'], 'high' => $extent['top'],
				'middle' => ($extent['bottom'] + $extent['top']) / 2,
				'range' => (self::RANGE[$gate['kind']] + $reach) ** 2,
			];
		}
		$this->course = $course;
		$this->revision = $course->revision;
	}

	/** Release the context-local handles. The next draw uploads what it needs again. */
	public function release(): void
	{
		foreach($this->meshes as $mesh) $mesh->close();
		$this->program?->close();
		$this->meshes = [];
		$this->program = null;
	}
}
