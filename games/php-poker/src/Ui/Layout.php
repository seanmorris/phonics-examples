<?php
declare(strict_types=1);
namespace PhpPoker\Ui;

/**
 * Pure table layout: where every seat, card, chip stack, plate, button and dialog sits for one frame.
 *
 * Two design profiles share one vocabulary. `wide` is the 1280x720 field of the DOS game; `tall` is a 450x800
 * portrait field for phones, with a tighter oval, smaller fans and the action bar across the bottom. The chosen
 * field scales uniformly to fit the canvas and is centred, and all results are in device pixels. Rendering and
 * hit testing share this so a region a player can click is exactly the region that is drawn.
 */
final class Layout
{
	public const MENU_BAR = 28;
	public const PLATE_HEIGHT = 24;
	/** Lowest angle below the right-hand horizontal a seat may take before its fan meets the action bar. */
	public const ACTION_CLEARANCE = .42;

	/** @var array<string,array<string,mixed>> Design metrics per profile. */
	private const PROFILES = [
		'wide' => [
			'width' => 1280, 'height' => 720, 'centre' => [640,378], 'oval' => [470,250], 'emblemInset' => [175,115]
			, 'card' => [68,95], 'cardStep' => 30, 'handCard' => [90,126], 'handStep' => 38, 'dialogCard' => [124,174]
			, 'plateWidth' => 200, 'handPlateWidth' => 250, 'plateLift' => 62, 'handPlateLift' => 90
			, 'actions' => [790,588], 'actionRows' => false, 'buttonHeight' => 32, 'chipRadius' => 15, 'handChipRadius' => 17
			, 'menuItem' => [300,46], 'menuTop' => 300, 'dialogWidth' => 760, 'display' => 54
		]
		, 'tall' => [
			'width' => 450, 'height' => 800, 'centre' => [225,352], 'oval' => [165,260], 'emblemInset' => [84,78]
			, 'card' => [36,50], 'cardStep' => 16, 'handCard' => [66,92], 'handStep' => 28, 'dialogCard' => [72,101]
			, 'plateWidth' => 104, 'handPlateWidth' => 190, 'plateLift' => 48, 'handPlateLift' => 70
			, 'actions' => [16,676], 'actionRows' => true, 'buttonHeight' => 44, 'chipRadius' => 10, 'handChipRadius' => 12
			, 'menuItem' => [300,52], 'menuTop' => 300, 'dialogWidth' => 418, 'display' => 42
		]
	];

	/** @var array<string,mixed> */
	private readonly array $design;

	/**
	 * @param string $profile `wide` or `tall`.
	 * @param float $scale Device pixels per design pixel.
	 * @param float $left Device x of the field's left edge.
	 * @param float $top Device y of the field's top edge.
	 */
	private function __construct(public readonly string $profile, public readonly float $scale, public readonly float $left, public readonly float $top)
	{
		$this->design = self::PROFILES[$profile];
	}

	/**
	 * Fit the design field to a canvas, choosing the tall profile for portrait and narrow canvases.
	 *
	 * @param int $width Canvas width in device pixels.
	 * @param int $height Canvas height in device pixels.
	 * @param float $dpr Device-pixel ratio; the profile switch happens on CSS pixels.
	 */
	public static function fit(int $width, int $height, float $dpr = 1): self
	{
		$dpr = max(.5, min(4, is_finite($dpr) ? $dpr : 1));
		$profile = $width < $height || $width / $dpr < 760 ? 'tall' : 'wide';
		$design = self::PROFILES[$profile];
		$scale = max(1e-6, min($width / $design['width'], $height / $design['height']));
		return new self($profile, $scale, ($width - $design['width'] * $scale) / 2, ($height - $design['height'] * $scale) / 2);
	}

	/**
	 * A design metric of the active profile.
	 *
	 * @param string $name Metric name, such as `oval` or `handCard`.
	 * @return mixed
	 */
	public function metric(string $name): mixed { return $this->design[$name]; }

	/** Design width of the field. */
	public function width(): int { return $this->design['width']; }

	/** Design height of the field. */
	public function height(): int { return $this->design['height']; }

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
	 * Angle of a seat on the oval, measured clockwise on screen from the right; the human sits at the bottom.
	 *
	 * Opponents are spaced evenly around the oval from the human's left. The action buttons sit to the lower right
	 * of the human in the wide profile, so no seat may come lower on that side than `ACTION_CLEARANCE`; when even
	 * spacing would put the last seat there, the opponents spread over the arc that stops short of it instead. The
	 * tall profile keeps the bar below the hand and the field is narrow, so its opponents spread over the arc that
	 * starts sixty degrees either side of the human.
	 *
	 * @param int $seat Seat id; 0 is the human.
	 * @param int $seats Number of seats at the table.
	 */
	public function seatAngle(int $seat, int $seats): float
	{
		if($seat === 0) return M_PI / 2;
		$seats = max(2, $seats);
		$gap = 2 * M_PI / $seats;
		if($this->design['actionRows'])
		{
			if($seats === 2) return M_PI * 1.5;
			$margin = M_PI / 3;
			return M_PI / 2 + $margin + ($seat - 1) * (2 * M_PI - 2 * $margin) / ($seats - 2);
		}
		$clearance = M_PI / 2 - self::ACTION_CLEARANCE;
		if($gap >= $clearance) return M_PI / 2 + $gap * $seat;
		return M_PI / 2 + $gap + ($seat - 1) * (2 * M_PI - $gap - $clearance) / max(1, $seats - 2);
	}

	/**
	 * Design-space geometry of one seat: plate, fan, chip anchor, caption and emblem.
	 *
	 * @param int $seat Seat id; 0 is the human.
	 * @param int $seats Number of seats at the table.
	 * @return array{plate:array{float,float,float,float},cards:list<array{float,float,float,float}>,chips:array{float,float},caption:array{float,float},emblem:array{float,float},angle:float,outward:int}
	 */
	public function seat(int $seat, int $seats): array
	{
		$d = $this->design;
		$angle = $this->seatAngle($seat, $seats);
		$ux = cos($angle);
		$uy = sin($angle);
		[$cx,$cy] = [$d['centre'][0] + $ux * $d['oval'][0], $d['centre'][1] + $uy * $d['oval'][1]];
		$human = $seat === 0;
		[$cardWidth,$cardHeight] = $human ? $d['handCard'] : $d['card'];
		$step = $human ? $d['handStep'] : $d['cardStep'];
		$plateWidth = $human ? $d['handPlateWidth'] : $d['plateWidth'];
		$plate = [$cx - $plateWidth / 2, $cy - ($human ? $d['handPlateLift'] : $d['plateLift']), $plateWidth, self::PLATE_HEIGHT];
		$fanLeft = $plate[0] + ($plateWidth - ($cardWidth + 4 * $step)) / 2;
		$fanTop = $plate[1] + self::PLATE_HEIGHT + 8;
		$cards = [];
		for($index = 0; $index < 5; $index++) $cards[] = [$fanLeft + $index * $step, $fanTop, $cardWidth, $cardHeight];
		$outward = $ux > .3 ? 1 : -1;
		$chipRadius = $human ? $d['handChipRadius'] : $d['chipRadius'];
		// Chips stand in front of the fan's outer corner; the human's stand beside the hand so they stay on screen.
		$chips = $human
			? [$plate[0] - $chipRadius * 4.2, $fanTop + $cardHeight - $chipRadius * 2.6]
			: [$outward > 0 ? $plate[0] + $plateWidth - $chipRadius * 3.7 : $plate[0] - 4, $fanTop + $cardHeight + 4];
		// Name and stack read under the fan on the side away from the chips.
		$caption = [$outward > 0 ? $fanLeft : $fanLeft + $cardWidth + 4 * $step, $fanTop + $cardHeight + 14];
		$emblem = $human
			? [$plate[0] - 46, $plate[1] + 8]
			: [$d['centre'][0] + $ux * ($d['oval'][0] - $d['emblemInset'][0]), $d['centre'][1] + $uy * ($d['oval'][1] - $d['emblemInset'][1])];

		return ['plate' => $plate, 'cards' => $cards, 'chips' => $chips, 'caption' => $caption, 'emblem' => $emblem, 'angle' => $angle, 'outward' => $outward];
	}

	/**
	 * Design-space geometry of the centre: pot plate, deck, chip anchors and bet plate.
	 *
	 * @return array{pot:array{float,float,float,float},deck:array{float,float,float,float},bet:array{float,float,float,float},chips:list<array{float,float}>}
	 */
	public function centre(): array
	{
		[$cx,$cy] = $this->design['centre'];
		[$cardWidth,$cardHeight] = $this->design['handCard'];
		$plateWidth = min(200, $this->design['width'] - 40);
		$lift = $cardHeight * .57;
		return [
			'pot' => [$cx - $plateWidth / 2, $cy - $lift - 38, $plateWidth, self::PLATE_HEIGHT]
			, 'deck' => [$cx - $cardWidth / 2, $cy - $lift, $cardWidth, $cardHeight]
			, 'bet' => [$cx - $plateWidth / 2, $cy + $cardHeight - $lift + 14, $plateWidth, self::PLATE_HEIGHT]
			, 'chips' => [[$cx - 80, $cy + 10], [$cx - 50, $cy + 30], [$cx + 70, $cy + 18], [$cx + 40, $cy + 36]]
		];
	}

	/**
	 * Design-space rectangles of the action buttons for the legal actions of the human seat.
	 *
	 * Only legal actions get a button; Fold is leftmost, the primary action rightmost. In the tall profile the
	 * buttons fill a row across the bottom with touch-sized heights. Each entry carries the intent it sends, its
	 * label and its key legend.
	 *
	 * @param array<string,mixed> $view Current session view.
	 * @param array{discards:list<int>,amount:int} $state Presentation state: selected discards and the raise-to amount.
	 * @return list<array{id:string,rect:array{float,float,float,float},label:string,key:string,style:string,intent:array<string,mixed>|null}>
	 */
	public function actions(array $view, array $state): array
	{
		$legal = $view['legalActions'] ?? [];
		$screen = $view['screen'] ?? 'menu';
		$phase = $view['phase'] ?? 'ready';
		if($screen !== 'table') return [];
		$entries = [];
		$add = static function(string $id, float $weight, string $label, string $key, string $style, ?array $intent) use (&$entries): void {
			$entries[] = ['id' => $id, 'weight' => $weight, 'label' => $label, 'key' => $key, 'style' => $style, 'intent' => $intent];
		};

		if($phase === 'ready') $add('deal', 1.2, 'Deal', '↵', 'primary', ['type' => 'next']);
		elseif(!empty($legal['draw']))
		{
			$count = count($state['discards']);
			$add('clear', 1, 'Keep all', '', 'default', null);
			$add('draw', 1.3, $count ? "Draw $count" : 'Stand pat', 'D', 'primary', ['type' => 'draw', 'cards' => $state['discards']]);
		}
		elseif(!empty($legal['next']))
		{
			// The terminal action deals the next hand; when the human is broke it also borrows the house's loan first.
			$loan = (int)($legal['loan'] ?? 0);
			$add('history', 1.1, 'History', 'H', 'default', null);
			$add('next', $loan > 0 ? 1.9 : 1.2, $loan > 0 ? "Borrow $loan & deal" : 'Deal', '↵', 'primary', ['type' => 'next']);
		}
		elseif(array_key_exists('fold', $legal))
		{
			$add('fold', 1, 'Fold', 'F', 'danger', ['type' => 'fold']);
			if(!empty($legal['pass'])) $add('pass', 1, 'Pass', 'C', 'default', ['type' => 'pass']);
			elseif(!empty($legal['check'])) $add('check', 1, 'Check', 'C', 'default', ['type' => 'check']);
			elseif(!empty($legal['call'])) $add('call', 1, 'Call ' . ($legal['callAmount'] ?? 0), 'C', 'default', ['type' => 'call']);
			if(!empty($legal['bet']))
			{
				$amount = max((int)$legal['minBet'], min((int)$legal['maxBet'], $state['amount']));
				$add('bet', 1.1, ($legal['betLabel'] ?? 'Bet') . ' ' . $amount, 'B', 'primary', ['type' => 'bet', 'amount' => $amount]);
			}
		}
		if(!$entries) return [];

		[$x, $y] = $this->design['actions'];
		$height = $this->design['buttonHeight'];
		$gap = 10;
		$unit = $this->design['actionRows'] ? ($this->design['width'] - 2 * $x - $gap * (count($entries) - 1)) / array_sum(array_column($entries, 'weight')) : 100;
		$buttons = [];
		foreach($entries as $entry)
		{
			$width = $entry['weight'] * $unit;
			$buttons[] = ['id' => $entry['id'], 'rect' => [$x, $y, $width, $height], 'label' => $entry['label'], 'key' => $entry['key'], 'style' => $entry['style'], 'intent' => $entry['intent']];
			$x += $width + $gap;
		}

		return $buttons;
	}

	/**
	 * Design-space rectangles of the raise-to stepper, present only when a bet is legal.
	 *
	 * @param array<string,mixed> $view Current session view.
	 * @return array{less:array{float,float,float,float},amount:array{float,float,float,float},more:array{float,float,float,float},label:array{float,float}}|null
	 */
	public function stepper(array $view): ?array
	{
		if(($view['screen'] ?? '') !== 'table' || empty($view['legalActions']['bet'])) return null;
		[$x, $y] = $this->design['actions'];
		$height = $this->design['buttonHeight'];
		if($this->design['actionRows'])
		{
			// Second row across the bottom: label at the left, then minus, amount and plus toward the right edge.
			$row = $y + $height + 10;
			$size = $height - 6;
			$right = $this->design['width'] - $x;
			return ['less' => [$right - $size * 2 - 100, $row, $size, $size], 'amount' => [$right - $size - 90, $row, 80, $size], 'more' => [$right - $size, $row, $size, $size], 'label' => [$x, $row + $size / 2]];
		}
		$row = $y + 44;
		return ['less' => [$x + 110, $row, 32, 28], 'amount' => [$x + 150, $row, 60, 28], 'more' => [$x + 218, $row, 32, 28], 'label' => [$x + 100, $row + 14]];
	}

	/**
	 * Design-space geometry of the discard dialog shown while the human draws.
	 *
	 * @return array{box:array{float,float,float,float},cards:list<array{float,float,float,float}>}
	 */
	public function discardDialog(): array
	{
		$width = $this->design['dialogWidth'];
		[$cardWidth,$cardHeight] = $this->design['dialogCard'];
		$gap = ($width - 40 - 5 * $cardWidth) / 4;
		$height = $cardHeight + 80;
		$left = $this->design['width'] / 2 - $width / 2;
		$top = $this->design['actionRows'] ? $this->design['actions'][1] - $height - 10 : $this->design['height'] - $height - 10;
		$cards = [];
		for($index = 0; $index < 5; $index++) $cards[] = [$left + 20 + $index * ($cardWidth + $gap), $top + 22, $cardWidth, $cardHeight];
		return ['box' => [$left, $top, $width, $height], 'cards' => $cards];
	}

	/**
	 * Design-space geometry of the main menu items.
	 *
	 * @param bool $canContinue Whether a saved game can be continued.
	 * @return list<array{id:string,rect:array{float,float,float,float},label:string,intent:array<string,mixed>|null}>
	 */
	public function menu(bool $canContinue): array
	{
		$items = [['offline', 'Play Offline', ['type' => 'setup']]];
		if($canContinue) $items[] = ['continue', 'Continue Offline', ['type' => 'resume']];
		$items[] = ['online', 'Play Online', null];
		$items[] = ['help', 'How to Play', ['type' => 'help']];
		$items[] = ['settings', 'Settings', ['type' => 'settings']];
		[$width, $height] = $this->design['menuItem'];
		$width = min($width, $this->design['width'] - 40);
		$result = [];
		foreach($items as $index => [$id, $label, $intent])
		$result[] = ['id' => $id, 'rect' => [$this->design['width'] / 2 - $width / 2, $this->design['menuTop'] + $index * ($height + 10), $width, $height], 'label' => $label, 'intent' => $intent];

		return $result;
	}

	/**
	 * Design-space geometry of the table setup card: opponent count stepper, variant toggle and Start.
	 *
	 * @param int $players Current seat count including the human.
	 * @param string $variant `jackpots` or `anything`.
	 * @return array{box:array{float,float,float,float},less:array{float,float,float,float},more:array{float,float,float,float},count:array{float,float,float,float},variant:array{float,float,float,float},start:array{float,float,float,float},back:array{float,float,float,float}}
	 */
	public function setup(int $players, string $variant): array
	{
		$width = min(440, $this->design['width'] - 24);
		$left = $this->design['width'] / 2 - $width / 2;
		$top = 230;
		$control = $this->design['buttonHeight'] - 4;
		$column = $left + $width - 24 - 200;
		return [
			'box' => [$left, $top, $width, 220]
			, 'less' => [$column, $top + 50, 32, $control], 'count' => [$column + 40, $top + 50, 60, $control], 'more' => [$column + 108, $top + 50, 32, $control]
			, 'variant' => [$column, $top + 96, 200, $control]
			, 'back' => [$left + 24, $top + 160, 120, $this->design['buttonHeight']], 'start' => [$left + $width - 24 - 140, $top + 160, 140, $this->design['buttonHeight']]
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
