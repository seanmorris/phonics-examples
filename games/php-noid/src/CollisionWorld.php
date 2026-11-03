<?php
declare(strict_types=1);
namespace PhpNoid;

use Phonics\Ecs\Registry;
use Phonics\Physics\Hit;
use Phonics\Physics\Pose;
use Phonics\Physics\Terrain;
use PhpNoid\Component\Brick;
use PhpNoid\Component\Enemy;
use PhpNoid\Component\Paddle;

/** Session-owned geometry shared by every ball, bounce, and weapon cast. */
final class CollisionWorld
{
	private ?Terrain $targets, $arena, $paddleBody;
	private array $entries = [Brick::class => [], Enemy::class => []];
	private ?array $arenaBounds = null, $paddleBounds = null;
	private ?int $paddleEdge = null, $paddleShape = null;
	private ?Paddle $paddle = null;
	private bool $closed = false;

	/**
	 * Track existing and subsequently attached targets without owning their gameplay state.
	 * @param Registry $registry Session registry whose lifecycle maintains shape membership.
	 * @return void Creates persistent indexes and subscribes to component changes.
	 */
	public function __construct(private Registry $registry)
	{
		$this->targets = new Terrain;
		$this->arena = new Terrain;
		$this->paddleBody = new Terrain;
		$registry->on('added', [$this, 'added']);
		$registry->on('removed', [$this, 'removed']);

		foreach([Brick::class, Enemy::class] as $type)
		foreach($registry->query($type) as $id) $this->added($registry, $id, $registry->get($id, $type));
	}

	/**
	 * Index an attached target immediately, including additions during an impact callback.
	 * @param Registry $registry Registry emitting the event.
	 * @param int $id Entity receiving the component.
	 * @param object $component Newly attached component.
	 * @return void Retains the component and its independently removable shape.
	 */
	public function added(Registry $registry, int $id, object $component): void
	{
		if($this->closed || !($component instanceof Brick || $component instanceof Enemy)) return;

		$type = get_class($component);

		// An earlier listener may have removed or replaced this component during the same event.
		if(!$registry->has($id, $type) || $registry->get($id, $type) !== $component) return;

		$this->entries[$type][$id] = ['component' => $component, 'handle' => null, 'bounds' => null];
		$this->syncTarget($type, $id, $this->entries[$type][$id]);
	}

	/**
	 * Remove geometry before another cast can encounter a retired component.
	 * @param Registry $registry Registry emitting the event.
	 * @param int $id Entity losing the component.
	 * @param object $component Detached component, which may already have a replacement.
	 * @return void Removes only this component's shape and membership.
	 */
	public function removed(Registry $registry, int $id, object $component): void
	{
		if($this->closed) return;

		$type = get_class($component);
		$entry = $this->entries[$type][$id] ?? null;

		if($entry && $entry['component'] === $component)
		{
			$this->targets->removeShape($entry['handle']);
			unset($this->entries[$type][$id]);
		}

		if($component === $this->paddle) $this->removePaddle();
	}

	/**
	 * Observe public component bounds once per collision-system update, never per cast.
	 * @param float $width Current arena width.
	 * @param float $height Current arena height.
	 * @param Paddle $paddle Selected player paddle, after movement or capsule effects.
	 * @return void Replaces only geometry whose bounds changed.
	 */
	public function sync(float $width, float $height, Paddle $paddle): void
	{
		$this->requireOpen();

		foreach($this->entries as $type => &$entries)
		foreach($entries as $id => &$entry) $this->syncTarget($type, $id, $entry);
		unset($entries, $entry);

		if($this->arenaBounds !== [$width, $height])
		{
			$this->arena = new Terrain;
			$this->arena->add([[8, 0], [8, $height]], false, 0, ['kind' => 'wall']);
			$this->arena->add([[$width - 8, $height], [$width - 8, 0]], false, 0, ['kind' => 'wall']);
			$this->arena->add([[$width - 8, 8], [8, 8]], false, 0, ['kind' => 'wall']);
			$this->arenaBounds = [$width, $height];
			$this->paddleEdge = null;
			$this->paddleBounds = null;
		}

		$bounds = [$paddle->x - $paddle->width / 2, $paddle->y - 7, $paddle->width, 14.0];

		if($bounds === $this->paddleBounds && $paddle === $this->paddle) return;

		$this->removePaddle();
		[$x, $y, $w, $h] = $bounds;
		$this->paddleEdge = $this->arena->addShape([[$x, $y], [$x + $w, $y]], false, 0, ['kind' => 'paddle']);
		$this->paddleShape = $this->paddleBody->addShape(self::box($x, $y, $w, $h), false, 0, ['kind' => 'paddle']);
		$this->paddleBounds = $bounds;
		$this->paddle = $paddle;
	}

	/**
	 * Retain unchanged target shapes, including damage and material-only changes.
	 * @param string $type Concrete brick or enemy component class.
	 * @param int $id Owning entity identifier.
	 * @param array $entry Component reference, shape handle, and previous bounds.
	 * @return void Updates one target's geometry if its bounds differ.
	 */
	private function syncTarget(string $type, int $id, array &$entry): void
	{
		$c = $entry['component'];
		$brick = $type === Brick::class;
		$x = $brick ? $c->x : $c->x - 14;
		$y = $brick ? $c->y : $c->y - 8;
		$w = $brick ? $c->width : 28.0;
		$h = $brick ? $c->height : 16.0;
		$old = $entry['bounds'];

		if($old && $old[0] === $x && $old[1] === $y && $old[2] === $w && $old[3] === $h) return;

		if($entry['handle'] !== null) $this->targets->removeShape($entry['handle']);

		$entry['handle'] = $this->targets->addShape(
			self::box($x, $y, $w, $h)
			, false
			, $brick ? 0 : 1
			, ['kind' => $brick ? 'brick' : 'enemy', 'entity' => $id]
		);

		$entry['bounds'] = [$x, $y, $w, $h];
	}

	/**
	 * Sweep a ball against shared targets and the arena, selecting the earliest contact.
	 * @param Pose $pose Disposable ball query pose; its plane selects ship eligibility.
	 * @param float $dx Full horizontal displacement.
	 * @param float $dy Full vertical displacement.
	 * @param bool $player Whether this ball may hit enemy ships.
	 * @return Hit|null Contact carrying kind and optional entity metadata.
	 */
	public function ball(Pose $pose, float $dx, float $dy, bool $player): ?Hit
	{
		$this->requireOpen();
		$pose->collisionLayer = $player ? 1 : 0;
		$target = $this->targets->castBody($pose, $dx, $dy);
		$boundary = $this->arena->castBody($pose, $dx, $dy);

		// The upward-facing paddle edge naturally rejects rising balls. At equal times,
		// retain wall-before-target and target-before-paddle ordering.
		if(!$target) return $boundary;

		if(!$boundary) return $target;

		return $boundary->t < $target->t
			|| ($boundary->t === $target->t && $boundary->metadata['kind'] === 'wall')
			? $boundary
			: $target;
	}

	/**
	 * Sweep a weapon through the shared target index or the hostile-shot paddle shape.
	 * @param Pose $pose Disposable projectile query pose.
	 * @param float $dx Full horizontal displacement.
	 * @param float $dy Full vertical displacement.
	 * @param bool $hostile Whether the projectile can hit only the paddle.
	 * @return Hit|null Earliest eligible contact with target metadata.
	 */
	public function projectile(Pose $pose, float $dx, float $dy, bool $hostile): ?Hit
	{
		$this->requireOpen();
		$pose->collisionLayer = 1;

		return ($hostile ? $this->paddleBody : $this->targets)->castBody($pose, $dx, $dy);
	}

	/**
	 * Remove both paddle representations without disturbing arena walls or targets.
	 * @return void Releases the selected paddle's shapes and bounds.
	 */
	private function removePaddle(): void
	{
		if($this->paddleEdge !== null) $this->arena->removeShape($this->paddleEdge);

		if($this->paddleShape !== null) $this->paddleBody->removeShape($this->paddleShape);

		$this->paddleEdge = $this->paddleShape = null;
		$this->paddleBounds = null;
		$this->paddle = null;
	}

	/**
	 * Build rectangular geometry in the core's world-space vertex format.
	 * @param float $x Left edge.
	 * @param float $y Top edge.
	 * @param float $width Horizontal extent.
	 * @param float $height Vertical extent.
	 * @return array Counterclockwise rectangle vertices.
	 */
	public static function box(float $x, float $y, float $width, float $height): array
	{
		return [[$x, $y], [$x + $width, $y], [$x + $width, $y + $height], [$x, $y + $height]];
	}

	/**
	 * Reject queries after session cleanup.
	 * @return void Returns while the cache is open or throws LogicException.
	 */
	private function requireOpen(): void
	{
		if($this->closed) throw new \LogicException('Collision world is closed');
	}

	/**
	 * Detach lifecycle listeners and release all geometry and component references.
	 * @return void Closes the cache once; repeated cleanup is harmless.
	 */
	public function close(): void
	{
		if($this->closed) return;

		$this->closed = true;
		$this->registry->off('added', [$this, 'added']);
		$this->registry->off('removed', [$this, 'removed']);
		$this->entries = [];
		$this->targets = $this->arena = $this->paddleBody = null;
		$this->arenaBounds = $this->paddleBounds = null;
		$this->paddleEdge = $this->paddleShape = null;
		$this->paddle = null;
	}
}
