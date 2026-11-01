<?php
declare(strict_types=1);

require_once 'phar://' . Phar::running(false) . '/core/autoload.php';
require_once __DIR__ . '/autoload.php';

use Phonics\Application\Context;
use PhpBilliards\Physics\Simulation;

$app = new Context;
$app->browser(__DIR__ . '/browser/game.mjs');

$assetManifest = json_decode(
	file_get_contents(__DIR__ . '/assets/generated/textures.json')
	, true
	, flags: JSON_THROW_ON_ERROR
);

foreach($assetManifest as $name => &$asset)
{
	$asset['data'] = base64_encode(file_get_contents(__DIR__ . '/assets/generated/' . $asset['file']));
}
unset($asset);
(vrzno_env('billiardsAssets'))(json_encode($assetManifest, JSON_THROW_ON_ERROR));

$game = new Simulation;
$window = new Vrzno;
$last = null;
$accumulator = 0.0;
$raf = null;
$running = true;
$aim = 0.0;
$power = .58;
$top = 0.0;
$side = 0.0;

$frame = null;
$frame = static function(float $now) use (
	$game, $window, &$frame, &$last, &$accumulator, &$raf, &$running, &$aim, &$power, &$top, &$side
): void {
	if(!$running) return;

	$elapsed = $last === null ? 0 : min(.1, max(0, ($now - $last) / 1000));
	$last = $now;
	$input = json_decode((string)(vrzno_env('billiardsInput'))(), true, flags: JSON_THROW_ON_ERROR);
	$aim = deg2rad((float)($input['aim'] ?? rad2deg($aim)));
	$power = max(.05, min(1, (float)($input['power'] ?? $power)));
	$top = max(-1, min(1, (float)($input['top'] ?? $top)));
	$side = max(-1, min(1, (float)($input['side'] ?? $side)));

	foreach((array)($input['actions'] ?? []) as $action)
	{
		if($action === 'shoot') $game->shoot($aim, $power, $top, $side);
		elseif($action === 'rack') $game->rack();
		elseif($action === 'reset') $game->resetShot();
		elseif($action === 'place')
		$game->placeCue((float)($input['cueX'] ?? -.65), (float)($input['cueY'] ?? 0));
	}

	$accumulator += $elapsed;
	$steps = 0;

	while($accumulator >= Simulation::STEP && $steps++ < 6)
	{
		$game->step(Simulation::STEP);
		$accumulator -= Simulation::STEP;
	}

	if($steps > 6) $accumulator = 0;

	$state = $game->state($accumulator / Simulation::STEP) + [
		'aim' => $aim
		, 'power' => $power
		, 'top' => $top
		, 'side' => $side
		, 'events' => $game->consumeEvents()
	];

	(vrzno_env('billiardsRender'))(json_encode($state, JSON_THROW_ON_ERROR));
	$raf = $window->requestAnimationFrame($frame);
};

$close = null;
$refresh = vrzno_env('onRefresh');
$close = static function() use (&$running, &$raf, $window, $refresh, &$close): void {
	if(!$running) return;

	$running = false;

	if($raf !== null) $window->cancelAnimationFrame($raf);

	$refresh->delete($close);
};

$refresh->add($close);
$raf = $window->requestAnimationFrame($frame);
$app->ready();
