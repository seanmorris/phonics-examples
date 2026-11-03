<?php
declare(strict_types=1);
namespace DoomPhonics\Audio;

use DoomPhonics\Archive\Stack;

/** WAD-owned browser audio bridges with an SDL_mixer fallback for native consumers. */
final class SdlSoundEmitter implements SoundEmitter
{
	private ?Stack $wad = null;
	private ?int $wadIdentity = null;
	/** @var array<string,mixed> */
	private array $chunks = [];
	/** @var array<string,bool> Browser bridge keys whose WAV payload has already been sent. */
	private array $browserSamples = [];
	private mixed $music = null;
	private ?string $musicName = null;
	private ?string $browserMusicKey = null;
	private bool $opened = false;
	private float $retryAt = 0;

	public function beginScope(Stack $wad): void
	{
		$identity = spl_object_id($wad);

		if($this->wadIdentity !== null && $this->wadIdentity !== $identity)
		{
			$this->stopBrowser();
			$this->freeMusic();
			$this->freeChunks();
			$this->browserSamples = [];
		}

		$this->wad = $wad;
		$this->wadIdentity = $identity;
	}

	/** Stream the selected Ogg lump and keep only the active track resident. */
	public function playMusic(MusicCue $cue): void
	{
		if(!$this->wad)return;

		$name = $cue->lump();

		if($name === $this->musicName && $this->music !== null)
		{
			try
			{
				if(Mix_PlayingMusic() !== 0)return;
			}catch(\Throwable $error)
			{
			}
		}

		$source = $this->wad->lump($name);

		if(!$source)return;

		if($this->playMusicInBrowser($name, $source))return;

		if(!$this->open())return;

		try
		{
			$bytes = $source->bytes();

			if(substr($bytes, 0, 4) !== 'OggS')return;

			$rw = SDL_RWFromConstMem($bytes, strlen($bytes));
			$music = Mix_LoadMUS_RW($rw, 1);

			if(!$music)return;

			$this->freeMusic();

			if(Mix_PlayMusic($music, -1) < 0)
			{
				Mix_FreeMusic($music);

				return;
			}

			$this->music = $music;
			$this->musicName = $name;
		}
		catch(\Throwable $error)
		{
			/* Missing codecs and output devices must not stop simulation. */
		}
	}

	public function play(SoundEvent $event): void
	{
		if(!$this->wad)return;

		$name = $event->cue->lump();
		$source = $this->wad->lump($name);

		if(!$source)return;

		if($this->playInBrowser($event, $source))return;

		if(!$this->open())return;

		try
		{
			$chunk = $this->chunks[$name] ?? null;

			if(!$chunk)
			{
				$wav = SoundSample::wav($source);
				$rw = SDL_RWFromConstMem($wav, strlen($wav));
				$chunk = Mix_LoadWAV_RW($rw, 1);

				if(!$chunk)return;

				$this->chunks[$name] = $chunk;
			}

			$channel = Mix_PlayChannel(-1, $chunk, 0);

			if($channel < 0)return;

			if($event->listener === null)return;

			$distance = hypot($event->source->x - $event->listener->x, $event->source->y - $event->listener->y);
			$attenuation = $distance <= 160?0:($distance >= 1200?255:(int)round(($distance - 160) / 1040 * 255));

			$bearing = atan2(
				$event->source->y - $event->listener->y
				, $event->source->x - $event->listener->x
			) - $event->listener->angle;

			$angle = (int)round(fmod(360 - rad2deg($bearing) + 720, 360));
			Mix_SetPosition($channel, $angle, $attenuation);
		}
		catch(\Throwable $error)
		{
			/* Audio failure must not stop simulation. */
		}
	}

	public function stopScope(): void
	{
		$this->stopBrowser();

		if($this->opened)try{Mix_HaltChannel(-1);}catch(\Throwable $error){}

		$this->freeMusic();

		$this->wad = null;
	}

	public function close(): void
	{
		$this->stopBrowser();
		$this->browserSamples = [];

		if($this->opened)try{Mix_HaltChannel(-1);}catch(\Throwable $error){}

		$this->freeMusic();
		$this->freeChunks();

		if($this->opened)try{Mix_CloseAudio();}catch(\Throwable $error){}

		if($this->opened && function_exists('SDL_QuitSubSystem'))
		try
		{
			SDL_QuitSubSystem(SDL_INIT_AUDIO);
		}catch(\Throwable $error)
		{
		}

		$this->opened = false;
		$this->wad = null;
		$this->wadIdentity = null;
	}

	private function open(): bool
	{
		if($this->opened)return true;

		if(microtime(true) < $this->retryAt || !function_exists('Mix_OpenAudio') || !function_exists(
			'SDL_RWFromConstMem'
		))
		return false;

		$sdlStarted = false;

		try
		{
			if(function_exists('SDL_InitSubSystem'))
			{
				if(SDL_InitSubSystem(SDL_INIT_AUDIO) !== 0)
				throw new \RuntimeException('SDL audio initialization failed');

				$sdlStarted = true;
			}

			$format = defined('MIX_DEFAULT_FORMAT')?MIX_DEFAULT_FORMAT:0x8010;

			if(Mix_OpenAudio(44100, $format, 2, 1024) !== 0)
			throw new \RuntimeException('SDL_mixer audio initialization failed');

			Mix_AllocateChannels(32);
			$this->opened = true;

			return true;
		}
		catch(\Throwable $error)
		{
			if($sdlStarted && function_exists('SDL_QuitSubSystem'))
			try
			{
				SDL_QuitSubSystem(SDL_INIT_AUDIO);
			}catch(\Throwable $ignored)
			{
			}

			$this->retryAt = microtime(true) + 1;

			return false;
		}
	}

	private function freeChunks(): void
	{
		foreach($this->chunks as $chunk)try{Mix_FreeChunk($chunk);}catch(\Throwable $error){}

		$this->chunks = [];
	}

	private function freeMusic(): void
	{
		if($this->browserMusicKey !== null && function_exists('vrzno_env'))
		try
		{
			$stop = vrzno_env('doomMusic');

			if($stop)$stop('{"stop":true}');
		}catch(\Throwable $error)
		{
		}

		$this->browserMusicKey = null;

		if($this->music !== null)
		{
			try{Mix_FreeMusic($this->music);}catch(\Throwable $error){}
		}

		$this->music = null;
		$this->musicName = null;
	}

	private function playMusicInBrowser(string $name, \DoomPhonics\Archive\Lump $source): bool
	{
		if(!function_exists('vrzno_env'))return false;

		try
		{
			$play = vrzno_env('doomMusic');

			if(!$play)return false;

			$key = ($this->wadIdentity ?? 0) . ':' . $name;
			$payload = ['key' => $key];

			if($key !== $this->browserMusicKey)
			{
				$bytes = $source->bytes();

				if(substr($bytes, 0, 4) !== 'OggS')return false;

				$payload['ogg'] = base64_encode($bytes);
			}

			$play(json_encode($payload, JSON_THROW_ON_ERROR));
			$this->browserMusicKey = $key;

			return true;
		}
		catch(\Throwable $error)
		{
			return false;
		}
	}

	private function playInBrowser(SoundEvent $event, \DoomPhonics\Archive\Lump $source): bool
	{
		if(!function_exists('vrzno_env'))return false;

		try
		{
			$play = vrzno_env('doomSound');

			if(!$play)return false;

			$key = ($this->wadIdentity ?? 0) . ':' . $event->cue->lump();
			$payload = ['key' => $key,'gain' => 1.0,'pan' => 0.0];

			$fresh = !isset($this->browserSamples[$key]);

			if($fresh)
			{
				$payload['wav'] = base64_encode(SoundSample::wav($source));
			}

			if($event->listener !== null)
			{
				$dx = $event->source->x - $event->listener->x;
				$dy = $event->source->y - $event->listener->y;
				$distance = hypot($dx, $dy);
				$payload['gain'] = $distance <= 160?1.0:($distance >= 1200?0.0:1 - ($distance - 160) / 1040);
				$bearing = atan2($dy, $dx) - $event->listener->angle;
				$payload['pan'] = max(-1.0, min(1.0, sin($bearing)));
			}

			$play(json_encode($payload, JSON_THROW_ON_ERROR));

			if($fresh) $this->browserSamples[$key] = true;

			return true;
		}
		catch(\Throwable $error)
		{
			return false;
		}
	}

	private function stopBrowser(): void
	{
		if(!function_exists('vrzno_env'))return;

		try
		{
			$stop = vrzno_env('doomSound');

			if($stop)$stop('{"stop":true}');
		}catch(\Throwable $error)
		{
		}
	}
}
