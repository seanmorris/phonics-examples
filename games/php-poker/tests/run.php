<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
$root=getenv('PHONICS_ENGINE_ROOT') ?: dirname(__DIR__,3).'/node_modules/@seanmorris/phonics';
require $root.'/autoload.php';

use PhpPoker\{Game,Hand,Profile,Bot,Adaptation,Session,Variant,WildCards};
use Phonics\Random\RandomStream;

$tests=[];
$test=static function(string $name,callable $run) use (&$tests): void { $tests[$name]=$run; };
$expect=static function(bool $ok,string $message='Assertion failed'): void { if(!$ok) throw new RuntimeException($message); };
$reject=static function(callable $run) use ($expect): void {
	try { $run(); } catch(InvalidArgumentException) { return; }
	$expect(false,'Expected invalid action rejection');
};
$act=static function(Game $g,array $intent): void { $v=$g->view(-1); $g->act($v['turn']<0?0:$v['turn'],$intent,$v['revision']); };
$rig=static function(Game $game,array $hands): Game {
	$s=$game->snapshot(); $used=[];
	foreach($hands as $id=>$cards) { $s['piles']['piles']['hand'.$id]=[['kind'=>'ordered','cards'=>$cards]]; $used=array_merge($used,$cards); }
	$s['piles']['piles']['stock']=[['kind'=>'ordered','cards'=>array_values(array_diff($s['piles']['inventory'],$used))]];
	return Game::restore($s);
};
$test('the variant table matches variants.json and describes each game',static function() use ($expect,$reject): void {
	$published=json_decode(file_get_contents(dirname(__DIR__).'/variants.json'),true,flags:JSON_THROW_ON_ERROR);
	$expect($published===['version'=>1,'games'=>Variant::GAMES],'variants.json and Variant::GAMES differ');
	$expect(Variant::ids()===['draw','holdem','stud5','stud7']&&Variant::DEFAULT==='draw');
	$shape=[];
	foreach(Variant::ids() as $id)
	{
		$rules=Variant::of($id);
		$shape[$id]=[$rules->handSize(),$rules->boardSize(),$rules->seats(),count($rules->streets()),$rules->bestOfMany()];
		// A full table never needs more cards than the smallest deck holds.
		$expect($rules->handSize()*$rules->seats()+$rules->boardSize()<=52||$id==='draw',$id.' can outrun the deck');
		foreach($rules->streets() as $street)
			$expect(in_array($street['limits'],['early','late'],true)&&array_keys($street['deal'])===['down','up','board']);
	}
	$expect($shape===['draw'=>[5,0,8,2,false],'holdem'=>[2,5,8,4,true],'stud5'=>[5,0,8,4,false],'stud7'=>[7,0,7,5,true]]);
	$expect(Variant::of('holdem')->dealt(1)===['down'=>2,'up'=>0,'board'=>3]&&Variant::of('stud7')->dealt(3)===['down'=>2,'up'=>4,'board'=>0]);
	$expect(Variant::of('draw')->street(1)['draw']===true&&Variant::of('stud5')->forced()==='ante-bring-in');
	$reject(static fn()=>Variant::of('omaha'));
	$reject(static fn()=>Variant::of('draw')->street(2));
});
$test('profiles carry the game and older profiles restore as five-card draw',static function() use ($expect,$reject): void {
	$expect((new Profile)->game==='draw'&&(new Profile)->snapshot()['version']==='bicycle-inspired-local-v5');
	$holdem=new Profile('anything',game:'holdem');
	$expect(Profile::restore($holdem->snapshot())->snapshot()===$holdem->snapshot()&&$holdem->rules()->label()==="Texas Hold'em");
	$v4=['version'=>'bicycle-inspired-local-v4','variant'=>'anything','ante'=>1,'minimum'=>2,'maximum'=>20,
		'finalMinimum'=>3,'finalMaximum'=>9,'raiseLimit'=>3,'playerRaiseLimit'=>2,'wild'=>[],'customWild'=>[]];
	$old=Profile::restore($v4);
	$expect($old->game==='draw'&&$old->finalMaximum===9&&$old->playerRaiseLimit===2);
	$reject(static fn()=>new Profile(game:'omaha'));
	$reject(static fn()=>Profile::restore(['game'=>'draw']+$v4));
	$bad=$holdem->snapshot(); $bad['game']=7;
	$reject(static fn()=>Profile::restore($bad));
	$session=new Session;
	$session->command(['type'=>'start','players'=>8,'game'=>'stud7'],'seat-cap');
	$expect(str_contains($session->view()['error']??'','seats at most 7'),'Seven-card stud caps at seven seats');
	$session->command(['type'=>'start','players'=>4,'game'=>'holdem'],'not-yet');
	$expect(str_contains($session->view()['error']??'','not available yet'));
	$session->command(['type'=>'start','players'=>4],'draw-default');
	$expect(($session->view()['error']??'')===''&&$session->view()['profile']['game']==='draw');
});
$test('best five of seven matches an exhaustive subset search and names board plays',static function() use ($expect,$reject): void {
	// Cards: id % 13 + 2 is the rank, intdiv(id, 13) the suit (clubs, diamonds, hearts, spades).
	$flush=Hand::best([0,2,5,8,11,13,26]);
	$expect($flush['category']===5&&$flush['cards']===[0,2,5,8,11],'Flush over the pair of twos');
	$expect(Hand::best([8,9,10,11,12,25,38])['name']==='Royal flush');
	$expect(Hand::best([12,25,38,11,24,0,1])['category']===6,'Aces full');
	$expect(Hand::best([12,13,27,41,3,20,34])['ranks']===[5],'Wheel found among seven');
	$expect(Hand::best([0,14,29,44,11])['score']===Hand::evaluate([0,14,29,44,11])['score'],'Five cards pass through');
	$expect(Hand::best([9,22,35,48,0,1,52],[52])['name']==='Five of a kind');
	$combine=static function(array $cards,int $size) use (&$combine): array {
		if($size===0) return [[]];
		$out=[];
		foreach($cards as $i=>$card) foreach($combine(array_slice($cards,$i+1),$size-1) as $rest) $out[]=[$card,...$rest];
		return $out;
	};
	$random=new RandomStream('best-of-seven','php-poker/tests/v1');
	for($trial=0;$trial<300;$trial++)
	{
		$deck=range(0,51); $cards=[];
		for($i=0;$i<7;$i++) { $at=$random->boundedInt(count($deck)); $cards[]=$deck[$at]; array_splice($deck,$at,1); }
		$size=$trial%3+5; $cards=array_slice($cards,0,$size);
		$wild=$trial%5===0?[$cards[0]]:[];
		$top=0;
		foreach($combine($cards,5) as $subset) $top=max($top,Hand::evaluate($subset,$wild)['score']);
		$best=Hand::best($cards,$wild);
		$expect($best['score']===$top,'Best of '.$size.' disagrees with exhaustive search');
		$expect(Hand::evaluate($best['cards'],$wild)['score']===$top&&count(array_intersect($best['cards'],$cards))===5);
	}
	$reject(static fn()=>Hand::best([0,1,2,3]));
	$reject(static fn()=>Hand::best([0,1,2,3,4,5,6,7]));
	$reject(static fn()=>Hand::best([0,0,1,2,3,4]));
});
$test('stud showing hands order pairs above high cards and never count straights or flushes',static function() use ($expect,$reject): void {
	$score=static fn(array $cards,array $wild=[]): int=>Hand::showing($cards,$wild)['score'];
	$expect($score([12])>$score([11]),'Ace above king');
	$expect($score([0,13])>$score([12,24]),'Pair of twos above ace-king');
	$expect($score([12,24,10])>$score([12,24,9]),'Third kicker breaks the tie');
	$expect($score([0,13,26])>$score([12,25,11]),'Trips above a pair');
	$expect($score([0,13,1,14])>$score([12,25,11,10]),'Two pair above one pair');
	$expect($score([0,13,26,39])>$score([12,25,38,11]),'Quads above trips');
	$expect(Hand::showing([8,9,10,11])['category']===0,'Four to a straight flush shows as high card');
	$expect($score([12])===$score([25]),'Suits do not rank');
	$expect(Hand::showing([52,5],[52])['category']===1&&Hand::showing([52],[52])['ranks']===[14],'Wild cards pair up or stand as aces');
	$reject(static fn()=>Hand::showing([]));
	$reject(static fn()=>Hand::showing([0,1,2,3,4]));
	$reject(static fn()=>Hand::showing([52]));
});
$deal=static function(Game $game,array $hands,array $stock=[]): Game {
	// Rig a freshly dealt table of a street game: each seat's cards as [id, face] pairs in dealing order, and the
	// cards that will come off the stock next. Forced bets already posted are kept.
	$s=$game->snapshot(); $used=$stock;
	foreach($hands as $id=>$faces)
	{
		$cards=array_column($faces,0);
		$s['piles']['piles']['hand'.$id]=[['kind'=>'ordered','cards'=>$cards]];
		$s['state']['faces'][$id]=$faces; $used=array_merge($used,$cards);
	}
	$s['piles']['piles']['stock']=[['kind'=>'ordered','cards'=>[...$stock,...array_values(array_diff($s['piles']['inventory'],$used))]]];
	return Game::restore($s);
};
$test('saves from before the street engine continue as five-card draw',static function() use ($expect,$act): void {
	$saves=json_decode(file_get_contents(__DIR__.'/fixtures/pre-street-saves.json'),true,flags:JSON_THROW_ON_ERROR);
	$expect(array_keys($saves)===['betting','draw','final-betting','showdown']);
	foreach($saves as $phase=>$save)
	{
		$expect(!isset($save['state']['street'])&&!isset($save['piles']['piles']['board'])&&$save['profile']['version']==='bicycle-inspired-local-v4');
		$game=Game::restore($save); $view=$game->view(0);
		$expect($view['phase']===$phase&&$view['game']==='draw'&&$view['board']===[],'Old '.$phase.' save');
		$drawn=(bool)array_filter(array_column($save['state']['players'],'drawCount'),'is_int');
		$expect($view['street']===($phase==='final-betting'||($phase==='showdown'&&$drawn)?'final':'opening'),'Street of old '.$phase.' save');
		$expect($view['players'][0]['faces']===array_fill(0,5,'down')&&count(array_filter($view['players'][1]['cards'],'is_int'))===($view['reveal']&&!$view['players'][1]['folded']?5:0));
		$again=Game::restore($game->snapshot());
		$expect($again->snapshot()===$game->snapshot()&&$game->snapshot()['profile']['version']==='bicycle-inspired-local-v5');
		for($step=0;$step<12;$step++)
		{
			$turn=$game->view(-1)['turn']; $legal=$game->view(max(0,$turn))['legalActions'];
			$act($game,match(true){ $turn<0=>['type'=>'next'], !empty($legal['draw'])=>['type'=>'draw','cards'=>[]],
				$legal['call']=>['type'=>'call'], $legal['check']=>['type'=>'check'], default=>['type'=>'pass'] });
		}
	}
});
$test('hold\'em posts blinds, orders each street and gives the big blind its option',static function() use ($expect,$reject,$act): void {
	$g=new Game('holdem-ring',4,new Profile('anything',ante:1,minimum:2,maximum:6,finalMinimum:4,finalMaximum:12,game:'holdem'));
	$v=$g->view(0);
	$expect($v['dealer']===0&&$v['game']==='holdem'&&$v['street']==='preflop'&&$v['board']===[]);
	$expect($v['players'][1]['bet']===1&&$v['players'][1]['lastAction']==='Small blind 1'&&$v['players'][2]['bet']===2&&$v['pot']===3);
	$expect($v['turn']===3&&$v['highest']===2,'Left of the big blind acts first');
	$expect(count($v['players'][0]['cards'])===2&&is_int($v['players'][0]['cards'][0])&&$v['players'][3]['cards']===[null,null]&&$v['players'][0]['handName']===null);
	$legal=$g->view(3)['legalActions'];
	$expect($legal['call']&&$legal['callAmount']===2&&!$legal['pass']&&!$legal['check']&&$legal['minBet']===4&&$legal['maxBet']===8&&$legal['betLabel']==='Raise to'&&!$legal['openingRequired']);
	$act($g,['type'=>'call']); $act($g,['type'=>'call']); $act($g,['type'=>'call']);
	$option=$g->view(2);
	$expect($option['turn']===2&&$option['street']==='preflop'&&$option['legalActions']['check']&&$option['legalActions']['bet'],'The big blind may check or raise');
	$reject(static fn()=>$g->act(2,['type'=>'draw','cards'=>[]],$option['revision']));
	$act($g,['type'=>'check']);
	$flop=$g->view(0);
	$expect($flop['street']==='flop'&&count($flop['board'])===3&&$flop['turn']===1&&$flop['highest']===0&&$flop['pot']===8,'Flop opens left of the dealer');
	$expect($flop['players'][0]['handName']!==null&&$flop['players'][1]['handName']===null&&str_starts_with($flop['history'][count($flop['history'])-1],'Flop: '));
	$expect($g->view(1)['legalActions']['minBet']===2&&$g->view(1)['legalActions']['betLabel']==='Bet');
	foreach(range(1,4) as $_) $act($g,['type'=>'check']);
	$turn=$g->view(1);
	$expect($turn['street']==='turn'&&count($turn['board'])===4&&array_slice($turn['board'],0,3)===$flop['board'],'The turn is added after the flop');
	$expect($turn['legalActions']['minBet']===4&&$turn['legalActions']['maxBet']===12,'Late streets use the late limits');
	$act($g,['type'=>'bet','amount'=>4]); $act($g,['type'=>'fold']); $act($g,['type'=>'call']); $act($g,['type'=>'call']);
	$river=$g->view(0);
	$expect($river['street']==='river'&&count($river['board'])===5&&$river['turn']===1&&$river['players'][2]['folded']&&count($g->view(-1)['players'][2]['cards'])===2);
	$restored=Game::restore(json_decode(json_encode($g->snapshot()),true));
	$expect($restored->snapshot()===$g->snapshot());
	foreach(range(1,3) as $_) $act($g,['type'=>'check']);
	$end=$g->view(0);
	$expect($end['phase']==='showdown'&&$end['reveal']&&$end['pot']===0&&array_sum(array_column($end['awards'],'amount'))===20);
	$expect($end['players'][1]['handName']!==null&&$end['players'][2]['cards']===[null,null],'Folded hands stay hidden at showdown');
	$act($g,['type'=>'next']);
	$expect($g->view(0)['dealer']===1&&$g->view(0)['board']===[]&&$g->view(0)['street']==='preflop');
	$heads=new Game('holdem-heads-up',2,new Profile('anything',game:'holdem'));
	$h=$heads->view(0);
	$expect($h['dealer']===0&&$h['players'][0]['bet']===1&&$h['players'][1]['bet']===2&&$h['turn']===0,'Heads-up the dealer is the small blind and acts first');
	$act($heads,['type'=>'call']); $act($heads,['type'=>'check']);
	$expect($heads->view(0)['street']==='flop'&&$heads->view(0)['turn']===1,'After the flop the dealer acts last');
	$act($heads,['type'=>'bet','amount'=>2]); $act($heads,['type'=>'fold']);
	$expect($heads->view(0)['phase']==='showdown'&&!$heads->view(0)['reveal']&&$heads->view(0)['awards'][0]===['seat'=>1,'amount'=>6,'handName'=>null]);
	$reject(static fn()=>new Game('too-many',9,new Profile(game:'holdem')));
});
$test('hold\'em showdowns use the best five of seven and split when the board plays',static function() use ($expect,$act,$deal): void {
	$check=static function(Game $g) use ($act): void { while($g->view(-1)['phase']!=='showdown') { $legal=$g->view($g->actor())['legalActions']; $act($g,['type'=>$legal['call']?'call':'check']); } };
	// Seat 0 holds two aces, seat 1 king-queen of hearts; the board brings three more hearts.
	$g=$deal(new Game('holdem-flush',2,new Profile('anything',game:'holdem')),
		[0=>[[12,'down'],[25,'down']],1=>[[37,'down'],[36,'down']]],[26,28,30,0,14]);
	$check($g); $v=$g->view(0);
	$expect($v['board']===[26,28,30,0,14]&&$v['awards']===[['seat'=>1,'amount'=>4,'handName'=>'Flush']],'Flush beats aces up');
	$expect($v['players'][0]['handName']==='Two pair'&&$v['players'][1]['cards']===[37,36]);
	// A royal flush on the board plays for both seats.
	$g=$deal(new Game('holdem-board',2,new Profile('anything',game:'holdem')),
		[0=>[[0,'down'],[14,'down']],1=>[[1,'down'],[15,'down']]],[8,9,10,11,12]);
	$check($g); $v=$g->view(0);
	$expect(array_column($v['awards'],'amount')===[2,2]&&$v['awards'][0]['handName']==='Royal flush'&&str_contains($v['message'],'split'));
	// A kicker in the hole decides between two pairs of aces.
	$g=$deal(new Game('holdem-kicker',2,new Profile('anything',game:'holdem')),
		[0=>[[12,'down'],[11,'down']],1=>[[25,'down'],[23,'down']]],[38,2,17,32,47]);
	$check($g);
	$expect($g->view(0)['awards']===[['seat'=>0,'amount'=>4,'handName'=>'One pair']]);
});
$test('stud deals exposed cards, forces a bring-in and lets the best showing hand act first',static function() use ($expect,$reject,$act,$deal): void {
	$fresh=new Game('stud-five',3,new Profile('anything',ante:1,minimum:2,maximum:6,finalMinimum:4,finalMaximum:12,game:'stud5'));
	$expect($fresh->view(0)['pot']===5&&array_sum(array_column($fresh->view(0)['players'],'totalBet'))===5,'Every seat antes and one brings in');
	// Cards are dealt from the dealer's left, so seat 0, the dealer, is served last on every street.
	// Up-cards: seat 0 the two of diamonds, seat 1 the two of clubs, seat 2 a king. Clubs is the lowest suit.
	$g=$deal(new Game('stud-five',3,new Profile('anything',ante:1,minimum:2,maximum:6,finalMinimum:4,finalMaximum:12,game:'stud5')),
		[0=>[[12,'down'],[13,'up']],1=>[[25,'down'],[0,'up']],2=>[[38,'down'],[11,'up']]],[24,5,26, 3,39, 2,1]);
	// The rigged table keeps the bring-in posted by the original deal; rebuild it for the rigged up-cards.
	$s=$g->snapshot();
	foreach($s['state']['players'] as $id=>&$p) { $p['stack']=499; $p['bet']=0; $p['totalBet']=1; $p['lastAction']=''; }
	unset($p);
	$s['state']['players'][1]=['stack'=>497,'bet'=>2,'totalBet'=>3,'lastAction'=>'Brings in 2']+$s['state']['players'][1];
	$s['state']['pending']=[2,0]; $s['state']['turn']=2; $s['state']['highest']=2; $s['state']['pot']=5;
	$g=Game::restore($s); $v=$g->view(0);
	$expect($v['street']==='second'&&$v['players'][0]['cards']===[12,13]&&$v['players'][0]['faces']===['down','up']);
	$expect($v['players'][1]['cards']===[null,0]&&$v['players'][2]['cards']===[null,11],'Only exposed cards are public');
	$expect($g->view(-1)['players'][0]['cards']===[null,13]);
	$act($g,['type'=>'call']); $act($g,['type'=>'call']);
	$third=$g->view(0);
	$expect($third['street']==='third'&&$third['players'][0]['cards']===[12,13,26]&&$third['players'][1]['cards']===[null,0,24]&&$third['players'][2]['cards']===[null,11,5]);
	$expect($third['turn']===0&&str_contains($third['message'],'You show the best hand'),'A pair of twos showing acts first');
	$expect($third['history'][count($third['history'])-1]==='Third street.'&&$g->view(0)['legalActions']['check']&&$g->view(0)['legalActions']['minBet']===2);
	$act($g,['type'=>'bet','amount'=>2]); $act($g,['type'=>'call']); $act($g,['type'=>'fold']);
	$fourth=$g->view(0);
	$expect($fourth['street']==='fourth'&&$fourth['players'][2]['folded']&&$fourth['players'][2]['cards']===[null,null,null],'A folded hand is turned face down');
	$expect($fourth['players'][0]['cards']===[12,13,26,39]&&$fourth['players'][1]['cards']===[null,0,24,3]&&count($g->view(2)['players'][2]['cards'])===3);
	$expect($fourth['turn']===0&&$g->view(0)['legalActions']['minBet']===4,'Trip twos showing; late limits from fourth street');
	$act($g,['type'=>'check']); $act($g,['type'=>'check']);
	$fifth=$g->view(0);
	$expect($fifth['street']==='fifth'&&$fifth['players'][0]['cards']===[12,13,26,39,1]&&$fifth['players'][0]['handName']==='Three of a kind');
	$act($g,['type'=>'check']); $act($g,['type'=>'check']);
	$end=$g->view(0);
	$expect($end['phase']==='showdown'&&$end['awards'][0]['seat']===0&&$end['awards'][0]['amount']===13&&$end['players'][1]['cards']===[25,0,24,3,2]);
	$seven=new Game('stud-seven',7,new Profile('anything',game:'stud7'));
	$expect($seven->view(0)['players'][0]['faces']===['down','down','up']&&$seven->view(0)['street']==='third');
	while($seven->view(-1)['phase']!=='showdown') { $legal=$seven->view($seven->actor())['legalActions']; $act($seven,['type'=>$legal['call']?'call':'check']); }
	$last=$seven->view(0);
	$expect($last['street']==='seventh'&&$last['players'][3]['faces']===['down','down','up','up','up','up','down']&&$last['stockCount']===3);
	$expect(count(array_filter($last['players'][3]['cards'],'is_int'))===7&&$last['players'][3]['handName']!==null);
	$reject(static fn()=>new Game('stud-seven',8,new Profile(game:'stud7')));
	$bad=$seven->snapshot(); $bad['state']['faces'][0][0][1]='up';
	$reject(static fn()=>Game::restore($bad));
});
$test('street games survive long random sessions with chips conserved at every seat count',static function() use ($expect): void {
	foreach(['holdem','stud5','stud7'] as $id) foreach(range(2,Variant::of($id)->seats()) as $players)
	{
		$game=new Game("session-$id-$players",$players,new Profile('anything',wild:$players===3?['deuces']:[],game:$id));
		$random=new RandomStream("session-$id-$players",'php-poker/tests/v1'); $hands=0;
		for($step=0;$step<500;$step++)
		{
			$actor=$game->actor(); $view=$game->view(max(0,$actor));
			if($actor<0) { $hands++; $game->act(0,['type'=>'next'],$view['revision']); continue; }
			$legal=$view['legalActions']; $roll=$random->boundedInt(10);
			$intent=$legal['bet']&&$roll<3?['type'=>'bet','amount'=>$roll===0?$legal['maxBet']:$legal['minBet']]
				:($legal['call']?['type'=>$roll===9?'fold':'call']:['type'=>'check']);
			$game->act($actor,$intent,$view['revision']);
			if($step%29===0) $game=Game::restore(json_decode(json_encode($game->snapshot()),true));
		}
		$expect($hands>5,"$id with $players seats played only $hands hands");
	}
});
$test('street-game bots finish long sessions from their own views and value hands sensibly',static function() use ($expect): void {
	foreach(['holdem','stud5','stud7'] as $id) foreach([2,4,Variant::of($id)->seats()] as $players)
	{
		$game=new Game("bot-session-$id-$players",$players,new Profile('anything',wild:$players===4?['deuces']:[],game:$id));
		$streams=[]; for($seat=0;$seat<$players;$seat++) $streams[$seat]=new RandomStream("bot-session-$id-$players",'php-poker/bot/'.$seat.'/v1');
		$hands=0; $showdowns=0; $types=[];
		for($step=0;$step<700;$step++)
		{
			$actor=$game->actor();
			if($actor<0) { $hands++; if($game->view(0)['reveal']) $showdowns++; $game->act(0,['type'=>'next'],$game->view(0)['revision']); continue; }
			$view=$game->view($actor);
			$intent=Bot::choose($view,$actor,$streams[$actor],$actor===1?300:0);
			$types[$intent['type']]=true;
			$game->act($actor,$intent,$view['revision']);
			if($step%53===0) $game=Game::restore(json_decode(json_encode($game->snapshot()),true));
		}
		$expect($hands>=10&&$showdowns>0&&$showdowns<$hands,"$id with $players seats: $hands hands, $showdowns showdowns");
		$expect(isset($types['fold'],$types['call'],$types['check'],$types['bet']),"$id with $players seats uses every kind of action");
	}
	// Strength reads only what the seat can see, and orders hands the way a player would.
	$g=new Game('bot-strength',3,new Profile('anything',game:'holdem'));
	$view=$g->view(1); $view['players'][1]['cards']=[12,25]; $aces=Bot::streetStrength($view,1);
	$view['players'][1]['cards']=[5,13]; $trash=Bot::streetStrength($view,1);
	$view['players'][1]['cards']=[11,10]; $suited=Bot::streetStrength($view,1);
	$expect($aces>$suited&&$suited>$trash,'Aces beat king-queen suited, which beats seven-two');
	$view['board']=[38,0,1]; $view['streetIndex']=1;
	$view['players'][1]['cards']=[12,25]; $overpair=Bot::streetStrength($view,1);
	$view['players'][1]['cards']=[2,3]; $flushDraw=Bot::streetStrength($view,1);
	$view['players'][1]['cards']=[18,33]; $nothing=Bot::streetStrength($view,1);
	$expect($overpair>$nothing&&$flushDraw>$nothing,'A pair or a draw beats nothing after the flop');
	$view['board']=[12,25,38,51,0]; $view['streetIndex']=3; $view['players'][1]['cards']=[14,29];
	$expect(Bot::streetStrength($view,1)<760,'Four aces on the board are everyone\'s, so a seat that only plays the board is not strong');
	$s=new Game('bot-stud',3,new Profile('anything',game:'stud5'));
	$view=$s->view(1); $view['players'][1]['cards']=[1,14]; $view['players'][1]['faces']=['down','up'];
	$view['players'][2]['cards']=[null,12]; $view['players'][2]['faces']=['down','up'];
	$pair=Bot::streetStrength($view,1);
	$view['players'][2]['cards']=[null,12,25]; $view['players'][2]['faces']=['down','up','up'];
	$expect(Bot::streetStrength($view,1)<$pair,'Aces showing across the table weaken a pair of threes');
});
$test('hand categories, wheel, kickers and concealed opening predicate',static function() use ($expect,$reject): void {
	$hands=[[0,14,29,44,11],[9,22,0,16,30],[9,22,8,21,0],[9,22,35,0,15],[0,14,28,42,4],
		[0,2,5,8,11],[9,22,35,0,13],[9,22,35,48,0],[8,9,10,11,12]];
	$last=-1;
	foreach($hands as $i=>$cards) { $h=Hand::evaluate($cards); $expect($h['category']===$i,'Category '.$i); $expect($h['score']>$last); $last=$h['score']; }
	$wheel=Hand::evaluate([12,13,27,41,3]); $expect($wheel['category']===4&&$wheel['ranks']===[5]);
	$expect(Hand::evaluate([1,15,29,43,5])['score']>$wheel['score']);
	$expect(Hand::canOpen([9,22,0,16,30])); $expect(!Hand::canOpen([8,21,0,16,30]));
	$expect(Hand::canOpen([0,13,1,14,30]));
	$reject(static fn()=>Hand::evaluate([0,0,1,2,3]));
});
$test('wild presets, custom cards, joker deck and five of a kind are exact',static function() use ($expect,$reject,$rig): void {
	$expect(WildCards::ids('one-eyed-jacks')===[35,48]);
	$expect(WildCards::ids('mustached-kings')===[11,24,50]);
	$expect(WildCards::ids('kings')===[11,24,37,50]);
	$expect(WildCards::ids('deuces-treys')===[0,1,13,14,26,27,39,40]);
	$expect(WildCards::ids(['joker','one-eyed-jacks'])===[35,48,52,53]);
	$expect(Hand::evaluate([9,22,35,48,0],[0])['name']==='Five of a kind');
	$expect(Hand::evaluate([8,9,10,11,52],[52])['name']==='Royal flush');
	$expect(Hand::evaluate([9,22,35,52,53],[52,53])['name']==='Five of a kind');
	$expect(Hand::evaluate([35,48,11,24,37],WildCards::ids('one-eyed-jacks'))['ranks']===[13]);
	$expect(Hand::evaluate([11,24,50,12,25],WildCards::ids('mustached-kings'))['ranks']===[14]);
	$expect(Hand::label(52)==='Joker 1'&&Hand::label(53)==='Joker 2'
		&&WildCards::cardLabel(52)==='Joker 1'&&WildCards::cardLabel(53)==='Joker 2');
	$reject(static fn()=>new Profile(wild:['custom'],customWild:[2,1]));
	$profile=new Profile('anything',wild:['joker']);
	$expect($profile->deck()===range(0,53)&&Profile::restore($profile->snapshot())->snapshot()===$profile->snapshot());
	$oneJoker=new Profile('anything',wild:['custom'],customWild:[53]);
	$expect($oneJoker->deck()===[...range(0,51),53]&&$oneJoker->wildCards()===[53]);
	$legacy=['version'=>'bicycle-inspired-local-v1','variant'=>'anything','ante'=>1,'minimum'=>2,'maximum'=>20,'raiseLimit'=>3];
	$expect(Profile::restore($legacy)->wild===[]);
	$single=['version'=>'bicycle-inspired-local-v2','variant'=>'anything','ante'=>1,'minimum'=>2,'maximum'=>20,'raiseLimit'=>3,'wild'=>'deuces','customWild'=>[]];
	$expect(Profile::restore($single)->wild===['deuces']);
	$multi=['version'=>'bicycle-inspired-local-v3','variant'=>'anything','ante'=>2,'minimum'=>3,'maximum'=>9,'raiseLimit'=>4,'wild'=>['deuces'],'customWild'=>[]];
	$migratedProfile=Profile::restore($multi);
	$expect($migratedProfile->finalMinimum===3&&$migratedProfile->finalMaximum===9&&$migratedProfile->playerRaiseLimit===4);
	$configured=new Profile('anything',2,1,5,4,[],[],3,9,2);
	$expect(Profile::restore($configured->snapshot())->snapshot()===$configured->snapshot());
	$reject(static fn()=>new Profile(finalMinimum:8,finalMaximum:4));
	$game=$rig(new Game('joker-deck',2,$profile),[[8,9,10,11,52],[0,13,26,39,1]]);
	$expect($game->snapshot()['piles']['inventory']===range(0,53));
	$expect($game->view(0)['players'][0]['handName']==='Royal flush');
	$old=(new Game('single-joker-save',2,new Profile('anything')))->snapshot();
	$old['profile']=$profile->snapshot(); $old['piles']['inventory'][]=52;
	$old['piles']['piles']['stock'][]=['kind'=>'ordered','cards'=>[52]];
	$migrated=Game::restore($old)->snapshot();
	$expect($migrated['piles']['inventory']===range(0,53)
		&& end($migrated['piles']['piles']['stock'])===['kind'=>'ordered','cards'=>[53]]);
	$expect(!in_array(52,Bot::discards([52,9,22,0,16],[52]),true),'a bot discarded its first wild joker');
	$expect(!in_array(53,Bot::discards([53,9,22,0,16],[53]),true),'a bot discarded its second wild joker');
	$botView=['turn'=>1,'players'=>[['folded'=>false],['folded'=>false,'cards'=>[11,24,37,50,52]]],
		'profile'=>['wildCards'=>[52]],'legalActions'=>['check'=>true],'opener'=>0,'pot'=>10];
	$expect(Bot::choose($botView,1,new RandomStream('five-kind-bot'))===['type'=>'check']);
});
$test('betting rounds and total and per-player raise caps use their configured limits',static function() use ($expect,$act): void {
	$rounds=new Game('round-limits',2,new Profile('anything',minimum:2,maximum:4,raiseLimit:0,finalMinimum:5,finalMaximum:7,playerRaiseLimit:0));
	$opening=$rounds->view(1)['legalActions'];
	$expect($opening['minBet']===2&&$opening['maxBet']===4&&$opening['betIncrement']===2);
	$act($rounds,['type'=>'bet','amount'=>2]); $act($rounds,['type'=>'call']);
	$act($rounds,['type'=>'draw','cards'=>[]]); $act($rounds,['type'=>'draw','cards'=>[]]);
	$final=$rounds->view(1)['legalActions'];
	$expect($rounds->view(-1)['phase']==='final-betting'&&$final['minBet']===5&&$final['maxBet']===7&&$final['betIncrement']===5);

	$caps=new Game('raise-caps',4,new Profile('anything',minimum:2,maximum:2,raiseLimit:3,playerRaiseLimit:1));
	$act($caps,['type'=>'bet','amount'=>2]);
	$act($caps,['type'=>'bet','amount'=>4]);
	$act($caps,['type'=>'call']); $act($caps,['type'=>'call']);
	$act($caps,['type'=>'bet','amount'=>6]);
	$seatTwo=$caps->view(2)['legalActions'];
	$expect($caps->view(-1)['raises']===2&&$seatTwo['call']&&!$seatTwo['bet'],'a player raised twice despite its per-round cap');
	$total=new Game('total-raise-cap',4,new Profile('anything',minimum:2,maximum:2,raiseLimit:1,playerRaiseLimit:3));
	$act($total,['type'=>'bet','amount'=>2]); $act($total,['type'=>'bet','amount'=>4]);
	$expect($total->view(3)['legalActions']['call']&&!$total->view(3)['legalActions']['bet'],'the table exceeded its total raise cap');
	$save=$caps->snapshot(); unset($save['state']['raiseCounts']);
	$restored=Game::restore($save);
	$expect(array_sum($restored->snapshot()['state']['raiseCounts'])===2,'an old save did not reconstruct raise counters');
});
$test('passing returns to the betting queue after an opener and stale actions are atomic',static function() use ($expect,$act,$reject): void {
	$g=new Game('pass',4,new Profile('anything')); $act($g,['type'=>'pass']); $act($g,['type'=>'bet','amount'=>4]);
	$expect($g->view(-1)['turn']===3); $act($g,['type'=>'call']); $act($g,['type'=>'call']);
	$expect($g->view(-1)['turn']===1); $act($g,['type'=>'call']); $expect($g->view(-1)['phase']==='draw');
	$before=$g->snapshot(); $reject(static fn()=>$g->act(1,['type'=>'draw','cards'=>[]],0));
	$reject(static fn()=>$g->act(1,['type'=>'draw','cards'=>[99]],$g->view(-1)['revision']));
	$expect($before===$g->snapshot());
});
$test('all-pass redeal carries the pot, rotates dealer and does not award it',static function() use ($expect,$act): void {
	$g=new Game('passes',3); for($i=0;$i<3;$i++) $act($g,['type'=>'pass']);
	$expect($g->view(-1)['phase']==='redeal'&&$g->view(-1)['pot']===1);
	$act($g,['type'=>'next']); $v=$g->view(-1);
	$expect($v['dealer']===1&&$v['pot']===2&&$v['handNumber']===2&&$v['awards']===[]);
});
$test('legal-action metadata cannot be submitted as a turn',static function() use ($expect,$reject): void {
	$g=new Game('action-schema',3,new Profile('anything'));
	$before=$g->snapshot(); $v=$g->view(1);
	foreach(['minBet','maxBet','betLabel','openingRequired','callAmount','maximumDiscards','loan','revision',null,1,[]] as $type)
	{
		$reject(static fn()=>$g->act(1,['type'=>$type],$v['revision']));
		$expect($before===$g->snapshot(),'Rejected intent changed cards, chips or randomness');
	}
});
$test('eight seats can each replace all five without receiving that draw own discards',static function() use ($expect,$act): void {
	$g=new Game('recycle',8,new Profile('anything')); $act($g,['type'=>'bet','amount'=>2]);
	for($i=0;$i<7;$i++) $act($g,['type'=>'call']);
	for($i=0;$i<8;$i++)
	{
		$seat=$g->view(-1)['turn']; $cards=$g->view($seat)['players'][$seat]['cards'];
		$act($g,['type'=>'draw','cards'=>$cards]);
		$after=$g->view($seat)['players'][$seat]['cards']; $expect(!array_intersect($cards,$after)); $g->validate();
	}
	$expect($g->view(-1)['phase']==='final-betting'&&$g->view(-1)['turn']===1);
	for($i=0;$i<8;$i++) $act($g,['type'=>'check']);
	$expect($g->view(-1)['phase']==='showdown');
});
$test('opening eligibility belongs to the original hand and folded opener does not block final betting',static function() use ($expect,$act,$rig): void {
	$g=$rig(new Game('opener',3),[[0,13,2,16,30],[9,22,1,17,31],[3,18,33,47,12]]);
	$act($g,['type'=>'bet','amount'=>2]); $act($g,['type'=>'bet','amount'=>4]);
	$act($g,['type'=>'call']); $act($g,['type'=>'fold']);
	$expect($g->view(-1)['phase']==='draw');
	$act($g,['type'=>'draw','cards'=>[]]); $act($g,['type'=>'draw','cards'=>[]]);
	$expect($g->view(-1)['phase']==='final-betting'&&$g->view(-1)['turn']===2);
	$h=$rig(new Game('qualifier',2),[[0,13,2,16,30],[9,22,1,17,31]]);
	$act($h,['type'=>'bet','amount'=>2]); $act($h,['type'=>'call']);
	$act($h,['type'=>'draw','cards'=>[9]]); $act($h,['type'=>'draw','cards'=>[]]);
	$act($h,['type'=>'check']); $act($h,['type'=>'check']);
	$expect($h->view(-1)['phase']==='showdown');
});
$test('uncontested winners keep their cards private and ties split odd chips clockwise',static function() use ($expect,$act,$rig): void {
	$g=new Game('fold',2,new Profile('anything')); $act($g,['type'=>'fold']);
	$expect($g->view(-1)['reveal']===false&&$g->view(-1)['players'][0]['cards']===array_fill(0,5,null));
	$h=$rig(new Game('ties',2,new Profile('anything')),[[8,9,10,11,12],[21,22,23,24,25]]);
	$act($h,['type'=>'bet','amount'=>2]); $act($h,['type'=>'call']);
	$act($h,['type'=>'draw','cards'=>[]]); $act($h,['type'=>'draw','cards'=>[]]);
	$act($h,['type'=>'check']); $act($h,['type'=>'check']);
	$expect($h->view(-1)['awards']===[['seat'=>1,'amount'=>3,'handName'=>'Royal flush'],['seat'=>0,'amount'=>2,'handName'=>'Royal flush']]);
});
$test('mid-hand loans preserve chip accounting and prohibit raising',static function() use ($expect,$act): void {
	$g=new Game('loans',3,new Profile('anything')); $s=$g->snapshot();
	$s['state']['players'][0]['stack']-=498; $s['state']['players'][0]['debt']=0;
	$s['state']['players'][1]['stack']+=498; $g=Game::restore($s);
	$act($g,['type'=>'bet','amount'=>10]); $act($g,['type'=>'call']); $act($g,['type'=>'call']);
	$expect($g->view(-1)['players'][0]['debt']===9&&$g->view(-1)['players'][0]['creditUsed']);
	for($i=0;$i<3;$i++) $act($g,['type'=>'draw','cards'=>[]]);
	$act($g,['type'=>'bet','amount'=>10]); $act($g,['type'=>'call']);
	$expect(!$g->view(0)['legalActions']['bet']); $g->validate();
});
$test('bots are deterministic from their permitted view and keep made hands',static function() use ($expect): void {
	$g=new Game('view',4,new Profile('anything')); $v=$g->view(1);
	$expect($v['players'][0]['cards']===array_fill(0,5,null));
	$a=Bot::choose($v,1,new RandomStream('bot')); $s=$g->snapshot();
	// Swap two hidden cards between other seats; the bot view and decision must remain identical.
	[$s['piles']['piles']['hand0'][0]['cards'][0],$s['piles']['piles']['hand2'][0]['cards'][0]]=
		[$s['piles']['piles']['hand2'][0]['cards'][0],$s['piles']['piles']['hand0'][0]['cards'][0]];
	$other=Game::restore($s)->view(1); $expect($v===$other); $expect($a===Bot::choose($other,1,new RandomStream('bot')));
	$expect(Bot::discards([8,9,10,11,12])===[]);
	$expect(count(Bot::discards([9,22,0,16,30]))===3);
});
$test('automatic adaptation is bounded, smoothed and restored without deck access',static function() use ($expect): void {
	$a=new Adaptation; $prior=0;
	for($hand=1;$hand<=80;$hand++)
	{
		$v=['phase'=>'showdown','handNumber'=>$hand,'players'=>[['stack'=>100,'debt'=>500]]];
		$a->observe($v); $expect($a->level()>=0&&$a->level()<=600&&abs($a->level()-$prior)<=40);
		$prior=$a->level(); $copy=Adaptation::restore($a->snapshot()); $a->observe($v); $expect($a->snapshot()===$copy->snapshot());
	}
	$expect($a->level()===600);
	for($hand=81;$hand<=180;$hand++) $a->observe(['phase'=>'showdown','handNumber'=>$hand,'players'=>[['stack'=>1200,'debt'=>0]]]);
	$expect($a->level()===0);
});
$test('save restores a bot turn exactly and menu pauses progression',static function() use ($expect): void {
	$a=new Session; $a->command(['type'=>'start','players'=>4,'variant'=>'anything'],'session-seed');
	$a->command(['type'=>'pace','value'=>'fast']); $a->tick(.1); $before=$a->save();
	$b=Session::restore($before); $expect($b->view()['canContinue']);
	$b->tick(100); $expect($b->save()===$before,'Menu must pause bots');
	$b->command(['type'=>'resume']);
	for($i=0;$i<20;$i++) { $a->tick(.1); $b->tick(.1); }
	$expect($a->save()===$b->save());
	$c=Session::restore('{broken'); $expect(!$c->view()['canContinue']&&$c->view()['error']!=='');
	$bad=json_decode($before,true); $bad['game']['state']['players'][0]['stack']++;
	$expect(!Session::restore(json_encode($bad))->view()['canContinue']);
	foreach([
		static function(array &$d): void { unset($d['game']['state']['opener']); },
		static function(array &$d): void { unset($d['game']['state']['players'][0]['drawCount']); },
		static function(array &$d): void { $d['game']['state']['history']=[['bad'=>'shape']]; },
		static function(array &$d): void { $d['game']['state']['awards']=[['seat'=>99,'amount'=>1,'handName'=>null]]; },
		static function(array &$d): void { $d['game']['state']['pending']=[['seat'=>1]]; }
	] as $corrupt)
	{
		$bad=json_decode($before,true); $corrupt($bad);
		$restored=Session::restore(json_encode($bad))->view();
		$expect(!$restored['canContinue']&&$restored['error']!=='','Corrupt save was silently accepted');
	}
});
$test('manual snapshots resume as a fresh presentation generation',static function() use ($expect): void {
	$session=new Session;
	$expect(!$session->hasGame());
	$session->command(['type'=>'start','players'=>2,'variant'=>'anything'],'manual-slot');
	$before=$session->view(); $snapshot=$session->save();
	$session->command(['type'=>'save-game']);
	$expect($session->view()['error']==='' && $session->hasGame(),'Save Game is accepted only for a live local table');
	$loaded=Session::restore($snapshot);
	$loaded->resumeLoaded($before['generation']+5);
	$after=$loaded->view();
	$expect($after['screen']==='table' && $after['generation']===$before['generation']+5,'a manual load resumes the table at the requested fresh generation');
	$expect($after['revision']===$before['revision'] && $after['players']===$before['players'],'loading preserves the exact game state');
	$empty=new Session; $empty->command(['type'=>'load-failed']);
	$expect(str_contains($empty->view()['error'],'selected saved game'),'an invalid selected file reports a readable error');
});
$test('long seeded bot sessions complete at every seat count and both variants',static function() use ($expect,$act): void {
	foreach(['jackpots','anything'] as $variant) foreach(range(2,8) as $n)
	{
		$g=new Game('long-'.$variant.'-'.$n,$n,new Profile($variant)); $r=[];
		foreach(range(0,$n-1) as $id) $r[$id]=new RandomStream('long-bot-'.$id);
		$completed=0;
		for($actions=0;$actions<8000&&$completed<40;$actions++)
		{
			$public=$g->view(-1); $seat=$public['turn'];
			if($seat<0) { $completed++; $act($g,['type'=>'next']); continue; }
			$v=$g->view($seat); $intent=Bot::choose($v,$seat,$r[$seat],200);
			$g->act($seat,$intent,$v['revision']);
			if($actions%17===0) $g=Game::restore($g->snapshot());
		}
		$expect($completed===40,"Deadlock at $n seats / $variant");
	}
});
$failed=0;
foreach($tests as $name=>$run) try { $run(); echo "PASS $name\n"; } catch(Throwable $e) { $failed++; fwrite(STDERR,"FAIL $name: {$e->getMessage()}\n{$e->getTraceAsString()}\n"); }
echo count($tests)." poker tests, $failed failures\n";
exit($failed?1:0);
