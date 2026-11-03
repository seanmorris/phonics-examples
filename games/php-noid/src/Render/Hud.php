<?php
declare(strict_types=1);
namespace PhpNoid\Render;

use Phonics\Render\Atlas;
use Phonics\Render\BitmapFont;
use Phonics\Render\Camera;
use Phonics\Render\RectRenderer;
use Phonics\Render\Sprite;
use Phonics\Render\SpriteRenderer;
use PhpNoid\Component\Paddle;
use PhpNoid\Playfield;

/** Pixel HUD rendered in the SDL/WebGL framebuffer beside the 640x480 playfield. */
final class Hud
{
	public const WIDTH = 230;
	public const PLAYFIELD_LEFT = 8;
	public const PLAYFIELD_WIDTH = 624;
	public const HEIGHT = 480;
	public const SCREEN_WIDTH = self::WIDTH + self::PLAYFIELD_WIDTH;

	private Atlas $atlas;
	private SpriteRenderer $sprites;
	private RectRenderer $rectangles;
	private Camera $camera;
	private Camera $screenCamera;
	private int $backgroundGid;
	/** @var array<string,mixed> Last visible layout, exposed for browser acceptance probes. */
	private array $snapshot = [];

	/**
	 * Build one atlas containing the block font and 230x480 metallic rail.
	 *
	 * @param BitmapFont $font Bitmap font and map containing the HUD rail tileset.
	 * @return void Initializes GPU renderers and cameras for the HUD.
	 */
	public function __construct(private BitmapFont $font)
	{
		$this->atlas = new Atlas($font->map);
		$this->sprites = new SpriteRenderer($this->atlas);
		$this->rectangles = new RectRenderer;
		$this->camera = new Camera(0, 0, self::WIDTH, self::HEIGHT);
		$this->screenCamera = new Camera(0, 0, self::SCREEN_WIDTH, self::HEIGHT);
		$this->backgroundGid = (int)$font->map->tilesets[1]['firstgid'];
	}

	/**
	 * Draw the decorative rail, aligned game/stage names, instruments, and stacked powers.
	 *
	 * @param Playfield $field Current campaign, registry, capsule, and player state.
	 * @param bool $paused Whether the pause prompt replaces the gameplay prompt.
	 * @param int $bestScore Persistent best score to compare with the current score.
	 * @return void Submits the HUD sprite and rectangle batches.
	 */
	public function render(Playfield $field, bool $paused, int $bestScore = 0): void
	{
		$background = new Sprite($this->backgroundGid, 0, 0, 0, 0);
		$background->animated = $background->outline = $background->smooth = false;
		$this->sprites->render([$background], $this->camera);

		$panels = [];

		foreach([[8,70,214,1],[8,164,214,1],[8,226,214,1],[8,415,214,1]] as [$x,$y,$w,$h])
		$panels[] = self::rectangle($x, $y, $w, $h, [.35, .46, .49, 1]);

		$text = [];
		$this->text($text, 'PHP/NOID', 14, 11, 3, [0.89, .94, 1, 1]);
		$this->text($text, strtoupper($field->state->name), 12, 44, 2, [.72, .91, 1, 1], 206);
		$this->text($text, 'SCORE', 14, 77, 2, [1, .78, .42, 1]);
		$this->text($text, sprintf('%06d', $field->state->score), 14, 96, 4);
		$this->text($text, 'BEST', 14, 138, 2, [.56, .67, .75, 1]);
		$this->text($text, sprintf('%06d', max($bestScore, $field->state->score)), 78, 138, 2, [.56, .67, .75, 1]);

		$this->text(
			$text
			, sprintf('LEVEL %02d / %02d', $field->state->stage + 1, $field->state->stageCount)
			, 14
			, 172
			, 2
			, [.72, .91, 1, 1]
		);

		$this->text(
			$text
			, 'PADDLES X ' . ($field->state->infiniteLives ? 'INF' : $field->state->lives)
			, 14
			, 196
			, 2
			, [1, .78, .42, 1]
		);

		for($i = 0; $i < min(6, max(0, $field->state->lives)); $i++)
		{
			$panels[] = self::rectangle(147 + ($i % 3) * 19, 198 + intdiv($i, 3) * 8, 13, 4, [.72, .91, 1, 1]);
			$panels[] = self::rectangle(145 + ($i % 3) * 19, 199 + intdiv($i, 3) * 8, 2, 2, [1, .3, .45, 1]);
			$panels[] = self::rectangle(160 + ($i % 3) * 19, 199 + intdiv($i, 3) * 8, 2, 2, [1, .3, .45, 1]);
		}

		$registry = $field->registry();
		$paddle = isset($field->paddle) ? $registry->get($field->paddle, Paddle::class) : null;
		$powers = $paddle?->powers ?? [];
		$flashes = $paddle?->powerFlashes ?? [];
		$heading = 'POWER UPS';
		$headingColor = [1, .78, .42, 1];

		// Instant pickups have no equipment row: briefly show their name in the heading.
		foreach($flashes as $kind => $remaining)
		{
			if($field->capsules[$kind]['duration'] > 0) continue;

			$heading = strtoupper($field->capsules[$kind]['label']);
			$headingColor = self::pickupColor($field->capsules[$kind]['color'], $remaining);
		}

		$this->text($text, $heading, 14, 234, 2, $headingColor, 200);
		$row = 0;

		foreach($powers as $kind => $seconds)
		{
			if($row >= 5 || $seconds <= 0 || !isset($field->capsules[$kind])) continue;

			$power = $field->capsules[$kind];
			$y = 255 + $row * 29;
			$flash = $flashes[$kind] ?? 0;
			$color = self::pickupColor($power['color'], $flash);
			Scene::balloon($panels, 14, $y, 18, 17, $color, true);
			$panels[] = self::rectangle(14, $y + 21, 200, 3, [.12, .18, .22, 1]);

			$panels[] = self::rectangle(
				14
				, $y + 21
				, 200 * min(1, $seconds / max(1, (float)$power['duration']))
				, 3
				, $color
			);

			$this->text($text, (string)$power['symbol'], 17, $y + 1, 2, [.03, .07, .11, 1]);
			$this->text($text, strtoupper((string)$power['label']), 38, $y, 2, $flash > 0 ? $color : [1, 1, 1, 1], 164);
			$row++;
		}

		if($row === 0)
		$this->text($text, 'STANDARD', 14, 256, 2, [.56, .67, .75, 1]);

		$lines = $paused
			? ['PAUSED', 'RIGHT CLICK / P']
			: match($field->state->phase)
			{
				'gameover' => ['GAME OVER', 'FIRE TO CONTINUE']
				, 'complete' => ['CAMPAIGN CLEAR', 'FIRE TO REPLAY']
				, 'clearing' => ['LEVEL CLEAR']
				, 'loading' => ['LOADING...']
				, 'entering' => ['GET READY']
				, default => ['LAUNCH / FIRE', 'MOVE: ARROWS']
			};

		$this->text($text, implode(PHP_EOL, $lines), 14, 425, 2, [1, 1, 1, 1], 200);

		$this->rectangles->render($panels, self::WIDTH, self::HEIGHT);
		$this->sprites->render($text, $this->camera);
		$this->snapshot = [
			'width' => self::WIDTH
			, 'height' => self::HEIGHT
			, 'game' => 'PHP/NOID'
			, 'logoBounds' => [14, 158]
			, 'stage' => $field->state->name
			, 'powers' => $row
			, 'glyphs' => count($text)
			, 'minimumScale' => 2
			, 'integerScale' => true
			, 'promptLines' => $lines
		];
	}

	/**
	 * Draw the same title beneath both its accessible hit targets and the PHP-info modal.
	 *
	 * @param string $selected Selected title-menu action.
	 * @param int $width Render-target width in pixels.
	 * @param int $height Render-target height in pixels.
	 * @param float $pixelRatio Browser device pixel ratio used for the glyph floor.
	 * @param float $displayWidth CSS display width used by diagnostics.
	 * @return void Submits the title background, menu, and help labels.
	 */
	public function renderTitle(
		string $selected
		, int $width = self::SCREEN_WIDTH
		, int $height = self::HEIGHT
		, float $pixelRatio = 1
		, float $displayWidth = self::SCREEN_WIDTH
	): void {

		/**
		 * Scale a reference-space horizontal coordinate to the current title width.
		 * @param float $value Reference-space X coordinate.
		 * @return int Scaled pixel X coordinate.
		 */
		$x = static fn(float $value): int => (int)round($value * $width / self::SCREEN_WIDTH);

		/**
		 * Scale a reference-space vertical coordinate to the current title height.
		 * @param float $value Reference-space Y coordinate.
		 * @return int Scaled pixel Y coordinate.
		 */
		$y = static fn(float $value): int => (int)round($value * $height / self::HEIGHT);
		$rectangles = [
			self::rectangle(0, 0, $width, $height, [.015, .03, .06, 1])
			, self::rectangle($x(7), $y(7), $width - 2 * $x(7), 2, [.25, .35, .4, 1])
			, self::rectangle($x(7), $y(471), $width - 2 * $x(7), 2, [.07, .12, .16, 1])
			, self::rectangle($x(7), $y(7), 2, $height - 2 * $y(7), [.25, .35, .4, 1])
			, self::rectangle($x(845), $y(7), 2, $height - 2 * $y(7), [.07, .12, .16, 1])
		];

		$text = [];
		$labels = [];
		// Scale with the title viewport, then snap to physical pixels. The small-screen
		// floor preserves legibility without pinning fullscreen text to a fixed CSS size.
		$scale = max((int)ceil(10 * $pixelRatio / 7), $x(2));

		/**
		 * Append a centered title label and retain its measured diagnostic bounds.
		 * @param string $value Label text.
		 * @param int $top Top pixel coordinate.
		 * @param int $size Integer bitmap scale.
		 * @param array $color RGBA text color.
		 * @return void Appends glyphs and records the label position.
		 */
		$label = function(string $value, int $top, int $size, array $color) use (&$text, &$labels, $width): void {
			$left = (int)floor(($width - $this->font->measure($value, $size)['width']) / 2);
			$this->text($text, $value, $left, $top, $size, $color);
			$labels[$value] = ['x' => $left, 'y' => $top, 'scale' => $size];
		};

		$label('PHP/NOID', $y(90), max(1, $x(6)), [.89, .94, 1, 1]);
		$label('BREAK THE RUNTIME', $y(174), $scale, [.41, .85, .77, 1]);

		foreach(['start' => 'START GAME', 'php-info' => 'PHP INFO'] as $key => $value)
		{
			$top = $y($key === 'start' ? 244 : 300);
			$active = $selected === $key;
			$rectangles[] = self::rectangle($x(287), $top, $x(280), $y(40), [.25, .35, .4, 1]);

			$rectangles[] = self::rectangle(
				$x(287) + 2
				, $top + 2
				, $x(280) - 4
				, $y(40) - 4
				, $active ? [1, .78, .42, 1] : [.02, .04, .07, 1]
			);

			$label(
				$value
				, $top + (int)floor(($y(40) - 7 * $scale) / 2)
				, $scale
				, $active ? [.03, .07, .12, 1] : [.89, .94, 1, 1]
			);
		}

		$label('UP DOWN SELECT', $y(366), $scale, [.56, .67, .75, 1]);
		$label('ENTER / START', $y(404), $scale, [.56, .67, .75, 1]);
		$this->rectangles->render($rectangles, $width, $height);
		$this->sprites->render($text, new Camera(0, 0, $width, $height));
		$this->snapshot = [
			'mode' => 'title'
			, 'width' => $width
			, 'height' => $height
			, 'selection' => $selected
			, 'textScale' => $scale
			, 'displayWidth' => $displayWidth
			, 'pixelRatio' => $pixelRatio
			, 'integerScale' => true
			, 'labels' => $labels
		];
	}

	/**
	 * Return compact render evidence without exposing mutable sprite batches.
	 * @return array Latest HUD or title layout snapshot.
	 */
	public function snapshot(): array { return $this->snapshot; }

	/**
	 * Release the HUD's shaders, buffers, and private atlas.
	 * @return void Releases owned GPU resources.
	 */
	public function close(): void
	{
		$this->sprites->close();
		$this->rectangles->close();
		$this->atlas->close();
	}

	/**
	 * Recreate HUD resources after restoration of the shared WebGL context.
	 * @return void Rebuilds all owned GPU resources.
	 */
	public function restore(): void
	{
		$this->atlas->restore();
		$this->sprites->restore();
		$this->rectangles->restore();
	}

	/**
	 * Append one positioned font layout to the frame batch.
	 * @param array $sprites Destination sprite batch.
	 * @param string $value Text to lay out.
	 * @param float $x Left pixel coordinate.
	 * @param float $y Top pixel coordinate.
	 * @param int $scale Integer bitmap scale.
	 * @param array $tint RGBA glyph tint.
	 * @param float $maxWidth Optional wrapping width.
	 * @return void Appends positioned glyph sprites.
	 */
	private function text(
		array &$sprites
		, string $value
		, float $x
		, float $y
		, int $scale
		, array $tint = [1, 1, 1, 1]
		, float $maxWidth = INF
	): void {
		if($scale < 1)
		throw new \LogicException('PHP-Noid font scales must preserve whole source pixels');

		$layout = $this->font->layout($value, $scale, $maxWidth, $tint);

		foreach($layout['sprites'] as $sprite)
		{
			$sprite->x += $x;
			$sprite->y += $y;
			$sprites[] = $sprite;
		}
	}

	/**
	 * Append horizontally centered text using the same integer-scaled bitmap metrics.
	 * @param array $sprites Destination sprite batch.
	 * @param string $value Text to center.
	 * @param float $y Top pixel coordinate.
	 * @param int $scale Integer bitmap scale.
	 * @param array $tint RGBA glyph tint.
	 * @return void Appends centered glyph sprites.
	 */
	private function center(array &$sprites, string $value, float $y, int $scale, array $tint): void
	{
		$size = $this->font->measure($value, $scale);
		$this->text($sprites, $value, (self::SCREEN_WIDTH - $size['width']) / 2, $y, $scale, $tint);
	}

	/**
	 * Convert rectangle and color fields to the renderer's eight-float record.
	 * @param float $x Left edge.
	 * @param float $y Top edge.
	 * @param float $width Rectangle width.
	 * @param float $height Rectangle height.
	 * @param array $color RGBA channels.
	 * @return array Rectangle batch record.
	 */
	private static function rectangle(float $x, float $y, float $width, float $height, array $color): array
	{
		return [$x, $y, $width, $height, ...$color];
	}

	/**
	 * Pulse between the capsule color and white twice during the pickup's short HUD flash.
	 * @param array $color Base RGB channels.
	 * @param float $remaining Remaining flash duration in seconds.
	 * @return array Pulsed RGBA channels.
	 */
	private static function pickupColor(array $color, float $remaining): array
	{
		$light = $remaining > 0 ? .5 + .5 * cos((.8 - $remaining) * 5 * M_PI) : 0;

		return [
			$color[0] + (1 - $color[0]) * $light
			, $color[1] + (1 - $color[1]) * $light
			, $color[2] + (1 - $color[2]) * $light
			, 1
		];
	}
}
