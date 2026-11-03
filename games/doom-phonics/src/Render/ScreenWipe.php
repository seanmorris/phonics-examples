<?php
declare(strict_types=1);
namespace DoomPhonics\Render;

use Phonics\Render\Atlas;
use Phonics\Render\Program;

/** Ping-pong full-frame compositor for classic staggered-column screen melts. */
final class ScreenWipe
{
	private Program $program;
	private MeltColumns $columns;
	/** @var list<int> */
	private array $textures = [], $framebuffers = [], $depthBuffers = [], $vao = [];
	private int $front = -1;
	private int $drawing = -1;
	private int $source = -1;
	private int $parentFramebuffer = 0;
	private int $uploadedRevision = -1;
	private int $seed = 1;
	private int $transitions = 0;
	private bool $capturing = false;
	private bool $transitioning = false;
	private bool $closed = false;

	public function __construct(public int $width, public int $height)
	{
		if($width < 1 || $height < 1)
		throw new \InvalidArgumentException('Screen-wipe dimensions must be positive');

		$this->columns = new MeltColumns;

		$this->program = new Program(
			file_get_contents(dirname(__DIR__, 2) . '/shaders/wipe.vert')
			, file_get_contents(dirname(__DIR__, 2) . '/shaders/wipe.frag')
		);

		$this->create();
	}

	/** Rebuild full-frame attachments after the output framebuffer changes size. */
	public function resize(int $width, int $height): bool
	{
		if($width < 1 || $height < 1)
		throw new \InvalidArgumentException('Screen-wipe dimensions must be positive');

		if($this->width === $width && $this->height === $height) return false;

		if($this->capturing) throw new \LogicException('Screen wipe cannot resize while capturing');

		$parentFramebuffer = glGetIntegerv(GL_FRAMEBUFFER_BINDING);
		$oldTextures = $this->textures;
		$oldFramebuffers = $this->framebuffers;
		$oldDepthBuffers = $this->depthBuffers;
		$oldVao = $this->vao;
		$front = $this->front;
		$source = $this->source;
		$transitioning = $this->transitioning;
		$this->textures = $this->framebuffers = $this->depthBuffers = $this->vao = [];
		$this->width = $width;
		$this->height = $height;
		$this->drawing = -1;
		$this->uploadedRevision = -1;
		$this->create();

		$slots = $transitioning && $source >= 0 ? [$source,1 - $source] : ($front >= 0 ? [$front] : []);

		foreach(array_unique($slots) as $slot)
		{
			if(!isset($oldTextures[$slot], $this->framebuffers[$slot])) continue;

			glBindFramebuffer(GL_FRAMEBUFFER, $this->framebuffers[$slot]);
			glViewport(0, 0, $width, $height);
			glDisable(GL_DEPTH_TEST);
			glDepthMask(false);
			glDisable(GL_BLEND);
			$this->draw($oldTextures[$slot], false);
		}

		glBindFramebuffer(GL_FRAMEBUFFER, $parentFramebuffer);
		glViewport(0, 0, $width, $height);
		glBindVertexArray(0);
		glActiveTexture(GL_TEXTURE0);
		glDepthMask(true);
		$this->deleteResources($oldTextures, $oldFramebuffers, $oldDepthBuffers, $oldVao);
		$this->front = $front;
		$this->source = $source;
		$this->transitioning = $transitioning;

		return true;
	}

	/** Preserve the latest completed frame and start a fresh classic melt. */
	public function begin(): bool
	{
		if($this->closed || $this->transitioning || $this->front < 0) return false;

		$this->source = $this->front;
		$this->columns->restart($this->seed++);
		$this->transitioning = true;
		$this->transitions++;

		return true;
	}

	public function advance(float $elapsed): void
	{
		if($this->transitioning) $this->columns->advance($elapsed);
	}

	public function active(): bool { return $this->transitioning; }

	/**
	 * Describe the wipe for diagnostics, including how many wipes have begun since creation.
	 *
	 * @return array{active:bool,progress:float,columns:int,transitions:int} Current wipe state and lifetime count.
	 */
	public function status(): array
	{
		return [
			'active' => $this->transitioning
			, 'progress' => $this->transitioning ? $this->columns->progress() : 1.0
			, 'columns' => $this->columns->columns
			, 'transitions' => $this->transitions
		];
	}

	/** Bind the target texture that receives the complete incoming frame. */
	public function beginFrame(): void
	{
		if($this->closed || $this->capturing)
		throw new \LogicException('Screen wipe cannot begin a frame');

		$this->parentFramebuffer = glGetIntegerv(GL_FRAMEBUFFER_BINDING);
		$this->drawing = $this->transitioning ? 1 - $this->source : ($this->front < 0 ? 0 : 1 - $this->front);
		$this->capturing = true;
		glBindFramebuffer(GL_FRAMEBUFFER, $this->framebuffers[$this->drawing]);
		glViewport(0, 0, $this->width, $this->height);
		glClearColor(0, 0, 0, 1);
		glClearDepth(1);
		glDepthMask(true);
		glClear(GL_COLOR_BUFFER_BIT | GL_DEPTH_BUFFER_BIT);
	}

	/** Present the incoming frame and overlay the retained outgoing columns while melting. */
	public function present(): void
	{
		if(!$this->capturing) throw new \LogicException('Screen wipe has no frame to present');

		$this->capturing = false;
		glBindFramebuffer(GL_FRAMEBUFFER, $this->parentFramebuffer);
		glViewport(0, 0, $this->width, $this->height);
		glDisable(GL_DEPTH_TEST);
		glDepthMask(false);
		glDisable(GL_BLEND);
		$this->draw($this->textures[$this->drawing], false);

		if($this->transitioning && !$this->columns->complete())
		{
			$this->uploadColumns();
			$this->draw($this->textures[$this->source], true);
		}

		if(!$this->transitioning || $this->columns->complete())
		{
			$this->front = $this->drawing;
			$this->source = -1;
			$this->transitioning = false;
		}

		glBindVertexArray(0);
		glActiveTexture(GL_TEXTURE0);
		glDepthMask(true);
		$this->drawing = -1;
	}

	public function restore(): void
	{
		if($this->closed) return;

		$this->release();
		$this->program->restore();
		$this->front = $this->drawing = $this->source = -1;
		$this->transitioning = $this->capturing = false;
		$this->uploadedRevision = -1;
		$this->create();
	}

	public function close(): void
	{
		if($this->closed) return;

		$this->closed = true;
		$this->transitioning = $this->capturing = false;
		$this->release();
		$this->program->close();
	}

	private function create(): void
	{
		glActiveTexture(GL_TEXTURE0);

		for($slot = 0; $slot < 2; $slot++)
		{
			$this->textures[] = Atlas::texture(GL_TEXTURE_2D);
			glTexImage2D(GL_TEXTURE_2D, 0, GL_RGBA8, $this->width, $this->height, 0, GL_RGBA, GL_UNSIGNED_BYTE, null);
		}

		$this->textures[] = Atlas::texture(GL_TEXTURE_2D);

		glTexImage2D(
			GL_TEXTURE_2D
			, 0
			, GL_R32F
			, $this->columns->columns
			, 1
			, 0
			, GL_RED
			, GL_FLOAT
			, str_repeat("\0", $this->columns->columns * 4)
		);

		glGenFramebuffers(2, $this->framebuffers);
		glGenRenderbuffers(2, $this->depthBuffers);

		for($slot = 0; $slot < 2; $slot++)
		{
			glBindFramebuffer(GL_FRAMEBUFFER, $this->framebuffers[$slot]);
			glFramebufferTexture2D(GL_FRAMEBUFFER, GL_COLOR_ATTACHMENT0, GL_TEXTURE_2D, $this->textures[$slot], 0);

			glBindRenderbuffer(GL_RENDERBUFFER, $this->depthBuffers[$slot]);
			glRenderbufferStorage(GL_RENDERBUFFER, GL_DEPTH_COMPONENT24, $this->width, $this->height);
			glFramebufferRenderbuffer(GL_FRAMEBUFFER, GL_DEPTH_ATTACHMENT, GL_RENDERBUFFER, $this->depthBuffers[$slot]);

			if(glCheckFramebufferStatus(GL_FRAMEBUFFER) !== GL_FRAMEBUFFER_COMPLETE)
			throw new \RuntimeException('Doom-Phonics screen-wipe framebuffer is incomplete');
		}

		glBindRenderbuffer(GL_RENDERBUFFER, 0);
		glBindFramebuffer(GL_FRAMEBUFFER, 0);
		glGenVertexArrays(1, $this->vao);
		$this->program->use();
		glUniform1i($this->program->location('frameTexture'), 6);
		glUniform1i($this->program->location('columnOffsets'), 7);
		glUseProgram(0);
	}

	private function uploadColumns(): void
	{
		if($this->uploadedRevision === $this->columns->revision()) return;

		glActiveTexture(GL_TEXTURE0 + 7);
		glBindTexture(GL_TEXTURE_2D, $this->textures[2]);

		glTexSubImage2D(
			GL_TEXTURE_2D
			, 0
			, 0
			, 0
			, $this->columns->columns
			, 1
			, GL_RED
			, GL_FLOAT
			, $this->columns->textureBytes()
		);

		$this->uploadedRevision = $this->columns->revision();
	}

	private function draw(int $texture, bool $melt): void
	{
		$this->program->use();
		glUniform1i($this->program->location('melt'), $melt ? 1 : 0);
		glActiveTexture(GL_TEXTURE0 + 6);
		glBindTexture(GL_TEXTURE_2D, $texture);
		glActiveTexture(GL_TEXTURE0 + 7);
		glBindTexture(GL_TEXTURE_2D, $this->textures[2]);
		glBindVertexArray($this->vao[0]);
		glDrawArrays(GL_TRIANGLES, 0, 3);
	}

	private function release(): void
	{
		$this->deleteResources($this->textures, $this->framebuffers, $this->depthBuffers, $this->vao);

		$this->textures = $this->framebuffers = $this->depthBuffers = $this->vao = [];
	}

	/**
	 * @param list<int> $textures
	 * @param list<int> $framebuffers
	 * @param list<int> $depthBuffers
	 * @param list<int> $vao
	 */
	private function deleteResources(array $textures, array $framebuffers, array $depthBuffers, array $vao): void
	{
		if($textures) glDeleteTextures(count($textures), $textures);

		if($framebuffers) glDeleteFramebuffers(count($framebuffers), $framebuffers);

		if($depthBuffers) glDeleteRenderbuffers(count($depthBuffers), $depthBuffers);

		if($vao) glDeleteVertexArrays(count($vao), $vao);
	}
}
