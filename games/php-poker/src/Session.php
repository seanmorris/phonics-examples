<?php
declare(strict_types=1);
namespace PhpPoker;

use Phonics\Random\RandomStream;

/**
 * Offline menu, bot turns and exact saved-session continuation.
 *
 * The controller separates cosmetic delay from deterministic policy decisions.
 * Every accepted action emits a new complete local snapshot for atomic storage.
 */
final class Session
{
	private ?Game $game=null;
	private Adaptation $adaptation;
	private array $bots=[];
	private string $screen='menu';
	private string $error='';
	private float $delay=0;
	private bool $dirty=true;
	private bool $saveDirty=false;
	private int $generation=0;
	private string $pace='normal';

	/** @return void Initializes a title screen without creating or exposing any hand. */
	public function __construct() { $this->adaptation=new Adaptation; }

	/**
	 * Handle menu or human input on the current game generation and revision.
	 * @param array $intent Intent including type, generation and revision for game actions.
	 * @param string|null $newSeed Fresh local seed for a start action only.
	 * @return void Publishes a changed view or a readable validation error.
	 */
	public function command(array $intent, ?string $newSeed=null): void
	{
		$this->error='';
		try
		{
			$type=$intent['type']??'';
			if($type==='start')
			{
				if($newSeed===null) throw new \InvalidArgumentException('A fresh local seed is required');
				$players=$intent['players']??4;
				if(!is_int($players)) throw new \InvalidArgumentException('Choose a player count');
				$wild=$intent['wild']??[]; $customWild=$intent['customWild']??[];
				if(!is_array($wild) || !is_array($customWild)) throw new \InvalidArgumentException('Choose valid wild cards');
				$profile=new Profile($intent['variant']??'jackpots',
					ante:$intent['ante']??1, minimum:$intent['minimum']??2, maximum:$intent['maximum']??20,
					raiseLimit:$intent['raiseLimit']??3, wild:$wild, customWild:$customWild,
					finalMinimum:$intent['finalMinimum']??null, finalMaximum:$intent['finalMaximum']??null,
					playerRaiseLimit:$intent['playerRaiseLimit']??null);
				$this->game=new Game($newSeed,$players,$profile);
				$this->bots=[];
				for($id=1;$id<$players;$id++) $this->bots[$id]=new RandomStream($newSeed,'php-poker/bot/'.$id.'/v1');
				$this->adaptation=new Adaptation;
				$this->generation++; $this->screen='table'; $this->saveDirty=true; $this->delay=0;
			}
			elseif($type==='menu') $this->screen='menu';
			elseif($type==='resume' && $this->game) $this->screen='table';
			elseif($type==='save-game' && $this->game) {}
			elseif($type==='load-failed') throw new \InvalidArgumentException('The selected saved game could not be loaded');
			elseif($type==='help') $this->screen='help';
			elseif($type==='settings') $this->screen='settings';
			elseif($type==='pace')
			{
				if(!in_array($intent['value']??null,['normal','fast'],true)) throw new \InvalidArgumentException('Invalid pace');
				$this->pace=$intent['value']; $this->saveDirty=$this->game!==null;
			}
			elseif($this->screen==='table' && $this->game)
			{
				if(($intent['generation']??null)!==$this->generation || !is_int($intent['revision']??null))
					throw new \InvalidArgumentException('That table has already changed');
				$this->game->act(0,$intent,$intent['revision']);
				$this->afterAction();
			}
			else throw new \InvalidArgumentException('Start or continue a game first');
		}
		catch(\InvalidArgumentException|\TypeError $exception) { $this->error=$exception->getMessage(); }
		$this->dirty=true;
	}

	/**
	 * Advance at most one bot, with a cosmetic delay that never changes its decision.
	 * @param float $seconds Active elapsed time, excluding background/menu pauses.
	 * @return void Commits one policy decision when its display delay expires.
	 */
	public function tick(float $seconds): void
	{
		if($this->screen!=='table' || !$this->game) return;
		$seat=$this->game->actor();
		if($seat<1) { $this->delay=0; return; }
		$this->delay+=max(0,min(.1,$seconds));
		if($this->delay<($this->pace==='fast' ? .12 : .65)) return;
		$view=$this->game->view($seat);
		$random=RandomStream::restore($this->bots[$seat]->snapshot());
		$action=Bot::choose($view,$seat,$random,$this->adaptation->level());
		$this->game->act($seat,$action,$view['revision']);
		$this->bots[$seat]=$random;
		$this->afterAction();
	}

	/** @return array Authorized local UI state without policy internals or stock order. */
	public function view(): array
	{
		$view=$this->game?->view(0)??['phase'=>'ready','players'=>[],'legalActions'=>[],'revision'=>0];
		return array_merge($view,['screen'=>$this->screen,'canContinue'=>$this->game!==null,
			'generation'=>$this->generation,'error'=>$this->error,'pace'=>$this->pace]);
	}

	/** @return bool Whether presentation needs a new authorized view. */
	public function consumeDirty(): bool { $dirty=$this->dirty; $this->dirty=false; return $dirty; }
	/** @return bool Whether an accepted local action needs atomic persistence. */
	public function consumeSaveDirty(): bool { $dirty=$this->saveDirty; $this->saveDirty=false; return $dirty; }
	/** @return bool Whether a local game exists for explicit save/load menu commands. */
	public function hasGame(): bool { return $this->game !== null; }

	/** Resume a manually loaded snapshot as a new UI generation without changing its cards or rules. */
	public function resumeLoaded(int $minimumGeneration): void
	{
		if(!$this->game) throw new \InvalidArgumentException('The saved game is empty');
		$this->generation=max($this->generation + 1, $minimumGeneration);
		$this->screen='table'; $this->delay=0; $this->dirty=true; $this->saveDirty=true; $this->error='';
	}

	/** @return string Complete local save; contains hidden hands and is never a public replay. */
	public function save(): string
	{
		return json_encode(['version'=>1,'mode'=>'offline','game'=>$this->game?->snapshot(),
			'adaptation'=>$this->adaptation->snapshot(),'bots'=>array_map(static fn(RandomStream $r): array=>$r->snapshot(),$this->bots),
			'generation'=>$this->generation,'pace'=>$this->pace],JSON_THROW_ON_ERROR);
	}

	/**
	 * Restore an offline session to the title screen, requiring an explicit Continue.
	 * @param string $json Saved local document.
	 * @return self Validated controller, or a clear menu error preserving the original save.
	 */
	public static function restore(string $json): self
	{
		$result=new self;
		if($json==='') return $result;
		try
		{
			if(strlen($json)>1048576) throw new \InvalidArgumentException('Poker save is too large');
			$data=json_decode($json,true,32,JSON_THROW_ON_ERROR);
			if(!is_array($data) || ($data['version']??null)!==1 || ($data['mode']??null)!=='offline'
				|| !is_int($data['generation']??null) || $data['generation']<1 || $data['generation']>2147483647
				|| !in_array($data['pace']??null,['normal','fast'],true) || !is_array($data['bots']??null))
				throw new \InvalidArgumentException('Unsupported poker save');
			$result->game=Game::restore($data['game']);
			$result->adaptation=Adaptation::restore($data['adaptation']);
			$n=count($result->game->view(-1)['players']);
			if(array_keys($data['bots'])!==range(1,$n-1)) throw new \InvalidArgumentException('Incomplete saved opponents');
			foreach($data['bots'] as $id=>$state) $result->bots[$id]=RandomStream::restore($state);
			$result->generation=$data['generation']; $result->pace=$data['pace'];
		}
		catch(\Throwable)
		{
			$result=new self;
			$result->error='Your saved game could not be restored. Start a new table to replace it.';
		}
		return $result;
	}

	private function afterAction(): void
	{
		if($this->game->phase()==='showdown') $this->adaptation->observe($this->game->view(-1));
		$this->delay=0; $this->dirty=true; $this->saveDirty=true;
	}
}
