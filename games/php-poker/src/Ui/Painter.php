<?php
declare(strict_types=1);
namespace PhpPoker\Ui;

use PhpPoker\WildCards;
use PhpPoker\Render\Theme;
use PhpPoker\Render\UiCanvas;

/**
 * Draws one frame of the table from the view, the presentation state and the layout.
 *
 * The composition follows the DOS game: the whole canvas is felt, every seat is a status plate over a fan of
 * cards with chip stacks in front, each opponent has a compact icon/name/total row, the pot and deck sit in the
 * middle, a menu bar runs along the top and dialogs are ruled boxes. The `classic` mode draws it flat; `modern`
 * adds rounded corners, a soft vignette and light shadows.
 */
final class Painter
{
	/** Brightness of your own face-down stud cards, between a live card and a folded one. */
	private const HOLE_SHADE = .76;

	private const SUITS = ['♣','♦','♥','♠'];
	private const RANKS = [2 => '2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K', 'A'];
	private const EMBLEM_COLORS = [[.42,.33,.5,1],[.18,.37,.48,1],[.48,.29,.17,1],[.25,.42,.25,1],[.54,.35,.12,1],[.29,.29,.48,1],[.48,.18,.22,1],[.2,.42,.36,1]];

	private readonly string $mode;
	private readonly string $face;
	private readonly float $unit;
	private readonly float $horizontalUnit;

	/**
	 * @param UiCanvas $canvas Draw list to fill.
	 * @param Layout $layout Field placement for this canvas.
	 * @param array<string,mixed> $view Session view.
	 * @param array<string,mixed> $state Presenter state from `Presenter::state()`.
	 * @param array<string,mixed>|null $atlas Rasterised card atlas descriptor, or null while it is loading.
	 */
	public function __construct(
		private UiCanvas $canvas, private Layout $layout, private array $view, private array $state, private ?array $atlas
	) {
		$this->mode = $state['mode'] ?? Theme::CLASSIC;
		$this->face = $this->mode === Theme::CLASSIC && isset($canvas->text->faces()['dos']) ? 'dos' : 'bold';
		$this->unit = $layout->scale;
		$this->horizontalUnit = $layout->horizontalScale;
	}

	/**
	 * Draw everything for the current screen.
	 *
	 * @param int $width Canvas width in device pixels.
	 * @param int $height Canvas height in device pixels.
	 */
	public function paint(int $width, int $height): void
	{
		$this->field($width, $height);
		$screen = $this->view['screen'] ?? 'menu';
		if($screen === 'menu') $this->menuScreen();
		elseif($screen === 'help') $this->helpScreen();
		elseif($screen === 'settings') $this->settingsScreen();
		else { $this->menuBar(); $this->table(); $this->tableOverlay(); }
		$this->controllerFocus();
	}

	private function field(int $width, int $height): void
	{
		$felt = Theme::felt($this->mode);
		$this->canvas->box(0, 0, $width, $height, 0, $felt);
		if($this->mode !== Theme::MODERN) return;
		// Vignette: lighter centre band, darker edges, built from vertical and horizontal ramps.
		$centre = Theme::feltCentre($this->mode);
		$edge = Theme::feltEdge($this->mode);
		$this->canvas->gradient(0, 0, $width, $height / 2, 0, $edge, $centre);
		$this->canvas->gradient(0, $height / 2, $width, $height / 2, 0, $centre, $edge);
		$side = Theme::alpha([0,0,0,1], .18);
		$clear = [0,0,0,0];
		$this->canvas->gradient(0, 0, $width * .3, $height, 0, $side, $clear, true);
		$this->canvas->gradient($width * .7, 0, $width * .3, $height, 0, $clear, $side, true);
	}

	private function menuBar(): void
	{
		// The bar belongs to the page, not the field: it sits on the canvas's top edge and spans its full width
		// even when the field is centred inside a canvas of another shape.
		[, , $fieldWidth, $height] = $this->layout->rect(0, 0, $this->layout->width(), $this->layout->menuHeight());
		$top = 0;
		$left = 0;
		$width = $this->layout->left * 2 + $fieldWidth;
		$this->canvas->box(0, $top, $width, $height, 0, Theme::MENU);
		$headings=$this->layout->topMenus();
		foreach(['game'=>'GAME','poker'=>'POKER'] as $id=>$label)
		{
			[$hx,$hy,$hw,$hh]=$this->layout->rect(...$headings[$id]);
			$active=($this->state['topMenu']??null)===$id;
			if($active) $this->canvas->box($hx,$hy,$hw,$hh,0,Theme::DIALOG);
			$ink=$active?Theme::INK:Theme::MENU_TEXT;
			$full=$this->canvas->measure($label,Theme::LABEL,$this->face);
			$first=$this->canvas->measure($label[0],Theme::LABEL,$this->face);
			$this->canvas->label($label,$hx+$hw/2,$hy+$hh/2,Theme::LABEL,$ink,$this->face,'center');
			$this->canvas->box($hx+($hw-$full)/2,$hy+$hh*.76,max(2*$this->unit,$first*.82),max(1,$this->unit),0,$ink);
		}
		[,,$pokerWidth]=$this->layout->rect(...$headings['poker']);
		[$pokerX]=$this->layout->point($headings['poker'][0],0);
		$game = (string)($this->view['game'] ?? 'draw');
		$stage = $this->state['motion']['kind'] ?? '';
		$phase = !empty($this->view['waitingForConfirmation']) ? 'CONFIRMING'
			: GameText::phase($game, (string)($this->view['street'] ?? ''), (string)($this->view['phase'] ?? ''), (string)$stage);
		// The right of the bar reads the step, then the game. It may use all the bar the two menus leave.
		$right = $left + $width - 14 * $this->unit;
		$this->canvas->label(GameText::barStatus($game, $phase), $right, $top + $height / 2, Theme::LABEL, Theme::MENU_TEXT, $this->face, 'right'
			, max(1, $right - ($pokerX + $pokerWidth + 16 * $this->unit)));
	}

	/** Draw table-bar dropdowns and information windows above every table object. */
	private function tableOverlay(): void
	{
		if(($this->state['confirmDialog']??null)!==null) { $this->confirmationDialog(); return; }
		if(($this->state['infoDialog']??null)!==null)
		{
			// The rules and the settings are the title's own screens, drawn here as windows over the table.
			match($this->state['infoDialog']) {
				'game-settings' => $this->gameSettingsDialog(), 'help' => $this->helpScreen(), 'settings' => $this->settingsScreen(),
				default => $this->oddsDialog((string)$this->state['infoDialog'])
			};
			return;
		}
		if(($this->state['topMenu']??null)!==null) $this->dropdownMenu((string)$this->state['topMenu']);
	}

	/** A compact desktop-style command menu attached to the black table bar. */
	private function dropdownMenu(string $menu): void
	{
		$dropdown=$this->layout->topDropdown($menu, (string)($this->view['game'] ?? 'draw'));
		[$x,$y,$w,$h]=$this->layout->rect(...$dropdown['box']);
		if($this->mode===Theme::MODERN) $this->canvas->shadow($x,$y,$w,$h,2*$this->unit,8*$this->unit);
		$this->canvas->box($x,$y,$w,$h,0,Theme::DIALOG,Theme::INK,1*$this->unit);
		foreach($dropdown['items'] as $index=>$item)
		{
			$disabled=($this->view['mode']??'offline')==='online' && in_array($item['id'],['new','save','load'],true);
			$disabled=$disabled || ($item['id']==='save' && empty($this->view['canContinue']));
			[$rx,$ry,$rw,$rh]=$this->layout->rect(...$item['rect']);
			if($index>0) $this->canvas->box($rx+5*$this->unit,$ry,$rw-10*$this->unit,1*$this->unit,0,Theme::alpha(Theme::INK,.18));
			$ink=$disabled?Theme::alpha(Theme::INK,.38):Theme::INK;
			$this->text($item['label'],$item['rect'][0]+14*$this->layout->stretch(),$item['rect'][1]+$item['rect'][3]/2,$this->layout->controlText(),$ink,'left',$item['rect'][2]-58*$this->layout->stretch());
			$this->text($item['key'],$item['rect'][0]+$item['rect'][2]-14*$this->layout->stretch(),$item['rect'][1]+$item['rect'][3]/2,$this->layout->captionText(),$ink,'right');
		}
	}

	/** Exact odds tables for the deck and wild-card rules currently in play. */
	private function oddsDialog(string $table): void
	{
		$tables=is_array($this->state['oddsTables']??null)?$this->state['oddsTables']:[];
		$rows=is_array($tables[$table]??null)?$tables[$table]:[];
		$geometry=$this->layout->oddsDialog($table,count($rows));
		$this->dialog($geometry['box'],$table==='odds'?'ODDS AGAINST BEING DEALT':'ODDS AGAINST IMPROVING');
		$this->text((string)($tables['rules']??'No wild cards'),$geometry['box'][0]+$geometry['box'][2]/2,$geometry['box'][1]+24,
			$this->layout->captionText(),Theme::INK,'center',$geometry['box'][2]-24);
		foreach($geometry['rows'] as $index=>$rect)
		{
			if(!isset($rows[$index])) continue;
			[$x,$y,$w,$h]=$this->layout->rect(...$rect);
			$fill=$index%2===0?Theme::alpha(Theme::BUTTON,.72):Theme::DIALOG;
			$this->canvas->box($x,$y,$w,$h,0,$fill,Theme::alpha(Theme::INK,.24),1*$this->unit);
			$middle=$rect[1]+$rect[3]/2;
			if($table==='odds')
			{
				$this->text((string)$rows[$index]['hand'],$rect[0]+10*$this->layout->stretch(),$middle,$this->layout->captionText(),Theme::INK,'left',$rect[2]*.72);
				$this->text((string)$rows[$index]['odds'],$rect[0]+$rect[2]-10*$this->layout->stretch(),$middle,$this->layout->captionText(),Theme::INK,'right');
			}
			else
			{
				$this->text((string)$rows[$index]['hand'],$rect[0]+10*$this->layout->stretch(),$middle,$this->layout->captionText(),Theme::INK,'left',$rect[2]*.38);
				$this->text((string)$rows[$index]['target'],$rect[0]+$rect[2]*.43,$middle,$this->layout->captionText(),Theme::INK,'left',$rect[2]*.4);
				$this->text((string)$rows[$index]['odds'],$rect[0]+$rect[2]-10*$this->layout->stretch(),$middle,$this->layout->captionText(),Theme::INK,'right');
			}
		}
		if($table==='odds') $this->button($geometry['more'],'MORE...','M','default',false);
		$this->button($geometry['ok'],'OK','↵','primary',false);
	}

	/** Read-only summary of the active table profile. */
	private function gameSettingsDialog(): void
	{
		$geometry=$this->layout->gameSettingsDialog((int)($this->state['gameSettingsWildLines']??1));
		$settings=is_array($this->state['gameSettings']??null)?$this->state['gameSettings']:[];
		$labels=GameText::houseRuleLabels((string)($this->view['game'] ?? 'draw'));
		$this->dialog($geometry['box'],(string)($this->state['gameSettingsTitle']??'HOUSE RULES'));
		foreach($geometry['rows'] as $index=>$rect)
		{
			[$x,$y,$w,$h]=$this->layout->rect(...$rect);
			$position=array_search($index,array_keys($geometry['rows']),true);
			$fill=((int)$position%2===0)?Theme::alpha(Theme::BUTTON,.72):Theme::DIALOG;
			$this->canvas->box($x,$y,$w,$h,0,$fill,Theme::alpha(Theme::INK,.2),1*$this->unit);
			$labelWidth=min(132*$this->layout->stretch(),$rect[2]*.36);
			$firstLineY=$rect[1]+min($rect[3],30)/2;
			$this->text($labels[$index],$rect[0]+10*$this->layout->stretch(),$firstLineY,
				$this->layout->captionText(),Theme::alpha(Theme::INK,.72),'left',$labelWidth-14*$this->layout->stretch());
			$value=(string)($settings[$index]??'');
			$valueX=$rect[0]+$labelWidth;
			$valueWidth=$rect[2]-$labelWidth-10*$this->layout->stretch();
			if($index!=='wild')
			{
				$this->text($value,$valueX,$rect[1]+$rect[3]/2,$this->layout->controlText(),Theme::INK,'left',$valueWidth);
				continue;
			}
			$shown=$this->mode===Theme::CLASSIC?strtoupper($value):$value;
			$size=$this->layout->controlText();
			$lines=$this->canvas->text->wrap($shown,$size*$this->unit,$valueWidth*$this->horizontalUnit,3,$this->face);
			$leading=$size*1.25;
			$lines=array_slice($lines,0,3);
			$lineY=$firstLineY;
			foreach($lines as $line)
			{
				$this->text($line,$valueX,$lineY,$size,Theme::INK,'left',$valueWidth);
				$lineY+=$leading;
			}
		}
		$this->button($geometry['ok'],'OK','↵','primary',false);
	}

	private function confirmationDialog(): void
	{
		// Only an online table asks: leaving resigns the match. Offline tables are saved and leave at once.
		$geometry=$this->layout->confirmationDialog();
		$this->dialog($geometry['box'],'LEAVE MATCH');
		$this->text('Leaving forfeits this match.',$geometry['box'][0]+$geometry['box'][2]/2,$geometry['box'][1]+58,
			$this->layout->controlText(),Theme::INK,'center',$geometry['box'][2]-32);
		$this->button($geometry['cancel'],'STAY','N','default',false);
		$this->button($geometry['confirm'],'FORFEIT','Y','danger',false);
	}

	private function table(): void
	{
		$players = $this->view['players'] ?? [];
		$seats = count($players);
		$phase = $this->view['phase'] ?? 'ready';
		$showdown = in_array($phase, ['showdown','redeal'], true);
		$geometry = [];
		for($seat = 0; $seat < $seats; $seat++) $geometry[$seat] = $this->layout->seat($seat, $seats);

		// Layer 1: plates.
		foreach($players as $seat => $player)
		{
			$label = $this->plateText($player, $seat, $showdown);
			if($label === '') continue;
			$winner = in_array($seat, array_column($this->view['awards'] ?? [], 'seat'), true);
			if($winner && !($this->state['winnerFlash']['visible'] ?? true)) $label = '';
			$kind = $player['folded'] ? 'fold' : ($showdown && $player['handName'] ? 'hand' : 'info');
			$this->plate($geometry[$seat]['plate'], $label, $kind, ($this->view['turn'] ?? -1) === $seat);
		}
		// Layer 2: cards.
		foreach($players as $seat => $player)
		{
			if($phase === 'ready') continue;
			if(in_array($seat, $this->state['motion']['hiddenSeats'] ?? [], true)) continue;
			if($seat === 0 && !empty($this->view['legalActions']['draw']) && !$this->state['locked']) continue;
			$dim = $player['folded'] ? .55 : 1.0;
			$hidden = $this->state['motion']['hiddenCards'][$seat] ?? [];
			foreach($geometry[$seat]['cards'] as $index => $rect)
			{
				if(!array_key_exists($index, $player['cards'] ?? [])) break;
				if(in_array($index, $hidden, true)) continue;
				// In stud your face-down cards are yours alone, beside up-cards everyone sees, so they are shaded. The
				// shade covers the whole face because a fan shows only each card's index strip.
				$hole = $seat === 0 && !$player['folded'] && str_starts_with((string)($this->view['game'] ?? 'draw'), 'stud')
					&& ($player['faces'][$index] ?? 'up') === 'down' && $player['cards'][$index] !== null && empty($this->view['reveal']);
				$this->card($rect, $player['cards'][$index], $hole ? self::HOLE_SHADE : $dim);
			}
		}
		// Layer 3: chips in front of the fans.
		foreach($players as $seat => $player)
		{
			$bank = ($this->state['motion']['banks'] ?? $this->state['chips'])[$seat] ?? [];
			$this->chipStacks($seat, $bank);
			$total = (int)($this->state['motion']['totals'][$seat] ?? Chips::value($bank));
			if($seat === 0)
			{
				// The default disc already says YOU; a profile picture gets YOU beneath it, above the total.
				[$tx, $ty] = $this->layout->caption($seat, $seats, $bank);
				$size = $this->layout->captionText();
				if($this->pictured($player))
				{
					$this->text('You', $tx, $ty, $size, Theme::PLATE, 'center');
					$ty += $size + 2;
				}
				$this->text((string)$total, $tx, $ty, $size, Theme::PLATE, 'center');
			}
			if(!empty($player['bet']))
			{
				$outward = $geometry[$seat]['outward'];
				[$px, $py, $pw] = $geometry[$seat]['plate'];
				$bx = $outward > 0 ? $px + $pw + 24 : $px - 40;
				if($seat === 0)
				{
					// Right-align beside the bank's left edge, leaving the denomination labels unobstructed.
					[$x,, $radius] = $this->layout->chip($seat, $seats, 1, 0);
					$bx = $x - $radius;
					foreach($bank as $value => $count) if($count > 0)
					{
						[$x,, $radius] = $this->layout->chip($seat, $seats, (int)$value, 0);
						$bx = min($bx, $x - $radius);
					}
					$label = 'Bet ' . $player['bet'];
					$size = $this->layout->captionText();
					$hand = $geometry[$seat]['slots'][4];
					$left = $this->layout->profile === 'landscape' ? $hand[0] + $hand[2] + 6 : 8;
					$width = $this->canvas->measure($this->mode === Theme::CLASSIC ? strtoupper($label) : $label, $size, $this->face) / $this->horizontalUnit;
					$size *= min(1, ($bx - 10 - $left) / $width);
					$this->text($label, $bx - 10, $geometry[$seat]['chips'][1] + 10, $size, Theme::PLATE, 'right');
				}
				// The plate already says what an opponent did; the running bet is spelled out only where the wide field has room.
				elseif($this->layout->profile === 'wide')
				{
					// Beside the bank on its outer side, or under it: the first spot that stays on the field and off
					// every other seat. The plate already names the action, so with no clear spot the label is left out.
					$label = 'Bet ' . $player['bet'];
					$size = $this->layout->captionText();
					$width = $this->canvas->measure($this->mode === Theme::CLASSIC ? strtoupper($label) : $label, $size, $this->face) / $this->horizontalUnit;
					$bounds = $this->layout->bankBounds($seat, $seats, $bank) ?? [$geometry[$seat]['chips'][0], $geometry[$seat]['chips'][1], 0, 0];
					$gap = 6 * $this->layout->stretch();
					$labelY = $geometry[$seat]['chips'][1] + 10;
					$spots = [
						$outward > 0 ? [$bounds[0] + $bounds[2] + $gap, $labelY] : [$bounds[0] - $gap - $width, $labelY]
						, [$bounds[0] + $bounds[2] / 2 - $width / 2, $bounds[1] + $bounds[3] + $size * .7]
					];
					foreach($spots as [$left, $middle])
					{
						$box = [$left - 2, $middle - $size * .6, $width + 4, $size * 1.2];
						$clear = $box[0] >= 0 && $box[0] + $box[2] <= $this->layout->width() && $box[1] + $box[3] <= $this->layout->height();
						foreach($geometry as $other => $near) if($clear && $other !== $seat)
						{
							$last = $near['slots'][4];
							foreach([$near['plate'], [$near['slots'][0][0], $near['slots'][0][1], $last[0] + $last[2] - $near['slots'][0][0], $last[3]]] as $block)
							if($box[0] < $block[0] + $block[2] && $block[0] < $box[0] + $box[2] && $box[1] < $block[1] + $block[3] && $block[1] < $box[1] + $box[3]) $clear = false;
						}
						if(!$clear) continue;
						$this->text($label, $left, $middle, $size, Theme::PLATE, 'left');
						break;
					}
				}
			}
		}
		// Layer 4: seat identities, dealer button and turn marker.
		foreach($players as $seat => $player)
		{
			$bank = ($this->state['motion']['banks'] ?? $this->state['chips'])[$seat] ?? [];
			[$x, $y] = $geometry[$seat]['emblem'];
			$this->emblem($x, $y, $seat, (string)($player['name'] ?? ''), (string)($player['portrait'] ?? ''));
			if($seat !== 0)
			{
				[$tx, $ty] = $geometry[$seat]['caption'];
				$width = max(1, $geometry[$seat]['plate'][2]);
				$total = (int)($this->state['motion']['totals'][$seat] ?? Chips::value($bank));
				$size = $this->layout->captionText();
				$name = (string)($player['name'] ?? '');
				$shown = $this->mode === Theme::CLASSIC ? strtoupper($name) : $name;
				$cards = $geometry[$seat]['cards'];
				$last = $cards[array_key_last($cards)];
				$cardRight = $last[0] + $last[2];
				$available = $geometry[$seat]['outward'] > 0 ? $this->layout->width() - $cardRight - 4 : $cards[0][0] - 4;
				$nameLimit = min($width, max(1, $available));
				$nameWidth = min($nameLimit, $this->canvas->measure($shown, $size, $this->face) / $this->horizontalUnit);
				$nameX = Layout::identityNameX($geometry[$seat], $nameWidth);
				$this->text($name, $nameX, $ty, $size, Theme::PLATE, 'center', $nameLimit);
				$this->text((string)$total, $tx, $ty + $size + 2, $size, Theme::PLATE, 'center', $width);
			}
			if(($this->view['dealer'] ?? -1) === $seat && $phase !== 'ready')
			{
				[$dealerX, $dealerY, $dealerRadius] = $this->layout->dealerMarker($seat, $seats);
				[$dx, $dy] = $this->layout->point($dealerX, $dealerY);
				$this->canvas->circle($dx, $dy, $dealerRadius * $this->unit, Theme::PLATE, Theme::INK, 1 * $this->unit);
				$this->canvas->label('D', $dx, $dy, Theme::CAPTION, Theme::INK, $this->face, 'center');
			}
		}
		$this->centre();
		$this->messages();
		if(!empty($this->view['legalActions']['draw']) && !$this->state['locked']) $this->discardDialog();
		foreach($this->state['motion']['cards'] ?? [] as $card) $this->card($card['rect'], $card['id'], 1.0);
		foreach($this->state['motion']['chips'] ?? [] as $chip) $this->chip($chip['position'], $chip['value']);
		$this->actions();
		if($this->state['history']) $this->historyDialog();
		$status = (string)($this->view['error'] ?? '') !== ''
			|| (string)($this->state['hint'] ?? $this->view['message'] ?? '') !== '';
		if($phase === 'ready' && (int)($this->view['handNumber'] ?? 0) <= 1 && !$status) $this->welcomeDialog();
	}

	private function plateText(array $player, int $seat, bool $showdown): string
	{
		if($player['folded']) return 'Fold';
		// While the browsers shuffle, deal or reveal between themselves, only the working seat has a plate, saying
		// what it is doing; the centre status explains the step.
		$activity = $this->view['activity'] ?? null;
		if(is_array($activity))
		return ($activity['seat'] ?? -1) !== $seat ? ''
			: match($activity['stage'] ?? '') {'shuffle' => 'Shuffling', 'reveal' => 'Revealing', default => 'Dealing'};
		if($showdown)
		{
			if(in_array($seat, array_column($this->view['awards'] ?? [], 'seat'), true)) return $player['handName'] ?: 'Winner';
			return $player['handName'] ? (string)$player['handName'] : ($seat === 0 ? 'Your hand' : '');
		}
		// Online, the YOU disc or the YOU under a profile picture already names the seat, so an idle plate is
		// left out; offline keeps it.
		if($seat === 0) return ($this->view['turn'] ?? -1) === 0 ? 'Your turn'
			: ($player['lastAction'] ?: (($this->view['mode'] ?? 'offline') === 'online' ? '' : 'You'));
		if(($this->view['turn'] ?? -1) === $seat) return 'Thinking';
		return (string)($player['lastAction'] ?: '');
	}

	private function centre(): void
	{
		if(($this->view['phase'] ?? 'ready') === 'ready') return;
		$centre = $this->layout->centre();
		$pot = ($this->state['motion']['banks'] ?? $this->state['chips'])['pot'] ?? [];
		$this->plate($centre['pot'], 'Total pot (' . Chips::value($pot) . ')', 'info', false);
		// Beside a board the stock could pass for one more card, so it is drawn as a squared-up pile.
		if(!empty($centre['board'])) foreach([3, 1.5] as $offset)
			$this->card([$centre['deck'][0] + $offset * $this->layout->stretch(), $centre['deck'][1] + $offset, $centre['deck'][2], $centre['deck'][3]], null, .7);
		$this->card($centre['deck'], null, 1.0);
		$hiddenBoard = $this->state['motion']['hiddenBoard'] ?? [];
		foreach($this->view['board'] ?? [] as $index => $card)
			if(isset($centre['board'][$index]) && !in_array($index, $hiddenBoard, true)) $this->card($centre['board'][$index], $card, 1.0);
		if(($this->state['motion']['discardCount'] ?? $this->view['discardCount'] ?? 0) > 0)
		{
			$this->card($centre['discard'], null, .8);
			// On a phone the loose pot sits right above the piles, so the discards go unlabelled there.
			if($this->layout->profile !== 'tall') $this->text('Discards', $centre['discard'][0] + $centre['discard'][2] / 2, $centre['discard'][1] - 9, $this->layout->captionText(), Theme::PLATE, 'center');
		}
		$this->chipStacks('pot', $pot);
		$highest = 0;
		foreach($this->view['players'] ?? [] as $player) $highest = max($highest, (int)($player['bet'] ?? 0));
		if(!$this->underDialog($centre['bet'])) $this->plate($centre['bet'], 'Current bet (' . $highest . ')', 'info', false);
	}

	/** Whether the open discard dialog covers any part of a design-space rectangle. */
	private function underDialog(array $rect): bool
	{
		if(empty($this->view['legalActions']['draw']) || $this->state['locked']) return false;
		$box = $this->layout->discardDialog()['box'];
		return $rect[0] < $box[0] + $box[2] && $box[0] < $rect[0] + $rect[2] && $rect[1] < $box[1] + $box[3] && $box[1] < $rect[1] + $rect[3];
	}

	private function actions(): void
	{
		$buttons = $this->layout->actions($this->view, $this->state);
		foreach($buttons as $button)
		$this->button($button['rect'], $button['label'], $button['key'], $button['style'], $this->state['locked'] && $button['intent'] !== null);

		$stepper = $this->layout->stepper($this->view);
		if($stepper)
		{
			$this->button($stepper['less'], '−', '', 'default', false);
			$this->plate($stepper['amount'], (string)$this->state['amount'], 'info', false);
			$this->button($stepper['more'], '+', '', 'default', false);
			[$lx, $ly] = $this->layout->point(...$stepper['label']);
			$stepperLabel = strtoupper((string)($this->view['legalActions']['betLabel'] ?? 'Bet'));
			// The primary button already names the bet. In the landscape panel the label would run back over the
			// last seat's name, so it is shown only where it stays inside the panel.
			$labelRoom = $this->layout->profile === 'landscape' ? $lx - ($this->layout->point($this->layout->tableRight(), 0)[0] + 4 * $this->unit) : INF;
			if($this->canvas->measure($stepperLabel, $this->layout->captionText(), $this->face) <= $labelRoom)
			$this->canvas->label($stepperLabel, $lx, $ly, $this->layout->captionText(), Theme::PLATE, $this->face, $stepper['align']);
		}
		if($buttons && $this->state['locked'] && ($this->view['turn'] ?? -1) === 0)
		{
			$controls = array_column($buttons, 'rect');
			if($stepper) foreach(['less','amount','more'] as $part) $controls[] = $stepper[$part];
			$left = min(array_column($controls, 0));
			$right = max(array_map(static fn(array $rect): float => $rect[0] + $rect[2], $controls));
			$bottom = max(array_map(static fn(array $rect): float => $rect[1] + $rect[3], $controls));
			$this->drummingText('WAITING FOR THE TABLE', ($left + $right) / 2, $bottom + 16, Theme::CAPTION, Theme::PLATE, 'center');
		}
	}

	private function messages(): void
	{
		$error = (string)($this->view['error'] ?? '');
		$message = (string)($this->state['hint'] ?? $this->view['message'] ?? '');
		$drumming = $error === '' && !empty($this->state['networkWait']['active']);
		$line = $error !== '' ? $error : ($drumming ? rtrim(rtrim($message), '.') . '...' : $message);
		if($line === '') return;
		// The layout names where the message stands: under the bet plate, or under the board where that plate has moved.
		[$messageX, $top, $anchorWidth] = $this->layout->centre()['message'];
		$width = $this->layout->profile === 'wide'
			? min($this->layout->statusLane()['width'], max(240, $this->canvas->measure(strtoupper($line), Theme::BODY, $this->face) / $this->horizontalUnit + 40)) : min(180, $anchorWidth);
		// The lane between the columns is narrow, so the message may run to three lines there.
		$lines = $this->canvas->text->wrap(strtoupper($line), Theme::BODY * $this->canvas->unit, ($width - 12) * $this->horizontalUnit, $this->layout->columns() ? 3 : 2, $this->face);
		$messageHeight = $this->layout->plateHeight() + 16 * (count($lines) - 1);
		if($this->layout->profile === 'wide')
		{
			$humanTop = $this->layout->seat(0, count($this->view['players'] ?? []))['plate'][1];
			$top = min($top, $humanTop - $messageHeight - 8);
		}
		if($error === '' && $this->underDialog([$messageX - $width / 2, $top, $width, $messageHeight])) return;
		$this->plate([$messageX - $width / 2, $top, $width, $messageHeight], '', $error !== '' ? 'fold' : 'info', false);
		foreach($lines as $index => $text)
		{
			$y = $top + $this->layout->plateHeight() / 2 + $index * 16 + .5;
			if($drumming && $index === array_key_last($lines))
			$this->drummingText(rtrim($text, '.'), $messageX, $y, Theme::BODY, Theme::INK, 'center');
			else $this->text($text, $messageX, $y, Theme::BODY, Theme::INK, 'center');
		}
	}

	private function discardDialog(): void
	{
		$cards = $this->view['players'][0]['cards'] ?? [];
		$dialog = $this->layout->discardDialog($cards, $this->state['discards']);
		$this->dialog($dialog['box'], 'SELECT DISCARDS');
		foreach($dialog['cards'] as $index => $rect)
		{
			if(!isset($cards[$index])) break;
			$selected = in_array((int)$cards[$index], $this->state['discards'], true);
			$this->card($rect, $cards[$index], 1.0, $selected);
			$this->text((string)($index + 1), $rect[0] + $rect[2] / 2, $dialog['numbers'], $this->layout->captionText(), Theme::INK, 'center');
		}
		// The hint shares the bottom row with the draw controls and gives way to them on a narrow dialog.
		[$rowX, $rowY, $rowWidth, $rowHeight] = $dialog['buttons'];
		$buttons = $this->layout->actions($this->view, $this->state);
		$room = ($buttons ? $buttons[0]['rect'][0] : $rowX + $rowWidth) - $rowX - 16;
		$hint = 'Select cards, then press Draw.';
		$hintWidth = $this->canvas->measure($this->mode === Theme::CLASSIC ? strtoupper($hint) : $hint, $this->layout->captionText(), $this->face) / $this->horizontalUnit;
		if($room >= $hintWidth) $this->text($hint, $rowX, $rowY + $rowHeight / 2, $this->layout->captionText(), Theme::INK, 'left', $room);
	}

	private function historyDialog(): void
	{
		$players=array_slice($this->view['players']??[],0,8);
		$content=RoundHistory::build($players,$this->view['history']??[],($this->view['game']??'draw')==='draw');
		$history = $this->layout->history(count($players),count($content['rows']),count($content['lines']));
		$box = $history['box'];
		$this->dialog($box, 'ROUND HISTORY');
		if($history['summary'])
		{
			[$hx,$hy,$hw,$hh]=$this->layout->rect(...$history['header']);
			$this->canvas->box($hx,$hy,$hw,$hh,0,Theme::felt($this->mode),Theme::INK,1*$this->unit);
			foreach($players as $seat=>$player)
			{
				[$x,$y]=$history['emblems'][$seat];
				$this->emblem($x,$y,$seat,(string)($player['name']??''),(string)($player['portrait']??''));
			}
			foreach($content['rows'] as $index=>$row)
			{
				$rect=$history['rows'][$index];
				[$rx,$ry,$rw,$rh]=$this->layout->rect(...$rect);
				if($row['kind']==='system')
				{
					$this->canvas->box($rx,$ry,$rw,$rh,0,Theme::BUTTON,Theme::INK,1*$this->unit);
					$this->text((string)$row['text'],$rect[0]+8*$this->layout->stretch(),$rect[1]+$rect[3]/2,
						$this->layout->captionText(),Theme::INK,'left',$rect[2]-16*$this->layout->stretch());
					continue;
				}
				$column=$rect[2]/max(1,count($players));
				for($seat=0;$seat<count($players);$seat++)
				{
					$cell=[$rect[0]+$seat*$column,$rect[1],$column,$rect[3]];
					$action=$row['cells'][$seat]??null;
					$style=(string)($action['style']??'');
					$fill=match($style) {
						'fold'=>Theme::PLATE_FOLD,
						'call','check','pass'=>Theme::PLATE_HAND,
						'open','bet','raise'=>Theme::mix(Theme::felt($this->mode),Theme::PLATE_HAND,.42),
						'draw','other'=>Theme::BUTTON,
						default=>Theme::HISTORY_BLUE
					};
					[$cx,$cy,$cw,$ch]=$this->layout->rect(...$cell);
					$this->canvas->box($cx,$cy,$cw,$ch,0,$fill,Theme::INK,1*$this->unit);
					if($action!==null) $this->text((string)$action['text'],$cell[0]+$cell[2]/2,$cell[1]+$cell[3]/2,
						$this->layout->captionText(),Theme::INK,'center',$cell[2]-6);
				}
			}
		}
		else foreach($content['lines'] as $index => $line)
		{
			$rect=$history['rows'][$index];
			$this->text($line,$rect[0]+8*$this->layout->stretch(),$rect[1]+$rect[3]/2,Theme::BODY,Theme::INK,'left',$rect[2]-16*$this->layout->stretch());
		}
		$this->button($history['back'], 'OK', '↵', 'primary', false);
	}

	private function welcomeDialog(): void
	{
		$box = $this->layout->panel(440, 250 + $this->layout->verticalShift(), 120);
		$profile = (array)($this->view['profile'] ?? []);
		$this->dialog($box, GameText::title((string)($profile['game'] ?? 'draw')));
		$this->text('Welcome to a game of', $this->layout->width() / 2, $box[1] + 50, Theme::LABEL, Theme::INK, 'center');
		$this->text(GameText::welcome($profile), $this->layout->width() / 2, $box[1] + 80, Theme::LABEL, Theme::INK, 'center', $box[2] - 24);
	}

	private function menuScreen(): void
	{
		$shift = $this->layout->verticalShift();
		$local = $this->state['local'];
		if($local === 'setup') { $this->setupDialog(); return; }
		if($local === 'limits') { $this->limitsDialog(); return; }
		if($local === 'games') { $this->gameDialog(); return; }
		if($local === 'wildcards') { $this->wildcardDialog(); return; }
		if($local === 'custom-wildcards') { $this->customWildcardDialog(); return; }
		if($local === 'online')
		{
			$box = $this->layout->panel(440, $this->layout->profile === 'landscape' ? 165 : 280 + $shift, 120);
			$this->dialog($box, 'PLAY ONLINE');
			$this->text('Opening the Matrix table lobby…', $this->layout->width() / 2, $box[1] + 52, Theme::BODY, Theme::INK, 'center');
			$this->text($this->controller() ? 'Press ' . $this->backLegend() . ' to go back.' : 'Press Escape to go back.', $this->layout->width() / 2, $box[1] + 82, Theme::BODY, Theme::INK, 'center');
			return;
		}
		$canContinue = !empty($this->view['canContinue']);
		$box = $this->layout->menuBox($canContinue);
		$this->dialog($box, 'POKER');
		$this->text("Draw · Texas Hold'em · five- and seven-card stud", $this->layout->width() / 2, $box[1] + 24, Theme::CAPTION, Theme::INK, 'center', $box[2] - 32);
		foreach($this->layout->menu($canContinue) as $index => $item)
		$this->button($item['rect'], $item['label'], $this->controller() ? $this->confirmLegend() : $item['key'], $index === 0 ? 'primary' : 'default', false);
		$this->text('Icon art: Clint Bellanger · thekingphoenix · 7Soul', $this->layout->width() / 2, $box[1] + $box[3] - 8
			, Theme::CAPTION, Theme::alpha(Theme::INK, .75), 'center', $box[2] - 24);
	}

	private function setupDialog(): void
	{
		$players = (int)$this->state['setup']['players'];
		$variant = (string)$this->state['setup']['variant'];
		$game = (string)($this->state['setup']['game'] ?? 'draw');
		$setup = $this->layout->setup($players, $variant);
		$this->dialog($setup['box'], 'TABLE SETUP');
		[$x, $y] = $setup['box'];
		$x += 24 * $this->layout->stretch() - 24;
		$this->text('Opponents', $x + 24, $setup['less'][1] + $setup['less'][3] / 2, Theme::LABEL, Theme::INK);
		$this->button($setup['less'], '−', '', 'default', $players <= 2);
		$this->plate($setup['count'], (string)($players - 1), 'info', false);
		$this->button($setup['more'], '+', '', 'default', $players >= GameText::seats($game));
		$this->text('Game', $x + 24, $setup['variant'][1] + $setup['variant'][3] / 2, Theme::LABEL, Theme::INK);
		$this->button($setup['variant'], GameText::choiceLabel($game, $variant), 'V', 'default', false);
		$wild = (array)($this->state['setup']['wild'] ?? []);
		$this->text('Wild cards', $x + 24, $setup['wild'][1] + $setup['wild'][3] / 2, Theme::LABEL, Theme::INK);
		$this->button($setup['wild'], WildCards::selectionLabel($wild), 'W', 'default', false);
		$state = $this->state['setup'];
		$summary = $state['ante'].' · '.$state['minimum'].'–'.$state['maximum'].' / '.$state['finalMinimum'].'–'.$state['finalMaximum']
			.' · '.$state['raiseLimit'].'/'.$state['playerRaiseLimit'];
		$this->text('Limits', $x + 24, $setup['limits'][1] + $setup['limits'][3] / 2, Theme::LABEL, Theme::INK);
		$this->button($setup['limits'], $summary, 'L', 'default', false);
		$this->button($setup['back'], 'Back', '⎋', 'default', false);
		$this->button($setup['start'], 'Deal', '↵', 'primary', false);
	}

	private function limitsDialog(): void
	{
		$limits = $this->layout->limits();
		$draft = (array)($this->state['setup']['limitDraft'] ?? []);
		$text = GameText::limits((string)($this->state['setup']['game'] ?? 'draw'));
		$labels = array_map(static fn(array $pair): string => $pair[0], $text);
		$captions = array_map(static fn(array $pair): string => $pair[1], $text);
		$this->dialog($limits['box'], 'BETTING & RAISING LIMITS');
		foreach($limits['fields'] as $field => $stepper)
		{
			$value = (int)($draft[$field] ?? 0);
			[$minimum,$maximum] = match($field) {
				'ante'=>[0,100], 'minimum'=>[1,(int)($draft['maximum']??1)],
				'maximum'=>[(int)($draft['minimum']??1),100],
				'finalMinimum'=>[1,(int)($draft['finalMaximum']??1)],
				'finalMaximum'=>[(int)($draft['finalMinimum']??1),100], default=>[0,10]
			};
			$this->text($labels[$field], $stepper['label'][0], $stepper['label'][1] + $stepper['label'][3] * .31,
				Theme::LABEL, Theme::INK, 'left', $stepper['label'][2]);
			$this->text($captions[$field], $stepper['label'][0], $stepper['label'][1] + $stepper['label'][3] * .72,
				$this->layout->captionText(), Theme::alpha(Theme::INK, .72), 'left', $stepper['label'][2]);
			$this->button($stepper['less'], '−', '', 'default', $value <= $minimum);
			$this->plate($stepper['count'], (string)$value, 'info', false);
			$this->button($stepper['more'], '+', '', 'default', $value >= $maximum);
		}
		$this->button($limits['back'], 'Cancel', '⎋', 'default', false);
		$this->button($limits['apply'], 'OK', '↵', 'primary', false);
	}

	/** The game selector: a column of buttons, the current choice highlighted. */
	private function gameDialog(): void
	{
		$setup = $this->state['setup'];
		$current = GameText::choice((string)($setup['game'] ?? 'draw'), (string)$setup['variant']);
		$menu = $this->layout->gameMenu(count(GameText::CHOICES));
		$this->dialog($menu['box'], 'GAME');
		foreach($menu['items'] as $index => $rect)
			$this->button($rect, GameText::choiceLabel(...GameText::CHOICES[$index]), (string)($index + 1), $index === $current ? 'primary' : 'default', false);
		$this->button($menu['back'], 'Back', '⎋', 'default', false);
	}

	private function wildcardDialog(): void
	{
		$menu = $this->layout->wildcardMenu();
		$selected = (array)($this->state['setup']['wildDraft'] ?? []);
		$this->dialog($menu['box'], 'WILD CARDS');
		foreach($menu['items'] as $id => $rect)
		{
			$index = array_search($id, array_keys(WildCards::OPTIONS), true);
			$checked = $id === 'none' ? $selected === [] : in_array($id, $selected, true);
			$this->checkbox($rect, WildCards::label($id), (string)$index, $checked);
		}
		$this->button($menu['back'], 'Cancel', '⎋', 'default', false);
		$this->button($menu['apply'], 'OK', '↵', 'primary', false);
	}

	private function customWildcardDialog(): void
	{
		$page = (int)($this->state['setup']['customWildPage'] ?? 0);
		$custom = $this->layout->customWildcards($page);
		$selected = array_fill_keys($this->state['setup']['customWildDraft'] ?? [], true);
		$this->dialog($custom['box'], 'CUSTOM WILD CARDS');
		if($custom['paged'])
		{
			$pages = ['Clubs','Diamonds','Hearts','Spades','Joker'];
			$this->button($custom['previous'], '−', '', 'default', $page === 0);
			$this->text($pages[$page], $custom['pageLabel'][0], $custom['pageLabel'][1], Theme::LABEL, Theme::INK, 'center');
			$this->button($custom['next'], '+', '', 'default', $page === 4);
		}
		else foreach($custom['rowLabels'] as $label)
		$this->text($label['text'], $label['point'][0], $label['point'][1], Theme::LABEL, Theme::INK, 'center');
		if(!$custom['paged']) foreach($custom['columnLabels'] as $label)
		$this->text($label['text'], $label['point'][0], $label['point'][1], Theme::LABEL, Theme::INK, 'center');
		foreach($custom['cards'] as $id => $rect)
		{
			$suit = $id >= 52 ? -1 : intdiv($id, 13);
			$label = $id >= 52 ? WildCards::rankLabel($id) : WildCards::cardLabel($id);
			$this->checkbox($rect, $label, '', isset($selected[$id]),
				in_array($suit, [1,2], true) ? Theme::DANGER_INK : Theme::INK, true);
		}
		$countLeft = $custom['back'][0] + $custom['back'][2] + 14 * $this->layout->stretch();
		$count = count($selected) . ' selected';
		// The running count sits between Back and the Done button where the footer has room for it whole.
		if($this->canvas->measure(strtoupper($count), Theme::CAPTION, $this->face) / $this->horizontalUnit <= $custom['done'][0] - 8 * $this->layout->stretch() - $countLeft)
		$this->text($count, $countLeft, $custom['back'][1] + $custom['back'][3] / 2, Theme::CAPTION, Theme::INK);
		$this->button($custom['back'], 'Back', '⎋', 'default', false);
		$this->button($custom['done'], 'Use selection', '↵', 'primary', false);
	}

	private function helpScreen(): void
	{
		$game = $this->state['helpGame'] ?? null;
		$games = GameText::HELP_GAMES;
		if($game === null)
		{
			$menu = $this->layout->helpIndex(count($games));
			$this->dialog($menu['box'], 'HOW TO PLAY');
			$title = $this->layout->controlText();
			$caption = $this->layout->captionText();
			$em = $this->layout->typeScale();
			$across = $this->layout->stretch();
			$grow = $title * $em / 14;
			// Each row leads with its game's hand as a small fan, wherever the row is wide enough to keep its copy whole.
			$fan = (20 + 6 * 13 + 3 * 6) * $grow * $across;
			$copy = 0.0;
			foreach($games as $each) foreach([[GameText::title($each), $title], [GameText::helpSummary($each), $caption]] as [$line, $size])
				$copy = max($copy, $this->canvas->measure($line, $size, $this->face) / $this->horizontalUnit);
			$fanned = 14 * $across + $fan + 12 * $across + $copy <= $menu['items'][0][2] - 40 * $across;
			foreach($menu['items'] as $index => $rect)
			{
				// Enter opens the game the table is set up for, so that row is the primary one.
				$this->button($rect, '', (string)($index + 1), $games[$index] === ($this->state['helpChoice'] ?? null) ? 'primary' : 'default', false);
				$left = $rect[0] + 14 * $across;
				if($fanned)
				{
					$x = $left;
					foreach(GameText::helpCards($games[$index]) as $group)
					{
						foreach($group as $id)
						{
							$this->card([$x, $rect[1] + ($rect[3] - 28 * $grow) / 2, 20 * $grow * $across, 28 * $grow], $id, 1);
							$x += 13 * $grow * $across;
						}
						$x += 6 * $grow * $across;
					}
					$left += $fan + 12 * $across;
				}
				$room = $rect[0] + $rect[2] - 40 * $across - $left;
				$top = $rect[1] + ($rect[3] - ($title + $caption) * $em - 5) / 2;
				$this->text(GameText::title($games[$index]), $left, $top + $title * $em / 2, $title, Theme::INK, 'left', $room);
				// The summary is a phrase to read, so like the rules it keeps its small letters in both looks.
				[$sx, $sy] = $this->layout->point($left, $top + $title * $em + 5 + $caption * $em / 2);
				$this->canvas->label(GameText::helpSummary($games[$index]), $sx, $sy, $caption, Theme::alpha(Theme::INK, .78), $this->face, 'left', $room * $this->horizontalUnit);
			}
			$this->button($menu['back'], 'Back', '⎋', 'default', false);
			return;
		}
		$help = HelpPage::of($this->layout, $this->canvas->text, $this->mode, (string)($this->state['inputTheme'] ?? 'keyboard'), (string)$game, ($this->state['infoDialog'] ?? null) === 'help');
		$first = max(0, min($help->last, (int)($this->state['helpScroll'] ?? 0)));
		$this->dialog($help->frame['box'], 'HOW TO PLAY · ' . GameText::title((string)$game));
		foreach($help->shown($first) as $item)
		{
			if($item['kind'] === 'card') { $this->card([$item['x'], $item['y'], $item['width'], $item['height']], $item['id'], $item['dim']); continue; }
			if($item['kind'] === 'text')
			{
				// Rows are whole pixels tall; a line set on a whole pixel keeps every row equally sharp.
				[$x, $y] = $this->layout->point($item['x'], $item['y']);
				$this->canvas->label($item['text'], $x, round($y), $item['size'], Theme::INK, $item['face'], $item['align']);
				continue;
			}
			[$x, $y, $w, $h] = $this->layout->rect($item['x'], $item['y'], $item['width'], $item['height']);
			// A rule is whole device pixels thick, however far the field is scaled down.
			if($item['kind'] === 'rule') { $this->canvas->box($x, round($y), $w, max(1, round($h)), 0, Theme::INK); continue; }
			// A key cap, drawn as the buttons draw theirs: an inverse square in Classic, a soft cap in Modern.
			if($this->mode === Theme::MODERN) $this->canvas->box($x, $y, $w, $h, 3 * $this->unit, [0,0,0,.12], [0,0,0,.35], 1 * $this->unit);
			else $this->canvas->box($x, $y, $w, $h, 0, Theme::INK);
			$this->canvas->label($item['text'], $x + $w / 2, $y + $h / 2, $item['size'], $this->mode === Theme::MODERN ? Theme::INK : Theme::PLATE, $this->face, 'center');
		}
		$this->helpScrollbar($help, $first);
		// Previous and Next turn to the game on either side, and name it where they are wide enough.
		$position = array_search($game, $games, true);
		$named = static fn(int $index, string $arrow, bool $leads): string => !isset($games[$index]) || !$help->frame['named'] ? $arrow
			: ($leads ? $arrow . ' ' . GameText::title($games[$index]) : GameText::title($games[$index]) . ' ' . $arrow);
		$this->button($help->frame['previous'], $named($position - 1, '←', true), '', 'default', $position === 0);
		$this->button($help->frame['back'], 'Back', '⎋', 'primary', false);
		$this->button($help->frame['next'], $named($position + 1, '→', false), '', 'default', $position === count($games) - 1);
	}

	/**
	 * The scrollbar beside the rules, as a text-mode window draws one: an arrow at each end, a shaded track between
	 * them and a solid thumb. Nothing is drawn when all the copy is in view.
	 */
	private function helpScrollbar(HelpPage $help, int $first): void
	{
		$thumb = $help->thumb($first);
		if($thumb === null) return;
		$classic = $this->mode === Theme::CLASSIC;
		$bar = $help->frame['bar'];
		[$x, $y, $w, $h] = array_map('round', $this->layout->rect(...$bar['track']));
		[, $thumbTop, , $thumbHeight] = array_map('round', $this->layout->rect(...$thumb));
		if($classic)
		{
			$this->canvas->box($x, $y, $w, $h, 0, Theme::BUTTON, Theme::INK, 1 * $this->unit);
			// The light shade of a DOS scrollbar: one cell in four inked, six cells across, each row stepped from the last.
			$cell = max(1, round($w / 6));
			$left = $x + ($w - 6 * $cell) / 2;
			for($row = 0; $row * $cell + $cell <= $h; $row++)
			{
				$top = $y + $row * $cell;
				if($top + $cell > $thumbTop && $top < $thumbTop + $thumbHeight) continue;
				for($column = $row % 2 * 2; $column < 6; $column += 4) $this->canvas->box($left + $column * $cell, $top, $cell, $cell, 0, Theme::INK);
			}
			$this->canvas->box($x, $thumbTop, $w, $thumbHeight, 0, Theme::INK);
		}
		else
		{
			$this->canvas->box($x, $y, $w, $h, $w / 2, [0,0,0,.1]);
			$this->canvas->box($x + 2 * $this->unit, $thumbTop, $w - 4 * $this->unit, $thumbHeight, $w / 2, [0,0,0,.5]);
		}
		foreach(['up' => ['↑', $first === 0], 'down' => ['↓', $first === $help->last]] as $end => [$arrow, $spent])
		{
			[$ax, $ay, $aw, $ah] = array_map('round', $this->layout->rect(...$bar[$end]));
			if($classic) $this->canvas->box($ax, $ay, $aw, $ah, 0, Theme::BUTTON, Theme::INK, 1 * $this->unit);
			$this->canvas->label($arrow, $ax + $aw / 2, $ay + $ah / 2, $this->layout->controlText(), Theme::alpha(Theme::INK, $spent ? .35 : 1), $this->face, 'center');
		}
	}

	private function settingsScreen(): void
	{
		$setup = $this->layout->setup(0, '');
		$box = $setup['box'];
		$this->dialog($box, 'SETTINGS');
		$box[0] += 24 * $this->layout->stretch() - 24;
		$this->text('Look', $box[0] + 24, $setup['variant'][1] + $setup['variant'][3] / 2, Theme::LABEL, Theme::INK);
		$this->button($setup['variant'], $this->mode === Theme::CLASSIC ? 'Classic' : 'Modern', 'M', 'default', false);
		$this->text('Pace', $box[0] + 24, $setup['pace'][1] + $setup['pace'][3] / 2, Theme::LABEL, Theme::INK);
		// Pace is the house players' thinking time; an online table has none, so the button is shown spent.
		$this->button($setup['pace'], ($this->view['pace'] ?? 'normal') === 'fast' ? 'Fast' : 'Normal', 'P', 'default',
			($this->view['screen'] ?? '') === 'table' && ($this->view['mode'] ?? 'offline') === 'online');
		$this->button($setup['back'], 'Back', '⎋', 'default', false);
	}

	/** @param array{float,float,float,float} $rect Design-space rectangle. */
	private function plate(array $rect, string $label, string $kind, bool $active): void
	{
		[$x, $y, $w, $h] = $this->layout->rect(...$rect);
		$fill = match($kind) {'fold' => Theme::PLATE_FOLD, 'hand' => Theme::PLATE_HAND, default => Theme::PLATE};
		$radius = Theme::radius($this->mode, 3 * $this->unit);
		if($this->mode === Theme::MODERN) $this->canvas->shadow($x, $y, $w, $h, $radius, 6 * $this->unit);
		$this->canvas->box($x, $y, $w, $h, $radius, $fill, $active ? Theme::BRASS : Theme::INK, ($active ? 2 : ($this->mode === Theme::MODERN ? 1 : 2)) * $this->unit);
		$this->canvas->label(strtoupper($label), $x + $w / 2, $y + $h / 2 + .5 * $this->unit, Theme::BODY, Theme::INK, $this->face, 'center', $w - 12 * $this->unit);
	}

	/** @param array{float,float,float,float} $rect Design-space rectangle. */
	private function card(array $rect, ?int $id, float $dim, bool $selected = false): void
	{
		[$x, $y, $w, $h] = $this->layout->rect(...$rect);
		$radius = Theme::radius($this->mode, $w * .07);
		if($this->mode === Theme::MODERN) $this->canvas->shadow($x, $y, $w, $h, $radius, 5 * $this->unit);
		$uv = $this->cardUv($id);
		if($uv !== null) $this->canvas->image($x, $y, $w, $h, $radius, $uv, [$dim, $dim, $dim, 1]);
		else
		{
			// Card art is still rasterising: a plain face with its index, or a flat back.
			$this->canvas->box($x, $y, $w, $h, $radius, $id === null ? [.725,.141,.176,1] : [1,1,1,$dim]);
			if($id !== null)
			{
				$suit = $id >= 52 ? -1 : intdiv($id, 13);
				$ink = $suit === 1 || $suit === 2 ? [.62,.12,.15,1] : Theme::INK;
				$label = $id >= 52 ? 'JOKER ' . ($id - 51) : (self::RANKS[$id % 13 + 2] ?? '?') . self::SUITS[$suit];
				$this->canvas->label($label, $x + 6 * $this->unit, $y + 12 * $this->unit, Theme::BODY, $ink, 'bold');
			}
		}
		$this->canvas->box($x, $y, $w, $h, $radius, [0,0,0,0], $selected ? Theme::BRASS : [0,0,0,.45], ($selected ? 2.5 : 1) * $this->unit);
	}

	/** @return array{float,float,float,float}|null */
	private function cardUv(?int $id): ?array
	{
		if($this->atlas === null) return null;
		$cell = $id === null ? (int)$this->atlas['backs']['red'] : ($id >= 52 ? $id
			: [2,3,1,0][intdiv($id, 13)] * 13 + ($id % 13 + 2 === 14 ? 0 : $id % 13 + 1));
		[$cw, $ch] = $this->atlas['cell'];
		$columns = (int)$this->atlas['columns'];
		$u = ($cell % $columns) * $cw / $this->atlas['width'];
		$v = intdiv($cell, $columns) * $ch / $this->atlas['height'];
		return [$u, $v, $u + $cw / $this->atlas['width'], $v + $ch / $this->atlas['height']];
	}

	/** Render every physical chip, using the same indexed positions as the animation endpoints. */
	private function chipStacks(int|string $owner, array $bank): void
	{
		ksort($bank);
		$chips = [];
		foreach($bank as $value => $count) for($index = 0; $index < $count; $index++)
		$chips[] = ['position' => $this->layout->chip($owner, count($this->view['players']), (int)$value, $index), 'value' => (int)$value];
		if($owner === 'pot') usort($chips, static fn(array $a, array $b): int => $a['position'][1] <=> $b['position'][1]);
		foreach($chips as $chip) $this->chip($chip['position'], $chip['value']);
	}

	private function chip(array $position, int $value): void
	{
		[$x, $y, $radius] = $position;
		$color = match($value) {
			1 => Theme::CHIP_GRAY, 5 => Theme::CHIP_RED, 25 => [.12,.58,.25,1], 100 => [.12,.12,.14,1],
			500 => [.55,.25,.7,1], 1000 => [.9,.65,.1,1], default => Theme::CHIP_BLUE
		};
		[$dx, $dy] = $this->layout->point($x, $y);
		$r = $radius * $this->unit;
		$ry = $r * .42;
		$this->canvas->ellipse($dx, $dy + 2.2 * $this->unit, $r, $ry, [0,0,0,.45]);
		$this->canvas->ellipse($dx, $dy, $r, $ry, $color, [0,0,0,.55], .8 * $this->unit);
		$this->canvas->ellipse($dx, $dy, $r * .8, $ry * .8, [0,0,0,0], [0,0,0,.35], .8 * $this->unit);
		$ink = $color === Theme::CHIP_GRAY ? [.13,.13,.13,1] : Theme::CHIP_INK;
		$label = $value >= 1000000 ? ($value / 1000000) . 'M' : ($value >= 1000 ? ($value / 1000) . 'K' : (string)$value);
		$this->canvas->label($label, $dx, $dy, $radius * .6, $ink, 'bold', 'center', $r * 1.6);
	}

	/** Whether an online seat shows its player's profile picture rather than a stand-in disc. */
	private function pictured(array $player): bool
	{
		return ($this->view['mode'] ?? 'offline') === 'online'
			&& isset($this->atlas['icons'][(string)($player['portrait'] ?? '')]);
	}

	private function emblem(float $x, float $y, int $seat, string $name, string $portrait): void
	{
		[$dx, $dy] = $this->layout->point($x, $y);
		$size = max(1, round($this->layout->metric('emblemSize') * $this->unit));
		$r = $size / 2;
		$online = ($this->view['mode'] ?? 'offline') === 'online';
		$icon = $this->atlas['icons'][$portrait] ?? null;
		if($this->mode === Theme::MODERN) $this->canvas->circle($dx, $dy + 2 * $this->unit, $r + 2 * $this->unit, [0,0,0,.16]);
		if($online && $icon !== null)
		{
			[$ix, $iy, $iw, $ih] = $icon;
			$this->canvas->image(round($dx - $size / 2), round($dy - $size / 2), $size, $size, 0
				, [$ix / $this->atlas['width'], $iy / $this->atlas['height'], ($ix + $iw) / $this->atlas['width'], ($iy + $ih) / $this->atlas['height']]);
			$this->canvas->circle($dx, $dy, $r, [0,0,0,0], Theme::BRASS, 2 * $this->unit);
			return;
		}
		if($seat === 0)
		{
			$this->canvas->circle($dx, $dy, $r, Theme::PLATE, Theme::BRASS, 3 * $this->unit);
			$this->canvas->label('YOU', $dx, $dy + .5 * $this->unit, Theme::CAPTION, Theme::INK, $this->face, 'center', $size - 4 * $this->unit);
			return;
		}
		if($online)
		{
			$label = $name !== '' ? $name : $portrait;
			$initial = preg_match('/^./us', $label, $match) === 1 ? $match[0] : '?';
			$this->canvas->circle($dx, $dy, $r, Theme::PLATE, Theme::BRASS, 3 * $this->unit);
			$this->canvas->label(strtoupper($initial), $dx, $dy + .5 * $this->unit, Theme::HEADING, Theme::INK, $this->face, 'center');
			return;
		}
		if($icon !== null)
		{
			// Emblems retain their 32x32 grid and are snapped to framebuffer pixels.
			[$ix, $iy, $iw, $ih] = $icon;
			$this->canvas->image(round($dx - $size / 2), round($dy - $size / 2), $size, $size, 0
				, [$ix / $this->atlas['width'], $iy / $this->atlas['height'], ($ix + $iw) / $this->atlas['width'], ($iy + $ih) / $this->atlas['height']]);
			return;
		}
		// Before the atlas arrives, a coloured disc with the player's initial stands in for the emblem.
		$this->canvas->circle($dx, $dy, $r, self::EMBLEM_COLORS[$seat % 8], [0,0,0,.4], 1 * $this->unit);
		$label = $name !== '' ? $name : $portrait;
		$initial = preg_match('/^./us', $label, $match) === 1 ? $match[0] : '?';
		$this->canvas->label(strtoupper($initial), $dx, $dy + .5 * $this->unit, Theme::HEADING, Theme::EMBLEM, $this->face, 'center');
	}

	/** @param array{float,float,float,float} $rect Design-space rectangle. */
	private function button(array $rect, string $label, string $key, string $style, bool $dim): void
	{
		[$x, $y, $w, $h] = $this->layout->rect(...$rect);
		$radius = Theme::radius($this->mode, 4 * $this->unit);
		if($this->mode === Theme::MODERN) $this->canvas->shadow($x, $y, $w, $h, $radius, 6 * $this->unit);
		$fill = $style === 'primary' ? Theme::BRASS : Theme::BUTTON;
		$this->canvas->box($x, $y, $w, $h, $radius, $fill, Theme::INK, 1 * $this->unit);
		if($this->mode === Theme::CLASSIC)
		{
			$this->canvas->box($x + 1 * $this->unit, $y + 1 * $this->unit, $w - 2 * $this->unit, 1 * $this->unit, 0, [1,1,1,1]);
			$this->canvas->box($x + 1 * $this->unit, $y + 1 * $this->unit, 1 * $this->unit, $h - 2 * $this->unit, 0, [1,1,1,1]);
			$this->canvas->box($x + 1 * $this->unit, $y + $h - 2 * $this->unit, $w - 2 * $this->unit, 1 * $this->unit, 0, [.47,.47,.47,1]);
			$this->canvas->box($x + $w - 2 * $this->unit, $y + 1 * $this->unit, 1 * $this->unit, $h - 2 * $this->unit, 0, [.47,.47,.47,1]);
		}
		$ink = $style === 'danger' ? Theme::DANGER_INK : Theme::INK;
		if($dim) $ink = Theme::alpha($ink, .45);
		if(in_array($label, ['+', '−', '-'], true))
		{
			$length = 12 * $this->layout->dpr;
			$stroke = 2 * $this->layout->dpr;
			$this->canvas->box($x + ($w - $length) / 2, $y + ($h - $stroke) / 2, $length, $stroke, 0, $ink);
			if($label === '+') $this->canvas->box($x + ($w - $stroke) / 2, $y + ($h - $length) / 2, $stroke, $length, 0, $ink);
			return;
		}
		if($this->controller()) $key = $this->focused($rect) ? $this->confirmLegend() : '';
		$key = $this->layout->keyHintFits($w) ? match($key) {'↵' => 'ENT', '⎋' => 'ESC', default => $key} : '';
		$hintWidth = strlen($key) > 1 ? 28 : 18;
		$kw = $hintWidth * $this->unit;
		$kx = $x + $w - $kw - 7 * $this->unit;
		$labelText = strtoupper($label);
		$labelSize = $this->layout->controlText();
		$labelX = $x + $w / 2;
		$labelMax = max(1, $w - 16 * $this->unit);
		// The label is the control; its key legend is a courtesy. Where both cannot be shown whole, the legend goes.
		if($key !== '' && $this->canvas->measure($labelText, $labelSize, $this->face) > $kx - 6 * $this->unit - ($x + 8 * $this->unit)) $key = '';
		if($key !== '')
		{
			// Keep short labels centred on the whole button. Reserve the keycap's left margin only when the
			// centred text would actually enter it, then move the label by the minimum distance needed to clear it.
			$clearRight = $kx - 6 * $this->unit;
			$measured = min($this->canvas->measure($labelText, $labelSize, $this->face), $labelMax);
			if($labelX + $measured / 2 > $clearRight)
			{
				$labelMax = max(1, $clearRight - ($x + 8 * $this->unit));
				$measured = min($this->canvas->measure($labelText, $labelSize, $this->face), $labelMax);
				$labelX = min($labelX, $clearRight - $measured / 2);
			}
		}
		$this->canvas->label($labelText, $labelX, $y + $h / 2 + ($this->mode === Theme::MODERN ? .5 : 0) * $this->unit, $labelSize, $ink, $this->face, 'center', $labelMax);
		if($key !== '')
		{
			// Key hint: a soft keycap in Modern, an inverse square in Classic as a DOS accelerator would be shown.
			$ky = $y + $h / 2 - 9 * $this->unit;
			if($this->mode === Theme::MODERN)
			{
				$this->canvas->box($kx, $ky, $kw, 18 * $this->unit, 3 * $this->unit, [0,0,0,.12], [0,0,0,.35], 1 * $this->unit);
				$this->canvas->label($key, $kx + $kw / 2, $ky + 9 * $this->unit, 11, $ink, 'bold', 'center');
			}
			else
			{
				$this->canvas->box($kx, $ky, $kw, 18 * $this->unit, 0, Theme::INK);
				$this->canvas->label($key, $kx + $kw / 2, $ky + 9 * $this->unit, Theme::BODY, $style === 'primary' ? Theme::BRASS : Theme::PLATE, $this->face, 'center');
			}
		}
	}

	/** Checkbox row with one left-aligned field; the complete row remains the shared menu hit target. */
	private function checkbox(array $rect, string $label, string $key, bool $checked, array $ink = Theme::INK, bool $compact = false): void
	{
		[$x, $y, $w, $h] = $this->layout->rect(...$rect);
		$size = min($h - ($compact ? 18 : 10) * $this->unit, ($compact ? 19 : 28) * $this->unit);
		$cx = $x + ($compact ? 2 : 10) * $this->unit;
		$cy = $y + ($h - $size) / 2;
		$radius = Theme::radius($this->mode, 2 * $this->unit);
		$this->canvas->box($cx, $cy, $size, $size, $radius, Theme::BUTTON, Theme::INK, 1 * $this->unit);
		if($this->mode === Theme::CLASSIC)
		{
			$this->canvas->box($cx + $this->unit, $cy + $this->unit, $size - 2 * $this->unit, $this->unit, 0, [1,1,1,1]);
			$this->canvas->box($cx + $this->unit, $cy + $this->unit, $this->unit, $size - 2 * $this->unit, 0, [1,1,1,1]);
		}
		if($checked)
		{
			$inset = ($compact ? 4 : 6) * $this->unit;
			$this->canvas->box($cx + $inset, $cy + $inset, $size - 2 * $inset, $size - 2 * $inset,
				Theme::radius($this->mode, $this->unit), Theme::INK);
		}
		$labelX = $cx + $size + ($compact ? 3 : 10) * $this->unit;
		$right = $x + $w - ($compact ? 1 : 10) * $this->unit;
		if($this->controller()) $key = $this->focused($rect) ? $this->confirmLegend() : '';
		$key = $this->layout->keyHintFits($w) ? $key : '';
		if($key !== '')
		{
			$key = match($key) {'↵' => 'ENT', '⎋' => 'ESC', default => $key};
			$kw = (strlen($key) > 1 ? 28 : 18) * $this->unit;
			$kx = $right - $kw;
			$ky = $y + $h / 2 - 9 * $this->unit;
			$this->canvas->box($kx, $ky, $kw, 18 * $this->unit, Theme::radius($this->mode, 3 * $this->unit), Theme::INK);
			$this->canvas->label($key, $kx + $kw / 2, $ky + 9 * $this->unit, Theme::BODY, Theme::DIALOG, $this->face, 'center');
			$right = $kx - 8 * $this->unit;
		}
		if($label !== '') $this->canvas->label($this->mode === Theme::CLASSIC ? strtoupper($label) : $label, $labelX, $y + $h / 2,
			$this->layout->controlText(), $ink, $this->face, 'left', max(1, $right - $labelX));
	}

	private function controller(): bool { return ($this->state['inputTheme'] ?? 'keyboard') !== 'keyboard'; }
	private function confirmLegend(): string { return ($this->state['inputTheme'] ?? '') === 'playstation' ? 'X' : 'A'; }
	private function backLegend(): string { return ($this->state['inputTheme'] ?? '') === 'playstation' ? 'O' : 'B'; }

	/** Whether this design-space rectangle is the live controller focus target. */
	private function focused(array $rect): bool
	{
		$focus = $this->state['controllerFocus']['rect'] ?? null;
		if(!is_array($focus) || count($focus) !== 4) return false;
		foreach($rect as $index => $value) if(abs((float)$value - (float)$focus[$index]) > 1e-6) return false;
		return true;
	}

	/** Draw one clear focus frame after every screen so cards, steppers and buttons share the same navigation cue. */
	private function controllerFocus(): void
	{
		$rect = $this->state['controllerFocus']['rect'] ?? null;
		if(!$this->controller() || !is_array($rect) || count($rect) !== 4) return;
		[$x, $y, $w, $h] = $this->layout->rect($rect[0] - 4, $rect[1] - 4, $rect[2] + 8, $rect[3] + 8);
		$radius = Theme::radius($this->mode, 6 * $this->unit);
		$this->canvas->box($x, $y, $w, $h, $radius, [0,0,0,0], [0,0,0,.72], 5 * $this->unit);
		$this->canvas->box($x, $y, $w, $h, $radius, [0,0,0,0], Theme::BRASS, 3 * $this->unit);
	}

	/** @param array{float,float,float,float} $rect Design-space rectangle. */
	private function dialog(array $rect, string $title): void
	{
		[$x, $y, $w, $h] = $this->layout->rect(...$rect);
		$radius = Theme::radius($this->mode, 6 * $this->unit);
		if($this->mode === Theme::MODERN) $this->canvas->shadow($x, $y, $w, $h, $radius, 18 * $this->unit, [0,0,0,.32]);
		$this->canvas->box($x, $y, $w, $h, $radius, Theme::DIALOG, Theme::INK, 1 * $this->unit);
		// Double rule along the top with the title set into it, as the DOS dialogs were drawn.
		$this->canvas->box($x + 6 * $this->unit, $y + 5 * $this->unit, $w - 12 * $this->unit, 1 * $this->unit, 0, Theme::INK);
		$this->canvas->box($x + 6 * $this->unit, $y + 8 * $this->unit, $w - 12 * $this->unit, 1 * $this->unit, 0, Theme::INK);
		$width = $this->canvas->measure($title, Theme::BODY, $this->face) + 16 * $this->unit;
		$this->canvas->box($x + $w / 2 - $width / 2, $y, $width, 14 * $this->unit, 0, Theme::DIALOG);
		$this->canvas->label($title, $x + $w / 2, $y + 7 * $this->unit, Theme::BODY, Theme::INK, $this->face, 'center');
	}

	private function text(string $text, float $x, float $y, float $size, array $color, string $align = 'left', ?float $maxWidth = null): void
	{
		[$dx, $dy] = $this->layout->point($x, $y);
		$this->canvas->label($this->mode === Theme::CLASSIC ? strtoupper($text) : $text, $dx, $dy, $size, $color, $this->face, $align, $maxWidth === null ? null : $maxWidth * $this->horizontalUnit);
	}

	/** Draw a fixed-width three-dot suffix, revealing only the current presentation cadence. */
	private function drummingText(string $text, float $x, float $y, float $size, array $color, string $align = 'left'): void
	{
		$dots = !empty($this->state['networkWait']['active']) ? (int)($this->state['networkWait']['dots'] ?? 0) : 0;
		if(empty($this->state['networkWait']['active'])) { $this->text($text, $x, $y, $size, $color, $align); return; }
		$text = $this->mode === Theme::CLASSIC ? strtoupper($text) : $text;
		[$dx, $dy] = $this->layout->point($x, $y);
		$fullWidth = $this->canvas->measure($text . '...', $size, $this->face);
		$left = match($align) { 'center' => $dx - $fullWidth / 2, 'right' => $dx - $fullWidth, default => $dx };
		$offset = $this->canvas->label($text, $left, $dy, $size, $color, $this->face);
		for($index = 0; $index < 3; $index++)
		$offset += $this->canvas->label('.', $left + $offset, $dy, $size, Theme::alpha($color, $index < $dots ? 1 : 0), $this->face);
	}
}
