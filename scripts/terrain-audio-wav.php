<?php
declare(strict_types=1);

/** Write or verify interleaved signed 16-bit PCM at the source rate. */
function writeTerrainPcm(string $name, int $channels, int $rate, string $pcm, bool $check): void
{
	$frameBytes = 2*$channels;
	$format = pack('vvVVvv',1,$channels,$rate,$rate*$frameBytes,$frameBytes,16);
	$chunks = 'fmt '.pack('V',strlen($format)).$format.'data'.pack('V',strlen($pcm)).$pcm;
	$wav = 'RIFF'.pack('V',strlen($chunks)+4).'WAVE'.$chunks;
	$file = dirname(__DIR__).'/games/terrain-sandbox/assets/car-audio/'.$name;
	if($check) {
		if(!is_file($file) || file_get_contents($file) !== $wav)
			throw new RuntimeException("Generated audio differs: $name");
	} elseif(file_put_contents($file,$wav) !== strlen($wav)) throw new RuntimeException("Could not write $file");
}
