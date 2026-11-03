<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

/** Session settings shared by the menu, browser look input, and newly activated maps. */
final class GameSettings
{
	private SkillLevel $skill;
	private ?MultiplayerMode $multiplayerMode = null;
	private float $lookSensitivity = 1.0;
	private bool $invertVerticalLook = false;
	public float $controllerHorizontal = 1.0, $controllerVertical = 1.0, $controllerDeadZone = .12;
	public bool $controllerInvertY = false;
	private RenderResolution $renderResolution = RenderResolution::Native;
	private PitchMode $pitchMode = PitchMode::Perspective;
	private bool $reticle = true;
	private float $bloodSplatter = 1.0;

	public function __construct(?SkillLevel $skill = null)
	{
		$this->skill = $skill ?? SkillLevel::fromIndex(2);
	}

	public function skill(): SkillLevel { return $this->skill; }

	public function chooseSkill(SkillLevel $skill): void { $this->skill = $skill; }

	/** Gameplay rules are independent of whether another peer is connected. */
	public function mode(): string { return $this->multiplayerMode?->value() ?? 'singleplayer'; }

	public function multiplayerMode(): ?MultiplayerMode { return $this->multiplayerMode; }

	public function chooseMode(string $mode): void
	{
		$this->multiplayerMode = $mode === 'singleplayer' ? null : MultiplayerMode::from($mode);
	}

	public function lookSensitivity(): float { return $this->lookSensitivity; }

	public function setLookSensitivity(float $sensitivity): void
	{
		if(!is_finite($sensitivity) || $sensitivity < .5 || $sensitivity > 2)
		throw new \OutOfRangeException('Look sensitivity must be between 0.5 and 2.0');

		$this->lookSensitivity = $sensitivity;
	}

	public function invertVerticalLook(): bool { return $this->invertVerticalLook; }

	public function setInvertVerticalLook(bool $invert): void { $this->invertVerticalLook = $invert; }

	public function renderResolution(): RenderResolution { return $this->renderResolution; }

	public function setRenderResolution(RenderResolution $resolution): void
	{
		$this->renderResolution = $resolution;
	}

	public function pitchMode(): PitchMode { return $this->pitchMode; }

	public function setPitchMode(PitchMode $mode): void { $this->pitchMode = $mode; }

	public function reticle(): bool { return $this->reticle; }

	public function setReticle(bool $visible): void { $this->reticle = $visible; }

	public function bloodSplatter(): float { return $this->bloodSplatter; }

	public function setBloodSplatter(float $amount): void
	{
		if(!is_finite($amount) || $amount < 0 || $amount > 2)
		throw new \OutOfRangeException('Blood splatter must be between zero and two');

		$this->bloodSplatter = (float)sprintf('%.2f', $amount);
	}
}
