<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
$root=getenv('PHONICS_ENGINE_ROOT') ?: dirname(__DIR__,3).'/node_modules/@seanmorris/phonics';
require $root.'/autoload.php';

use PhpPoker\{Game,Hand,Profile,Bot,Adaptation,Session,WildCards};
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
	$expect(str_contains($empty->view()['error'],'No manually saved game'),'an empty manual slot reports a readable error');
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
