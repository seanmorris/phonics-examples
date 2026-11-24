<?php
declare(strict_types=1);
namespace TerrainPhonics;

use Phonics\Render\Atlas;
use Phonics\Render\Program;
use Phonics\Render\RenderTarget;

/** Unlit pigment and surface normals for a single frame of a joined clipmap. Both use the same depth buffer. */
final class SurfaceSampleBuffer
{
	private RenderTarget $target;
	private int $normals = 0;

	public function __construct(int $width, int $height)
	{
		glActiveTexture(GL_TEXTURE0+13);
		$this->target = new RenderTarget($width,$height,GL_NEAREST,true,'Terrain surface samples');
		try {
			glActiveTexture(GL_TEXTURE0+14);
			$this->normals = Atlas::texture(GL_TEXTURE_2D);
			glTexStorage2D(GL_TEXTURE_2D,1,GL_RGBA8,$width,$height);
			$this->target->bind();
			try {
				$second = GL_COLOR_ATTACHMENT0+1;
				glFramebufferTexture2D(GL_FRAMEBUFFER,$second,GL_TEXTURE_2D,$this->normals,0);
				glDrawBuffers(2,[GL_COLOR_ATTACHMENT0,$second]);
				if(glCheckFramebufferStatus(GL_FRAMEBUFFER) !== GL_FRAMEBUFFER_COMPLETE)
					throw new \RuntimeException('Incomplete terrain surface framebuffer');
			} finally {
				$this->target->unbind();
			}
		} catch(\Throwable $error) {
			$this->close();
			throw $error;
		}
	}

	public function fits(int $width, int $height): bool
	{
		return $this->target->width === $width && $this->target->height === $height;
	}

	public function begin(): void
	{
		$clear = glGetFloatv(GL_COLOR_CLEAR_VALUE);
		$this->target->bind([0.,0.,0.,0.]);
		glClearColor(...$clear);
	}

	public function end(int $width, int $height): void
	{
		$this->target->unbind($width,$height);
	}

	public function bind(Program $program, int $width, int $height): void
	{
		glActiveTexture(GL_TEXTURE0+13);
		glBindTexture(GL_TEXTURE_2D,$this->target->texture());
		glUniform1i($program->location('uMaterialPigment'),13);
		glActiveTexture(GL_TEXTURE0+14);
		glBindTexture(GL_TEXTURE_2D,$this->normals);
		glUniform1i($program->location('uMaterialNormal'),14);
		glUniform2f($program->location('uSurfacePixel'),1/$width,1/$height);
	}

	public function close(): void
	{
		$this->target->close();
		if($this->normals) glDeleteTextures(1,[$this->normals]);
		$this->normals = 0;
	}
}
