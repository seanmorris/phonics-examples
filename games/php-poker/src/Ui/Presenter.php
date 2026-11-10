<?php
declare(strict_types=1);
namespace PhpPoker\Ui;

use PhpPoker\Odds;
use PhpPoker\WildCards;
use PhpPoker\Render\Text;
use PhpPoker\Render\Theme;
use PhpPoker\Render\UiCanvas;
use PhpPoker\Render\UiRenderer;

/**
 * The table's presentation: turns the session view and raw input into intents and frames.
 *
 * The presenter never touches the rules. It holds only presentation state: the selected discards, the raise-to
 * amount, the local screens that the session does not know (table setup, help, settings, history), the visual
 * mode, and the input lock. It sends at most one game intent per view revision and ignores further game input
 * until a view with a new revision or a non-empty error arrives.
 */
final class Presenter
{
	private const KEY_CARDS = ['1' => 0, '2' => 1, '3' => 2, '4' => 3, '5' => 4];

	/** @var array<string,mixed> */
	/** Revision and text of the error currently shown, so it can be retired when the table moves on. */
	private ?int $errorRevision = null;
	private string $errorText = '';
	private array $view = ['screen' => 'menu', 'phase' => 'ready', 'players' => [], 'legalActions' => [], 'revision' => 0, 'generation' => 0, 'error' => '', 'canContinue' => false];
	/** @var array<string,mixed> */
	private array $textures;
	private Text $text;
	private ?UiRenderer $renderer = null;
	private ?UiCanvas $frame = null;
	private bool $dirty = true;
	private bool $closed = false;
	private string $mode = Theme::CLASSIC;
	/** Local screen when the session is on its menu: menu, setup, help or settings. */
	private string $local = 'menu';
	/** @var list<int> Card ids selected for discard; presentation only until Draw is sent. */
	private array $discards = [];
	private int $amount = 0;
	private int $setupPlayers = 4;
	private string $setupVariant = 'jackpots';
	private int $setupAnte = 1;
	private int $setupMinimum = 2;
	private int $setupMaximum = 20;
	private int $setupFinalMinimum = 2;
	private int $setupFinalMaximum = 20;
	private int $setupRaiseLimit = 3;
	private int $setupPlayerRaiseLimit = 3;
	/** @var array<string,int> Editable copy used by the combined betting and raising dialog. */
	private array $limitDraft = [];
	/** @var list<string> */
	private array $setupWild = [];
	/** @var list<int> */
	private array $setupCustomWild = [];
	/** @var list<string> */
	private array $wildDraft = [];
	/** @var list<int> */
	private array $customWildDraft = [];
	private int $customWildPage = 0;
	private bool $historyOpen = false;
	/** View revision a game intent was sent for; input is locked until the view moves past it. */
	private ?int $lockedRevision = null;
	private ?array $cardAtlas = null;
	private bool $cardArtSent = false;
	/** @var array{float,float}|null Last pointer position in device pixels. */
	private ?array $pointer = null;
	private int $width = 1280;
	private int $height = 720;
	private float $dpr = 1.0;
	private bool $receivedView = false;
	private array $chips = [];
	private ?Motion $motion = null;
	private float $winnerElapsed = 4;
	private bool $winnerVisible = true;
	private string $networkWaitKey = '';
	private float $networkWaitElapsed = 0;
	private int $networkWaitDots = 0;
	/** First help paragraph shown in the measured scroll viewport. */
	private int $helpScroll = 0;
	/** Latest input family, used to expose controller focus and swap the on-canvas help. */
	private string $inputTheme = 'keyboard';
	/** Open table-bar menu and modal odds table. */
	private ?string $topMenu = null;
	private ?string $infoDialog = null;
	private ?string $confirmDialog = null;
	/** Online table whose house rules already opened by themselves; the browser names a table only on first sight. */
	private ?string $houseRulesShown = null;
	/** New Game leaves an online room or paused local table before opening setup. */
	private bool $setupAfterMenu = false;
	/** Exact dealt/drawing tables, cached by the active wild-card rules. */
	private array $oddsCache = [];
	/** Short feedback from an explicit file save or load. */
	private int $fileRevision = -1;
	private string $fileNotice = '';
	private float $fileNoticeElapsed = 0;
	/** Stable controller target ID; visible targets resolve their rectangle from current layout state. */
	private ?string $controllerFocus = null;
	/** A neutral table focus was active while no local action could be taken. */
	private bool $controllerAwaitingTurn = false;
	/** Held controller actions and their next navigation repeat times. */
	private array $controllerHeld = [], $controllerNext = [];

	/**
	 * @param string $root Absolute root directory of the Poker package.
	 */
	public function __construct(private string $root)
	{
		$this->textures = json_decode((string)file_get_contents($root . '/assets/generated/textures.json'), true, flags: JSON_THROW_ON_ERROR);
		$this->text = Text::load($root, $this->textures['uiFont']);
		if(isset($this->textures['uiFont']) && !isset($this->text->faces()['dos'])) $this->mode = Theme::MODERN;
	}

	/**
	 * Accept a new authorized view from the session.
	 *
	 * @param array<string,mixed> $view `Session::view()`.
	 */
	public function update(array $view): void
	{
		$previous = $this->view;
		$this->view = $view;
		$fileRevision=(int)($view['fileRevision']??-1);
		if($fileRevision!==$this->fileRevision)
		{
			$this->fileRevision=$fileRevision;
			$this->fileNotice=(string)($view['fileNotice']??'');
			$this->fileNoticeElapsed=$this->fileNotice===''?0:3;
		}
		if(($view['phase'] ?? '') === 'showdown' && ($previous['phase'] ?? '') !== 'showdown' && $this->receivedView)
		{ $this->winnerElapsed = 0; $this->winnerVisible = true; }
		elseif(($view['phase'] ?? '') !== 'showdown') { $this->winnerElapsed = 4; $this->winnerVisible = true; }
		if(!$this->receivedView)
		{
			$this->chips = Chips::fromView($view);
			$this->receivedView = true;
		}
		elseif(!empty($view['fileLoaded']) && ($view['screen'] ?? '') === 'table')
		{
			$this->chips = Chips::fromView($view);
			$this->motion = null;
		}
		elseif(($view['revision'] ?? 0) !== ($previous['revision'] ?? 0) || ($view['generation'] ?? 0) !== ($previous['generation'] ?? 0))
		{
			$change = Chips::transition($previous, $view, $this->chips);
			$this->chips = $change['after'];
			$motion = new Motion($previous, $view, $change, $this->discards);
			$this->motion = $motion->active() ? $motion : null;
		}
		$revision = (int)($view['revision'] ?? 0);
		if($revision !== (int)($previous['revision'] ?? -1) || ($view['generation'] ?? 0) !== ($previous['generation'] ?? 0))
		{
			$this->discards = [];
			$this->lockedRevision = null;
			$this->amount = (int)($view['legalActions']['minBet'] ?? 0);
		}
		// An online action is first shown with its controls suppressed, then confirmed at the same revision. Clamp
		// when betting becomes visible as well as when the revision changes, so its spinbox never exposes stale zero.
		if(!empty($view['legalActions']['bet']))
		$this->amount = max((int)$view['legalActions']['minBet'], min((int)$view['legalActions']['maxBet'], $this->amount));
		// A rejected action's message belongs to the revision it was rejected on: once the table has moved on, drop it.
		$error = (string)($view['error'] ?? '');
		if($error === '') $this->errorRevision = null;
		elseif($this->errorRevision === null || $error !== $this->errorText) [$this->errorRevision, $this->errorText] = [$revision, $error];
		elseif($revision !== $this->errorRevision) $this->view['error'] = '';
		if(($this->view['error'] ?? '') !== '') $this->lockedRevision = null;
		if(($view['screen'] ?? 'menu') !== 'menu') $this->local = (string)$view['screen'];
		elseif($this->setupAfterMenu) { $this->local = 'setup'; $this->setupAfterMenu = false; }
		elseif(($previous['screen'] ?? 'menu') !== 'menu' || !in_array($this->local, ['setup','limits','wildcards','custom-wildcards','online'], true)) $this->local = 'menu';
		if(($view['screen'] ?? '') !== 'table') { $this->topMenu = null; $this->infoDialog = null; $this->confirmDialog=null; $this->historyOpen=false; }
		$houseRules = is_string($view['houseRules'] ?? null) && $view['houseRules'] !== '' ? $view['houseRules'] : null;
		if($houseRules !== null && $houseRules !== $this->houseRulesShown && ($view['screen'] ?? '') === 'table')
		{
			// A new online match opens its house rules once, over the table, before anyone acts.
			$this->houseRulesShown = $houseRules;
			$this->topMenu = null;
			$this->infoDialog = 'game-settings';
			$this->dirty = true;
		}
		if(($view['screen'] ?? '') === 'help' && ($previous['screen'] ?? '') !== 'help') $this->helpScroll = 0;
		$this->syncNetworkWait();
		$this->dirty = true;
	}

	/**
	 * Turn one frame of input into session intents.
	 *
	 * @param list<array{key:string}> $controls Key presses since the last frame, lowercase key names.
	 * @param array{x:float,y:float,down:bool,clicks:list<array{x:float,y:float}>}|null $pointer Pointer state in device pixels.
	 * @param float $elapsed Seconds since the last frame.
	 * @param array<string,bool|float> $controller Shared SDL UI buttons: directions, confirm, back, pause and shoulders.
	 * @param string $inputTheme `keyboard`, `generic`, `xbox`, or `playstation`.
	 * @return list<array<string,mixed>> Intents for `Session::command()`, in order.
	 */
	public function input(array $controls, ?array $pointer, float $elapsed, array $controller = [], string $inputTheme = 'keyboard'): array
	{
		$intents = [];
		if($inputTheme !== $this->inputTheme)
		{
			$this->inputTheme = $inputTheme;
			$this->dirty = true;
		}
		if($inputTheme === 'keyboard') $controller = [];
		array_push($intents, ...$this->controller($controller, $elapsed));
		if($pointer && isset($pointer['x'], $pointer['y']))
		{
			$position = [(float)$pointer['x'], (float)$pointer['y']];
			if($this->pointer === null || abs($this->pointer[0] - $position[0]) > .5 || abs($this->pointer[1] - $position[1]) > .5) $this->dirty = true;
			$this->pointer = $position;
			foreach($pointer['clicks'] ?? [] as $click) if(isset($click['x'], $click['y']))
			array_push($intents, ...$this->click((float)$click['x'], (float)$click['y']));
		}
		foreach($controls as $control) if(isset($control['key'])) array_push($intents, ...$this->key(strtolower((string)$control['key'])));

		return $intents;
	}

	/**
	 * Turn shared SDL controller state into focus movement and activations.
	 *
	 * Directions repeat after a deliberate hold; activation, Back, Start and shoulders only fire on a new press.
	 * @param array<string,bool|float> $buttons
	 * @return list<array<string,mixed>>
	 */
	private function controller(array $buttons, float $elapsed): array
	{
		$elapsed = is_finite($elapsed) ? max(0, $elapsed) : 0;
		$names = ['left','right','up','down','confirm','back','pause','pageUp','pageDown'];
		$pulses = [];
		foreach($names as $name)
		{
			$down = (float)($buttons[$name] ?? 0) >= .5;
			if(!$down)
			{
				unset($this->controllerHeld[$name], $this->controllerNext[$name]);
				$pulses[$name] = 0;
				continue;
			}
			if(!isset($this->controllerHeld[$name]))
			{
				$this->controllerHeld[$name] = 0.0;
				$this->controllerNext[$name] = .5;
				$pulses[$name] = 1;
				continue;
			}
			// A slow browser frame must not replay every missed navigation interval at once. Cap the amount of
			// hold time one frame may contribute and emit at most one repeat on that frame.
			$this->controllerHeld[$name] += min(.1,$elapsed);
			$pulses[$name] = 0;
			if(!in_array($name, ['left','right','up','down'], true)) continue;
			if($this->controllerHeld[$name] + 1e-9 >= $this->controllerNext[$name])
			{
				$pulses[$name]=1;
				$this->controllerNext[$name] += $this->controllerNext[$name] < 1.5 - 1e-9 ? .25 : .05;
			}
		}

		if($this->inputTheme === 'keyboard')
		{
			$this->controllerHeld = $this->controllerNext = [];
			return [];
		}

		$targets = $this->controllerTargets();
		$this->ensureControllerFocus($targets);
		if($pulses['pause']) return $this->controllerPause();
		if($pulses['back']) return $this->controllerBack();
		$help = ($this->view['screen'] ?? '') === 'help';
		if($help)
		{
			if($pulses['pageUp'] || $pulses['up']) $this->scrollHelp(-1);
			if($pulses['pageDown'] || $pulses['down']) $this->scrollHelp(1);
			$pulses['up'] = $pulses['down'] = 0;
		}
		else
		{
			if($pulses['pageUp']) { $this->step(-1); $this->dirty = true; }
			if($pulses['pageDown']) { $this->step(1); $this->dirty = true; }
		}
		foreach(['left','right','up','down'] as $direction)
		for($count = $pulses[$direction]; $count > 0; $count--) $this->moveControllerFocus($direction, $targets);
		if($pulses['confirm'])
		{
			$this->ensureControllerFocus($targets);
			foreach($targets as $target) if($target['id'] === $this->controllerFocus) return $this->activateTarget($target);
		}
		return [];
	}

	/** @return list<array<string,mixed>> */
	private function click(float $x, float $y): array
	{
		$point = $this->layout()->design($x, $y);
		$this->dirty = true;
		foreach($this->clickTargets() as $target) if(Layout::hit($point, $target['rect']))
		return $this->activateTarget($target);
		return [];
	}

	/** @return list<array<string,mixed>> */
	private function activateTarget(array $target): array
	{
		$this->dirty = true;
		if(!empty($target['disabled'])) return [];
		if(isset($target['key'])) return $this->key($target['key']);
		if(isset($target['confirm'])) return $this->activateConfirmation((string)$target['confirm']);
		if(isset($target['topMenu'])) { $menu=(string)$target['topMenu']; $this->historyOpen=false; $this->topMenu=$this->topMenu===$menu?null:$menu; return []; }
		if(isset($target['topAction'])) return $this->activateTopAction((string)$target['topAction']);
		if(isset($target['info']))
		{
			$this->infoDialog=$target['info']==='close'?null:(string)$target['info'];
			return [];
		}
		if(isset($target['menu'])) return $this->activateMenu($target['menu']);
		if(isset($target['wildPreset'])) { $this->toggleWildPreset($target['wildPreset']); return []; }
		if(isset($target['customWild'])) { $this->toggleCustomWild((int)$target['customWild']); return []; }
		if(isset($target['customPage'])) { $this->customWildPage=max(0,min(4,$this->customWildPage+(int)$target['customPage'])); return []; }
		if(isset($target['limit'])) { $this->adjustLimit((string)$target['limit'], (int)($target['delta'] ?? 0)); return []; }
		if(isset($target['card'])) { $this->toggleCard($target['card']); return []; }
		if(isset($target['button'])) return $this->activate($target['button']['id'], $target['button']['intent']);
		return [];
	}

	/** Active targets in hit-test order, shared by clicks and browser cursor feedback. */
	private function clickTargets(): array
	{
		$layout = $this->layout();
		$targets = [];
		$addKey = static function(array $rect, string $key, ?string $id = null, bool $disabled = false) use (&$targets): void {
			$target = ['rect' => $rect, 'key' => $key];
			if($id !== null) $target['id'] = $id;
			if($disabled) $target['disabled'] = true;
			$targets[] = $target;
		};
		$wholeCanvas = [-$layout->left / $layout->horizontalScale, -$layout->top / $layout->scale, $this->width / $layout->horizontalScale, $this->height / $layout->scale];
		if(($this->view['screen'] ?? 'menu') === 'menu')
		{
			if($this->local === 'setup')
			{
				$setup = $layout->setup($this->setupPlayers, $this->setupVariant);
				if($this->setupPlayers > 2) $addKey($setup['less'], 'arrowleft', 'setup:less');
				if($this->setupPlayers < 8) $addKey($setup['more'], 'arrowright', 'setup:more');
				$addKey($setup['variant'], 'v', 'setup:variant');
				$addKey($setup['wild'], 'w', 'setup:wild');
				$addKey($setup['limits'], 'l', 'setup:limits');
				$addKey($setup['back'], 'escape', 'setup:back');
				$addKey($setup['start'], 'enter', 'setup:start');
			}
			elseif($this->local === 'limits')
			{
				$limits = $layout->limits();
				foreach($limits['fields'] as $field => $stepper)
				{
					[$low,$high]=$this->limitBounds($field);
					$value=$this->limitDraft[$field]??$low;
					$targets[]=['id'=>'limit:'.$field.':less','rect'=>$stepper['less'],'limit'=>$field,'delta'=>-1,'disabled'=>$value<=$low];
					$targets[]=['id'=>'limit:'.$field.':more','rect'=>$stepper['more'],'limit'=>$field,'delta'=>1,'disabled'=>$value>=$high];
				}
				$addKey($limits['back'], 'escape', 'limit:back');
				$addKey($limits['apply'], 'enter', 'limit:apply');
			}
			elseif($this->local === 'wildcards')
			{
				$menu = $layout->wildcardMenu();
				foreach($menu['items'] as $id => $rect) $targets[] = ['id'=>'wild:'.$id,'rect'=>$rect,'wildPreset'=>$id];
				$addKey($menu['back'], 'escape', 'wild:back');
				$addKey($menu['apply'], 'enter', 'wild:apply');
			}
			elseif($this->local === 'custom-wildcards')
			{
				$custom = $layout->customWildcards($this->customWildPage);
				foreach($custom['cards'] as $id => $rect) $targets[] = ['id'=>'custom-wild:'.$id,'rect'=>$rect,'customWild'=>$id];
				if($custom['paged'] && $this->customWildPage > 0) $targets[]=['id'=>'custom-wild:previous','rect'=>$custom['previous'],'customPage'=>-1];
				if($custom['paged'] && $this->customWildPage < 4) $targets[]=['id'=>'custom-wild:next','rect'=>$custom['next'],'customPage'=>1];
				$addKey($custom['back'], 'escape', 'custom-wild:back');
				$addKey($custom['done'], 'enter', 'custom-wild:done');
			}
			elseif($this->local !== 'menu') $addKey($wholeCanvas, 'escape');
			else foreach($layout->menu(!empty($this->view['canContinue'])) as $item)
			$targets[] = ['id' => 'menu:' . $item['id'], 'rect' => $item['rect'], 'menu' => $item];
			return $targets;
		}

		if(in_array($this->view['screen'] ?? '', ['help','settings'], true))
		{
			if(($this->view['screen'] ?? '') === 'settings')
			{
				$toggle = $layout->setup(0, '');
				$addKey($toggle['pace'], 'p', 'settings:pace');
				$addKey($toggle['variant'], 'm', 'settings:look');
				$addKey($toggle['back'], 'escape', 'settings:back');
			}
			else
			{
				$information = $layout->information();
				$maximum = $this->helpMaximumScroll();
				$addKey($information['up'], 'arrowup', 'help:up', $this->helpScroll === 0);
				$addKey($information['back'], 'escape', 'help:back');
				$addKey($information['down'], 'arrowdown', 'help:down', $this->helpScroll === $maximum);
			}
			return $targets;
		}

		if($this->confirmDialog !== null)
		{
			$dialog=$layout->confirmationDialog();
			$targets[]=['id'=>'confirm:cancel','rect'=>$dialog['cancel'],'confirm'=>'cancel'];
			$targets[]=['id'=>'confirm:leave','rect'=>$dialog['confirm'],'confirm'=>'leave'];
			$addKey($wholeCanvas,'escape');
			return $targets;
		}

		if($this->infoDialog !== null)
		{
			if($this->infoDialog==='game-settings') $dialog=$layout->gameSettingsDialog($this->gameSettingsWildLines($this->gameSettings()));
			else $dialog=$layout->oddsDialog($this->infoDialog,count($this->oddsTables()[$this->infoDialog]));
			if($this->infoDialog==='odds') $targets[]=['id'=>'info:more','rect'=>$dialog['more'],'info'=>'improving'];
			$targets[]=['id'=>'info:ok','rect'=>$dialog['ok'],'info'=>'close'];
			$addKey($wholeCanvas,'escape');
			return $targets;
		}

		if($this->topMenu !== null)
		{
			foreach($layout->topMenus() as $menu=>$rect) $targets[]=['id'=>'top:'.$menu,'rect'=>$rect,'topMenu'=>$menu];
			foreach($layout->topDropdown($this->topMenu)['items'] as $item)
			{
				$disabled=($this->view['mode']??'offline')==='online' && in_array($item['id'],['new','save','load'],true);
				$disabled=$disabled || ($item['id']==='save' && empty($this->view['canContinue']));
				$targets[]=['id'=>'top-item:'.$item['id'],'rect'=>$item['rect'],'topAction'=>$item['id'],'disabled'=>$disabled];
			}
			$addKey($wholeCanvas,'escape');
			return $targets;
		}

		foreach($layout->topMenus() as $menu=>$rect) $targets[]=['id'=>'top:'.$menu,'rect'=>$rect,'topMenu'=>$menu];

		if($this->historyOpen)
		{
			$players=array_slice($this->view['players']??[],0,8);
			$history=RoundHistory::build($players,$this->view['history']??[]);
			$addKey($layout->history(count($players),count($history['rows']),count($history['lines']))['back'], 'enter', 'history:back');
			$addKey($wholeCanvas, 'escape');
			return $targets;
		}

		if(!empty($this->view['legalActions']['draw']) && !$this->locked())
		{
			$cards = $this->view['players'][0]['cards'] ?? [];
			$dialog = $layout->discardDialog($cards, $this->discards);
			// Cards paint from left to right, so a later card is visually above an earlier one where their fans
			// overlap. Hit-test in reverse paint order while preserving each card's raised visible edge.
			foreach(array_reverse($dialog['cards'], true) as $index => $rect) if(isset($cards[$index]))
			$targets[] = ['id' => 'card:' . $index, 'rect' => $rect, 'card' => $index];
		}

		if($stepper = $layout->stepper($this->view))
		{
			$addKey($stepper['less'], '-', 'step:less');
			$addKey($stepper['more'], '+', 'step:more');
		}

		foreach($layout->actions($this->view, $this->controlState()) as $button) if(!$this->locked() || $button['intent'] === null)
		$targets[] = ['id' => 'action:' . $button['id'], 'rect' => $button['rect'], 'button' => $button];

		return $targets;
	}

	/** Whether table focus should rest neutrally while no local game action can be taken. */
	private function controllerWaiting(): bool
	{
		if(($this->view['screen'] ?? '') !== 'table' || $this->modal()) return false;
		if($this->locked()) return true;
		foreach($this->layout()->actions($this->view, $this->controlState()) as $button)
		if($button['intent'] !== null) return false;
		return true;
	}

	/** Controller targets; broad click-to-dismiss regions are deliberately omitted. */
	private function controllerTargets(): array
	{
		$targets = array_values(array_filter($this->clickTargets(), static fn(array $target): bool => isset($target['id']) && empty($target['disabled'])));
		if(!$this->controllerWaiting()) return $targets;
		// Keep the menu reachable by moving Up, but do not let an old action, draw control or bet stepper retain focus
		// after its turn. The zero-size centre target is navigation geometry only and never paints or accepts clicks.
		$targets = array_values(array_filter($targets, static fn(array $target): bool => str_starts_with($target['id'], 'top:')));
		$layout = $this->layout();
		$targets[] = ['id'=>'table:waiting', 'rect'=>[$layout->width()/2,$layout->height()/2,0,0], 'hidden'=>true];
		return $targets;
	}

	/** @param list<array<string,mixed>> $targets */
	private function ensureControllerFocus(array $targets): void
	{
		$waiting = false;
		foreach($targets as $target) if($target['id'] === 'table:waiting') { $waiting = true; break; }
		if($waiting) $this->controllerAwaitingTurn = true;
		elseif($this->controllerAwaitingTurn && ($this->view['screen'] ?? '') === 'table' && !$this->modal())
		{
			// A turn began while the neutral target (or a menu heading reached from it) held focus. Choose this
			// turn's affirmative action once; subsequent navigation within the turn remains undisturbed.
			$this->controllerFocus = null;
			$this->controllerAwaitingTurn = false;
		}
		foreach($targets as $target) if($target['id'] === $this->controllerFocus) return;
		$preferences = $this->confirmDialog !== null ? ['confirm:cancel','confirm:leave']
			: ($this->infoDialog !== null ? ['info:ok','info:more']
			: ($this->topMenu !== null ? ['top-item:new','top-item:game-settings','top-item:odds']
			: ['table:waiting','action:check','action:call','action:pass','action:draw','action:next','action:deal',
				'action:bet','action:fold','setup:start','wild:none','custom-wild:0','settings:look']));
		$next = null;
		foreach($preferences as $id) foreach($targets as $target) if($target['id'] === $id) { $next = $id; break 2; }
		$next ??= $targets[0]['id'] ?? null;
		if($next !== $this->controllerFocus) { $this->controllerFocus = $next; $this->dirty = true; }
	}

	/** Move focus to the nearest target in one screen-space direction. */
	private function moveControllerFocus(string $direction, array $targets): void
	{
		$this->ensureControllerFocus($targets);
		$current = null;
		foreach($targets as $target) if($target['id'] === $this->controllerFocus) { $current = $target; break; }
		if($current === null) return;
		[$x, $y, $w, $h] = $current['rect'];
		$cx = $x + $w / 2; $cy = $y + $h / 2;
		$best = null; $bestScore = INF;
		foreach($targets as $target)
		{
			if($target['id'] === $this->controllerFocus) continue;
			[$tx, $ty, $tw, $th] = $target['rect'];
			$dx = $tx + $tw / 2 - $cx; $dy = $ty + $th / 2 - $cy;
			[$primary, $cross] = match($direction) {
				'left' => [-$dx, abs($dy)], 'right' => [$dx, abs($dy)], 'up' => [-$dy, abs($dx)], default => [$dy, abs($dx)]
			};
			// Raised discard cards differ slightly in Y. Require meaningful travel on the requested axis so Down
			// reaches the action row instead of slipping sideways to an unraised neighbour.
			if($primary <= max(1e-6, $cross * .25)) continue;
			$score = $primary + 1.75 * $cross + hypot($dx, $dy) * .05;
			if($score < $bestScore) { $bestScore = $score; $best = $target['id']; }
		}
		if($best !== null && $best !== $this->controllerFocus)
		{ $this->controllerFocus = $best; $this->dirty = true; }
	}

	/** @return list<array<string,mixed>> */
	private function controllerBack(): array
	{
		if($this->historyOpen) return $this->key('escape');
		if(($this->view['screen'] ?? 'menu') === 'menu' && $this->local === 'menu') return [];
		return $this->key('escape');
	}

	/** @return list<array<string,mixed>> */
	private function controllerPause(): array
	{
		if(($this->view['screen'] ?? 'menu') === 'table') return $this->key('escape');
		if(!empty($this->view['canContinue'])) return [['type' => 'resume']];
		if(($this->view['screen'] ?? 'menu') !== 'menu') return [['type' => 'menu']];
		if($this->local !== 'menu') { $this->local = 'menu'; $this->dirty = true; }
		return [];
	}

	/** @return list<array<string,mixed>> */
	private function key(string $key): array
	{
		$this->dirty = true;
		$screen = $this->view['screen'] ?? 'menu';
		if($screen==='table' && in_array($key,['alt+g','alt+p'],true))
		{
			$menu=$key==='alt+g'?'game':'poker';
			$this->infoDialog=null; $this->confirmDialog=null; $this->historyOpen=false;
			$this->topMenu=$this->topMenu===$menu?null:$menu;
			return [];
		}

		if($screen === 'menu')
		{
			if($this->local === 'setup')
			{
				return match($key) {
					'arrowleft', '-' => (function(): array { $this->setupPlayers = max(2, $this->setupPlayers - 1); return []; })(),
					'arrowright', '+', '=' => (function(): array { $this->setupPlayers = min(8, $this->setupPlayers + 1); return []; })(),
					'arrowup', 'arrowdown', 'v' => (function(): array { $this->setupVariant = $this->setupVariant === 'jackpots' ? 'anything' : 'jackpots'; return []; })(),
					'w' => (function(): array { $this->wildDraft=$this->setupWild; $this->customWildDraft=$this->setupCustomWild; $this->local='wildcards'; return []; })(),
					'l' => (function(): array { $this->openLimits(); return []; })(),
					'escape', 'backspace' => (function(): array { $this->local = 'menu'; return []; })(),
					'enter', ' ' => [['type' => 'start', 'players' => $this->setupPlayers, 'variant' => $this->setupVariant,
						'ante'=>$this->setupAnte,'minimum'=>$this->setupMinimum,'maximum'=>$this->setupMaximum,
						'finalMinimum'=>$this->setupFinalMinimum,'finalMaximum'=>$this->setupFinalMaximum,
						'raiseLimit'=>$this->setupRaiseLimit,'playerRaiseLimit'=>$this->setupPlayerRaiseLimit,
						'wild'=>$this->setupWild,'customWild'=>$this->setupCustomWild]],
					default => [],
				};
			}
			if($this->local === 'limits')
			{
				if(in_array($key, ['escape','backspace'], true)) { $this->local='setup'; $this->limitDraft=[]; return []; }
				if(in_array($key, ['enter',' '], true)) { $this->applyLimits(); return []; }
				return [];
			}
			if($this->local === 'wildcards')
			{
				$options = array_keys(WildCards::OPTIONS);
				if(in_array($key, ['0','1','2','3','4','5','6','7'], true)) { $this->toggleWildPreset($options[(int)$key]); return []; }
				if(in_array($key, ['escape','backspace'], true)) { $this->local='setup'; return []; }
				if(in_array($key, ['enter',' '], true))
				{
					$this->setupWild=$this->wildDraft;
					$this->setupCustomWild=in_array('custom', $this->wildDraft, true) ? $this->customWildDraft : [];
					$this->local='setup';
					return [];
				}
				return [];
			}
			if($this->local === 'custom-wildcards')
			{
				$paged = $this->layout()->customWildcards($this->customWildPage)['paged'];
				if($paged && $key === 'arrowleft') { $this->customWildPage=max(0,$this->customWildPage-1); return []; }
				if($paged && $key === 'arrowright') { $this->customWildPage=min(4,$this->customWildPage+1); return []; }
				if(in_array($key, ['escape','backspace'], true)) { $this->local='wildcards'; return []; }
				if(in_array($key, ['enter',' '], true))
				{ $this->setupWild=$this->wildDraft; $this->setupCustomWild=$this->customWildDraft; $this->local='setup'; return []; }
				return [];
			}
			if($this->local !== 'menu') { if(in_array($key, ['escape','backspace','enter',' '], true)) $this->local = 'menu'; return []; }
			$items = $this->layout()->menu(!empty($this->view['canContinue']));
			$shortcut = ['o' => 'offline', 'c' => 'continue', 'p' => 'online', 'h' => 'help', 'm' => 'settings'][$key] ?? null;
			if($shortcut !== null) foreach($items as $item) if($item['id'] === $shortcut) return $this->activateMenu($item);
			return match($key) {
				'enter', ' ' => $this->activateMenu($items[0]),
				default => [],
			};
		}

		if($screen === 'help')
		{
			if(in_array($key, ['arrowup','pageup'], true)) { $this->scrollHelp(-1); return []; }
			if(in_array($key, ['arrowdown','pagedown'], true)) { $this->scrollHelp(1); return []; }
			if(in_array($key, ['escape','backspace','enter',' ','q'], true)) return [['type' => 'menu']];
			return [];
		}

		if($screen === 'settings')
		{
			if($key === 'p') return [['type' => 'pace', 'value' => ($this->view['pace'] ?? 'normal') === 'fast' ? 'normal' : 'fast']];
			if(in_array($key, ['m','v','enter',' '], true)) { $this->mode = $this->mode === Theme::CLASSIC ? Theme::MODERN : Theme::CLASSIC; return []; }
			if(in_array($key, ['escape','backspace','enter',' ','q'], true)) return [['type' => 'menu']];
			return [];
		}

		if($this->confirmDialog !== null)
		{
			// Forfeiting takes the explicit Y. Enter and Space choose the safe default, as controller focus does, so a
			// doubled Escape-Enter or Q-Q cannot resign a match.
			if($key==='y') return $this->activateConfirmation('leave');
			if(in_array($key,['escape','backspace','n','enter',' '],true)) return $this->activateConfirmation('cancel');
			return [];
		}

		if($this->infoDialog !== null)
		{
			if($this->infoDialog==='odds' && $key==='m') $this->infoDialog='improving';
			elseif(in_array($key,['escape','backspace','enter',' '],true)) $this->infoDialog=null;
			return [];
		}
		if($this->topMenu !== null)
		{
			if(in_array($key,['escape','backspace'],true)) { $this->topMenu=null; return []; }
			$action=($this->topMenu==='game'
				? ['n'=>'new','s'=>'save','l'=>'load','q'=>'quit']
				: ['r'=>'game-settings','h'=>'history','o'=>'odds','i'=>'improving'])[$key]??null;
			return $action===null?[]:$this->activateTopAction($action);
		}

		if($key === 'escape')
		{
			if($this->historyOpen) { $this->historyOpen = false; return []; }
			return $this->leaveTable();
		}
		if($key === 'h') { $this->historyOpen = !$this->historyOpen; return []; }
		if($this->historyOpen) { if(in_array($key, ['enter',' '], true)) $this->historyOpen = false; return []; }

		if(isset(self::KEY_CARDS[$key]) && !empty($this->view['legalActions']['draw']) && !$this->locked())
		{
			$this->toggleCard(self::KEY_CARDS[$key]);
			return [];
		}
		if($key === '-' || $key === 'arrowdown') { $this->step(-1); return []; }
		if($key === '+' || $key === '=' || $key === 'arrowup') { $this->step(1); return []; }

		$legend = match($key) {'f' => 'F', 'c' => 'C', 'b' => 'B', 'd' => 'D', 'enter', ' ' => '↵', 'l' => 'L', default => null};
		if($legend === null) return [];
		foreach($this->layout()->actions($this->view, $this->controlState()) as $button) if($button['key'] === $legend)
		return $this->activate($button['id'], $button['intent']);

		return [];
	}

	/** @return list<array<string,mixed>> */
	private function activateMenu(array $item): array
	{
		if($item['id'] === 'offline') { $this->local = 'setup'; return []; }
		if($item['intent'] === null) { $this->local = 'online'; return []; }
		return [$item['intent']];
	}

	/** @return list<array<string,mixed>> */
	private function activateTopAction(string $action): array
	{
		$online=($this->view['mode']??'offline')==='online';
		if(($online && in_array($action,['new','save','load'],true))
			|| ($action==='save' && empty($this->view['canContinue']))) return [];
		$this->topMenu=null;
		if($action==='new') { $this->setupAfterMenu=true; return [['type'=>'menu']]; }
		if($action==='save') return [['type'=>'save-game']];
		if($action==='load') return [['type'=>'load-game']];
		if($action==='quit') return $this->leaveTable();
		if($action==='history') { $this->historyOpen=true; return []; }
		if(in_array($action,['game-settings','odds','improving'],true)) { $this->infoDialog=$action; return []; }
		return [];
	}

	/** @return list<array<string,mixed>> */
	private function activateConfirmation(string $action): array
	{
		if($action==='cancel') { $this->confirmDialog=null; return []; }
		if($action!=='leave') return [];
		$this->confirmDialog=null; $this->setupAfterMenu=false;
		return [['type'=>'menu']];
	}

	/** Values shown by Poker > House Rules, derived from the authoritative profile in the current view. */
	private function gameSettings(): array
	{
		$profile=is_array($this->view['profile']??null)?$this->view['profile']:[];
		$minimum=(int)($profile['minimum']??2);
		$maximum=(int)($profile['maximum']??20);
		$finalMinimum=(int)($profile['finalMinimum']??$minimum);
		$finalMaximum=(int)($profile['finalMaximum']??$maximum);
		$raiseLimit=(int)($profile['raiseLimit']??3);
		$playerRaiseLimit=(int)($profile['playerRaiseLimit']??$raiseLimit);
		$selection=$profile['wild']??[];
		$custom=is_array($profile['customWild']??null)?$profile['customWild']:[];
		try { $wild=WildCards::describe(is_string($selection)||is_array($selection)?$selection:[],$custom); }
		catch(\InvalidArgumentException) { $wild='No wild cards'; }
		$players=max(2,count($this->view['players']??[]));
		$mode=($this->view['mode']??'offline')==='online'?'Online':'Offline';
		$ante=(int)($profile['ante']??1);
		return [
			'table'=>$players.' players · '.$mode,
			'opening'=>($profile['variant']??'jackpots')==='anything'?'Anything opens':'Jacks or better',
			'wild'=>$wild,
			'ante'=>$ante.' '.($ante===1?'chip':'chips'),
			'before'=>$minimum.'–'.$maximum.' chips',
			'after'=>$finalMinimum.'–'.$finalMaximum.' chips',
			'raises'=>(string)$raiseLimit,
			'playerRaises'=>(string)$playerRaiseLimit
		];
	}

	/** Exact number of lines needed by the active wild-card description in the current settings window. */
	private function gameSettingsWildLines(array $settings): int
	{
		$layout=$this->layout();
		$rect=$layout->gameSettingsDialog(1)['rows']['wild'];
		$labelWidth=min(132*$layout->stretch(),$rect[2]*.36);
		$valueWidth=$rect[2]-$labelWidth-10*$layout->stretch();
		$text=(string)($settings['wild']??'No wild cards');
		if($this->mode===Theme::CLASSIC) $text=strtoupper($text);
		$face=$this->mode===Theme::CLASSIC&&isset($this->text->faces()['dos'])?'dos':'bold';
		$lines=$this->text->wrap($text,$layout->controlText()*$layout->scale,$valueWidth*$layout->horizontalScale,3,$face);
		return max(1,min(3,count($lines)));
	}

	/** Exact dialog rows for the active deck, reused until its wild-card rules change. */
	private function oddsTables(): array
	{
		$profile=is_array($this->view['profile']??null)?$this->view['profile']:[];
		$selection=$profile['wild']??[];
		$custom=is_array($profile['customWild']??null)?$profile['customWild']:[];
		try
		{
			$selection=WildCards::selection(is_string($selection)||is_array($selection)?$selection:[]);
			$wild=WildCards::ids($selection,$custom);
			$deck=WildCards::deck($selection,$custom);
		}
		catch(\InvalidArgumentException)
		{
			$selection=[]; $custom=[]; $wild=[]; $deck=range(0,51);
		}
		$key=json_encode([$selection,$custom],JSON_THROW_ON_ERROR);
		if(isset($this->oddsCache[$key])) return $this->oddsCache[$key];

		$dealt=Odds::dealt($deck,$wild);
		$dealtRows=[];
		foreach(Odds::DEALT_ROWS as $hand)
		{
			$hits=(int)($dealt['counts'][$hand]??0);
			if($hits===0) continue;
			$dealtRows[]=['hand'=>$hand,'odds'=>$this->formatOdds(Odds::against($hits,$dealt['total']))];
		}
		$drawingRows=[]; $previous='';
		foreach(Odds::drawing($deck,$wild) as $row)
		{
			$hand=(string)$row['hand'];
			$drawingRows[]=['hand'=>$hand===$previous?'...':$hand,'target'=>(string)$row['target'],
				'odds'=>$this->formatOdds(Odds::against((int)$row['hits'],(int)$row['trials']))];
			$previous=$hand;
		}
		if($drawingRows===[]) $drawingRows[]=['hand'=>'No natural starting hands','target'=>'','odds'=>''];
		$rules=WildCards::describe($selection,$custom);
		return $this->oddsCache[$key]=['odds'=>$dealtRows,'improving'=>$drawingRows,'rules'=>$rules];
	}

	/**
	 * Fractional odds against, as printed in odds tables: "20/1" against, "1/1" for evens, and "1/4.2" for a
	 * favourite (4.2 to 1 on), instead of a hard-to-read "0.24/1".
	 */
	private function formatOdds(?float $odds): string
	{
		if($odds===null) return 'Never';
		if($odds<=0) return 'Certain';
		if($odds<.995) return '1/'.self::oddsNumber(1/$odds);
		return self::oddsNumber($odds).'/1';
	}

	private static function oddsNumber(float $value): string
	{
		$rounded=round($value);
		$decimals=abs($value-$rounded)<.005||$value>=1000?0:($value>=10?1:2);
		$text=number_format($value,$decimals,'.',',');
		return $decimals>0?rtrim(rtrim($text,'0'),'.'):$text;
	}

	/**
	 * Leave the table. Offline nothing is lost: the table is saved after every action and Continue restores it, so
	 * the title opens at once. Online, leaving resigns the match, so ask first.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function leaveTable(): array
	{
		$this->topMenu=null; $this->infoDialog=null; $this->historyOpen=false;
		if(($this->view['mode']??'offline')==='online') { $this->confirmDialog='forfeit'; return []; }
		$this->confirmDialog=null; $this->setupAfterMenu=false;
		return [['type'=>'menu']];
	}

	/** @return list<array<string,mixed>> */
	private function activate(string $id, ?array $intent): array
	{
		if($id === 'clear') { $this->discards = []; return []; }
		if($id === 'history') { $this->historyOpen = true; return []; }
		if($intent === null || $this->locked()) return [];
		$this->lockedRevision = (int)($this->view['revision'] ?? 0);
		return [$intent + ['generation' => $this->view['generation'] ?? 0, 'revision' => $this->view['revision'] ?? 0]];
	}

	private function openLimits(): void
	{
		$this->limitDraft = [
			'ante'=>$this->setupAnte,'minimum'=>$this->setupMinimum,'maximum'=>$this->setupMaximum,
			'finalMinimum'=>$this->setupFinalMinimum,'finalMaximum'=>$this->setupFinalMaximum,
			'raiseLimit'=>$this->setupRaiseLimit,'playerRaiseLimit'=>$this->setupPlayerRaiseLimit
		];
		$this->local = 'limits';
	}

	private function applyLimits(): void
	{
		if(!$this->limitDraft) return;
		$this->setupAnte=$this->limitDraft['ante'];
		$this->setupMinimum=$this->limitDraft['minimum'];
		$this->setupMaximum=$this->limitDraft['maximum'];
		$this->setupFinalMinimum=$this->limitDraft['finalMinimum'];
		$this->setupFinalMaximum=$this->limitDraft['finalMaximum'];
		$this->setupRaiseLimit=$this->limitDraft['raiseLimit'];
		$this->setupPlayerRaiseLimit=$this->limitDraft['playerRaiseLimit'];
		$this->limitDraft=[];
		$this->local='setup';
	}

	private function adjustLimit(string $field, int $delta): void
	{
		if(!$this->limitDraft || !array_key_exists($field, $this->limitDraft) || !in_array($delta, [-1,1], true)) return;
		[$low,$high] = $this->limitBounds($field);
		$this->limitDraft[$field]=max($low,min($high,$this->limitDraft[$field]+$delta));
	}

	/** @return array{int,int} */
	private function limitBounds(string $field): array
	{
		return match($field) {
			'ante' => [0,100], 'minimum' => [1,$this->limitDraft['maximum']],
			'maximum' => [$this->limitDraft['minimum'],100],
			'finalMinimum' => [1,$this->limitDraft['finalMaximum']],
			'finalMaximum' => [$this->limitDraft['finalMinimum'],100],
			default => [0,10]
		};
	}

	private function toggleCard(int $index): void
	{
		$cards = $this->view['players'][0]['cards'] ?? [];
		if(!isset($cards[$index]) || $cards[$index] === null) return;
		$id = (int)$cards[$index];
		$position = array_search($id, $this->discards, true);
		if($position === false) $this->discards[] = $id;
		else array_splice($this->discards, (int)$position, 1);
		sort($this->discards);
	}

	private function toggleCustomWild(int $card): void
	{
		$position = array_search($card, $this->customWildDraft, true);
		if($position === false) $this->customWildDraft[] = $card;
		else array_splice($this->customWildDraft, (int)$position, 1);
		sort($this->customWildDraft, SORT_NUMERIC);
	}

	private function toggleWildPreset(string $preset): void
	{
		if($preset === 'none') { $this->wildDraft=[]; return; }
		$position = array_search($preset, $this->wildDraft, true);
		if($position === false)
		{
			$this->wildDraft[] = $preset;
			if($preset === 'custom') { $this->customWildPage=0; $this->local='custom-wildcards'; }
		}
		else array_splice($this->wildDraft, (int)$position, 1);
		$this->wildDraft = WildCards::selection($this->wildDraft);
	}

	private function step(int $direction): void
	{
		$legal = $this->view['legalActions'] ?? [];
		if(empty($legal['bet'])) return;
		$increment = max(1, (int)($legal['minBet'] ?? 1) - (int)($this->view['highest'] ?? 0));
		$this->amount = max((int)$legal['minBet'], min((int)$legal['maxBet'], $this->amount + $direction * max(1, (int)($legal['betIncrement'] ?? $this->view['profile']['minimum'] ?? $increment))));
	}

	/** Scroll the rules by one paragraph, keeping every stop meaningful at every viewport size. */
	private function scrollHelp(int $direction): void
	{
		$next = max(0, min($this->helpMaximumScroll(), $this->helpScroll + ($direction <=> 0)));
		if($next !== $this->helpScroll) { $this->helpScroll = $next; $this->dirty = true; }
	}

	/** Copy shared with the painter so wrapping, overflow and scroll stops are measured from the same text. */
	public static function helpParagraphs(bool $controller): array
	{
		return [
			'The dealer antes. Everyone receives five cards face down.',
			'Jacks or better: a pair of jacks or higher is needed to open. Anything opens: any hand may open.',
			'Each checked wild-card rule may be combined with the others. Wild cards may stand for any missing rank and suit. Joker adds both jokers to the deck; Custom may add either one. Other choices designate cards already in the 52-card deck.',
			'Betting starts left of the dealer. Fold, pass or check, call, or raise.',
			'Draw: tap cards to select discards. Press Draw to replace them, or Stand pat to keep all five.',
			'After the draw, final betting starts with the opener. With no bet to call, check or bet; otherwise call, raise, or fold.',
			'Best to worst: five of a kind, straight flush, four of a kind, full house, flush, straight, three of a kind, two pair, pair, high card.',
			$controller ? 'Controller: use the D-pad or left stick to move focus, then the confirm button to select.' : 'Keys: F fold, C call/check/pass, B bet/raise, + and - change the amount.',
			$controller ? 'The back button goes back, Start asks before leaving the table, and the shoulder buttons change the bet.' : '1 to 5 select cards, D draws, H shows history, Alt+G and Alt+P open the table menus, and Escape asks before leaving.'
		];
	}

	/** Last useful paragraph scroll stop after measuring the wrapped copy in the current help window. */
	private function helpMaximumScroll(): int
	{
		$layout = $this->layout();
		$information = $layout->information();
		$box = $information['box'];
		$paragraphs = self::helpParagraphs($this->inputTheme !== 'keyboard');
		$size = $layout->controlText();
		$leading = $size * 1.45;
		$start = $box[1] + 36;
		$bottom = $information['back'][1] - 14;
		$face = $this->mode === Theme::CLASSIC && isset($this->text->faces()['dos']) ? 'dos' : 'bold';
		$fits = function(int $first) use ($paragraphs, $size, $leading, $start, $bottom, $box, $layout, $face): bool {
			$y = $start;
			foreach(array_slice($paragraphs, $first) as $paragraph)
			{
				$shown = $this->mode === Theme::CLASSIC ? strtoupper($paragraph) : $paragraph;
				$lines = $this->text->wrap($shown, $size * $layout->scale, ($box[2] - 48) * $layout->horizontalScale, 20, $face);
				foreach($lines as $_)
				{
					if($y + $leading / 2 > $bottom) return false;
					$y += $leading;
				}
				$y += 8;
			}
			return true;
		};
		for($first = 0; $first < count($paragraphs); $first++) if($fits($first)) return $first;
		return count($paragraphs) - 1;
	}

	/** Layout of the current canvas; the profile follows its size and pixel ratio. */
	public function layout(): Layout
	{
		$seats = count($this->view['players'] ?? []);
		return Layout::fit($this->width, $this->height, $this->dpr, $seats >= 2 ? $seats : 8);
	}

	/** Update geometry before consuming input, including the first frame after a resize. */
	public function resize(int $width, int $height, float $dpr): void
	{
		if([$width, $height, $dpr] === [$this->width, $this->height, $this->dpr]) return;
		[$this->width, $this->height, $this->dpr] = [$width, $height, $dpr];
		$this->dirty = true;
	}

	/** Whether an intent is outstanding or a visible card/chip transfer has yet to finish. */
	public function locked(): bool
	{
		return $this->animating() || ($this->lockedRevision !== null && $this->lockedRevision === (int)($this->view['revision'] ?? 0) && ($this->view['error'] ?? '') === '');
	}

	/** A visible transition holds game input and bot turns until its chips/cards have arrived. */
	public function animating(): bool { return $this->motion !== null && ($this->view['screen'] ?? '') === 'table'; }

	/** Table-bar menus and dialogs pause local bot decisions while the player reads or saves. */
	public function modal(): bool { return $this->topMenu !== null || $this->infoDialog !== null || $this->confirmDialog !== null || $this->historyOpen; }

	/** Advance presentation time only while the table is visible. No redraw remains scheduled after completion. */
	public function advance(float $seconds): void
	{
		if(($this->view['screen'] ?? '') !== 'table' || $seconds <= 0) return;
		if($this->motion)
		{
			$this->motion->advance($seconds);
			if(!$this->motion->active()) $this->motion = null;
			$this->dirty = true;
		}
		if($this->winnerElapsed < 4)
		{
			$this->winnerElapsed = min(4, $this->winnerElapsed + $seconds);
			$visible = $this->winnerElapsed >= 4 || (int)floor($this->winnerElapsed / .3) % 2 === 0;
			// min() returns the integer 4, stored as 4.0, so compare with >=. Reaching the end must redraw even when a
			// slow frame jumps from a lit phase straight past it and visibility does not change.
			if($visible !== $this->winnerVisible || $this->winnerElapsed >= 4.0) $this->dirty = true;
			$this->winnerVisible = $visible;
		}
		$this->syncNetworkWait();
		if($this->networkWaitKey !== '')
		{
			$this->networkWaitElapsed += $seconds;
			$dots = (int)floor($this->networkWaitElapsed / .3) % 4;
			if($dots !== $this->networkWaitDots) $this->dirty = true;
			$this->networkWaitDots = $dots;
		}
		if($this->fileNoticeElapsed>0)
		{
			$before=$this->fileNoticeElapsed;
			$this->fileNoticeElapsed=max(0,$before-$seconds);
			if($before>0 && $this->fileNoticeElapsed===0) $this->dirty=true;
		}
	}

	/** Reset the visual cadence whenever the table starts or finishes a distinct network wait. */
	private function syncNetworkWait(): void
	{
		$key = $this->networkWaitKey();
		if($key === $this->networkWaitKey) return;
		$this->networkWaitKey = $key;
		$this->networkWaitElapsed = 0;
		$this->networkWaitDots = 0;
		$this->dirty = true;
	}

	/** A presentation-only key for online work or settlement that may take more than one frame. */
	private function networkWaitKey(): string
	{
		if(($this->view['screen'] ?? '') !== 'table' || ($this->view['mode'] ?? 'offline') !== 'online'
			|| ($this->view['error'] ?? '') !== '' || $this->motion) return '';
		$line = $this->guidance();
		if($line === '') return '';
		if(!empty($this->view['waitingForConfirmation']) || ($this->view['phase'] ?? '') === 'ready') return $line;
		return preg_match('/\b(?:waiting|shuffl|verif|private cards?)\b/i', $line) === 1 ? $line : '';
	}

	/** Explain the current step without inviting a draw while betting or waiting for another seat. */
	private function guidance(): string
	{
		if(($this->view['screen'] ?? '') !== 'table') return '';
		if($this->fileNoticeElapsed>0 && $this->fileNotice!=='') return $this->fileNotice;
		if(($this->view['phase'] ?? '') === 'showdown') return '';
		if($this->motion) return match($this->motion->status()['kind']) { 'deal' => 'Dealing cards.', 'draw' => 'Discarding and drawing replacements.', default => 'Moving chips.' };
		if(!empty($this->view['waitingForConfirmation'])) return (string)($this->view['message'] ?? 'Waiting for both players to confirm the action.');
		$phase = $this->view['phase'] ?? '';
		$legal = $this->view['legalActions'] ?? [];
		$human = $this->view['players'][0] ?? [];
		if(in_array($phase, ['betting','draw','final-betting'], true) && !empty($human['folded'])) return 'You folded. Waiting for the other players.';
		if($phase === 'betting') return !empty($legal['openingRequired'])
			? 'Need jacks or better. Pass to stay in.' : 'Betting first; choose discards afterward.';
		if($phase === 'draw' && empty($legal['draw'])) return isset($human['drawCount'])
			? 'Your draw is complete. Waiting for the others.' : 'Waiting for your turn to choose discards.';
		if($phase === 'final-betting' && !empty($legal['check'])) return !empty($legal['bet'])
			? 'Check or bet after the draw.' : 'Check after the draw.';
		if($phase === 'final-betting' && !empty($legal['call'])) return !empty($legal['bet'])
			? 'Final betting: call, raise, or fold.' : 'Final betting: call or fold.';
		return (string)($this->view['message'] ?? '');
	}

	/** Minimal presentation state needed to derive action labels and intents without recursively resolving focus. */
	private function controlState(): array { return ['discards' => $this->discards, 'amount' => $this->amount]; }

	/** Current controller target with a live geometry rectangle, or null for keyboard/pointer input. */
	private function focusedTarget(): ?array
	{
		if($this->inputTheme === 'keyboard') return null;
		$targets = $this->controllerTargets();
		$this->ensureControllerFocus($targets);
		foreach($targets as $target) if($target['id'] === $this->controllerFocus)
		return !empty($target['hidden']) ? ['id' => $target['id']] : ['id' => $target['id'], 'rect' => $target['rect']];
		return null;
	}

	/**
	 * Presentation state that layout and painting depend on.
	 *
	 * @return array<string,mixed> Selection, controls, chip inventories and the current presentation frame.
	 */
	public function state(): array
	{
		$helpMaximum = ($this->view['screen'] ?? '') === 'help' ? $this->helpMaximumScroll() : 0;
		$this->helpScroll = min($this->helpScroll, $helpMaximum);
		$gameSettings=$this->infoDialog==='game-settings'?$this->gameSettings():null;
		return [
			'discards' => $this->discards, 'amount' => $this->amount, 'mode' => $this->mode, 'local' => $this->local
			, 'history' => $this->historyOpen, 'topMenu'=>$this->topMenu, 'infoDialog'=>$this->infoDialog
			, 'confirmDialog'=>$this->confirmDialog
			, 'oddsTables'=>in_array($this->infoDialog,['odds','improving'],true)?$this->oddsTables():null
			, 'gameSettings'=>$gameSettings
			, 'gameSettingsTitle'=>'HOUSE RULES'
			, 'gameSettingsWildLines'=>$gameSettings===null?1:$this->gameSettingsWildLines($gameSettings)
			, 'locked' => $this->locked(), 'hint' => $this->guidance()
			, 'setup' => ['players' => $this->setupPlayers, 'variant' => $this->setupVariant,
				'ante'=>$this->setupAnte,'minimum'=>$this->setupMinimum,'maximum'=>$this->setupMaximum,
				'finalMinimum'=>$this->setupFinalMinimum,'finalMaximum'=>$this->setupFinalMaximum,
				'raiseLimit'=>$this->setupRaiseLimit,'playerRaiseLimit'=>$this->setupPlayerRaiseLimit,
				'limitDraft'=>$this->limitDraft,
				'wild'=>$this->setupWild,'customWild'=>$this->setupCustomWild,'wildDraft'=>$this->wildDraft,
				'customWildDraft'=>$this->customWildDraft,'customWildPage'=>$this->customWildPage], 'pointer' => $this->pointer
			, 'chips' => $this->chips, 'motion' => $this->motion?->frame($this->layout())
			, 'winnerFlash' => ['active' => $this->winnerElapsed < 4, 'visible' => $this->winnerVisible]
			, 'networkWait' => ['active' => $this->networkWaitKey !== '', 'dots' => $this->networkWaitDots]
			, 'helpScroll' => $this->helpScroll, 'helpScrollMax' => $helpMaximum
			, 'inputTheme' => $this->inputTheme, 'controllerFocus' => $this->focusedTarget()
		];
	}

	/**
	 * Authorized presentation status published beside the view for tests and assistive tooling.
	 *
	 * @return array{ui:array<string,mixed>}
	 */
	public function status(): array
	{
		$state = $this->state();
		unset($state['pointer'], $state['motion']);
		$state['animation'] = $this->motion?->status();
		$state['cardArt'] = $this->cardAtlas !== null;
		$layout = $this->layout();
		$state['profile'] = $layout->profile;
		// Normalized canvas rectangles let browser hover feedback use the same targets without copying layout rules.
		$state['hitRegions'] = [];
		foreach($this->clickTargets() as $target)
		{
			if(!empty($target['disabled'])) continue;
			[$x, $y, $w, $h] = $layout->rect(...$target['rect']);
			$state['hitRegions'][] = [$x / $this->width, $y / $this->height, $w / $this->width, $h / $this->height];
		}
		return ['ui' => $state];
	}

	/** Whether the next frame must be drawn. */
	public function dirty(): bool
	{
		if($this->pollCardAtlas()) $this->dirty = true;
		return $this->dirty;
	}

	/**
	 * Draw one frame.
	 *
	 * @param int $width Canvas width in device pixels.
	 * @param int $height Canvas height in device pixels.
	 * @param float $dpr Device-pixel ratio of the canvas.
	 */
	public function render(int $width, int $height, float $dpr): void
	{
		if($this->closed) return;
		$this->resize($width, $height, $dpr);
		$this->dirty = false;
		$this->renderer ??= new UiRenderer($this->root, $this->textures);
		$this->pollCardAtlas();
		$this->frame = $this->paint($width, $height);
		$this->renderer->render($this->frame, $width, $height);
	}

	/**
	 * Build the draw list for the current view without a GL context.
	 *
	 * @param int $width Canvas width in device pixels.
	 * @param int $height Canvas height in device pixels.
	 */
	public function paint(int $width, int $height): UiCanvas
	{
		[$this->width, $this->height] = [$width, $height];
		$layout = $this->layout();
		$canvas = new UiCanvas($this->text, $layout->uiScale());
		(new Painter($canvas, $layout, $this->view, $this->state(), $this->cardAtlas))->paint($width, $height);
		return $canvas;
	}

	/** Ask the browser bridge for the latest rasterised card and avatar atlas. */
	private function pollCardAtlas(): bool
	{
		if(!function_exists('vrzno_env')) return false;
		if(!$this->cardArtSent)
		{
			$this->cardArtSent = true;
			$art = $this->textures['cards'];
			$send = vrzno_env('pokerCardArt');
			$icons = [];
			// Emblems are small PNGs: they travel as data URLs, which the browser draws without another conversion.
			foreach($art['icons'] ?? [] as $key => $icon)
			{
				$bytes = (string)file_get_contents($this->root . '/' . $icon['file']);
				$icons[$key] = str_ends_with($icon['file'], '.png') ? 'data:image/png;base64,' . base64_encode($bytes) : $bytes;
			}
			if($send) $send(json_encode([
				'sheet' => (string)file_get_contents($this->root . '/' . $art['sheet']['file']), 'columns' => $art['sheet']['columns'], 'rows' => $art['sheet']['rows']
				, 'backs' => ['red' => (string)file_get_contents($this->root . '/' . $art['backs']['red']['file']), 'blue' => (string)file_get_contents($this->root . '/' . $art['backs']['blue']['file'])]
				, 'icons' => $icons, 'suitRows' => $art['suitRows'], 'aspect' => $art['aspect']
			], JSON_THROW_ON_ERROR));
		}
		$fetch = vrzno_env('pokerCardAtlas');
		if(!$fetch) return false;
		$json = (string)$fetch();
		if($json === '') return false;
		$atlas = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
		$pixels = base64_decode((string)$atlas['base64'], true);
		if($pixels === false) return false;
		$this->cardAtlas = [
			'width' => (int)$atlas['width'], 'height' => (int)$atlas['height'], 'cell' => $atlas['cell'], 'columns' => (int)$atlas['columns']
			, 'backs' => $atlas['backs'], 'icons' => $atlas['icons'] ?? []
		];
		$this->renderer?->setAtlas($pixels, $this->cardAtlas['width'], $this->cardAtlas['height']);
		return true;
	}

	public function restore(): void
	{
		$this->renderer?->restore();
		$this->dirty = true;
	}

	public function close(): void
	{
		$this->closed = true;
		$this->renderer?->close();
	}
}
