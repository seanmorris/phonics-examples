<?php
declare(strict_types=1);

namespace TerrainSandbox;

use Phonics\Render\Program;
use TerrainPhonics\Camera;
use TerrainPhonics\Clipmap;
use TerrainPhonics\Scene;
use TerrainPhonics\Landscape;

/**
 * A soft dark patch on the ground under the car, so it reads as standing on the ground, the same at any hour and from
 * any sun. A dark core lies between the wheels. A fainter skirt fills the body's outline and shows a little past the
 * bumpers and sills, which is all of the patch the chase camera behind the car can see. A car in the air leaves a
 * fainter, wider patch, and none from about 12 m up.
 *
 * It darkens what the terrain pass drew by multiplying it, through a small world-aligned grid that takes the
 * terrain's own heights. The clipmap supplies the same GPU triangles drawn under the wheels. While that grid is
 * streaming, or when rendering with CPU terrain, the car uses a cached grid of sampled heights.
 */
final class GroundShadow
{
	/**
	 * Half the length and width of the patch's footprint, in metres: the wheelbase and the wider (rear) track, centred
	 * between the axles, with corners rounded by CORNER.
	 */
	public const HALF_LENGTH = Car::WHEELBASE / 2, HALF_WIDTH = Car::REAR_TRACK / 2, CORNER = .3;
	/** Share of the ground's light the patch removes under the middle of the car. */
	public const STRENGTH = .6;
	/**
	 * How far inside the footprint's edge the fade starts, and how far outside it ends, in metres. The patch keeps
	 * about 30% of its strength at the middle of each wheel line and 9 to 12% under the tyres. It is gone 0.25 m
	 * outside them, under the sills and the overhangs.
	 */
	public const FADE_IN = .45, FADE_OUT = .25;
	/**
	 * The skirt's footprint: half the body's length and width, how far its middle sits ahead of the midpoint between
	 * the axles, and the rounding of its corners, in metres.
	 */
	public const BODY_HALF_LENGTH = (Car::BODY_FRONT + Car::BODY_REAR) / 2, BODY_HALF_WIDTH = Car::BODY_HALF_WIDTH,
		BODY_CENTRE = (Car::BODY_FRONT - Car::BODY_REAR) / 2, BODY_CORNER = .5;
	/**
	 * The skirt's strength, and how far inside and outside the body's edge it fades. It removes about 30% of the
	 * ground's light at the body's edge and is gone 0.4 m outside it.
	 */
	public const SKIRT = .4, SKIRT_FADE_IN = .2, SKIRT_FADE_OUT = .4;
	/** Grid spacing in metres. */
	public const SPACING = .75;
	/** Most heights kept before the cache starts again. */
	private const CACHE = 4096;
	/** Height of the grid above the terrain in metres. */
	private const LIFT = .03;
	/**
	 * How far each grid point moves towards the eye, as a fraction of its distance, and at most in metres. From a
	 * chase camera 18 degrees above the ground, the 8 m pull clears terrain drawn up to about 20 cm too high.
	 */
	public const PULL = .08, PULL_LIMIT = .8;

	private ?Program $sampledProgram = null;
	private ?Program $terrainProgram = null;
	private array $vao = [0], $buffers = [0, 0];
	/** @var array<int, float> Terrain height at each grid point, keyed by its packed grid coordinates. */
	private array $heights = [];
	private ?Landscape $land = null;
	private int $revision = -1, $columns = 0, $rows = 0, $indexCount = 0;

	private const VERTEX = <<<'GLSL'
		#version 300 es
		precision highp float;
		precision highp int;
		// POSITION_SOURCE
		uniform mat4 uViewProjection;
		uniform vec2 uOrigin;   // the car's centre relative to the eye, on X and Z
		uniform vec2 uAxis;     // sine and cosine of the car's heading: forward is (s, c), across is (c, -s)
		out vec2 vLocal;
		void main()
		{
			vec3 position = relativePosition();
			vec2 offset = position.xz - uOrigin;
			vLocal = vec2(dot(offset, uAxis), dot(offset, vec2(uAxis.y, -uAxis.x)));
			// Pull the point towards the eye along its own view ray. It stays where it is on screen, but clears
			// terrain drawn a little above its true height, as coarse patches are before detail streams in.
			float distance = length(position);
			gl_Position = uViewProjection * vec4(position * max(0.0, 1.0 - min(PULL_LIMIT, PULL * distance) / distance), 1.0);
		}
		GLSL;

	private const FRAGMENT = <<<'GLSL'
		#version 300 es
		precision highp float;
		in vec2 vLocal;
		uniform vec2 uHalf;   // half the core's length and width
		uniform vec3 uShade;  // the core's strength, then how far inside and outside its edge the fade runs, in metres
		uniform vec3 uBody;   // half the body's length and width, and how far its middle sits ahead of the axles' midpoint
		uniform vec3 uSkirt;  // the skirt's strength and fade, as uShade
		out vec4 color;
		// Signed distance from the edge of a rectangle with rounded corners.
		float edge(vec2 p, vec2 size, float corner)
		{
			vec2 q = abs(p) - size + corner;
			return length(max(q, 0.0)) + min(max(q.x, q.y), 0.0) - corner;
		}
		void main()
		{
			// GroundShadow::darkness() mirrors this.
			float core = uShade.x * (1.0 - smoothstep(-uShade.y, uShade.z, edge(vLocal, uHalf, CORNER)));
			float skirt = uSkirt.x * (1.0 - smoothstep(-uSkirt.y, uSkirt.z,
				edge(vLocal - vec2(uBody.z, 0.0), uBody.xy, BODY_CORNER)));
			color = vec4(vec3(1.0 - max(core, skirt)), 1.0);
		}
		GLSL;

	private static function program(bool $terrain): Program
	{
		$source = $terrain ? Clipmap::gridShader() . <<<'GLSL'

			uniform vec3 uEye;
			vec3 relativePosition() {
				ivec2 cell = clipStitchedCell(clipCell());
				vec2 xz = vec2(cell) * uGridStep;
				return vec3(xz.x, clipHeight(cell,clipMorph(cell)) + LIFT, xz.y) - uEye;
			}
			GLSL : 'layout(location=0) in vec3 aPosition; vec3 relativePosition() { return aPosition; }';
		return new Program(str_replace(['// POSITION_SOURCE','PULL_LIMIT','PULL','LIFT'],
			[$source,sprintf('%.3f', self::PULL_LIMIT), sprintf('%.3f', self::PULL),sprintf('%.3f', self::LIFT)], self::VERTEX),
			str_replace(['BODY_CORNER', 'CORNER'], [sprintf('%.3f', self::BODY_CORNER), sprintf('%.3f', self::CORNER)],
				self::FRAGMENT));
	}

	/** Compile the program and create the grid's buffers. */
	private function makeSampledProgram(): Program
	{
		glGenVertexArrays(1, $this->vao);
		glBindVertexArray($this->vao[0]);
		glGenBuffers(2, $this->buffers);
		glBindBuffer(GL_ARRAY_BUFFER, $this->buffers[0]);
		glEnableVertexAttribArray(0);
		glVertexAttribPointer(0, 3, GL_FLOAT, false, 12, 0);
		glBindBuffer(GL_ELEMENT_ARRAY_BUFFER, $this->buffers[1]);
		glBindVertexArray(0);
		return self::program(false);
	}

	/**
	 * Work out how far the patch reaches and how dark it is.
	 * @param array $pose The car's y as drawn.
	 * @param float $ground Terrain height under the car's centre.
	 * @return array The extent to cover in the car's frame (along from and to, across from and to, from the midpoint
	 *   between the axles); the core's shade: strength, and how far inside and outside its edge the fade runs; and the
	 *   skirt's, likewise.
	 */
	public static function shape(array $pose, float $ground): array
	{
		// In the air the patch grows fainter and wider: gone from about 12 m up, and no wider than 4 m up makes it.
		$lift = max(0.0, $pose['y'] - $ground - .25);
		$spread = min(4.0, $lift);
		$fade = exp(-$lift / 2.5);
		$shade = [self::STRENGTH * $fade, self::FADE_IN + .2 * $spread, self::FADE_OUT + .4 * $spread];
		$skirt = [self::SKIRT * $fade, self::SKIRT_FADE_IN + .2 * $spread, self::SKIRT_FADE_OUT + .4 * $spread];
		$along = self::BODY_HALF_LENGTH + $skirt[2] + .05; $across = self::BODY_HALF_WIDTH + $skirt[2] + .05;

		return ['extent' => [self::BODY_CENTRE - $along, self::BODY_CENTRE + $along, -$across, $across],
			'shade' => $shade, 'skirt' => $skirt];
	}

	/**
	 * How much light the patch removes at a point, as the fragment shader works it out.
	 * @param array $shape The result of shape().
	 * @param float $along Distance ahead of the midpoint between the axles, in metres.
	 * @param float $across Distance to the side of it, in metres.
	 * @return float Share of the ground's light removed, from 0 to the core's strength.
	 */
	public static function darkness(array $shape, float $along, float $across): float
	{
		$fade = static function(array $shade, float $x, float $y, float $halfX, float $halfY, float $corner): float {
			$qx = abs($x) - $halfX + $corner; $qy = abs($y) - $halfY + $corner;
			$d = hypot(max($qx, 0.0), max($qy, 0.0)) + min(max($qx, $qy), 0.0) - $corner;
			$t = max(0.0, min(1.0, ($d + $shade[1]) / ($shade[1] + $shade[2])));
			return $shade[0] * (1 - $t * $t * (3 - 2 * $t));
		};

		return max($fade($shape['shade'], $along, $across, self::HALF_LENGTH, self::HALF_WIDTH, self::CORNER),
			$fade($shape['skirt'], $along - self::BODY_CENTRE, $across, self::BODY_HALF_LENGTH, self::BODY_HALF_WIDTH,
				self::BODY_CORNER));
	}

	/**
	 * The world grid that covers an extent in the car's frame.
	 * @param array $pose The car's x, z and yaw.
	 * @param array $extent Along from and to, across from and to, as shape() gives it.
	 * @return array{0:int,1:int,2:int,3:int} First column, first row, and the number of columns and rows of points.
	 */
	public static function grid(array $pose, array $extent): array
	{
		return self::sampleGrid(self::bounds($pose, $extent));
	}

	private static function sampleGrid(array $bounds): array
	{
		[$left,$top,$right,$bottom] = $bounds;
		$i = (int)floor($left/self::SPACING); $j = (int)floor($top/self::SPACING);
		return [$i,$j,(int)ceil($right/self::SPACING)-$i+1,(int)ceil($bottom/self::SPACING)-$j+1];
	}

	private static function bounds(array $pose, array $extent): array
	{
		$s = sin($pose['yaw']); $c = cos($pose['yaw']);
		[$from,$to,$left,$right] = $extent;
		return [$pose['x'] + min($from*$s,$to*$s) + min($left*$c,$right*$c),
			$pose['z'] + min($from*$c,$to*$c) + min(-$left*$s,-$right*$s),
			$pose['x'] + max($from*$s,$to*$s) + max($left*$c,$right*$c),
			$pose['z'] + max($from*$c,$to*$c) + max(-$left*$s,-$right*$s)];
	}

	/**
	 * Darken the ground under the car. Call it from the scene's opaque pass, after the terrain and before the car.
	 * @param array $pose The car's x, y, z and yaw as drawn.
	 * @param Landscape $land Ground under the car.
	 * @param Camera $camera The drawing camera.
	 * @param int $width Drawing-buffer width.
	 * @param int $height Drawing-buffer height.
	 * @param Scene|null $scene Scene providing the resident terrain grid when available.
	 * @return int Terrain heights looked up for this frame.
	 */
	public function draw(array $pose, Landscape $land, Camera $camera, int $width, int $height, ?Scene $scene = null): int
	{
		if($land !== $this->land || $land->revision() !== $this->revision || count($this->heights) > self::CACHE)
		{
			$this->heights = [];
			$this->land = $land;
			$this->revision = $land->revision();
		}
		$shape = self::shape($pose, $land->height($pose['x'], $pose['z']));
		if($shape['shade'][0] < .005) return 1;
		$bounds = self::bounds($pose, $shape['extent']);
		glEnable(GL_BLEND);
		glBlendFuncSeparate(GL_ZERO, GL_SRC_COLOR, GL_ZERO, GL_ONE);
		glDepthMask(false);
		glDepthFunc(GL_LEQUAL);
		$looked = 1;
		$drawn = false;
		if($scene !== null)
		{
			$p = $this->terrainProgram ??= self::program(true);
			$this->bind($p, $pose, $shape, $camera, $width, $height);
			glUniform3f($p->location('uEye'), ...$camera->position);
			$drawn = $scene->drawGroundPatch($p, $camera, $bounds);
		}
		if(!$drawn)
		{
			$this->sampledProgram ??= $this->makeSampledProgram();
			$this->bind($this->sampledProgram, $pose, $shape, $camera, $width, $height);
			$looked += $this->drawSampled($land, $camera, $bounds);
		}
		glDepthFunc(GL_LESS);
		glDepthMask(true);
		glDisable(GL_BLEND);
		glBindVertexArray(0);
		return $looked;
	}

	private function drawSampled(Landscape $land, Camera $camera, array $bounds): int
	{
		[$i0, $j0, $columns, $rows] = self::sampleGrid($bounds);
		[$ex, $ey, $ez] = $camera->position;
		$half = $land->recipe()['worldSize'] / 2;
		$looked = 0;
		$points = [];
		for($j = $j0; $j < $j0 + $rows; $j++)
			for($i = $i0; $i < $i0 + $columns; $i++)
			{
				$x = $i * self::SPACING; $z = $j * self::SPACING;
				$key = ($i + 32768) << 16 | ($j + 32768);
				if(!isset($this->heights[$key]))
				{
					$this->heights[$key] = $land->height(max(-$half, min($half, $x)), max(-$half, min($half, $z)));
					$looked++;
				}
				array_push($points, $x - $ex, $this->heights[$key] + self::LIFT - $ey, $z - $ez);
			}

		glBindVertexArray($this->vao[0]);
		$data = pack('g*', ...$points);
		glBindBuffer(GL_ARRAY_BUFFER, $this->buffers[0]);
		glBufferData(GL_ARRAY_BUFFER, strlen($data), $data, GL_DYNAMIC_DRAW);
		if($columns !== $this->columns || $rows !== $this->rows)
		{
			$indices = [];
			for($j = 0; $j < $rows - 1; $j++)
				for($i = 0; $i < $columns - 1; $i++)
				{
					$a = $j * $columns + $i;
					array_push($indices, $a, $a + 1, $a + $columns, $a + 1, $a + $columns + 1, $a + $columns);
				}
			$data = pack('v*', ...$indices);
			glBufferData(GL_ELEMENT_ARRAY_BUFFER, strlen($data), $data, GL_STATIC_DRAW);
			[$this->columns, $this->rows, $this->indexCount] = [$columns, $rows, count($indices)];
		}
		glDrawElements(GL_TRIANGLES, $this->indexCount, GL_UNSIGNED_SHORT, 0);
		return $looked;
	}

	private function bind(Program $p, array $pose, array $shape, Camera $camera, int $width, int $height): void
	{
		$p->use();
		[$ex,,$ez] = $camera->position;
		glUniformMatrix4fv($p->location('uViewProjection'), 1, false, $camera->viewProjection($width, $height));
		glUniform2f($p->location('uOrigin'), $pose['x'] - $ex, $pose['z'] - $ez);
		glUniform2f($p->location('uAxis'), sin($pose['yaw']), cos($pose['yaw']));
		glUniform2f($p->location('uHalf'), self::HALF_LENGTH, self::HALF_WIDTH);
		glUniform3f($p->location('uShade'), ...$shape['shade']);
		glUniform3f($p->location('uBody'), self::BODY_HALF_LENGTH, self::BODY_HALF_WIDTH, self::BODY_CENTRE);
		glUniform3f($p->location('uSkirt'), ...$shape['skirt']);
	}

	/** Release the program and the grid. */
	public function close(): void
	{
		if($this->sampledProgram !== null)
		{
			glDeleteBuffers(2, $this->buffers);
			glDeleteVertexArrays(1, $this->vao);
			$this->sampledProgram->close();
		}
		$this->terrainProgram?->close();
	}
}
