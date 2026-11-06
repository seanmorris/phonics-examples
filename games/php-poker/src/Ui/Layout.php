<?php
declare(strict_types=1);
namespace PhpPoker\Ui;

/**
 * Pure table layout: where every seat, card, chip stack, plate, button and dialog sits for one frame.
 *
 * Three design profiles share one vocabulary. `wide` is the 1280x720 field of the DOS game, with seats on an
 * oval that can extend vertically on desktop. `tall` is a 470-wide field for phones and `landscape` uses a
 * 400-high field with a right-hand control panel; both grow along their free axis to fill the canvas and seat the
 * opponents in a column
 * down each side, with the pot between them and the human's hand at the bottom, because an oval of full seat
 * blocks does not fit there. Phone fields scale uniformly. A wide field keeps its vertical scale while its x axis
 * compresses independently, so horizontal resizing cannot shrink cards, chips or type. Rendering and hit testing
 * share both axes so a region a player can click is exactly the region that is drawn.
 */
final class Layout
{
	public const MENU_BAR = 28;
	public const PLATE_HEIGHT = 24;
	/** Lower-right angular boundary; a slight upward bias keeps full card faces clear of the action column. */
	public const ACTION_CLEARANCE = -.06;
	/** Width kept for the identity (emblem over name and stack) on the outer side of a column seat. */
	public const IDENTITY = 40;
	/** Width of the short-landscape vertical control panel, including its margins. */
	private const PANEL = 240;
	/** Smallest useful x/y scale ratio for the oval once cards retain their natural aspect ratio. */
	private const WIDE_HORIZONTAL_FIT = .55;

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
			, 'card' => [28,40], 'cardStep' => 12, 'handCard' => [72,101], 'handStep' => 30, 'dialogCard' => [86,120]
			, 'plateWidth' => 112, 'handPlateWidth' => 192, 'plateLift' => 44, 'handPlateLift' => 70, 'handBankLift' => 0
			, 'actions' => [568,250], 'actionRows' => false, 'buttonHeight' => 52, 'chipRadius' => 9, 'handChipRadius' => 11
			, 'menuItem' => [350,52], 'menuTop' => 150, 'dialogWidth' => 530, 'display' => 42
		]
	];

	/** @var array<string,mixed> */
	private readonly array $design;

	/**
	 * @param string $profile `wide`, `tall` or `landscape`.
	 * @param float $scale Device pixels per vertical design pixel and per fixed-size object unit.
	 * @param float $horizontalScale Device pixels per horizontal design pixel.
	 * @param float $left Device x of the field's left edge.
	 * @param float $top Device y of the field's top edge.
	 */
	private function __construct(public readonly string $profile, array $design, public readonly float $scale, public readonly float $horizontalScale, public readonly float $left, public readonly float $top, public readonly float $dpr, public readonly int $seats)
	{
		$this->design = $design;
	}

	/**
	 * Fit the design field to a canvas, choosing the tall profile for portrait and narrow canvases.
	 *
	 * @param int $width Canvas width in device pixels.
	 * @param int $height Canvas height in device pixels.
	 * @param float $dpr Device-pixel ratio; the profile switch happens on CSS pixels.
	 * @param int $seats Players at the table; sparse tables can spend more of the field on each hand.
	 */
	public static function fit(int $width, int $height, float $dpr = 1, int $seats = 8): self
	{
		$dpr = max(.5, min(4, is_finite($dpr) ? $dpr : 1));
		$seats = max(2, min(8, $seats));
		$aspect = $width / max(1, $height);
		$short = $height / $dpr < 480 || ($height / $dpr < 720 && $aspect >= 1.3);
		$wideHorizontalFit = ($width / self::PROFILES['wide']['width'])
			/ max(1e-6, $height / self::PROFILES['wide']['height']);
		$profile = $width >= $height && $short
			? 'landscape'
			: ($width / $dpr < 760 || $wideHorizontalFit < self::WIDE_HORIZONTAL_FIT ? 'tall' : 'wide');
		$design = self::PROFILES[$profile];
		if($profile === 'wide')
		{
			// Fill the canvas vertically from the canonical 720-high field. Width gets its own transform below;
			// changing only the browser width must never feed back into this scale or into any object size.
			$fieldScale = max(1e-6, $height / $design['height']);
			// Wide tables use one stable right-side action column at every aspect ratio. Keeping this independent of
			// horizontal size also lets the menu, status plates, emblems and type retain one height-derived scale.
			$design['verticalActions'] = true;

			// Sparse tables use one CSS size throughout the wide profile. Actual geometry may reduce it before any
			// seat block gains a new overlap. The human fan remains centered; its surrounding objects follow it.
			// Chrome follows available vertical room only. Horizontal resizing may move or resize table objects, but
			// cannot make plates, emblems, controls or type jump between vertical sizes.
			$verticalScale = max($dpr, $fieldScale);
			$design['chromeScale'] = $verticalScale / $fieldScale;
			if(abs($design['chromeScale'] - 1) > 1e-9) $design['emblemSize'] *= $design['chromeScale'];
			$preferredDensity = 1 + match($seats) { 2 => .48, 3 => .40, 4 => .32, default => 0 };
			$growth = $seats <= 4
				? $preferredDensity * $dpr / $fieldScale
				: 1;
			if($seats <= 4 && $growth > 1 && !self::wideGrowthFits($design, $growth, $fieldScale, $dpr, $seats))
			{
				$low = 1.0;
				$high = $growth;
				for($iteration = 0; $iteration < 12; $iteration++)
				{
					$middle = ($low + $high) / 2;
					if(self::wideGrowthFits($design, $middle, $fieldScale, $dpr, $seats)) $low = $middle;
					else $high = $middle;
				}
				$growth = $low;
			}
			if(abs($growth - 1) > 1e-9) $design = self::scaleWideObjects($design, $growth);
			// CPU cards use the same seat-anchor rule as the local hand. Bring their readable faces up to the former
			// human-card height, then make the local faces larger still. Both fans retain their horizontal footprints
			// through tighter overlap, so the extra size spends vertical room without crowding neighboring seats.
			$design = self::scaleWideOpponents($design, $design['handCard'][1] / $design['card'][1]);
			$design = self::scaleWideHuman($design, 1.2);
		}
		// The phone and short-landscape fields grow along whichever axis the canvas leaves free, within limits, so
		// neither is letterboxed while its seats are cramped.
		if($profile !== 'wide')
		{
			if($aspect < $design['width'] / $design['height']) $design['height'] = (int)round(min($profile === 'tall' ? 1100 : 560, $design['width'] / $aspect));
			else $design['width'] = (int)round(min($profile === 'tall' ? 700 : 1200, $design['height'] * $aspect));
			if($profile === 'tall')
			{
				// When the responsive field gains room, let the cards and chips share a little of it. The identity
				// emblems remain 32x32, and the modest cap still leaves a useful centre lane at a full table.
				$widthRoom = min(1, max(0, ($design['width'] - 470) / 230));
				$heightRoom = min(1, max(0, ($design['height'] - 836) / 264));
				$growth = 1 + min(.18, .28 * max($widthRoom, $heightRoom));
				$growth *= 1 + match($seats) { 2 => .32, 3 => .12, 4 => .09, 5 => .06, 6 => .035, 7 => .015, default => 0 };
				foreach(['card','handCard'] as $metric)
					$design[$metric] = array_map(static fn(int|float $value): float => $value * $growth, $design[$metric]);
				foreach(['cardStep','handStep','plateWidth','handPlateWidth','chipRadius','handChipRadius'] as $metric)
					$design[$metric] *= $growth;
			}
		}
		$scale = max(1e-6, min($width / $design['width'], $height / $design['height']));
		$horizontalScale = $scale;
		if($profile === 'wide')
		{
			$scale = max(1e-6, $height / $design['height']);
			$horizontalScale = min($scale, max(1e-6, $width / $design['width']));
			// The x transform tightens positions and overlap, never the card artwork itself. Counter-scale card
			// widths in design space so their rendered aspect ratio follows the vertical/object scale. Seat plates are
			// derived from the resulting fan and therefore remain exactly as wide as the cards they identify.
			$cardWidthScale = $scale / $horizontalScale;
			if($cardWidthScale > 1 + 1e-9) foreach(['card','handCard','dialogCard'] as $metric)
				$design[$metric][0] *= $cardWidthScale;
		}
		return new self($profile, $design, $scale, $horizontalScale, ($width - $design['width'] * $horizontalScale) / 2, ($height - $design['height'] * $scale) / 2, $dpr, $seats);
	}

	/** Grow a wide table's visible objects while preserving its top, bottom and action-side anchors. */
	private static function scaleWideObjects(array $design, float $growth): array
	{
		$oldCardHeight = $design['card'][1];
		$oldHandHeight = $design['handCard'][1];
		foreach(['card','handCard'] as $metric)
			$design[$metric] = array_map(static fn(int|float $value): float => $value * $growth, $design[$metric]);
		foreach(['cardStep','handStep','plateWidth','handPlateWidth','chipRadius','handChipRadius'] as $metric)
			$design[$metric] *= $growth;
		$design['plateLift'] += ($design['card'][1] - $oldCardHeight) / 2;
		$design['handPlateLift'] += $design['handCard'][1] - $oldHandHeight;
		$design['objectScale'] = $growth;
		return $design;
	}

	/** Enlarge opponent card faces while keeping the fan centered in its existing horizontal footprint. */
	private static function scaleWideOpponents(array $design, float $growth): array
	{
		$fanWidth = $design['card'][0] + 4 * $design['cardStep'];
		$design['card'] = array_map(static fn(int|float $value): float => $value * $growth, $design['card']);
		$design['cardStep'] = max(2, ($fanWidth - $design['card'][0]) / 4);
		return $design;
	}

	/** Enlarge the centered local card faces, preserving the fan width and bottom anchor. */
	private static function scaleWideHuman(array $design, float $growth): array
	{
		$fanWidth = $design['handCard'][0] + 4 * $design['handStep'];
		$oldHeight = $design['handCard'][1];
		$design['handCard'] = array_map(static fn(int|float $value): float => $value * $growth, $design['handCard']);
		$design['handStep'] = max(2, ($fanWidth - $design['handCard'][0]) / 4);
		$design['handPlateLift'] += $design['handCard'][1] - $oldHeight;
		return $design;
	}

	/**
	 * A sparse candidate may retain the canonical layout's existing tight clearances, but cannot make one worse.
	 * Comparing named pairs avoids trading a new collision in one part of the table for space gained elsewhere.
	 */
	private static function wideGrowthFits(array $base, float $growth, float $scale, float $dpr, int $seats): bool
	{
		$baseline = new self('wide', $base, $scale, $scale, 0, 0, $dpr, $seats);
		$candidate = new self('wide', self::scaleWideObjects($base, $growth), $scale, $scale, 0, 0, $dpr, $seats);
		$baseOverlaps = self::wideOverlaps($baseline, $seats);
		foreach(self::wideOverlaps($candidate, $seats) as $pair => $area)
			if($area > ($baseOverlaps[$pair] ?? 0) + 1e-6) return false;
		return true;
	}

	/** @return array<string,float> Area of each collision or field overflow in design coordinates. */
	private static function wideOverlaps(self $layout, int $seats): array
	{
		$blocks = [];
		$size = (float)$layout->metric('emblemSize');
		for($seat = 0; $seat < $seats; $seat++)
		{
			$geometry = $layout->seat($seat, $seats);
			$bank = $layout->bankBounds($seat, $seats, Chips::bank(500));
			$left = min($geometry['plate'][0], $geometry['emblem'][0] - $size / 2, $bank[0]);
			$right = max($geometry['plate'][0] + $geometry['plate'][2], $geometry['emblem'][0] + $size / 2, $bank[0] + $bank[2]);
			$top = min($geometry['plate'][1], $bank[1]);
			$bottom = max($geometry['cards'][0][1] + $geometry['cards'][0][3], $bank[1] + $bank[3], $geometry['caption'][1] + ($seat === 0 ? 8 : 22));
			$blocks[$seat] = [$left, $top, $right - $left, $bottom - $top];
		}
		$centre = $layout->centre();
		$chip = (float)$layout->metric('chipRadius');
		$cluster = [min($centre['pot'][0], $centre['deck'][0], $centre['pile'][0] - $centre['pile'][2] - $chip), $centre['pot'][1], 0, 0];
		$cluster[2] = max($centre['pot'][0] + $centre['pot'][2], $centre['discard'][0] + $centre['discard'][2], $centre['pile'][0] + $centre['pile'][2] + $chip) - $cluster[0];
		$cluster[3] = max($centre['bet'][1] + $centre['bet'][3], $centre['deck'][1] + $centre['deck'][3]) + 8 + 56 - $cluster[1];
		$overlaps = [];
		foreach($blocks as $seat => $block)
		{
			// Antialiased chip rims can cross the mathematical edge by a few CSS pixels without clipping their
			// readable face. Do not let that harmless margin resize every card at the portrait transition.
			$edgeTolerance = 4 * $layout->dpr / $layout->scale;
			$overlaps["seat:$seat:field"] = max(0, -$block[0] - $edgeTolerance)
				+ max(0, $block[0] + $block[2] - $layout->width() - $edgeTolerance)
				+ max(0, $layout->menuHeight() + 4 - $block[1] - $edgeTolerance)
				+ max(0, $block[1] + $block[3] - $layout->height() - $edgeTolerance);
			$overlaps["seat:$seat:centre"] = self::intersectionArea($block, $cluster);
		}
		foreach($blocks as $a => $first) foreach($blocks as $b => $second) if($a < $b)
			$overlaps["seat:$a:seat:$b"] = self::intersectionArea($first, $second);
		// Sparse hands may grow only while the permanent action lane remains clear. Reserve the complete vertical
		// button/stepper envelope so the growth solver cannot trade centre space for a control collision.
		$control = $layout->controlSize();
		$actionTop = $layout->height() - 26 - 5 * $control - 4 * 10;
		$actionLane = [$layout->width() - 266, $actionTop, 250, $layout->height() - 26 - $actionTop];
		foreach($blocks as $seat => $block) $overlaps["seat:$seat:actions"] = self::intersectionArea($block, $actionLane);
		return $overlaps;
	}

	private static function intersectionArea(array $a, array $b): float
	{
		return max(0, min($a[0] + $a[2], $b[0] + $b[2]) - max($a[0], $b[0]))
			* max(0, min($a[1] + $a[3], $b[1] + $b[3]) - max($a[1], $b[1]));
	}

	/**
	 * A design metric of the active profile.
	 *
	 * @param string $name Metric name, such as `oval` or `handCard`.
	 * @return mixed
	 */
	public function metric(string $name): mixed { return match($name) { 'buttonHeight' => $this->controlSize(), 'actions' => $this->actionOrigin(), default => $this->design[$name] }; }

	/** Minimum target in design units, measured against CSS pixels rather than the framebuffer. */
	public function controlSize(): float
	{
		$height = $this->design['buttonHeight'] * ($this->verticalActionBar() ? ($this->design['chromeScale'] ?? 1) : 1);
		return max($height, 48 * $this->dpr / $this->scale);
	}

	/** Canvas scale for type: wide chrome follows vertical room while table positions keep using field scale. */
	public function uiScale(): float { return $this->verticalActionBar() ? $this->scale * ($this->design['chromeScale'] ?? 1) : $this->scale; }

	/** Status-plate height in design units, independent of horizontal resizing in the wide layout. */
	public function plateHeight(): float { return self::PLATE_HEIGHT * ($this->design['chromeScale'] ?? 1); }

	/** Menu-bar height in design units, independent of horizontal resizing in the wide layout. */
	public function menuHeight(): float { return self::MENU_BAR * ($this->design['chromeScale'] ?? 1); }

	/** A keycap and its label lane both fit at this rendered button width. */
	public function keyHintFits(float $deviceWidth): bool { return $deviceWidth >= 80 * $this->dpr; }

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

	/** Whether a desktop table stacks its regular actions in the right-side lane. */
	public function verticalActionBar(): bool { return $this->profile === 'landscape' || ($this->profile === 'wide' && !empty($this->design['verticalActions'])); }

	/** Right edge of the table area; the landscape control panel lies beyond it. */
	public function tableRight(): float { return $this->profile === 'landscape' ? $this->width() - self::PANEL : $this->width(); }

	/** Top of the human's plate in a column profile, measured up from the controls or the bottom edge. */
	private function handTop(): float
	{
		// Room is left under the hand for the bank: above the action rows on a phone, above the edge in landscape.
		$bottom = $this->profile === 'tall' ? $this->actionOrigin()[1] - 34 : $this->height() - 26;
		return $bottom - $this->design['handCard'][1] - 8 - $this->plateHeight();
	}

	/** Readable control text size in design units. */
	public function controlText(): float { return max(14, 13 * $this->dpr / $this->uiScale()); }

	/** Caption size in design units, never below eleven CSS pixels however far the field is scaled down. */
	public function captionText(): float { return max(12, 11 * $this->dpr / $this->uiScale()); }

	/** Design width of the field. */
	public function width(): int { return $this->design['width']; }

	/** Design height of the field. */
	public function height(): int { return $this->design['height']; }

	/** Offset used to keep central wide-screen menus aligned as the oval grows taller. */
	public function verticalShift(): float { return (float)($this->design['verticalShift'] ?? 0); }

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
		return [$this->left + $x * $this->horizontalScale, $this->top + $y * $this->scale, $width * $this->horizontalScale, $height * $this->scale];
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
		return [$this->left + $x * $this->horizontalScale, $this->top + $y * $this->scale];
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
		return [($x - $this->left) / $this->horizontalScale, ($y - $this->top) / $this->scale];
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
		$human = $seat === 0;
		$ux = cos($angle);
		$uy = sin($angle);
		// Use the space freed above the opponents by the centered status plate, without lowering the human hand.
		$verticalRadius = $d['oval'][1] + ($uy < 0 ? $d['upperStretch'] : 0);
		// Larger opponent faces need a wider vertical arc. This spacing is independent of the viewport width, so
		// compressing x cannot pull diagonal chip banks into the next player's indicator.
		if(!$human && $seats > 2) $verticalRadius += max(0, $d['card'][1] - self::PROFILES['wide']['card'][1]) * 1.25;
		[$cx,$cy] = [$d['centre'][0] + $ux * $d['oval'][0], $d['centre'][1] + $uy * $verticalRadius];
		// Heads-up sits exactly on the top axis, where cosine rounding used to pick the left treatment. Keep the
		// hand centred but mirror its identity, bank and denomination rows like every opponent on the right side.
		$outward = $seats === 2 && !$human ? 1 : ($ux > .2 ? 1 : -1);
		[$cardWidth,$cardHeight] = $human ? $d['handCard'] : $d['card'];
		$step = $human ? $d['handStep'] : $d['cardStep'];
		$plateWidth = $cardWidth + 4 * $step;
		$chrome = (float)($d['chromeScale'] ?? 1);
		$plateHeight = $this->plateHeight();
		$fanGap = 8 * $chrome;
		$plateTop = $cy - ($human ? $d['handPlateLift'] + $d['handBankLift'] : $d['plateLift'] + 28);
		if($human) $plateTop -= ($plateHeight - self::PLATE_HEIGHT) + ($fanGap - 8);
		// The icon and its two-line caption sit to the left, so the indicator needs no caption lane below it.
		if(!$human) $plateTop = max($this->menuHeight() + 10 * $chrome, $plateTop);
		// The fan is the seat anchor. Plates, identities and chips derive from its bounds; in heads-up neither fan
		// may be pushed off the shared centre line merely to make room for its surrounding chrome.
		$plateLeft = $cx - $plateWidth / 2;
		// Identity blocks sit on the outer side of the table: left of left seats and right of right seats.
		$identity = self::IDENTITY * $chrome;
		$clampPlate = static fn(float $left, int $side): float => max($side > 0 ? 0 : $identity,
			min($d['width'] - $plateWidth - ($side > 0 ? $identity : 0), $left));
		if(!$human && $seats !== 2) $plateLeft = $clampPlate($plateLeft, $outward);
		// At dense tables, translate both complete side groups inward so only their shared seam gap changes.
		if(!$human && $seats >= 7)
		{
			$firstRight = 1;
			while($firstRight < $seats && cos($this->seatAngle($firstRight, $seats)) <= .2) $firstRight++;
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
		$plate = [$plateLeft, $plateTop, $plateWidth, $plateHeight];
		$fanLeft = $plate[0];
		$fanTop = $plate[1] + $plateHeight + $fanGap;
		$cards = [];
		for($index = 0; $index < 5; $index++) $cards[] = [$fanLeft + $index * $step, $fanTop, $cardWidth, $cardHeight];
		// Chips stand in front of the fan's outer corner; the human bank sits beside the hand.
		$humanChipsY = $fanTop + $cardHeight + 4;
		if($human)
		{
			// Fixed-size cards need a fixed-size chip gutter even when a tall desktop's field scale gets small.
			// Tuck the common four-denomination bank slightly into the card corner before its rim can be clipped.
			$bankBottom = 1.3 * $d['handChipRadius'] + 2.2;
			$humanChipsY = min($humanChipsY, $d['height'] - 4 - $bankBottom);
		}
		$chips = $human
			? [$plate[0] - 4, $humanChipsY]
			: [$outward > 0 ? $plate[0] + $plateWidth + 4 : $plate[0] - 4, $fanTop + $cardHeight + 4];
		$emblem = [$outward > 0 && !$human ? $plate[0] + $plate[2] + 20 * $chrome : $plate[0] - ($human ? 22 : 20) * $chrome,
			$plate[1] + $plateHeight / 2];
		// Every identity caption is centered directly beneath its icon; the YOU disc supplies the human's name.
		$caption = [$emblem[0], $plate[1] + 40 * $chrome];
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
		$compact = !$human && $plateWidth > $fanWidth + 1e-6;
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
		// The larger fixed-height wide cards leave the status lane directly above the local hand. Lift the entire
		// centre cluster together so its current-bet plate and message can occupy that lane without stacking.
		if($this->profile === 'wide') $cy -= 30;
		[$cardWidth,$cardHeight] = $this->design['handCard'];
		$chrome = (float)($this->design['chromeScale'] ?? 1);
		$plateHeight = $this->plateHeight();
		$objectScale = $this->profile === 'tall' ? $this->design['card'][0] / 36 : ($this->design['objectScale'] ?? 1);
		$plateWidth = min(200 * $objectScale, $this->design['width'] - 40);
		if($this->profile === 'landscape') { $cardWidth = 40; $cardHeight = 56; $plateWidth = 150; }
		$lift = $cardHeight * .57;
		if($this->columns())
		{
			// Between the two columns: no wider than the gap they leave, and centred in the height above the hand.
			$cx = $this->tableRight() / 2;
			// Full tables reserve a broad lane beside both columns. Sparse tables can use those empty row positions
			// for a wider centre, and heads-up has no side column to constrain it at all.
			$clearance = match($this->seats) { 3 => 2, 4 => 3, 5 => 4, 6 => 6, 7 => 9, default => 18 };
			$inner = self::IDENTITY + max($this->design['card'][0] + 4 * $this->design['cardStep'], $this->design['plateWidth']) + $clearance;
			if($this->profile === 'tall' && $this->seats > 2) $plateWidth = min($plateWidth, $this->tableRight() - 2 * $inner);
			$cluster = $this->profile === 'landscape'
				? self::PLATE_HEIGHT + 14 + $cardHeight + 8 + 56
				: self::PLATE_HEIGHT + 56 * $objectScale + 67 * $objectScale + 14 + self::PLATE_HEIGHT + 8 + 56;
			$top = self::MENU_BAR + 10;
			$clusterTop = $top + max(0, ($this->handTop() - 14 - $top - $cluster) / 2);
			// Use the phone's spare vertical lane. A mild downward bias keeps the cluster near the human hand without
			// crowding its status message or the opponents above it.
			if($this->profile === 'tall')
			{
				$lowest = $this->handTop() - 14 - $cluster;
				// Heads-up has no side rows in the centre lane. Use its full height so the cluster clears the remote
				// cards and finishes directly above the local plate; denser tables retain the gentler shared bias.
				$clusterTop += max(0, $lowest - $clusterTop) * ($this->seats === 2 ? 1 : .65);
			}
			$cy = $clusterTop + $lift + 38;
		}
		$centre = [
			'pot' => [$cx - $plateWidth / 2, $cy - $lift - 38 * $chrome, $plateWidth, $plateHeight]
			, 'deck' => [$cx - $cardWidth / 2, $cy - $lift, $cardWidth, $cardHeight]
			, 'discard' => [$cx + $cardWidth / 2 + 16, $cy - $lift, $cardWidth, $cardHeight]
			, 'bet' => [$cx - $plateWidth / 2, $cy + $cardHeight - $lift + 14 * $chrome, $plateWidth, $plateHeight]
			, 'chips' => [[$cx - 80, $cy + 10], [$cx - 50, $cy + 30], [$cx + 70, $cy + 18], [$cx + 40, $cy + 36]]
		];
		$radius = (float)$this->design['chipRadius'];
		// The loose pot: centre of the pile and its horizontal and vertical spread.
		$centre['pile'] = [$centre['deck'][0] - $radius * 2.8, $centre['deck'][1] + $cardHeight / 2, $radius * 1.6, $radius * 2];
		if($this->profile === 'tall')
		{
			// A phone stacks the cluster in the lane between the columns: pot plate, the loose pot, the stock and
			// discards side by side, then the bet plate, all inside the plate's width.
			[$deckWidth, $deckHeight] = [48 * $objectScale, 67 * $objectScale];
			$top = $centre['pot'][1];
			$centre['pile'] = [$cx, $top + self::PLATE_HEIGHT + 8 + 20 * $objectScale, min(34 * $objectScale, $plateWidth / 2 - $radius), 12 * $objectScale];
			$centre['deck'] = [$cx - $deckWidth - 6 * $objectScale, $top + self::PLATE_HEIGHT + 56 * $objectScale, $deckWidth, $deckHeight];
			$centre['discard'] = [$cx + 6 * $objectScale, $centre['deck'][1], $deckWidth, $deckHeight];
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
	 * Only legal actions get a button; Fold comes first and the primary action last. On phones the buttons fill a
	 * row across the bottom; desktop profiles stack them in the right lane. Each entry carries the intent it sends, its
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

		$height = $this->controlSize();
		if($this->verticalActionBar())
		{
			$width = $this->profile === 'landscape' ? self::PANEL - 32 : min(250, $this->width() - 32);
			$x = $this->width() - $width - 16;
			$bottom = $this->height() - 26;
			if(!empty($legal['bet'])) $bottom -= $height + $gap;
			$y = $bottom - count($entries) * $height - (count($entries) - 1) * $gap;
			$buttons = [];
			foreach($entries as $entry)
			{
				$buttons[] = ['id' => $entry['id'], 'rect' => [$x, $y, $width, $height], 'label' => $entry['label'], 'key' => $entry['key'], 'style' => $entry['style'], 'intent' => $entry['intent']];
				$y += $height + $gap;
			}
			return $buttons;
		}

		[$x, $y] = $this->actionOrigin();
		$weights = array_sum(array_column($entries, 'weight'));
		$unit = match($this->profile) {
			'landscape' => (316 - $gap * (count($entries) - 1)) / $weights,
			'tall' => ($this->design['width'] - 2 * $x - $gap * (count($entries) - 1)) / $weights,
			default => min(160, ($this->design['width'] - $x - 16 - $gap * (count($entries) - 1)) / $weights)
		};
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
		if($this->verticalActionBar())
		{
			$gap = 10;
			$right = $this->width() - 16;
			$row = $this->height() - 26 - $height;
			$more = $right - $height;
			$amount = $more - $gap - 70;
			$less = $amount - $gap - $height;
			return ['less' => [$less, $row, $height, $height], 'amount' => [$amount, $row, 70, $height], 'more' => [$more, $row, $height, $height], 'label' => [$less - $gap, $row + $height / 2], 'align' => 'right'];
		}
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
	 * @return list<array{id:string,rect:array{float,float,float,float},label:string,key:string,intent:array<string,mixed>|null}>
	 */
	public function menu(bool $canContinue): array
	{
		$items = [['offline', 'Play Offline', 'O', ['type' => 'setup']]];
		if($canContinue) $items[] = ['continue', 'Continue Offline', 'C', ['type' => 'resume']];
		$items[] = ['online', 'Play Online', 'P', null];
		$items[] = ['help', 'How to Play', 'H', ['type' => 'help']];
		$items[] = ['settings', 'Settings', 'M', ['type' => 'settings']];
		$box = $this->menuBox($canContinue);
		[$width, $preferredHeight] = $this->design['menuItem'];
		$width = min($width, $box[2] - 48);
		$gap = 4;
		$minimumTouchHeight = 48 * $this->dpr / $this->scale;
		$height = min(max($preferredHeight, $this->controlSize(), $minimumTouchHeight), ($box[3] - 56 - (count($items) - 1) * $gap) / count($items));
		$result = [];
		foreach($items as $index => [$id, $label, $key, $intent])
		{
			$x = $this->width() / 2 - $width / 2;
			$y = $box[1] + 36 + $index * ($height + $gap);
			$result[] = ['id' => $id, 'rect' => [$x, $y, $width, $height], 'label' => $label, 'key' => $key, 'intent' => $intent];
		}

		return $result;
	}

	/** Ruled window containing the title, vertical menu controls and compact art credit. */
	public function menuBox(bool $canContinue): array
	{
		$count = $canContinue ? 5 : 4;
		$preferredHeight = max($this->design['menuItem'][1], $this->controlSize(), 48 * $this->dpr / $this->scale);
		$height = min($this->height() - 24, 56 + $count * $preferredHeight + ($count - 1) * 4);
		$width = min(500, $this->width() - 24);
		return [$this->width() / 2 - $width / 2, max(12, ($this->height() - $height) / 2), $width, $height];
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
		$top = $this->profile === 'landscape' ? $this->height() - $height - 12 : 230 + $this->verticalShift();
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

	/** Help dialog bounds with a fixed scroll row beneath its wrapped copy. */
	public function information(): array
	{
		$width = min(800, $this->width() - 24);
		$left = ($this->width() - $width) / 2;
		$top = 50;
		$height = $this->height() - $top - 12;
		$control = $this->controlSize();
		$row = $top + $height - $control - 12;
		$backWidth = min(140, $width - 152);
		$back = [$this->width() / 2 - $backWidth / 2, $row, $backWidth, $control];
		return ['box' => [$left, $top, $width, $height], 'up' => [$back[0] - 66, $row, 56, $control]
			, 'back' => $back, 'down' => [$back[0] + $back[2] + 10, $row, 56, $control]];
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
