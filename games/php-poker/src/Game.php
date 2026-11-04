<?php
declare(strict_types=1);
namespace PhpPoker;

use Phonics\Cards\PileSet;
use Phonics\Random\RandomStream;

/**
 * Deterministic local five-card draw rules and authorized player views.
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
		if($players < 2 || $players > 8) throw new \InvalidArgumentException('Choose 2 to 8 seats');
		$names = ['You',...array_column(Bot::OPPONENTS,'name')];
		$seats = []; $piles = ['stock'=>[['kind'=>'random','cards'=>range(0,51)]], 'discard'=>[], 'holding'=>[]];
		for($id = 0; $id < $players; $id++)
		{
			$seats[] = ['id'=>$id,'name'=>$names[$id],'stack'=>500,'debt'=>0,'folded'=>false,
				'bet'=>0,'totalBet'=>0,'drawCount'=>null,'lastAction'=>'','creditUsed'=>false];
			$piles['hand'.$id] = [];
		}
		$this->piles = new PileSet(range(0,51), $piles, new RandomStream($seed, 'php-poker/deck/v1'));
		$this->state = ['phase'=>'ready','turn'=>-1,'dealer'=>$players-1,'handNumber'=>0,'revision'=>0,
			'players'=>$seats,'pot'=>0,'highest'=>0,'raises'=>0,'opener'=>null,'originalOpenerCards'=>[],
			'pending'=>[],'awards'=>[],'reveal'=>false,'message'=>'','history'=>[]];
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
		unset($view['pending'], $view['originalOpenerCards']);
		foreach($view['players'] as $id => &$player)
		{
			$player['portrait'] = $id === 0 ? 'you' : Bot::OPPONENTS[$id-1]['icon'];
			$player['isHuman'] = $id === 0;
			$cards = $this->cards($id);
			usort($cards, static fn(int $a,int $b): int => (($a%13) <=> ($b%13)) ?: ($a <=> $b));
			$visible = $id === $seat || ($this->state['reveal'] && !$player['folded']);
			$player['cards'] = $visible ? $cards : array_fill(0,count($cards),null);
			$player['handName'] = $visible && count($cards) === 5 ? Hand::evaluate($cards)['name'] : null;
			$player['net'] = $player['stack'] - $player['debt'] - 500;
		}
		unset($player);
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
		$opening = $this->state['phase'] === 'betting' && $this->state['opener'] === null;
		$qualifies = !$opening || $this->profile->variant === 'anything' || Hand::canOpen($this->cards($seat));
		$minimum = $this->state['highest'] + $this->profile->minimum;
		$maximum = min($p['bet'] + $p['stack'], $this->state['highest'] + $this->profile->maximum);
		$canRaise = $qualifies && !$p['creditUsed'] && $maximum >= $minimum
			&& ($this->state['highest'] === 0 || $this->state['raises'] < $this->profile->raiseLimit);
		return ['fold'=>true,'pass'=>$opening && $due === 0,'check'=>!$opening && $due === 0,
			'call'=>$due > 0,'callAmount'=>$due,'bet'=>$canRaise,'minBet'=>$minimum,'maxBet'=>$maximum,
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
		$instance = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
		$instance->profile = Profile::restore($snapshot['profile']);
		$instance->state = $snapshot['state'];
		$instance->piles = PileSet::restore($snapshot['piles']);
		$instance->validate();
		return $instance;
	}

	/** @return void Verifies the complete local state before saving or accepting input. */
	public function validate(): void
	{
		$s = $this->state;
		$fields = ['phase','turn','dealer','handNumber','revision','players','pot','highest','raises','opener',
			'originalOpenerCards','pending','awards','reveal','message','history'];
		if(count($s) !== count($fields) || array_diff($fields,array_keys($s)))
			throw new \InvalidArgumentException('Incomplete poker state');
		if(!in_array($s['phase'] ?? null, ['betting','draw','final-betting','showdown','redeal'], true)
			|| !is_array($s['players'] ?? null) || !array_is_list($s['players'])
			|| count($s['players']) < 2 || count($s['players']) > 8)
			throw new \InvalidArgumentException('Invalid poker state');
		$n = count($s['players']);
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
			Hand::evaluate($this->cards($id));
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
		if($s['opener'] !== null) Hand::evaluate($s['originalOpenerCards']);
		$piles = $this->piles->snapshot();
		$names = ['stock','discard','holding',...array_map(static fn(int $id): string=>'hand'.$id,range(0,$n-1))];
		if($piles['inventory'] !== range(0,51) || count($piles['piles']) !== $n + 3
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
			if($raising) $this->state['raises']++;
			if($this->state['phase'] === 'betting' && $this->state['opener'] === null)
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
		if($this->state['phase'] === 'final-betting') { $this->settle(true); return; }
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
		foreach(['discard','holding',...array_map(static fn(int $id): string => 'hand'.$id, range(0,$n-1))] as $pile)
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
		$this->state['opener'] = null; $this->state['originalOpenerCards'] = [];
		$this->state['reveal'] = false; $this->state['awards'] = [];
		$this->state['phase'] = 'betting';
		$this->state['pending'] = $this->ordered($this->state['dealer'], true);
		$this->state['turn'] = $this->state['pending'][0];
		$this->pay($this->state['dealer'], $this->profile->ante);
		for($round=0; $round<5; $round++) foreach($this->state['pending'] as $id) $this->piles->move('stock','hand'.$id);
		$this->state['message'] = $this->profile->variant === 'jackpots' ? 'Jacks or better to open.' : 'Any hand may open.';
		$this->history('Hand '.$this->state['handNumber'].': '.$this->state['players'][$this->state['dealer']]['name'].' deals.');
	}

	private function finalBetting(): void
	{
		$this->state['phase'] = 'final-betting';
		$this->state['highest'] = $this->state['raises'] = 0;
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
		foreach($active as $id)
		{
			$score = Hand::evaluate($this->cards($id))['score'];
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
			$this->state['awards'][] = ['seat'=>$id,'amount'=>$amount,'handName'=>$reveal ? Hand::evaluate($this->cards($id))['name'] : null];
		}
		$names = array_map(fn(int $id): string => $this->state['players'][$id]['name'], $winners);
		$this->state['message'] = implode(' and ', $names).(count($winners)>1 ? ' split ' : ' wins ').$this->state['pot'].' chips'
			. ($reveal ? ' with '.strtolower(Hand::evaluate($this->cards($winners[0]))['name']) : ' uncontested').'.';
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
