<?php
declare(strict_types=1);

use DoomPhonics\Component\ActorState;
use DoomPhonics\Definition\ActionDispatcher;
use DoomPhonics\Definition\DecorateParser;
use DoomPhonics\System\ActorStateSystem;
use Phonics\Ecs\Registry;

// Doom's P_MobjThinker sets the next state, and runs its action, in the tic the current one runs out, so every frame
// lasts exactly its duration. Entering the next frame of a sequence a tic late made idle monsters look 9 then 11 tics
// apart, and E1M3's 74 monsters bunched up to 19 looks in one tic.
(static function(): void {
	$actors = DecorateParser::catalog(['timing.dec' => <<<'DEC'
ACTOR TimingProbe
{
  States
  {
  Spawn:
    PROB A 10 A_Mark
    PROB B 10 A_Mark
    Loop
  Chain:
    PROB C 3 A_Mark
    PROB D 2 A_Mark
    PROB E 0 A_Mark
    PROB F 4 A_Mark
    Stop
  }
}
DEC]);

	$run = static function(string $label, int $tics) use ($actors): array {
		$registry = new Registry;
		$state = new ActorState($actors->named('TimingProbe'), $label);
		$entity = $registry->create($state);
		$tic = 0;
		$marks = [];
		$dispatcher = new ActionDispatcher;
		$dispatcher->register('A_Mark', static function() use (&$tic, &$marks, $state): void { $marks[] = $tic . ':' . $state->current()?->frame; });
		$system = new ActorStateSystem($dispatcher);

		for($tic = 0; $tic < $tics; $tic++) $system->update($registry, 1 / 35);

		return $marks;
	};

	// The tic an actor is created in is its first frame's first tic, as for a thing spawned during a tic in Doom.
	// After that, every frame lasts exactly its duration; the old code alternated 9 and 11 (0, 10, 19, 30, 39).
	verifyWad(
		$run('Spawn', 41) === ['0:A', '9:B', '19:A', '29:B', '39:A']
		, 'a looping two-frame state runs each frame for exactly its ten tics'
	);

	// C (3 tics) ends in tic 2, D (2 tics) in tic 4, and the zero-tic E hands straight to F in that tic. The old code
	// gave D one tic and started F a tic after E (0, 3, 4, 5).
	verifyWad(
		$run('Chain', 12) === ['0:C', '2:D', '4:E', '4:F']
		, 'frames in a sequence start in the tic the previous one runs out, and zero-tic frames chain at once'
	);
})();
