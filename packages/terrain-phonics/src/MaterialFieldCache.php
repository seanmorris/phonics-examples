<?php
declare(strict_types=1);
namespace TerrainPhonics;

use Phonics\Render\Atlas;
use Phonics\Render\Program;

/** Persistent world-space noise bands. Height, paint, lighting and screen-space filtering stay live. */
final class MaterialFieldCache
{
	// A 512 m window moves in 32 m strips. Each texel stores three regional fields and the seam in 16 bytes.
	private const SIZE = 256, STEP = 2, SNAP = 16;
	private Program $program;
	private array $framebuffer = [], $regions = [];
	private int $texture = 0;
	private ?array $origin = null;
	public int $texelsWritten = 0;

	public function __construct()
	{
		$root = dirname(__DIR__).'/shaders/';
		$fragment = str_replace(['// LAYERS','// MATERIAL NOISE'],
			[file_get_contents($root.'surface-layers.glsl'),file_get_contents($root.'material-noise.glsl')],
			file_get_contents($root.'material-cache.frag'));
		$this->program = new Program(file_get_contents($root.'shadows.vert'),$fragment);
		$parent = glGetIntegerv(GL_FRAMEBUFFER_BINDING);
		try {
			glActiveTexture(GL_TEXTURE0+12);
			$this->texture = Atlas::texture(GL_TEXTURE_2D_ARRAY);
			glTexStorage3D(GL_TEXTURE_2D_ARRAY,1,GL_RGBA8,self::SIZE,self::SIZE,4);
			glTexParameteri(GL_TEXTURE_2D_ARRAY,GL_TEXTURE_MIN_FILTER,GL_LINEAR);
			glTexParameteri(GL_TEXTURE_2D_ARRAY,GL_TEXTURE_MAG_FILTER,GL_LINEAR);
			glTexParameteri(GL_TEXTURE_2D_ARRAY,GL_TEXTURE_WRAP_S,GL_REPEAT);
			glTexParameteri(GL_TEXTURE_2D_ARRAY,GL_TEXTURE_WRAP_T,GL_REPEAT);
			glGenFramebuffers(1,$this->framebuffer);
			glBindFramebuffer(GL_FRAMEBUFFER,$this->framebuffer[0]);
			$attachments = [];
			for($layer=0;$layer<4;$layer++) {
				$attachments[] = GL_COLOR_ATTACHMENT0+$layer;
				glFramebufferTextureLayer(GL_FRAMEBUFFER,GL_COLOR_ATTACHMENT0+$layer,$this->texture,0,$layer);
			}
			glDrawBuffers(4,$attachments);
			if(glCheckFramebufferStatus(GL_FRAMEBUFFER)!==GL_FRAMEBUFFER_COMPLETE)
				throw new \RuntimeException('Incomplete terrain material field framebuffer');
		} catch(\Throwable $error) {
			$this->close();
			throw $error;
		} finally {
			glBindFramebuffer(GL_FRAMEBUFFER,$parent);
		}
	}

	public function update(array $eye, array $materials, int $vao): void
	{
		$snap = self::STEP*self::SNAP;
		$half = intdiv(self::SIZE,2);
		$origin = [(int)floor($eye[0]/$snap)*self::SNAP-$half, (int)floor($eye[2]/$snap)*self::SNAP-$half];
		$regions = [];
		foreach($materials as [, $recipe, $local]) $regions[] = [$local[0],$local[2],$recipe['seed']];
		if($this->origin === $origin && $this->regions === $regions) return;
		$p = $this->program;
		$p->use();
		if($this->regions !== $regions) {
			foreach($regions as $i=>[$x,$z,$seed]) {
				$hash=crc32((string)$seed);
				glUniform4f($p->location("uRegions[$i]"),$x,$z,$hash&65535,($hash>>16)&65535);
			}
			$this->origin = null;
			$this->regions = $regions;
		}
		$parent = glGetIntegerv(GL_FRAMEBUFFER_BINDING);
		glBindFramebuffer(GL_FRAMEBUFFER,$this->framebuffer[0]);
		try {
			glBindVertexArray($vao);
			glDisable(GL_DEPTH_TEST);
			glDisable(GL_BLEND);
			glDisable(GL_CULL_FACE);
			glDisable(GL_SCISSOR_TEST);
			glDisable(GL_DITHER);
			glUniform1f($p->location('uStep'),self::STEP);
			$exposed = Clipmap::exposed($origin[0],$origin[1],self::SIZE,$this->origin);
			foreach(Clipmap::wrapped($exposed,self::SIZE) as [$x,$z,$u,$v,$w,$h]) {
				glViewport($u,$v,$w,$h);
				glUniform2f($p->location('uCellOffset'),$x-$u,$z-$v);
				glDrawArrays(GL_TRIANGLES,0,3);
				$this->texelsWritten += $w*$h;
			}
		} finally {
			glBindFramebuffer(GL_FRAMEBUFFER,$parent);
		}
		$this->origin = $origin;
	}

	public function bind(Program $p): void
	{
		glActiveTexture(GL_TEXTURE0+12);
		glBindTexture(GL_TEXTURE_2D_ARRAY,$this->texture);
		glUniform1i($p->location('uMaterialFields'),12);
		glUniform4f($p->location('uFieldWindow'),$this->origin[0]*self::STEP,$this->origin[1]*self::STEP,self::STEP,1/self::SIZE);
	}

	public function close(): void
	{
		if($this->framebuffer) glDeleteFramebuffers(1,$this->framebuffer);
		if($this->texture) glDeleteTextures(1,[$this->texture]);
		$this->program->close();
		$this->texture=0;
		$this->framebuffer=[];
	}
}
