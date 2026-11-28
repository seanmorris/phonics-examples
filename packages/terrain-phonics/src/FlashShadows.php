<?php
declare(strict_types=1);
namespace TerrainPhonics;

use Phonics\Render\Program;
use Phonics\Render\RenderTarget;

/**
 * The shadows one lightning strike casts on the ground around it.
 *
 * A map is drawn once for a strike, before its first flash. Near the eye it reads the scene's fine grid of heights,
 * so cliffs cast shadows; beyond that grid it reads the coarse heights the sun's broad shadows use. Each point holds
 * the height below which the bolt cannot be seen there, and the ground shader compares a pixel's own height with it.
 */
final class FlashShadows
{
	/** Width of the square of ground the map covers, in meters, and the map's size in points. */
	public const SPAN = 8192., SIZE = 256;

	private ?Program $program = null;
	private ?RenderTarget $target = null;
	private array $vao = [], $center = [0., 0.], $range = [0., 1.];
	private string $key = '';
	/** Maps drawn so far, for a caller that wants to know when one was made. */
	public int $generation = 0;

	/**
	 * Create the map and the program that draws it.
	 *
	 * @return void Leaves the map empty until the first update().
	 */
	public function __construct()
	{
		$root = dirname(__DIR__) . '/shaders/';

		try
		{
			$this->program = new Program(
				file_get_contents($root . 'shadows.vert')
				, file_get_contents($root . 'flash-shadows.frag')
			);

			$this->target = new RenderTarget(self::SIZE, self::SIZE, GL_LINEAR, false, 'Lightning shadows');
			glGenVertexArrays(1, $this->vao);
		}
		catch(\Throwable $error)
		{
			$this->close();

			throw $error;
		}
	}

	/**
	 * Draw the map for a strike unless it already holds that strike.
	 *
	 * @param int $heights The scene's coarse height texture. Zero leaves the map as it is.
	 * @param float $extent Width of the square world the height texture covers, in meters.
	 * @param bool $packed Whether the heights are stored in RGBA8.
	 * @param int $revision Revision of the landscape the heights hold.
	 * @param array $light The light's X, height and Z in meters.
	 * @param string $strike A name for the strike, so one strike is drawn once.
	 * @param array $range The lowest and highest ground in the landscape, in meters.
	 * @param ?array $fine The fine grid near the eye from Clipmap::fine(), or null to use the coarse heights alone.
	 * @return bool Whether the map was drawn. The caller resets the viewport, program, vertex array, textures and
	 * the depth, cull, blend and scissor state before drawing its scene.
	 */
	public function update(
		int $heights
		, float $extent
		, bool $packed
		, int $revision
		, array $light
		, string $strike
		, array $range
		, ?array $fine = null
	): bool {
		if($heights === 0 || $this->program === null) return false;

		// A strike is drawn once. The fine grid follows the eye, and its moving on does not redraw the map.
		$key = "$heights:$extent:$revision:$strike:" . (int)$packed;

		if($key === $this->key) return false;

		// Shadow heights above the highest ground make no difference: everything there is hidden already.
		$this->range = [$range[0] - 1., max(1., $range[1] - $range[0] + 2.)];
		[$texture, $step, $left, $near, $right, $back] = $fine ?? [$heights, 1., 0., 0., 0., 0.];

		glDisable(GL_DEPTH_TEST);
		glDisable(GL_CULL_FACE);
		glDisable(GL_BLEND);
		glDisable(GL_SCISSOR_TEST);
		glDepthMask(false);

		$this->program->use();
		glBindVertexArray($this->vao[0]);
		glActiveTexture(GL_TEXTURE0 + 1);
		glBindTexture(GL_TEXTURE_2D, $heights);
		glActiveTexture(GL_TEXTURE0);
		glBindTexture(GL_TEXTURE_2D, $texture);

		glUniform1i($this->program->location('uTerrainHeights'), 1);
		glUniform1i($this->program->location('uFine'), 0);
		glUniform1i($this->program->location('uPacked'), (int)$packed);
		glUniform1f($this->program->location('uTerrainShadowExtent'), $extent);
		glUniform4f($this->program->location('uBoltShadow'), $light[0], $light[1], $light[2], self::SPAN);
		glUniform1f($this->program->location('uBoltShadowSize'), self::SIZE);

		glUniform2f($this->program->location('uShadowHeightRange'), ...$this->range);
		glUniform4f($this->program->location('uFineBox'), $left, $near, $right, $back);
		glUniform1f($this->program->location('uFineStep'), $step);
		$this->target->bind();

		try
		{
			glDrawArrays(GL_TRIANGLES, 0, 3);
		}
		finally
		{
			$this->target->unbind();
		}

		$this->key = $key;
		$this->center = [$light[0], $light[2]];
		$this->generation++;

		return true;
	}

	/**
	 * Hand the map to a ground program that is in use.
	 *
	 * @param Program $program Program declaring uBoltShadowMap, uBoltShadowAt and uBoltShadowRange.
	 * @param int $unit Texture unit. The ground's other textures use units 0 to 15, and the cached sky takes four
	 * in a row from unit 2 after this is bound, so the default is the first unit past them all.
	 * @return void No value.
	 */
	public function bind(Program $program, int $unit = 16): void
	{
		if($this->target === null) return;

		glActiveTexture(GL_TEXTURE0 + $unit);
		glBindTexture(GL_TEXTURE_2D, $this->target->texture());
		glUniform1i($program->location('uBoltShadowMap'), $unit);
		glUniform3f($program->location('uBoltShadowAt'), $this->center[0], $this->center[1], self::SPAN);
		glUniform2f($program->location('uBoltShadowRange'), ...$this->range);
		glActiveTexture(GL_TEXTURE0);
	}

	/**
	 * Release the map, its program and its vertex array.
	 *
	 * @return void No value.
	 */
	public function close(): void
	{
		$this->key = '';
		$this->target?->close();
		$this->program?->close();
		$this->target = $this->program = null;

		if($this->vao) glDeleteVertexArrays(1, $this->vao);

		$this->vao = [];
	}
}
