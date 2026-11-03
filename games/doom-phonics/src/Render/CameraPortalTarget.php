<?php
declare(strict_types=1);
namespace DoomPhonics\Render;

use Phonics\Render\Atlas;

/** Full-scene color/depth target sampled by one or more camera portal surfaces. */
final class CameraPortalTarget
{
	/** @var list<int> */
	private array $framebuffer = [], $depthBuffer = [];
	private int $texture = 0, $parentFramebuffer = 0;
	private bool $capturing = false, $closed = false;

	public function __construct(public int $width, public int $height) { $this->create(); }

	public function texture(): int { return $this->texture; }

	public function resize(int $width, int $height): void
	{
		if($width === $this->width && $height === $this->height) return;

		if($this->capturing) throw new \LogicException('Camera portal target cannot resize while capturing');

		$this->release();
		$this->width = $width;
		$this->height = $height;
		$this->create();
	}

	private function create(): void
	{
		$parentFramebuffer = glGetIntegerv(GL_FRAMEBUFFER_BINDING);
		$this->texture = Atlas::texture(GL_TEXTURE_2D);
		glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MIN_FILTER, GL_LINEAR);
		glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MAG_FILTER, GL_LINEAR);
		glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_WRAP_S, GL_CLAMP_TO_EDGE);
		glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_WRAP_T, GL_CLAMP_TO_EDGE);
		glTexImage2D(GL_TEXTURE_2D, 0, GL_RGBA8, $this->width, $this->height, 0, GL_RGBA, GL_UNSIGNED_BYTE, null);
		glGenFramebuffers(1, $this->framebuffer);
		glBindFramebuffer(GL_FRAMEBUFFER, $this->framebuffer[0]);
		glFramebufferTexture2D(GL_FRAMEBUFFER, GL_COLOR_ATTACHMENT0, GL_TEXTURE_2D, $this->texture, 0);
		glGenRenderbuffers(1, $this->depthBuffer);
		glBindRenderbuffer(GL_RENDERBUFFER, $this->depthBuffer[0]);
		glRenderbufferStorage(GL_RENDERBUFFER, GL_DEPTH_COMPONENT24, $this->width, $this->height);
		glFramebufferRenderbuffer(GL_FRAMEBUFFER, GL_DEPTH_ATTACHMENT, GL_RENDERBUFFER, $this->depthBuffer[0]);

		if(glCheckFramebufferStatus(GL_FRAMEBUFFER) !== GL_FRAMEBUFFER_COMPLETE)
		throw new \RuntimeException('Doom-Phonics camera portal framebuffer is incomplete');

		glBindRenderbuffer(GL_RENDERBUFFER, 0);
		glBindFramebuffer(GL_FRAMEBUFFER, $parentFramebuffer);
	}

	public function begin(): void
	{
		if($this->closed || $this->capturing) throw new \LogicException('Camera portal target cannot begin');

		$this->parentFramebuffer = glGetIntegerv(GL_FRAMEBUFFER_BINDING);
		$this->capturing = true;
		glBindFramebuffer(GL_FRAMEBUFFER, $this->framebuffer[0]);
		glViewport(0, 0, $this->width, $this->height);
		glClearColor(0, 0, 0, 1);
		glClearDepth(1);
		glDepthMask(true);
		glClear(GL_COLOR_BUFFER_BIT | GL_DEPTH_BUFFER_BIT);
	}

	public function end(): void
	{
		if(!$this->capturing) throw new \LogicException('Camera portal target must begin before end');

		$this->capturing = false;
		glBindFramebuffer(GL_FRAMEBUFFER, $this->parentFramebuffer);
	}

	public function restore(): void
	{
		if($this->closed) return;

		$this->capturing = false;
		$this->release();
		$this->create();
	}

	public function close(): void
	{
		if($this->closed) return;

		$this->closed = true;
		$this->capturing = false;
		$this->release();
	}

	private function release(): void
	{
		if($this->texture) glDeleteTextures(1, [$this->texture]);

		if($this->framebuffer) glDeleteFramebuffers(1, $this->framebuffer);

		if($this->depthBuffer) glDeleteRenderbuffers(1, $this->depthBuffer);

		$this->texture = 0;
		$this->framebuffer = $this->depthBuffer = [];
	}
}
