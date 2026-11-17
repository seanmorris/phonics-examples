<?php
declare(strict_types=1);

require_once 'phar://' . Phar::running(false) . '/core/autoload.php';

$app = new \Phonics\Application\Context;
$phonics = $app->compose(__DIR__ . '/worlds/rpsc.world', __DIR__ . '/phonics.json#game', null);

// The lobby is created after the game's own browser module has taken the canvas into its frame, so the lobby
// returns the canvas to that frame when a table ends. The two modules after it hold the hidden-placement primitive
// and the online game; the game's module finds all three on the session when Play online is first chosen.
$app->lobby([
	'provider' => '@matrix/lobby'
	, 'title' => 'RPSC Online'
	, 'options' => [
		'homeserver' => 'https://matrix.org'
		, 'room' => '#phonics-rpsc:matrix.org'
		, 'configurationKey' => 'rpsc'
	]
	, 'game' => [
		'id' => 'org.seanmorris.phonics.rpsc'
		, 'protocol' => '1.0.0'
		, 'rules' => 'rpsc-v1'
		// Five minutes a step, so a player can step away briefly; a longer stall forfeits the game.
		, 'actionTimeoutMs' => 300000
		, 'players' => 2
		, 'spectators' => false
		, 'aspectRatio' => 'auto'
		, 'checkpointMode' => 'history'
		, 'actionBatches' => true
		, 'maximumActionBytes' => 49152
		, 'actionTypes' => ['placement-packet', 'move', 'challenge', 'answer', 'reveal']
	]
]);
$app->browser(__DIR__ . '/browser/mental-poker.mjs');
$app->browser(__DIR__ . '/browser/online-match.mjs');

require $app->entry();
