<?php
declare(strict_types=1);

use DoomPhonics\Audio\SdlSoundEmitter;

// Browser stereo follows Doom's S_AdjustSoundParams: a source on the listener's right pans right, by at most Doom's
// 96/128 swing, and volume falls linearly from 160 to 1200 units. The pan used to be mirrored, since Doom's angles
// run counter-clockwise and a right-hand source has a negative bearing.
(static function(): void {
	$pan = static fn(float $dx, float $dy, float $facing): float => SdlSoundEmitter::stereo($dx, $dy, $facing)[1];
	$gain = static fn(float $distance): float => SdlSoundEmitter::stereo($distance, 0.0, 0.0)[0];
	// Facing east: north is on the left, south on the right.
	verifyWad(abs($pan(0, -100, 0.0) - .75) < 1e-9 && abs($pan(0, 100, 0.0) + .75) < 1e-9, 'facing east, a source to the south is right and to the north is left, at Doom\'s swing');
	verifyWad(abs($pan(100, 0, 0.0)) < 1e-9 && abs($pan(-100, 0, 0.0)) < 1e-9, 'sources dead ahead and dead behind are centred');
	// Facing north: east is on the right.
	verifyWad(abs($pan(100, 0, M_PI / 2) - .75) < 1e-9 && abs($pan(-100, 0, M_PI / 2) + .75) < 1e-9, 'facing north, east is right and west is left');
	verifyWad(abs($pan(100, -100, 0.0) - .75 * sin(M_PI / 4)) < 1e-9, 'a source forward-right pans by the sine of its bearing');
	verifyWad(abs($pan(0, -100, 0.0) - $pan(0, -100, 2 * M_PI)) < 1e-9 && abs($pan(0, -100, 0.0) - $pan(0, -100, -2 * M_PI)) < 1e-9, 'a facing past a full turn pans the same');
	verifyWad($gain(100) === 1.0 && $gain(160) === 1.0 && abs($gain(680) - .5) < 1e-9 && $gain(1200) === 0.0 && $gain(2000) === 0.0, 'gain is full to 160 units, half at 680, silent from 1200');
	verifyWad(SdlSoundEmitter::stereo(0.0, 0.0, 1.0) === [1.0, 0.0], 'a source at the listener is full and centred');
})();
