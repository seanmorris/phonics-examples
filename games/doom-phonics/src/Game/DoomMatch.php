<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

use Phonics\Network\MatchContext;
use Phonics\Network\NetworkClock;
use Phonics\Network\PeerId;
use Phonics\Network\PlayerSlot;

/** Validated Doom rules layered over the engine-owned peer and slot assignment. */
final class DoomMatch
{
	public MultiplayerMode $mode;
	public int $fragLimit;
	public NetworkClock $clock;
	public ?\DoomPhonics\System\EpisodeNetworkSystem $replication = null;

	public function __construct(public MatchContext $network)
	{
		$settings = $network->settings();
		$this->clock = new NetworkClock;
		$this->mode = MultiplayerMode::from((string)($settings['mode'] ?? MultiplayerMode::DEATHMATCH));
		$this->fragLimit = max(1, min(100, (int)($settings['fragLimit'] ?? 10)));
	}

	public function peer(PlayerSlot $slot): PeerId
	{
		if($slot->equals($this->network->localSlot)) return $this->network->localPeer;

		if($slot->equals($this->network->remoteSlot)) return $this->network->remotePeer;

		throw new \OutOfBoundsException("No peer owns player slot $slot");
	}

	public function local(PlayerSlot $slot): bool { return $slot->equals($this->network->localSlot); }
}
