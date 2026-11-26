<?php
declare(strict_types=1);
namespace TerrainSandbox;

/** Browser commands run on the input boundary; replay verification yields between physics ticks. */
final class CircuitOnline
{
	private ?string $parked = null;
	private ?\Generator $verification = null;
	private ?int $request = null;
	private array $jobs = [];
	private int $progress = -10;
	private int $lastFrame = -1;
	private float $parkedTime = 0.;
	private bool $parkedPause = false;
	private bool $reported = false;

	public function __construct(private Session $game, private \Closure $reply, private \Closure $frame) {}

	public function update(?array $command): void
	{
		if($command !== null) {
			$id = $command['id'];
			try {
				$result = match($command['type']) {
					'spec' => $this->game->raceSpec(isset($command['laps']) ? CircuitTrial::laps($command['laps']) : null),
					'prepare' => $this->prepare($command['spec']),
					'start' => $this->start(),
					'leave' => $this->leave(),
					'verify' => $this->verify($id,$command),
					'preview' => $this->preview($id,$command['spec']),
					default => throw new \InvalidArgumentException('Unexpected race command.')
				};
				if(!in_array($command['type'],['verify','preview'],true)) ($this->reply)($id,json_encode(['value'=>$result],JSON_THROW_ON_ERROR));
			} catch(\Throwable $e) { $this->error($id,$e); }
		}
		$trial = $this->game->trial;
		if($trial === null || !$trial->running || $this->reported) return;
		$end = $trial->dnf !== null || $trial->race->state === 'finished';
		if(!$end && $this->lastFrame >= 0 && $trial->tick-$this->lastFrame < 6) return;
		$from = max(0,$this->lastFrame);
		$this->lastFrame = $trial->tick;
		$data = ['tick'=>$trial->tick,'pose'=>$trial->pose(),'from'=>$from,'data'=>$trial->inputsSince($from),'split'=>$trial->lastSplit];
		if($end) {
			$this->reported = true;
			if($trial->dnf) $data['dnf'] = $trial->dnf;
			else $data['run'] = $trial->result();
		}
		($this->frame)(json_encode($data,JSON_THROW_ON_ERROR));
	}

	public function verifying(): bool
	{
		return $this->verification !== null || $this->jobs !== [];
	}

	/** A short, separately scheduled slice also works when drawing the terrain is slow. */
	public function advanceVerification(): void
	{
		if($this->verification === null && $this->jobs) {
			[$this->request,$this->verification] = array_shift($this->jobs);
			$this->progress = -10;
		}
		if($this->verification !== null) {
			try {
				$end = hrtime(true)+2500000;
				while($this->verification->valid() && hrtime(true)<$end) $this->verification->next();
				$percent = $this->verification->current();
				if(is_int($percent) && $percent >= $this->progress+10) {
					$this->progress = $percent;
					($this->reply)($this->request,json_encode(['progress'=>$percent],JSON_THROW_ON_ERROR));
				}
				if(!$this->verification->valid()) {
					($this->reply)($this->request,json_encode(['value'=>$this->verification->getReturn()],JSON_THROW_ON_ERROR));
					$this->verification = null;
				}
			} catch(\Throwable $e) {
				$this->error($this->request,$e);
				$this->verification = null;
			}
		}
	}

	private function error(int $id,\Throwable $error): void
	{
		($this->reply)($id,json_encode(['error'=>$error->getMessage()],JSON_THROW_ON_ERROR));
	}

	private function prepare(array $spec): bool
	{
		if($this->game->demo->recording || $this->game->demo->playhead !== null)
			throw new \RuntimeException('Stop the current demo before joining a race.');
		if($this->parked === null) {
			$this->parkedTime = $this->game->time;
			$this->parkedPause = $this->game->paused;
			$this->parked = $this->game->save(true);
		}
		try { $this->game->driveTrial($spec); }
		catch(\Throwable $e) { $this->leave(); throw $e; }
		$this->lastFrame = -1;
		$this->reported = false;
		return true;
	}

	private function start(): bool
	{
		if($this->game->trial === null) throw new \RuntimeException('Prepare the starting grid first.');
		$this->game->trial->running = true;
		return true;
	}

	private function leave(): bool
	{
		if($this->verification !== null) {
			$this->error($this->request,new \RuntimeException('Verification cancelled.'));
			$this->verification = null;
		}
		foreach($this->jobs as [$id]) $this->error($id,new \RuntimeException('Race preview cancelled.'));
		$this->jobs = [];
		if($this->parked !== null) {
			$this->game->leaveTrial();
			$this->game->time = $this->parkedTime;
			$this->game->load($this->parked);
			$this->game->paused = $this->parkedPause;
			$this->parked = null;
		}
		return true;
	}

	private function preview(int $id,array $spec): void
	{
		$world = CircuitTrial::document($spec);
		$survey = new \TerrainPhonics\LandformPreview($world['landscape']['recipe'],(float)$world['settings']['waterLevel'],32);
		$this->jobs[] = [$id,$survey->survey()];
	}

	private function verify(int $id,array $command): void
	{
		$this->jobs[] = [$id,(static function() use ($command): \Generator {
			$session = new Session;
			try {
				$trial = $session->prepareTrial($command['spec']);
				return yield from $trial->verify($command['run']);
			} finally { $session->close(); }
		})()];
	}
}
