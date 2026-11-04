<?php
declare(strict_types=1);
namespace PhpPoker;

/**
 * Automatic, bounded single-player opponent easing between completed hands.
 *
 * Public bankroll results drive a smoothed adjustment with a cooldown. The policy
 * has no user-facing switch and never observes a concealed card or changes a deck.
 */
final class Adaptation
{
	private array $state=['version'=>1,'level'=>0,'trend'=>0,'lastNet'=>0,'lastHand'=>0,'cooldown'=>0];

	/**
	 * Observe a completed hand once, without changing a policy mid-hand.
	 * @param array $public Public-only table view.
	 * @return void Updates bounded deterministic integer state.
	 */
	public function observe(array $public): void
	{
		if($public['phase'] !== 'showdown' || $public['handNumber'] <= $this->state['lastHand']) return;
		$human=$public['players'][0];
		$net=$human['stack']-$human['debt']-500;
		$this->state['trend']=intdiv($this->state['trend']*3+$net-$this->state['lastNet'],4);
		$this->state['lastNet']=$net;
		$this->state['lastHand']=$public['handNumber'];
		if($this->state['cooldown']>0) { $this->state['cooldown']--; return; }
		$target=max(0,min(600,-$net-$this->state['trend']*2));
		$delta=$target-$this->state['level'];
		if(abs($delta)>30)
		{
			$this->state['level']+=max(-40,min(40,$delta));
			$this->state['cooldown']=1;
		}
	}

	/** @return int Current frozen per-hand easing, from zero to 600. */
	public function level(): int { return $this->state['level']; }
	/** @return array Internal continuation state, never an online setting. */
	public function snapshot(): array { return $this->state; }

	/**
	 * Restore exact adaptation history and cooldown.
	 * @param array $state Internal saved policy state.
	 * @return self Validated policy.
	 */
	public static function restore(array $state): self
	{
		if(($state['version']??null)!==1) throw new \InvalidArgumentException('Unsupported adaptation state');
		foreach(['level','trend','lastNet','lastHand','cooldown'] as $key)
			if(!is_int($state[$key]??null) || abs($state[$key])>2147483647) throw new \InvalidArgumentException('Invalid adaptation counter');
		if($state['level']<0 || $state['level']>600 || $state['lastHand']<0 || $state['cooldown']<0 || $state['cooldown']>1)
			throw new \InvalidArgumentException('Invalid adaptation bounds');
		$result=new self;
		$result->state=$state;
		return $result;
	}
}
