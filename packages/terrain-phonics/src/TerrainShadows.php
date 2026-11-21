<?php
declare(strict_types=1);
namespace TerrainPhonics;

use Phonics\Render\Program;
use Phonics\Render\RenderTarget;

/**
 * Cached broad mountain visibility and projected blocker elevation from the scene's height overview.
 *
 * A bounded directional march runs only when height revision, world extent or sun direction changes. The
 * resulting RGBA8 map replaces per-fragment marching with one filtered lookup; it cannot resolve local detail.
 */
final class TerrainShadows
{
	public const SUN_COSINE = .999998477; // cos(0.1 degree), measured from the last completed map.
	private ?Program $program = null;
	private ?RenderTarget $target = null;
	private array $vao = [];
	private string $key = '';
	private float $extent = 0;
	private array $sun = [], $heightRange = [0.,1.];
	private int $generation = 0;
	private bool $closed = false;

	/**
	 * Allocate a bounded RGBA8 visibility target and its height-field integration program.
	 * @param int $resolution Square map size, between sixteen and 1024 pixels.
	 * @return void Leaves map contents invalid until the first successful update().
	 */
	public function __construct(private int $resolution = 256)
	{
		if($resolution < 16 || $resolution > 1024)
		throw new \InvalidArgumentException('Unsupported terrain shadow resolution');

		$root = dirname(__DIR__) . '/shaders/';

		try
		{
			$this->program = new Program(
				file_get_contents($root . 'shadows.vert')
				, file_get_contents($root . 'shadows.frag')
			);

			$this->target = new RenderTarget($resolution, $resolution, GL_LINEAR, false, 'Broad terrain shadows');
			glGenVertexArrays(1, $this->vao);
		}
		catch(\Throwable $error)
		{
			$this->close();
			throw $error;
		}
	}

	/**
	 * Refresh broad visibility after the authoritative coarse height field or illumination direction changes.
	 * @param int $heightTexture Scene-owned overview texture. Zero disables caching until ready.
	 * @param float $extent Width and depth of the centered square world, in meters.
	 * @param array $sunDirection Direction toward the sun as [x,y,z]; normalized before use.
	 * @param int $revision Revision represented by the uploaded height field.
	 * @param bool $packed Whether height samples are stored in RGBA8.
	 * @param float $minHeight Conservative minimum elevation of the sampled terrain, in meters.
	 * @param float $maxHeight Conservative maximum elevation of the sampled terrain, in meters.
	 * @return bool True if the map was regenerated. The parent framebuffer is restored; the caller resets viewport,
	 * program, VAO, textures, depth mask and depth/cull/blend/scissor enables before rendering its scene.
	 */
	public function update(int $heightTexture, float $extent, array $sunDirection, int $revision, bool $packed = false,
		float $minHeight = -32768., float $maxHeight = 32767.): bool
	{
		if($this->closed) throw new \LogicException('Terrain shadows are closed');

		if($heightTexture < 0 || !is_finite($extent) || $extent <= 0 || count($sunDirection) !== 3)
		throw new \InvalidArgumentException('Terrain shadows require a valid texture, extent and sun direction');
		if(!is_finite($minHeight) || !is_finite($maxHeight) || $minHeight > $maxHeight)
		throw new \InvalidArgumentException('Invalid terrain elevation bounds');

		foreach($sunDirection as $value)
		if(!is_numeric($value) || !is_finite((float)$value))
		throw new \InvalidArgumentException('Invalid sun direction');
		$length = 0.0;

		foreach($sunDirection as $value) $length += (float)$value * (float)$value;
		$length = sqrt($length);

		if(!is_finite($length) || $length < 1e-9) throw new \InvalidArgumentException('Sun direction cannot be zero');

		$sun = [];

		foreach($sunDirection as $value) $sun[] = (float)$value / $length;

		if($heightTexture === 0)
		{
			$this->key = '';

			return false;
		}

		$range = [$minHeight,max(1.,$maxHeight-$minHeight)];
		$key = json_encode([$heightTexture,$extent,$revision,$packed,$range,$sun[1]>0.], JSON_THROW_ON_ERROR);
		if($key === $this->key && ($sun[1] <= 0. ||
			$sun[0]*$this->sun[0]+$sun[1]*$this->sun[1]+$sun[2]*$this->sun[2] >= self::SUN_COSINE)) return false;

		$this->key = '';
		glDisable(GL_DEPTH_TEST);
		glDisable(GL_CULL_FACE);
		glDisable(GL_BLEND);
		glDisable(GL_SCISSOR_TEST);
		glDisable(GL_DITHER);
		glDepthMask(false);
		$this->program->use();
		glBindVertexArray($this->vao[0]);
		glActiveTexture(GL_TEXTURE0 + 1);
		glBindTexture(GL_TEXTURE_2D, $heightTexture);
		glUniform1i($this->program->location('uTerrainHeights'), 1);
		glUniform1i($this->program->location('uPacked'),(int)$packed);
		glUniform1f($this->program->location('uTerrainShadowExtent'), $extent);
		glUniform1f($this->program->location('uTerrainShadowSize'), $this->resolution);
		glUniform2f($this->program->location('uShadowHeightRange'), ...$range);
		glUniform3f($this->program->location('uShadowSunDirection'), ...$sun);
		$this->target->bind();

		try
		{
			glDrawArrays(GL_TRIANGLES, 0, 3);
		}
		finally
		{
			$this->target->unbind();
		}
		// Average distant coverage over the pixel footprint on a fixed world grid.
		glBindTexture(GL_TEXTURE_2D, $this->target->texture());
		glGenerateMipmap(GL_TEXTURE_2D);
		glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MIN_FILTER, GL_LINEAR_MIPMAP_LINEAR);

		$this->key = $key;
		$this->extent = $extent;
		$this->heightRange = $range;
		$this->sun = $sun;
		$this->generation++;

		return true;
	}

	/**
	 * Bind broad visibility on an already-current terrain program.
	 * @param Program $program Consumer declaring uTerrainShadowMap, uTerrainShadowExtent and uTerrainShadowReady.
	 * @param int $textureUnit Texture unit; six follows the terrain and atmosphere caches.
	 * @return void An unpopulated cache selects unshadowed sunlight in the terrain shader.
	 */
	public function bind(Program $program, int $textureUnit = 6): void
	{
		if($this->closed) throw new \LogicException('Terrain shadows are closed');

		if($textureUnit < 0) throw new \InvalidArgumentException('Texture unit must be nonnegative');

		glActiveTexture(GL_TEXTURE0 + $textureUnit);
		glBindTexture(GL_TEXTURE_2D, $this->target->texture());
		glUniform1i($program->location('uTerrainShadowMap'), $textureUnit);
		glUniform1f($program->location('uTerrainShadowExtent'), $this->extent);
		glUniform2f($program->location('uShadowHeightRange'), ...$this->heightRange);
		glUniform1i($program->location('uTerrainShadowReady'), $this->key !== '' ? 1 : 0);
	}

	/**
	 * Inspect cached visibility without reading GPU pixels.
	 * @return array Resolution, extent, completed-render generation and readiness.
	 */
	public function stats(): array
	{
		return [
			'resolution' => $this->resolution
			, 'extent' => $this->extent
			, 'generation' => $this->generation
			, 'ready' => !$this->closed && $this->key !== ''
		];
	}

	/**
	 * Rebuild GL resources after context restoration and invalidate lost visibility data.
	 * @return void The next update() regenerates the map; closed instances remain closed.
	 */
	public function restore(): void
	{
		if($this->closed) return;

		$this->key = '';
		$this->program->restore();
		$this->target->restore();

		if($this->vao) glDeleteVertexArrays(1, $this->vao);

		glGenVertexArrays(1, $this->vao);
	}

	/**
	 * Release the owned visibility target, integration program and vertex array once.
	 * @return void Leaves the externally owned height texture untouched.
	 */
	public function close(): void
	{
		if($this->closed) return;

		$this->closed = true;
		$this->key = '';
		$this->target?->close();
		$this->program?->close();

		if($this->vao) glDeleteVertexArrays(1, $this->vao);

		$this->vao = [];
	}
}
