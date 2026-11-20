<?php
declare(strict_types=1);

namespace TerrainSandbox;

use Phonics\Render\Program;
use TerrainPhonics\Camera;
use TerrainPhonics\Landscape;

/**
 * A soft shadow on the ground under the car. It darkens what the terrain pass drew by multiplying it, through a
 * small world-aligned grid that takes the terrain's own heights. Each grid point's height is looked up once and kept,
 * so a parked car costs no lookups and a moving one only the points it reaches.
 *
 * Two shadows combine. A contact shadow darkens the ground under and just around the body at any hour. The sun's
 * shadow sweeps the footprint away from the sun by the roof's throw, and fades as the sun sets. A car in the air
 * leaves lighter, softer shadows on the ground below.
 */
final class GroundShadow
{
	/**
	 * The car's body box, from Car: half its length and width, how far its middle sits ahead of the midpoint between
	 * the axles, and its height, which sets how far the sun throws the shadow. All in metres.
	 */
	public const HALF_LENGTH = (Car::BODY_FRONT + Car::BODY_REAR) / 2, HALF_WIDTH = Car::BODY_HALF_WIDTH,
		CENTRE = (Car::BODY_FRONT - Car::BODY_REAR) / 2, ROOF = Car::BODY_HEIGHT;
	/** Longest throw of the sun's shadow away from the car, in metres, so a setting sun cannot stretch it without end. */
	public const LONGEST = 3.0;
	/** Fractions of the ground's light the sun's shadow and the contact shadow remove at their darkest. */
	public const SUN = .55, CONTACT = .45;
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
		uniform vec3 uBody;   // half the body's length and width, and how far its middle sits ahead of the car's centre
		uniform vec2 uThrow;  // how far the sun throws the roof's shadow, in the car's frame
		uniform vec4 uShade;  // sun and contact strengths, then the sun's and the contact's edge softness in metres
		out vec4 color;
		// Cover of a rounded footprint grown by `grow`, soft over a band either side of its edge.
		float cover(vec2 p, float grow, float soft)
		{
			vec2 q = abs(p - vec2(uBody.z, 0.0)) - uBody.xy - grow + 0.45;
			float d = length(max(q, 0.0)) + min(max(q.x, q.y), 0.0) - 0.45;
			return 1.0 - smoothstep(-soft, soft, d);
		}
		void main()
		{
			// The body's shadow runs from its foot to the roof's, so sweep the footprint along the throw.
			float sun = 0.0;
			for(int i = 0; i <= 6; i++) sun = max(sun, cover(vLocal - uThrow * (float(i) / 6.0), -0.1, uShade.z));
			float contact = cover(vLocal, 0.0, uShade.w);
			color = vec4(vec3((1.0 - uShade.x * sun) * (1.0 - uShade.y * contact)), 1.0);
		}
		GLSL;

	/** Compile the program and create the grid's buffers. */
	public function __construct()
	{
		$this->program = new Program(str_replace(['PULL_LIMIT', 'PULL'],
			[sprintf('%.3f', self::PULL_LIMIT), sprintf('%.3f', self::PULL)], self::VERTEX), self::FRAGMENT);
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
	 * Work out where the shadow lies and how dark it is.
	 * @param array $pose The car's x, y, z and yaw as drawn.
	 * @param float $ground Terrain height under the car's centre.
	 * @param array $sun Unit vector towards the sun.
	 * @return array The extent to cover in the car's frame (along from and to, across from and to, from the midpoint
	 *   between the axles), the sun's throw in that frame, and the sun and contact strengths and softnesses.
	 */
	public static function shape(array $pose, float $ground, array $sun): array
	{
		$s = sin($pose['yaw']); $c = cos($pose['yaw']);
		// The sun throws the roof's shadow away from itself, farther the lower it stands.
		$height = max(.15, $sun[1]);
		$throwX = -$sun[0] / $height * self::ROOF; $throwZ = -$sun[2] / $height * self::ROOF;
		$length = sqrt($throwX * $throwX + $throwZ * $throwZ);
		if($length > self::LONGEST) { $throwX *= self::LONGEST / $length; $throwZ *= self::LONGEST / $length; }
		$along = $throwX * $s + $throwZ * $c; $across = $throwX * $c - $throwZ * $s;
		// A car in the air leaves lighter, softer shadows, and none from about ten metres up.
		$lift = max(0.0, $pose['y'] - $ground - .25);
		$fade = exp(-$lift / 2.5);
		$sunSoft = min(1.5, .12 + .3 * $lift); $contactSoft = min(1.5, .4 + .3 * $lift);
		// Daylight sets the sun's shadow. Below the horizon only the contact shadow stays.
		$daylight = max(0.0, min(1.0, $sun[1] / .12));
		$margin = 1.2 * max($sunSoft, $contactSoft) + .1;

		return [
			'extent' => [self::CENTRE + min(0.0, $along) - self::HALF_LENGTH - $margin,
				self::CENTRE + max(0.0, $along) + self::HALF_LENGTH + $margin,
				min(0.0, $across) - self::HALF_WIDTH - $margin, max(0.0, $across) + self::HALF_WIDTH + $margin],
			'throw' => [$along, $across],
			'shade' => [self::SUN * $daylight * $fade, self::CONTACT * $fade, $sunSoft, $contactSoft],
		];
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
	 * @param array $sun Unit vector towards the sun.
	 * @param Camera $camera The drawing camera.
	 * @param int $width Drawing-buffer width.
	 * @param int $height Drawing-buffer height.
	 * @return int Terrain heights looked up for this frame.
	 */
	public function draw(array $pose, Landscape $land, array $sun, Camera $camera, int $width, int $height): int
	{
		if($land !== $this->land || $land->revision() !== $this->revision || count($this->heights) > self::CACHE)
		{
			$this->heights = [];
			$this->land = $land;
			$this->revision = $land->revision();
		}
		$shape = self::shape($pose, $land->height($pose['x'], $pose['z']), $sun);
		if($shape['shade'][1] < .005) return 1;
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
		glUniform3f($p->location('uBody'), self::HALF_LENGTH, self::HALF_WIDTH, self::CENTRE);
		glUniform2f($p->location('uThrow'), ...$shape['throw']);
		glUniform4f($p->location('uShade'), ...$shape['shade']);
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
