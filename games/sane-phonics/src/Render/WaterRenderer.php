<?php
declare(strict_types=1);
namespace SanePhonics\Render;
use Phonics\Render\Program;
use Phonics\Render\Camera;
use Phonics\Render\Atlas;
use SanePhonics\Physics\WaterRegion;
use SanePhonics\Physics\WaterRegions;

/** Captures a scene once, then redraws liquid rectangles through a scrolling displacement map. */
final class WaterRenderer
{
	private Program $program;
	private array $vao = [],$framebuffer = [],$depthBuffer = [];
	private int $sceneTexture = 0,$displacementTexture = 0;
	private bool $closed = false,$capturing = false;
	public int $draws = 0;

	/**
	 * Allocate an offscreen scene target and procedural two-channel displacement texture.
	 *
	 * @param int $width Positive drawing-buffer width in pixels.
	 * @param int $height Positive drawing-buffer height in pixels.
	 * @return void Creates restorable color and depth resources for postprocessing water regions.
	 */
	public function __construct(public int $width, public int $height)
	{
		if($width <= 0 || $height <= 0)
		{
			throw new \InvalidArgumentException('Water target dimensions must be positive');
		}

		$this->program = new Program(
			file_get_contents(dirname(__DIR__, 2) . '/shaders/water.vert')
			, file_get_contents(dirname(__DIR__, 2) . '/shaders/water.frag')
		);

		$this->create();
	}

	/**
	 * Allocate framebuffer, scene texture, depth buffer, wave map, and fullscreen geometry.
	 *
	 * @return void Creates all context-local resources or releases partial allocation on failure.
	 */
	private function create(): void
	{
		try
		{
			glGenVertexArrays(1, $this->vao);
			$this->sceneTexture = Atlas::texture(GL_TEXTURE_2D);
			glTexImage2D(GL_TEXTURE_2D, 0, GL_RGBA8, $this->width, $this->height, 0, GL_RGBA, GL_UNSIGNED_BYTE, null);

			glGenFramebuffers(1, $this->framebuffer);
			glBindFramebuffer(GL_FRAMEBUFFER, $this->framebuffer[0]);
			glFramebufferTexture2D(GL_FRAMEBUFFER, GL_COLOR_ATTACHMENT0, GL_TEXTURE_2D, $this->sceneTexture, 0);
			glGenRenderbuffers(1, $this->depthBuffer);
			glBindRenderbuffer(GL_RENDERBUFFER, $this->depthBuffer[0]);
			glRenderbufferStorage(GL_RENDERBUFFER, GL_DEPTH_COMPONENT24, $this->width, $this->height);
			glFramebufferRenderbuffer(GL_FRAMEBUFFER, GL_DEPTH_ATTACHMENT, GL_RENDERBUFFER, $this->depthBuffer[0]);

			if(glCheckFramebufferStatus(GL_FRAMEBUFFER) !== GL_FRAMEBUFFER_COMPLETE)
			{
				throw new \RuntimeException('Water scene framebuffer is incomplete');
			}

			$this->displacementTexture = Atlas::texture(GL_TEXTURE_2D);
			glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_WRAP_S, GL_REPEAT);
			glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_WRAP_T, GL_REPEAT);
			glTexImage2D(GL_TEXTURE_2D, 0, GL_RGBA8, 64, 64, 0, GL_RGBA, GL_UNSIGNED_BYTE, $this->map());
			glBindRenderbuffer(GL_RENDERBUFFER, 0);
			glBindFramebuffer(GL_FRAMEBUFFER, 0);
		}
		catch(\Throwable $error)
		{
			glBindFramebuffer(GL_FRAMEBUFFER, 0);
			$this->release();

			throw $error;
		}
	}

	/**
	 * Generate a seamless RG displacement field similar to moving-boxes-2's tiled wave image.
	 *
	 * @return string Packed 64-by-64 RGBA8 pixels whose red and green channels offset scene UVs.
	 */
	private function map(): string
	{
		$bytes = '';

		for($y = 0; $y < 64; $y++)
		{
			for($x = 0; $x < 64; $x++)
			{
				$red = 128 + (int)round(70 * sin($y * M_PI / 16) + 20 * sin(($x + $y) * M_PI / 32));
				$green = 128 + (int)round(18 * cos($x * M_PI / 16));
				$bytes .= pack('C4', max(0, min(255, $red)), max(0, min(255, $green)), 128, 255);
			}
		}

		return $bytes;
	}

	/**
	 * Redirect subsequent map and sprite draws to the offscreen scene texture.
	 *
	 * @param array{0:float|int,1:float|int,2:float|int,3:float|int} $color Scene clear color as
	 * normalized RGBA channels.
	 * @return void Clears the color and depth targets and begins one capture/composite pair.
	 */
	public function begin(array $color = [.055,.075,.12,1]): void
	{
		if($this->closed || $this->capturing)
		{
			throw new \LogicException('Water renderer cannot begin this capture');
		}

		$this->capturing = true;
		$this->draws = 0;
		glBindFramebuffer(GL_FRAMEBUFFER, $this->framebuffer[0]);
		glViewport(0, 0, $this->width, $this->height);
		glClearColor(...$color);
		glClearDepth(1);
		glDepthMask(true);
		glClear(GL_COLOR_BUFFER_BIT | GL_DEPTH_BUFFER_BIT);
	}

	/**
	 * Copy the captured scene to the window, then replace visible water rectangles with displaced
	 * samples from that same scene texture.
	 *
	 * @param WaterRegions|list<WaterRegion> $waters Query collection or explicit water region list.
	 * @param Camera $camera Viewport and world-to-screen transform used by the captured scene.
	 * @param float $milliseconds Simulation or rendering time in milliseconds.
	 * @return void Ends capture and submits one base draw plus one draw per visible water region.
	 */
	public function compose(WaterRegions|array $waters, Camera $camera, float $milliseconds = 0): void
	{
		if(!$this->capturing)
		{
			throw new \LogicException('Water renderer must capture before composing');
		}

		$this->capturing = false;
		$regions = $waters instanceof WaterRegions?$waters->regions:$waters;
		glBindFramebuffer(GL_FRAMEBUFFER, 0);
		glViewport(0, 0, $this->width, $this->height);
		$p = $this->program;
		$p->use();
		glBindVertexArray($this->vao[0]);
		glActiveTexture(GL_TEXTURE0);
		glBindTexture(GL_TEXTURE_2D, $this->sceneTexture);
		glUniform1i($p->location('scene'), 0);
		glActiveTexture(GL_TEXTURE1);
		glBindTexture(GL_TEXTURE_2D, $this->displacementTexture);
		glUniform1i($p->location('displacementMap'), 1);
		glUniform2f($p->location('viewport'), $camera->width, $camera->height);
		glUniform2f($p->location('camera'), $camera->x, $camera->y);
		glUniform1f($p->location('zoom'), $camera->zoom);
		glUniform1f($p->location('time'), $milliseconds / 1000);
		glUniform1i($p->location('waterEffect'), 0);
		glUniform4f($p->location('screenRect'), 0, 0, $camera->width, $camera->height);
		glDrawArrays(GL_TRIANGLES, 0, 6);
		$this->draws++;
		glUniform1i($p->location('waterEffect'), 1);

		foreach($regions as $region)
		{
			if(!$region instanceof WaterRegion)
			{
				throw new \InvalidArgumentException('Water render list must contain WaterRegion objects');
			}

			$left = max(0, ($region->x - $camera->x) * $camera->zoom);
			$top = max(0, ($region->y - $camera->y) * $camera->zoom);
			$right = min($camera->width, ($region->x + $region->width - $camera->x) * $camera->zoom);
			$bottom = min($camera->height, ($region->y + $region->height - $camera->y) * $camera->zoom);

			if($right <= $left || $bottom <= $top)
			{
				continue;
			}

			glUniform4f($p->location('screenRect'), $left, $top, $right - $left, $bottom - $top);
			glUniform4f($p->location('tint'), ...$region->tint);
			glUniform1f($p->location('displacement'), $region->displacement * $camera->zoom);
			glUniform1f($p->location('displacementScale'), $region->displacementScale);
			glUniform1f($p->location('displacementSpeed'), $region->displacementSpeed);
			glDrawArrays(GL_TRIANGLES, 0, 6);
			$this->draws++;
		}

		glBindVertexArray(0);
		glActiveTexture(GL_TEXTURE0);
	}

	/**
	 * Rebuild all context-local postprocessing resources after browser WebGL restoration.
	 *
	 * @return void Recreates framebuffer textures and shader state unless permanently closed.
	 */
	public function restore(): void
	{
		if($this->closed)
		{
			return;
		}

		$this->capturing = false;
		$this->release();
		$this->program->restore();
		$this->create();
	}

	/**
	 * Permanently release framebuffer, textures, vertex array, and shader program.
	 *
	 * @return void Makes repeated closure harmless and prevents later capture or restoration.
	 */
	public function close(): void
	{
		if($this->closed)
		{
			return;
		}

		$this->closed = true;
		$this->capturing = false;
		$this->release();
		$this->program->close();
	}

	/**
	 * Delete live texture, depth, framebuffer, and geometry objects while retaining dimensions.
	 *
	 * @return void Clears every owned GL handle except the separately managed program wrapper.
	 */
	private function release(): void
	{
		$textures = array_values(array_filter([$this->sceneTexture,$this->displacementTexture]));

		if($textures)
		{
			glDeleteTextures(count($textures), $textures);
		}

		if($this->framebuffer)
		{
			glDeleteFramebuffers(1, $this->framebuffer);
		}

		if($this->depthBuffer)
		{
			glDeleteRenderbuffers(1, $this->depthBuffer);
		}

		if($this->vao)
		{
			glDeleteVertexArrays(1, $this->vao);
		}

		$this->sceneTexture = $this->displacementTexture = 0;
		$this->framebuffer = $this->depthBuffer = $this->vao = [];
	}
}
