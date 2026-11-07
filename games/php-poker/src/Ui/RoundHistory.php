<?php
declare(strict_types=1);
namespace PhpPoker\Ui;

/** Build the DOS-style player/action matrix from the authoritative textual hand history. */
final class RoundHistory
{
	/**
	 * Keep the complete current hand plus every line the former eight-line transcript exposed. Player actions become
	 * column cells; dealer, result, and unrecognised lines remain full-width rows with their exact text.
	 *
	 * @param list<array<string,mixed>> $players
	 * @param list<mixed> $history
	 * @return array{rows:list<array{kind:string,text?:string,cells?:array<int,array{text:string,style:string}>}>,lines:list<string>}
	 */
	public static function build(array $players, array $history): array
	{
		$history=array_values(array_filter($history,'is_string'));
		$lastEight=array_slice($history,-8);
		$handStart=count($history);
		foreach($history as $index=>$line) if(preg_match('/^Hand \d+:/i',$line)===1) $handStart=$index;
		$start=min($handStart,max(0,count($history)-8));
		$visible=array_slice($history,$start);

		$names=[];
		foreach($players as $seat=>$player)
		{
			$name=(string)($player['name']??'');
			if($name!=='') $names[]=['seat'=>$seat,'prefix'=>$name.': ','length'=>strlen($name)];
		}
		usort($names,static fn(array $a,array $b): int=>$b['length']<=>$a['length']);

		$rows=[]; $cells=[]; $mode=''; $afterDraw=false; $opened=false;
		$flush=static function() use (&$rows,&$cells,&$mode): void {
			if($cells!==[]) $rows[]=['kind'=>'actions','cells'=>$cells];
			$cells=[]; $mode='';
		};
		foreach($visible as $line)
		{
			$match=null;
			foreach($names as $candidate) if(str_starts_with($line,$candidate['prefix'])) { $match=$candidate; break; }
			if($match===null)
			{
				$flush();
				$rows[]=['kind'=>'system','text'=>$line];
				if(preg_match('/^Hand \d+:/i',$line)===1) { $afterDraw=false; $opened=false; }
				continue;
			}

			$seat=(int)$match['seat'];
			$action=rtrim(substr($line,strlen($match['prefix'])),'.');
			$isDraw=preg_match('/^Draws (\d+)$/i',$action,$draw)===1 || strcasecmp($action,'Stands pat')===0;
			if($isDraw)
			{
				if($mode!==''&&$mode!=='draw') $flush();
				$mode='draw';
				if(isset($cells[$seat])) $flush();
				$count=strcasecmp($action,'Stands pat')===0?0:(int)$draw[1];
				$cells[$seat]=['text'=>'D('.$count.')','style'=>'draw'];
				$afterDraw=true;
				continue;
			}

			if($mode!==''&&$mode!=='bet') $flush();
			$mode='bet';
			if(isset($cells[$seat])) { $flush(); $mode='bet'; }
			[$text,$style,$aggressive]=self::action($action,$afterDraw,$opened);
			if($style==='open') $opened=true;
			$cells[$seat]=['text'=>$text,'style'=>$style];
			if($aggressive) $flush();
		}
		$flush();
		return ['rows'=>$rows,'lines'=>$lastEight];
	}

	/** @return array{string,string,bool} Display text, colour role, and whether responses begin on a new row. */
	private static function action(string $action, bool $afterDraw, bool $opened): array
	{
		if(preg_match('/^Bets (\d+)$/i',$action,$match)===1)
		return [(!$afterDraw&&!$opened?'OPEN ':'BET ').$match[1],!$afterDraw&&!$opened?'open':'bet',true];
		if(preg_match('/^Raises to (\d+)$/i',$action,$match)===1) return ['RAISE TO '.$match[1],'raise',true];
		if(preg_match('/^Calls(?: (\d+))?$/i',$action,$match)===1) return ['CALL'.(isset($match[1])&&$match[1]!==''?' '.$match[1]:''),'call',false];
		if(strcasecmp($action,'Checks')===0) return ['CHECK','check',false];
		if(strcasecmp($action,'Passes')===0) return ['PASS','pass',false];
		if(strcasecmp($action,'Folds')===0) return ['FOLD','fold',false];
		return [strtoupper($action),'other',false];
	}
}
