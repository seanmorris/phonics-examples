<?php
declare(strict_types=1);
namespace PhpPoker;

use Phonics\Cards\PileSet;
use Phonics\Random\RandomStream;

/**
 * Deterministic local poker rules and authorized player views.
 *
 * The profile names the game. Its variant lists the streets: each one deals cards face down, face up or to the
 * shared board and then runs a betting round. Five-card draw adds its draw between two such rounds.
 *
 * Validated intents commit atomically and monotonically advance a revision.
 * This known-card dealer implements the local loan profile only; its snapshots
 * must never be accepted as private online allocations or public checkpoints.
 */
final class Game
{
	private array $state;
	private PileSet $piles;

	/**
	 * Start a local table and deal its first hand.
	 * @param string $seed Local replay seed, independent of bot decisions.
	 * @param int $players Total seats, including the human at seat zero.
	 * @param Profile $profile Immutable local rules.
	 */
	public function __construct(string $seed, int $players = 4, private Profile $profile = new Profile)
	{
		$limit = $profile->rules()->seats();
		if($players < 2 || $players > $limit) throw new \InvalidArgumentException('Choose 2 to '.$limit.' seats');
		$names = ['You',...array_column(Bot::OPPONENTS,'name')];
		$deck = $profile->deck();
		$seats = []; $piles = ['stock'=>[['kind'=>'random','cards'=>$deck]], 'discard'=>[], 'holding'=>[], 'board'=>[]];
		for($id = 0; $id < $players; $id++)
		{
			$seats[] = ['id'=>$id,'name'=>$names[$id],'stack'=>500,'debt'=>0,'folded'=>false,
				'bet'=>0,'totalBet'=>0,'drawCount'=>null,'lastAction'=>'','creditUsed'=>false];
			$piles['hand'.$id] = [];
		}
		$this->piles = new PileSet($deck, $piles, new RandomStream($seed, 'php-poker/deck/v1'));
		$this->state = ['phase'=>'ready','turn'=>-1,'dealer'=>$players-1,'handNumber'=>0,'revision'=>0,
			'players'=>$seats,'pot'=>0,'highest'=>0,'raises'=>0,'raiseCounts'=>array_fill(0,$players,0),'opener'=>null,'originalOpenerCards'=>[],
			'pending'=>[],'awards'=>[],'reveal'=>false,'message'=>'','history'=>[],
			'street'=>0,'faces'=>array_fill(0,$players,[])];
		$this->deal();
		$this->validate();
	}

	/**
	 * Commit a legal action once; invalid or stale input leaves state and random streams untouched.
	 * @param int $seat Acting seat.
	 * @param array $intent Action type and any amount/cards payload.
	 * @param int $revision Exact revision the player acted on.
	 * @return void Advances one accepted local action.
	 */
	public function act(int $seat, array $intent, int $revision): void
	{
		if($revision !== $this->state['revision']) throw new \InvalidArgumentException('That turn has already changed');
		$copy = clone $this;
		$copy->piles = PileSet::restore($this->piles->snapshot());
		$copy->apply($seat, $intent);
		$copy->state['revision']++;
		$copy->validate();
		$this->state = $copy->state;
		$this->piles = $copy->piles;
	}

	/**
	 * Produce a redacted view for one seat, or public-only spectator data.
	 * @param int $seat Authorized local seat, or -1 for the public view.
	 * @return array Presentation data containing no stock order or other concealed hand.
	 */
	public function view(int $seat = 0): array
	{
		$view = $this->state;
		unset($view['pending'], $view['originalOpenerCards'], $view['faces']);
		$draw = $this->isDraw();
		$board = $this->board();
		foreach($view['players'] as $id => &$player)
		{
			$player['portrait'] = $id === 0 ? 'you' : Bot::OPPONENTS[$id-1]['icon'];
			$player['isHuman'] = $id === 0;
			if($draw)
			{
				// A draw hand has no dealing order worth showing, so it is presented sorted by rank.
				$cards = $this->cards($id);
				usort($cards, static fn(int $a,int $b): int => (($a%13) <=> ($b%13)) ?: ($a <=> $b));
				$faces = array_fill(0, count($cards), 'down');
			}
			else
			{
				$cards = array_column($this->state['faces'][$id], 0);
				$faces = array_column($this->state['faces'][$id], 1);
			}
			$visible = $id === $seat || ($this->state['reveal'] && !$player['folded']);
			// A face-up card is public while its owner is still in the hand; a folded hand is turned over.
			$shown = !$player['folded'];
			$player['cards'] = array_map(static fn(int $card, string $face): ?int =>
				$visible || ($shown && $face === 'up') ? $card : null, $cards, $faces);
			$player['faces'] = $faces;
			$player['handName'] = $visible && count($cards) + count($board) >= 5
				? Hand::best([...$cards, ...$board], $this->profile->wildCards())['name'] : null;
			$player['net'] = $player['stack'] - $player['debt'] - 500;
		}
		unset($player);
		$view['game'] = $this->profile->game;
		$view['streetIndex'] = $this->state['street'];
		$view['street'] = $this->profile->rules()->street($this->state['street'])['name'];
		$view['board'] = $board;
		$view['mode'] = 'offline';
		$view['profile'] = $this->profile->snapshot();
		$view['legalActions'] = $this->legalActions($seat);
		$view['stockCount'] = $this->piles->count('stock');
		$view['discardCount'] = $this->piles->count('discard');
		return $view;
	}

	/**
	 * Describe legal controls without exposing another seat's opening predicate.
	 * @param int $seat Seat whose turn is being presented.
	 * @return array Legal action flags and exact raise-to bounds.
	 */
	public function legalActions(int $seat): array
	{
		if(in_array($this->state['phase'], ['showdown','redeal'], true))
			return $seat === 0 ? ['next'=>true,'loan'=>$this->state['players'][0]['stack'] === 0 ? 500 : 0] : [];
		if($seat !== $this->state['turn'] || !isset($this->state['players'][$seat])) return [];
		if($this->state['phase'] === 'draw') return ['draw'=>true,'maximumDiscards'=>5];
		$p = $this->state['players'][$seat];
		$due = $this->state['highest'] - $p['bet'];
		$opening = $this->isDraw() && $this->state['phase'] === 'betting' && $this->state['opener'] === null;
		$qualifies = !$opening || $this->profile->variant === 'anything' || Hand::canOpen($this->cards($seat), $this->profile->wildCards());
		// Each street names one of the profile's two limit tiers; draw's final round is its late street.
		$late = $this->profile->rules()->street($this->state['street'])['limits'] === 'late';
		$increment = $late ? $this->profile->finalMinimum : $this->profile->minimum;
		$maximumIncrement = $late ? $this->profile->finalMaximum : $this->profile->maximum;
		$minimum = $this->state['highest'] + $increment;
		$maximum = min($p['bet'] + $p['stack'], $this->state['highest'] + $maximumIncrement);
		$canRaise = $qualifies && !$p['creditUsed'] && $maximum >= $minimum
			&& ($this->state['highest'] === 0 || ($this->state['raises'] < $this->profile->raiseLimit
				&& $this->state['raiseCounts'][$seat] < $this->profile->playerRaiseLimit));
		return ['fold'=>true,'pass'=>$opening && $due === 0,'check'=>!$opening && $due === 0,
			'call'=>$due > 0,'callAmount'=>$due,'bet'=>$canRaise,'minBet'=>$minimum,'maxBet'=>$maximum,
			'betIncrement'=>$increment,
			'betLabel'=>$this->state['highest'] > 0 ? 'Raise to' : ($opening ? 'Open for' : 'Bet'),
			'openingRequired'=>$opening && !$qualifies];
	}

	/** @return array Full local-only continuation state, including concealed hands. */
	public function snapshot(): array
	{
		return ['version'=>1,'mode'=>'offline','profile'=>$this->profile->snapshot(),'state'=>$this->state,'piles'=>$this->piles->snapshot()];
	}

	/** @return int Current actor, or -1 between hands, without building a player view. */
	public function actor(): int { return $this->state['turn']; }
	/** @return string Current phase without allocating a presentation snapshot. */
	public function phase(): string { return $this->state['phase']; }

	/**
	 * Restore local state with card and chip conservation checks.
	 * @param array $snapshot Local snapshot, never a network checkpoint.
	 * @return self Validated game ready at the same revision.
	 */
	public static function restore(array $snapshot): self
	{
		if(($snapshot['version'] ?? null) !== 1 || ($snapshot['mode'] ?? null) !== 'offline'
			|| !is_array($snapshot['state'] ?? null) || !is_array($snapshot['piles'] ?? null)
			|| !is_array($snapshot['profile'] ?? null)) throw new \InvalidArgumentException('Unsupported poker save');
		// Early v3 saves used only the first joker. Preserve the hand and exact existing card order, placing the
		// newly enabled second joker at the bottom of the stock rather than rejecting the continuation.
		if(in_array($snapshot['profile']['version'] ?? null, ['bicycle-inspired-local-v3', Profile::DRAW_ONLY_VERSION, Profile::VERSION], true)
			&& is_array($snapshot['profile']['wild'] ?? null) && in_array('joker', $snapshot['profile']['wild'], true)
			&& ($snapshot['piles']['inventory'] ?? null) === range(0,52)
			&& is_array($snapshot['piles']['piles']['stock'] ?? null))
		{
			$snapshot['piles']['inventory'][] = 53;
			$snapshot['piles']['piles']['stock'][] = ['kind'=>'ordered','cards'=>[53]];
		}
		$instance = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
		$instance->profile = Profile::restore($snapshot['profile']);
		$instance->state = $snapshot['state'];
		if(!array_key_exists('raiseCounts', $instance->state) && is_array($instance->state['players'] ?? null))
			$instance->state['raiseCounts'] = self::restoreRaiseCounts($instance->state);
		// Saves from before the street engine are five-card draw: no board, no exposed cards, and the second street
		// only once the draw has happened.
		if(!array_key_exists('street', $instance->state) && is_array($instance->state['players'] ?? null))
		{
			$drawn = array_filter($instance->state['players'], static fn(mixed $p): bool => is_array($p) && ($p['drawCount'] ?? null) !== null);
			$phase = $instance->state['phase'] ?? null;
			$instance->state['street'] = $phase === 'final-betting' || ($phase === 'showdown' && $drawn) ? 1 : 0;
		}
		if(!array_key_exists('faces', $instance->state) && is_array($instance->state['players'] ?? null))
			$instance->state['faces'] = array_fill(0, count($instance->state['players']), []);
		if(is_array($snapshot['piles']['piles'] ?? null) && !array_key_exists('board', $snapshot['piles']['piles']))
			$snapshot['piles']['piles']['board'] = [];
		$instance->piles = PileSet::restore($snapshot['piles']);
		$instance->validate();
		return $instance;
	}

	/** Reconstruct the small current-round counter added after the original local save format shipped. */
	private static function restoreRaiseCounts(array $state): array
	{
		$players = $state['players'];
		$counts = array_fill(0, count($players), 0);
		$history = is_array($state['history'] ?? null) ? $state['history'] : [];
		$start = 0;
		foreach($history as $index => $line) if(is_string($line))
		{
			if(in_array($state['phase'] ?? '', ['final-betting','showdown'], true)
				&& preg_match('/: (?:Draws [0-5]|Stands pat)\.$/', $line)) $start = $index + 1;
			elseif(!in_array($state['phase'] ?? '', ['final-betting','showdown'], true)
				&& str_starts_with($line, 'Hand ')) $start = $index + 1;
		}
		foreach(array_slice($history, $start) as $line) foreach($players as $seat => $player)
			if(is_string($line) && preg_match('/^'.preg_quote((string)($player['name'] ?? ''),'/').': Raises to [0-9]+\.$/', $line)) $counts[$seat]++;
		$raises = is_int($state['raises'] ?? null) ? $state['raises'] : 0;
		if(array_sum($counts) !== $raises)
		{
			$counts = array_fill(0, count($players), 0);
			for($raise=0; $raise<$raises; $raise++) $counts[$raise % count($players)]++;
		}
		return $counts;
	}

	/** @return void Verifies the complete local state before saving or accepting input. */
	public function validate(): void
	{
		$s = $this->state;
		$fields = ['phase','turn','dealer','handNumber','revision','players','pot','highest','raises','raiseCounts','opener',
			'originalOpenerCards','pending','awards','reveal','message','history','street','faces'];
		if(count($s) !== count($fields) || array_diff($fields,array_keys($s)))
			throw new \InvalidArgumentException('Incomplete poker state');
		if(!in_array($s['phase'] ?? null, ['betting','draw','final-betting','showdown','redeal'], true)
			|| !is_array($s['players'] ?? null) || !array_is_list($s['players'])
			|| count($s['players']) < 2 || count($s['players']) > $this->profile->rules()->seats())
			throw new \InvalidArgumentException('Invalid poker state');
		$n = count($s['players']);
		$rules = $this->profile->rules();
		$draw = $this->isDraw();
		if(!is_int($s['street']) || $s['street'] < 0 || $s['street'] >= count($rules->streets())
			|| !is_array($s['faces']) || !array_is_list($s['faces']) || count($s['faces']) !== $n
			|| ($draw ? ($s['phase'] === 'final-betting') !== ($s['street'] === 1) && $s['phase'] !== 'showdown'
				: in_array($s['phase'], ['draw','final-betting','redeal'], true)))
			throw new \InvalidArgumentException('Invalid poker street');
		$dealt = $rules->dealt($s['street']);
		if($this->piles->count('board') !== $dealt['board']) throw new \InvalidArgumentException('Invalid board');
		if(!is_array($s['raiseCounts'] ?? null) || !array_is_list($s['raiseCounts']) || count($s['raiseCounts']) !== $n)
			throw new \InvalidArgumentException('Invalid raise counts');
		foreach($s['raiseCounts'] as $count)
			if(!is_int($count) || $count < 0 || $count > 2147483647) throw new \InvalidArgumentException('Invalid raise count');
		if(array_sum($s['raiseCounts']) !== $s['raises']) throw new \InvalidArgumentException('Inconsistent raise counts');
		foreach(['turn','dealer','handNumber','revision','pot','highest','raises'] as $field)
			if(!is_int($s[$field] ?? null) || $s[$field] < ($field === 'turn' ? -1 : 0) || $s[$field] > 2147483647)
				throw new \InvalidArgumentException('Invalid poker counter');
		if($s['dealer'] >= $n || $s['turn'] >= $n || $s['handNumber'] < 1 || !is_bool($s['reveal'])
			|| !is_string($s['message']) || strlen($s['message']) > 1024 || !is_array($s['awards']) || !array_is_list($s['awards'])
			|| !is_array($s['history']) || !array_is_list($s['history']) || count($s['history']) > 80
			|| !is_array($s['pending']) || !array_is_list($s['pending']))
			throw new \InvalidArgumentException('Invalid poker turn');
		foreach($s['history'] as $line)
			if(!is_string($line) || strlen($line) > 1024) throw new \InvalidArgumentException('Invalid hand history');
		$awarded = [];
		foreach($s['awards'] as $award)
		{
			if(!is_array($award) || !is_int($award['seat'] ?? null) || $award['seat'] < 0 || $award['seat'] >= $n
				|| isset($awarded[$award['seat']]) || !is_int($award['amount'] ?? null) || $award['amount'] < 0
				|| $award['amount'] > 2147483647 || !array_key_exists('handName',$award)
				|| ($award['handName'] !== null && (!is_string($award['handName']) || strlen($award['handName']) > 80)))
				throw new \InvalidArgumentException('Invalid pot award');
			$awarded[$award['seat']] = true;
		}
		$money = $s['pot'];
		foreach($s['players'] as $id => $p)
		{
			if(!is_array($p) || ($p['id'] ?? null) !== $id || !is_string($p['name'] ?? null) || strlen($p['name']) > 40
				|| !is_bool($p['folded'] ?? null) || !is_bool($p['creditUsed'] ?? null) || !is_string($p['lastAction'] ?? null)
				|| strlen($p['lastAction']) > 120 || !array_key_exists('drawCount',$p))
				throw new \InvalidArgumentException('Invalid poker seat');
			foreach(['stack','debt','bet','totalBet'] as $field)
				if(!is_int($p[$field] ?? null) || $p[$field] < 0 || $p[$field] > 2147483647)
					throw new \InvalidArgumentException('Invalid chip balance');
			if($p['bet'] > $p['totalBet'] || $p['bet'] > $s['highest']
				|| (!is_null($p['drawCount']) && (!is_int($p['drawCount']) || $p['drawCount'] < 0 || $p['drawCount'] > 5)))
				throw new \InvalidArgumentException('Invalid contribution or draw');
			$money += $p['stack'] - $p['debt'];
			$cards = $this->cards($id);
			$faces = $s['faces'][$id];
			if(!is_array($faces) || !array_is_list($faces)) throw new \InvalidArgumentException('Invalid card faces');
			if($draw)
			{
				if($faces !== []) throw new \InvalidArgumentException('Invalid card faces');
				Hand::evaluate($cards, $this->profile->wildCards());
				continue;
			}
			$known = []; $up = 0;
			foreach($faces as $entry)
			{
				if(!is_array($entry) || !array_is_list($entry) || count($entry) !== 2 || !is_int($entry[0])
					|| !in_array($entry[1], ['up','down'], true)) throw new \InvalidArgumentException('Invalid card faces');
				$known[] = $entry[0];
				if($entry[1] === 'up') $up++;
			}
			sort($known, SORT_NUMERIC); sort($cards, SORT_NUMERIC);
			$expected = $dealt['down'] + $dealt['up'];
			// A seat still in the hand holds exactly what the streets so far have dealt; a folded seat keeps what it had.
			if($known !== $cards || $p['drawCount'] !== null || count($cards) < 1 || count($cards) > $expected
				|| (!$p['folded'] && (count($cards) !== $expected || $up !== $dealt['up'])))
				throw new \InvalidArgumentException('Invalid dealt cards');
		}
		if($money !== $n * 500) throw new \InvalidArgumentException('Chips and loans are not conserved');
		foreach($s['pending'] as $id)
			if(!is_int($id) || !isset($s['players'][$id]) || $s['players'][$id]['folded']) throw new \InvalidArgumentException('Invalid pending seat');
		if(count(array_unique($s['pending'])) !== count($s['pending'])) throw new \InvalidArgumentException('Duplicate pending seat');
		$terminal = in_array($s['phase'], ['showdown','redeal'], true);
		if(($terminal && ($s['turn'] !== -1 || $s['pending']))
			|| (!$terminal && (!$s['pending'] || $s['turn'] !== $s['pending'][0])))
			throw new \InvalidArgumentException('Inconsistent turn queue');
		if($s['opener'] !== null && (!is_int($s['opener']) || $s['opener'] < 0 || $s['opener'] >= $n))
			throw new \InvalidArgumentException('Invalid opener');
		if(!is_array($s['originalOpenerCards']) || ($s['opener'] !== null && count($s['originalOpenerCards']) !== 5)
			|| ($s['opener'] === null && $s['originalOpenerCards'] !== []))
			throw new \InvalidArgumentException('Invalid opening snapshot');
		if($s['opener'] !== null) Hand::evaluate($s['originalOpenerCards'], $this->profile->wildCards());
		$piles = $this->piles->snapshot();
		$names = ['stock','discard','holding','board',...array_map(static fn(int $id): string=>'hand'.$id,range(0,$n-1))];
		if($piles['inventory'] !== $this->profile->deck() || count($piles['piles']) !== $n + 4
			|| array_diff($names,array_keys($piles['piles'])) || $this->piles->count('holding') !== 0)
			throw new \InvalidArgumentException('Invalid poker inventory');
		$this->piles->validate();
	}

	private function apply(int $seat, array $intent): void
	{
		$type = $intent['type'] ?? null;
		if(!in_array($type, ['fold','pass','check','call','bet','draw','next'], true))
			throw new \InvalidArgumentException('Unknown poker action');
		$legal = $this->legalActions($seat);
		if(($legal[$type] ?? false) !== true) throw new \InvalidArgumentException('That action is unavailable');
		if($type === 'next') { $this->deal(); return; }
		if($type === 'draw')
		{
			$cards = $intent['cards'] ?? null;
			if(!is_array($cards) || !array_is_list($cards) || count($cards) > 5) throw new \InvalidArgumentException('Invalid discards');
			$this->piles->moveCards('hand'.$seat, 'holding', $cards);
			$remaining = count($cards);
			while($remaining > 0)
			{
				if($this->piles->count('stock') === 0)
				{
					$this->piles->move('discard','stock',$this->piles->count('discard'));
					$this->piles->shuffle('stock');
				}
				$take = min($remaining, $this->piles->count('stock'));
				if($take === 0) throw new \RuntimeException('No cards available for replacement');
				$this->piles->move('stock','hand'.$seat,$take);
				$remaining -= $take;
			}
			$this->piles->move('holding','discard',count($cards));
			$this->state['players'][$seat]['drawCount'] = count($cards);
			$this->record($seat, $cards ? 'Draws '.count($cards) : 'Stands pat');
			array_shift($this->state['pending']);
			if(!$this->state['pending']) $this->finalBetting();
			else $this->state['turn'] = $this->state['pending'][0];
			return;
		}
		if($type === 'bet')
		{
			$amount = $intent['amount'] ?? null;
			if(!is_int($amount) || $amount < $legal['minBet'] || $amount > $legal['maxBet'])
				throw new \InvalidArgumentException('Bet is outside the legal range');
			$raising = $this->state['highest'] > 0;
			$this->pay($seat, $amount - $this->state['players'][$seat]['bet']);
			$this->state['players'][$seat]['bet'] = $amount;
			$this->state['highest'] = $amount;
			if($raising) { $this->state['raises']++; $this->state['raiseCounts'][$seat]++; }
			if($this->isDraw() && $this->state['phase'] === 'betting' && $this->state['opener'] === null)
			{
				$this->state['opener'] = $seat;
				$this->state['originalOpenerCards'] = $this->cards($seat);
			}
			$this->state['pending'] = $this->ordered($seat, false);
			$this->record($seat, ($raising ? 'Raises to ' : 'Bets ').$amount);
		}
		else
		{
			array_shift($this->state['pending']);
			if($type === 'call')
			{
				$this->pay($seat, $legal['callAmount']);
				$this->state['players'][$seat]['bet'] = $this->state['highest'];
				$this->record($seat, 'Calls '.$legal['callAmount']);
			}
			elseif($type === 'fold') { $this->state['players'][$seat]['folded'] = true; $this->record($seat,'Folds'); }
			else $this->record($seat, $type === 'pass' ? 'Passes' : 'Checks');
		}
		$active = $this->ordered($this->state['dealer'], true);
		if(count($active) === 1) { $this->settle(false); return; }
		if($this->state['pending']) { $this->state['turn'] = $this->state['pending'][0]; return; }
		// The betting round is over. The last street goes to showdown; a draw street first needs an opener and then
		// lets each player replace cards; any other street deals its cards and opens the next round.
		$next = $this->profile->rules()->streets()[$this->state['street'] + 1] ?? null;
		if($next === null) { $this->settle(true); return; }
		if(empty($next['draw'])) { $this->street($this->state['street'] + 1); return; }
		if($this->state['opener'] === null)
		{
			$this->state['phase'] = 'redeal';
			$this->state['turn'] = -1;
			$this->state['message'] = 'Everyone passed. The pot carries to the next deal.';
			$this->history($this->state['message']);
			return;
		}
		$this->state['phase'] = 'draw';
		$this->state['pending'] = $this->ordered($this->state['dealer'], true);
		$this->state['turn'] = $this->state['pending'][0];
		$this->state['message'] = 'Choose up to five cards to replace, or stand pat.';
	}

	private function deal(): void
	{
		$n = count($this->state['players']);
		foreach(['discard','holding',...array_map(static fn(int $id): string => 'hand'.$id, range(0,$n-1)),'board'] as $pile)
			$this->piles->move($pile,'stock',$this->piles->count($pile));
		$this->piles->shuffle('stock');
		$this->state['dealer'] = ($this->state['dealer'] + 1) % $n;
		$this->state['handNumber']++;
		foreach($this->state['players'] as &$p)
		{
			if($p['stack'] === 0) { $p['stack'] += 500; $p['debt'] += 500; }
			$p['folded'] = false; $p['bet'] = $p['totalBet'] = 0;
			$p['drawCount'] = null; $p['lastAction'] = ''; $p['creditUsed'] = false;
		}
		unset($p);
		$this->state['highest'] = $this->state['raises'] = 0;
		$this->state['raiseCounts'] = array_fill(0, $n, 0);
		$this->state['opener'] = null; $this->state['originalOpenerCards'] = [];
		$this->state['reveal'] = false; $this->state['awards'] = [];
		$this->state['phase'] = 'betting';
		$this->state['street'] = 0;
		$this->state['faces'] = array_fill(0, $n, []);
		$this->state['pending'] = $this->ordered($this->state['dealer'], true);
		$this->state['turn'] = $this->state['pending'][0];
		$rules = $this->profile->rules();
		if($rules->forced() === 'dealer-ante') $this->pay($this->state['dealer'], $this->profile->ante);
		elseif($rules->forced() === 'ante-bring-in') foreach($this->state['pending'] as $id) $this->pay($id, $this->profile->ante);
		$this->dealStreet(0);
		$this->state['message'] = $this->profile->variant === 'jackpots' ? 'Jacks or better to open.' : 'Any hand may open.';
		$this->history('Hand '.$this->state['handNumber'].': '.$this->state['players'][$this->state['dealer']]['name'].' deals.');
		if(!$this->isDraw()) $this->open();
	}

	/**
	 * Deal one street's cards from the stock: face-down and then face-up cards round the table from the dealer's
	 * left, one at a time, to every seat still in the hand, and then any board cards.
	 */
	private function dealStreet(int $index): void
	{
		$deal = $this->profile->rules()->street($index)['deal'];
		$order = $this->ordered($this->state['dealer'], true);
		$draw = $this->isDraw();
		foreach(['down','up'] as $face) for($round = 0; $round < $deal[$face]; $round++) foreach($order as $id)
		{
			$moved = $this->piles->move('stock', 'hand'.$id);
			if(!$draw) $this->state['faces'][$id][] = [$moved[0], $face];
		}
		if($deal['board'] > 0) $this->piles->move('stock', 'board', $deal['board']);
	}

	/** Begin a later street of a game without a draw: clear the round's bets, deal, and open the betting. */
	private function street(int $index): void
	{
		$this->state['street'] = $index;
		$this->state['phase'] = 'betting';
		$this->state['highest'] = $this->state['raises'] = 0;
		$this->state['raiseCounts'] = array_fill(0, count($this->state['players']), 0);
		foreach($this->state['players'] as &$p) $p['bet'] = 0;
		unset($p);
		$before = $this->piles->count('board');
		$this->dealStreet($index);
		$name = ucfirst($this->profile->rules()->street($index)['name']);
		$added = array_slice($this->board(), $before);
		$this->history($added ? $name.': '.implode(', ', array_map(Hand::label(...), $added)).'.' : $name.' street.');
		$this->open();
	}

	/**
	 * Post the current street's forced bets and queue its betting round by the street's first-actor rule.
	 *
	 * Blinds sit to the dealer's left, except heads-up where the dealer posts the small blind and acts first before
	 * the flop. In stud the lowest exposed card brings in for the minimum bet on the first street, and the best
	 * exposed hand acts first afterwards; ties go to the seat nearest the dealer's left.
	 */
	private function open(): void
	{
		$street = $this->profile->rules()->street($this->state['street']);
		$active = $this->ordered($this->state['dealer'], true);
		$forced = function(int $seat, int $amount, string $label): void {
			$this->pay($seat, $amount);
			$this->state['players'][$seat]['bet'] = $amount;
			$this->state['highest'] = max($this->state['highest'], $amount);
			$this->record($seat, $label.' '.$amount);
		};
		$from = function(int $first): array {
			$queue = $this->ordered($first, false);
			array_unshift($queue, $first);
			return $queue;
		};
		$exposed = fn(int $seat): array => array_column(array_filter($this->state['faces'][$seat],
			static fn(array $entry): bool => $entry[1] === 'up'), 0);
		if($street['first'] === 'left-of-blinds')
		{
			$small = count($active) === 2 ? $this->state['dealer'] : $active[0];
			$big = count($active) === 2 ? $active[0] : $active[1];
			$forced($small, min($this->profile->ante, $this->profile->minimum), 'Small blind');
			$forced($big, $this->profile->minimum, 'Big blind');
			// Everyone answers the big blind in turn, and the big blind acts last with the option to raise.
			$this->state['pending'] = $this->ordered($big, true);
			$this->state['message'] = 'Blinds are posted.';
		}
		elseif($street['first'] === 'low-up')
		{
			$low = null; $lowest = PHP_INT_MAX;
			foreach($active as $seat)
			{
				$card = $exposed($seat)[0];
				// Lowest rank brings in; equal ranks are separated by suit, clubs lowest. Jokers rank above aces.
				$value = $card >= 52 ? 1000 + $card : ($card % 13) * 4 + intdiv($card, 13);
				if($value < $lowest) { $lowest = $value; $low = $seat; }
			}
			$forced($low, $this->profile->minimum, 'Brings in');
			$this->state['pending'] = $this->ordered($low, false);
			$this->state['message'] = $low === 0 ? 'You bring in.' : $this->state['players'][$low]['name'].' brings in.';
		}
		elseif($street['first'] === 'high-showing')
		{
			$high = null; $best = -1;
			foreach($active as $seat)
			{
				$score = Hand::showing($exposed($seat), $this->profile->wildCards())['score'];
				if($score > $best) { $best = $score; $high = $seat; }
			}
			$this->state['pending'] = $from($high);
			$this->state['message'] = $high === 0 ? 'You show the best hand and act first.'
				: $this->state['players'][$high]['name'].' shows the best hand and acts first.';
		}
		else
		{
			$this->state['pending'] = $active;
			$this->state['message'] = ucfirst($street['name']).' betting.';
		}
		$this->state['turn'] = $this->state['pending'][0];
	}

	private function finalBetting(): void
	{
		$this->state['phase'] = 'final-betting';
		$this->state['street'] = 1;
		$this->state['highest'] = $this->state['raises'] = 0;
		$this->state['raiseCounts'] = array_fill(0, count($this->state['players']), 0);
		foreach($this->state['players'] as &$p) $p['bet'] = 0;
		unset($p);
		$opener = $this->state['opener'];
		$this->state['pending'] = $this->ordered($opener, false);
		if(!$this->state['players'][$opener]['folded']) array_unshift($this->state['pending'], $opener);
		$this->state['turn'] = $this->state['pending'][0];
		$this->state['message'] = 'Final betting round.';
	}

	private function settle(bool $reveal): void
	{
		$active = $this->ordered($this->state['dealer'], true);
		$best = -1; $winners = [];
		// An uncontested pot is won without a hand, which may not even be complete yet.
		if(count($active) === 1) $winners = $active;
		else foreach($active as $id)
		{
			$score = $this->strength($id)['score'];
			if($score > $best) { $best = $score; $winners = [$id]; }
			elseif($score === $best) $winners[] = $id;
		}
		$each = intdiv($this->state['pot'], count($winners));
		$odd = $this->state['pot'] % count($winners);
		$this->state['awards'] = [];
		foreach($winners as $i => $id)
		{
			$amount = $each + ($i < $odd ? 1 : 0);
			$this->state['players'][$id]['stack'] += $amount;
			$this->state['awards'][] = ['seat'=>$id,'amount'=>$amount,'handName'=>$reveal ? $this->strength($id)['name'] : null];
		}
		$names = array_map(fn(int $id): string => $this->state['players'][$id]['name'], $winners);
		$this->state['message'] = implode(' and ', $names).(count($winners)>1 ? ' split ' : ' wins ').$this->state['pot'].' chips'
			. ($reveal ? ' with '.strtolower($this->strength($winners[0])['name']) : ' uncontested').'.';
		$this->state['pot'] = 0;
		$this->state['phase'] = 'showdown'; $this->state['turn'] = -1;
		$this->state['pending'] = []; $this->state['reveal'] = $reveal;
		$this->history($this->state['message']);
	}

	private function pay(int $seat, int $amount): void
	{
		$p = &$this->state['players'][$seat];
		if($amount > $p['stack'])
		{
			$loan = $amount - $p['stack'];
			$p['debt'] += $loan; $p['stack'] += $loan; $p['creditUsed'] = true;
		}
		$p['stack'] -= $amount; $p['totalBet'] += $amount; $this->state['pot'] += $amount;
	}

	private function ordered(int $after, bool $include): array
	{
		$result=[]; $n=count($this->state['players']);
		for($step=1; $step<=($include ? $n : $n-1); $step++)
		{
			$id=($after+$step)%$n;
			if(!$this->state['players'][$id]['folded']) $result[]=$id;
		}
		return $result;
	}

	private function cards(int $seat): array { return $this->piles->peek('hand'.$seat, $this->piles->count('hand'.$seat)); }
	/** @return list<int> Shared board cards in the order they were dealt. */
	private function board(): array { return $this->piles->peek('board', $this->piles->count('board')); }
	/** @return bool Whether this table deals five-card draw, the one game with an opener and a draw. */
	private function isDraw(): bool { return $this->profile->game === Variant::DEFAULT; }
	/** @return array The best five-card hand a seat makes from its own cards and the board. */
	private function strength(int $seat): array { return Hand::best([...$this->cards($seat), ...$this->board()], $this->profile->wildCards()); }
	private function record(int $seat, string $action): void
	{
		$this->state['players'][$seat]['lastAction']=$action;
		$this->history($this->state['players'][$seat]['name'].': '.$action.'.');
	}
	private function history(string $line): void
	{
		$this->state['history'][]=$line;
		$this->state['history']=array_slice($this->state['history'],-80);
	}
}
