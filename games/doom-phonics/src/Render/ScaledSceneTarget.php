<?php
declare(strict_types=1);
namespace DoomPhonics\Render;

use Phonics\Render\Program;
use Phonics\Render\RenderTarget;

/** Render the three-dimensional scene below output resolution and upscale it with nearest sampling. */
final class ScaledSceneTarget
{
	private Program $program;
	/** @var list<int> */
	private RenderTarget $target;
	private array $vao = [];
	private bool $closed = false;
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

		if($this->target->bound()) throw new \LogicException('Scaled scene target cannot resize while capturing');

		$this->setViewport($viewport);
		$this->target->resize($this->width, $this->height);

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
		$this->target = new RenderTarget($this->width, $this->height, GL_NEAREST, true, 'Doom-Phonics scaled scene');
		glGenVertexArrays(1, $this->vao);
		$this->program->use();
		glUniform1i($this->program->location('scene'), 4);
		glUseProgram(0);
	}

	/** Redirect scene rendering to the smaller color and depth target. */
	public function begin(): void
	{
		if($this->closed || $this->target->bound()) throw new \LogicException('Scaled scene target cannot begin');

		$this->target->bind([0, 0, 0, 1]);
	}

	/** Restore the output target and present the scene with a single nearest-neighbor draw. */
	public function compose(): void
	{
		if(!$this->target->bound()) throw new \LogicException('Scaled scene target must begin before compose');

		$this->target->unbind($this->outputWidth, $this->outputHeight);
		glDisable(GL_DEPTH_TEST);
		glDepthMask(false);
		glDisable(GL_BLEND);
		$this->program->use();
		glBindVertexArray($this->vao[0]);
		glActiveTexture(GL_TEXTURE0 + 4);
		glBindTexture(GL_TEXTURE_2D, $this->target->texture());
		glDrawArrays(GL_TRIANGLES, 0, 3);
		glBindVertexArray(0);
		glActiveTexture(GL_TEXTURE0);
		glDepthMask(true);
	}

	public function restore(): void
	{
		if($this->closed) return;

		$this->release();
		$this->program->restore();
		$this->create();
	}

	public function close(): void
	{
		if($this->closed) return;

		$this->closed = true;
		$this->release();
		$this->program->close();
	}

	private function release(): void
	{
		$this->target->close();

		if($this->vao) glDeleteVertexArrays(1, $this->vao);

		$this->vao = [];
	}
}
