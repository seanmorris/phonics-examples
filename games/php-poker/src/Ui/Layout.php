<?php
declare(strict_types=1);
namespace PhpPoker\Ui;

/**
 * Pure table layout: where every seat, card, chip stack, plate, button and dialog sits for one frame.
 *
 * Three design profiles share one vocabulary. `wide` is the 1280x720 field of the DOS game, with the seats on an
 * oval. `tall` is a 470-wide portrait field for phones and `landscape` a 400-high field with a right-hand control
 * panel for short screens; both grow along their free axis to fill the canvas and seat the opponents in a column
 * down each side, with the pot between them and the human's hand at the bottom, because an oval of full seat
 * blocks does not fit there. The field scales uniformly and is centred; results are in device pixels. Rendering
 * and hit testing share this so a region a player can click is exactly the region that is drawn.
 */
final class Layout
{
	public const MENU_BAR = 28;
	public const PLATE_HEIGHT = 24;
	/** Lowest angle below the right-hand horizontal a seat may take before its fan meets the action bar. */
	public const ACTION_CLEARANCE = .42;
	/** Width kept for the identity (emblem over name and stack) on the outer side of a column seat. */
	public const IDENTITY = 40;
	/** Width of the landscape control panel, including its margins. */
	private const PANEL = 342;

	/** @var array<string,array<string,mixed>> Design metrics per profile. */
	private const PROFILES = [
		'wide' => [
			'width' => 1280, 'height' => 720, 'centre' => [640,378], 'oval' => [470,250], 'upperStretch' => 24, 'emblemSize' => 32
			, 'card' => [68,95], 'cardStep' => 30, 'handCard' => [90,126], 'handStep' => 38, 'dialogCard' => [112,157]
			, 'plateWidth' => 200, 'handPlateWidth' => 250, 'plateLift' => 62, 'handPlateLift' => 90, 'handBankLift' => 14
			, 'actions' => [790,588], 'actionRows' => false, 'buttonHeight' => 32, 'chipRadius' => 15, 'handChipRadius' => 17
			, 'menuItem' => [300,52], 'menuTop' => 300, 'dialogWidth' => 680, 'display' => 54
		]
		, 'tall' => [
			'width' => 470, 'height' => 836, 'centre' => [235,352], 'oval' => [165,260], 'upperStretch' => 6, 'emblemSize' => 32
			, 'card' => [36,50], 'cardStep' => 16, 'handCard' => [66,92], 'handStep' => 28, 'dialogCard' => [72,101]
			, 'plateWidth' => 100, 'handPlateWidth' => 190, 'plateLift' => 48, 'handPlateLift' => 70, 'handBankLift' => 24
			, 'actions' => [16,676], 'actionRows' => true, 'buttonHeight' => 44, 'chipRadius' => 10, 'handChipRadius' => 12
			, 'menuItem' => [300,52], 'menuTop' => 300, 'dialogWidth' => 418, 'display' => 42
		]
		, 'landscape' => [
			'width' => 900, 'height' => 400, 'centre' => [300,210], 'oval' => [210,115], 'upperStretch' => 12, 'emblemSize' => 32
			, 'card' => [24,34], 'cardStep' => 10, 'handCard' => [62,87], 'handStep' => 26, 'dialogCard' => [86,120]
			, 'plateWidth' => 106, 'handPlateWidth' => 190, 'plateLift' => 44, 'handPlateLift' => 70, 'handBankLift' => 0
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
	private function __construct(public readonly string $profile, array $design, public readonly float $scale, public readonly float $left, public readonly float $top, public readonly float $dpr)
	{
		$this->design = $design;
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
		// The phone and short-landscape fields grow along whichever axis the canvas leaves free, within limits, so
		// neither is letterboxed while its seats are cramped.
		if($profile !== 'wide')
		{
			$aspect = $width / max(1, $height);
			if($aspect < $design['width'] / $design['height']) $design['height'] = (int)round(min($profile === 'tall' ? 1100 : 560, $design['width'] / $aspect));
			else $design['width'] = (int)round(min($profile === 'tall' ? 700 : 1200, $design['height'] * $aspect));
		}
		$scale = max(1e-6, min($width / $design['width'], $height / $design['height']));
		return new self($profile, $design, $scale, ($width - $design['width'] * $scale) / 2, ($height - $design['height'] * $scale) / 2, $dpr);
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

	/** Where the action buttons start: two rows along the bottom of a phone, the right panel in landscape. */
	private function actionOrigin(): array
	{
		// Phones reserve a caption line below both control rows for online settlement status.
		$rows = $this->height() - 2 * $this->controlSize() - ($this->profile === 'tall' ? 38 : 20);
		return match($this->profile) {
			'tall' => [$this->design['actions'][0], $rows]
			, 'landscape' => [$this->width() - self::PANEL + 10, min($this->design['actions'][1], $rows)]
			, default => [$this->design['actions'][0], min($this->design['actions'][1], $rows)]
		};
	}

	/** Whether opponents sit in side columns rather than on the oval. */
	public function columns(): bool { return $this->profile !== 'wide'; }

	/** Right edge of the table area; the landscape control panel lies beyond it. */
	public function tableRight(): float { return $this->profile === 'landscape' ? $this->width() - self::PANEL : $this->width(); }

	/** Top of the human's plate in a column profile, measured up from the controls or the bottom edge. */
	private function handTop(): float
	{
		// Room is left under the hand for the bank: above the action rows on a phone, above the edge in landscape.
		$bottom = $this->profile === 'tall' ? $this->actionOrigin()[1] - 34 : $this->height() - 26;
		return $bottom - $this->design['handCard'][1] - 8 - self::PLATE_HEIGHT;
	}

	/** Readable control text size in design units. */
	public function controlText(): float { return max(14, 13 * $this->dpr / $this->scale); }

	/** Caption size in design units, never below eleven CSS pixels however far the field is scaled down. */
	public function captionText(): float { return max(12, 11 * $this->dpr / $this->scale); }

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
		if($this->columns()) return $this->columnSeat($seat, $seats);
		$d = $this->design;
		$angle = $this->seatAngle($seat, $seats);
		$ux = cos($angle);
		$uy = sin($angle);
		// Use the space freed above the opponents by the centered status plate, without lowering the human hand.
		$verticalRadius = $d['oval'][1] + ($uy < 0 ? $d['upperStretch'] : 0);
		[$cx,$cy] = [$d['centre'][0] + $ux * $d['oval'][0], $d['centre'][1] + $uy * $verticalRadius];
		$human = $seat === 0;
		// Heads-up sits exactly on the top axis, where cosine rounding used to pick the left treatment. Keep the
		// hand centred but mirror its identity, bank and denomination rows like every opponent on the right side.
		$outward = $seats === 2 && !$human ? 1 : ($ux > .3 ? 1 : -1);
		[$cardWidth,$cardHeight] = $human ? $d['handCard'] : $d['card'];
		$step = $human ? $d['handStep'] : $d['cardStep'];
		$plateWidth = $cardWidth + 4 * $step;
		$plateTop = $cy - ($human ? $d['handPlateLift'] + $d['handBankLift'] : $d['plateLift'] + 28);
		// The icon and its two-line caption sit to the left, so the indicator needs no caption lane below it.
		if(!$human) $plateTop = max(self::MENU_BAR + 10, $plateTop);
		$plateLeft = $cx - $plateWidth / 2;
		// Identity blocks sit on the outer side of the table: left of left seats and right of right seats.
		$clampPlate = static fn(float $left, int $side): float => max($side > 0 ? 0 : self::IDENTITY,
			min($d['width'] - $plateWidth - ($side > 0 ? self::IDENTITY : 0), $left));
		if(!$human) $plateLeft = $clampPlate($plateLeft, $outward);
		// At dense tables, translate both complete side groups inward so only their shared seam gap changes.
		if(!$human && $seats >= 7)
		{
			$firstRight = 1;
			while($firstRight < $seats && cos($this->seatAngle($firstRight, $seats)) <= .3) $firstRight++;
			if($firstRight < $seats)
			{
				$leftAngle = $this->seatAngle($firstRight - 1, $seats);
				$rightAngle = $this->seatAngle($firstRight, $seats);
				$leftBase = $clampPlate($d['centre'][0] + cos($leftAngle) * $d['oval'][0] - $plateWidth / 2, -1);
				$rightBase = $clampPlate($d['centre'][0] + cos($rightAngle) * $d['oval'][0] - $plateWidth / 2, 1);
				$pull = max(0, ($rightBase - ($leftBase + $plateWidth) - 64) / 2);
				$plateLeft += $outward > 0 ? -$pull : $pull;
			}
		}
		$plate = [$plateLeft, $plateTop, $plateWidth, self::PLATE_HEIGHT];
		$fanLeft = $plate[0];
		$fanTop = $plate[1] + self::PLATE_HEIGHT + 8;
		$cards = [];
		for($index = 0; $index < 5; $index++) $cards[] = [$fanLeft + $index * $step, $fanTop, $cardWidth, $cardHeight];
		// Chips stand in front of the fan's outer corner; the human bank sits beside the hand.
		$chips = $human
			? [$plate[0] - 4, $fanTop + $cardHeight + 4]
			: [$outward > 0 ? $plate[0] + $plateWidth + 4 : $plate[0] - 4, $fanTop + $cardHeight + 4];
		$emblem = [$outward > 0 && !$human ? $plate[0] + $plate[2] + 20 : $plate[0] - ($human ? 22 : 20),
			$plate[1] + self::PLATE_HEIGHT / 2];
		// Every identity caption is centered directly beneath its icon; the YOU disc supplies the human's name.
		$caption = [$emblem[0], $plate[1] + 40];
		return ['plate' => $plate, 'cards' => $cards, 'chips' => $chips, 'caption' => $caption, 'emblem' => $emblem, 'angle' => $angle, 'outward' => $outward];
	}

	/**
	 * Seat geometry for the tall and landscape profiles: the human at the bottom of the table area, one opponent
	 * across from them, or the opponents in a column down each side, clockwise from the human's left, so the
	 * first sits bottom-left, the order runs up the left column and comes back down the right.
	 *
	 * @param int $seat Seat id; 0 is the human.
	 * @param int $seats Number of seats at the table.
	 * @return array{plate:array{float,float,float,float},cards:list<array{float,float,float,float}>,chips:array{float,float},caption:array{float,float},emblem:array{float,float},angle:float,outward:int}
	 */
	private function columnSeat(int $seat, int $seats): array
	{
		$d = $this->design;
		$human = $seat === 0;
		$right = $this->tableRight();
		$middle = $right / 2;
		[$cardWidth,$cardHeight] = $human ? $d['handCard'] : $d['card'];
		$step = $human ? $d['handStep'] : $d['cardStep'];
		$fanWidth = $cardWidth + 4 * $step;
		$plateWidth = $human ? $fanWidth : max($fanWidth, $d['plateWidth']);
		$radius = $human ? $d['handChipRadius'] : $d['chipRadius'];
		// A compact fan sits under the outer end of a wider plate, with its chips beside it rather than below.
		$compact = !$human && $plateWidth > $fanWidth;
		$opponents = max(1, $seats - 1);
		$top = self::MENU_BAR + 10;
		if($human)
		{
			$outward = -1;
			$plateLeft = $middle - $plateWidth / 2;
			$plateTop = $this->handTop();
			$angle = M_PI / 2;
		}
		elseif($opponents === 1 && $this->profile === 'tall')
		{
			$outward = 1;
			$plateLeft = $middle - $plateWidth / 2;
			$plateTop = $top;
			$angle = M_PI * 1.5;
		}
		else
		{
			$leftCount = intdiv($opponents + 1, 2);
			$onLeft = $opponents === 1 ? false : $seat <= $leftCount;
			$count = $onLeft ? $leftCount : $opponents - $leftCount;
			// Rows count down from the top; the left column fills from the bottom so seat 1 is nearest the human.
			$row = $onLeft ? $leftCount - $seat : $seat - $leftCount - 1;
			$blockHeight = self::PLATE_HEIGHT + 8 + $cardHeight + ($compact ? $radius * 1.8 : $radius * 2.2);
			// Phone columns stop above the human's plate; landscape columns flank the hand down to the bottom edge.
			$limit = $this->profile === 'tall' ? $this->handTop() - 14 : $this->height() - 12;
			$span = max(0, $limit - $blockHeight - $top);
			$pitch = $count > 1 ? min($blockHeight + 36, $span / ($count - 1)) : 0;
			$outward = $onLeft ? -1 : 1;
			$plateLeft = $onLeft ? self::IDENTITY : $right - self::IDENTITY - $plateWidth;
			$plateTop = $top + ($span - $pitch * ($count - 1)) / 2 + $row * $pitch;
			$angle = $onLeft ? M_PI : 0.0;
		}
		$plate = [$plateLeft, $plateTop, $plateWidth, self::PLATE_HEIGHT];
		$fanLeft = $compact && $outward > 0 ? $plateLeft + $plateWidth - $fanWidth : $plateLeft;
		$fanTop = $plateTop + self::PLATE_HEIGHT + 8;
		$cards = [];
		for($index = 0; $index < 5; $index++) $cards[] = [$fanLeft + $index * $step, $fanTop, $cardWidth, $cardHeight];
		if($human) $chips = [$this->profile === 'landscape' ? $plateLeft + $plateWidth + 4 : $plateLeft - 4, $fanTop + $cardHeight + 4];
		// Compact chips stand beside the fan on its baseline, so even a twenty-chip stack stays under the plate.
		elseif($compact) $chips = [$outward > 0 ? $fanLeft - 8 : $fanLeft + $fanWidth + 8, $fanTop + $cardHeight];
		// Phone chips stand in front of the fan's inner corner: a tall stack rises over the cards, never over the
		// name and total in the identity column on the outer side.
		else $chips = [$outward > 0 ? $plateLeft + $radius * 3.95 : $plateLeft + $plateWidth - $radius * 3.95, $fanTop + $cardHeight + 4];
		$emblem = [$outward > 0 ? $plateLeft + $plateWidth + self::IDENTITY / 2 : $plateLeft - ($human ? 22 : self::IDENTITY / 2), $plateTop + self::PLATE_HEIGHT / 2];
		$caption = [$emblem[0], $plateTop + 40];
		return ['plate' => $plate, 'cards' => $cards, 'chips' => $chips, 'caption' => $caption, 'emblem' => $emblem, 'angle' => $angle, 'outward' => $outward];
	}

	/** Bounds of every rendered chip in a bank, in design coordinates. */
	public function bankBounds(int|string $owner, int $seats, array $bank): ?array
	{
		$left = $top = INF;
		$right = $bottom = -INF;
		foreach($bank as $value => $count) if($count > 0)
		{
			foreach([0, $count - 1] as $index)
			{
				[$x,$y,$radius] = $this->chip($owner, $seats, (int)$value, $index);
				$left = min($left, $x - $radius);
				$right = max($right, $x + $radius);
				$top = min($top, $y - $radius * .42);
				$bottom = max($bottom, $y + 2.2 + $radius * .42);
			}
		}
		return is_finite($left) ? [$left,$top,$right - $left,$bottom - $top] : null;
	}

	/** Keep the human total centered beneath the YOU icon while its chip bank changes. */
	public function caption(int $seat, int $seats, array $_bank): array
	{
		return $this->seat($seat, $seats)['caption'];
	}

	/** Keep a wide opponent name outside its card fan; narrow names remain centered under the emblem. */
	public static function identityNameX(array $geometry, float $width, float $gap = 4): float
	{
		$centre = (float)$geometry['caption'][0];
		$width = max(0, $width);
		$left = (float)$geometry['cards'][0][0];
		$last = $geometry['cards'][array_key_last($geometry['cards'])];
		$right = (float)$last[0] + (float)$last[2];
		if(($geometry['outward'] ?? -1) > 0)
		return $centre - $width / 2 < $right - 1e-9 ? $right + $gap + $width / 2 : $centre;
		return $centre + $width / 2 > $left + 1e-9 ? $left - $gap - $width / 2 : $centre;
	}

	/**
	 * Design-space geometry of the centre: pot plate, deck, chip anchors and bet plate.
	 *
	 * @return array{pot:array{float,float,float,float},deck:array{float,float,float,float},discard:array{float,float,float,float},bet:array{float,float,float,float},chips:list<array{float,float}>,pile:array{float,float,float,float}}
	 */
	public function centre(): array
	{
		[$cx,$cy] = $this->design['centre'];
		[$cardWidth,$cardHeight] = $this->design['handCard'];
		$plateWidth = min(200, $this->design['width'] - 40);
		if($this->profile === 'landscape') { $cardWidth = 40; $cardHeight = 56; $plateWidth = 150; }
		$lift = $cardHeight * .57;
		if($this->columns())
		{
			// Between the two columns: no wider than the gap they leave, and centred in the height above the hand.
			$cx = $this->tableRight() / 2;
			// Eighteen pixels of felt stay between each column's plates and the pot's.
			$inner = self::IDENTITY + max($this->design['card'][0] + 4 * $this->design['cardStep'], $this->design['plateWidth']) + 18;
			if($this->profile === 'tall') $plateWidth = min($plateWidth, $this->tableRight() - 2 * $inner);
			$cluster = $this->profile === 'landscape' ? self::PLATE_HEIGHT + 14 + $cardHeight + 8 + 56 : self::PLATE_HEIGHT + 56 + 67 + 14 + self::PLATE_HEIGHT + 8 + 56;
			$top = self::MENU_BAR + 10;
			$clusterTop = $top + max(0, ($this->handTop() - 14 - $top - $cluster) / 2);
			// On a phone the pot sits high in the lane, just below where a lone opponent sits across the table, rather
			// than centred: the eye then travels from the pot down to the hand and the controls.
			if($this->profile === 'tall') $clusterTop = $top + self::PLATE_HEIGHT + 8 + $this->design['card'][1] + $this->design['chipRadius'] * 2.2 + 14;
			$cy = $clusterTop + $lift + 38;
		}
		$centre = [
			'pot' => [$cx - $plateWidth / 2, $cy - $lift - 38, $plateWidth, self::PLATE_HEIGHT]
			, 'deck' => [$cx - $cardWidth / 2, $cy - $lift, $cardWidth, $cardHeight]
			, 'discard' => [$cx + $cardWidth / 2 + 16, $cy - $lift, $cardWidth, $cardHeight]
			, 'bet' => [$cx - $plateWidth / 2, $cy + $cardHeight - $lift + 14, $plateWidth, self::PLATE_HEIGHT]
			, 'chips' => [[$cx - 80, $cy + 10], [$cx - 50, $cy + 30], [$cx + 70, $cy + 18], [$cx + 40, $cy + 36]]
		];
		$radius = (float)$this->design['chipRadius'];
		// The loose pot: centre of the pile and its horizontal and vertical spread.
		$centre['pile'] = [$centre['deck'][0] - $radius * 2.8, $centre['deck'][1] + $cardHeight / 2, $radius * 1.6, $radius * 2];
		if($this->profile === 'tall')
		{
			// A phone stacks the cluster in the lane between the columns: pot plate, the loose pot, the stock and
			// discards side by side, then the bet plate, all inside the plate's width.
			[$deckWidth, $deckHeight] = [48, 67];
			$top = $centre['pot'][1];
			$centre['pile'] = [$cx, $top + self::PLATE_HEIGHT + 8 + 20, min(34, $plateWidth / 2 - $radius), 12];
			$centre['deck'] = [$cx - $deckWidth - 6, $top + self::PLATE_HEIGHT + 56, $deckWidth, $deckHeight];
			$centre['discard'] = [$cx + 6, $centre['deck'][1], $deckWidth, $deckHeight];
			$centre['bet'] = [$centre['pot'][0], $centre['deck'][1] + $deckHeight + 14, $plateWidth, self::PLATE_HEIGHT];
		}
		if($this->profile === 'landscape')
		{
			// Put the stock and discards beside the bet, leaving room for status text below it and above the hand.
			$centre['bet'][1] = $centre['deck'][1];
			$centre['deck'][0] = $cx - $plateWidth / 2 - $cardWidth - 12;
			$centre['discard'][0] = $cx + $plateWidth / 2 + 12;
			$centre['chips'][0] = [$cx - $plateWidth / 2 - 33, $centre['deck'][1] - 36];
			// The loose pot sits above the stock, left of the pot plate, clear of the left column.
			$centre['pile'] = [$centre['deck'][0] + $cardWidth / 2, $centre['pot'][1] + self::PLATE_HEIGHT / 2 + 2, 12, 7];
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
			[$x, $y, $spreadX, $spreadY] = $this->centre()['pile'];
			// A fixed low-discrepancy scatter: adding or removing chips never reshuffles the remaining pile.
			$id = $index * count(Chips::VALUES) + $slot + 1;
			$angle = $id * 2.399963229728653;
			$distance = sqrt(fmod($id * .7548776662466927, 1));
			return [$x + cos($angle) * $distance * $spreadX, $y + sin($angle) * $distance * $spreadY, $radius];
		}
		$geometry = $this->seat((int)$owner, $seats);
		[$x, $y] = $geometry['chips'];
		$direction = (((int)$owner !== 0 && $geometry['outward'] > 0)
			|| ((int)$owner === 0 && $this->profile === 'landscape')) ? -1 : 1;
		// Small denominations form the tall rear row; larger, shorter stacks nest closely in front.
		$x = $direction > 0
			? max($radius, min($this->width() - $radius * 4.6, $x))
			: max($radius * 4.6, min($this->width() - $radius, $x));
		$row = intdiv($slot, 2);
		return [$x + $direction * (($slot % 2) * 1.45 + ($row % 2) * .5) * $radius,
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

		if($phase === 'ready' && !empty($legal['next'])) $add('deal', 1.2, 'Deal', '↵', 'primary', ['type' => 'next']);
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
		elseif(!empty($legal['fold']))
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

		$gap = 10;
		if(!empty($legal['draw']) && $phase !== 'ready')
		{
			// The draw controls belong to the discard dialog: its bottom row, right-aligned, or the full row on a phone.
			[$rowX, $rowY, $rowWidth, $rowHeight] = $this->discardDialog()['buttons'];
			$weights = array_sum(array_column($entries, 'weight'));
			$unit = min($this->design['actionRows'] ? INF : 120, ($rowWidth - $gap * (count($entries) - 1)) / $weights);
			$x = $rowX + $rowWidth - $unit * $weights - $gap * (count($entries) - 1);
			$buttons = [];
			foreach($entries as $entry)
			{
				$buttons[] = ['id' => $entry['id'], 'rect' => [$x, $rowY, $entry['weight'] * $unit, $rowHeight], 'label' => $entry['label'], 'key' => $entry['key'], 'style' => $entry['style'], 'intent' => $entry['intent']];
				$x += $entry['weight'] * $unit + $gap;
			}
			return $buttons;
		}

		[$x, $y] = $this->actionOrigin();
		$height = $this->controlSize();
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
	 * @return array{less:array{float,float,float,float},amount:array{float,float,float,float},more:array{float,float,float,float},label:array{float,float},align:string}|null
	 */
	public function stepper(array $view): ?array
	{
		if(($view['screen'] ?? '') !== 'table' || empty($view['legalActions']['bet'])) return null;
		[$x, $y] = $this->actionOrigin();
		$height = $this->controlSize();
		if($this->design['actionRows'])
		{
			// Second row across the bottom, packed against the right edge: the label reads straight into minus, amount and plus.
			$row = $y + $height + 10;
			$size = $height;
			$right = $this->width() - $x;
			$less = $right - $size * 2 - 100;
			return ['less' => [$less, $row, $size, $size], 'amount' => [$right - $size - 90, $row, 80, $size], 'more' => [$right - $size, $row, $size, $size], 'label' => [$less - 10, $row + $size / 2], 'align' => 'right'];
		}
		$row = $y + $height + 10;
		return ['less' => [$x + 80, $row, $height, $height], 'amount' => [$x + 90 + $height, $row, 60, $height], 'more' => [$x + 160 + $height, $row, $height, $height], 'label' => [$x + 70, $row + $height / 2], 'align' => 'right'];
	}

	/**
	 * Design-space geometry of the discard dialog shown while the human draws.
	 *
	 * @param list<int|null> $cards Cards in hand order.
	 * @param list<int> $discards Selected card ids; their raised positions also define their hit regions.
	 * @return array{box:array{float,float,float,float},cards:list<array{float,float,float,float}>,numbers:float,buttons:array{float,float,float,float}}
	 */
	public function discardDialog(array $cards = [], array $discards = []): array
	{
		$width = $this->design['dialogWidth'];
		[$cardWidth,$cardHeight] = $this->design['dialogCard'];
		$gap = ($width - 40 - 5 * $cardWidth) / 4;
		$control = $this->controlSize();
		// Title rule, room for a selected card to lift without covering the title, the cards, their numbers, then
		// the row holding the hint and the draw controls.
		$lift = 12;
		$height = 22 + $lift + $cardHeight + 24 + $control + 12;
		$left = $this->design['width'] / 2 - $width / 2;
		// The dialog opens over the human's own hand at the bottom of the field, as the DOS one did.
		$top = $this->height() - $height - 10;
		if($this->profile === 'landscape') { $width = min($width, $this->tableRight() - 24); $gap = ($width - 40 - 5 * $cardWidth) / 4; $left = 12; }
		$rects = [];
		for($index = 0; $index < 5; $index++)
		{
			$raised = isset($cards[$index]) && in_array($cards[$index], $discards, true) ? $lift : 0;
			$rects[] = [$left + 20 + $index * ($cardWidth + $gap), $top + 22 + $lift - $raised, $cardWidth, $cardHeight];
		}
		return [
			'box' => [$left, $top, $width, $height], 'cards' => $rects
			, 'numbers' => $top + 22 + $lift + $cardHeight + 12
			, 'buttons' => [$left + 20, $top + $height - $control - 10, $width - 40, $control]
		];
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

	/** Compact round-history dialog and its explicit OK target. */
	public function history(): array
	{
		$width = min(640, $this->width() - 24);
		$control = $this->controlSize();
		$box = [$this->width() / 2 - $width / 2, 50, $width, min($this->height() - 62, 260 + $control)];
		return ['box' => $box, 'back' => [$box[0] + $box[2] / 2 - 70, $box[1] + $box[3] - $control - 12, 140, $control]];
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
