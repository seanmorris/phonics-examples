<?php
declare(strict_types=1);

namespace PhonicsBgm;

use Phonics\Application\Context;

/** A streamed playlist with a stack of interrupting music cues. Paths are relative to the executable PHAR URL. */
final class BackgroundMusic
{
	public function __construct(Context $app) { $app->browser(__DIR__.'/../browser/BackgroundMusic.mjs'); }

	/** Each track has a stable id and src, and can include title, artist, album and cover. */
	public function playlist(array $tracks, string $memoryKey): void
	{
		(vrzno_env('phonicsBgmPlaylist'))(json_encode($tracks, JSON_THROW_ON_ERROR),$memoryKey);
	}

	public function push(array $track, bool $loop = false): void
	{
		(vrzno_env('phonicsBgmPush'))(json_encode($track, JSON_THROW_ON_ERROR),$loop);
	}

	public function pop(): void { (vrzno_env('phonicsBgmPop'))(); }
}
