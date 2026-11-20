<?php
declare(strict_types=1);

namespace TerrainSandbox;

use Phonics\Render\Program;
use TerrainPhonics\Camera;
use TerrainPhonics\Landscape;

/**
 * A soft dark patch on the ground under the car, between its wheels, so it reads as standing on the ground. It is
 * darkest under the middle of the car and fades out by the wheels, the same at any hour and from any sun. A car in
 * the air leaves a fainter, wider patch, and none from about 12 m up.
 *
 * It darkens what the terrain pass drew by multiplying it, through a small world-aligned grid that takes the
 * terrain's own heights. Each grid point's height is looked up once and kept, so a parked car costs no lookups and a
 * moving one only the points it reaches.
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

	private Program $program;
	private array $vao = [0], $buffers = [0, 0];
	/** @var array<int, float> Terrain height at each grid point, keyed by its packed grid coordinates. */
	private array $heights = [];
	private ?Landscape $land = null;
	private int $revision = -1, $columns = 0, $rows = 0, $indexCount = 0;

	private const VERTEX = <<<'GLSL'
		#version 300 es
		precision highp float;
		layout(location=0) in vec3 aPosition; // the grid point relative to the eye
		uniform mat4 uViewProjection;
		uniform vec2 uOrigin;   // the car's centre relative to the eye, on X and Z
		uniform vec2 uAxis;     // sine and cosine of the car's heading: forward is (s, c), across is (c, -s)
		out vec2 vLocal;
		void main()
		{
			vec2 offset = aPosition.xz - uOrigin;
			vLocal = vec2(dot(offset, uAxis), dot(offset, vec2(uAxis.y, -uAxis.x)));
			// Pull the point towards the eye along its own view ray. It stays where it is on screen, but clears
			// terrain drawn a little above its true height, as coarse patches are before detail streams in.
			float distance = length(aPosition);
			gl_Position = uViewProjection * vec4(aPosition * max(0.0, 1.0 - min(PULL_LIMIT, PULL * distance) / distance), 1.0);
		}
		GLSL;

	private const FRAGMENT = <<<'GLSL'
		#version 300 es
		precision highp float;
		in vec2 vLocal;
		uniform vec2 uHalf;   // half the footprint's length and width
		uniform vec3 uShade;  // strength, then how far inside and outside the footprint's edge the fade runs, in metres
		out vec4 color;
		void main()
		{
			// Signed distance from the footprint's rounded edge; GroundShadow::darkness() mirrors this.
			vec2 q = abs(vLocal) - uHalf + CORNER;
			float d = length(max(q, 0.0)) + min(max(q.x, q.y), 0.0) - CORNER;
			color = vec4(vec3(1.0 - uShade.x * (1.0 - smoothstep(-uShade.y, uShade.z, d))), 1.0);
		}
		GLSL;

	/** Compile the program and create the grid's buffers. */
	public function __construct()
	{
		$this->program = new Program(str_replace(['PULL_LIMIT', 'PULL'],
			[sprintf('%.3f', self::PULL_LIMIT), sprintf('%.3f', self::PULL)], self::VERTEX),
			str_replace('CORNER', sprintf('%.3f', self::CORNER), self::FRAGMENT));
		glGenVertexArrays(1, $this->vao);
		glBindVertexArray($this->vao[0]);
		glGenBuffers(2, $this->buffers);
		glBindBuffer(GL_ARRAY_BUFFER, $this->buffers[0]);
		glEnableVertexAttribArray(0);
		glVertexAttribPointer(0, 3, GL_FLOAT, false, 12, 0);
		glBindBuffer(GL_ELEMENT_ARRAY_BUFFER, $this->buffers[1]);
		glBindVertexArray(0);
	}

	/**
	 * Work out how far the patch reaches and how dark it is.
	 * @param array $pose The car's y as drawn.
	 * @param float $ground Terrain height under the car's centre.
	 * @return array The extent to cover in the car's frame (along from and to, across from and to, from the midpoint
	 *   between the axles), and the shade: strength, and how far inside and outside the edge the fade runs.
	 */
	public static function shape(array $pose, float $ground): array
	{
		// In the air the patch grows fainter and wider: gone from about 12 m up, and no wider than 4 m up makes it.
		$lift = max(0.0, $pose['y'] - $ground - .25);
		$spread = min(4.0, $lift);
		$shade = [self::STRENGTH * exp(-$lift / 2.5), self::FADE_IN + .2 * $spread, self::FADE_OUT + .4 * $spread];
		$along = self::HALF_LENGTH + $shade[2] + .05; $across = self::HALF_WIDTH + $shade[2] + .05;

		return ['extent' => [-$along, $along, -$across, $across], 'shade' => $shade];
	}

	/**
	 * How much light the patch removes at a point, as the fragment shader works it out.
	 * @param array $shade The shade from shape().
	 * @param float $along Distance ahead of the midpoint between the axles, in metres.
	 * @param float $across Distance to the side of it, in metres.
	 * @return float Share of the ground's light removed, from 0 to the strength.
	 */
	public static function darkness(array $shade, float $along, float $across): float
	{
		$qx = abs($along) - self::HALF_LENGTH + self::CORNER; $qy = abs($across) - self::HALF_WIDTH + self::CORNER;
		$d = hypot(max($qx, 0.0), max($qy, 0.0)) + min(max($qx, $qy), 0.0) - self::CORNER;
		$t = max(0.0, min(1.0, ($d + $shade[1]) / ($shade[1] + $shade[2])));

		return $shade[0] * (1 - $t * $t * (3 - 2 * $t));
	}

	/**
	 * The world grid that covers an extent in the car's frame.
	 * @param array $pose The car's x, z and yaw.
	 * @param array $extent Along from and to, across from and to, as shape() gives it.
	 * @return array{0:int,1:int,2:int,3:int} First column, first row, and the number of columns and rows of points.
	 */
	public static function grid(array $pose, array $extent): array
	{
		$s = sin($pose['yaw']); $c = cos($pose['yaw']);
		$xs = $zs = [];
		foreach([[$extent[0], $extent[2]], [$extent[0], $extent[3]], [$extent[1], $extent[2]], [$extent[1], $extent[3]]] as [$a, $b])
		{
			$xs[] = $pose['x'] + $a * $s + $b * $c;
			$zs[] = $pose['z'] + $a * $c - $b * $s;
		}
		$i = (int)floor(min($xs) / self::SPACING); $j = (int)floor(min($zs) / self::SPACING);

		return [$i, $j, min(32, (int)ceil(max($xs) / self::SPACING) - $i + 1), min(32, (int)ceil(max($zs) / self::SPACING) - $j + 1)];
	}

	/**
	 * Darken the ground under the car. Call it from the scene's opaque pass, after the terrain and before the car.
	 * @param array $pose The car's x, y, z and yaw as drawn.
	 * @param Landscape $land Ground under the car.
	 * @param Camera $camera The drawing camera.
	 * @param int $width Drawing-buffer width.
	 * @param int $height Drawing-buffer height.
	 * @return int Terrain heights looked up for this frame.
	 */
	public function draw(array $pose, Landscape $land, Camera $camera, int $width, int $height): int
	{
		if($land !== $this->land || $land->revision() !== $this->revision || count($this->heights) > self::CACHE)
		{
			$this->heights = [];
			$this->land = $land;
			$this->revision = $land->revision();
		}
		$shape = self::shape($pose, $land->height($pose['x'], $pose['z']));
		if($shape['shade'][0] < .005) return 1;
		[$i0, $j0, $columns, $rows] = self::grid($pose, $shape['extent']);
		[$ex, $ey, $ez] = $camera->position;
		$half = $land->recipe()['worldSize'] / 2;
		$looked = 1;
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

		$p = $this->program;
		$p->use();
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
		glUniformMatrix4fv($p->location('uViewProjection'), 1, false, $camera->viewProjection($width, $height));
		glUniform2f($p->location('uOrigin'), $pose['x'] - $ex, $pose['z'] - $ez);
		glUniform2f($p->location('uAxis'), sin($pose['yaw']), cos($pose['yaw']));
		glUniform2f($p->location('uHalf'), self::HALF_LENGTH, self::HALF_WIDTH);
		glUniform3f($p->location('uShade'), ...$shape['shade']);
		glEnable(GL_BLEND);
		glBlendFuncSeparate(GL_ZERO, GL_SRC_COLOR, GL_ZERO, GL_ONE);
		glDepthMask(false);
		glDepthFunc(GL_LEQUAL);
		glDrawElements(GL_TRIANGLES, $this->indexCount, GL_UNSIGNED_SHORT, 0);
		glDepthFunc(GL_LESS);
		glDepthMask(true);
		glDisable(GL_BLEND);
		glBindVertexArray(0);

		return $looked;
	}

	/** Release the program and the grid. */
	public function close(): void
	{
		glDeleteBuffers(2, $this->buffers);
		glDeleteVertexArrays(1, $this->vao);
		$this->program->close();
	}
}
