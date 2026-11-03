<?php
declare(strict_types=1);
namespace DoomPhonics\Menu;

use DoomPhonics\Game\GameSettings;
use DoomPhonics\Game\PitchMode;
use DoomPhonics\Game\RenderResolution;

/** Browser-owned audio and video preferences exposed through the in-game menu. */
final class DoomOptions
{
	private ?\Closure $reader;
	private ?\Closure $writer;
	private bool $muted = false;
	private float $effectsVolume = 1.0;
	private float $musicVolume = 1.0;
	private bool $fullscreen = false;
	private bool $fullscreenAvailable = false;
	private bool $fullscreenBusy = false;
	private string $fullscreenError = '';
	private bool $sessionOnly = false;

	public function __construct(
		private readonly GameSettings $settings
		, ?callable $reader = null
		, ?callable $writer = null
	){
		$this->reader = $reader === null ? null : \Closure::fromCallable($reader);
		$this->writer = $writer === null ? null : \Closure::fromCallable($writer);
		$this->sessionOnly = $writer === null;
		$this->refresh();
	}

	public function muted(): bool { return $this->muted; }

	public function effectsVolume(): float { return $this->effectsVolume; }

	public function musicVolume(): float { return $this->musicVolume; }

	public function fullscreen(): bool { return $this->fullscreen; }

	public function fullscreenAvailable(): bool { return $this->fullscreenAvailable; }

	public function fullscreenBusy(): bool { return $this->fullscreenBusy; }

	/** Poll live browser state so the in-game controls mirror the corner controls. */
	public function refresh(): void
	{
		if(!$this->reader) return;

		try
		{
			$this->accept(($this->reader)());
		}
		catch(\Throwable)
		{
			$this->sessionOnly = true;
		}
	}

	public function setMuted(bool $value): void
	{
		$this->muted = $value;
		$this->write(['muted' => $value]);
	}

	public function setEffectsVolume(float $value): void
	{
		$this->effectsVolume = $this->volume($value);
		$this->write(['effectsVolume' => $this->effectsVolume]);
	}

	public function setMusicVolume(float $value): void
	{
		$this->musicVolume = $this->volume($value);
		$this->write(['musicVolume' => $this->musicVolume]);
	}

	public function setFullscreen(bool $value): void
	{
		if(!$this->fullscreenAvailable || $this->fullscreenBusy) return;

		$this->fullscreenError = '';
		$this->write(['fullscreen' => $value]);
	}

	public function setRenderResolution(RenderResolution $value): void
	{
		$this->settings->setRenderResolution($value);
		$this->write(['renderResolution' => $value->value]);
	}

	public function setPitchMode(PitchMode $value): void
	{
		$this->settings->setPitchMode($value);
		$this->write(['pitchMode' => $value->value]);
	}

	public function setReticle(bool $value): void
	{
		$this->settings->setReticle($value);
		$this->write(['reticle' => $value]);
	}

	public function setBloodSplatter(float $value): void
	{
		$this->settings->setBloodSplatter($value);
		$this->write(['bloodSplatter' => $this->settings->bloodSplatter()]);
	}

	public function message(): string
	{
		if($this->fullscreenError !== '') return $this->fullscreenError;

		return $this->sessionOnly ? 'Changes apply to this session' : '';
	}

	/** @return array<string,bool|float|string> */
	public function state(): array
	{
		return [
			'muted' => $this->muted
			, 'effectsVolume' => $this->effectsVolume
			, 'musicVolume' => $this->musicVolume
			, 'fullscreen' => $this->fullscreen
			, 'fullscreenAvailable' => $this->fullscreenAvailable
			, 'fullscreenBusy' => $this->fullscreenBusy
			, 'fullscreenError' => $this->fullscreenError
			, 'sessionOnly' => $this->sessionOnly
			, 'reticle' => $this->settings->reticle()
			, 'bloodSplatter' => $this->settings->bloodSplatter()
		];
	}

	/** @param array<string,mixed> $patch */
	private function write(array $patch): void
	{
		if(!$this->writer)
		{
			$this->sessionOnly = true;

			return;
		}

		try
		{
			$response = ($this->writer)(json_encode($patch, JSON_THROW_ON_ERROR));
			$this->accept($response);
		}
		catch(\Throwable)
		{
			$this->sessionOnly = true;
		}
	}

	private function accept(mixed $value): void
	{
		if(!is_string($value) || strlen($value) > 1048576) return;

		$data = json_decode($value, true);

		if(!is_array($data)) return;

		if(is_bool($data['muted'] ?? null)) $this->muted = $data['muted'];

		foreach(['effectsVolume','musicVolume'] as $name)
		{
			$value = $data[$name] ?? null;

			if((is_int($value) || is_float($value)) && is_finite((float)$value)
				&& $value >= 0 && $value <= 1
			) $this->$name = (float)$value;
		}

		if(is_string($data['renderResolution'] ?? null)
			&& ($resolution = RenderResolution::tryFrom($data['renderResolution']))
		) $this->settings->setRenderResolution($resolution);

		if(is_string($data['pitchMode'] ?? null) && ($mode = PitchMode::tryFrom($data['pitchMode'])))
		$this->settings->setPitchMode($mode);

		if(is_bool($data['reticle'] ?? null)) $this->settings->setReticle($data['reticle']);

		$blood = $data['bloodSplatter'] ?? null;

		if((is_int($blood) || is_float($blood)) && is_finite((float)$blood) && $blood >= 0 && $blood <= 2)
		$this->settings->setBloodSplatter((float)$blood);

		foreach(['fullscreen','fullscreenAvailable','fullscreenBusy','sessionOnly'] as $name)
		if(is_bool($data[$name] ?? null)) $this->$name = $data[$name];

		if(is_string($data['fullscreenError'] ?? null))
		$this->fullscreenError = substr($data['fullscreenError'], 0, 256);
	}

	private function volume(float $value): float
	{
		if(!is_finite($value) || $value < 0 || $value > 1)
		throw new \OutOfRangeException('Volume must be between zero and one');

		return (float)sprintf('%.1f', $value);
	}
}
