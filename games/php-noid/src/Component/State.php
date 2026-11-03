<?php
declare(strict_types=1);
namespace PhpNoid\Component;

/** Mutable ECS state state. */
final class State
{
	public int $score = 0, $lives = 3, $stage = 0, $frames = 0;
	public string $phase = 'loading';
	public string $error = '';
	public float $width = 640, $height = 480;
	public array $events = [];
	public string $name = '', $notice = '';
	public float $banner = 0, $flash = 0;
	public int $run = 1;
	public bool $exit = false;
	public bool $infiniteLives = false;
	public bool $confirmReady = false;
	public ?int $nextStage = null;
	public float $transitionTime = 0, $fade = 0;

	/**
	 * Initialize State state and its owned services.
	 *
	 * @param int $stageCount Number of maps in the campaign.
	 * @return void Completes the operation.
	 */
	public function __construct(public int $stageCount = 25) {}
}
