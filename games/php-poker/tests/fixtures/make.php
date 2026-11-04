<?php
declare(strict_types=1);

// Regenerates tests/fixtures/views/*.json from the real reducer, so the presenter tests see exactly what
// Session::view() produces. Each fixture is the first view that matches its condition under a fixed seed.
// Usage: php tests/fixtures/make.php
require __DIR__ . '/../../autoload.php';
$engineRoot = getenv('PHONICS_ENGINE_ROOT');
foreach(array_filter([$engineRoot ? $engineRoot . '/autoload.php' : null, __DIR__ . '/../../../../node_modules/@seanmorris/phonics/autoload.php', __DIR__ . '/../../../../../../node_modules/@seanmorris/phonics/autoload.php']) as $engine)
if(is_file($engine)) { require_once $engine; break; }

use PhpPoker\Session;

$targets = [
	'opening-8' => [8, 'jackpots', static fn(array $v): bool => $v['phase'] === 'betting' && $v['turn'] === 0 && !empty($v['legalActions']['pass']) && !empty($v['legalActions']['bet'])],
	'facing-raise-5' => [5, 'anything', static fn(array $v): bool => $v['phase'] === 'betting' && $v['turn'] === 0 && !empty($v['legalActions']['call']) && !empty($v['legalActions']['bet'])],
	'draw-2' => [2, 'anything', static fn(array $v): bool => $v['phase'] === 'draw' && $v['turn'] === 0],
	'showdown-5' => [5, 'anything', static fn(array $v): bool => $v['phase'] === 'showdown' && !empty($v['legalActions']['next'])],
];
$views = [];
foreach($targets as $name => [$players, $variant, $condition])
{
	$found = null;
	for($seed = 1; $seed <= 200 && $found === null; $seed++)
	{
		$session = new Session;
		$session->command(['type' => 'start', 'players' => $players, 'variant' => $variant], str_pad(dechex($seed), 64, '0', STR_PAD_LEFT));
		$session->command(['type' => 'pace', 'value' => 'fast']);
		for($step = 0; $step < 400; $step++)
		{
			$view = $session->view();
			if($condition($view)) { $found = $view; break; }
			if($view['turn'] < 0 && !in_array($view['phase'], ['showdown','redeal'], true)) break;
			if(in_array($view['phase'], ['showdown','redeal'], true)) break;
			if($view['turn'] > 0) { $session->tick(.2); continue; }
			$legal = $view['legalActions'];
			$intent = !empty($legal['draw']) ? ['type' => 'draw', 'cards' => []]
				: (!empty($legal['call']) ? ['type' => 'call'] : (!empty($legal['check']) ? ['type' => 'check'] : (!empty($legal['pass']) ? ['type' => 'pass'] : ['type' => 'fold'])));
			$session->command($intent + ['generation' => $view['generation'], 'revision' => $view['revision']]);
			if($view['error'] !== '') throw new RuntimeException("$name: {$view['error']}");
		}
	}
	if($found === null) throw new RuntimeException("No seed reached $name");
	$views[$name] = $found;
}
$views['menu'] = (new Session)->view();
$views['menu']['canContinue'] = true;

@mkdir(__DIR__ . '/views', 0775, true);
foreach($views as $name => $view)
file_put_contents(__DIR__ . "/views/$name.json", json_encode($view, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo 'Wrote ' . count($views) . " fixtures.\n";
