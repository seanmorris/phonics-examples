<?php
declare(strict_types=1);
namespace PhpBilliards\Render;

use Phonics\Render\Program;

/**
 * Instanced screen-space renderer for the HUD's rounded panels, rings, gradients, and signed-distance text.
 *
 * One draw call submits a `UiCanvas` list with premultiplied blending. The generated font atlas is the only
 * texture, and the program, buffers, and atlas are rebuilt together after WebGL context restoration.
 */
final class UiRenderer
{
	private const FLOATS = 20;
	private Program $program;
	private Texture $font;
	private array $vao = [], $buffers = [];
	private bool $closed = false;
	/** Draw list whose packed instances currently occupy the GPU buffer. */
	private ?UiCanvas $uploaded = null;

	/**
	 * @param string $root Absolute root directory of the Billiards package.
	 * @param array<string,mixed> $descriptor `uiFont` entry from `textures.json`.
	 */
	public function __construct(string $root, array $descriptor)
	{
		$this->program = new Program(
			file_get_contents($root . '/shaders/ui.vert')
			, file_get_contents($root . '/shaders/ui.frag')
		);
		$bytes = file_get_contents($root . '/assets/generated/' . $descriptor['file']);
		if(isset($descriptor['sha256']) && !hash_equals($descriptor['sha256'], hash('sha256', (string)$bytes)))
		throw new \RuntimeException('Billiards font atlas checksum mismatch');
		$this->font = new Texture((string)$bytes, (int)$descriptor['width'], (int)$descriptor['height'], false, false);
		$this->create();
	}

	private function create(): void
	{
		glGenVertexArrays(1, $this->vao);
		glGenBuffers(1, $this->buffers);
		glBindVertexArray($this->vao[0]);
		glBindBuffer(GL_ARRAY_BUFFER, $this->buffers[0]);

		for($attribute = 0; $attribute < 5; $attribute++)
		{
			glVertexAttribPointer($attribute, 4, GL_FLOAT, false, self::FLOATS * 4, $attribute * 16);
			glEnableVertexAttribArray($attribute);
			glVertexAttribDivisor($attribute, 1);
		}

		glBindVertexArray(0);
	}

	/**
	 * Draw a canvas over the current framebuffer.
	 *
	 * @param UiCanvas $canvas Draw list built for this frame.
	 * @param int $width Framebuffer width in pixels.
	 * @param int $height Framebuffer height in pixels.
	 */
	public function render(UiCanvas $canvas, int $width, int $height): void
	{
		$instances = $canvas->instances();
		if(!$instances || $this->closed) return;

		// An unchanged draw list is already resident in the instance buffer; skip packing and uploading it again.
		$upload = $canvas !== $this->uploaded;
		if($upload)
		{
			$bytes = '';
			foreach($instances as $instance) $bytes .= pack('f*', ...$instance);
			$this->uploaded = $canvas;
		}

		glDisable(GL_DEPTH_TEST);
		glDisable(GL_CULL_FACE);
		glEnable(GL_BLEND);
		glBlendFunc(GL_ONE, GL_ONE_MINUS_SRC_ALPHA);
		$this->program->use();
		glUniform2f($this->program->location('uViewport'), $width, $height);
		glActiveTexture(GL_TEXTURE0);
		$this->font->bind();
		glUniform1i($this->program->location('uFont'), 0);
		glBindVertexArray($this->vao[0]);
		glBindBuffer(GL_ARRAY_BUFFER, $this->buffers[0]);
		if($upload) glBufferData(GL_ARRAY_BUFFER, strlen($bytes), $bytes, GL_DYNAMIC_DRAW);
		glDrawArraysInstanced(GL_TRIANGLES, 0, 6, count($instances));
		glBindVertexArray(0);
		glBlendFunc(GL_SRC_ALPHA, GL_ONE_MINUS_SRC_ALPHA);
	}

	private function release(): void
	{
		if($this->vao) glDeleteVertexArrays(count($this->vao), $this->vao);
		if($this->buffers) glDeleteBuffers(count($this->buffers), $this->buffers);
		$this->vao = $this->buffers = [];
		$this->uploaded = null;
	}

	public function restore(): void
	{
		if($this->closed) return;
		$this->release();
		$this->program->restore();
		$this->font->restore();
		$this->create();
	}

	public function close(): void
	{
		if($this->closed) return;
		$this->closed = true;
		$this->release();
		$this->program->close();
		$this->font->close();
	}
}
