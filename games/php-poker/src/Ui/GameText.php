<?php
declare(strict_types=1);
namespace PhpPoker\Ui;

use PhpPoker\Variant;
use PhpPoker\WildCards;

/**
 * Every word the table shows that depends on which game is dealt.
 *
 * Five-card draw keeps the copy it has always had; hold'em and stud name their own streets, forced bets and limits.
 */
final class GameText
{
	/** Setup choices in the order the Game row cycles through them: a game and, for draw, its opening rule. */
	public const CHOICES = [['draw','jackpots'],['draw','anything'],['holdem','anything'],['stud5','anything'],['stud7','anything']];
	/** Rules pages shown by How to Play. Draw's two opening rules share one page. */
	public const HELP_GAMES = ['draw','holdem','stud5','stud7'];
	/** The page that the PHP Info window sets in the How to Play window, in place of a game's rules. */
	public const PHP_INFO = 'phpinfo';
	/** Hands from best to worst, as every rules page lists them. */
	public const HAND_RANKS = ['Five of a kind', 'Straight flush', 'Four of a kind', 'Full house', 'Flush',
		'Straight', 'Three of a kind', 'Two pair', 'Pair', 'High card'];

	/**
	 * An example of each hand in `HAND_RANKS` order, and what makes it that hand. The cards that make it stand in
	 * groups, with the cards that only fill out the five in a group of their own.
	 *
	 * Card ids are suit times thirteen plus rank less two, suits being clubs, diamonds, hearts and spades; 52 is a
	 * joker, which the first example needs: no plain deck holds five cards of one rank.
	 *
	 * @var list<array{list<list<int>>,string}>
	 */
	public const HAND_EXAMPLES = [
		// Ace of spades, hearts, diamonds and clubs, and a joker.
		[[[51, 38, 25, 12, 52]], 'Four of one rank, and a wild card as the fifth.'],
		// Nine to five of hearts.
		[[[33, 32, 31, 30, 29]], 'Five cards in a row, all of one suit. Ten to ace is a royal flush.'],
		// Four queens and the four of diamonds.
		[[[49, 36, 23, 10], [15]], 'Four cards of one rank.'],
		// Three kings and two sevens, as in the Showdown example.
		[[[50, 37, 24], [5, 44]], 'Three cards of one rank and two of another.'],
		// Ace, jack, eight, six and two of diamonds.
		[[[25, 22, 19, 17, 13]], 'Five cards of one suit that are not in a row.'],
		// Ten of spades, nine of diamonds, eight of hearts, seven of clubs, six of spades.
		[[[47, 20, 32, 5, 43]], 'Five cards in a row, in more than one suit. An ace counts high or low.'],
		// Three eights, the king of clubs and the three of diamonds.
		[[[45, 32, 19], [11, 14]], 'Three cards of one rank.'],
		// Two jacks, two fives and the nine of spades.
		[[[48, 22], [29, 3], [46]], 'Two cards of one rank and two of another.'],
		// Two tens, the ace of spades, the seven of diamonds and the four of clubs.
		[[[34, 8], [51, 18, 2]], 'Two cards of one rank.'],
		// Ace of hearts, jack of clubs, eight of spades, six of diamonds, three of clubs.
		[[[38, 9, 45, 17, 1]], 'None of the above. The highest card decides, then the next.'],
	];

	/** Shown under the list of hands: the one hand a plain deck cannot make. */
	public const HAND_RANKS_NOTE = 'Five of a kind needs a wild card.';

	/** How the wild-card choices on the setup screen work; the same in every game. */
	public const HELP_WILD = 'A wild card stands for any rank and suit. The rules you check combine. Joker adds both jokers to the deck and Custom may add either one; the other rules name cards already in the deck.';

	/** Street names as the menu bar shows them while that street is bet. */
	private const STREETS = [
		'preflop'=>'PRE-FLOP','flop'=>'FLOP','turn'=>'TURN','river'=>'RIVER',
		'second'=>'2ND STREET','third'=>'3RD STREET','fourth'=>'4TH STREET','fifth'=>'5TH STREET',
		'sixth'=>'6TH STREET','seventh'=>'7TH STREET'
	];

	/** @return int Position of a game and opening rule in CHOICES; unknown pairs fall back to draw. */
	public static function choice(string $game, string $variant): int
	{
		foreach(self::CHOICES as $index => [$choiceGame, $choiceVariant])
			if($choiceGame === $game && ($game !== 'draw' || $choiceVariant === $variant)) return $index;
		return 0;
	}

	/** @return string The Game row's button label. */
	public static function choiceLabel(string $game, string $variant): string
	{
		return match($game) {
			'holdem' => "Texas Hold'em", 'stud5' => 'Five-card stud', 'stud7' => 'Seven-card stud',
			default => $variant === 'anything' ? 'Draw · anything opens' : 'Draw · jacks or better'
		};
	}

	/** @return string The game's name in the table's menu bar. */
	public static function title(string $game): string
	{
		return match($game) { 'holdem' => "TEXAS HOLD'EM", 'stud5' => 'FIVE-CARD STUD', 'stud7' => 'SEVEN-CARD STUD', default => 'DRAW POKER' };
	}

	/**
	 * Name the step the table is on for the right of the menu bar.
	 * @param string $stage Motion kind while cards or chips move, otherwise empty.
	 */
	public static function phase(string $game, string $street, string $phase, string $stage): string
	{
		if(in_array($stage, ['deal','draw'], true)) return $stage === 'deal' ? 'DEALING' : 'DRAW';
		if($game === 'draw' || in_array($phase, ['showdown','redeal','ready'], true))
			return match($phase) {
				'betting' => 'OPENING BETS', 'draw' => 'DRAW', 'final-betting' => 'FINAL BETS',
				'showdown' => 'HAND OVER', 'redeal' => 'REDEAL', default => self::title($game)
			};
		return self::STREETS[$street] ?? self::title($game);
	}

	/**
	 * What the right of the table's menu bar reads: the step, then the game, as in "4TH STREET / SEVEN-CARD STUD".
	 * Between hands the step is the game's own name, which is then said once.
	 *
	 * @param string $step The step as `phase()` names it, or a word such as CONFIRMING that stands in for it.
	 */
	public static function barStatus(string $game, string $step): string
	{
		return $step === self::title($game) ? $step : $step . ' / ' . self::title($game);
	}

	/** @return string The welcome card's line naming the game and its rules. */
	public static function welcome(array $profile): string
	{
		$game = (string)($profile['game'] ?? 'draw');
		$wild = (array)($profile['wild'] ?? []);
		$wilds = $wild === [] ? '' : ' · ' . WildCards::selectionLabel($wild) . ' wild';
		if($game === 'draw')
			return '5-CARD DRAW POKER  (' . strtolower((($profile['variant'] ?? 'jackpots') === 'anything' ? 'Anything opens' : 'Jacks or better') . $wilds) . ')';
		$forced = $game === 'holdem'
			? 'blinds ' . min((int)($profile['ante'] ?? 1), (int)($profile['minimum'] ?? 2)) . '/' . (int)($profile['minimum'] ?? 2)
			: 'ante ' . (int)($profile['ante'] ?? 1) . ', bring-in ' . (int)($profile['minimum'] ?? 2);
		return self::title($game) . '  (' . strtolower($forced . $wilds) . ')';
	}

	/** @return array<string,array{string,string}> Label and caption for each limit field on the Limits screen. */
	public static function limits(string $game): array
	{
		$common = ['raiseLimit'=>['Total raises','Whole-table cap'], 'playerRaiseLimit'=>['Raises/player',"Each player's cap"]];
		return match($game) {
			'holdem' => ['ante'=>['Small blind','Left of the dealer'], 'minimum'=>['Early min','Also the big blind'],
				'maximum'=>['Early max','Pre-flop and flop'], 'finalMinimum'=>['Late min','Turn and river'],
				'finalMaximum'=>['Late max','Turn and river']] + $common,
			'stud5', 'stud7' => ['ante'=>['Ante','Every seat pays'], 'minimum'=>['Early min','Also the bring-in'],
				'maximum'=>['Early max','First two streets'], 'finalMinimum'=>['Late min','Later streets'],
				'finalMaximum'=>['Late max','Later streets']] + $common,
			default => ['ante'=>['Ante','Dealer pays pot'], 'minimum'=>['Before min','Minimum bet/raise'],
				'maximum'=>['Before max','Maximum bet/raise'], 'finalMinimum'=>['After min','Minimum bet/raise'],
				'finalMaximum'=>['After max','Maximum bet/raise']] + $common,
		};
	}

	/** @return array<string,string> Row labels of the House Rules window. */
	public static function houseRuleLabels(string $game): array
	{
		$labels = ['table'=>'Table','opening'=>'Opening','wild'=>'Wild cards','ante'=>'Dealer ante',
			'before'=>'Before draw','after'=>'After draw','raises'=>'Raises / round','playerRaises'=>'Raises / player'];
		if($game === 'holdem') return ['opening'=>'Game','ante'=>'Blinds','before'=>'Early streets','after'=>'Turn, river'] + $labels;
		if($game === 'stud5' || $game === 'stud7') return ['opening'=>'Game','ante'=>'Ante, bring-in','before'=>'Early streets','after'=>'Later streets'] + $labels;
		return $labels;
	}

	/** @return array{opening:string,ante:string} The House Rules values that change with the game. */
	public static function houseRuleValues(array $profile): array
	{
		$game = (string)($profile['game'] ?? 'draw');
		$ante = (int)($profile['ante'] ?? 1);
		$minimum = (int)($profile['minimum'] ?? 2);
		if($game === 'holdem') return ['opening'=>"Texas Hold'em", 'ante'=>min($ante, $minimum).' and '.$minimum.' chips'];
		if($game === 'stud5' || $game === 'stud7')
			return ['opening'=>self::choiceLabel($game, 'anything'), 'ante'=>$ante.' '.($ante === 1 ? 'chip' : 'chips').', bring-in '.$minimum];
		return ['opening'=>($profile['variant'] ?? 'jackpots') === 'anything' ? 'Anything opens' : 'Jacks or better',
			'ante'=>$ante.' '.($ante === 1 ? 'chip' : 'chips')];
	}

	/** @return string One line that tells a game from the others, on the How to Play index and at the head of its rules: its cards and how they lie. */
	public static function helpSummary(string $game): string
	{
		return match($game) {
			'holdem' => 'Two down · five shared', 'stud5' => 'One down · four up',
			'stud7' => 'Two down · four up · one down', default => 'Five down · one draw'
		};
	}

	/**
	 * A finished hand as its row of the How to Play index draws it, in dealing order.
	 * @return list<list<?int>> Groups of card ids; null is a card dealt face down.
	 */
	public static function helpCards(string $game): array
	{
		// Ten of spades, jack of clubs, queen of diamonds, king of hearts, ace of spades.
		return match($game) {
			'holdem' => [[null, null], [47, 9, 23], [37], [51]],
			'stud5' => [[null], [9, 23, 37, 51]],
			'stud7' => [[null, null], [9, 23, 37, 51], [null]],
			default => [[null, null, null, null, null]],
		};
	}

	/**
	 * The hand a game's Showdown section sets out: the same full house in every game, dealt the way that game deals it.
	 *
	 * @return array{groups:list<array{string,list<int>}>,plays:list<int>,caption:string} Card ids in dealing order under
	 *   the label of each group, the five of them that make the hand, and what to notice about it.
	 */
	public static function helpExample(string $game): array
	{
		// King of spades, hearts and diamonds; seven of clubs and spades; two of hearts; nine of diamonds.
		[$kingSpades, $kingHearts, $kingDiamonds, $sevenClubs, $sevenSpades, $two, $nine] = [50, 37, 24, 5, 44, 26, 20];
		$plays = [$kingSpades, $kingHearts, $kingDiamonds, $sevenClubs, $sevenSpades];
		$hand = 'Example: a full house, kings over sevens.';
		return match($game) {
			'holdem' => ['groups' => [['Yours', [$kingSpades, $kingHearts]], ['The board', [$kingDiamonds, $sevenClubs, $sevenSpades, $two, $nine]]],
				'plays' => $plays, 'caption' => $hand . ' Both of your cards play with three from the board; the two dimmed cards do not.'],
			'stud5' => ['groups' => [['Down', [$kingSpades]], ['Up', [$kingHearts, $sevenClubs, $kingDiamonds, $sevenSpades]]],
				'plays' => $plays, 'caption' => $hand . ' The table sees two pair until your down card is shown.'],
			'stud7' => ['groups' => [['Down', [$kingSpades, $kingHearts]], ['Up', [$sevenClubs, $two, $kingDiamonds, $nine]], ['Down', [$sevenSpades]]],
				'plays' => $plays, 'caption' => $hand . ' It is the best five of your seven cards; the two dimmed cards do not play.'],
			default => ['groups' => [['Your hand', $plays]],
				'plays' => $plays, 'caption' => $hand . ' Three cards of one rank and two of another.'],
		};
	}

	/** @return list<array{string,string}> A hand from the deal to the showdown: each step's heading and its copy. */
	public static function helpSteps(string $game): array
	{
		$streets = 'Fold, check, call, or raise.';
		return match($game) {
			'holdem' => [
				['Blinds', 'The two seats after the dealer post the small and big blinds.'],
				['Pre-flop', 'Everyone receives two cards face down. Betting starts left of the big blind, who may still raise when everyone only calls.'],
				['Flop, turn, river', 'Three shared cards follow (the flop), then a fourth (the turn) and a fifth (the river), each with a round of betting that starts left of the dealer.'],
				['Betting', $streets . ' Before and on the flop the early limits apply; on the turn and river, the late ones.'],
				['Showdown', 'Your hand is the best five of your two cards and the five on the board. If the board itself is best, everyone left splits the pot.'],
			],
			'stud5', 'stud7' => [
				['The deal', $game === 'stud5'
					? 'Everyone antes and receives one card face down and one face up.'
					: 'Everyone antes and receives two cards face down and one face up.'],
				['Bring-in', 'The lowest card showing brings in for the minimum bet.'],
				['Later streets', ($game === 'stud5'
					? 'Three more cards follow face up, one per street.'
					: 'Three more cards follow face up and a last one face down, one per street.')
					. ' Each brings a round of betting, and the best hand showing acts first.'],
				['Betting', $streets . ' The first two streets use the early limits and the rest use the late ones.'],
				['Showdown', $game === 'stud5' ? 'The best five-card hand wins.' : 'Your hand is the best five of your seven cards.'],
			],
			default => [
				['The deal', 'The dealer antes. Everyone receives five cards face down.'],
				['Opening', 'Jacks or better: a pair of jacks or higher is needed to open. Anything opens: any hand may open.'],
				['First bets', 'Betting starts left of the dealer. Fold, pass or check, call, or raise.'],
				['The draw', 'Tap cards to select discards. Press Draw to replace them, or Keep all to stand pat.'],
				['Final bets', 'Final betting starts with the opener. With no bet to call, check or bet; otherwise call, raise, or fold.'],
				['Showdown', 'The best hand still in wins the pot.'],
			],
		};
	}

	/**
	 * What each key or controller button does at the table.
	 * @param string $theme Input family: `keyboard`, `generic`, `xbox` or `playstation`.
	 * @return list<array{list<string>,string}> Key caps and what they do, most used first.
	 */
	public static function helpControls(string $game, string $theme): array
	{
		if($theme !== 'keyboard')
		{
			$sony = $theme === 'playstation';
			return [
				[['D-pad'], 'Move focus'], [[$sony ? 'X' : 'A'], 'Select'], [[$sony ? 'O' : 'B'], 'Go back'],
				[$sony ? ['L1', 'R1'] : ['LB', 'RB'], 'Change the bet'], [['Start'], 'Leave the table'],
			];
		}
		$draw = $game === 'draw';
		return [
			[['F'], 'Fold'], [['C'], $draw ? 'Call, check or pass' : 'Call or check'], [['B'], 'Bet or raise'],
			[['+', '-'], 'Change the amount'],
			...($draw ? [[['1-5'], 'Select cards'], [['D'], 'Draw']] : []),
			[['H'], 'Round history'], [['?'], 'How to play'], [['Alt+G'], 'Game menu'], [['Alt+P'], 'Poker menu'], [['Esc'], 'Leave the table'],
		];
	}

	/** @return string What to do on a street game's turn, or an empty string when the view's message says it best. */
	public static function streetGuidance(string $street, array $legal): string
	{
		$name = ucfirst(strtolower(self::STREETS[$street] ?? 'Betting'));
		if(!empty($legal['check'])) return $name . ': ' . (!empty($legal['bet']) ? 'check or bet.' : 'check.');
		if(!empty($legal['call'])) return $name . ': ' . (!empty($legal['bet']) ? 'call, raise, or fold.' : 'call or fold.');
		return '';
	}

	/** @return int Most seats a game allows, for the setup stepper. */
	public static function seats(string $game): int { return Variant::of(in_array($game, Variant::ids(), true) ? $game : Variant::DEFAULT)->seats(); }
}
