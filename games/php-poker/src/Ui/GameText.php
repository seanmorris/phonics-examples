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
			'holdem' => ['ante'=>['Small blind','Left of the dealer'], 'minimum'=>['Early min','Big blind · pre-flop, flop'],
				'maximum'=>['Early max','Pre-flop and flop'], 'finalMinimum'=>['Late min','Turn and river'],
				'finalMaximum'=>['Late max','Turn and river']] + $common,
			'stud5', 'stud7' => ['ante'=>['Ante','Every seat pays'], 'minimum'=>['Early min','Bring-in · first two streets'],
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

	/** @return list<string> Help paragraphs for the game, shared by the help window and its scroll stops. */
	public static function help(string $game, bool $controller): array
	{
		$ranking = 'Best to worst: five of a kind, straight flush, four of a kind, full house, flush, straight, three of a kind, two pair, pair, high card.';
		$wild = 'Each checked wild-card rule may be combined with the others. Wild cards may stand for any missing rank and suit. Joker adds both jokers to the deck; Custom may add either one. Other choices designate cards already in the 52-card deck.';
		$controls = $controller ? 'Controller: use the D-pad or left stick to move focus, then the confirm button to select.' : 'Keys: F fold, C call/check/pass, B bet/raise, + and - change the amount.';
		$more = $controller ? 'The back button goes back, Start asks before leaving the table, and the shoulder buttons change the bet.'
			: ($game === 'draw' ? '1 to 5 select cards, D draws, H shows history, Alt+G and Alt+P open the table menus, and Escape asks before leaving.'
				: 'H shows history, Alt+G and Alt+P open the table menus, and Escape asks before leaving.');
		return match($game) {
			'holdem' => [
				'The two seats after the dealer post the small and big blinds. Everyone receives two cards face down.',
				'Betting starts left of the big blind, who may still raise when everyone only calls. Fold, check, call, or raise.',
				'Three shared cards follow (the flop), then a fourth (the turn) and a fifth (the river), each with a round of betting that starts left of the dealer.',
				'Your hand is the best five of your two cards and the five on the board. If the board itself is best, everyone left splits the pot.',
				$wild, $ranking, $controls, $more
			],
			'stud5', 'stud7' => [
				$game === 'stud5'
					? 'Everyone antes and receives one card face down and one face up, then three more face up, one per street.'
					: 'Everyone antes and receives two cards face down and one face up, then three more face up and a last one face down.',
				'The lowest card showing brings in for the minimum bet. On later streets the best hand showing acts first.',
				'Fold, check, call, or raise. The first two streets use the early limits and the rest use the late ones.',
				$game === 'stud5' ? 'At the showdown the best five-card hand wins.' : 'At the showdown your hand is the best five of your seven cards.',
				$wild, $ranking, $controls, $more
			],
			default => [
				'The dealer antes. Everyone receives five cards face down.',
				'Jacks or better: a pair of jacks or higher is needed to open. Anything opens: any hand may open.',
				$wild,
				'Betting starts left of the dealer. Fold, pass or check, call, or raise.',
				'Draw: tap cards to select discards. Press Draw to replace them, or Keep all to stand pat.',
				'After the draw, final betting starts with the opener. With no bet to call, check or bet; otherwise call, raise, or fold.',
				$ranking, $controls, $more
			],
		};
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
