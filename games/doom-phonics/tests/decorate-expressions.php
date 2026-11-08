<?php
declare(strict_types=1);

use DoomPhonics\Definition\ActionDispatcher;
use DoomPhonics\Definition\DecorateParser;
use DoomPhonics\Definition\Expression;
use DoomPhonics\Definition\StateFrame;
use Phonics\Ecs\Registry;

// ZDoom computes action arguments such as random(-3,3) on every call. Read as tokens, `random(-3,3)` became two
// arguments and shifted every later one: the Omega Weapon Pack's eighty A_FireCustomMissile calls fired with no
// spread and misread flags.
(static function(): void {
	$actors = DecorateParser::catalog([
		'expressions.dec' => <<<'DECORATE'
ACTOR Shot {}
ACTOR Spreader
{
  States
  {
  Fire:
    PISG A 1 A_FireCustomMissile("Shot", random(-3,3), 1, random(-2,2), 0, 0, random(-1,1))
    PISG B 1 A_SpawnItemEx("Shot", 2*random(1,1)+3, -random(2,2), 0, 0, 0, 0, 0, SXF_CLIENTSIDE|SXF_NOCHECKPOSITION, 0)
    PISG C 1 A_PlaySound("weapons/pistol, loud", CHAN_WEAPON)
    PISG D 1 A_SetPitch(pitch+5)
    PISG E 1 A_Explode(random(3,1), frandom(0.5,0.5), 7/2, 7.0/2, CallACS("x")*4, -(1+2)*2)
    Stop
  }
}
DECORATE
	]);
	$frames = $actors->named('Spreader')->state('Fire')->frames;
	$spread = $frames[0]->arguments;
	verifyWad(
		count($spread) === 7 && $spread[0] === 'Shot' && $spread[1] instanceof Expression && $spread[2] === 1
			&& $spread[3] instanceof Expression && $spread[4] === 0 && $spread[6] instanceof Expression && $frames[0]->dynamic
		, 'random(a,b) is one argument, holding its place among the others'
	);

	$seen = [];

	for($i = 0; $i < 200; $i++)
	{
		$value = $frames[0]->evaluated()->arguments[1];
		verifyWad(is_int($value) && $value >= -3 && $value <= 3, 'random(-3,3) evaluates to an integer in range');
		$seen[$value] = true;

		if(count($seen) === 7) break;
	}

	verifyWad(count($seen) > 1, 'random(-3,3) varies between evaluations');
	$ex = $frames[1]->evaluated()->arguments;
	verifyWad($ex[1] === 5 && $ex[2] === -2 && $ex[8] === 80, 'arithmetic, unary minus, and flag constants combine with C precedence');
	$sound = $frames[2]->arguments;
	verifyWad($sound[0] === 'weapons/pistol, loud' && $sound[1] === 'CHAN_WEAPON' && !$frames[2]->dynamic, 'a quoted string keeps its comma and a bare name stays a string');
	verifyWad($frames[3]->evaluated()->arguments[0] === 5, 'an unknown variable such as pitch reads as zero');
	$explode = $frames[4]->evaluated()->arguments;
	verifyWad(
		$explode[0] >= 1 && $explode[0] <= 3 && $explode[1] === 0.5 && $explode[2] === 3 && $explode[3] === 3.5
			&& $explode[4] === 0 && $explode[5] === -6
		, 'random swaps reversed bounds, frandom is a float, integers divide as integers, unknown calls are zero'
	);
	verifyWad((new Expression('foo('))->evaluate() === 0 && (new Expression('1 +'))->evaluate() === 0, 'an expression the grammar cannot read evaluates to zero rather than failing');
	verifyWad((new Expression('pitch*2'))->evaluate(static fn(string $name): ?float => $name === 'pitch' ? 4.5 : null) === 9.0, 'a caller can supply named values');

	// The dispatcher hands actions a frame whose expressions are already computed, so every action reads scalars.
	$dispatcher = new ActionDispatcher;
	$received = null;
	$dispatcher->register('A_FireCustomMissile', static function(int $entity, StateFrame $frame, Registry $registry) use (&$received): void { $received = $frame->arguments; });
	$dispatcher->dispatch(1, $frames[0], new Registry);
	verifyWad($received !== null && is_int($received[1]) && $received[2] === 1 && is_int($received[3]) && is_int($received[6]), 'the dispatcher evaluates a dynamic frame before the action reads it');
	verifyWad($frames[0]->arguments[1] instanceof Expression, 'the definition keeps its expressions for the next call');
})();
