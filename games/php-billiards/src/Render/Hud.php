<?php
declare(strict_types=1);
namespace PhpBilliards\Render;

use PhpBilliards\Game;

/**
 * Native SDL/OpenGL controls and status overlay; no DOM controls participate in gameplay.
 *
 * Static layouts drive both pointer hit testing and rendering for the title menu, the broadcast-style gameplay HUD
 * (spin disc, power meter, action dock, scoreboard, toast, and hints), the pause and controls overlays, and result
 * cards. Every size is a design pixel multiplied by a unit derived from the canvas size and device-pixel ratio.
 */
final class Hud
{
	/**
	 * Shared control reference for the hint strip and the controls sheet: label, keyboard legends, pad legends.
	 *
	 * @var list<array{string,list<string>,list<string>}>
	 */
	public const CONTROLS = [
		['Aim',['Drag','←','→'],['LS']]
		, ['Fine aim (hold)',['Shift'],['L3']]
		, ['Power',['↑','↓'],['LS']]
		, ['Pull back and shoot',['Drag power'],[]]
		, ['Top / back spin',['Q','E'],['LT','RT']]
		, ['Side spin',['Z','C'],['LB','RB']]
		, ['Clear spin',['X'],['X']]
		, ['Shoot or place',['Space'],['A']]
		, ['Undo shot',['Bksp'],['B']]
		, ['New rack',['R'],['Y']]
		, ['Orbit camera',['Right-drag'],['RS']]
		, ['Zoom',['Wheel'],[]]
		, ['Camera behind cue',['0'],['R3']]
		, ['Menu',['Esc'],['Start']]
		, ['Mute',['M'],['Back']]
		, ['Fullscreen',['F'],[]]
		, ['Hide HUD',['H'],[]]
		, ['Controls',['?'],[]]
	];

	private static ?Text $sharedText = null;
	private UiRenderer $renderer;
	private bool $closed = false;
	private ?float $lastRender = null;
	private ?string $toastText = null;
	private ?array $toastShown = null;
	private float $toastOpacity = 0.0;
	private ?string $hintKey = null;
	/** Wall-clock time at which the current hint strip starts fading out. */
	private float $hintFadeAt = 0.0;
	private float $hintOpacity = 0.0;
	/** Whether a fade was still in progress when the last frame was built. */
	private bool $fading = false;
	/** Inputs of the last built frame and its draw list, reused while nothing visible changes. */
	private ?string $frameKey = null;
	private ?UiCanvas $frameCanvas = null;
	/** Right edge of the top-left chip or scoreboard drawn this frame, which the toast avoids. */
	private float $leftWidth = 0.0;
	/** @var array<int,float>|null Wall-clock time each pocketed ball was first seen; null before the first frame. */
	private ?array $sunkAt = null;
	/** Wall-clock time at which the last sink-board flash settles. */
	private float $flashUntil = 0.0;

	/**
	 * @param string $root Absolute root directory of the Billiards package.
	 */
	public function __construct(string $root)
	{
		$textures = json_decode(file_get_contents($root . '/assets/generated/textures.json'), true, flags: JSON_THROW_ON_ERROR);
		self::$sharedText ??= Text::load($root, $textures['uiFont']);
		$this->renderer = new UiRenderer($root, $textures['uiFont']);
	}

	/** Font metrics shared by static layouts and rendering. */
	public static function text(): Text
	{
		if(self::$sharedText) return self::$sharedText;
		$root = dirname(__DIR__, 2);
		$textures = json_decode(file_get_contents($root . '/assets/generated/textures.json'), true, flags: JSON_THROW_ON_ERROR);

		return self::$sharedText = Text::load($root, $textures['uiFont']);
	}

	/**
	 * Device pixels per design pixel.
	 *
	 * Layouts are authored for a 1280x720 CSS viewport; smaller and larger windows scale within readable bounds.
	 *
	 * @param int $width Canvas width in device pixels.
	 * @param int $height Canvas height in device pixels.
	 * @param float $dpr Device-pixel ratio of the canvas.
	 */
	public static function unit(int $width, int $height, float $dpr = 1): float
	{
		$dpr = max(.5, min(4, is_finite($dpr) ? $dpr : 1));
		$cssWidth = $width / $dpr;
		$cssHeight = $height / $dpr;

		return $dpr * max(.8, min(1.25, $cssWidth / 1280, $cssHeight / 720));
	}

	private static function canvas(int $width, int $height, float $dpr): UiCanvas
	{
		return new UiCanvas(self::text(), self::unit($width, $height, $dpr));
	}

	/**
	 * Pick the regions that currently accept pointer input.
	 *
	 * @param Game $game Current title, overlay, and gameplay state.
	 * @param int $width Canvas width in pixels.
	 * @param int $height Canvas height in pixels.
	 * @param float $dpr Device-pixel ratio of the canvas.
	 */
	public static function regionsFor(Game $game, int $width, int $height, float $dpr = 1): array
	{
		if(!$game->started) return self::titleRegions($width, $height, $dpr);
		if($game->overlay !== null) return self::overlayRegions($game, $width, $height, $dpr);

		return self::regions($width, $height, $dpr, $game);
	}

	/** Keycap legend for a dock action under the current input theme. */
	private static function legend(string $action, string $theme): string
	{
		$pad = $theme !== 'keyboard';

		return match($action) {
			'shoot' => $pad ? 'A' : 'Space',
			'reset' => $pad ? 'B' : 'Bksp',
			'rack' => $pad ? 'Y' : 'R',
			default => ''
		};
	}

	/**
	 * The way into the controls sheet for the current input theme, as [action, legend, label].
	 *
	 * A controller has no direct Controls button, so its hint names the Start menu that holds the sheet.
	 *
	 * @return array{string,string,string}
	 */
	private static function helpHint(?Game $game): array
	{
		return ($game?->inputTheme ?? 'keyboard') === 'keyboard' ? ['controls','?','Controls'] : ['menu','Start','Menu'];
	}

	/** @return list<array{string,string}> Dock buttons as [action, label]. */
	private static function dockButtons(?Game $game): array
	{
		$shoot = ['shoot',$game && $game->simulation->phase === 'placing' ? 'Place' : 'Shoot'];
		if($game && $game->online()) return [$shoot];

		return [['reset','Undo shot'],['rack','New rack'],$shoot];
	}

	/**
	 * Build stable gameplay regions shared by native rendering and SDL pointer handling.
	 *
	 * @param int $width Canvas width in pixels.
	 * @param int $height Canvas height in pixels.
	 * @param float $dpr Device-pixel ratio of the canvas.
	 * @param Game|null $game Current game, which selects online or free-play controls and input-theme legends.
	 */
	public static function layout(int $width, int $height, float $dpr = 1, ?Game $game = null): array
	{
		$canvas = self::canvas($width, $height, $dpr);
		$u = $canvas->unit;
		$margin = Theme::MARGIN * $u;
		$regions = [];
		$metrics = ['unit' => $u,'margin' => $margin,'narrow' => $width / $u < 760];

		$regions['menu'] = ['type' => 'action','name' => 'menu','rect' => [$width - $margin - 40 * $u,$margin,40 * $u,40 * $u]];

		// The help hint never fades: it stays beside the menu button through play, ball motion, narrow layouts and a
		// hidden HUD. Narrow canvases show only its keycap.
		[$helpName,$helpLegend,$helpLabel] = self::helpHint($game);
		$compact = $metrics['narrow'];
		$helpWidth = 24 * $u + $canvas->keycapWidth($helpLegend, Theme::CAPTION, ($game?->inputTheme ?? 'keyboard') !== 'keyboard')
			+ ($compact ? 0 : 8 * $u + $canvas->measure($helpLabel, Theme::CAPTION, 'bold'));
		$helpX = $width - $margin - 48 * $u - $helpWidth;
		$regions['help'] = [
			'type' => 'action','name' => $helpName,'legend' => $helpLegend,'label' => $compact ? '' : $helpLabel
			, 'rect' => [$helpX,$margin + 4 * $u,$helpWidth,32 * $u]
		];
		$metrics['topRight'] = $helpX;

		if($game?->muted)
		{
			$chip = $canvas->measure('Muted', Theme::CAPTION, 'bold') + 20 * $u;
			$metrics['muted'] = [$helpX - $chip - 8 * $u,$margin + 8 * $u,$chip,24 * $u];
			$metrics['topRight'] = $metrics['muted'][0];
		}

		if($game?->hudHidden)
		{
			$regions['_metrics'] = $metrics + ['hidden' => true];
			return $regions;
		}

		// Shot card: spin disc and power meter, bottom left.
		$cardWidth = 178 * $u;
		$cardHeight = 164 * $u;
		$cardX = $margin;
		$cardY = $height - $margin - $cardHeight;
		$spinCenter = [$cardX + 58 * $u,$cardY + 70 * $u];
		$regions['spin'] = [
			'type' => 'spin','name' => 'spin','rect' => [$spinCenter[0] - 44 * $u,$spinCenter[1] - 44 * $u,88 * $u,88 * $u]
			, 'center' => $spinCenter,'radius' => 30 * $u
		];
		$meterX = $cardX + 116 * $u;
		$bar = [$meterX + 17 * $u,$cardY + 14 * $u,14 * $u,112 * $u];
		$regions['power'] = [
			'type' => 'power','name' => 'power','rect' => [$meterX,$cardY + 6 * $u,48 * $u,128 * $u]
			, 'bar' => $bar,'travel' => 170 * $u,'reserve' => 6 * $u
		];
		$metrics['card'] = [$cardX,$cardY,$cardWidth,$cardHeight];

		// Action dock: a row on wide canvases, a right-aligned column on narrow ones.
		$theme = $game?->inputTheme ?? 'keyboard';
		$buttons = [];
		foreach(self::dockButtons($game) as [$name,$label])
		{
			$labelWidth = $canvas->measure($label, Theme::LABEL, 'bold');
			$buttons[$name] = [$label,14 * $u + $canvas->keycapWidth(self::legend($name, $theme), Theme::CAPTION, $theme !== 'keyboard')
				+ 9 * $u + $labelWidth + 18 * $u];
		}
		$buttonHeight = 44 * $u;
		$gap = Theme::GAP * $u;
		if($metrics['narrow'])
		{
			$columnWidth = max(array_column($buttons, 1));
			$y = $height - $margin - $buttonHeight;
			foreach(array_reverse($buttons, true) as $name => [$label,$buttonWidth])
			{
				$regions[$name] = ['type' => 'action','name' => $name,'label' => $label
					, 'rect' => [$width - $margin - $columnWidth,$y,$columnWidth,$buttonHeight]];
				$y -= $buttonHeight + $gap;
			}
			$metrics['dockLeft'] = $width - $margin - $columnWidth;
		}
		else
		{
			$x = $width - $margin;
			foreach(array_reverse($buttons, true) as $name => [$label,$buttonWidth])
			{
				$x -= $buttonWidth;
				$regions[$name] = ['type' => 'action','name' => $name,'label' => $label
					, 'rect' => [$x,$height - $margin - $buttonHeight,$buttonWidth,$buttonHeight]];
				$x -= $gap;
			}
			$metrics['dockLeft'] = $x + $gap;
		}

		if($game && ($card = $game->endCard()))
		{
			$layout = self::endCardLayout($canvas, $card, $width, $height);
			foreach($layout['buttons'] as $name => $rect) $regions['end:' . $name] = ['type' => 'action','name' => $name,'rect' => $rect];
			$regions['end-card'] = ['type' => 'panel','name' => 'end-card','rect' => $layout['panel']];
			$metrics['endCard'] = $layout;
		}

		// Panels last so their child controls win hit testing.
		$regions['shot-card'] = ['type' => 'panel','name' => 'shot-card','rect' => $metrics['card']];
		if($game?->online())
		{
			$metrics['scoreboard'] = [$margin,$margin,min(300 * $u,$metrics['topRight'] - $margin * 2),112 * $u];
			$regions['scoreboard'] = ['type' => 'panel','name' => 'scoreboard','rect' => $metrics['scoreboard']];
		}
		$regions['_metrics'] = $metrics;

		return $regions;
	}

	/**
	 * @param int $width Canvas width in pixels.
	 * @param int $height Canvas height in pixels.
	 * @param float $dpr Device-pixel ratio of the canvas.
	 * @param Game|null $game Current game, which selects online or free-play controls.
	 */
	public static function regions(int $width, int $height, float $dpr = 1, ?Game $game = null): array
	{
		$regions = self::layout($width, $height, $dpr, $game);
		unset($regions['_metrics']);
		return $regions;
	}

	/**
	 * Build the Free Play and Online Play title items and their wordmark metrics.
	 *
	 * @param int $width Canvas width in pixels.
	 * @param int $height Canvas height in pixels.
	 * @param float $dpr Device-pixel ratio of the canvas.
	 */
	public static function titleLayout(int $width, int $height, float $dpr = 1): array
	{
		$u = self::unit($width, $height, $dpr);
		$margin = Theme::MARGIN * $u;
		// The title camera frames the far rail and the rack about a third of the way down, so the wordmark and the
		// row of buttons stay above that line, shrinking together on short screens rather than covering the balls.
		$limit = $height * .32;
		$top = max($margin, $height * .05);
		$titleSize = max(Theme::DISPLAY, min(64, $height * .11 / $u));
		$natural = ($titleSize * 1.1 + 44) * $u + 56 * $u;
		$scale = max(.6, min(1, ($limit - $top) / $natural));
		$titleSize *= $scale;
		$wordmark = $top + $titleSize * $u * .5;
		$rule = $wordmark + $titleSize * $u * .6;
		$caption = $rule + 20 * $u * $scale;
		$buttonTop = $caption + 24 * $u * $scale;
		$buttonHeight = max(44, 56 * $scale) * $u;
		$gap = 12 * $u;
		$buttonWidth = min(260 * $u, ($width - 2 * $margin - $gap) / 2);
		$x = ($width - 2 * $buttonWidth - $gap) / 2;

		return [
			'free-play' => ['type' => 'action','name' => 'free-play','rect' => [$x,$buttonTop,$buttonWidth,$buttonHeight]]
			, 'online-play' => ['type' => 'action','name' => 'online-play'
				, 'rect' => [$x + $buttonWidth + $gap,$buttonTop,$buttonWidth,$buttonHeight]]
			, '_metrics' => ['unit' => $u,'wordmark' => $wordmark,'titleSize' => $titleSize,'rule' => $rule
				, 'caption' => $caption,'limit' => $limit]
		];
	}

	/**
	 * @param int $width Canvas width in pixels.
	 * @param int $height Canvas height in pixels.
	 * @param float $dpr Device-pixel ratio of the canvas.
	 */
	public static function titleRegions(int $width, int $height, float $dpr = 1): array
	{
		$regions = self::titleLayout($width, $height, $dpr);
		unset($regions['_metrics']);

		return $regions;
	}

	/**
	 * Pause menu or controls-sheet regions, with a full-screen panel that swallows stray clicks.
	 *
	 * @param Game $game Game whose overlay is open.
	 * @param int $width Canvas width in pixels.
	 * @param int $height Canvas height in pixels.
	 * @param float $dpr Device-pixel ratio of the canvas.
	 */
	public static function overlayRegions(Game $game, int $width, int $height, float $dpr = 1): array
	{
		$layout = self::overlayLayout($game, self::canvas($width, $height, $dpr), $width, $height);
		$regions = [];
		foreach($layout['items'] as $name => $rect) $regions[$name] = ['type' => 'action','name' => $name,'rect' => $rect];
		$regions['overlay'] = ['type' => 'panel','name' => 'overlay','rect' => [0,0,$width,$height]];

		return $regions;
	}

	private static function overlayLayout(Game $game, UiCanvas $canvas, int $width, int $height): array
	{
		$u = $canvas->unit;
		$items = $game->menuItems();

		if($game->overlay === 'controls')
		{
			$rows = count(self::CONTROLS);
			$cardWidth = min($width - 32 * $u, 600 * $u);
			$rowHeight = max(17 * $u, min(27 * $u, ($height - 32 * $u - 150 * $u) / $rows));
			$cardHeight = 76 * $u + $rows * $rowHeight + 76 * $u;
			$x = ($width - $cardWidth) / 2;
			$y = max(16 * $u, ($height - $cardHeight) / 2);

			return [
				'panel' => [$x,$y,$cardWidth,$cardHeight],'rowHeight' => $rowHeight
				, 'items' => ['back' => [$x + $cardWidth / 2 - 70 * $u,$y + $cardHeight - 60 * $u,140 * $u,44 * $u]]
			];
		}

		$cardWidth = min($width - 32 * $u, 320 * $u);
		$itemHeight = 46 * $u;
		$cardHeight = 74 * $u + count($items) * ($itemHeight + 6 * $u) + 44 * $u;
		$x = ($width - $cardWidth) / 2;
		$y = max(16 * $u, ($height - $cardHeight) / 2);
		$rects = [];
		foreach($items as $index => [$name])
		$rects[$name] = [$x + 16 * $u,$y + 66 * $u + $index * ($itemHeight + 6 * $u),$cardWidth - 32 * $u,$itemHeight];

		return ['panel' => [$x,$y,$cardWidth,$cardHeight],'items' => $rects];
	}

	private static function endCardLayout(UiCanvas $canvas, array $card, int $width, int $height): array
	{
		$u = $canvas->unit;
		$cardWidth = min($width - 32 * $u, 380 * $u);
		$cardHeight = 168 * $u;
		$x = ($width - $cardWidth) / 2;
		$y = ($height - $cardHeight) / 2 - 30 * $u;
		$buttons = [];
		$count = count($card['actions']);
		$buttonWidth = 168 * $u;
		$left = $x + ($cardWidth - $count * $buttonWidth - ($count - 1) * 8 * $u) / 2;
		foreach($card['actions'] as $index => [$name])
		$buttons[$name] = [$left + $index * ($buttonWidth + 8 * $u),$y + $cardHeight - 64 * $u,$buttonWidth,44 * $u];

		return ['panel' => [$x,$y,$cardWidth,$cardHeight],'buttons' => $buttons];
	}

	/**
	 * Whether another frame is needed for a fade: one in progress, or a hint strip whose hold time has elapsed.
	 *
	 * The hold itself needs no frames, so an idle table stops rendering until the fade is due.
	 */
	public function animating(): bool
	{
		return $this->fading || ($this->hintOpacity > .01 && microtime(true) >= $this->hintFadeAt)
			|| microtime(true) < $this->flashUntil;
	}

	/**
	 * Draw the HUD for the current state over the rendered scene.
	 *
	 * @param Game $game Current game and simulation state.
	 * @param int $width Canvas width in pixels.
	 * @param int $height Canvas height in pixels.
	 * @param float $dpr Device-pixel ratio of the canvas.
	 */
	public function render(Game $game, int $width, int $height, float $dpr = 1): void
	{
		if($this->closed) return;
		$this->renderer->render($this->frame($game, $width, $height, $dpr), $width, $height);
	}

	/**
	 * Build this frame's HUD draw list without touching GL, advancing fades by the time since the last frame.
	 *
	 * @param Game $game Current game and simulation state.
	 * @param int $width Canvas width in pixels.
	 * @param int $height Canvas height in pixels.
	 * @param float $dpr Device-pixel ratio of the canvas.
	 */
	public function frame(Game $game, int $width, int $height, float $dpr = 1): UiCanvas
	{
		$now = microtime(true);
		$elapsed = $this->lastRender === null ? 0 : max(0, min(.1, $now - $this->lastRender));
		$this->lastRender = $now;
		$flashes = $this->sinkFlashes($game, $now);

		// Moving balls redraw the scene every frame while the HUD usually stays put; rebuild it only on change.
		$key = serialize([
			$width,$height,$dpr,$game->started,$game->overlay,$game->hover,$game->pressed,$game->menuSelection
			, $game->hudHidden,$game->inputTheme,$game->titleSelection,$game->muted,$game->power,$game->top,$game->side
			, $game->cueDraw(),$game->simulation->phase,$game->simulation->shots,$game->stroking(),$game->controllable()
			, $game->simulation->moving(),$game->onlineScoreboard(),$game->toast(),$game->endCard(),$game->pocketedBalls(),$flashes
		]);
		if($this->frameCanvas && $key === $this->frameKey && !$this->animating()) return $this->frameCanvas;
		$this->frameKey = $key;
		$this->fading = false;
		$canvas = self::canvas($width, $height, $dpr);

		if(!$game->started) $this->drawTitle($canvas, $game, $width, $height, $dpr);
		else
		{
			$layout = self::layout($width, $height, $dpr, $game);
			$metrics = $layout['_metrics'];
			if(empty($metrics['hidden']))
			{
				$this->drawShotCard($canvas, $game, $layout);
				$this->drawDock($canvas, $game, $layout);
				if($game->online()) $leftBottom = $this->drawScoreboard($canvas, $game, $metrics);
				else
				{
					$this->drawFreeChip($canvas, $game, $metrics);
					$leftBottom = $this->drawSinkBoard($canvas, $game, $width, $height, $dpr, $flashes);
				}
				$this->drawToast($canvas, $game, $width, $metrics, $leftBottom, $elapsed);
				$this->drawHints($canvas, $game, $width, $height, $metrics, $elapsed);
				if(isset($metrics['endCard'])) $this->drawEndCard($canvas, $game, $metrics['endCard'], $width, $height);
			}
			$this->drawHelp($canvas, $game, $layout['help'], !empty($metrics['hidden']));
			$this->drawMenuButton($canvas, $game, $layout['menu']['rect'], !empty($metrics['hidden']), $metrics['muted'] ?? null);
			if($game->overlay !== null) $this->drawOverlay($canvas, $game, $width, $height);
		}

		return $this->frameCanvas = $canvas;
	}

	private function panel(UiCanvas $canvas, float $x, float $y, float $width, float $height, float $radius = Theme::RADIUS_PANEL
		, bool $modal = false
	): void {
		$u = $canvas->unit;
		$canvas->shadow($x, $y + 5 * $u, $width, $height, $radius * $u, 16 * $u);
		$canvas->box($x, $y, $width, $height, $radius * $u, $modal ? Theme::alpha(Theme::PANEL, 1.13) : Theme::PANEL, Theme::HAIRLINE, max(1, $u));
	}

	/**
	 * Draw a labelled button with an optional keycap legend.
	 *
	 * @param string $style primary or secondary.
	 */
	private function button(UiCanvas $canvas, array $rect, string $label, string $legend, string $style
		, bool $enabled, bool $hover, bool $pressed, bool $focused = false, ?string $sublabel = null, bool $pad = false
	): void {
		$u = $canvas->unit;
		[$x,$y,$width,$height] = $rect;
		$radius = Theme::RADIUS_BUTTON * $u;
		$primary = $style === 'primary';
		$opacity = $canvas->opacity;

		if($enabled && !$pressed) $canvas->shadow($x, $y + 3 * $u, $width, $height, $radius, 8 * $u, [0,0,0,.32]);
		if(!$enabled) $canvas->box($x, $y, $width, $height, $radius, Theme::alpha(Theme::PANEL_RAISED, .7), Theme::HAIRLINE, max(1, $u));
		elseif($primary)
		{
			$top = $pressed ? Theme::BRASS : ($hover ? [1,.85,.52,1] : Theme::BRASS_BRIGHT);
			$bottom = $pressed ? Theme::BRASS_DEEP : Theme::BRASS;
			$canvas->gradient($x, $y, $width, $height, $radius, $top, $bottom);
			$canvas->box($x, $y, $width, $height, $radius, [0,0,0,0], [1,.95,.8,.35], max(1, $u));
		}
		else
		{
			$fill = $pressed ? Theme::PANEL : ($hover ? Theme::mix(Theme::PANEL_RAISED, [.2,.24,.21,.95], .7) : Theme::PANEL_RAISED);
			$canvas->box($x, $y, $width, $height, $radius, $fill, $hover ? Theme::alpha(Theme::BRASS, .55) : Theme::HAIRLINE, max(1, $u));
		}
		if($focused) $canvas->box($x - 3 * $u, $y - 3 * $u, $width + 6 * $u, $height + 6 * $u, $radius + 3 * $u, [0,0,0,0]
			, Theme::alpha(Theme::BRASS_BRIGHT, .9), 2 * $u);

		$textColor = !$enabled ? Theme::TEXT_SECONDARY : ($primary ? Theme::TEXT_ON_BRASS : Theme::TEXT);
		if(!$enabled) $canvas->opacity = $opacity * .75;
		$middle = $y + ($sublabel === null ? $height / 2 : $height * .38);

		if($legend !== '')
		{
			$capWidth = $canvas->keycapWidth($legend, Theme::CAPTION, $pad);
			$left = $x + 14 * $u;
			$canvas->keycap($legend, $left, $middle, Theme::CAPTION, $pad);
			$canvas->label($label, $left + $capWidth + 9 * $u, $middle, Theme::LABEL, $textColor, 'bold', 'left'
				, $width - $capWidth - 37 * $u);
		}
		else $canvas->label($label, $x + $width / 2, $middle, Theme::LABEL + 1, $textColor, 'bold', 'center', $width - 24 * $u);

		if($sublabel !== null) $canvas->label($sublabel, $x + $width / 2, $y + $height * .7, Theme::CAPTION
			, $primary && $enabled ? Theme::alpha(Theme::TEXT_ON_BRASS, .78) : Theme::TEXT_SECONDARY, 'body', 'center', $width - 24 * $u);
		$canvas->opacity = $opacity;
	}

	private function drawTitle(UiCanvas $canvas, Game $game, int $width, int $height, float $dpr): void
	{
		$u = $canvas->unit;
		$layout = self::titleLayout($width, $height, $dpr);
		$metrics = $layout['_metrics'];
		$canvas->gradient(0, 0, $width, $metrics['limit'] * 1.4, 0, [.004,.008,.006,.72], [.004,.008,.006,0]);
		$canvas->gradient(0, $height * .62, $width, $height * .38, 0, [.004,.008,.006,0], [.004,.008,.006,.55]);
		$canvas->label('PHP Billiards', $width / 2, $metrics['wordmark'], $metrics['titleSize'], Theme::TEXT, 'bold', 'center'
			, $width - 32 * $u, -.005, true);
		$canvas->box($width / 2 - 24 * $u, $metrics['rule'], 48 * $u, 3 * $u, 1.5 * $u, Theme::BRASS);
		$canvas->label('Eight-ball, simulated in PHP', $width / 2, $metrics['caption'], Theme::BODY, Theme::TEXT_SECONDARY
			, 'body', 'center', $width - 32 * $u, .01, true);

		foreach(['free-play' => ['Free play','Practice table'],'online-play' => ['Online play','Two players over Matrix']]
			as $name => [$label,$caption])
		{
			$selected = $game->titleSelection === ($name === 'free-play' ? 0 : 1);
			$this->button($canvas, $layout[$name]['rect'], $label, '', $selected ? 'primary' : 'secondary', true
				, $game->hover === $name && !$selected, $game->pressed === $name, false, $caption);
		}

		$pad = $game->inputTheme !== 'keyboard';
		$this->hintRow($canvas, [[['←','→'],'Select'],[[$pad ? 'A' : 'Enter'],'Play']], $width / 2, $height - 34 * $u, 'center'
			, null, $pad, true);
	}

	private function drawShotCard(UiCanvas $canvas, Game $game, array $layout): void
	{
		$u = $canvas->unit;
		[$x,$y,$width,$height] = $layout['_metrics']['card'];
		$this->panel($canvas, $x, $y, $width, $height);
		$enabled = $game->controllable() && $game->simulation->phase === 'aim' && !$game->stroking() && !$game->simulation->moving();
		$opacity = $canvas->opacity;
		if(!$enabled) $canvas->opacity *= .55;

		// Spin disc: a cue-ball face with the tip contact point.
		$spin = $layout['spin'];
		[$centerX,$centerY] = $spin['center'];
		$radius = $spin['radius'];
		$active = $game->hover === 'spin' || $game->pressed === 'spin';
		$canvas->circle($centerX, $centerY, 44 * $u, [0,0,0,.28], $active ? Theme::alpha(Theme::BRASS, .7) : Theme::HAIRLINE, max(1, $u) * 1.2);
		$canvas->circle($centerX, $centerY + 1.5 * $u, 38 * $u, [0,0,0,.35]);
		$canvas->circle($centerX, $centerY, 38 * $u, [.9,.88,.82,1]);
		$canvas->shadow($centerX - 22 * $u, $centerY - 27 * $u, 20 * $u, 18 * $u, 10 * $u, 9 * $u, [1,1,1,.55]);
		$canvas->box($centerX - 30 * $u, $centerY - .5 * $u, 60 * $u, max(1, $u), 0, [.1,.12,.11,.16]);
		$canvas->box($centerX - .5 * $u, $centerY - 30 * $u, max(1, $u), 60 * $u, 0, [.1,.12,.11,.16]);
		$canvas->circle($centerX, $centerY, $radius, [0,0,0,0], [.1,.12,.11,.14], max(1, $u));
		$dotX = $centerX + $game->side * $radius;
		$dotY = $centerY - $game->top * $radius;
		$canvas->circle($dotX, $dotY + 1 * $u, 7.5 * $u, [0,0,0,.3]);
		$canvas->circle($dotX, $dotY, 7 * $u, Theme::DANGER, [1,1,1,.85], 1.5 * $u);
		$spinLabel = abs($game->top) < .005 && abs($game->side) < .005 ? 'Spin' : self::spinCaption($game->top, $game->side);
		$canvas->label($spinLabel, $centerX, $y + 146 * $u, Theme::CAPTION, Theme::TEXT_SECONDARY, 'body', 'center', 104 * $u);

		// Power meter: graded fill from the bottom with a tick at the current setting.
		[$barX,$barY,$barWidth,$barHeight] = $layout['power']['bar'];
		$active = $game->hover === 'power' || $game->pressed === 'power';
		$canvas->box($barX, $barY, $barWidth, $barHeight, $barWidth / 2, [0,0,0,.42], $active ? Theme::alpha(Theme::BRASS, .7) : Theme::HAIRLINE, max(1, $u));
		for($tick = 1; $tick < 4; $tick++)
		$canvas->box($barX - 6 * $u, $barY + $barHeight * (1 - $tick / 4) - .5 * $u, 4 * $u, max(1, $u), 0, Theme::alpha(Theme::TEXT_SECONDARY, .45));
		$fill = max($barWidth, $barHeight * $game->power);
		$canvas->gradient($barX + 2 * $u, $barY + $barHeight - $fill + 2 * $u, $barWidth - 4 * $u, $fill - 4 * $u, ($barWidth - 4 * $u) / 2
			, Theme::mix(Theme::BRASS_BRIGHT, Theme::DANGER, max(0, ($game->power - .6) / .4)), [.94,.92,.85,.95]);
		$tickY = $barY + $barHeight - $barHeight * $game->power;
		$canvas->box($barX - 5 * $u, $tickY - 1.5 * $u, $barWidth + 10 * $u, 3 * $u, 1.5 * $u, Theme::TEXT);
		$caption = $game->cueDraw() > 0 ? 'Release' : 'Power ' . (int)round($game->power * 100);
		$canvas->label($caption, $barX + $barWidth / 2, $y + 146 * $u, Theme::CAPTION, Theme::TEXT_SECONDARY, 'body', 'center', 70 * $u);
		$canvas->opacity = $opacity;
	}

	private static function spinCaption(float $top, float $side): string
	{
		$parts = [];
		if(abs($top) >= .005) $parts[] = ($top > 0 ? 'Top ' : 'Draw ') . (int)round(abs($top) * 100);
		if(abs($side) >= .005) $parts[] = ($side > 0 ? 'Right ' : 'Left ') . (int)round(abs($side) * 100);

		return implode(' · ', $parts);
	}

	private function drawDock(UiCanvas $canvas, Game $game, array $layout): void
	{
		foreach(self::dockButtons($game) as [$name,$label])
		{
			$controllable = $game->controllable();
			$enabled = match($name) {
				'shoot' => $controllable && $game->simulation->phase !== 'moving' && !$game->stroking(),
				'reset' => $controllable && $game->simulation->shots > 0 && !$game->stroking(),
				default => $controllable && !$game->stroking()
			};
			$this->button($canvas, $layout[$name]['rect'], $label, self::legend($name, $game->inputTheme)
				, $name === 'shoot' ? 'primary' : 'secondary', $enabled, $enabled && $game->hover === $name, $game->pressed === $name
				, false, null, $game->inputTheme !== 'keyboard');
		}
	}

	/** Draw the always-visible way into the controls sheet, dimmed like the menu button while the HUD is hidden. */
	private function drawHelp(UiCanvas $canvas, Game $game, array $region, bool $hidden): void
	{
		$u = $canvas->unit;
		[$x,$y,$width,$height] = $region['rect'];
		$hover = $game->hover === $region['name'];
		$opacity = $canvas->opacity;
		if($hidden && !$hover) $canvas->opacity *= .45;
		$canvas->box($x, $y, $width, $height, $height / 2, $hover ? Theme::PANEL_RAISED : Theme::alpha(Theme::PANEL, .78)
			, $hover ? Theme::alpha(Theme::BRASS, .6) : Theme::HAIRLINE, max(1, $u));
		$middle = $y + $height / 2;
		$cursor = $x + 12 * $u + $canvas->keycap($region['legend'], $x + 12 * $u, $middle, Theme::CAPTION, $game->inputTheme !== 'keyboard');
		if($region['label'] !== '')
		$canvas->label($region['label'], $cursor + 8 * $u, $middle, Theme::CAPTION, Theme::alpha(Theme::TEXT, .82), 'bold', 'left');
		$canvas->opacity = $opacity;
	}

	/** @param array{float,float,float,float}|null $muted Muted chip rectangle from the layout, when sound is off. */
	private function drawMenuButton(UiCanvas $canvas, Game $game, array $rect, bool $hidden, ?array $muted = null): void
	{
		$u = $canvas->unit;
		[$x,$y,$width,$height] = $rect;
		$opacity = $canvas->opacity;
		if($hidden && $game->hover !== 'menu') $canvas->opacity *= .45;
		$hover = $game->hover === 'menu';
		$canvas->shadow($x, $y + 3 * $u, $width, $height, $width / 2, 8 * $u, [0,0,0,.3]);
		$canvas->circle($x + $width / 2, $y + $height / 2, $width / 2, $hover ? Theme::PANEL_RAISED : Theme::PANEL
			, $hover ? Theme::alpha(Theme::BRASS, .6) : Theme::HAIRLINE, max(1, $u));
		for($bar = -1; $bar <= 1; $bar++)
		$canvas->box($x + 13 * $u, $y + $height / 2 + $bar * 6 * $u - 1 * $u, 14 * $u, 2 * $u, 1 * $u, Theme::TEXT);
		if($muted)
		{
			[$chipX,$chipY,$chip,$chipHeight] = $muted;
			$canvas->box($chipX, $chipY, $chip, $chipHeight, 12 * $u, Theme::PANEL, Theme::HAIRLINE, max(1, $u));
			$canvas->label('Muted', $chipX + $chip / 2, $chipY + $chipHeight / 2, Theme::CAPTION, Theme::TEXT_SECONDARY, 'bold', 'center');
		}
		$canvas->opacity = $opacity;
	}

	/** @return float Bottom edge of the drawn chip, for toast placement. */
	private function drawFreeChip(UiCanvas $canvas, Game $game, array $metrics): float
	{
		$u = $canvas->unit;
		$margin = $metrics['margin'];
		$shots = $game->simulation->shots;
		$detail = $shots === 1 ? '1 shot' : "$shots shots";
		$labelWidth = $canvas->measure('Free play', Theme::BODY, 'bold');
		$width = 18 * $u + $labelWidth + 10 * $u + $canvas->measure($detail, Theme::BODY) + 18 * $u;
		$this->panel($canvas, $margin, $margin, $width, 36 * $u, 18);
		$canvas->label('Free play', $margin + 18 * $u, $margin + 18 * $u, Theme::BODY, Theme::TEXT, 'bold');
		$canvas->label($detail, $margin + 28 * $u + $labelWidth, $margin + 18 * $u, Theme::BODY, Theme::TEXT_SECONDARY);
		$this->leftWidth = $margin + $width;

		return $margin + 36 * $u;
	}

	/**
	 * Free-play sink board geometry: one slot per numbered ball under the Free play chip, solids, the 8, then stripes.
	 *
	 * @return array{rect:array{float,float,float,float},slots:array<int,array{float,float}>,radius:float}
	 */
	public static function sinkBoardLayout(int $width, int $height, float $dpr = 1): array
	{
		$u = self::unit($width, $height, $dpr);
		$margin = Theme::MARGIN * $u;
		[$top,$pad,$step,$gap] = [$margin + 44 * $u,14 * $u,17 * $u,5 * $u];
		$slots = [];
		$x = $margin + $pad + 6.5 * $u;
		foreach(range(1, 15) as $number)
		{
			$slots[$number] = [$x,$top + 15 * $u];
			$x += $step + ($number === 7 || $number === 8 ? $gap : 0);
		}

		return [
			'rect' => [$margin,$top,$x - $step + 6.5 * $u + $pad - $margin,30 * $u]
			, 'slots' => $slots,'radius' => 6.5 * $u
		];
	}

	/**
	 * Track when each ball dropped so a newly sunk ball flashes on the sink board: lit and empty in alternating half
	 * seconds for two seconds, then settled. Balls already down when the board first appears do not flash, and a ball
	 * returned by a rack or undo flashes again when it next drops.
	 *
	 * @return array<int,bool> For each ball still flashing, whether it shows this frame.
	 */
	private function sinkFlashes(Game $game, float $now): array
	{
		$first = $this->sunkAt === null;
		$sunk = [];
		foreach($game->online() ? [] : $game->pocketedBalls() as $number)
		$sunk[$number] = $first ? -INF : ($this->sunkAt[$number] ?? $now);
		$this->sunkAt = $sunk;
		$this->flashUntil = 0.0;
		$flashes = [];
		foreach($sunk as $number => $time) if($now - $time < 2.0)
		{
			$flashes[$number] = fmod($now - $time, 1.0) < .5;
			$this->flashUntil = max($this->flashUntil, $time + 2.0);
		}

		return $flashes;
	}

	/**
	 * Show which balls are off the table: pocketed balls in their own colours, balls still in play as faint outlines.
	 *
	 * @return float Bottom edge of the board, for toast placement.
	 */
	private function drawSinkBoard(UiCanvas $canvas, Game $game, int $width, int $height, float $dpr, array $flashes): float
	{
		$board = self::sinkBoardLayout($width, $height, $dpr);
		[$x,$y,$boardWidth,$boardHeight] = $board['rect'];
		$u = $canvas->unit;
		$radius = $board['radius'];
		$this->panel($canvas, $x, $y, $boardWidth, $boardHeight, 15);
		$down = array_flip($game->pocketedBalls());
		foreach($board['slots'] as $number => [$ballX,$ballY])
		{
			$color = Scene::BALL_COLORS[$number];
			if(isset($flashes[$number]) && $flashes[$number])
			$canvas->circle($ballX, $ballY, $radius + 2.5 * $u, [0,0,0,0], Theme::alpha(Theme::BRASS_BRIGHT, .9), 1.5 * $u);
			if(!isset($down[$number]) || (isset($flashes[$number]) && !$flashes[$number]))
			{
				$canvas->circle($ballX, $ballY, $radius, [0,0,0,0], Theme::alpha(Theme::TEXT_SECONDARY, .3), max(1, $u));
				continue;
			}
			// The 8 gets a faint rim so the black ball does not vanish into the dark glass.
			if($number <= 8) $canvas->circle($ballX, $ballY, $radius, $color
				, $number === 8 ? Theme::alpha(Theme::TEXT_SECONDARY, .45) : null, $number === 8 ? max(1, $u) : 0);
			else
			{
				$canvas->circle($ballX, $ballY, $radius, [.93,.91,.85,1]);
				$canvas->box($ballX - $radius, $ballY - $radius / 2, $radius * 2, $radius, 2 * $u, $color);
			}
		}
		$this->leftWidth = max($this->leftWidth, $x + $boardWidth);

		return $y + $boardHeight;
	}

	/** @return float Bottom edge of the scoreboard card. */
	private function drawScoreboard(UiCanvas $canvas, Game $game, array $metrics): float
	{
		$score = $game->onlineScoreboard();
		[$x,$y,$width,$height] = $metrics['scoreboard'];
		$this->leftWidth = $x + $width;
		if($score === null) return $y;
		$u = $canvas->unit;
		$this->panel($canvas, $x, $y, $width, $height);

		foreach(array_slice($score['players'], 0, 2) as $index => $player)
		{
			$rowY = $y + 6 * $u + $index * 50 * $u;
			$rowHeight = 46 * $u;
			if($player['turn']) $canvas->box($x + 6 * $u, $rowY, $width - 12 * $u, $rowHeight, 9 * $u
				, Theme::alpha(Theme::BRASS, .16), Theme::alpha(Theme::BRASS, .6), 1.5 * $u);

			$group = $player['group'];
			$chip = match($group) {'solid' => 'Solids','stripe' => 'Stripes',default => 'Open'};
			$chipColor = match($group) {'solid' => Theme::SOLID,'stripe' => Theme::STRIPE,default => Theme::TEXT_SECONDARY};
			$chipWidth = $canvas->measure($chip, Theme::CAPTION, 'bold') + 16 * $u;
			$chipX = $x + $width - 14 * $u - $chipWidth;
			$canvas->box($chipX, $rowY + 7 * $u, $chipWidth, 20 * $u, 10 * $u, Theme::alpha($chipColor, .16));
			$canvas->label($chip, $chipX + $chipWidth / 2, $rowY + 17 * $u, Theme::CAPTION, $chipColor, 'bold', 'center');

			$nameX = $x + 16 * $u;
			$tag = $player['local'] ? 'You' : null;
			$tagWidth = $tag ? $canvas->measure($tag, Theme::CAPTION, 'bold') + 14 * $u : 0;
			$nameWidth = $canvas->label($player['name'], $nameX, $rowY + 17 * $u, Theme::LABEL
				, $player['turn'] ? Theme::TEXT : Theme::alpha(Theme::TEXT, .86), 'bold', 'left', $chipX - $nameX - $tagWidth - 12 * $u);
			if($tag)
			{
				$tagX = $nameX + $nameWidth + 8 * $u;
				$canvas->box($tagX, $rowY + 8 * $u, $tagWidth, 18 * $u, 9 * $u, [1,1,1,.1]);
				$canvas->label($tag, $tagX + $tagWidth / 2, $rowY + 17 * $u, Theme::CAPTION, Theme::TEXT_SECONDARY, 'bold', 'center');
			}

			if($group === null)
			{
				$open = $score['openDown'] ? 'Open table · ' . count($score['openDown']) . ' down' : 'Open table';
				$canvas->label($open, $nameX, $rowY + 35 * $u, Theme::CAPTION, Theme::TEXT_SECONDARY, 'body', 'left', $width - 32 * $u);
				continue;
			}

			$numbers = $group === 'solid' ? range(1, 7) : range(9, 15);
			foreach($numbers as $ballIndex => $number)
			{
				$ballX = $nameX + 6 * $u + $ballIndex * 16 * $u;
				$ballY = $rowY + 35 * $u;
				$color = Scene::BALL_COLORS[$number];
				if(in_array($number, $player['down'], true))
				{
					$canvas->circle($ballX, $ballY, 6 * $u, [0,0,0,0], Theme::alpha(Theme::TEXT_SECONDARY, .4), max(1, $u));
					continue;
				}
				if($group === 'solid') $canvas->circle($ballX, $ballY, 6 * $u, $color);
				else
				{
					$canvas->circle($ballX, $ballY, 6 * $u, [.93,.91,.85,1]);
					$canvas->box($ballX - 6 * $u, $ballY - 3 * $u, 12 * $u, 6 * $u, 2 * $u, $color);
				}
			}
			if(count($player['down']) === 7)
			$canvas->label('On the 8', $nameX + 6 * $u + 7 * 16 * $u, $rowY + 35 * $u, Theme::CAPTION, Theme::BRASS_BRIGHT, 'bold');
		}

		return $y + $height;
	}

	private function drawToast(UiCanvas $canvas, Game $game, int $width, array $metrics, float $leftBottom, float $elapsed): void
	{
		$toast = $game->toast();
		$key = $toast ? $toast['tone'] . ':' . $toast['text'] : null;
		if($key !== $this->toastText)
		{
			$this->toastText = $key;
			if($toast) {$this->toastShown = $toast; $this->toastOpacity = 0.0;}
		}
		$target = $toast ? 1.0 : 0.0;
		$this->toastOpacity += ($target - $this->toastOpacity) * (1 - exp(-$elapsed * 14));
		if(abs($target - $this->toastOpacity) < .01) $this->toastOpacity = $target;
		else $this->fading = true;
		if($this->toastShown === null || $this->toastOpacity <= .01) return;

		$u = $canvas->unit;
		$margin = $metrics['margin'];
		$tone = $this->toastShown['tone'];
		$accent = match($tone) {'error' => Theme::DANGER,'warning' => Theme::BRASS,'turn' => Theme::BRASS_BRIGHT,default => Theme::SUCCESS};
		$left = $this->leftWidth + 12 * $u;
		$right = $metrics['topRight'] - 12 * $u;
		$maxWidth = min(560 * $u, $width - 2 * $margin);
		$lines = $canvas->text->wrap($this->toastShown['text'], Theme::BODY * $u, $maxWidth - 50 * $u, 2);
		$textWidth = max(array_map(fn(string $line): float => $canvas->text->measure($line, Theme::BODY * $u), $lines));
		$toastWidth = $textWidth + 50 * $u;
		$toastHeight = (count($lines) === 1 ? 40 : 62) * $u;
		$x = ($width - $toastWidth) / 2;
		$y = $margin;
		if($x < $left || $x + $toastWidth > $right)
		{
			$x = max($left, min($right - $toastWidth, $x));
			if($x < $left || $x + $toastWidth > $right) {$x = $margin; $y = $leftBottom + 10 * $u;}
		}
		$y -= (1 - $this->toastOpacity) * 6 * $u;

		$opacity = $canvas->opacity;
		$canvas->opacity *= $this->toastOpacity;
		$canvas->shadow($x, $y + 4 * $u, $toastWidth, $toastHeight, 20 * $u, 14 * $u);
		$canvas->box($x, $y, $toastWidth, $toastHeight, 20 * $u, Theme::PANEL_RAISED, Theme::alpha($accent, .45), max(1, $u));
		$canvas->circle($x + 22 * $u, $y + 20 * $u, 4 * $u, $accent);
		foreach($lines as $index => $line)
		$canvas->label($line, $x + 36 * $u, $y + (20 + $index * 22) * $u, Theme::BODY, Theme::TEXT);
		$canvas->opacity = $opacity;
	}

	/**
	 * Draw a row of [legends, label] hints anchored at `$x`.
	 *
	 * @param list<array{list<string>,string}> $hints
	 */
	private function hintRow(UiCanvas $canvas, array $hints, float $x, float $middle, string $align = 'left', ?float $maxWidth = null
		, bool $pad = false, bool $backdrop = false
	): float {
		$u = $canvas->unit;
		$widths = [];
		if($backdrop && $maxWidth !== null) $maxWidth -= 28 * $u;
		foreach($hints as $index => [$legends,$label])
		{
			$width = $canvas->measure($label, Theme::CAPTION);
			foreach($legends as $legend) $width += $canvas->keycapWidth($legend, Theme::CAPTION, $pad) + 4 * $u;
			$widths[$index] = $width + 4 * $u;
		}
		while($hints && $maxWidth !== null && array_sum($widths) + (count($widths) - 1) * 18 * $u > $maxWidth)
		{
			array_pop($hints);
			array_pop($widths);
		}
		if(!$hints) return 0;
		$total = array_sum($widths) + (count($widths) - 1) * 18 * $u;
		$cursor = match($align) {'center' => $x - $total / 2, 'right' => $x - $total, default => $x};
		if($backdrop) $canvas->box($cursor - 14 * $u, $middle - 16 * $u, $total + 28 * $u, 32 * $u, 16 * $u
			, Theme::alpha(Theme::PANEL, .78), Theme::HAIRLINE, max(1, $u));

		foreach($hints as [$legends,$label])
		{
			foreach($legends as $legend) $cursor += $canvas->keycap($legend, $cursor, $middle, Theme::CAPTION, $pad) + 4 * $u;
			$cursor += 4 * $u;
			$cursor += $canvas->label($label, $cursor, $middle, Theme::CAPTION, Theme::alpha(Theme::TEXT, .82), 'body', 'left');
			$cursor += 18 * $u;
		}

		return $total;
	}

	private function drawHints(UiCanvas $canvas, Game $game, int $width, int $height, array $metrics, float $elapsed): void
	{
		$pad = $game->inputTheme !== 'keyboard';
		$placing = $game->simulation->phase === 'placing';
		$hints = $placing
			? ($pad ? [[['LS'],'Move'],[['A'],'Place']] : [[['Click'],'Place'],[['←','→'],'Move'],[['Esc'],'Menu']])
			: ($pad ? [[['LS'],'Aim'],[['L3'],'Fine'],[['A'],'Shoot'],[['RS'],'Camera']]
				: [[['Drag'],'Aim'],[['Shift'],'Fine'],[['Space'],'Shoot']]);
		$key = $game->inputTheme . ':' . ($placing ? 'placing' : 'aim');
		$now = microtime(true);
		if($key !== $this->hintKey) {$this->hintKey = $key; $this->hintFadeAt = $now + 9;}
		$target = $now < $this->hintFadeAt && $game->controllable() && !$game->simulation->moving() ? 1.0 : 0.0;
		$this->hintOpacity += ($target - $this->hintOpacity) * (1 - exp(-$elapsed * 5));
		if(abs($target - $this->hintOpacity) < .01) $this->hintOpacity = $target;
		else $this->fading = true;
		if($this->hintOpacity <= .01 || $metrics['narrow']) return;

		$u = $canvas->unit;
		$left = $metrics['card'][0] + $metrics['card'][2] + 20 * $u;
		$right = $metrics['dockLeft'] - 20 * $u;
		$opacity = $canvas->opacity;
		$canvas->opacity *= $this->hintOpacity;
		$this->hintRow($canvas, $hints, ($left + $right) / 2, $height - $metrics['margin'] - 22 * $u, 'center', $right - $left, $pad, true);
		$canvas->opacity = $opacity;
	}

	private function drawEndCard(UiCanvas $canvas, Game $game, array $layout, int $width, int $height): void
	{
		$card = $game->endCard();
		if($card === null) return;
		$u = $canvas->unit;
		[$x,$y,$cardWidth,$cardHeight] = $layout['panel'];
		$canvas->box(0, 0, $width, $height, 0, [.004,.008,.006,.35]);
		$this->panel($canvas, $x, $y, $cardWidth, $cardHeight, 16, true);
		$canvas->box($x + $cardWidth / 2 - 20 * $u, $y + 18 * $u, 40 * $u, 3 * $u, 1.5 * $u, Theme::BRASS);
		$canvas->label($card['title'], $x + $cardWidth / 2, $y + 48 * $u, 30, Theme::TEXT, 'bold', 'center', $cardWidth - 40 * $u);
		$canvas->label($card['detail'], $x + $cardWidth / 2, $y + 80 * $u, Theme::BODY, Theme::TEXT_SECONDARY, 'body', 'center', $cardWidth - 40 * $u);
		foreach($card['actions'] as [$name,$label])
		$this->button($canvas, $layout['buttons'][$name], $label, '', 'primary', true, $game->hover === $name, $game->pressed === $name);
	}

	private function drawOverlay(UiCanvas $canvas, Game $game, int $width, int $height): void
	{
		$u = $canvas->unit;
		$layout = self::overlayLayout($game, $canvas, $width, $height);
		[$x,$y,$cardWidth,$cardHeight] = $layout['panel'];
		$canvas->box(0, 0, $width, $height, 0, Theme::DIM);
		$this->panel($canvas, $x, $y, $cardWidth, $cardHeight, 16, true);
		$pad = $game->inputTheme !== 'keyboard';

		if($game->overlay === 'controls')
		{
			$canvas->label('Controls', $x + 28 * $u, $y + 36 * $u, Theme::HEADING, Theme::TEXT, 'bold');
			$keyboardX = $x + $cardWidth * .42;
			$padX = $x + $cardWidth * .76;
			$canvas->label('Keyboard & mouse', $keyboardX, $y + 64 * $u, Theme::CAPTION, Theme::BRASS_BRIGHT, 'bold');
			$canvas->label('Controller', $padX, $y + 64 * $u, Theme::CAPTION, Theme::BRASS_BRIGHT, 'bold');
			$rowHeight = $layout['rowHeight'];
			$size = min(Theme::CAPTION, $rowHeight / $u - 9);
			foreach(self::CONTROLS as $index => [$label,$keys,$buttons])
			{
				$middle = $y + 76 * $u + ($index + .5) * $rowHeight;
				if($index % 2 === 0) $canvas->box($x + 16 * $u, $middle - $rowHeight / 2, $cardWidth - 32 * $u, $rowHeight, 6 * $u, [1,1,1,.025]);
				$canvas->label($label, $x + 28 * $u, $middle, $size + 1, Theme::TEXT, 'body', 'left', $keyboardX - $x - 36 * $u);
				$cursor = $keyboardX;
				foreach($keys as $legend) $cursor += $canvas->keycap($legend, $cursor, $middle, $size) + 4 * $u;
				$cursor = $padX;
				if(!$buttons) $canvas->label('—', $cursor, $middle, $size, Theme::alpha(Theme::TEXT_SECONDARY, .6));
				foreach($buttons as $legend) $cursor += $canvas->keycap($legend, $cursor, $middle, $size, true) + 4 * $u;
			}
			$this->button($canvas, $layout['items']['back'], 'Back', '', 'primary', true, $game->hover === 'back', $game->pressed === 'back', true);

			return;
		}

		$canvas->label('Paused', $x + $cardWidth / 2, $y + 36 * $u, Theme::HEADING, Theme::TEXT, 'bold', 'center');
		foreach($game->menuItems() as $index => [$name,$label])
		{
			$selected = $index === $game->menuSelection;
			$this->button($canvas, $layout['items'][$name], $label, '', $selected ? 'primary' : 'secondary', true
				, $game->hover === $name && !$selected, $game->pressed === $name);
		}
		$this->hintRow($canvas, [[['↑','↓'],'Select'],[[$pad ? 'A' : 'Enter'],'Choose'],[[$pad ? 'B' : 'Esc'],'Back']]
			, $x + $cardWidth / 2, $y + $cardHeight - 24 * $u, 'center', $cardWidth - 24 * $u, $pad);
	}

	public function restore(): void
	{
		if($this->closed) return;
		$this->renderer->restore();
	}

	public function close(): void
	{
		if($this->closed) return;
		$this->closed = true;
		$this->renderer->close();
	}
}
