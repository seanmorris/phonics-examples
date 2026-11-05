<?php
declare(strict_types=1);
namespace PhpPoker\Ui;

/**
 * Pure table layout: where every seat, card, chip stack, plate, button and dialog sits for one frame.
 *
 * Three design profiles share one vocabulary. `wide` is the 1280x720 field of the DOS game; `tall` is a 450x800
 * portrait field for phones; `landscape` reserves a right-hand control panel on short screens. The chosen
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
			'width' => 1280, 'height' => 720, 'centre' => [640,378], 'oval' => [470,250], 'upperStretch' => 24, 'emblemInset' => [175,115]
			, 'card' => [68,95], 'cardStep' => 30, 'handCard' => [90,126], 'handStep' => 38, 'dialogCard' => [124,174]
			, 'plateWidth' => 200, 'handPlateWidth' => 250, 'plateLift' => 62, 'handPlateLift' => 90
			, 'actions' => [790,588], 'actionRows' => false, 'buttonHeight' => 32, 'chipRadius' => 15, 'handChipRadius' => 17
			, 'menuItem' => [300,52], 'menuTop' => 300, 'dialogWidth' => 760, 'display' => 54
		]
		, 'tall' => [
			'width' => 450, 'height' => 800, 'centre' => [225,352], 'oval' => [165,260], 'upperStretch' => 6, 'emblemInset' => [84,78]
			, 'card' => [36,50], 'cardStep' => 16, 'handCard' => [66,92], 'handStep' => 28, 'dialogCard' => [72,101]
			, 'plateWidth' => 104, 'handPlateWidth' => 190, 'plateLift' => 48, 'handPlateLift' => 70
			, 'actions' => [16,676], 'actionRows' => true, 'buttonHeight' => 44, 'chipRadius' => 10, 'handChipRadius' => 12
			, 'menuItem' => [300,52], 'menuTop' => 300, 'dialogWidth' => 418, 'display' => 42
		]
		, 'landscape' => [
			'width' => 900, 'height' => 400, 'centre' => [300,210], 'oval' => [210,115], 'upperStretch' => 12, 'emblemInset' => [85,55]
			, 'card' => [38,53], 'cardStep' => 17, 'handCard' => [62,87], 'handStep' => 26, 'dialogCard' => [86,120]
			, 'plateWidth' => 118, 'handPlateWidth' => 190, 'plateLift' => 44, 'handPlateLift' => 70
			, 'actions' => [568,250], 'actionRows' => false, 'buttonHeight' => 52, 'chipRadius' => 9, 'handChipRadius' => 11
			, 'menuItem' => [350,52], 'menuTop' => 150, 'dialogWidth' => 530, 'display' => 42
		]
	];

	/** @var array<string,mixed> */
	private readonly array $design;

	/**
	 * @param string $profile `wide`, `tall` or `landscape`.
	 * @param float $scale Device pixels per design pixel.
	 * @param float $left Device x of the field's left edge.
	 * @param float $top Device y of the field's top edge.
	 */
	private function __construct(public readonly string $profile, public readonly float $scale, public readonly float $left, public readonly float $top, public readonly float $dpr)
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
		$profile = $width >= $height && $height / $dpr < 480 ? 'landscape' : ($width < $height || $width / $dpr < 760 ? 'tall' : 'wide');
		$design = self::PROFILES[$profile];
		$scale = max(1e-6, min($width / $design['width'], $height / $design['height']));
		return new self($profile, $scale, ($width - $design['width'] * $scale) / 2, ($height - $design['height'] * $scale) / 2, $dpr);
	}

	/**
	 * A design metric of the active profile.
	 *
	 * @param string $name Metric name, such as `oval` or `handCard`.
	 * @return mixed
	 */
	public function metric(string $name): mixed { return match($name) { 'buttonHeight' => $this->controlSize(), 'actions' => $this->actionOrigin(), default => $this->design[$name] }; }

	/** Minimum target in design units, measured against CSS pixels rather than the framebuffer. */
	public function controlSize(): float { return max($this->design['buttonHeight'], 48 * $this->dpr / $this->scale); }

	/** Action rows reserve room for the raise controls beneath them. */
	private function actionOrigin(): array { return [$this->design['actions'][0], min($this->design['actions'][1], $this->height() - 2 * $this->controlSize() - 20)]; }

	/** Readable control text size in design units. */
	public function controlText(): float { return max(14, 13 * $this->dpr / $this->scale); }

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
		// Use the space freed above the opponents by the centered status plate, without lowering the human hand.
		$verticalRadius = $d['oval'][1] + ($uy < 0 ? $d['upperStretch'] : 0);
		[$cx,$cy] = [$d['centre'][0] + $ux * $d['oval'][0], $d['centre'][1] + $uy * $verticalRadius];
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
			? [$plate[0] - $chipRadius * 4.2 - ($this->profile === 'wide' ? 20 : 36), $fanTop + $cardHeight - $chipRadius * 2.6]
			: [$outward > 0 ? $plate[0] + $plateWidth - $chipRadius * 3.7 : $plate[0] - 4, $fanTop + $cardHeight + 4];
		// Opponent captions read under the fan; the human caption sits directly below the chip group.
		$caption = $human
			? [$chips[0] + $chipRadius, $chips[1] + $chipRadius * 2 + 14]
			: [$outward > 0 ? $fanLeft : $fanLeft + $cardWidth + 4 * $step, $fanTop + $cardHeight + 14];
		$emblem = $human
			? [$plate[0] - 46, $plate[1] + 8]
			: [$d['centre'][0] + $ux * ($d['oval'][0] - $d['emblemInset'][0]), $d['centre'][1] + $uy * ($verticalRadius - $d['emblemInset'][1])];
		if($human && $this->profile === 'landscape')
		{
			// The adjacent opponent occupies the left side on a short screen. Keep the human bank in the gap
			// between the hand and the action panel, with the combined YOU / total label below.
			$handRight = $fanLeft + $cardWidth + 4 * $step;
			$chips[0] = $handRight + 32;
			$caption = [$chips[0] + $chipRadius, $chips[1] + $chipRadius * 2 + 14];
			$emblem = [$handRight + 22, $fanTop + 19];
		}

		return ['plate' => $plate, 'cards' => $cards, 'chips' => $chips, 'caption' => $caption, 'emblem' => $emblem, 'angle' => $angle, 'outward' => $outward];
	}

	/** Keep the human total below the occupied denominations, including after a color-up. */
	public function caption(int $seat, int $seats, array $bank): array
	{
		$caption = $this->seat($seat, $seats)['caption'];
		if($seat !== 0 || !array_filter($bank)) return $caption;
		$left = INF;
		$right = $bottom = -INF;
		foreach($bank as $value => $count) if($count > 0)
		{
			[$x,$y,$radius] = $this->chip($seat, $seats, (int)$value, 0);
			$left = min($left, $x - $radius);
			$right = max($right, $x + $radius);
			$bottom = max($bottom, $y + $radius * .42);
		}
		return [($left + $right) / 2, $bottom + 14];
	}

	/**
	 * Design-space geometry of the centre: pot plate, deck, chip anchors and bet plate.
	 *
	 * @return array{pot:array{float,float,float,float},deck:array{float,float,float,float},discard:array{float,float,float,float},bet:array{float,float,float,float},chips:list<array{float,float}>}
	 */
	public function centre(): array
	{
		[$cx,$cy] = $this->design['centre'];
		[$cardWidth,$cardHeight] = $this->design['handCard'];
		$plateWidth = min(200, $this->design['width'] - 40);
		if($this->profile === 'landscape') { $cardWidth = 40; $cardHeight = 56; $plateWidth = 150; }
		$lift = $cardHeight * .57;
		$centre = [
			'pot' => [$cx - $plateWidth / 2, $cy - $lift - 38, $plateWidth, self::PLATE_HEIGHT]
			, 'deck' => [$cx - $cardWidth / 2, $cy - $lift, $cardWidth, $cardHeight]
			, 'discard' => [$cx + $cardWidth / 2 + 16, $cy - $lift, $cardWidth, $cardHeight]
			, 'bet' => [$cx - $plateWidth / 2, $cy + $cardHeight - $lift + 14, $plateWidth, self::PLATE_HEIGHT]
			, 'chips' => [[$cx - 80, $cy + 10], [$cx - 50, $cy + 30], [$cx + 70, $cy + 18], [$cx + 40, $cy + 36]]
		];
		if($this->profile === 'landscape')
		{
			// Put the stock and discards beside the bet, leaving room for status text below it and above the hand.
			$centre['bet'][1] = $centre['deck'][1];
			$centre['deck'][0] = $cx - $plateWidth / 2 - $cardWidth - 12;
			$centre['discard'][0] = $cx + $plateWidth / 2 + 12;
			$centre['chips'][0] = [$cx - $plateWidth / 2 - 33, $centre['deck'][1] - 36];
		}
		return $centre;
	}

	/** Stable positions shared by stacks, the loose pot, and every transfer endpoint. */
	public function chip(int|string $owner, int $seats, int $value, int $index): array
	{
		$slot = array_search($value, Chips::VALUES, true);
		if($slot === false || $index < 0) throw new \InvalidArgumentException('Invalid chip');
		$radius = (float)$this->metric($owner === 0 ? 'handChipRadius' : 'chipRadius');
		if($owner === 'pot')
		{
			[$x,$y,$width,$height] = $this->centre()['deck'];
			$spread = $this->profile === 'landscape' ? .8 : 1.6;
			// A fixed low-discrepancy scatter: adding or removing chips never reshuffles the remaining pile.
			$id = $index * count(Chips::VALUES) + $slot + 1;
			$angle = $id * 2.399963229728653;
			$distance = sqrt(fmod($id * .7548776662466927, 1));
			return [$x - $radius * ($spread + 1.2) + cos($angle) * $distance * $radius * $spread,
				$y + $height / 2 + sin($angle) * $distance * $radius * ($this->profile === 'landscape' ? 1.4 : 2), $radius];
		}
		[$x, $y] = $this->seat($owner, $seats)['chips'];
		// Small denominations form the tall rear row; larger, shorter stacks nest closely in front.
		$x = max($radius, min($this->width() - $radius * 4.6, $x));
		$row = intdiv($slot, 2);
		return [$x + (($slot % 2) * 1.45 + ($row % 2) * .5) * $radius,
			$y + ($row * .7 + ($slot % 2) * .18 - $index * .22) * $radius, $radius];
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

		[$x, $y] = $this->actionOrigin();
		$height = $this->controlSize();
		$gap = 10;
		$unit = $this->profile === 'landscape' ? (316 - $gap * (count($entries) - 1)) / array_sum(array_column($entries, 'weight')) : ($this->design['actionRows'] ? ($this->design['width'] - 2 * $x - $gap * (count($entries) - 1)) / array_sum(array_column($entries, 'weight')) : 100);
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
		[$x, $y] = $this->actionOrigin();
		$height = $this->controlSize();
		if($this->design['actionRows'])
		{
			// Second row across the bottom: label at the left, then minus, amount and plus toward the right edge.
			$row = $y + $height + 10;
			$size = $height;
			$right = $this->design['width'] - $x;
			return ['less' => [$right - $size * 2 - 100, $row, $size, $size], 'amount' => [$right - $size - 90, $row, 80, $size], 'more' => [$right - $size, $row, $size, $size], 'label' => [$x, $row + $size / 2]];
		}
		$row = $y + $height + 10;
		return ['less' => [$x + 80, $row, $height, $height], 'amount' => [$x + 90 + $height, $row, 60, $height], 'more' => [$x + 160 + $height, $row, $height, $height], 'label' => [$x + 70, $row + $height / 2]];
	}

	/**
	 * Design-space geometry of the discard dialog shown while the human draws.
	 *
	 * @param list<int|null> $cards Cards in hand order.
	 * @param list<int> $discards Selected card ids; their raised positions also define their hit regions.
	 * @return array{box:array{float,float,float,float},cards:list<array{float,float,float,float}>}
	 */
	public function discardDialog(array $cards = [], array $discards = []): array
	{
		$width = $this->design['dialogWidth'];
		[$cardWidth,$cardHeight] = $this->design['dialogCard'];
		$gap = ($width - 40 - 5 * $cardWidth) / 4;
		$height = $cardHeight + 80;
		$left = $this->design['width'] / 2 - $width / 2;
		// Keep the cards above the action row; on a short landscape screen they occupy the left panel.
		$top = $this->actionOrigin()[1] - $height - 10;
		if($this->profile === 'landscape') { $left = 20; $top = $this->height() - $height - 12; }
		$rects = [];
		for($index = 0; $index < 5; $index++)
		{
			$lift = isset($cards[$index]) && in_array($cards[$index], $discards, true) ? 12 : 0;
			$rects[] = [$left + 20 + $index * ($cardWidth + $gap), $top + 22 - $lift, $cardWidth, $cardHeight];
		}
		return ['box' => [$left, $top, $width, $height], 'cards' => $rects];
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
		$items[] = ['online', 'Online (coming soon)', null];
		$items[] = ['help', 'How to Play', ['type' => 'help']];
		$items[] = ['settings', 'Settings', ['type' => 'settings']];
		[$width, $height] = $this->design['menuItem'];
		$width = min($width, $this->design['width'] - 40);
		$height = max($height, $this->controlSize());
		$result = [];
		foreach($items as $index => [$id, $label, $intent])
		{
			$x = $this->width() / 2 - $width / 2;
			$y = $this->design['menuTop'] + $index * ($height + 10);
			if($this->profile === 'landscape') { $x = $this->width() / 2 - $width - 10 + ($index % 2) * ($width + 20); $y = $this->design['menuTop'] + intdiv($index, 2) * ($height + 10); }
			$result[] = ['id' => $id, 'rect' => [$x, $y, $width, $height], 'label' => $label, 'intent' => $intent];
		}

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
		$width = min(500, $this->width() - 24);
		$left = ($this->width() - $width) / 2;
		$control = $this->controlSize();
		$height = 3 * $control + 70;
		$top = $this->profile === 'landscape' ? $this->height() - $height - 12 : 230;
		$column = $left + $width - 24 - max(200, 3 * $control + 20);
		$row = $top + 28;
		return [
			'box' => [$left, $top, $width, $height]
			, 'less' => [$column, $row, $control, $control], 'count' => [$column + $control + 10, $row, $control, $control], 'more' => [$column + 2 * ($control + 10), $row, $control, $control]
			, 'pace' => [$column, $row, $width - ($column - $left) - 24, $control]
			, 'variant' => [$column, $row + $control + 10, $width - ($column - $left) - 24, $control]
			, 'back' => [$left + 24, $row + 2 * ($control + 10), 120, $control], 'start' => [$left + $width - 24 - 140, $row + 2 * ($control + 10), 140, $control]
		];
	}

	/** Dialog bounds and an explicit Back target shared by help and history. */
	public function information(): array
	{
		$width = min(800, $this->width() - 24);
		$left = ($this->width() - $width) / 2;
		$top = 50;
		$height = $this->height() - $top - 12;
		$control = $this->controlSize();
		return ['box' => [$left, $top, $width, $height], 'back' => [$this->width() / 2 - 70, $top + $height - $control - 12, 140, $control]];
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
