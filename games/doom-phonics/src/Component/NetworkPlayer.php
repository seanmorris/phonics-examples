<?php
declare(strict_types=1);
namespace DoomPhonics\Component;

use Phonics\Network\PeerId;
use Phonics\Network\PlayerSlot;

/** Match-owned player identity, independent of a map's spawn marker number. */
final class NetworkPlayer
{

	public function __construct(
		public PlayerSlot $slot
		, public PeerId $peer
		, public bool $local
	){
	}
}
