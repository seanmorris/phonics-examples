<?php
declare(strict_types=1);

use Phonics\Render\ImageRenderer;
use Phonics\Tiled\Loader;
use Phonics\Tiled\World;
use Phonics\Tiled\WorldStream;

/** A title map using the same dependency discovery and parallax drawing as gameplay maps. */
final class DemoBackdrop
{
	public ?ImageRenderer $renderer = null;
	private WorldStream $stream;
	private object $assets;

	/**
	 * Stage the title's image-only Tiled map in an independently releasable asset scope.
	 *
	 * @return void Creates a stream without downloading any gameplay maps or tilesets.
	 */
	public function __construct()
	{
		$this->assets = (vrzno_env('createAssetScope'))();

		$world = new World(
			new Loader
			, __DIR__ . '/assets/title.world'
			, [
				'maps' => [
					[
						'fileName' => 'title.tmj'
						, 'x' => 0
						, 'y' => 0
						, 'width' => 1
						, 'height' => 1
					]
				]
			]
		);

		$this->stream = new WorldStream(
			$world
			, /**
			 * Poll a dependency through the shared browser transport.
			 *
			 * @param string $path Resolved map or image path.
			 * @param bool $retry Whether to replace a previous failed transfer.
			 * @return bool|string True when mounted, false while pending, or an error message.
			 */
			fn(string $path, bool $retry): bool|string => $this->assets->request($path, $retry)
			, /**
			 * Drop this backdrop's reference without evicting a gameplay map's shared image.
			 *
			 * @param string $path Dependency no longer required by this stream.
			 * @return void Releases the backdrop's ownership.
			 */
			function(string $path): void { $this->assets->release($path); }
		);
	}

	/**
	 * Poll discovery, downloads, parsing, and GPU preparation at a frame boundary.
	 *
	 * @return bool True after all title dependencies and the first image frame are ready.
	 */
	public function prepare(): bool
	{
		if($this->renderer !== null)
		{
			return true;
		}

		$this->stream->update(0, 0, 1, 1);

		if($this->stream->errors)
		{
			throw new RuntimeException(implode('; ', $this->stream->errors));
		}

		$maps = $this->stream->loaded();

		if(!$maps)
		{
			return false;
		}

		$renderer = new ImageRenderer(reset($maps));

		try
		{
			$renderer->prepare();
			$this->renderer = $renderer;
		}
		catch(Throwable $error)
		{
			$renderer->close();
			throw $error;
		}

		return true;
	}

	/**
	 * Read counts for files discovered so far by the Tiled loader.
	 *
	 * @return array{ready:int,total:int,failed:int} Ready, known, and failed dependency counts.
	 */
	public function progress(): array
	{
		$counts = $this->assets->progress();

		return ['ready' => $counts->ready, 'total' => $counts->total, 'failed' => $counts->failed];
	}

	/**
	 * Restore the title graphics after the engine recreates its GL context.
	 *
	 * @return void Retains parsed assets and the application's background clock.
	 */
	public function restore(): void { $this->renderer?->restore(); }

	/**
	 * Retire the title graphics and its file references.
	 *
	 * @return void Cancels unshared pending downloads; shared gameplay files remain mounted.
	 */
	public function close(): void
	{
		$this->renderer?->close();
		$this->stream->close();
		$this->assets->close();
	}
}
