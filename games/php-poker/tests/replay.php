<?php
declare(strict_types=1);

// This fixture uses only accepted actions and saved-session continuation. Its hashes
// include deck and bot stream positions, so native/WASM divergence cannot hide behind
// the same visible hand. It is packaged only by the browser regression test.
function pokerReplayFixture(): array
{
	$results=[];
	foreach(['jackpots','anything'] as $variant) foreach([2,5,8] as $seats)
	{
		$s=new PhpPoker\Session;
		$s->command(['type'=>'start','players'=>$seats,'variant'=>$variant],'wasm-replay-'.$seats.'-'.$variant);
		$s->command(['type'=>'pace','value'=>'fast']);
		$human=new Phonics\Random\RandomStream('human-fixture');
		$checks=[];
		for($step=0;$step<240;$step++)
		{
			$v=$s->view();
			if($v['turn']>0) $s->tick(.1);
			else
			{
				$intent=$v['turn']<0?['type'=>'next']:PhpPoker\Bot::choose($v,0,$human);
				$s->command($intent+['generation'=>$v['generation'],'revision'=>$v['revision']]);
			}
			if($s->view()['error']!=='') throw new RuntimeException($s->view()['error']);
			if($step%19===0)
			{
				$saved=$s->save();
				$checks[]=hash('sha256',$saved);
				$s=PhpPoker\Session::restore($saved);
				if(!$s->view()['canContinue'] || $s->save()!==$saved) throw new RuntimeException('Replay restore diverged');
				$s->command(['type'=>'resume']);
			}
		}
		$results[$variant.'-'.$seats]=['hand'=>$s->view()['handNumber'],'revision'=>$s->view()['revision'],'checks'=>$checks];
	}
	return $results;
}
