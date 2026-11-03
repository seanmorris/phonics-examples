<?php
declare(strict_types=1);
namespace DoomPhonics\Tests;

use DoomPhonics\Adapter\WadSource;
use DoomPhonics\Component\ActorState;
use DoomPhonics\Component\BeamEffect;
use DoomPhonics\Component\Body;
use DoomPhonics\Component\MonsterBrain;
use DoomPhonics\Component\NetworkPlayer;
use DoomPhonics\Component\PlayerStart;
use DoomPhonics\Component\RemoteBeam;
use DoomPhonics\Component\RemoteProjectile;
use DoomPhonics\Component\Transform;
use DoomPhonics\Game\DoomMatch;
use DoomPhonics\Game\PlayerState;
use DoomPhonics\System\EpisodeSystems;
use Phonics\Ecs\Registry;
use Phonics\Ecs\Scheduler;
use Phonics\FixedStep;
use Phonics\Input\Controls;
use Phonics\Network\Component\NetworkIdentity;
use Phonics\World\ScopeContext;

/** Exercise the installed game systems and the same interpolated poses the renderer reads. */
final class EpisodeMovement
{

	public ScopeContext $scope;
	public Controls $controls;
	private FixedStep $fixed;

	public function __construct(WadSource $source, ?DoomMatch $match = null)
	{
		$registry = new Registry;
		$this->controls = new Controls;
		$this->controls->set([\DoomPhonics\Input\DoomActions::FORWARD => 1]);
		$this->fixed = new FixedStep(1 / 35);

		$this->scope = new ScopeContext(
			'episode'
			, iterator_to_array($source->catalog())[0]
			, $registry
			, new Scheduler($registry)
			, services: ['controls' => $this->controls, 'doom-match' => $match]
		);

		$this->scope->setContent($source->activate('E1M1', $this->scope)->content());
		(new EpisodeSystems)->activate($this->scope);

		foreach($registry->query(PlayerStart::class) as $entity)
		{
			$registry->get($entity, Transform::class)->angle = 0;
			$player = $registry->get($entity, PlayerState::class);
			$player->noClip = true;
			$player->godMode = true;
		}
	}

	/** Equip both peers identically and keep their shot paths free of monsters. */
	public function armWeapon(string $weapon = 'RocketLauncher', string $ammo = 'RocketAmmo'): void
	{
		$registry = $this->scope->registry;
		$this->controls->set([]);

		foreach($registry->query(MonsterBrain::class) as $entity) $registry->destroy($entity);

		foreach($registry->query(PlayerStart::class) as $entity)
		{
			$player = $registry->get($entity, PlayerState::class);
			$player->weapon = $weapon;
			$player->weapons[$weapon] = true;
			$player->ammo[$ammo] = 20;
			$registry->get($entity, Body::class)->shootable = false;
		}
	}

	/** @return array<string,mixed> */
	public function advance(float $elapsed): array
	{
		$alpha = $this->fixed->advance($elapsed, [$this->scope->scheduler, 'update']);
		$registry = $this->scope->registry;
		$players = [];

		foreach($registry->query(PlayerStart::class) as $entity)
		{
			$player = $registry->has($entity, NetworkPlayer::class)
				? $registry->get($entity, NetworkPlayer::class)
				: null;

			$pose = $registry->get($entity, Transform::class);
			$players[$player?->slot->value() ?? 1] = [
				'local' => $player?->local ?? true
				, 'x' => $pose->x
				, 'previousX' => $pose->previousX
				, 'renderX' => $pose->xAt($alpha)
			];
		}

		$rockets = [];

		foreach($registry->query(ActorState::class) as $entity)
		{
			$state = $registry->get($entity, ActorState::class);

			if($state->definition->name !== 'Rocket') continue;

			$pose = $registry->get($entity, Transform::class);
			$rockets[] = [
				'id' => $registry->has($entity, NetworkIdentity::class)
					? $registry->get($entity, NetworkIdentity::class)->id->value()
					: null
				, 'sprite' => $state->current()?->sprite
				, 'label' => $state->label
				, 'x' => $pose->x
				, 'previousX' => $pose->previousX
				, 'replica' => $registry->has($entity, RemoteProjectile::class)
				, 'collides' => $registry->has($entity, Body::class)
			];
		}

		$beams = [];

		foreach($registry->query(BeamEffect::class) as $entity)
		{
			$beam = $registry->get($entity, BeamEffect::class);
			$beams[] = [
				'id' => $registry->has($entity, NetworkIdentity::class)
					? $registry->get($entity, NetworkIdentity::class)->id->value()
					: null
				, 'from' => [$beam->from->x, $beam->from->y, $beam->from->z]
				, 'to' => [$beam->to->x, $beam->to->y, $beam->to->z]
				, 'duration' => $beam->duration
				, 'width' => $beam->width
				, 'remaining' => $beam->remaining($alpha)
				, 'replica' => $registry->has($entity, RemoteBeam::class)
			];
		}

		return ['alpha' => $alpha, 'players' => $players, 'rockets' => $rockets, 'beams' => $beams];
	}
}
