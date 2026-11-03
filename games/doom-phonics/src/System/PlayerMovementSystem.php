<?php
declare(strict_types=1);
namespace DoomPhonics\System;
use DoomPhonics\Input\DoomActions;

use DoomPhonics\Component\ActorState;
use DoomPhonics\Component\Body;
use DoomPhonics\Component\Gravity;
use DoomPhonics\Component\HitPoints;
use DoomPhonics\Component\NetworkPlayer;
use DoomPhonics\Component\PlayerStart;
use DoomPhonics\Component\PlayerCommand;
use DoomPhonics\Component\Transform;
use DoomPhonics\Component\Velocity;
use DoomPhonics\Component\ViewInput;
use DoomPhonics\Game\Collision;
use DoomPhonics\Game\DoomGravity;
use DoomPhonics\Game\LineSpecials;
use DoomPhonics\Game\VerticalPhysics;
use DoomPhonics\Game\PlayerState;
use Phonics\Ecs\Query;
use Phonics\Ecs\Registry;
use Phonics\Input\Controls;

/** Modern mouse/WASD and gamepad movement over classic Doom collision geometry. */
final class PlayerMovementSystem
{
	private Query $players;
	private VerticalPhysics $verticalPhysics;

	public function __construct(
		public Collision $collision
		, public LineSpecials $specials
		, public Controls $controls
		, public float $speed = 420
		, public float $acceleration = 1531.25
		, public float $friction = .90625
		, public float $gravity = DoomGravity::ACCELERATION
		, public bool $simulateRemotePlayers = true
	){
		$this->players = Query::all(
			PlayerStart::class
			, Transform::class
			, Velocity::class
			, Body::class
			, PlayerState::class
		);

		$this->verticalPhysics = new VerticalPhysics($collision->level, $collision->maxStep, $gravity);
	}

	public function update(Registry $registry, float $dt): void
	{
		foreach($registry->query($this->players) as $entity)
		{
			if(!$this->simulateRemotePlayers
				&& $registry->has($entity, NetworkPlayer::class)
				&& !$registry->get($entity, NetworkPlayer::class)->local
			) continue;

			$pose = $registry->get($entity, Transform::class);
			$body = $registry->get($entity, Body::class);
			$velocity = $registry->get($entity, Velocity::class);
			$player = $registry->get($entity, PlayerState::class);
			$view = $registry->has($entity, ViewInput::class) ? $registry->get($entity, ViewInput::class) : null;
			$command = $registry->has($entity, PlayerCommand::class)
				? $registry->get($entity, PlayerCommand::class)
				: null;

			$dead = $registry->has($entity, HitPoints::class)
				&& $registry->get($entity, HitPoints::class)->current <= 0;

			if($dead)
			{
				$command?->release();
				$view?->release();
			}

			$sector = $this->collision->level->sectorAt($pose->x, $pose->y);
			$floorBefore = $sector->supportZ($pose->x, $pose->y, $pose->z);
			$body->grounded = $pose->z <= $floorBefore + .01 && $velocity->z <= 0;
			$pose->angle -= ($dead ? 0 : ($command?->turn ?? $this->controls->value(DoomActions::TURN))) * 2.5 * $dt;
			$pose->angle += $dead ? 0 : ($command?->consumeYaw() ?? ($view?->consumeYaw() ?? 0));
			$frozen = $dead || $player->reactionTics > 0;

			if($player->reactionTics > 0) $player->reactionTics--;

			$forward = $frozen ? 0.0 : ($command?->forward ?? $this->controls->value(DoomActions::FORWARD));
			$strafe = $frozen ? 0.0 : ($command?->strafe ?? $this->controls->value(DoomActions::STRAFE));
			$length = hypot($forward, $strafe);

			if($length > 1)
			{
				$forward /= $length;
				$strafe /= $length;
			}

			// Positive strafe follows the same clockwise screen-right basis as the renderer.
			$velocity->x += (cos($pose->angle) * $forward + sin($pose->angle) * $strafe) * $this->acceleration * $dt;
			$velocity->y += (sin($pose->angle) * $forward - cos($pose->angle) * $strafe) * $this->acceleration * $dt;
			$drag = pow($this->friction, $dt * 35);
			$water = $this->collision->level->waterSurface($pose->x, $pose->y, $pose->z, $body->height);
			if($water !== null)
			{
				$drag *= pow(.94, $dt * 35);
				$pitch = $command?->pitch ?? $view?->pitch ?? 0;
				$velocity->z += sin($pitch) * $forward * $this->acceleration * $dt;
			}
			$velocity->x *= $drag;
			$velocity->y *= $drag;
			$horizontal = hypot($velocity->x, $velocity->y);

			if($horizontal > $this->speed)
			{
				$velocity->x *= $this->speed / $horizontal;
				$velocity->y *= $this->speed / $horizontal;
			}

			if($horizontal < .01 && $length === 0.0) $velocity->x = $velocity->y = 0;

			$motion = $player->noClip
				? $this->noClip($pose, $velocity, $dt)
				: $this->collision->moveAndSlide($pose, $body, $velocity->x * $dt, $velocity->y * $dt, $entity);

			foreach($motion['normals'] as [$normalX,$normalY])
			{
				$into = $velocity->x * $normalX + $velocity->y * $normalY;

				if($into < 0)
				{
					$velocity->x -= $normalX * $into;
					$velocity->y -= $normalY * $into;
				}
			}

			$this->animatePlayerActor($registry, $entity, hypot($velocity->x, $velocity->y), $dead);

			$gravityScale = $registry->has($entity, Gravity::class)
				? $registry->get($entity, Gravity::class)->scale
				: 1.0;

			// Resolve wall portals before sampling support/headroom: the horizontal
			// endpoint can lie outside the source room until it enters destination space.
			if(!$dead) $this->specials->cross($pose->previousX, $pose->previousY, $pose, $player);

			$this->verticalPhysics->step($pose, $velocity, $body, $dt, $gravityScale, $player->viewHeight);

			$use = !$dead && ($command
				? $command->consumeUse()
				: $this->controls->pressed(DoomActions::USE) || ($view?->consumeUse() ?? false)
			);

			if($use)
			$this->specials->use($pose, $player);
		}
	}

	/** Select the third-person idle/walk states without interrupting attacks, pain, or death. */
	private function animatePlayerActor(
		Registry $registry
		, int $entity
		, float $horizontalSpeed
		, bool $dead
	): void {
		if($dead || !$registry->has($entity, ActorState::class)) return;

		$state = $registry->get($entity, ActorState::class);
		$label = strtolower($state->label);

		if($horizontalSpeed > .01 && $label === 'spawn' && $state->definition->state('See'))
		$state->enter('See');
		elseif($horizontalSpeed <= .01 && $label === 'see' && $state->definition->state('Spawn'))
		$state->enter('Spawn');
	}

	/** @return array{collided:bool,dx:float,dy:float,normals:list<array{0:float,1:float}>} */
	private function noClip(Transform $pose, Velocity $velocity, float $dt): array
	{
		$dx = $velocity->x * $dt;
		$dy = $velocity->y * $dt;
		$pose->x += $dx;
		$pose->y += $dy;

		return ['collided' => false,'dx' => $dx,'dy' => $dy,'normals' => []];
	}
}
