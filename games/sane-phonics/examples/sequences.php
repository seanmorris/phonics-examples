<?php
declare(strict_types=1);

use Phonics\Input\Sequences;

return
/**
 * Register observable demo inputs without changing movement or executing combat actions.
 *
 * @param Sequences $input Logical keyboard/gamepad matcher owned by SDL input.
 * @return void Adds Konami, moving-boxes debug and example fighting-game combinations.
 */
static function(Sequences $input): void {
	$input->add('konami', ['up','up','down','down','left','right','left','right',1,0]);
	$input->add('debug', [12,12,13,13,12,12,12,12]);
	$input->add('special', ['down','forward',1], .3);
	$input->add('uppercut', [['press' => 2, 'held' => ['down']]]);
	$input->add('two-button', [[0,1]]);
};
