<?php
declare(strict_types=1);
namespace PhpNoid;

use Phonics\Ecs\Registry;
use Phonics\Input\Controls;
use Phonics\Package\Context;
use Phonics\Tiled\TileMap;
use Phonics\Tiled\Loader;
use PhpNoid\Component\Ball;
use PhpNoid\Component\Brick;
use PhpNoid\Component\Capsule;
use PhpNoid\Component\Enemy;
use PhpNoid\Component\Paddle;
use PhpNoid\Component\Pointer;
use PhpNoid\Component\Projectile;
use PhpNoid\Component\State;

/** Level lifecycle and shared game services used by independently scheduled systems. */
final class Playfield
{
	public State $state;
	public Controls $input;
	public Pointer $pointer;
	public CollisionWorld $collisions;
	public ?TileMap $map = null;
	public int $paddle;
	public array $capsules;
	public $loadStage;
	private array $stageEntities = [];

	/**
	 * Initialize Playfield state and its owned services.
	 *
	 * @param Context $context Session registration and lifecycle services.
	 * @param int $stages Number of maps in the campaign.
	 * @return void Completes the operation.
	 */
	public function __construct(public Context $context, int $stages = 25)
	{
		$this->state = new State($stages);
		$this->input = new Controls;
		$this->pointer = new Pointer;
		$this->capsules = json_decode(file_get_contents(dirname(__DIR__) . '/assets/capsules.json'), true);
		$this->collisions = new CollisionWorld($context->entities);
		$context->onClose([$this->collisions, 'close']);
	}

	/**
	 * Return the playfield entity registry.
	 *
	 * @return Registry Result of this operation.
	 */
	public function registry(): Registry { return $this->context->entities; }

	/**
	 * Replace stage entities with the supplied authored map.
	 *
	 * @param TileMap $map Loaded Tiled map defining this stage.
	 * @param int $index Zero-based stage index.
	 * @return void Completes the operation.
	 */
	public function stage(TileMap $map, int $index): void
	{
		$entering = $this->state->phase === 'loading' && $this->state->nextStage === $index;

		foreach($this->stageEntities as $id)
		if($this->registry()->alive($id)) $this->registry()->destroy($id);

		foreach([Ball::class, Brick::class, Capsule::class, Enemy::class, Projectile::class, Paddle::class] as $type)
		foreach($this->registry()->query($type) as $id) $this->registry()->destroy($id);
		$this->map = $map;
		$this->state->width = $map->data['width'] * $map->projection->tileWidth;
		$this->state->height = $map->data['height'] * $map->projection->tileHeight;
		$this->stageEntities = $this->context->factories->spawn($map, $this->registry());
		$this->paddle = $this->registry()->query(Paddle::class)[0]
			?? $this->registry()->create(new Paddle($this->state->width / 2, $this->state->height - 30));

		$this->state->stage = $index;
		$this->state->phase = $entering ? 'entering' : 'playing';
		$this->state->nextStage = null;
		$this->state->transitionTime = 0;
		$this->state->fade = $entering ? 1 : 0;
		$this->state->name = Loader::properties($map->data['properties'] ?? [])['name'] ?? 'Stage ' . ($index + 1);
		$this->state->notice = '';
		$this->state->banner = 2;
		$this->state->flash = 0;
		$this->state->exit = false;
		$this->state->confirmReady = false;
		$this->pointer->reset();
		$this->serve();

		$this->collisions->sync(
			$this->state->width
			, $this->state->height
			, $this->registry()->get($this->paddle, Paddle::class)
		);
	}

	/**
	 * Attach a fresh player ball and reset temporary paddle powers.
	 *
	 * @return void Completes the operation.
	 */
	public function serve(): void
	{
		$paddle = $this->resetPaddle();
		$this->registry()->create(new Ball($paddle->x, $paddle->y - 13));
	}

	/**
	 * Clear temporary equipment on every life and stage boundary, including game over.
	 * @return Paddle Reset paddle component.
	 */
	private function resetPaddle(): Paddle
	{
		$paddle = $this->registry()->get($this->paddle, Paddle::class);
		$paddle->powers = [];
		$paddle->powerFlashes = [];
		$paddle->invisible = $paddle->cooldown = $paddle->catchFlash = $paddle->respawnProtection = 0;
		$paddle->width = $paddle->baseWidth;

		return $paddle;
	}

	/**
	 * Retire active balls and enter game over or serve the next life.
	 *
	 * @return void Completes the operation.
	 */
	public function loseLife(): void
	{
		if($this->state->phase !== 'playing') return;

		foreach([Ball::class, Capsule::class, Projectile::class] as $type)
		foreach($this->registry()->query($type) as $id) $this->registry()->destroy($id);

		if(!$this->state->infiniteLives) $this->state->lives--;

		$this->state->events[] = 'loss';
		$this->state->flash = .4;
		$this->state->confirmReady = false;
		$this->pointer->reset();

		if(!$this->state->infiniteLives && $this->state->lives <= 0)
		{
			$this->resetPaddle();
			$this->state->phase = 'gameover';
		}
		else
		{
			$this->serve();
			$this->registry()->get($this->paddle, Paddle::class)->respawnProtection = 2;
		}
	}

	/**
	 * Apply brick damage, score, and configured capsule drops.
	 *
	 * @param int $id Entity identifier in the session registry.
	 * @param int $amount Damage to apply to a breakable brick.
	 * @param bool $reward Whether a destroyed brick grants score and its capsule.
	 * @param string $source Red energy can destroy green metallic bricks.
	 * @return void Completes the operation.
	 */
	public function damage(int $id, int $amount = 1, bool $reward = true, string $source = 'normal'): void
	{
		if(!$this->registry()->alive($id)) return;

		$brick = $this->registry()->get($id, Brick::class);

		if($brick->unbreakable || ($brick->material === 'green' && $source !== 'red')) return;

		$brick->hits -= $amount;
		$this->state->events[] = 'hit';

		if($brick->hits > 0) return;

		if($reward && $brick->material !== 'green')
		{
			$this->state->score += $brick->score;

			if($brick->capsule !== '')
			$this->registry()->create(
				new Capsule($brick->x + $brick->width / 2, $brick->y + $brick->height / 2, $brick->capsule)
			);
		}

		$this->registry()->destroy($id);
	}

	/**
	 * Damage a ship using laser units and award its family-specific score on destruction.
	 * @param int $id Ship entity identifier.
	 * @param int $amount Lasers deal one; missiles and ordinary balls deal three.
	 * @param bool $reward Paddle collisions retire ships without points.
	 * @return void Applies damage and retires destroyed ships.
	 */
	public function damageEnemy(int $id, int $amount = 3, bool $reward = true): void
	{
		if(!$this->registry()->alive($id)) return;

		$enemy = $this->registry()->get($id, Enemy::class);
		$enemy->hits -= $amount;
		$this->state->events[] = 'hit';

		if($enemy->hits > 0) return;

		if($reward && $enemy->family !== 'sympathizer')
		$this->state->score += in_array($enemy->family, ['destroyer', 'annihilator'], true) ? 500 : 250;

		$this->registry()->destroy($id);
	}

	/**
	 * Read held fire across keyboard, controller, and mouse.
	 * @return bool Whether a weapon should continue firing.
	 */
	public function firing(): bool { return $this->input->down(0) || $this->pointer->held || $this->pointer->tap; }

	/**
	 * Read a buffered launch edge across input devices.
	 * @return bool Whether attached balls should be released this step.
	 */
	public function launching(): bool { return $this->input->pressed(0) || $this->pointer->tap; }

	/**
	 * Apply a capsule action using the data-driven definition.
	 *
	 * @param string $kind Capsule action or projectile kind.
	 * @return void Completes the operation.
	 */
	public function power(string $kind): void
	{
		$definition = $this->capsules[$kind] ?? throw new \UnexpectedValueException("Unknown capsule $kind");
		$paddle = $this->registry()->get($this->paddle, Paddle::class);
		$paddle->powerFlashes[$kind] = .8;

		if($definition['duration'] > 0)
		{
			// Only equipment in the same authored conflict group replaces another power.
			if(isset($definition['group']))
			foreach($paddle->powers as $active => $remaining)
			if(($this->capsules[$active]['group'] ?? null) === $definition['group'])
			unset($paddle->powers[$active]);

			$paddle->powers[$kind] = (float)$definition['duration'];
			$paddle->width = $definition['width'] ?? $paddle->width;
		}

		$this->state->notice = $definition['label'];
		$this->state->banner = 2;

		if($definition['action'] === 'split')
		{
			foreach($this->registry()->query(Ball::class) as $id)
			{
				$ball = $this->registry()->get($id, Ball::class);

				if($ball->team !== 'player') continue;

				$ball->attached = false;
				$ball->vy = -abs($ball->vy);

				foreach([-0.45, 0.45] as $angle)
				{
					$copy = clone $ball;
					$copy->attached = false;
					$copy->vx = $ball->vx * cos($angle) - $ball->vy * sin($angle);
					$copy->vy = -abs($ball->vx * sin($angle) + $ball->vy * cos($angle));
					$this->registry()->create($copy);
				}

				break;
			}
		}
		elseif($definition['action'] === 'extra') $this->state->lives++;
		elseif($definition['action'] === 'exit') $this->state->exit = true;

		$this->state->events[] = 'power';
	}

	/**
	 * Reset lives and score, then request the selected campaign stage.
	 *
	 * @param bool $continue Whether to reload the current stage instead of the first.
	 * @return void Completes the operation.
	 */
	public function restart(bool $continue = false): void
	{
		$this->state->nextStage = null;
		$this->state->transitionTime = $this->state->fade = 0;
		$this->state->lives = 3;
		$this->state->infiniteLives = false;
		$this->state->run++;
		$this->state->score = 0;
		$this->state->phase = 'loading';
		($this->loadStage)($continue ? $this->state->stage : 0);
	}
}
