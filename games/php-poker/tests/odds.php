<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';

use PhpPoker\{Hand,Odds,WildCards};

$tests=[];
$test=static function(string $name,callable $run) use (&$tests): void { $tests[$name]=$run; };
$expect=static function(bool $ok,string $message='Assertion failed'): void { if(!$ok) throw new RuntimeException($message); };
$label=static fn(array $hand): string => $hand['category']===8 && $hand['name']==='Royal flush' ? 'Royal flush' : Hand::LABELS[$hand['category']];
/** Every k-card combination of a list, in lexicographic order. */
$combinations=static function(array $items,int $size): Generator {
	$count=count($items);
	if($size>$count) return;
	$index=range(0,$size-1);
	while(true)
	{
		yield array_map(static fn(int $i)=>$items[$i],$index);
		$position=$size-1;
		while($position>=0 && $index[$position]===$count-$size+$position) $position--;
		if($position<0) return;
		$index[$position]++;
		for($next=$position+1;$next<$size;$next++) $index[$next]=$index[$next-1]+1;
	}
};

$test('a standard deck reproduces the textbook counts of dealt five-card hands',static function() use ($expect): void {
	$dealt=Odds::dealt(range(0,51));
	$expect($dealt['total']===2598960);
	$expect($dealt['counts']===[
		'High card'=>1302540,'One pair'=>1098240,'Two pair'=>123552,'Three of a kind'=>54912,'Straight'=>10200,
		'Flush'=>5108,'Full house'=>3744,'Four of a kind'=>624,'Straight flush'=>36,'Royal flush'=>4,'Five of a kind'=>0
	],'Standard dealt counts: '.json_encode($dealt['counts']));
	$expect(Odds::against(1302540,2598960)>.995 && Odds::against(1302540,2598960)<1.0);
	$expect(Odds::against(0,10)===null);
});

$test('deuces wild reproduces the published wild-card distribution',static function() use ($expect): void {
	$dealt=Odds::dealt(WildCards::deck('deuces'),WildCards::ids('deuces'));
	$expect($dealt['counts']===[
		'High card'=>799680,'One pair'=>1225008,'Two pair'=>95040,'Three of a kind'=>355080,'Straight'=>62232,
		'Flush'=>14472,'Full house'=>12672,'Four of a kind'=>31552,'Straight flush'=>2068,'Royal flush'=>484,'Five of a kind'=>672
	],'Deuces wild counts: '.json_encode($dealt['counts']));
	// With wild cards, frequency no longer follows rank: three of a kind is commoner than two pair.
	$expect($dealt['counts']['Three of a kind']>$dealt['counts']['Two pair']);
});

$test('pattern counting matches full enumeration on random decks, wild sets and jokers',static function() use ($expect,$label,$combinations): void {
	mt_srand(20261106);
	for($trial=0;$trial<14;$trial++)
	{
		$wildCount=$trial%6;
		$natural=range(0,51); shuffle($natural);
		$jokers=array_slice([52,53],0,$trial%3);
		$deck=[...array_slice($natural,0,16+$trial%5-count($jokers)),...$jokers];
		$candidates=$deck; shuffle($candidates);
		$wild=array_values(array_unique([...$jokers,...array_slice(array_diff($candidates,$jokers),0,max(0,$wildCount-count($jokers)))]));
		$brute=array_fill_keys(Odds::DEALT_ROWS,0);
		foreach($combinations($deck,5) as $hand) $brute[$label(Hand::evaluate($hand,$wild))]++;
		$counted=Odds::dealt($deck,$wild);
		$expect($counted['counts']===$brute,"Trial $trial dealt counts differ: ".json_encode([$counted['counts'],$brute]));
		$expect($counted['total']===array_sum($brute));

		// A draw from the same deck: keep some natural cards, draw the rest from what remains.
		$naturals=array_values(array_diff($deck,$wild));
		$keep=$trial%4;
		$held=array_slice($naturals,0,$keep);
		$unseen=array_values(array_diff($deck,$held,array_slice($naturals,$keep,5-$keep)));
		$drawn=array_fill_keys(Odds::DEALT_ROWS,0);
		foreach($combinations($unseen,5-$keep) as $cards) $drawn[$label(Hand::evaluate([...$held,...$cards],$wild))]++;
		$expect(Odds::draw($held,$unseen,5-$keep,$wild)===$drawn,"Trial $trial draw counts differ");
	}
});

$test('a standard deck gives the exact odds against improving each drawing hand',static function() use ($expect): void {
	$rows=[];
	foreach(Odds::drawing(range(0,51)) as $row) $rows[$row['hand'].' → '.$row['target']]=[$row['draw'],$row['hits'],$row['trials']];
	$expect($rows===[
		'One ace → Pair of aces or better'=>[4,46581,178365],
		'One pair → Two pair'=>[3,2592,16215],'One pair → Three of a kind'=>[3,1854,16215],
		'One pair → Full house'=>[3,165,16215],'One pair → Four of a kind'=>[3,45,16215],
		'Two pairs → Full house'=>[1,4,47],
		'Three of a kind → Full house'=>[2,66,1081],'Three of a kind → Four of a kind'=>[2,46,1081],
		'Four flush → Flush'=>[1,9,47],'Outside straight → Straight'=>[1,8,47],'Inside straight → Straight'=>[1,4,47],
	],'Standard drawing rows: '.json_encode($rows));
	// The two values the 1991 table printed differently: four of a kind from trips and a four flush.
	$expect(abs(Odds::against(46,1081)-22.5)<1e-9 && abs(Odds::against(9,47)-38/9)<1e-12);
});

$test('drawing odds follow the wild cards in play and drop hands that cannot start naturally',static function() use ($expect): void {
	$rows=[];
	foreach(Odds::drawing(WildCards::deck('deuces'),WildCards::ids('deuces')) as $row) $rows[$row['hand'].' → '.$row['target']]=$row;
	$expect(count($rows)===11,'Every deuces-wild starting hand can be formed from natural cards');
	// One of the 47 unseen cards is wild for each of the three remaining deuces, so a four flush fills more often.
	$expect($rows['Four flush → Flush']['hits']===12 && $rows['Four flush → Flush']['trials']===47);
	$aces=Odds::drawing(range(0,51),[12,25,38,51]);
	$expect(!in_array('One ace',array_column($aces,'hand'),true),'Aces wild leaves no natural lone ace');
	$expect(Odds::drawing(range(0,51))===Odds::drawing(range(0,51)),'Results are stable and cached');
});

$test('every preset keeps the counts of the per-pattern evaluation',static function() use ($expect): void {
	// Recorded before evaluations were shared between equal hand shapes; full-deck values for three more rules.
	$expected=[
		'joker'=>[1302540,1437936,123552,232968,34704,11388,9360,9360,540,84,78],
		'one-eyed-jacks'=>[1052130,1188652,102960,199758,28938,9476,8112,8328,480,54,72],
		'deuces-treys'=>[464100,1113696,71280,610080,144564,24668,18480,129656,10760,3524,8152],
	];
	foreach($expected as $preset=>$counts)
	{
		$dealt=Odds::dealt(WildCards::deck($preset),WildCards::ids($preset));
		$expect(array_values($dealt['counts'])===$counts,"$preset counts: ".json_encode(array_values($dealt['counts'])));
	}
});

$test('heavy wild rules still form every drawing hand from natural cards',static function() use ($expect,$combinations): void {
	$custom=[0,1,2,13,14,15,26,27,28,39,40,41];
	$rows=Odds::drawing(WildCards::deck(['custom'],$custom),WildCards::ids(['custom'],$custom));
	$expect(count($rows)===11,'Twos, threes and fours wild still leave every starting hand');
	// The ace row reads the pair's rank, so check it against every draw with deuces wild.
	$wild=WildCards::ids('deuces');
	$ace=Odds::drawing(range(0,51),$wild)[0];
	$held=[51]; $unseen=array_values(array_diff(range(0,51),$held,[11,20,30,1]));
	$hits=0;
	foreach($combinations($unseen,4) as $cards)
	{
		$hand=Hand::evaluate([...$held,...$cards],$wild);
		if($hand['category']>1 || ($hand['category']===1 && $hand['ranks'][0]===14)) $hits++;
	}
	$expect($ace['hits']===$hits && $hits===89973 && $ace['trials']===178365,"Deuces-wild ace row {$ace['hits']} vs $hits");
});

$test('odds headings name the wild cards in play',static function() use ($expect): void {
	foreach([
		[[],[],'No wild cards'],[['deuces'],[],'Deuces wild'],
		[['joker','one-eyed-jacks'],[],'Jokers and One-Eyed Jacks wild'],
		[['custom'],[0,1,2,13,14,15,26,27,28,39,40,41],'Twos, Threes and Fours wild'],
		[['custom','kings'],[12,25,38,51,5,53],'Kings, Aces, 7♣ and Joker 2 wild'],
		[['custom'],[3,4,5,6,7,8],'6 chosen cards wild'],
		[['custom'],[],'No wild cards'],
	] as [$presets,$custom,$label])
	$expect(WildCards::describe($presets,$custom)===$label,'Described as '.WildCards::describe($presets,$custom).", expected $label");
});

$test('invalid decks and draws are rejected',static function() use ($expect): void {
	foreach([
		static fn()=>Odds::dealt([0,1,2,3]),
		static fn()=>Odds::dealt([0,1,2,3,3]),
		static fn()=>Odds::dealt([...range(0,51),52]),
		static fn()=>Odds::draw([0,1],[2,3,4,5],2),
		static fn()=>Odds::draw([0],[1,2,3,4,5],4,[0]),
		static fn()=>Odds::against(5,4),
	] as $index=>$invalid)
	{
		try { $invalid(); $expect(false,"Invalid input $index was accepted"); }
		catch(InvalidArgumentException) {}
	}
});

$failed=0;
foreach($tests as $name=>$run)
{
	try { $run(); echo "ok - $name\n"; }
	catch(Throwable $error) { $failed++; echo "not ok - $name: {$error->getMessage()}\n"; }
}
echo count($tests)-$failed,'/',count($tests)," PHP Poker odds tests passed.\n";
exit($failed?1:0);
