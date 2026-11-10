<?php
declare(strict_types=1);
namespace PhlappyPhant\Render;

use Phonics\Render\Atlas;
use Phonics\Render\Program;
use Phonics\Render\Sprite;

/**
 * One-pass renderer for the scrolling terrain and its hand-graded palette crossfade.
 *
 * The shared sprite renderer expresses a palette fade as two complete overlapping sprites. That is flexible, but
 * the backdrop covers the frame, so the transition doubles the game's fragment work. This batch keeps the same
 * painter order and samples the current and next atlas frames in one fragment invocation.
 */
final class TerrainRenderer
{
	private Program $program;
	/** @var list<int> Restorable vertex-array and buffer handles. */
	private array $vao = [], $buffers = [];
	private bool $closed = false;
	public int $instances = 0;

	/**
	 * Prepare the terrain program and its streaming instance buffer while borrowing the scene atlas.
	 *
	 * @param Atlas $atlas Atlas containing every graded terrain frame.
	 * @return void Allocates restorable GPU resources.
	 */
	public function __construct(private Atlas $atlas)
	{
		$root = dirname(__DIR__, 2);
		$this->program = new Program(
			file_get_contents($root . '/shaders/terrain.vert')
			, file_get_contents($root . '/shaders/terrain.frag')
		);
		$this->create();
	}

	/**
	 * Allocate the terrain vertex array and its packed per-instance attributes.
	 *
	 * @return void Configures one destination and two atlas rectangles per instance.
	 */
	private function create(): void
	{
		glGenVertexArrays(1, $this->vao);
		glGenBuffers(1, $this->buffers);
		glBindVertexArray($this->vao[0]);
		glBindBuffer(GL_ARRAY_BUFFER, $this->buffers[0]);

		for($attribute = 0; $attribute < 3; $attribute++)
		{
			glVertexAttribPointer($attribute, 4, GL_FLOAT, false, 48, $attribute * 16);
			glEnableVertexAttribArray($attribute);
			glVertexAttribDivisor($attribute, 1);
		}

		glBindVertexArray(0);
	}

	/**
	 * Draw the complete terrain with an optional one-pass palette crossfade.
	 *
	 * @param list<array{Sprite,int}> $terrain Sprite and GID stride to its next graded palette.
	 * @param int $width Drawing-buffer width in pixels.
	 * @param int $height Drawing-buffer height in pixels.
	 * @param float $milliseconds Animation clock used by the river tiles.
	 * @param float $blend Normalized blend from the current graded palette to the next.
	 * @return void Uploads and draws one painter-ordered terrain batch.
	 */
	public function render(array $terrain, int $width, int $height, float $milliseconds, float $blend): void
	{
		$this->instances = count($terrain);

		if(!$terrain || $this->closed) return;

		$blend = max(0.0, min(1.0, $blend));
		$bytes = '';

		foreach($terrain as [$sprite, $stride])
		{
			$gid = $sprite->frame($this->atlas->map, $milliseconds);
			$current = $sprite->rectangle($this->atlas->rects[$gid]
				?? throw new \OutOfBoundsException("Terrain GID $gid is missing"));
			$next = $blend > 0
				? $sprite->rectangle($this->atlas->rects[$gid + $stride]
					?? throw new \OutOfBoundsException('The next terrain palette is missing'))
				: $current;
			$drawWidth = $current[2] * $sprite->scaleX;
			$drawHeight = $current[3] * $sprite->scaleY;
			$left = $sprite->x - $drawWidth * $sprite->originX;
			$top = $sprite->y - $drawHeight * $sprite->originY;

			$bytes .= pack('f*', ...[
				$left, $top, $sprite->flipX ? -$drawWidth : $drawWidth, $sprite->flipY ? -$drawHeight : $drawHeight
				, ...$current
				, ...$next
			]);
		}

		$this->program->use();
		glBindVertexArray($this->vao[0]);
		glBindBuffer(GL_ARRAY_BUFFER, $this->buffers[0]);
		glBufferData(GL_ARRAY_BUFFER, strlen($bytes), $bytes, GL_STREAM_DRAW);
		glActiveTexture(GL_TEXTURE0);
		glBindTexture(GL_TEXTURE_2D, $this->atlas->texture);
		glUniform1i($this->program->location('atlas'), 0);
		glUniform1f($this->program->location('blend'), $blend);
		glUniform2f($this->program->location('viewport'), $width, $height);
		glDisable(GL_DEPTH_TEST);
		glDrawArraysInstanced(GL_TRIANGLES, 0, 6, $this->instances);
		glBindVertexArray(0);
	}

	/**
	 * Delete the restorable vertex array and streaming buffer.
	 *
	 * @return void Clears the retained handles after releasing them.
	 */
	private function release(): void
	{
		if($this->vao) glDeleteVertexArrays(count($this->vao), $this->vao);
		if($this->buffers) glDeleteBuffers(count($this->buffers), $this->buffers);
		$this->vao = $this->buffers = [];
	}

	/**
	 * Rebuild the program and instance buffer after WebGL context restoration.
	 *
	 * @return void Leaves an already closed renderer unchanged.
	 */
	public function restore(): void
	{
		if($this->closed) return;
		$this->release();
		$this->program->restore();
		$this->create();
	}

	/**
	 * Release the renderer's program and buffers without closing its borrowed atlas.
	 *
	 * @return void Closes each owned GPU resource once.
	 */
	public function close(): void
	{
		if($this->closed) return;
		$this->closed = true;
		$this->release();
		$this->program->close();
	}
}
