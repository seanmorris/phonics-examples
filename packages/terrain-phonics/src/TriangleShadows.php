<?php
declare(strict_types=1);
namespace TerrainPhonics;

use Phonics\Render\Program;
use Phonics\Render\RenderTarget;

/** Shared visibility probes on a world-aligned terrain grid and the two refined triangles of each cell. */
final class TriangleShadows
{
	private const CELLS = 64;
	private Program $program;
	private RenderTarget $vertices, $triangles;
	private array $key = [], $direction = [], $grid = [];
	public int $generation = 0;

	public function __construct()
	{
		$root = dirname(__DIR__).'/shaders/';
		try
		{
			$this->program = new Program(file_get_contents($root.'shadows.vert'),file_get_contents($root.'triangle-shadows.frag'));
			$this->vertices = new RenderTarget(self::CELLS+1,self::CELLS+1,GL_NEAREST,false,'Terrain shadow vertices');
			$this->triangles = new RenderTarget(self::CELLS*2,self::CELLS,GL_NEAREST,false,'Terrain shadow triangles');
		}
		catch(\Throwable $error)
		{
			$this->close();
			throw $error;
		}
	}

	public function invalidate(): void { $this->key = []; }

	/** The caster is a complete toroidal clipmap level. Both passes read only its resident interior. */
	public function update(array $level, int $span, array $eye, array $sun, int $overview, float $extent,
		bool $packed, float $playHalf, int $revision, int $vao): void
	{
		$step = $level['step'];
		// Snap in eight-texel blocks. Leave room on every side for the same rays after a recenter.
		$cx = (int)round($eye[0]/(8*$step))*8;
		$cz = (int)round($eye[2]/(8*$step))*8;
		$safeRadius = intdiv($span,2)-6;
		$cell = 2 ** floor(log($safeRadius/self::CELLS,2));
		$half = self::CELLS*$cell/2;
		$reach = (int)floor($safeRadius-$half);
		$grid = [($cx-$half)*$step,($cz-$half)*$step,$cell*$step];
		$key = [$grid,$step,$reach,$extent,$playHalf,$level['textures'][0],$overview,$revision];
		// Allow at most 0.1 degree of drift while the clock runs.
		if($key === $this->key && $sun[0]*$this->direction[0]+$sun[1]*$this->direction[1]+$sun[2]*$this->direction[2] >= .999998477)
			return;

		$this->invalidate();
		$p = $this->program;
		$p->use();
		glBindVertexArray($vao);
		glDisable(GL_SCISSOR_TEST);
		glDisable(GL_DEPTH_TEST);
		glDisable(GL_BLEND);
		glDisable(GL_CULL_FACE);
		glDisable(GL_DITHER);
		glDepthMask(false);
		foreach([$level['textures'][0],$overview] as $unit=>$texture)
		{
			glActiveTexture(GL_TEXTURE0+$unit);
			glBindTexture(GL_TEXTURE_2D,$texture);
		}
		glUniform1i($p->location('uCaster'),0);
		glUniform1i($p->location('uOverview'),1);
		glUniform1i($p->location('uPacked'),(int)$packed);
		glUniform1f($p->location('uCasterStep'),$step);
		glUniform1f($p->location('uReach'),$reach);
		glUniform1f($p->location('uExtent'),$extent);
		glUniform1f($p->location('uPlayHalf'),$playHalf);
		glUniform3f($p->location('uShadowGrid'),...$grid);
		glUniform3f($p->location('uSun'),...$sun);

		foreach([$this->vertices,$this->triangles] as $refine=>$target)
		{
			// During the vertex pass point the unused input at the caster to avoid framebuffer feedback.
			glUniform1i($p->location('uVertices'),$refine ? 2 : 0);
			if($refine)
			{
				glActiveTexture(GL_TEXTURE0+2);
				glBindTexture(GL_TEXTURE_2D,$this->vertices->texture());
			}
			glUniform1i($p->location('uRefine'),$refine);
			$target->bind();
			try { glDrawArrays(GL_TRIANGLES,0,3); }
			finally { $target->unbind(); }
		}
		$this->grid = $grid;
		$this->direction = $sun;
		$this->key = $key;
		$this->generation++;
	}

	public function bind(Program $program): void
	{
		glUniform3f($program->location('uShadowGrid'), ...($this->key ? $this->grid : [0.,0.,0.]));
		glActiveTexture(GL_TEXTURE0+9);
		glBindTexture(GL_TEXTURE_2D,$this->triangles->texture());
		glUniform1i($program->location('uShadowTriangles'),9);
	}

	public function close(): void
	{
		if(isset($this->vertices)) $this->vertices->close();
		if(isset($this->triangles)) $this->triangles->close();
		if(isset($this->program)) $this->program->close();
	}
}
