<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use Phonics\Ecs\Query;
use Phonics\Ecs\Registry;
use Phonics\Input\Controls;
use SanePhonics\Physics\Body;
use SanePhonics\Physics\Terrain;
use Phonics\Render\Sprite;
use Phonics\Render\SpriteGroup;
use Phonics\Tiled\TileMap;

/** Automatic grapple contact, rope simulation, rider transport, and owned entity cleanup. */
final class Grapples
{
	/** @var array<int,GrapplePoint> Owned handles keyed by entity ID. */
	public array $points = [];
	/** @var array<int,RopeAnchor> Owned suspension points keyed by entity ID. */
	public array $anchors = [];
	private array $previous = [], $anchorPrevious = [], $art = [];
	private bool $closed = false;
	private ?\Closure $onChange;

	/**
	 * Bind rope simulation to the registry and terrain shared with platformer movement.
	 *
	 * @param Registry $registry Registry containing opt-in Body plus GrappleGrip players.
	 * @param Terrain $terrain Collision source for hook, attachment, and passenger casts.
	 * @param TileMap|null $map Optional Tiled objects and annotated rope artwork to load.
	 * @param callable(int):?int|null $resolveAnchor Optional Tiled source ID to live body entity resolver.
	 * @param callable(int,int,bool):void|null $onChange Player, handle, and attached state notification.
	 * @return void Owns only newly created handles, rope sprites, and anchor proxy entities.
	 */
	public function __construct(
		private Registry $registry
		, private Terrain $terrain
		, ?TileMap $map = null
		, ?callable $resolveAnchor = null
		, ?callable $onChange = null
	){
		$this->onChange = $onChange === null ? null : \Closure::fromCallable($onChange);

		try
		{
			if($map !== null)
			{
				(new GrappleLoader)->load($this, $map, $resolveAnchor);
			}
		}
		catch(\Throwable $error)
		{
			$this->close();
			throw $error;
		}
	}

	/**
	 * Register an owned suspension point that ropes may share.
	 *
	 * @param RopeAnchor $anchor Mutable fixed or entity-following suspension point.
	 * @param Sprite|null $sprite Optional visible anchor artwork.
	 * @return int New anchor entity ID for Rope construction.
	 */
	public function anchor(RopeAnchor $anchor, ?Sprite $sprite = null): int
	{
		if($this->closed)
		{
			throw new \LogicException('Cannot add to a closed grapple system');
		}

		$id = $this->registry->create($anchor);
		$this->anchors[$id] = $anchor;
		$position = $this->followedPosition($anchor);

		if($position !== null)
		{
			[$anchor->x, $anchor->y] = $position;
		}

		$this->anchorPrevious[$id] = [$anchor->x, $anchor->y];

		if($sprite !== null)
		{
			$sprite->x = $anchor->x;
			$sprite->y = $anchor->y;
			$this->registry->add($id, $sprite);
		}

		return $id;
	}

	/**
	 * Register an automatically grabbable handle and its rope component.
	 *
	 * @param GrapplePoint $point Handle bounds and collision plane.
	 * @param Rope $rope Rope referencing a live RopeAnchor entity in this registry.
	 * @param Sprite|null $sprite Optional bottom-centered handle artwork.
	 * @param int|null $ropeGid Optional tile ID repeated along the visible rope.
	 * @param float $segmentLength Positive world height of one unstretched rope tile.
	 * @return int New owned entity carrying GrapplePoint, Rope, and optional sprite components.
	 */
	public function add(
		GrapplePoint $point
		, Rope $rope
		, ?Sprite $sprite = null
		, ?int $ropeGid = null
		, float $segmentLength = 8
	): int {
		if($this->closed || !$this->registry->has($rope->anchor, RopeAnchor::class) || $point->entity !== 0)
		{
			throw new \LogicException('A new grapple needs a live anchor and an open owner');
		}

		if(!is_finite($segmentLength) || $segmentLength <= 0 || ($ropeGid !== null && $ropeGid < 1))
		{
			throw new \InvalidArgumentException('Invalid rope artwork or segment length');
		}

		$id = $point->entity = $this->registry->create($point, $rope);
		$this->points[$id] = $point;
		$this->art[$id] = [$ropeGid, $segmentLength, $sprite?->opacity ?? 1, $sprite?->tint ?? [1, 1, 1, 1]];

		if($sprite !== null)
		{
			$this->registry->add($id, $sprite);
		}

		if($ropeGid !== null)
		{
			$this->registry->add($id, new SpriteGroup);
		}

		$this->draw($point, $rope);

		return $id;
	}

	/**
	 * Record swing input and release a held handle when Jump is pressed.
	 *
	 * @param int $player Player entity with Body and GrappleGrip components.
	 * @param Controls $input Current logical controls and fresh jump edge.
	 * @param float $dt Positive finite simulation delta in seconds.
	 * @return bool True when Jump released the grapple and must not also trigger flight or a shield.
	 */
	public function control(int $player, Controls $input, float $dt): bool
	{
		if(!is_finite($dt) || $dt <= 0)
		{
			throw new \InvalidArgumentException('Grapple controls require a positive finite delta');
		}

		if($this->closed || !$this->registry->has($player, GrappleGrip::class)
			|| !$this->registry->has($player, Body::class)
		){
			return false;
		}

		$grip = $this->registry->get($player, GrappleGrip::class);
		$body = $this->registry->get($player, Body::class);
		$grip->axis = $input->axis('left', 'right');

		if($grip->point === null)
		{
			return false;
		}

		if($body->ability !== 'hanging')
		{
			$this->release($player, inherit: false);

			return false;
		}

		if($grip->axis)
		{
			$body->facing = $body->direction = $grip->axis <=> 0;
		}

		if($input->pressed('jump'))
		{
			$this->release($player, true);

			return true;
		}

		return false;
	}

	/**
	 * Release one rider and briefly exclude the old handle from automatic reattachment.
	 *
	 * @param int $player Player entity whose attachment should end.
	 * @param bool $jump Add a three-quarter-strength upward jump to inherited rope momentum.
	 * @param bool $inherit Transfer endpoint velocity; false preserves external damage knockback.
	 * @return bool True when a live attachment was removed.
	 */
	public function release(int $player, bool $jump = false, bool $inherit = true): bool
	{
		if(!$this->registry->has($player, GrappleGrip::class))
		{
			return false;
		}

		$grip = $this->registry->get($player, GrappleGrip::class);
		$id = $grip->point;

		if($id === null)
		{
			return false;
		}

		$grip->point = null;
		$grip->cooldowns[$id] = .25;

		if($this->registry->has($player, Body::class))
		{
			$body = $this->registry->get($player, Body::class);

			if($inherit)
			{
				$rope = $this->registry->has($id, Rope::class) ? $this->registry->get($id, Rope::class) : null;
				$anchor = $rope !== null && $this->registry->has($rope->anchor, RopeAnchor::class)
					? $this->registry->get($rope->anchor, RopeAnchor::class)
					: null;

				$body->vx = ($anchor?->vx ?: $rope?->vx) ?? $body->vx;
				$body->vy = ($anchor?->vy ?: $rope?->vy) ?? $body->vy;
				$body->grounded = $body->rolling = $body->grinding = false;
				$body->jumping = true;
				$body->angle = $body->groundSpeed = $body->coyote = $body->jumpBuffer = 0;

				if($jump)
				{
					$body->vy -= $body->jumpSpeed * .75 * ($body->water?->drag ?? 1);
				}
			}

			if($body->ability === 'hanging')
			{
				$body->ability = '';
			}
		}

		if($this->onChange !== null)
		{
			($this->onChange)($player, $id, false);
		}

		return true;
	}

	/**
	 * Advance ropes after player movement and moving anchors, then acquire swept rider contacts.
	 *
	 * @param Registry $registry Bound registry supplied by the engine scheduler.
	 * @param float $dt Positive finite simulation delta in seconds.
	 * @return void Moves handles and riders through continuous casts and synchronizes rope sprites.
	 */
	public function update(Registry $registry, float $dt): void
	{
		if($this->closed)
		{
			return;
		}

		if($registry !== $this->registry || !is_finite($dt) || $dt <= 0)
		{
			throw new \InvalidArgumentException('Grapple update requires its registry and positive time');
		}

		$players = $registry->query(Query::all(Body::class, GrappleGrip::class));

		foreach($players as $player)
		{
			$grip = $registry->get($player, GrappleGrip::class);

			foreach($grip->cooldowns as $id => $time)
			{
				if(($grip->cooldowns[$id] = $time - $dt) <= 1e-9)
				{
					unset($grip->cooldowns[$id]);
				}
			}

			if($grip->point !== null && (!isset($this->points[$grip->point])
				|| $registry->get($player, Body::class)->ability !== 'hanging'
			)
			){
				$this->release($player, inherit: false);
			}
		}

		$this->moveAnchors($dt);

		foreach($this->points as $id => $point)
		{
			if(!$registry->has($id, Rope::class) || !$registry->has($id, GrapplePoint::class))
			{
				$this->remove($id);
				continue;
			}

			$rope = $registry->get($id, Rope::class);
			$anchor = $registry->has($rope->anchor, RopeAnchor::class)
				? $registry->get($rope->anchor, RopeAnchor::class)
				: null;

			$riders = $this->riders($id, $players);

			if($anchor === null || !$anchor->enabled)
			{
				foreach($riders as $player => $body)
				{
					$this->release($player);
				}

				$this->draw($point, $rope, false);
				continue;
			}

			$from = [$rope->x, $rope->y];
			$leader = array_key_first($riders);
			$axis = $leader === null ? 0 : $registry->get($leader, GrappleGrip::class)->axis;
			$this->move($point, $rope, $anchor, $riders, $axis, $dt);

			foreach($players as $player)
			{
				$body = $registry->get($player, Body::class);
				$grip = $registry->get($player, GrappleGrip::class);

				if($body->ability === 'riding' || $grip->point !== null || isset($grip->cooldowns[$id])
					|| ($point->collisionLayer !== 0 && $point->collisionLayer !== $body->collisionLayer)
				){
					continue;
				}

				if($this->contact($body, $this->previous[$player] ?? [$body->x, $body->y], $point, $rope, $from))
				{
					$this->attach($player, $point, $rope);
				}
			}

			$this->draw($point, $rope);
		}

		$this->previous = [];

		foreach($players as $player)
		{
			if($registry->has($player, Body::class))
			{
				$this->sync($player, $registry->get($player, Body::class));
			}
		}

		foreach($this->anchors as $id => $anchor)
		{
			$this->anchorPrevious[$id] = [$anchor->x, $anchor->y];
		}
	}

	/**
	 * Replace the previous player position after a teleport or respawn.
	 *
	 * @param int $player Entity whose old sweep origin should be forgotten.
	 * @param Body $body Teleported body in its new world position.
	 * @return void Prevents the teleport path from attaching to intervening handles.
	 */
	public function sync(int $player, Body $body): void
	{
		$this->previous[$player] = [$body->x, $body->y];
	}

	/**
	 * Read followed entities and calculate the anchor's whole-step velocity.
	 *
	 * @param float $dt Positive fixed step duration in seconds.
	 * @return void Disables missing followers and synchronizes visible anchor artwork.
	 */
	private function moveAnchors(float $dt): void
	{
		foreach($this->anchors as $id => $anchor)
		{
			if($anchor->followEntity !== null)
			{
				$position = $this->followedPosition($anchor);

				if($position === null)
				{
					$anchor->enabled = false;
				}
				else
				{
					[$anchor->x, $anchor->y] = $position;
				}
			}

			[$x, $y] = $this->anchorPrevious[$id];
			$anchor->vx = ($anchor->x - $x) / $dt;
			$anchor->vy = ($anchor->y - $y) / $dt;

			if($this->registry->has($id, Sprite::class))
			{
				$sprite = $this->registry->get($id, Sprite::class);
				$sprite->x = $anchor->x;
				$sprite->y = $anchor->y;
			}
		}
	}

	/**
	 * Read the bottom-center suspension point of a supported moving entity.
	 *
	 * @param RopeAnchor $anchor Anchor with an optional entity reference.
	 * @return array{float,float}|null Followed position, or null for a fixed or missing entity.
	 */
	private function followedPosition(RopeAnchor $anchor): ?array
	{
		if($anchor->followEntity !== null)
		{
			foreach([Body::class, MovingPlatform::class, BuoyantPlatform::class] as $type)
			{
				if($this->registry->has($anchor->followEntity, $type))
				{
					$position = $this->registry->get($anchor->followEntity, $type);

					return $position instanceof Body
						? [$position->supportX(), $position->supportY()]
						: [$position->x, $position->y];
				}
			}
		}

		return null;
	}

	/**
	 * Collect live player bodies attached to a particular handle.
	 *
	 * @param int $point Handle entity ID.
	 * @param list<int> $players Current opt-in player entity snapshot.
	 * @return array<int,Body> Attached bodies keyed by player entity ID.
	 */
	private function riders(int $point, array $players): array
	{
		$riders = [];

		foreach($players as $player)
		{
			if($this->registry->get($player, GrappleGrip::class)->point === $point)
			{
				$riders[$player] = $this->registry->get($player, Body::class);
			}
		}

		return $riders;
	}

	/**
	 * Sweep a proposed rope path and all attached bodies in short curvature increments.
	 *
	 * Every increment casts its full displacement, so speed cannot skip thin terrain. A blocked
	 * rider stops the handle; an anchor pulling beyond reach releases riders instead of crushing.
	 *
	 * @param GrapplePoint $point Handle dimensions and plane.
	 * @param Rope $rope Mutable endpoint position and momentum.
	 * @param RopeAnchor $anchor Suspension position at the end of this step.
	 * @param array<int,Body> $riders Attached bodies keyed by entity ID.
	 * @param float $axis First rider's horizontal pump input.
	 * @param float $dt Positive fixed-step duration in seconds.
	 * @return void Moves the hook and passengers only through collision-free displacement.
	 */
	private function move(
		GrapplePoint $point
		, Rope $rope
		, RopeAnchor $anchor
		, array $riders
		, float $axis
		, float $dt
	): void {
		$steps = max(1, min(2048, (int)ceil(max($dt * 120, hypot($rope->vx, $rope->vy) * $dt / 4))));
		$origin = $this->anchorPrevious[$rope->anchor] ?? [$anchor->x, $anchor->y];
		$local = clone $anchor;
		$probe = new Body;
		$probe->radius = $point->width / 2;
		$probe->halfHeight = $point->height / 2;
		$probe->collisionLayer = $point->collisionLayer;

		for($step = 1; $step <= $steps; $step++)
		{
			$local->x = $origin[0] + ($anchor->x - $origin[0]) * $step / $steps;
			$local->y = $origin[1] + ($anchor->y - $origin[1]) * $step / $steps;
			$x = $rope->x;
			$y = $rope->y;
			$rope->step($local, $dt / $steps, $axis);
			$dx = $rope->x - $x;
			$dy = $rope->y - $y;
			$probe->x = $x;
			$probe->y = $y - $probe->halfHeight;
			$hit = $this->terrain->castBody($probe, $dx, $dy);

			foreach($riders as $body)
			{
				$contact = $this->terrain->castBody($body, $dx, $dy, $body->dropTimer > 0);

				if($contact !== null && ($hit === null || $contact->t < $hit->t))
				{
					$hit = $contact;
				}
			}

			if($hit !== null)
			{
				$fraction = max(0, $hit->t - .001 / max(.001, hypot($dx, $dy)));
				$rope->x = $x + $dx * $fraction;
				$rope->y = $y + $dy * $fraction;
				$normal = min(0, $rope->vx * $hit->nx + $rope->vy * $hit->ny);
				$rope->vx -= $normal * $hit->nx;
				$rope->vy -= $normal * $hit->ny;
			}

			foreach($riders as $player => $body)
			{
				$body->x += $rope->x - $x;
				$body->y += $rope->y - $y;

				if(hypot($rope->x - $local->x, $rope->y - $local->y) > $rope->length + .01)
				{
					$this->release($player);
					unset($riders[$player]);
				}
			}
		}
	}

	/**
	 * Detect swept relative contact between a player's oriented bounds and a moving handle.
	 *
	 * @param Body $body Player at its current movement endpoint.
	 * @param array{float,float} $from Player position after the preceding grapple update.
	 * @param GrapplePoint $point Handle contact dimensions.
	 * @param Rope $rope Current handle bottom anchor.
	 * @param array{float,float} $hookFrom Handle bottom anchor before rope movement.
	 * @return bool True if the full relative movement crosses the combined bounds.
	 */
	private function contact(Body $body, array $from, GrapplePoint $point, Rope $rope, array $hookFrom): bool
	{
		$ca = abs(cos($body->angle));
		$sa = abs(sin($body->angle));
		$near = 0;
		$far = 1;
		$axes = [
			[
				$from[0] - $hookFrom[0], $body->x - $from[0] - ($rope->x - $hookFrom[0])
				, $ca * $body->radius + $sa * $body->halfHeight + $point->width / 2
			]
			, [
				$from[1] - $hookFrom[1] + $point->height / 2
				, $body->y - $from[1] - ($rope->y - $hookFrom[1])
				, $sa * $body->radius + $ca * $body->halfHeight + $point->height / 2
			]
		];

		foreach($axes as [$origin, $delta, $extent])
		{
			if(abs($delta) < 1e-9)
			{
				if(abs($origin) > $extent)
				{
					return false;
				}

				continue;
			}

			$a = (-$extent - $origin) / $delta;
			$b = ($extent - $origin) / $delta;
			$near = max($near, min($a, $b));
			$far = min($far, max($a, $b));

			if($near > $far)
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Align a contacted player beneath a handle only when standing bounds and transport are clear.
	 *
	 * @param int $player Live Body plus GrappleGrip entity.
	 * @param GrapplePoint $point Contacted handle receiving the rider.
	 * @param Rope $rope Endpoint receiving the first rider's approach momentum.
	 * @return bool True after attachment; false if standing or alignment would cross solid terrain.
	 */
	private function attach(int $player, GrapplePoint $point, Rope $rope): bool
	{
		$body = $this->registry->get($player, Body::class);
		$probe = clone $body;
		$probe->angle = 0;
		$probe->halfHeight = $body->standingHeight;
		$dx = $rope->x - $body->x;
		$dy = $rope->y + $probe->halfHeight - 5 - $body->y;

		if(!$this->terrain->canPlace($probe) || $this->terrain->castBody($probe, $dx, $dy) !== null)
		{
			return false;
		}

		$probe->x += $dx;
		$probe->y += $dy;

		if(!$this->terrain->canPlace($probe))
		{
			return false;
		}

		$rope->vx = $rope->noSwing ? $rope->vx : ($body->vx ?: cos($body->angle) * $body->groundSpeed);
		$rope->vy = $rope->noSwing ? $rope->vy : $body->vy;
		$body->x = $probe->x;
		$body->y = $probe->y;
		$body->halfHeight = $probe->halfHeight;
		$body->vx = $body->vy = $body->groundSpeed = $body->angle = 0;
		$body->grounded = $body->grinding = $body->rolling = $body->jumping = $body->crouching = false;
		$body->charge = $body->dropCharge = $body->coyote = $body->jumpBuffer = 0;
		$body->ability = 'hanging';
		$body->lightDashed = $body->skimming = false;
		$body->wall = 0;
		$body->dropTimer = $body->crouchTime = 0;
		$this->registry->get($player, GrappleGrip::class)->point = $point->entity;

		if($this->onChange !== null)
		{
			($this->onChange)($player, $point->entity, true);
		}

		return true;
	}

	/**
	 * Synchronize handle artwork and repeated rope segments with the current endpoint geometry.
	 *
	 * @param GrapplePoint $point Handle whose entity owns the sprites.
	 * @param Rope $rope Endpoint and anchor reference used for placement.
	 * @param bool $visible Whether the anchor remains available.
	 * @return void Reuses segment sprites and trims the final segment to the actual rope distance.
	 */
	private function draw(GrapplePoint $point, Rope $rope, bool $visible = true): void
	{
		$id = $point->entity;

		if($this->registry->has($id, Sprite::class))
		{
			$sprite = $this->registry->get($id, Sprite::class);
			$sprite->x = $rope->x;
			$sprite->y = $rope->y;
			$sprite->opacity = $visible ? $this->art[$id][2] : 0;
		}

		if(!$this->registry->has($id, SpriteGroup::class))
		{
			return;
		}

		$group = $this->registry->get($id, SpriteGroup::class);

		if(!$visible || !$this->registry->has($rope->anchor, RopeAnchor::class))
		{
			$group->sprites = [];

			return;
		}

		$anchor = $this->registry->get($rope->anchor, RopeAnchor::class);
		$dx = $rope->x - $anchor->x;
		$dy = $rope->y - $anchor->y;
		$distance = hypot($dx, $dy);
		$length = max(0, $distance - $point->height);
		[$gid, $tileLength] = $this->art[$id];
		$segment = max($tileLength, $length / 2048);
		$count = (int)ceil($length / $segment);
		$rotation = atan2($dy, $dx) - M_PI_2;
		$sprites = [];

		for($i = 0; $i < $count; $i++)
		{
			$sprite = $group->sprites[$i] ?? new Sprite($gid, originY: 0);
			$offset = $i * $segment;
			$sprite->x = $anchor->x + $dx * $offset / $distance;
			$sprite->y = $anchor->y + $dy * $offset / $distance;
			$sprite->rotation = $rotation;
			$sprite->scaleY = min($segment, $length - $offset) / $tileLength;
			$sprite->outline = false;
			$sprite->opacity = $this->art[$id][2];
			$sprite->tint = $this->art[$id][3];
			$sprites[] = $sprite;
		}

		$group->sprites = $sprites;
	}

	/**
	 * Remove a handle and release its riders without destroying its shared anchor.
	 *
	 * @param int $entity Owned grapple entity to retire.
	 * @return void Removes the handle, grouped rope sprites, and its internal artwork metadata.
	 */
	public function remove(int $entity): void
	{
		if(!isset($this->points[$entity]))
		{
			return;
		}

		foreach($this->registry->query(GrappleGrip::class) as $player)
		{
			if($this->registry->get($player, GrappleGrip::class)->point === $entity)
			{
				$this->release($player);
			}
		}

		if($this->registry->alive($entity))
		{
			$this->registry->destroy($entity);
		}

		unset($this->points[$entity], $this->art[$entity]);
	}

	/**
	 * Retire this level's handles and anchor proxies, releasing attached players first.
	 *
	 * @return void Removes owned sprites and components once; followed external entities survive.
	 */
	public function close(): void
	{
		if($this->closed)
		{
			return;
		}

		$this->closed = true;

		foreach(array_keys($this->points) as $id)
		{
			$this->remove($id);
		}

		foreach(array_keys($this->anchors) as $id)
		{
			if($this->registry->alive($id))
			{
				$this->registry->destroy($id);
			}
		}

		$this->anchors = $this->previous = $this->anchorPrevious = [];
		$this->onChange = null;
	}

	/**
	 * Expose live handle, rope, anchor, and rider state for scripts and browser diagnostics.
	 *
	 * @return list<array<string,mixed>> World coordinates, length, source IDs, and rider entity IDs.
	 */
	public function snapshot(): array
	{
		$result = [];
		$players = $this->registry->query(Query::all(Body::class, GrappleGrip::class));

		foreach($this->points as $id => $point)
		{
			if(!$this->registry->has($id, Rope::class))
			{
				continue;
			}

			$rope = $this->registry->get($id, Rope::class);
			$anchor = $this->registry->has($rope->anchor, RopeAnchor::class)
				? $this->registry->get($rope->anchor, RopeAnchor::class)
				: null;

			$result[] = [
				'entity' => $id
				, 'sourceId' => $point->sourceId
				, 'x' => $rope->x
				, 'y' => $rope->y
				, 'anchorX' => $anchor?->x
				, 'anchorY' => $anchor?->y
				, 'length' => $rope->length
				, 'vx' => $rope->vx
				, 'vy' => $rope->vy
				, 'noSwing' => $rope->noSwing
				, 'active' => $anchor?->enabled ?? false
				, 'riders' => array_keys($this->riders($id, $players))
			];
		}

		return $result;
	}
}
