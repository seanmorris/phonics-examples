<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use Phonics\Ecs\Registry;
use SanePhonics\Physics\Body;
use SanePhonics\Physics\WaterRegion;
use SanePhonics\Physics\WaterRegions;
use Phonics\Render\Sprite;
use Phonics\Tiled\TileMap;

/** Emits animated surface splashes and retains one directional skim spray per body. */
final class WaterParticles
{
	/** @var array<string,int> Animated GIDs keyed by WaterParticle kind. */
	private array $gids;
	/** @var array<int,WaterParticle> Owned effects keyed by entity ID. */
	private array $owned = [];
	/** @var array<int,int> Persistent skim effect entity keyed by body entity ID. */
	private array $skims = [];
	/** @var array<int,array{float,float,WaterRegion|null}> Previous body pose and water. */
	private array $previous = [];
	private float $time = 0;
	private bool $closed = false;

	/**
	 * Resolve annotated splash and skim animations from a map atlas.
	 *
	 * @param Registry $registry Registry containing bodies and receiving particle entities.
	 * @param TileMap $map Map whose tilesets define water-splash and water-skim classes.
	 * @param WaterRegions $waters Surface collection used to place crossing and skim effects.
	 * @param string $splashClass Nonempty Tiled tile class for the one-shot entry animation.
	 * @param string $skimClass Nonempty Tiled tile class for the looping surface spray.
	 * @param float $milliseconds Initial simulation clock in milliseconds for local animation starts.
	 * @return void Retains ECS and water references without creating an effect.
	 */
	public function __construct(
		private Registry $registry
		, TileMap $map
		, private WaterRegions $waters
		, string $splashClass = 'water-splash'
		, string $skimClass = 'water-skim'
		, float $milliseconds = 0
	){
		if($splashClass === '' || $skimClass === '' || !is_finite($milliseconds) || $milliseconds < 0)
		{
			throw new \InvalidArgumentException('Invalid water particle classes or animation time');
		}

		$this->gids = [
			WaterParticle::SPLASH => $this->gid($map, $splashClass)
			, WaterParticle::SKIM => $this->gid($map, $skimClass)
		];

		$this->time = $milliseconds / 1000;
	}

	/**
	 * Advance finite effects, detect surface crossings, and synchronize active skim sprays.
	 *
	 * @param Registry $registry Registry supplied by the engine scheduler.
	 * @param float $dt Positive fixed simulation delta in seconds.
	 * @return void Mutates or retires owned particle entities after movement state is available.
	 */
	public function update(Registry $registry, float $dt): void
	{
		if($this->closed)
		{
			return;
		}

		if($registry !== $this->registry || !is_finite($dt) || $dt <= 0)
		{
			throw new \InvalidArgumentException('Water particles require their registry and a positive delta');
		}

		$this->time += $dt;
		$this->age($dt);
		$active = [];

		foreach($registry->query(Body::class) as $bodyId)
		{
			$body = $registry->get($bodyId, Body::class);
			$from = $this->previous[$bodyId] ?? [$body->x,$body->y,$body->water];
			$active[$bodyId] = [$body->x,$body->y,$body->water];

			if(($body->waterEntered || $body->waterExited) && !$body->skimming)
			{
				$crossing = $this->crossing($body, $from);

				if($crossing !== null)
				{
					$this->splash($crossing[0], $crossing[1], $bodyId);
				}
			}

			$this->skim($bodyId, $body);
		}

		foreach(array_diff_key($this->skims, $active) as $bodyId => $particleId)
		{
			$this->remove($particleId);
			unset($this->skims[$bodyId]);
		}

		$this->previous = $active;
	}

	/**
	 * Emit a one-shot splash at an exact water surface point.
	 *
	 * @param float $x World X coordinate at the surface crossing.
	 * @param float $y World Y coordinate of the water surface.
	 * @param int|null $body Optional source body entity for inspection and tests.
	 * @return int Newly allocated particle entity ID.
	 */
	public function splash(float $x, float $y, ?int $body = null): int
	{
		if($this->closed)
		{
			throw new \LogicException('Water particle manager is closed');
		}

		if(!is_finite($x) || !is_finite($y))
		{
			throw new \InvalidArgumentException('Water splash coordinates must be finite');
		}

		$particle = new WaterParticle(WaterParticle::SPLASH, .32, $body);
		$sprite = new Sprite($this->gids[WaterParticle::SPLASH], $x, $y + 2, .5, 1);
		$sprite->time = -$this->time * 1000;
		$id = $this->registry->create($particle, $sprite);
		$this->owned[$id] = $particle;

		return $id;
	}

	/**
	 * Summarize live owned effects without exposing mutable sprite instances.
	 *
	 * @return array{splashes:int,skims:int,total:int} Counts suitable for diagnostics and scripting.
	 */
	public function snapshot(): array
	{
		$splashes = $skims = 0;

		foreach($this->owned as $particle)
		{
			if($particle->kind === WaterParticle::SPLASH)
			{
				$splashes++;
			}
			else
			{
				$skims++;
			}
		}

		return ['splashes' => $splashes, 'skims' => $skims, 'total' => count($this->owned)];
	}

	/**
	 * Destroy every owned particle and discard body history.
	 *
	 * @return void Leaves bodies, water regions, and unrelated sprites intact.
	 */
	public function close(): void
	{
		if($this->closed)
		{
			return;
		}

		$this->closed = true;

		foreach(array_keys($this->owned) as $id)
		{
			$this->remove($id);
		}

		$this->skims = $this->previous = [];
	}

	/**
	 * Resolve the first tile carrying an exact effect class.
	 *
	 * @param TileMap $map Map whose normalized tileset definitions are searched.
	 * @param string $class Exact Tiled tile class to find.
	 * @return int Global tile ID for the annotated animation.
	 */
	private function gid(TileMap $map, string $class): int
	{
		foreach($map->tilesets as $set)
		{
			foreach($set['definitions'] as $local => $tile)
			{
				if(($tile['class'] ?? $tile['type'] ?? '') === $class)
				{
					return $set['firstgid'] + $local;
				}
			}
		}

		throw new \OutOfBoundsException("Water particle class $class is missing");
	}

	/**
	 * Retire expired finite particles before emitting effects for the current step.
	 *
	 * @param float $dt Positive fixed simulation delta in seconds.
	 * @return void Advances owned component ages and destroys completed splashes.
	 */
	private function age(float $dt): void
	{
		foreach($this->owned as $id => $particle)
		{
			if(!$this->registry->alive($id) || $particle->step($dt))
			{
				$this->remove($id);
			}
		}
	}

	/**
	 * Find the first crossed top surface for a body that entered or exited water this step.
	 *
	 * @param Body $body Current body pose after movement.
	 * @param array{float,float,WaterRegion|null} $from Previous pose and active region.
	 * @return array{float,float}|null Interpolated world surface point, or null for side/bottom exits.
	 */
	private function crossing(Body $body, array $from): ?array
	{
		[$fromX, $fromY, $previous] = $from;
		$regions = [];

		foreach([$previous,$body->water] as $water)
		{
			if($water !== null)
			{
				$regions[spl_object_id($water)] = $water;
			}
		}

		foreach($this->waters->crossed($fromX, $fromY, $body->x - $fromX, $body->y - $fromY) as $water)
		{
			$regions[spl_object_id($water)] = $water;
		}

		foreach($regions as $water)
		{
			$dy = $body->y - $fromY;

			if(abs($dy) < 1e-9 || ($fromY - $water->y) * ($body->y - $water->y) > 0)
			{
				continue;
			}

			$t = ($water->y - $fromY) / $dy;
			$x = $fromX + ($body->x - $fromX) * $t;

			if($x >= $water->x && $x <= $water->x + $water->width)
			{
				return [$x,$water->y];
			}
		}

		return null;
	}

	/**
	 * Create, move, or retire one looping spray for a body's current skim state.
	 *
	 * @param int $bodyId Source body entity ID.
	 * @param Body $body Body carrying the grounded skim state and movement direction.
	 * @return void Keeps at most one persistent effect per body.
	 */
	private function skim(int $bodyId, Body $body): void
	{
		$surface = $body->skimming
			? $this->waters->surfaceAt($body->x, $body->y + $body->halfHeight, 3)
			: null;

		if($surface === null)
		{
			if(isset($this->skims[$bodyId]))
			{
				$this->remove($this->skims[$bodyId]);
				unset($this->skims[$bodyId]);
			}

			return;
		}

		$id = $this->skims[$bodyId] ?? null;

		if($id === null || !$this->registry->alive($id))
		{
			$particle = new WaterParticle(WaterParticle::SKIM, 0, $bodyId);
			$sprite = new Sprite($this->gids[WaterParticle::SKIM], originX: .5, originY: 1);
			$sprite->opacity = .65;
			$sprite->scaleX = 1.5;
			$sprite->time = -$this->time * 1000;
			$id = $this->registry->create($particle, $sprite);
			$this->owned[$id] = $particle;
			$this->skims[$bodyId] = $id;
		}

		$sprite = $this->registry->get($id, Sprite::class);
		$speed = abs($body->groundSpeed) > 1 ? $body->groundSpeed : $body->vx;
		$direction = $speed < 0 ? -1 : ($speed > 0 ? 1 : $body->facing);
		$sprite->x = $body->x - $direction * 24;
		$sprite->y = $surface->y + 2;
		$sprite->flipX = $direction < 0;
	}

	/**
	 * Remove one owned entity and any skim index that still points at it.
	 *
	 * @param int $id Particle entity ID to retire when live.
	 * @return void Makes repeated or externally triggered removal harmless.
	 */
	private function remove(int $id): void
	{
		unset($this->owned[$id]);

		if($this->registry->alive($id))
		{
			$this->registry->destroy($id);
		}

		foreach($this->skims as $bodyId => $particleId)
		{
			if($particleId === $id)
			{
				unset($this->skims[$bodyId]);
			}
		}
	}
}
