<?php
declare(strict_types=1);
namespace PhpBilliards\Render;

use Phonics\Render\Program;
use PhpBilliards\Game;
use PhpBilliards\Geometry\TableGeometry;
use PhpBilliards\Physics\Ball;
use PhpBilliards\Physics\Table;

/**
 * PHP-owned GLES billiards scene.
 *
 * The scene binds packed geometry, procedural material textures, lighting, camera transforms, and interpolated
 * game state to draw the table, balls, cue, projected path, pocket drops, and placement preview in ordered passes.
 */
final class Scene
{
	private const BALL = [.03,1.56,1,.35];
	private const WOOD = [.5,1.52,2,1];
	private const CUE = [.42,1.52,6,1];
	/** Veneer, ring, and inlay colour of each player's cue: blue for free play and the first player, red for the second. */
	public const CUE_ACCENTS = [[.16,.36,.78,1],[.74,.12,.1,1]];
	private const FELT = [.9,1.5,3,.85];
	private const CUSHION_FELT = [.93,1.5,3,1.15];
	/** Ball colours of a pre-1970 Bakelite set, matching art/svg/ball-atlas.svg; index 0 is the aged-ivory cue ball. */
	public const BALL_COLORS = [
		[.945,.918,.839,1],[.914,.627,.098,1],[.114,.227,.620,1],[.784,.078,.227,1]
		, [.290,.173,.541,1],[.941,.275,.118,1],[.071,.502,.416,1],[.576,.173,.282,1]
		, [.102,.114,.133,1],[.965,.698,.102,1],[.102,.314,.847,1],[.910,.094,.314,1]
		, [.345,.220,.659,1],[.961,.416,.251,1],[.059,.431,.333,1],[.478,.125,.204,1]
	];

	private Program $program;
	private MeshBundle $meshes;
	/** Ball skins as one texture array; independent layer mip chains prevent neighbouring skins bleeding at the poles. */
	private Texture $ballSkins;
	private Texture $wood;
	private Texture $felt;
	private bool $closed = false;

	/**
	 * @param string $root Absolute root directory of the Billiards package.
	 */
	public function __construct(private string $root)
	{
		$this->program = new Program(
			file_get_contents($root . '/shaders/billiards.vert')
			, file_get_contents($root . '/shaders/billiards.frag')
		);
		// Balls draw instanced, so the sphere keeps its indices: one validated draw and full vertex reuse per frame.
		$this->meshes = new MeshBundle(
			$root . '/assets/generated/meshes.json', $root . '/assets/generated/meshes.bin.gz', ['sphere']
		);
		$textures = json_decode(file_get_contents($root . '/assets/generated/textures.json'), true, flags: JSON_THROW_ON_ERROR);
		$this->ballSkins = $this->atlasTextures($textures['ballAtlas'], 4, 4);
		$this->wood = $this->texture($textures['wood']);
		$this->felt = $this->texture($textures['felt']);
	}

	private function pixels(array $descriptor): string
	{
		$bytes = file_get_contents($this->root . '/assets/generated/' . $descriptor['file']);
		if(isset($descriptor['sha256']) && !hash_equals($descriptor['sha256'], hash('sha256', $bytes)))
		throw new \RuntimeException('Billiards texture checksum mismatch');

		return $bytes;
	}

	private function texture(array $descriptor): Texture
	{
		return new Texture($this->pixels($descriptor), (int)$descriptor['width'], (int)$descriptor['height']);
	}

	/** Split the generated source atlas so each skin owns its mip levels and vertical pole clamp. */
	private function atlasTextures(array $descriptor, int $columns, int $rows): Texture
	{
		$bytes = $this->pixels($descriptor);
		$width = (int)$descriptor['width'];
		$height = (int)$descriptor['height'];
		if($width % $columns || $height % $rows) throw new \RuntimeException('Invalid billiards atlas grid');
		$cellWidth = intdiv($width, $columns);
		$cellHeight = intdiv($height, $rows);
		$pixels = '';

		// Layer index equals ball number: cells are stacked in row-major atlas order.
		for($row = 0; $row < $rows; $row++) for($column = 0; $column < $columns; $column++)
		for($y = 0; $y < $cellHeight; $y++)
		$pixels .= substr($bytes, (($row * $cellHeight + $y) * $width + $column * $cellWidth) * 4, $cellWidth * 4);

		return new Texture($pixels, $cellWidth, $cellHeight, true, false, $columns * $rows);
	}

	/**
	 * Render one interpolated game frame into the active GLES framebuffer.
	 *
	 * The pass establishes shared lighting and camera uniforms, draws table materials in depth order, then submits
	 * pocketed and active balls, the cue and stroke pose, the aiming guide, and scratch-placement feedback.
	 *
	 * @param Game $game Current game and simulation state.
	 * @param int $width Framebuffer width in pixels.
	 * @param int $height Framebuffer height in pixels.
	 * @param float $alpha Interpolation fraction between fixed updates.
	 */
	public function render(Game $game, int $width, int $height, float $alpha): void
	{
		glEnable(GL_DEPTH_TEST);
		glEnable(GL_CULL_FACE);
		glEnable(GL_BLEND);
		glBlendFunc(GL_SRC_ALPHA, GL_ONE_MINUS_SRC_ALPHA);
		$this->program->use();
		$simulation = $game->simulation;
		$eye = $game->camera->eye();
		$this->matrix4('uViewProjection', $game->camera->viewProjection($width, $height));
		glUniform3f($this->program->location('uEye'), ...$eye);
		glUniform2f($this->program->location('uTableSize'), Table::WIDTH, Table::HEIGHT);
		glUniform1f($this->program->location('uSpot'), Table::FOOT_SPOT);
		glActiveTexture(GL_TEXTURE0);
		$this->ballSkins->bind();
		glUniform1i($this->program->location('uBallSkins'), 0);
		glUniform1i($this->program->location('uInstanced'), 0);
		glActiveTexture(GL_TEXTURE1);
		$this->wood->bind();
		glUniform1i($this->program->location('uWood'), 1);
		glActiveTexture(GL_TEXTURE2);
		$this->felt->bind();
		glUniform1i($this->program->location('uFelt'), 2);

		$this->draw('apron', [0,0,0], [1,1,1], [0,0,0,1], [.45,.23,.095,1], self::WOOD);
		$this->draw('bed', [0,0,0], [1,1,1], [0,0,0,1], [.055,.285,.18,1], self::FELT);
		$this->draw('wood', [0,0,0], [1,1,1], [0,0,0,1], [.38,.18,.07,1], self::WOOD);
		$this->draw('cushion', [0,0,0], [1,1,1], [0,0,0,1], [.042,.21,.13,1], self::CUSHION_FELT);
		$this->draw('liners', [0,0,0], [1,1,1], [0,0,0,1], [.12,.045,.022,1], [.72,1.52,0,0]);

		// The black floor closes the liner's lower cylinder; sized to it, it can never show outside the cup.
		$floor = TableGeometry::pocketFloorRadius() * 1.04;
		foreach(TableGeometry::pockets() as $pocket)
		$this->draw(
			'disc', [$pocket['x'],TableGeometry::POCKET_FLOOR - .002,$pocket['z']]
			, [$floor,1,$floor]
			, [0,0,0,1], [.001,.0015,.001,1], [.96,1.5,0,0]
		);

		// Shadows and balls each go out as one instanced draw instead of one draw, matrix pair, and tint per ball.
		$shadows = $balls = '';
		$shadowCount = $ballCount = 0;
		foreach($simulation->balls as $ball) if($ball->active)
		{
			$state = $ball->state($alpha);
			if($ball->pocket === null)
			{
				$offset = .0085;
				$shadows .= pack('f*', ...[...Camera::model(
					[$state['x'] + ($state['x'] + .45) * $offset,.0124,$state['y'] + ($state['y'] + .7) * $offset]
					, [Ball::RADIUS * 1.28,1,Ball::RADIUS * 1.28], [0,0,0,1]
				), 0, 0, 0, .3, 0]);
				$shadowCount++;
			}
			$balls .= pack('f*', ...[...Camera::model(
				[$state['x'],Ball::RADIUS + .012 - ($state['drop'] ?? 0),$state['y']]
				, [Ball::RADIUS,Ball::RADIUS,Ball::RADIUS], $state['rotation']
			), ...self::BALL_COLORS[$ball->number], $ball->number]);
			$ballCount++;
		}
		$this->drawInstanced('disc', $shadows, $shadowCount, [1,0,4,0]);
		$this->drawInstanced('sphere', $balls, $ballCount, self::BALL);

		if($simulation->phase === 'placing')
		{
			$valid = $simulation->canPlaceCue(...$game->placement);
			[$x,$z] = $game->placement;
			$this->draw(
				'disc', [$x,.0124,$z], [Ball::RADIUS * 1.35,1,Ball::RADIUS * 1.35], [0,0,0,1]
				, $valid ? [.05,.5,.22,.32] : [.7,.02,.01,.35], [1,0,4,0]
			);
			$this->draw(
				'sphere', [$x,Ball::RADIUS + .012,$z], [Ball::RADIUS,Ball::RADIUS,Ball::RADIUS], [0,0,0,1]
				, $valid ? [.72,.92,.82,.72] : [1,.12,.08,.72], [.08,1.5,0,.2]
			);
		}

		if($simulation->phase === 'aim' || $game->stroking()) $this->cueAndGuide($game, $alpha);
		glBindVertexArray(0);
	}

	private function cueAndGuide(Game $game, float $alpha): void
	{
		$cue = $game->simulation->cue();
		if(!$cue->active) return;

		$stroke = $game->stroke($alpha);
		$idle = $stroke === null ? $game->idleCue() : null;
		$pose = Camera::cuePose(
			$stroke['x'] ?? $cue->x, $stroke['y'] ?? $cue->y, $stroke['aim'] ?? $idle['aim'] ?? $game->aim
		);
		$offset = $stroke['offset'] ?? $idle['offset'] ?? $game->cueDraw();
		foreach($pose['tip'] as $index => $value) $pose['tip'][$index] = $value - $pose['direction'][$index] * $offset;
		// The cue mesh is built in metres with its tip at the origin, so it sits at the (drawn-back) tip unscaled; the
		// tint carries the shooter's accent colour into the veneers, rings, and inlays.
		$this->draw('cue', $pose['tip'], [1,1,1], $pose['rotation'], self::CUE_ACCENTS[$game->cuePlayer()], self::CUE);

		// The guide follows this browser's aim. A dawdling cue dances about it; an opponent's considering cue does not.
		if($stroke !== null || ($idle['kind'] ?? null) === 'considering') return;
		$preview = $game->shotPreview();
		$this->dashes($preview['path'], .03, .018, [1,.96,.86], .62, .0036);

		if($contact = $preview['contact'])
		{
			$this->draw(
				'disc', [$contact['x'],.0125,$contact['y']], [Ball::RADIUS * 1.04,1,Ball::RADIUS * 1.04], [0,0,0,1]
				, [1,.96,.86,.5], [0,0,8,0]
			);
			$this->draw(
				'sphere', [$contact['x'],Ball::RADIUS + .012,$contact['y']], [Ball::RADIUS,Ball::RADIUS,Ball::RADIUS], [0,0,0,1]
				, [.95,.95,.92,.2], [.08,1.5,0,.2]
			);
			[$dx,$dy] = $contact['direction'];
			$from = [$contact['objectX'] + $dx * Ball::RADIUS * 1.15,$contact['objectY'] + $dy * Ball::RADIUS * 1.15];
			$this->dashes([$from,[$from[0] + $dx * .2,$from[1] + $dy * .2]], .02, .014, [1,.84,.5], .55, .003);
		}
	}

	/**
	 * Draw a tapering dashed line on the cloth through cloth-plane points.
	 *
	 * @param list<array{float,float}> $points Cloth X/Y polyline.
	 * @param array{float,float,float} $color Unlit RGB tint.
	 */
	private function dashes(array $points, float $dash, float $gap, array $color, float $alpha, float $width): void
	{
		$total = 0.0;
		for($index = 1; $index < count($points); $index++)
		$total += hypot($points[$index][0] - $points[$index - 1][0], $points[$index][1] - $points[$index - 1][1]);
		if($total <= 1e-8 || !is_finite($total)) return;

		$travelled = 0.0;
		$phase = 0.0;
		for($index = 1; $index < count($points); $index++)
		{
			[$x1,$y1] = $points[$index - 1];
			[$x2,$y2] = $points[$index];
			$length = hypot($x2 - $x1, $y2 - $y1);
			if($length <= 1e-8 || !is_finite($length)) continue;
			$angle = atan2($y2 - $y1, $x2 - $x1);
			$rotation = [0,-sin($angle / 2),0,cos($angle / 2)];

			for($start = -$phase; $start < $length; $start += $dash + $gap)
			{
				$from = max(0, $start);
				$to = min($length, $start + $dash);
				if($to - $from <= 1e-6) continue;
				$middle = ($from + $to) / 2 / $length;
				$fade = 1 - .65 * (($travelled + ($from + $to) / 2) / $total);
				$this->draw(
					'cube', [$x1 + ($x2 - $x1) * $middle,.0135,$y1 + ($y2 - $y1) * $middle]
					, [$to - $from,.0012,$width], $rotation, [...$color,$alpha * $fade], [0,0,7,0]
				);
			}

			$phase = fmod($phase + $length, $dash + $gap);
			$travelled += $length;
		}
	}

	/**
	 * Submit packed per-instance transforms, tints, and skin layers for one mesh with a shared material.
	 *
	 * @param string $meshName Generated mesh name.
	 * @param string $instances Packed `GpuMesh::INSTANCE_FLOATS` floats per instance.
	 * @param int $count Number of instances.
	 * @param array{float,float,float,float} $material Shared material parameters.
	 */
	private function drawInstanced(string $meshName, string $instances, int $count, array $material): void
	{
		if($count < 1) return;
		$translucent = $material[2] > 3.5 && $material[2] < 4.5;
		glUniform1i($this->program->location('uInstanced'), 1);
		glUniform4f($this->program->location('uMaterial'), ...$material);
		if($translucent) glDepthMask(false);
		$this->meshes->get($meshName)->drawInstanced($instances, $count);
		if($translucent) glDepthMask(true);
		glUniform1i($this->program->location('uInstanced'), 0);
	}

	private function draw(
		string $meshName, array $position, array $scale, array $rotation, array $color
		, array $material = [.4,0,0,0]
	): void {
		$mesh = $this->meshes->get($meshName);
		$this->matrix4('uModel', Camera::model($position, $scale, $rotation));
		$this->matrix3('uNormal', Camera::normalMatrix($rotation, $scale));
		glUniform4f($this->program->location('uBase'), ...$color);
		glUniform4f($this->program->location('uMaterial'), ...$material);
		$translucent = ($material[2] > 3.5 && $material[2] < 4.5) || $material[2] > 6.5 || $color[3] < .99;
		if($translucent) glDepthMask(false);
		if($mesh->doubleSided) glDisable(GL_CULL_FACE);
		$mesh->draw();
		if($mesh->doubleSided) glEnable(GL_CULL_FACE);
		if($translucent) glDepthMask(true);
	}

	private function matrix4(string $name, array $values): void
	{
		glUniformMatrix4fv($this->program->location($name), 1, false, $values);
	}

	private function matrix3(string $name, array $values): void
	{
		glUniformMatrix3fv($this->program->location($name), 1, false, $values);
	}

	public function restore(): void
	{
		if($this->closed) return;
		$this->program->restore();
		$this->meshes->restore();
		$this->ballSkins->restore();
		$this->wood->restore();
		$this->felt->restore();
	}

	public function close(): void
	{
		if($this->closed) return;
		$this->closed = true;
		$this->felt->close();
		$this->wood->close();
		$this->ballSkins->close();
		$this->meshes->close();
		$this->program->close();
	}
}
