<?php
declare(strict_types=1);

namespace TerrainSandbox;

use Phonics\Render\Program;
use TerrainPhonics\Atmosphere;
use TerrainPhonics\Camera;
use TerrainPhonics\SpotLights;
use TerrainPhonics\Water;

/**
 * Draws a course's race gates with the vehicle shaders: one baked mesh per gate kind, each holding four role prints
 * stacked in its atlas, so a gate's role is a texture offset chosen per draw. model.vert resizes each gate's mesh to
 * its opening from the uGate uniforms set per draw.
 *
 * A gate is drawn only within its kind's range of the eye and when its extent's box meets the view. The program and
 * the mesh of each kind the course holds are uploaded on the course's first frame, whether or not a gate is in view.
 */
final class GateModel
{
	/**
	 * Distance in meters from a gate's extent beyond which it is not drawn. An air gate is three times the size of the
	 * others at their own sizes, and an F5 at 260 m/s covers this range in about 17 seconds.
	 */
	public const RANGE = ['road' => 1500.0, 'water' => 1500.0, 'air' => 4500.0];
	private ?Program $program = null;
	private SpotLights $lamps;
	/** Meshes by kind, uploaded on first use. */
	private array $meshes = [];
	/** Kinds of the gates in $course at $revision, as keys. */
	private array $kinds = [];
	/** Per-gate drawing data for $course at $revision: basis, texture offset, resize uniforms and culling box. */
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
	public function __construct(private readonly GateShapes $shapes, private readonly string $folder) { $this->lamps = new SpotLights; }

	/**
	 * Draw every gate of a course that is in range and in view.
	 * @param Course $course The session's course. Water gates report their height on the waves now.
	 * @param Camera $camera Viewing camera.
	 * @param Atmosphere $sky Sunlight and sky state.
	 * @param Water $water Water level and appearance.
	 * @param int $width Viewport width in pixels.
	 * @param int $height Viewport height in pixels.
	 * @param array $lights Headlight beams and their exposure gain for the banner sheeting.
	 * @param array $hiddenGates Gate IDs hidden by the road layer preview.
	 * @return void Submits at most one draw per gate, grouped by kind.
	 */
	public function draw(Course $course, Camera $camera, Atmosphere $sky, Water $water, int $width, int $height,
		array $lights = [[],1.], array $hiddenGates = []): void
	{
		$started = hrtime(true);
		$this->drawn = 0;
		// Loading a file or generating a landscape replaces the course, whose revision count starts again.
		if($course !== $this->course || $course->revision !== $this->revision) $this->place($course);
		// Compiling the shaders and uploading a mesh hold up the frame they happen in. Doing both when the course
		// loads, or when a gate of a new kind is placed, keeps that stall out of a race, where a gate can first come
		// into view at speed. In Chromium under SwiftShader the compile took 0.7 to 2.3 seconds in three runs.
		foreach(array_keys($this->kinds) as $kind)
			$this->meshes[$kind] ??= new BakedMesh($this->folder . $this->shapes->kind($kind)['mesh']);
		if($this->kinds) $this->program ??= VehicleModel::program();
		$eye = $camera->position;
		$planes = $camera->clipPlanes($width, $height);
		$visible = [];
		$nearest = INF;
		$altitude = $eye[1];
		$surface = null;
		foreach($course->gates(false) as $gate)
		{
			if(isset($hiddenGates[$gate['id']])) continue;
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
			$p = $this->program;
			VehicleModel::light($p, $camera, $sky, $water, $width, $height, $altitude);
			glUniform3f($p->location('uEyeWorld'),...$eye);
			$this->lamps->set(...$lights);
			$this->lamps->bind($p,$sky->uniforms()['nightVisibility']);
			$rotation = $p->location('uRotation');
			$origin = $p->location('uOrigin');
			$offset = $p->location('uUvOffset');
			[$size, $board, $columns, $feet] = array_map([$p, 'location'],
				['uGateSize', 'uGateBoard', 'uGateColumns', 'uGateFeet']);
			foreach($visible as $kind => $gates)
			{
				$mesh = $this->meshes[$kind];
				$mesh->bind();
				foreach($gates as [$placed, $gate])
				{
					glUniformMatrix3fv($rotation, 1, false, $placed['basis']);
					glUniform3f($origin, $gate['x'] - $eye[0], $gate['y'] - $eye[1], $gate['z'] - $eye[2]);
					glUniform2f($offset, ...$placed['uv']);
					[$resize, $layout, $count, $base] = $placed['resize'];
					glUniform4f($size, ...$resize);
					glUniform4f($board, ...$layout);
					glUniform4f($columns, ...$count);
					glUniform4f($feet, ...$base);
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
		$this->placed = $this->cull = $this->kinds = [];
		$atlas = $this->shapes->atlas;
		foreach($course->gates(false) as $gate)
		{
			$extent = $course->measure($gate)['extent'];
			$this->kinds[$gate['kind']] = true;
			$cos = cos($gate['yaw']); $sin = sin($gate['yaw']);
			// A square around the gate holds its footprint at any heading.
			$reach = sqrt($extent['halfWidth'] ** 2 + $extent['halfDepth'] ** 2);
			$this->placed[$gate['id']] = [
				'kind' => $gate['kind'],
				// Column-major, as VehicleModel's basis with no pitch or roll: X across, Y up, Z along the travel.
				'basis' => [$cos, 0.0, -$sin, 0.0, 1.0, 0.0, $sin, 0.0, $cos],
				'uv' => $atlas[$course->print($gate)] ?? [0.0, 0.0],
				'resize' => array_values($this->uniforms($course, $gate)),
				'box' => ['x' => $gate['x'] - $reach, 'z' => $gate['z'] - $reach, 'size' => 2 * $reach],
				'low' => $extent['bottom'], 'high' => $extent['top'],
				'middle' => ($extent['bottom'] + $extent['top']) / 2,
				'range' => (self::RANGE[$gate['kind']] + $reach) ** 2,
			];
		}
		$this->course = $course;
		$this->revision = $course->revision;
	}

	/**
	 * Work out the uniforms with which model.vert resizes a gate's mesh to its opening.
	 * @param Course $course The course the gate belongs to.
	 * @param array $gate A gate of the course.
	 * @return array{uGateSize:list<float>,uGateBoard:list<float>,uGateColumns:list<float>,uGateFeet:list<float>}
	 * The half width and height the opening adds and the arch's scales across and up; the board's label half width
	 * and checker column width on its -X and +X sides; the columns each side shows at the mesh's size and holds; and
	 * the feet of the -X and +X posts and the ground under each, from the gate origin.
	 */
	public function uniforms(Course $course, array $gate): array
	{
		$measure = $course->measure($gate);
		$layout = $this->shapes->kind($gate['kind'])['board'];
		// Keep two checker columns on either side of the lettering even on the narrowest road gate.
		$printScale = $heightScale = $gate['kind'] === 'road' && $layout !== null ? 2.0 : 1.0;
		if($heightScale > 1.0)
		{
			foreach($layout['label'] as $side => $label)
			{
				$column = $layout['column'][$side];
				$room = $label + $layout['columns'] * $column + $measure['resize'][0];
				$printScale = min($printScale, ($room - 2 * $heightScale * $column) / $label);
			}
		}
		return ['uGateSize' => $measure['resize'],
			// A board without a layout keeps its width: its label reaches past every vertex.
			'uGateBoard' => $layout === null ? [1e9, 1e9, 1.0, 1.0] : [...$layout['label'], ...$layout['column']],
			'uGateColumns' => [(float)($layout['columns'] ?? 1), (float)($layout['baked'] ?? 1),
				$printScale, $heightScale],
			'uGateFeet' => $measure['feet']];
	}

	/** Release the context-local handles. The next draw uploads what it needs again. */
	public function release(): void
	{
		foreach($this->meshes as $mesh) $mesh->close();
		$this->program?->close();
		$this->lamps->forget();
		$this->meshes = [];
		$this->program = null;
	}
}
