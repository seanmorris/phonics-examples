<?php
declare(strict_types=1);

use Phonics\Ecs\Query;
use SanePhonics\Physics\Body;
use SanePhonics\Physics\Terrain;
use Phonics\Render\Atlas;
use Phonics\Render\BitmapFont;
use Phonics\Render\Camera;
use Phonics\Render\RectRenderer;
use Phonics\Render\SpriteRenderer;

/** Optional bitmap diagnostics, inspired by moving-boxes-2's debug OSD and FPS meter. */
final class DemoDebugOverlay
{
	public bool $enabled = false, $showFps = true;
	public int $frame = 0;
	public ?float $fps = null, $frameMilliseconds = null;
	public array $snapshot = [];
	private ?SpriteRenderer $sprites = null;
	private ?RectRenderer $rects = null;
	private bool $ownsAtlas, $closed = false;
	private int $samples = 0, $previousCasts = 0;
	private float $seconds = 0;
	private ?Terrain $terrain = null;
	private array $rows = [], $cache = [], $nextCache = [];

	/**
	 * Prepare diagnostics without allocating GPU resources until something is displayed.
	 *
	 * @param BitmapFont $font Annotated font already used by the demo menus.
	 * @param Atlas|null $atlas Matching atlas to borrow; null creates one on the first draw.
	 * @return void Retains metadata; a borrowed atlas must outlive this overlay.
	 */
	public function __construct(private BitmapFont $font, private ?Atlas $atlas = null)
	{
		$this->ownsAtlas = $atlas === null;
	}

	/**
	 * Measure actual frame intervals and handle the shortcut before menu or gameplay capture.
	 *
	 * @param float $elapsed Active browser frame seconds, zero on startup or recovery.
	 * @param array{focused:bool,events:array} $input SDL UI snapshot with ordered keyboard events.
	 * @return void Updates averaged FPS independently of simulation pause and ignores key repeats.
	 */
	public function update(float $elapsed, array $input): void
	{
		if($this->closed)
		{
			return;
		}

		$this->frame++;

		if($elapsed <= 0)
		{
			$this->seconds = 0;
			$this->samples = 0;
			$this->fps = $this->frameMilliseconds = null;
		}
		else
		{
			$this->seconds += $elapsed;
			$this->samples++;

			if($this->seconds >= .25)
			{
				$this->fps = $this->samples / $this->seconds;
				$this->frameMilliseconds = 1000 * $this->seconds / $this->samples;
				$this->seconds = 0;
				$this->samples = 0;
			}
		}

		foreach($input['events'] as $event)
		{
			if($input['focused'] && $event['type'] === 'key' && $event['key'] === 'F9'
				&& empty($event['repeat']) && empty($event['ctrl'])
			){
				$this->enabled = !$this->enabled;
			}
		}
	}

	/**
	 * Describe the controlled actor without inventing a player for camera-only inspection scenes.
	 *
	 * @param Body|null $body Active player body, or null when the scene only pans a camera.
	 * @return array<string,string> Ordered labels and values; speeds use pixels per second.
	 */
	public static function playerRows(?Body $body): array
	{
		if($body === null)
		{
			return ['Player' => 'None (camera)'];
		}

		$mode = ((int)floor(($body->angle + M_PI / 4) / (M_PI / 2)) % 4 + 4) % 4;
		$surface = ['Floor (0)', 'L-wall (1)', 'Ceiling (2)', 'R-wall (3)'][$mode];
		$state = $body->ability;

		if($state === '' && $body->charge > 0)
		{
			$state = 'spindash';
		}
		elseif($state === '' && $body->grinding)
		{
			$state = $body->crouching ? 'grinding-crouching' : 'grinding';
		}
		elseif($state === '' && $body->rolling)
		{
			$state = 'rolling';
		}
		elseif($state === '' && $body->jumping)
		{
			$state = 'jumping';
		}
		elseif($state === '' && $body->crouching)
		{
			$state = 'crouching';
		}
		elseif($state === '')
		{
			$state = $body->grounded ? 'ground' : 'air';
		}

		return [
			'Player' => $body->profile
			, 'Grounded' => $body->grounded ? 'Yes' : 'No'
			, 'Surface' => $body->grounded ? $surface : 'Air'
			, 'X px' => sprintf('%.3f', $body->supportX())
			, 'Y px' => sprintf('%.3f', $body->supportY())
			, 'Collision layer' => (string)$body->collisionLayer
			, 'Gravity' => "$body->gravityX / $body->gravityY"
			, 'Facing / direction' => "$body->facing / $body->direction"
			, 'Drop charge' => sprintf('%.3f', $body->dropCharge)
			, 'Gnd px/s' => sprintf('%.3f', $body->groundSpeed)
			, 'X px/s' => sprintf('%.3f', $body->vx)
			, 'Y px/s' => sprintf('%.3f', $body->vy)
			, 'Gnd rad' => sprintf('%.3f', $body->angle)
			, 'Air rad' => $body->vx || $body->vy ? sprintf('%.3f', atan2($body->vy, $body->vx)) : '-'
			, 'State' => $state
		];
	}

	/**
	 * Read live diagnostics for the current rendered frame while keeping GPU draw counts separate.
	 *
	 * @param DemoGame $game Session supplying world, camera, input, and entity state.
	 * @param int $casts Collision casts since the previous rendered frame, not a lifetime total.
	 * @return array<string,string> Rows displayed in the upper-right panel.
	 */
	private function read(DemoGame $game, int $casts): array
	{
		$player = !in_array($game->scene, ['world', 'isometric'], true) ? $game->body : null;
		$camera = $game->camera;
		$stream = $game->level?->stream;
		$stats = $game->renderStats;
		$bossRegion = $player ? $game->level?->bossRegions?->active($player) : null;

		$cameraState = $player
			? sprintf(
				'%.1f / %.1f · %s %.2f/%.2f'
				, $camera->x
				, $camera->y
				, $game->cameraBehavior->mode
				, $game->cameraBehavior->anchorX
				, $game->cameraBehavior->anchorY
			)
			: sprintf('%.1f / %.1f · manual', $camera->x, $camera->y);

		return ['Scene' => $game->scene] + self::playerRows($player) + [
			'Camera X/Y' => $cameraState
			, 'Zoom' => sprintf('%.2f', $camera->zoom)
			, 'Screen lock' => $bossRegion
				? sprintf('%.0f..%.0f', $bossRegion->x, $bossRegion->x + $bossRegion->width)
				: 'Off'
			, 'Entities' => (string)count($game->engine->entities->query(Query::all()))
			, 'Maps R/P/E' => sprintf(
				'%d / %d / %d'
				, count($stream?->loaded() ?? [])
				, count($stream?->pending() ?? [])
				, count($stream?->errors ?? [])
			)
			, 'World draws' => (string)$stats['draws']
			, 'Instances' => (string)$stats['instances']
			, 'Tile uploads' => (string)$stats['uploads']
			, 'Casts/frame' => $player ? (string)$casts : '-'
			, 'Render frame' => (string)$this->frame
			, 'Game seconds' => sprintf('%.2f', $game->engine->time)
			, 'Input' => $game->engine->input->inputTheme
			, 'Sequence' => $game->lastSequence ?: '-'
			, 'Matches' => (string)$game->sequenceCount
		];
	}

	/**
	 * Draw screen-space telemetry with the menu's font atlas, before rendering a pause menu.
	 *
	 * @param DemoGame $game Session whose last world render supplied the statistics.
	 * @param bool $visible False on intro and title screens, retaining the user's display settings.
	 * @return void Publishes an inspector snapshot and submits at most two additional draw calls.
	 */
	public function render(DemoGame $game, bool $visible = true): void
	{
		$terrain = $game->level?->terrain;
		$total = $terrain?->raycasts ?? 0;
		$casts = $terrain === $this->terrain ? max(0, $total - $this->previousCasts) : 0;
		$this->terrain = $terrain;
		$this->previousCasts = $total;

		$this->snapshot = [
			'enabled' => $this->enabled
			, 'showFps' => $this->showFps
			, 'visible' => $visible && !$this->closed
			, 'frame' => $this->frame
			, 'fps' => $this->fps
			, 'frameMs' => $this->frameMilliseconds
			, 'draws' => 0
			, 'panel' => null
			, 'rows' => []
		];

		if(!$visible || $this->closed || (!$this->enabled && !$this->showFps))
		{
			$this->cache = [];

			return;
		}

		$width = $game->engine->device->width;
		$height = $game->engine->device->height;
		$scale = min(1, $width / 720, $height / 540);

		if($this->enabled)
		{
			$this->rows = $this->read($game, $casts);
			$fullHeight = (count($this->rows) + 1) * ($this->font->lineHeight + 2) + 32;
			$scale = min($scale, $height / $fullHeight);
		}

		$pad = 8 * $scale;
		$batch = $boxes = $this->nextCache = [];

		if($this->enabled)
		{
			$panelWidth = 304 * $scale;
			$line = ($this->font->lineHeight + 2) * $scale;
			$x = $width - $panelWidth - $pad;
			$y = $pad;
			$panel = [$x, $y, $panelWidth, (count($this->rows) + 1) * $line + 2 * $pad];
			$boxes[] = array_merge($panel, [.025, .035, .065, .84]);
			$this->snapshot['panel'] = $panel;
			$this->snapshot['rows'] = $this->rows;

			$batch = $this->text(
				'DEBUG  F9'
				, $x + $pad
				, $y + $pad
				, $scale
				, $panelWidth - 2 * $pad
				, 'left'
				, [1, .85, .25, 1]
			);

			foreach($this->rows as $label => $value)
			{
				$y += $line;

				array_push(
					$batch
					, ...$this->text($label, $x + $pad, $y + $pad, $scale, 132 * $scale, 'left', [.7, .8, .9, 1])
				);

				array_push(
					$batch
					, ...$this->text(
						$value
						, $x + 148 * $scale
						, $y + $pad
						, $scale
						, $panelWidth - 148 * $scale - $pad
						, 'right'
						, [1, 1, 1, 1]
					)
				);
			}
		}

		if($this->showFps)
		{
			$text = $this->fps === null
				? 'FPS -- / -- MS'
				: sprintf('FPS %.0f / %.2f MS', $this->fps, $this->frameMilliseconds);

			$y = $height - ($this->font->lineHeight * $scale + 3 * $pad);
			$meterWidth = 224 * $scale;

			array_push(
				$batch
				, ...$this->text($text, 2 * $pad, $y + $pad, $scale, $meterWidth - 2 * $pad, 'left', [1, 1, 1, 1])
			);
		}

		$this->atlas ??= new Atlas($this->font->map);
		$this->sprites ??= new SpriteRenderer($this->atlas);
		$this->rects ??= new RectRenderer;
		$this->rects->render($boxes, $width, $height);
		$this->sprites->render($batch, new Camera(0, 0, $width, $height));
		$this->snapshot['draws'] = (int)(count($boxes) > 0) + $this->sprites->draws;
		$this->cache = $this->nextCache;
	}

	/**
	 * Cache one aligned glyph run, fitting long values within their column.
	 *
	 * @param string $text Label or formatted value to display.
	 * @param float $x Left edge of the alignment box in output pixels.
	 * @param float $y Top edge of the line in output pixels.
	 * @param float $scale Maximum font scale independent of world camera zoom.
	 * @param float $width Available width in output pixels.
	 * @param string $align Left or right alignment inside the box.
	 * @param array{float,float,float,float} $color Glyph tint in RGBA order.
	 * @return list<\Phonics\Render\Sprite> Cached sprites owned by this overlay.
	 */
	private function text(
		string $text
		, float $x
		, float $y
		, float $scale
		, float $width
		, string $align
		, array $color
	): array {
		$key = serialize([$text, $x, $y, $scale, $width, $align, $color]);
		$glyphs = $this->cache[$key] ?? null;

		if($glyphs === null)
		{
			$scale = min($scale, $width / max(1, $this->font->measure($text)['width']));
			$glyphs = $this->font->layout($text, $scale, INF, $color, $align, $width)['sprites'];

			foreach($glyphs as $sprite)
			{
				$sprite->x += $x;
				$sprite->y += $y;
			}
		}

		$this->nextCache[$key] = $glyphs;

		return $glyphs;
	}

	/**
	 * Rebuild graphics after the owner has restored the borrowed menu atlas.
	 *
	 * @return void Retains overlay settings and avoids counting suspended time in FPS.
	 */
	public function restore(): void
	{
		if($this->closed)
		{
			return;
		}

		if($this->ownsAtlas)
		{
			$this->atlas?->restore();
		}

		$this->sprites?->restore();
		$this->rects?->restore();
		$this->cache = [];
	}

	/**
	 * Release overlay batches without releasing an atlas borrowed from the pause menu.
	 *
	 * @return void Disposes once; safe before the first draw or during lost-context cleanup.
	 */
	public function close(): void
	{
		if($this->closed)
		{
			return;
		}

		$this->closed = true;
		$this->sprites?->close();
		$this->rects?->close();

		if($this->ownsAtlas)
		{
			$this->atlas?->close();
		}

		$this->cache = $this->nextCache = $this->rows = [];
		$this->terrain = null;
	}
}
