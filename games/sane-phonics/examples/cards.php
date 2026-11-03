<?php
use Phonics\Ui\Card;
use Phonics\Ui\CardSequence;

/**
 * Configure the demo's startup screens with editable PHP content and timing.
 *
 * @param callable():bool $ready Gate indicating that the title backdrop can render.
 * @param bool $titleOnly Whether to return directly to the title after leaving the main menu.
 * @return CardSequence Fresh sequence; the caller owns starting, updating, and cancelling it.
 */
function demoCards(callable $ready, bool $titleOnly = false): CardSequence
{
	$title = new Card(
		'title'
		, "PHP WASM\nENGINE"
		, 'Tiled worlds. PHP gameplay.'
		, duration: null
		, skipAfter: 2
		, fadeIn: .6
		, fadeOut: .3
	);

	if($titleOnly)
	{
		return new CardSequence([$title]);
	}

	return new CardSequence([
		new Card(
			'loading'
			, 'Loading the demo'
			, 'Discovering title assets...'
			, duration: 1
			, fadeIn: .25
			, fadeOut: .25
			, skippable: false
			, ready: $ready
		)
		, new Card(
			'engine'
			, 'PHP WASM ENGINE'
			, "Written in PHP\nPowered by SDL and WebGL 2"
			, duration: 4.5
			, fadeIn: .5
			, fadeOut: .5
		)
		, new Card(
			'controls'
			, 'Keyboard or controller'
			, "✚ Move   ⓿ Select   ❶ Back\nStart / Enter to continue"
			, duration: 3
		)
		, $title
	]);
}
