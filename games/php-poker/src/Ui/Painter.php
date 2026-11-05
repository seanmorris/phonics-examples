<?php
declare(strict_types=1);
namespace PhpPoker\Ui;

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
	private const SUITS = ['♣','♦','♥','♠'];
	private const RANKS = [2 => '2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K', 'A'];
	private const EMBLEM_COLORS = [[.42,.33,.5,1],[.18,.37,.48,1],[.48,.29,.17,1],[.25,.42,.25,1],[.54,.35,.12,1],[.29,.29,.48,1],[.48,.18,.22,1],[.2,.42,.36,1]];

	private readonly string $mode;
	private readonly string $face;
	private readonly float $unit;

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
		if($screen === 'menu') { $this->menuScreen(); return; }
		if($screen === 'help') { $this->helpScreen(); return; }
		if($screen === 'settings') { $this->settingsScreen(); return; }
		$this->menuBar();
		$this->table();
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
		$bar = $this->layout->rect(0, 0, $this->layout->width(), Layout::MENU_BAR);
		[$left, $top, $width, $height] = $bar;
		$this->canvas->box(0, $top, $this->layout->left * 2 + $width, $height, 0, Theme::MENU);
		$x = $left + 14 * $this->unit;
		foreach(['GAME','POKER','DRAW POKER'] as $entry)
		$x += $this->canvas->label($entry, $x, $top + $height / 2, Theme::LABEL, Theme::MENU_TEXT, $this->face) + 22 * $this->unit;
		$stage = $this->state['motion']['kind'] ?? '';
		$phase = match(in_array($stage, ['deal','draw'], true) ? $stage : ($this->view['phase'] ?? '')) {
			'deal' => 'DEALING', 'betting' => 'OPENING BETS', 'draw' => 'DRAW', 'final-betting' => 'FINAL BETS',
			'showdown' => 'HAND OVER', 'redeal' => 'REDEAL', default => 'DRAW POKER'
		};
		$this->canvas->label($phase, $left + $width - 14 * $this->unit, $top + $height / 2, Theme::LABEL, Theme::MENU_TEXT, $this->face, 'right');
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
			foreach($geometry[$seat]['cards'] as $index => $rect)
			{
				if(!array_key_exists($index, $player['cards'] ?? [])) break;
				$this->card($rect, $player['cards'][$index], $dim);
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
				[$tx, $ty] = $this->layout->caption($seat, $seats, $bank);
				$this->text((string)$total, $tx, $ty, $this->layout->captionText(), Theme::PLATE, 'center');
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
					$hand = $geometry[$seat]['cards'][4];
					$left = $this->layout->profile === 'landscape' ? $hand[0] + $hand[2] + 6 : 8;
					$width = $this->canvas->measure($this->mode === Theme::CLASSIC ? strtoupper($label) : $label, $size, $this->face) / $this->unit;
					$size *= min(1, ($bx - 10 - $left) / $width);
					$this->text($label, $bx - 10, $geometry[$seat]['chips'][1] + 10, $size, Theme::PLATE, 'right');
				}
				// The plate already says what an opponent did; the running bet is spelled out only where the wide field has room.
				elseif($this->layout->profile === 'wide') $this->text('Bet ' . $player['bet'], $bx + 16, $geometry[$seat]['chips'][1] + 10, $this->layout->captionText(), Theme::PLATE, 'center');
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
				$this->text((string)($player['name'] ?? ''), $tx, $ty, $size, Theme::PLATE, 'center', $width);
				$this->text((string)$total, $tx, $ty + $size + 2, $size, Theme::PLATE, 'center', $width);
			}
			if(($this->view['dealer'] ?? -1) === $seat && $phase !== 'ready')
			{
				[$px, $py, $pw, $ph] = $geometry[$seat]['plate'];
				$dealerX = $seat !== 0 && $geometry[$seat]['outward'] > 0 ? $px - 14 : $px + $pw + 14;
				$dealerY = $py + $ph / 2;
				// Column seats have the pot beside the plate, so the marker rides the emblem's outer corner instead.
				if($seat !== 0 && $this->layout->columns()) [$dealerX, $dealerY] = [$x + $geometry[$seat]['outward'] * 10, $y - 13];
				[$dx, $dy] = $this->layout->point($dealerX, $dealerY);
				$this->canvas->circle($dx, $dy, 9 * $this->unit, Theme::PLATE, Theme::INK, 1 * $this->unit);
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
		if($phase === 'ready') $this->welcomeDialog();
	}

	private function plateText(array $player, int $seat, bool $showdown): string
	{
		if($player['folded']) return 'Fold';
		if($showdown)
		{
			if(in_array($seat, array_column($this->view['awards'] ?? [], 'seat'), true)) return $player['handName'] ?: 'Winner';
			return $player['handName'] ? (string)$player['handName'] : ($seat === 0 ? 'Your hand' : '');
		}
		if($seat === 0) return ($this->view['turn'] ?? -1) === 0 ? 'Your turn' : ($player['lastAction'] ?: 'You');
		if(($this->view['turn'] ?? -1) === $seat) return 'Thinking';
		return (string)($player['lastAction'] ?: '');
	}

	private function centre(): void
	{
		if(($this->view['phase'] ?? 'ready') === 'ready') return;
		$centre = $this->layout->centre();
		$pot = ($this->state['motion']['banks'] ?? $this->state['chips'])['pot'] ?? [];
		$this->plate($centre['pot'], 'Total pot (' . Chips::value($pot) . ')', 'info', false);
		$this->card($centre['deck'], null, 1.0);
		if(($this->state['motion']['discardCount'] ?? $this->view['discardCount'] ?? 0) > 0)
		{
			$this->card($centre['discard'], null, .8);
			// On a phone the loose pot sits right above the piles, so the discards go unlabelled there.
			if($this->layout->profile !== 'tall') $this->text('Discards', $centre['discard'][0] + $centre['discard'][2] / 2, $centre['discard'][1] - 9, $this->layout->captionText(), Theme::PLATE, 'center');
		}
		$this->chipStacks('pot', $pot);
		$highest = 0;
		foreach($this->view['players'] ?? [] as $player) $highest = max($highest, (int)($player['bet'] ?? 0));
		$this->plate($centre['bet'], 'Current bet (' . $highest . ')', 'info', false);
	}

	private function actions(): void
	{
		foreach($this->layout->actions($this->view, $this->state) as $button)
		$this->button($button['rect'], $button['label'], $button['key'], $button['style'], $this->state['locked'] && $button['intent'] !== null);

		if($stepper = $this->layout->stepper($this->view))
		{
			$this->button($stepper['less'], '−', '', 'default', false);
			$this->plate($stepper['amount'], (string)$this->state['amount'], 'info', false);
			$this->button($stepper['more'], '+', '', 'default', false);
			[$lx, $ly] = $this->layout->point(...$stepper['label']);
			$this->canvas->label(strtoupper((string)($this->view['legalActions']['betLabel'] ?? 'Bet')), $lx, $ly, $this->layout->captionText(), Theme::PLATE, $this->face, $stepper['align']);
		}
		if($this->state['locked'] && ($this->view['turn'] ?? -1) === 0)
		{
			[$ax, $ay] = $this->layout->metric('actions');
			[$x, $y] = $this->layout->point($ax, $ay + $this->layout->metric('buttonHeight') + 18);
			$this->canvas->label('WAITING FOR THE TABLE', $x, $y, Theme::CAPTION, Theme::PLATE, $this->face);
		}
	}

	private function messages(): void
	{
		$error = (string)($this->view['error'] ?? '');
		$message = (string)($this->state['hint'] ?? $this->view['message'] ?? '');
		$line = $error !== '' ? $error : $message;
		if($line === '') return;
		[$betX, $betY, $betWidth, $betHeight] = $this->layout->centre()['bet'];
		$width = $this->layout->profile === 'wide'
			? min($this->layout->width() - 24, max(240, $this->canvas->measure(strtoupper($line), Theme::BODY, $this->face) / $this->unit + 40)) : min(180, $betWidth);
		$lines = $this->canvas->text->wrap(strtoupper($line), Theme::BODY * $this->unit, ($width - 12) * $this->unit, 2, $this->face);
		$top = $betY + $betHeight + 8;
		$this->plate([$betX + ($betWidth - $width) / 2, $top, $width, Layout::PLATE_HEIGHT + 16 * (count($lines) - 1)], '', $error !== '' ? 'fold' : 'info', false);
		foreach($lines as $index => $text)
		$this->text($text, $betX + $betWidth / 2, $top + Layout::PLATE_HEIGHT / 2 + $index * 16 + .5, Theme::BODY, Theme::INK, 'center');
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
		if($room >= 160) $this->text('Select cards, then press Draw.', $rowX, $rowY + $rowHeight / 2, $this->layout->captionText(), Theme::INK, 'left', $room);
	}

	private function historyDialog(): void
	{
		$w = min(640, $this->layout->width() - 24);
		$control = $this->layout->controlSize();
		$box = [$this->layout->width() / 2 - $w / 2, 50, $w, min($this->layout->height() - 62, 260 + $control)];
		$this->dialog($box, 'ROUND HISTORY');
		$lines = array_slice($this->view['history'] ?? [], -8);
		foreach($lines as $index => $line)
		$this->text((string)$line, $box[0] + 24, $box[1] + 44 + $index * 22, Theme::BODY, Theme::INK, 'left', $box[2] - 48);
		$this->button([$box[0] + $box[2] / 2 - 70, $box[1] + $box[3] - $control - 12, 140, $control], 'OK', '↵', 'primary', false);
	}

	private function welcomeDialog(): void
	{
		$w = min(440, $this->layout->width() - 24);
		$box = [$this->layout->width() / 2 - $w / 2, 250, $w, 120];
		$this->dialog($box, 'DRAW POKER');
		$variant = ($this->view['profile']['variant'] ?? 'jackpots') === 'anything' ? 'Anything opens' : 'Jacks or better';
		$this->text('Welcomes you to a game of', $this->layout->width() / 2, $box[1] + 50, Theme::LABEL, Theme::INK, 'center');
		$this->text('5-CARD DRAW POKER  (' . strtolower($variant) . ')', $this->layout->width() / 2, $box[1] + 80, Theme::LABEL, Theme::INK, 'center');
	}

	private function menuScreen(): void
	{
		$this->text('DRAW POKER', $this->layout->width() / 2, $this->layout->profile === 'landscape' ? 65 : 170, (float)$this->layout->metric('display'), Theme::PLATE, 'center');
		$this->text('Five-card draw · jacks or better · 2 to 8 seats', $this->layout->width() / 2, $this->layout->profile === 'landscape' ? 105 : 215, Theme::LABEL, Theme::PLATE, 'center');
		$local = $this->state['local'];
		if($local === 'setup') { $this->setupDialog(); return; }
		if($local === 'online')
		{
			$w = min(440, $this->layout->width() - 24);
			$box = [$this->layout->width() / 2 - $w / 2, $this->layout->profile === 'landscape' ? 165 : 280, $w, 120];
			$this->dialog($box, 'PLAY ONLINE');
			$this->text('Private tables are coming in a later release.', $this->layout->width() / 2, $box[1] + 52, Theme::BODY, Theme::INK, 'center');
			$this->text('Press Escape to go back.', $this->layout->width() / 2, $box[1] + 82, Theme::BODY, Theme::INK, 'center');
			return;
		}
		foreach($this->layout->menu(!empty($this->view['canContinue'])) as $index => $item)
		$this->button($item['rect'], $item['label'], '', $index === 0 ? 'primary' : 'default', false);
		$short = $this->layout->profile === 'landscape';
		$this->text('Enter plays · C continues · H help · M settings', $short ? 16 : $this->layout->width() / 2, $this->layout->height() - ($short ? 18 : 100), Theme::CAPTION, Theme::PLATE, $short ? 'left' : 'center');
		// Keep the artists' short credit under the hints, or at the right edge where a short landscape has no room.
		$landscape = $this->layout->profile === 'landscape';
		$this->text('Icon art: Clint Bellanger · thekingphoenix · 7Soul', $landscape ? $this->layout->width() - 16 : $this->layout->width() / 2
			, $this->layout->height() - ($landscape ? 18 : 72), Theme::CAPTION, Theme::alpha(Theme::PLATE, .8), $landscape ? 'right' : 'center');
	}

	private function setupDialog(): void
	{
		$players = (int)$this->state['setup']['players'];
		$variant = (string)$this->state['setup']['variant'];
		$setup = $this->layout->setup($players, $variant);
		$this->dialog($setup['box'], 'TABLE SETUP');
		[$x, $y] = $setup['box'];
		$this->text('Opponents', $x + 24, $setup['less'][1] + $setup['less'][3] / 2, Theme::LABEL, Theme::INK);
		$this->button($setup['less'], '−', '', 'default', $players <= 2);
		$this->plate($setup['count'], (string)($players - 1), 'info', false);
		$this->button($setup['more'], '+', '', 'default', $players >= 8);
		$this->text('Opening', $x + 24, $setup['variant'][1] + $setup['variant'][3] / 2, Theme::LABEL, Theme::INK);
		$this->button($setup['variant'], $variant === 'jackpots' ? 'Jacks or better' : 'Anything opens', 'V', 'default', false);
		$this->button($setup['back'], 'Back', '⎋', 'default', false);
		$this->button($setup['start'], 'Deal', '↵', 'primary', false);
	}

	private function helpScreen(): void
	{
		$info = $this->layout->information();
		$box = $info['box'];
		$this->dialog($box, 'HOW TO PLAY');
		$paragraphs = [
			'The dealer antes. Everyone receives five cards face down.',
			'Jacks or better: a pair of jacks or higher is needed to open. Anything opens: any hand may open.',
			'Betting starts left of the dealer. Fold, pass or check, call, or raise.',
			'Draw: tap cards to select discards. Press Draw to replace them, or Stand pat to keep all five.',
			'Final betting starts with the opener. The best remaining hand wins the pot.',
			'Best to worst: straight flush, four of a kind, full house, flush, straight, three of a kind, two pair, pair, high card.',
			'Keys: F fold, C call/check/pass, B bet/raise, + and - change the amount.',
			'1 to 5 select cards, D draws, H shows history, Escape opens the menu.'
		];
		$columns = $this->layout->profile === 'landscape' ? 2 : 1;
		$columnWidth = ($box[2] - 48 - ($columns - 1) * 24) / $columns;
		$size = $this->layout->controlText();
		$leading = $size * 1.45;
		$y = $box[1] + 36;
		foreach($paragraphs as $index => $paragraph)
		{
			$column = $columns === 2 ? intdiv($index, 4) : 0;
			if($columns === 2 && $index === 4) $y = $box[1] + 36;
			$x = $box[0] + 24 + $column * ($columnWidth + 24);
			$line = '';
			foreach(explode(' ', strtoupper($paragraph)) as $word)
			{
				$next = $line === '' ? $word : "$line $word";
				if($line !== '' && $this->canvas->measure($next, $size, $this->face) > $columnWidth * $this->unit)
				{ $this->text($line, $x, $y, $size, Theme::INK); $y += $leading; $line = $word; }
				else $line = $next;
			}
			$this->text($line, $x, $y, $size, Theme::INK);
			$y += $leading + 8;
		}
		$this->button($info['back'], 'Back', '⎋', 'primary', false);
	}

	private function settingsScreen(): void
	{
		$setup = $this->layout->setup(0, '');
		$box = $setup['box'];
		$this->dialog($box, 'SETTINGS');
		$this->text('Look', $box[0] + 24, $setup['variant'][1] + $setup['variant'][3] / 2, Theme::LABEL, Theme::INK);
		$this->button($setup['variant'], $this->mode === Theme::CLASSIC ? 'Classic' : 'Modern', 'M', 'default', false);
		$this->text('Pace', $box[0] + 24, $setup['pace'][1] + $setup['pace'][3] / 2, Theme::LABEL, Theme::INK);
		$this->button($setup['pace'], ($this->view['pace'] ?? 'normal') === 'fast' ? 'Fast' : 'Normal', 'P', 'default', false);
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
				$suit = intdiv($id, 13);
				$ink = $suit === 1 || $suit === 2 ? [.62,.12,.15,1] : Theme::INK;
				$this->canvas->label((self::RANKS[$id % 13 + 2] ?? '?') . self::SUITS[$suit], $x + 6 * $this->unit, $y + 12 * $this->unit, Theme::BODY, $ink, 'bold');
			}
		}
		$this->canvas->box($x, $y, $w, $h, $radius, [0,0,0,0], $selected ? Theme::BRASS : [0,0,0,.45], ($selected ? 2.5 : 1) * $this->unit);
	}

	/** @return array{float,float,float,float}|null */
	private function cardUv(?int $id): ?array
	{
		if($this->atlas === null) return null;
		$cell = $id === null ? (int)$this->atlas['backs']['red'] : [2,3,1,0][intdiv($id, 13)] * 13 + ($id % 13 + 2 === 14 ? 0 : $id % 13 + 1);
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

	private function emblem(float $x, float $y, int $seat, string $name, string $portrait): void
	{
		[$dx, $dy] = $this->layout->point($x, $y);
		$size = max(1, round($this->layout->metric('emblemSize') * $this->unit));
		$r = $size / 2;
		if($this->mode === Theme::MODERN) $this->canvas->circle($dx, $dy + 2 * $this->unit, $r + 2 * $this->unit, [0,0,0,.16]);
		if($seat === 0)
		{
			$this->canvas->circle($dx, $dy, $r, Theme::PLATE, Theme::BRASS, 3 * $this->unit);
			$this->canvas->label('YOU', $dx, $dy + .5 * $this->unit, Theme::CAPTION, Theme::INK, $this->face, 'center', $size - 4 * $this->unit);
			return;
		}
		$icon = $this->atlas['icons'][$portrait] ?? null;
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
		$key = $w >= 96 * $this->layout->dpr ? match($key) {'↵' => 'ENT', '⎋' => 'ESC', default => $key} : '';
		$hintWidth = strlen($key) > 1 ? 28 : 18;
		$shift = $key !== '' ? -$hintWidth * $this->unit / 2 : 0;
		$this->canvas->label(strtoupper($label), $x + $w / 2 + $shift, $y + $h / 2 + ($this->mode === Theme::MODERN ? .5 : 0) * $this->unit, $this->layout->controlText(), $ink, $this->face, 'center', $w - ($key !== '' ? $hintWidth + 12 : 8) * $this->unit);
		if($key !== '')
		{
			// Key hint: a soft keycap in Modern, an inverse square in Classic as a DOS accelerator would be shown.
			$kw = $hintWidth * $this->unit;
			$kx = $x + $w - $kw - 7 * $this->unit;
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
		$this->canvas->label($this->mode === Theme::CLASSIC ? strtoupper($text) : $text, $dx, $dy, $size, $color, $this->face, $align, $maxWidth === null ? null : $maxWidth * $this->unit);
	}
}
