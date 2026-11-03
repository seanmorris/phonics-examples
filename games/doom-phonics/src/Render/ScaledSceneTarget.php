<?php
declare(strict_types=1);
namespace DoomPhonics\Render;

use Phonics\Render\Atlas;
use Phonics\Render\Program;

/** Render the three-dimensional scene below output resolution and upscale it with nearest sampling. */
final class ScaledSceneTarget
{
	private Program $program;
	/** @var list<int> */
	private array $framebuffer = [], $depthBuffer = [], $vao = [];
	private int $texture = 0, $parentFramebuffer = 0;
	private bool $capturing = false, $closed = false;
	public int $width;
	public int $height;
	public int $outputWidth;
	public int $outputHeight;
	public float $scale;
	private RenderViewport $viewport;

	public function __construct(RenderViewport $viewport)
	{
		if($viewport->sceneScale >= 1)
		throw new \InvalidArgumentException('Scaled scene target requires positive dimensions and a scale below one');

		$this->setViewport($viewport);

		$this->program = new Program(
			file_get_contents(dirname(__DIR__, 2) . '/shaders/upscale.vert')
			, file_get_contents(dirname(__DIR__, 2) . '/shaders/upscale.frag')
		);

		$this->create();
	}

	/** Reallocate scene attachments only when either output or scene dimensions changed. */
	public function resize(RenderViewport $viewport): bool
	{
		if($viewport->sceneScale >= 1)
		throw new \InvalidArgumentException('Scaled scene target requires a scale below one');

		if($this->viewport->equals($viewport)) return false;

		if($this->capturing) throw new \LogicException('Scaled scene target cannot resize while capturing');

		$this->release();
		$this->setViewport($viewport);
		$this->create();

		return true;
	}

	private function setViewport(RenderViewport $viewport): void
	{
		$this->viewport = $viewport;
		$this->outputWidth = $viewport->outputWidth;
		$this->outputHeight = $viewport->outputHeight;
		$this->width = $viewport->sceneWidth;
		$this->height = $viewport->sceneHeight;
		$this->scale = $viewport->sceneScale;
	}

	private function create(): void
	{
		$this->texture = Atlas::texture(GL_TEXTURE_2D);
		glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MIN_FILTER, GL_NEAREST);
		glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MAG_FILTER, GL_NEAREST);
		glTexImage2D(GL_TEXTURE_2D, 0, GL_RGBA8, $this->width, $this->height, 0, GL_RGBA, GL_UNSIGNED_BYTE, null);
		glGenFramebuffers(1, $this->framebuffer);
		glBindFramebuffer(GL_FRAMEBUFFER, $this->framebuffer[0]);
		glFramebufferTexture2D(GL_FRAMEBUFFER, GL_COLOR_ATTACHMENT0, GL_TEXTURE_2D, $this->texture, 0);
		glGenRenderbuffers(1, $this->depthBuffer);
		glBindRenderbuffer(GL_RENDERBUFFER, $this->depthBuffer[0]);
		glRenderbufferStorage(GL_RENDERBUFFER, GL_DEPTH_COMPONENT24, $this->width, $this->height);
		glFramebufferRenderbuffer(GL_FRAMEBUFFER, GL_DEPTH_ATTACHMENT, GL_RENDERBUFFER, $this->depthBuffer[0]);

		if(glCheckFramebufferStatus(GL_FRAMEBUFFER) !== GL_FRAMEBUFFER_COMPLETE)
		throw new \RuntimeException('Doom-Phonics scaled scene framebuffer is incomplete');

		glBindRenderbuffer(GL_RENDERBUFFER, 0);
		glBindFramebuffer(GL_FRAMEBUFFER, 0);
		glGenVertexArrays(1, $this->vao);
		$this->program->use();
		glUniform1i($this->program->location('scene'), 4);
		glUseProgram(0);
	}

	/** Redirect scene rendering to the smaller color and depth target. */
	public function begin(): void
	{
		if($this->closed || $this->capturing) throw new \LogicException('Scaled scene target cannot begin');

		$this->parentFramebuffer = glGetIntegerv(GL_FRAMEBUFFER_BINDING);
		$this->capturing = true;
		glBindFramebuffer(GL_FRAMEBUFFER, $this->framebuffer[0]);
		glViewport(0, 0, $this->width, $this->height);
		glClearColor(0, 0, 0, 1);
		glClearDepth(1);
		glDepthMask(true);
		glClear(GL_COLOR_BUFFER_BIT | GL_DEPTH_BUFFER_BIT);
	}

	/** Restore the output target and present the scene with a single nearest-neighbor draw. */
	public function compose(): void
	{
		if(!$this->capturing) throw new \LogicException('Scaled scene target must begin before compose');

		$this->capturing = false;
		glBindFramebuffer(GL_FRAMEBUFFER, $this->parentFramebuffer);
		glViewport(0, 0, $this->outputWidth, $this->outputHeight);
		glDisable(GL_DEPTH_TEST);
		glDepthMask(false);
		glDisable(GL_BLEND);
		$this->program->use();
		glBindVertexArray($this->vao[0]);
		glActiveTexture(GL_TEXTURE0 + 4);
		glBindTexture(GL_TEXTURE_2D, $this->texture);
		glDrawArrays(GL_TRIANGLES, 0, 3);
		glBindVertexArray(0);
		glActiveTexture(GL_TEXTURE0);
		glDepthMask(true);
	}

	public function restore(): void
	{
		if($this->closed) return;

		$this->capturing = false;
		$this->release();
		$this->program->restore();
		$this->create();
	}

	public function close(): void
	{
		if($this->closed) return;

		$this->closed = true;
		$this->capturing = false;
		$this->release();
		$this->program->close();
	}

	private function release(): void
	{
		if($this->texture) glDeleteTextures(1, [$this->texture]);

		if($this->framebuffer) glDeleteFramebuffers(1, $this->framebuffer);

		if($this->depthBuffer) glDeleteRenderbuffers(1, $this->depthBuffer);

		if($this->vao) glDeleteVertexArrays(1, $this->vao);

		$this->texture = 0;
		$this->framebuffer = $this->depthBuffer = $this->vao = [];
	}
}
