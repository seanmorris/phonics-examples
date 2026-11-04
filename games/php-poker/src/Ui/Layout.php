<?php
declare(strict_types=1);
namespace PhpPoker\Ui;

/**
 * Pure table layout: where every seat, card, chip stack, plate, button and dialog sits for one frame.
 *
 * The design is authored on a 1280x720 field that scales uniformly to fit the canvas and is centred, so the
 * same oval, plates and dialogs appear at every size. All results are in device pixels. Rendering and hit
 * testing share this so a region a player can click is exactly the region that is drawn.
 */
final class Layout
{
	public const WIDTH = 1280;
	public const HEIGHT = 720;
	public const MENU_BAR = 28;
	/** Seats sit on this oval around the field centre, the human at the bottom. */
	public const OVAL = [470,250];
	public const CENTRE = [640,378];
	public const CARD = [68,95];
	public const CARD_STEP = 30;
	public const HAND_CARD = [90,126];
	public const HAND_STEP = 38;
	public const DIALOG_CARD = [124,174];
	/** Emblems sit inside the oval, between each seat and the table centre. */
	public const EMBLEM_INSET = [175,115];
	public const PLATE_HEIGHT = 24;

	/**
	 * @param float $scale Device pixels per design pixel.
	 * @param float $left Device x of the field's left edge.
	 * @param float $top Device y of the field's top edge.
	 */
	private function __construct(public readonly float $scale, public readonly float $left, public readonly float $top) {}

	/**
	 * Fit the design field to a canvas.
	 *
	 * @param int $width Canvas width in device pixels.
	 * @param int $height Canvas height in device pixels.
	 */
	public static function fit(int $width, int $height): self
	{
		$scale = max(1e-6, min($width / self::WIDTH, $height / self::HEIGHT));
		return new self($scale, ($width - self::WIDTH * $scale) / 2, ($height - self::HEIGHT * $scale) / 2);
	}

	/**
	 * Convert a design-space rectangle to device pixels.
	 *
	 * @param float $x Design left.
	 * @param float $y Design top.
	 * @param float $width Design width.
	 * @param float $height Design height.
	 * @return array{float,float,float,float}
	 */
	public function rect(float $x, float $y, float $width, float $height): array
	{
		return [$this->left + $x * $this->scale, $this->top + $y * $this->scale, $width * $this->scale, $height * $this->scale];
	}

	/**
	 * Convert a design-space point to device pixels.
	 *
	 * @param float $x Design x.
	 * @param float $y Design y.
	 * @return array{float,float}
	 */
	public function point(float $x, float $y): array
	{
		return [$this->left + $x * $this->scale, $this->top + $y * $this->scale];
	}

	/**
	 * Convert a device pixel to design space.
	 *
	 * @param float $x Device x.
	 * @param float $y Device y.
	 * @return array{float,float}
	 */
	public function design(float $x, float $y): array
	{
		return [($x - $this->left) / $this->scale, ($y - $this->top) / $this->scale];
	}

	/**
	 * Whole-field rectangle, which the felt fills past the design edges when the aspect differs.
	 *
	 * @param int $width Canvas width in device pixels.
	 * @param int $height Canvas height in device pixels.
	 * @return array{float,float,float,float}
	 */
	public static function field(int $width, int $height): array { return [0,0,$width,$height]; }

	/**
	 * Design-space geometry of one seat: plate, fan, chip anchor and emblem.
	 *
	 * @param int $seat Seat id; 0 is the human.
	 * @param int $seats Number of seats at the table.
	 * @return array{plate:array{float,float,float,float},cards:list<array{float,float,float,float}>,chips:array{float,float},emblem:array{float,float},angle:float}
	 */
	public static function seat(int $seat, int $seats): array
	{
		$angle = self::seatAngle($seat, $seats);
		$ux = cos($angle);
		$uy = sin($angle);
		[$cx,$cy] = [self::CENTRE[0] + $ux * self::OVAL[0], self::CENTRE[1] + $uy * self::OVAL[1]];
		$human = $seat === 0;
		[$cardWidth,$cardHeight] = $human ? self::HAND_CARD : self::CARD;
		$step = $human ? self::HAND_STEP : self::CARD_STEP;
		$plateWidth = $human ? 250 : 200;
		$plate = [$cx - $plateWidth / 2, $cy - ($human ? 90 : 62), $plateWidth, self::PLATE_HEIGHT];
		$fanLeft = $plate[0] + ($plateWidth - ($cardWidth + 4 * $step)) / 2;
		$fanTop = $plate[1] + self::PLATE_HEIGHT + 8;
		$cards = [];
		for($index = 0; $index < 5; $index++) $cards[] = [$fanLeft + $index * $step, $fanTop, $cardWidth, $cardHeight];
		$outward = $ux > .3 ? 1 : -1;
		// Chips stand in front of the fan's outer corner; the human's stand beside the hand so they stay on screen.
		$chips = $human ? [$plate[0] - 70, $fanTop + $cardHeight - 44] : [$outward > 0 ? $plate[0] + $plateWidth - 56 : $plate[0] - 4, $fanTop + $cardHeight + 4];
		$emblem = $human ? [$plate[0] - 46, $plate[1] + 8] : [self::CENTRE[0] + $ux * (self::OVAL[0] - self::EMBLEM_INSET[0]), self::CENTRE[1] + $uy * (self::OVAL[1] - self::EMBLEM_INSET[1])];

		return ['plate' => $plate, 'cards' => $cards, 'chips' => $chips, 'emblem' => $emblem, 'angle' => $angle, 'outward' => $outward];
	}

	/**
	 * Angle of a seat on the oval, measured clockwise on screen from the right; the human sits at the bottom.
	 *
	 * Opponents are spaced evenly around the oval from the human's left. The action buttons sit to the lower
	 * right of the human, so no seat may come lower on that side than `ACTION_CLEARANCE`; when even spacing
	 * would put the last seat there (seven or eight players), the opponents spread over the arc that stops
	 * short of it instead.
	 *
	 * @param int $seat Seat id; 0 is the human.
	 * @param int $seats Number of seats at the table.
	 */
	public static function seatAngle(int $seat, int $seats): float
	{
		if($seat === 0) return M_PI / 2;
		$seats = max(2, $seats);
		$gap = 2 * M_PI / $seats;
		$clearance = M_PI / 2 - self::ACTION_CLEARANCE;
		if($gap >= $clearance) return M_PI / 2 + $gap * $seat;
		return M_PI / 2 + $gap + ($seat - 1) * (2 * M_PI - $gap - $clearance) / max(1, $seats - 2);
	}

	/** Lowest angle below the right-hand horizontal a seat may take before its fan meets the action bar. */
	public const ACTION_CLEARANCE = .42;

	/**
	 * Design-space geometry of the centre: pot plate, deck, chip anchors and bet plate.
	 *
	 * @return array{pot:array{float,float,float,float},deck:array{float,float,float,float},bet:array{float,float,float,float},chips:list<array{float,float}>}
	 */
	public static function centre(): array
	{
		[$cx,$cy] = self::CENTRE;
		[$cardWidth,$cardHeight] = self::HAND_CARD;
		return [
			'pot' => [$cx - 100, $cy - 110, 200, self::PLATE_HEIGHT]
			, 'deck' => [$cx - $cardWidth / 2, $cy - 72, $cardWidth, $cardHeight]
			, 'bet' => [$cx - 100, $cy + 56, 200, self::PLATE_HEIGHT]
			, 'chips' => [[$cx - 80, $cy + 10], [$cx - 50, $cy + 30], [$cx + 70, $cy + 18], [$cx + 40, $cy + 36]]
		];
	}

	/**
	 * Design-space rectangles of the action buttons for the legal actions of the human seat.
	 *
	 * Only legal actions get a button; Fold is leftmost, the primary action rightmost. Each entry carries the
	 * intent it sends, its label and its key legend.
	 *
	 * @param array<string,mixed> $view Current session view.
	 * @param array{discards:list<int>,amount:int} $state Presentation state: selected discards and the raise-to amount.
	 * @return list<array{id:string,rect:array{float,float,float,float},label:string,key:string,style:string,intent:array<string,mixed>|null}>
	 */
	public static function actions(array $view, array $state): array
	{
		$legal = $view['legalActions'] ?? [];
		$screen = $view['screen'] ?? 'menu';
		$phase = $view['phase'] ?? 'ready';
		$x = self::CENTRE[0] + 150;
		$y = self::CENTRE[1] + self::OVAL[1] - 40;
		$buttons = [];
		$push = static function(string $id, float $left, float $width, string $label, string $key, string $style, ?array $intent) use (&$buttons, $y): void {
			$buttons[] = ['id' => $id, 'rect' => [$left, $y, $width, 32], 'label' => $label, 'key' => $key, 'style' => $style, 'intent' => $intent];
		};
		if($screen !== 'table') return [];

		if($phase === 'ready')
		{
			$push('deal', $x, 120, 'Deal', '↵', 'primary', ['type' => 'next']);
			return $buttons;
		}

		if(!empty($legal['draw']))
		{
			$count = count($state['discards']);
			$push('clear', $x, 100, 'Keep all', '', 'default', null);
			$push('draw', $x + 110, 130, $count ? "Draw $count" : 'Stand pat', 'D', 'primary', ['type' => 'draw', 'cards' => $state['discards']]);
			return $buttons;
		}

		if(!empty($legal['next']))
		{
			// The terminal action deals the next hand; when the human is broke it also borrows the house's loan first.
			$push('history', $x, 110, 'History', 'H', 'default', null);
			$loan = (int)($legal['loan'] ?? 0);
			$push('next', $x + 120, $loan > 0 ? 190 : 120, $loan > 0 ? "Borrow $loan & deal" : 'Deal', '↵', 'primary', ['type' => 'next']);
			return $buttons;
		}

		if(!array_key_exists('fold', $legal)) return [];

		$push('fold', $x, 100, 'Fold', 'F', 'danger', ['type' => 'fold']);
		if(!empty($legal['pass'])) $push('pass', $x + 110, 100, 'Pass', 'C', 'default', ['type' => 'pass']);
		elseif(!empty($legal['check'])) $push('check', $x + 110, 100, 'Check', 'C', 'default', ['type' => 'check']);
		elseif(!empty($legal['call'])) $push('call', $x + 110, 100, 'Call ' . ($legal['callAmount'] ?? 0), 'C', 'default', ['type' => 'call']);
		if(!empty($legal['bet']))
		{
			$amount = max((int)$legal['minBet'], min((int)$legal['maxBet'], $state['amount']));
			$push('bet', $x + 220, 110, ($legal['betLabel'] ?? 'Bet') . ' ' . $amount, 'B', 'primary', ['type' => 'bet', 'amount' => $amount]);
		}

		return $buttons;
	}

	/**
	 * Design-space rectangles of the raise-to stepper, present only when a bet is legal.
	 *
	 * @param array<string,mixed> $view Current session view.
	 * @return array{less:array{float,float,float,float},amount:array{float,float,float,float},more:array{float,float,float,float},label:array{float,float}}|null
	 */
	public static function stepper(array $view): ?array
	{
		if(($view['screen'] ?? '') !== 'table' || empty($view['legalActions']['bet'])) return null;
		$x = self::CENTRE[0] + 150 + 110;
		$y = self::CENTRE[1] + self::OVAL[1] - 40 + 44;
		return ['less' => [$x, $y, 32, 28], 'amount' => [$x + 40, $y, 60, 28], 'more' => [$x + 108, $y, 32, 28], 'label' => [$x - 10, $y + 14]];
	}

	/**
	 * Design-space geometry of the discard dialog shown while the human draws.
	 *
	 * @return array{box:array{float,float,float,float},cards:list<array{float,float,float,float}>}
	 */
	public static function discardDialog(): array
	{
		$width = 760;
		$height = 254;
		$left = self::CENTRE[0] - $width / 2;
		$top = self::HEIGHT - $height - 10;
		[$cardWidth,$cardHeight] = self::DIALOG_CARD;
		$cards = [];
		for($index = 0; $index < 5; $index++) $cards[] = [$left + 20 + $index * ($cardWidth + 22), $top + 22, $cardWidth, $cardHeight];
		return ['box' => [$left, $top, $width, $height], 'cards' => $cards];
	}

	/**
	 * Design-space geometry of the main menu items.
	 *
	 * @param bool $canContinue Whether a saved game can be continued.
	 * @return list<array{id:string,rect:array{float,float,float,float},label:string,intent:array<string,mixed>|null}>
	 */
	public static function menu(bool $canContinue): array
	{
		$items = [['offline', 'Play Offline', ['type' => 'setup']]];
		if($canContinue) $items[] = ['continue', 'Continue Offline', ['type' => 'resume']];
		$items[] = ['online', 'Play Online', null];
		$items[] = ['help', 'How to Play', ['type' => 'help']];
		$items[] = ['settings', 'Settings', ['type' => 'settings']];
		$width = 300;
		$height = 46;
		$result = [];
		foreach($items as $index => [$id, $label, $intent])
		$result[] = ['id' => $id, 'rect' => [self::WIDTH / 2 - $width / 2, 300 + $index * ($height + 10), $width, $height], 'label' => $label, 'intent' => $intent];

		return $result;
	}

	/**
	 * Design-space geometry of the table setup card: opponent count stepper, variant toggle and Start.
	 *
	 * @param int $players Current seat count including the human.
	 * @param string $variant `jackpots` or `anything`.
	 * @return array{box:array{float,float,float,float},less:array{float,float,float,float},more:array{float,float,float,float},count:array{float,float,float,float},variant:array{float,float,float,float},start:array{float,float,float,float},back:array{float,float,float,float}}
	 */
	public static function setup(int $players, string $variant): array
	{
		$left = self::WIDTH / 2 - 220;
		$top = 230;
		return [
			'box' => [$left, $top, 440, 220]
			, 'less' => [$left + 200, $top + 50, 32, 28], 'count' => [$left + 240, $top + 50, 60, 28], 'more' => [$left + 308, $top + 50, 32, 28]
			, 'variant' => [$left + 200, $top + 96, 200, 28]
			, 'back' => [$left + 24, $top + 160, 120, 32], 'start' => [$left + 440 - 24 - 140, $top + 160, 140, 32]
		];
	}

	/**
	 * Whether a design-space point is inside a design-space rectangle.
	 *
	 * @param array{float,float} $point Design-space x and y.
	 * @param array{float,float,float,float} $rect Design-space rectangle.
	 */
	public static function hit(array $point, array $rect): bool
	{
		return $point[0] >= $rect[0] && $point[0] <= $rect[0] + $rect[2] && $point[1] >= $rect[1] && $point[1] <= $rect[1] + $rect[3];
	}
}
