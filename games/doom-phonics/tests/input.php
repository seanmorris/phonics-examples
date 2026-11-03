<?php
declare(strict_types=1);

use DoomPhonics\Component\PlayerCommand;
use DoomPhonics\Component\ViewInput;
use DoomPhonics\Input\DoomActions;
use DoomPhonics\Input\InputSettings;
use DoomPhonics\Input\LookInput;
use DoomPhonics\Game\GameSettings;
use DoomPhonics\Game\PitchMode;
use DoomPhonics\Game\RenderResolution;
use DoomPhonics\Menu\DoomOptions;
use Phonics\Input\ActionFrame;
use Phonics\Input\ActionInput;
use Phonics\Input\Controls;
use Phonics\Input\InputCode;
use Phonics\Input\Source;
use Phonics\Input\Key;
use Phonics\Input\Pad;

verifyWad((new Source(Source::PAD_BUTTON, Pad::A))->hint() === '⓿', 'controller face buttons use shared hint symbols');
verifyWad((new Source(Source::PAD_BUTTON, Pad::RB))->hint() === '❺', 'controller shoulders use shared hint symbols');

verifyWad(
	(new Source(Source::PAD_AXIS, Pad::LEFT_Y, -1))->hint() === '🡩'
	, 'left-stick directions use shared hint symbols'
);

verifyWad(
	(new Source(Source::PAD_AXIS, Pad::RIGHT_X, 1))->hint() === '🡲'
	, 'right-stick directions use shared hint symbols'
);

verifyWad((new Source(Source::KEY, ord('w')))->hint() === null, 'keyboard bindings remain textual');

$defaultBindings = DoomActions::defaults();
$firePad = $defaultBindings->sources(DoomActions::FIRE, 1, Source::CONTROLLER);
$altFireMouse = $defaultBindings->sources(DoomActions::ALT_FIRE, 1, Source::KEYBOARD);
$altFirePad = $defaultBindings->sources(DoomActions::ALT_FIRE, 1, Source::CONTROLLER);
$useKeyboard = $defaultBindings->sources(DoomActions::USE, 1, Source::KEYBOARD);

verifyWad(
	count($firePad) === 1 && $firePad[0]->kind === Source::PAD_AXIS && $firePad[0]->code === Pad::RT
		&& count($altFirePad) === 1 && $altFirePad[0]->kind === Source::PAD_AXIS && $altFirePad[0]->code === Pad::LT
	, 'controller fire modes default to RT and LT'
);

verifyWad(
	count($altFireMouse) === 1 && $altFireMouse[0]->kind === Source::MOUSE_BUTTON
		&& $altFireMouse[0]->code === Source::SECONDARY
		&& count($useKeyboard) === 1 && $useKeyboard[0]->kind === Source::KEY
		&& $useKeyboard[0]->code === ord('e')
	, 'Mouse 2 defaults to Alt Fire while E remains Use'
);

foreach(DoomActions::rows() as [$code])
verifyWad($code > InputCode::RESERVED_MAX, 'Doom gameplay codes are outside the reserved engine range');
$inputControls = new Controls;
$inputView = new ViewInput;
$inputCommand = new PlayerCommand;

$inputControls->set(new ActionFrame([
	DoomActions::STRAFE => -.5
	, DoomActions::FORWARD => .25
	, DoomActions::FIRE => 1
	, DoomActions::ALT_FIRE => 1
]));

$inputView->look(.12, .2);
$inputCommand->sample($inputControls, $inputView);

verifyWad(
	$inputCommand->strafe === -.5 && $inputCommand->forward === .25
		&& $inputCommand->fire && $inputCommand->altFire
	, 'signed movement and both fire modes survive command sampling'
);

verifyWad(
	$inputCommand->turn === 0.0 && $inputCommand->consumeYaw() === .12 && $inputView->yaw === 0.0
	, 'display-frame look transfers once without also applying turn'
);

$inputCommand->sample($inputControls, $inputView);
verifyWad($inputCommand->consumeYaw() === 0.0, 'additional catch-up steps do not repeat yaw');

$inputControls->set(new ActionFrame([], [], [
	DoomActions::USE => 1
	, DoomActions::NEXT_WEAPON => 3
	, DoomActions::PREVIOUS_WEAPON => 1
	, DoomActions::SLOT_0 => 1
]));

$inputCommand->sample($inputControls, $inputView);
$inputActions = $inputCommand->actions();
$received = new PlayerCommand;
$received->apply(json_decode(json_encode($inputActions), true));
verifyWad($received->actions() === $inputActions, 'actions round trip through the network wire');

verifyWad(
	$inputCommand->consumeUse() && $inputCommand->consumeWeaponSlot() === 0 && $inputCommand->consumeWeaponSteps() === 2
	, 'short taps, slot zero and counted wheel pulses survive'
);

$inputControls->set(new ActionFrame);
$inputCommand->sample($inputControls, $inputView);

verifyWad(
	!$inputCommand->consumeUse() && $inputCommand->consumeWeaponSteps() === 0
	, 'counted actions are not repeated'
);

$settings = new GameSettings;
[$x, $y] = ActionInput::stick(.1, 0);
verifyWad($x === 0.0 && $y === 0.0, 'look deadzone removes small drift');
[$x, $y] = ActionInput::stick(.12, 0);
verifyWad($x === 0.0 && $y === 0.0, 'deadzone boundary is neutral');
[$x, $y] = ActionInput::stick(.56, .56);

verifyWad(
	abs($x - $y) < 1e-12 && abs(hypot($x, $y) - pow((hypot(.56, .56) - .12) / .88, 1.5)) < 1e-12
	, 'radial curve preserves diagonal direction'
);

[$x] = ActionInput::stick(.56, 0);
verifyWad(abs($x - pow(.5, 1.5)) < 1e-12, 'fine aim uses the rescaled 1.5 exponent');
[$x, $y] = ActionInput::stick(1, 1);
verifyWad(abs(hypot($x, $y) - 1) < 1e-12, 'diagonal magnitude is capped without changing direction');

foreach([30, 60, 144] as $fps)
{
	$view = new ViewInput;
	$clock = new \Phonics\FixedStep(1 / 35, 4);
	$command = new PlayerCommand;
	$controls = new Controls;
	$angle = 0.0;
	$zeroStep = false;

	for($f = 0; $f < $fps; $f++)
	{
		LookInput::advance($view, new ActionFrame(devices: [
			Source::CONTROLLER => [DoomActions::TURN => 1]
		]), 1 / $fps, $settings
		);

		$preview = $angle + $view->yaw;
		$steps = 0;

		$clock->advance(1 / $fps, function() use (&$steps, &$angle, $command, $controls, $view): void {
			$steps++;
			$command->sample($controls, $view);
			$angle += $command->consumeYaw();
		});

		$zeroStep = $zeroStep || $steps === 0;

		verifyWad(
			abs($angle + $view->yaw - $preview) < 1e-12
			, "$fps Hz camera is continuous when pending look is consumed"
		);
	}

	verifyWad(abs($angle + $view->yaw + M_PI) < 1e-9, "$fps Hz produces 180 degrees per second");

	if($fps > 35) verifyWad($zeroStep, "$fps Hz includes moving camera frames without fixed updates");
}

$view = new ViewInput;

LookInput::advance($view, new ActionFrame(devices: [
	Source::CONTROLLER => [DoomActions::LOOK_PITCH => 1]
]), .1, $settings
);

verifyWad(abs($view->pitch - M_PI / 15) < 1e-12, 'right-stick up looks up at 120 degrees per second');
$settings->controllerInvertY = true;
$settings->controllerVertical = 2;

LookInput::advance($view, new ActionFrame(devices: [
	Source::CONTROLLER => [DoomActions::LOOK_PITCH => 1]
]), .1, $settings
);

verifyWad(abs($view->pitch + M_PI / 15) < 1e-12, 'vertical multiplier and inversion are independent');
$before = [$view->yaw, $view->pitch];
LookInput::advance($view, new ActionFrame, .5, $settings);
verifyWad([$view->yaw, $view->pitch] === $before, 'release stops immediately without recentering');

$mixedSettings = new GameSettings;
$mixedSettings->controllerHorizontal = 2;
$mixedSettings->controllerVertical = 1.5;
$mixedSettings->controllerInvertY = true;
$mixedSettings->setLookSensitivity(.5);
$mixed = new ViewInput;

LookInput::advance(
	$mixed
	, new ActionFrame(
		deltas: [DoomActions::TURN => 10, DoomActions::LOOK_PITCH => 20]
		, devices: [
			Source::CONTROLLER => [DoomActions::TURN => .5, DoomActions::LOOK_PITCH => .5]
		]
	)
	, .1
	, $mixedSettings
);

verifyWad(abs($mixed->yaw + M_PI / 10 + .0125) < 1e-12, 'mixed mouse and stick yaw retains independent sensitivity');
verifyWad(abs($mixed->pitch + M_PI / 20 - .02) < 1e-12, 'controller inversion does not invert mouse pitch');

$view->look(5, 5);
verifyWad($view->yaw === 1.5 && $view->pitch === .75, 'preview is limited to the accepted command bounds');
$command = new PlayerCommand;
$command->sample(new Controls, $view);
$remoteView = new ViewInput;
$remote = new PlayerCommand;
$remote->apply($command->actions(), $remoteView);

verifyWad(
	$remote->consumeYaw() === 1.5 && $remoteView->pitch === .75
	, 'host receives the same orientation the local camera previewed'
);

$remote = new PlayerCommand;
$peer = new \Phonics\Network\PeerId('guest');
$slot = new \Phonics\Network\PlayerSlot(2);
$validator = new \Phonics\Network\CommandValidator;
$validator->own($slot, $peer);

foreach([1 => [.2,.1], 2 => [.3,.2], 3 => [-.1,.3]] as $seq => [$yaw, $pitch])
{
	$wire = new \Phonics\Network\InputCommand(
		$slot
		, new \Phonics\Network\SimulationTick(10)
		, new \Phonics\Network\CommandSequence($seq)
		, [
			'yaw' => $yaw
			, 'pitch' => $pitch
		]
	);

	if($validator->validate($peer, $wire, new \Phonics\Network\SimulationTick(10)))
	$remote->apply($wire->actions(), $remoteView, true);

	verifyWad(
		!$validator->validate($peer, $wire, new \Phonics\Network\SimulationTick(10))
		, 'repeated sequence cannot apply another turn'
	);
}

verifyWad(
	abs($remote->consumeYaw() - .4) < 1e-12 && $remoteView->pitch === .3
	, 'batched accepted commands sum yaw and retain latest absolute pitch'
);

verifyWad($remote->consumeYaw() === 0.0, 'batched yaw is consumed only once');

$saved = '';
$writes = 0;

$legacyBindings = json_decode(json_encode(DoomActions::defaults()), true);
$legacyBindings[DoomActions::FIRE][1][Source::CONTROLLER] = [
	[
		'kind' => Source::PAD_BUTTON
		, 'code' => Pad::RB
		, 'sign' => 1
	]
];

$legacyBindings[DoomActions::USE][1][Source::KEYBOARD][] = [
	'kind' => Source::MOUSE_BUTTON
	, 'code' => Source::SECONDARY
	, 'sign' => 1
];

unset($legacyBindings[DoomActions::ALT_FIRE]);

$legacy = new InputSettings(new GameSettings, static fn(): string => json_encode([
	'version' => 1
	, 'bindings' => $legacyBindings
	, 'look' => []
]));

verifyWad(
	$legacy->bindings->sources(DoomActions::FIRE, 1, Source::CONTROLLER)[0]->code === Pad::RT
		&& $legacy->bindings->sources(DoomActions::ALT_FIRE, 1, Source::KEYBOARD)[0]->code === Source::SECONDARY
		&& count($legacy->bindings->sources(DoomActions::USE, 1, Source::KEYBOARD)) === 1
	, 'v1 defaults migrate RB to RT and move Mouse 2 from Use to Alt Fire'
);

$preferences = new InputSettings(new GameSettings, null, function(string $json) use (&$saved, &$writes): bool {
	$saved = $json;
	$writes++;

	return true;
});

$preferences->bindings->remove(DoomActions::FIRE, 1, new Source(Source::KEY, Key::SPACE));
$preferences->bindings->bind(DoomActions::FIRE, 1, new Source(Source::KEY, ord('f')), 'share');
$preferences->preference('controllerHorizontal', 2.5);
$restored = new InputSettings(new GameSettings, fn(): string => $saved);
verifyWad($restored->settings->controllerHorizontal === 2.5, 'controller preferences survive storage');
verifyWad((json_decode($saved, true)['version'] ?? null) === 2, 'input profiles save the current binding version');

verifyWad(
	json_encode($restored->bindings) === json_encode($preferences->bindings)
	, 'binding alternatives and removals survive storage'
);

$data = json_decode($saved, true);
$data['look']['controllerHorizontal'] = 'oops';
$data['look']['controllerVertical'] = 2;
$data['bindings'][DoomActions::USE][1][Source::CONTROLLER] = [
	[
		'kind' => 'pad-button'
		, 'code' => Pad::START
		, 'sign' => 1
	]
];

$invalid = new InputSettings(new GameSettings, fn(): string => json_encode($data));

verifyWad(
	$invalid->settings->controllerHorizontal === 1.0 && $invalid->settings->controllerVertical === 2.0
	, 'invalid saved preference falls back without losing valid entries'
);

verifyWad(
	$invalid->bindings->sources(DoomActions::USE, 1, Source::CONTROLLER)[0]->code === Pad::A
	, 'saved Pause reassignments fall back to defaults'
);

$preferences->reset(Source::CONTROLLER);

verifyWad(
	$preferences->settings->controllerHorizontal === 1.0 && json_encode(
		$preferences->bindings->sources(DoomActions::FIRE, 1, Source::KEYBOARD)
	) === json_encode($restored->bindings->sources(DoomActions::FIRE, 1, Source::KEYBOARD))
	, 'controller defaults preserve keyboard alternatives'
);

$unavailable = new InputSettings(new GameSettings, null, static function(): bool {
	throw new RuntimeException('Storage denied');
});

$unavailable->preference('controllerVertical', 3);

verifyWad(
	$unavailable->sessionOnly && $unavailable->settings->controllerVertical === 3.0
	, 'unavailable storage allows session-only edits'
);

$browserOptions = [
	'muted' => true
	, 'effectsVolume' => 'invalid'
	, 'musicVolume' => .4
	, 'renderResolution' => 'classic'
	, 'pitchMode' => 'invalid'
	, 'reticle' => false
	, 'bloodSplatter' => 'invalid'
	, 'fullscreen' => false
	, 'fullscreenAvailable' => true
	, 'fullscreenBusy' => false
	, 'fullscreenError' => ''
	, 'sessionOnly' => false
];

$optionWrites = [];
$optionSettings = new GameSettings;

$options = new DoomOptions(
	$optionSettings
	, static fn(): string => json_encode($browserOptions)
	, static function(string $json) use (&$browserOptions, &$optionWrites): string {
		$patch = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
		$optionWrites[] = $patch;
		$browserOptions = $patch + $browserOptions;

		return json_encode($browserOptions, JSON_THROW_ON_ERROR);
	}
);

verifyWad(
	$options->muted() && $options->effectsVolume() === 1.0 && $options->musicVolume() === .4
		&& $optionSettings->renderResolution() === RenderResolution::Classic
		&& $optionSettings->pitchMode() === PitchMode::Perspective
		&& !$optionSettings->reticle()
		&& $optionSettings->bloodSplatter() === 1.0
	, 'browser options restore valid fields without accepting invalid neighbors'
);

$options->setEffectsVolume(.6);
$options->setMusicVolume(.7);
$options->setMuted(false);
$options->setRenderResolution(RenderResolution::Native);
$options->setPitchMode(PitchMode::ClassicShear);
$options->setReticle(true);
$options->setBloodSplatter(.5);
$options->setFullscreen(true);

verifyWad(
	$options->effectsVolume() === .6 && $options->musicVolume() === .7 && !$options->muted()
		&& $options->fullscreen() && $optionSettings->reticle()
		&& $optionSettings->bloodSplatter() === .5 && count($optionWrites) === 8
	, 'sound and video menu changes cross the browser options bridge'
);

$sessionOptions = new DoomOptions(new GameSettings, null, static function(): string {
	throw new RuntimeException('Storage denied');
});

$sessionOptions->setMusicVolume(.5);
verifyWad($sessionOptions->state()['sessionOnly'], 'unavailable option storage preserves session-only changes');

$bindingsMenu = new \DoomPhonics\Menu\DoomControlsMenu($preferences);
$menu = new \Phonics\Ui\Menu(['controls' => $bindingsMenu->item()]);
$menu->open();
$menu->activate();
$menu->activate();
$menu->select('fire');
$menu->activate();
$menu->activate();
$physical = new ActionInput($preferences->bindings);
$enter = new Source(Source::KEY, Key::ENTER);
$physical->hold($enter, 1);
$bindingsMenu->update($menu, $physical, true);
verifyWad($bindingsMenu->capturing(), 'capture waits for the activating key to be released');
$physical->beginPoll();
$physical->hold($enter, 0);
$bindingsMenu->update($menu, $physical, true);
$physical->beginPoll();
$physical->hold(new Source(Source::KEY, ord('w')), 1);
$bindingsMenu->update($menu, $physical, true);

verifyWad(
	isset($menu->rows()['replace'], $menu->rows()['share'], $menu->rows()['cancel'])
	, 'capture presents explicit conflict choices'
);

$menu->select('replace');
$menu->activate();

verifyWad(
	$preferences->bindings->conflicts(DoomActions::FIRE, 1, new Source(Source::KEY, ord('w'))) === []
	, 'replace removes only the captured source from other actions'
);

verifyWad(
	count($preferences->bindings->sources(DoomActions::FORWARD, 1, Source::CONTROLLER)) === 1
	, 'keyboard replacement leaves controller movement intact'
);

$writesBefore = $writes;

for($i = 0; $i < 10; $i++) $menu->refresh();
verifyWad($writes === $writesBefore, 'menu refresh does not write storage');

$menu->select('add-binding');
$menu->activate();
$physical->reset();
$physical->hold(new Source(Source::PAD_AXIS, \Phonics\Input\Pad::RIGHT_X), .05);
$bindingsMenu->update($menu, $physical, true);
verifyWad($bindingsMenu->capturing(), 'stick drift does not complete binding capture');
$physical->beginPoll();
$physical->hold(new Source(Source::KEY, ord('p')), 1);
$bindingsMenu->update($menu, $physical, true);
verifyWad($bindingsMenu->capturing(), 'fixed pause key cannot be assigned to gameplay');
$physical->beginPoll();
$physical->hold(new Source(Source::PAD_BUTTON, \Phonics\Input\Pad::START), 1);
$bindingsMenu->update($menu, $physical, true);

verifyWad(
	!$bindingsMenu->capturing() && $writes === $writesBefore
	, 'Start cancels capture without changing saved bindings'
);

(static function() use ($addonDefinitions): void {
	$worlds = $sessions = $players = $views = $commands = [];
	$queues = ['host' => [], 'guest' => []];
	$sequences = ['host' => 0, 'guest' => 0];
	$protocol = new \Phonics\Network\Protocol;

	foreach(['host', 'guest'] as $side)
	{
		$slot = $side === 'host' ? 1 : 2;
		$other = $side === 'host' ? 'guest' : 'host';

		$match = new \DoomPhonics\Game\DoomMatch(new \Phonics\Network\MatchContext(
			$side
			, new \Phonics\Network\PlayerSlot($slot)
			, new \Phonics\Network\PlayerSlot(3 - $slot)
			, new \Phonics\Network\PeerId($side)
			, new \Phonics\Network\PeerId($other)
			, 'sha256-AAAA='
			, [
				'settings' => ['mode' => 'cooperative']
			]
		));

		// Deliver three guest ticks in one host update, including redundant history in every packet.
		if($side === 'host') for($i = 0; $i < 3; $i++) $match->clock->advance();

		$world = $worlds[$side] = new \Phonics\Ecs\Registry;

		foreach([1, 2] as $playerSlot)
		{
			$local = $playerSlot === $slot;

			$players[$side][$playerSlot] = $world->create(
				new \DoomPhonics\Component\NetworkPlayer(
					new \Phonics\Network\PlayerSlot($playerSlot)
					, new \Phonics\Network\PeerId($local ? $side : $other)
					, $local
				)
				, $views[$side][$playerSlot] = new ViewInput
				, $commands[$side][$playerSlot] = new PlayerCommand
				, new \DoomPhonics\Component\Transform(0, 0, 0, 0)
			);
		}

		$bridge = new \Phonics\Network\BrowserBridge(
			static function(int $limit) use ($side, &$queues): string {
				return json_encode(array_splice($queues[$side], 0, $limit));
			}
			, static function(
				string $channel
				, int $family
				, int $tick
				, int $schema
				, string $json
			) use ($side, $other, $slot, $protocol, &$queues, &$sequences): bool {
				$packet = new \Phonics\Network\Packet(
					$channel
					, $family
					, 0
					, 1
					, $slot
					, ++$sequences[$side]
					, new \Phonics\Network\SimulationTick($tick)
					, 0
					, 0
					, $schema
					, json_decode($json, true)
				);

				$queues[$other][] = ['channel' => $channel, 'data' => base64_encode($protocol->encode($packet))];

				return true;
			}
		);

		$sessions[$side] = new \DoomPhonics\System\EpisodeNetworkSystem(
			$world
			, $match
			, 'episode'
			, $addonDefinitions->actors
			, $bridge
		);
	}

	$guestSettings = new GameSettings;
	$guestSettings->controllerHorizontal = 2;
	$guestSettings->controllerVertical = .5;
	$guestAngle = 0.0;

	foreach([.4, .7, -.2] as $turn)
	{
		LookInput::advance($views['guest'][2], new ActionFrame(devices: [
			Source::CONTROLLER => [DoomActions::TURN => $turn, DoomActions::LOOK_PITCH => .25]
		]), 1 / 35, $guestSettings
		);

		$commands['guest'][2]->sample(new Controls, $views['guest'][2]);
		$sessions['guest']->receive($worlds['guest'], 1 / 35);
		$guestAngle += $commands['guest'][2]->consumeYaw();
		$sessions['guest']->send($worlds['guest'], 1 / 35);
	}

	$views['host'][1]->look(.17, -.2);
	$commands['host'][1]->sample(new Controls, $views['host'][1]);
	$sessions['host']->receive($worlds['host'], 1 / 35);

	verifyWad(
		abs($commands['host'][2]->consumeYaw() - $guestAngle) < 1e-10
		, 'real Doom replication preserves a batch of guest yaw commands and ignores redundant history'
	);

	verifyWad(
		abs($views['host'][2]->pitch - $views['guest'][2]->pitch) < 1e-10
		, 'host uses guest absolute pitch with independent sensitivity settings'
	);

	verifyWad(abs($commands['host'][1]->consumeYaw() - .17) < 1e-10, 'host self-application does not double local yaw');
	$commands['host'][1]->sample(new Controls, $views['host'][1]);
	$sessions['host']->receive($worlds['host'], 1 / 35);
	verifyWad($commands['host'][2]->consumeYaw() === 0.0, 'following host update cannot repeat the accepted batch');

	foreach($sessions as $session) $session->close();
})();

(static function() use ($plainSource): void {
	$episode = new \DoomPhonics\Tests\EpisodeMovement($plainSource);
	$episode->armWeapon();
	$world = $episode->scope->registry;
	$player = $world->query(\DoomPhonics\Component\PlayerStart::class)[0];
	$view = $world->get($player, ViewInput::class);
	$pose = $world->get($player, \DoomPhonics\Component\Transform::class);

	LookInput::advance($view, new ActionFrame(devices: [
		Source::CONTROLLER => [DoomActions::TURN => .5, DoomActions::LOOK_PITCH => .5]
	]), 1 / 60, new GameSettings
	);

	$displayed = $pose->angle + $view->yaw;
	$episode->controls->set(new ActionFrame([DoomActions::FIRE => 1]));

	for($i = 0; $i < 35 && !$world->query(\DoomPhonics\Component\Projectile::class); $i++) $episode->advance(1 / 35);
	$missiles = $world->query(\DoomPhonics\Component\Projectile::class);
	verifyWad(count($missiles) > 0, 'offline commands fire the equipped projectile weapon');
	$velocity = $world->get($missiles[0], \DoomPhonics\Component\Velocity::class);

	verifyWad(
		abs(atan2($velocity->y, $velocity->x) - $displayed) < 1e-9
		, 'projectile heading agrees with the previewed camera heading'
	);

	verifyWad(
		abs($velocity->z / hypot($velocity->x, $velocity->y) - tan($view->pitch)) < 1e-9
		, 'projectile elevation agrees with immediate camera pitch'
	);

	$episode->scope->close();
})();
