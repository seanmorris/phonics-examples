<?php
declare(strict_types=1);
namespace PhpSlalom\Render;

use Phonics\Render\Atlas;
use Phonics\Render\BitmapFont;
use Phonics\Render\Camera;
use Phonics\Render\LogicalViewport;
use Phonics\Render\Sprite;
use Phonics\Render\SpriteRenderer;
use Phonics\Render\TriangleRenderer;
use Phonics\Tiled\TileMap;
use Phonics\Ui\Menu;
use Phonics\Ui\MenuRenderer;
use PhpSlalom\Obstacle;
use PhpSlalom\Simulation;

/** Project the original 320 by 200 course through batched triangles, sprites, and bitmap text. */
final class Scene
{
	/** Browser-accessible menu and touch hit regions from the latest frame. */
	public array $regions = [];
	/** Letterboxed game rectangle from the latest frame. */
	public array $viewport = [0,0,320,200];
	private Atlas $craftAtlas;
	private Atlas $fontAtlas;
	private SpriteRenderer $craft;
	private SpriteRenderer $text;
	private TriangleRenderer $triangles;
	private MenuRenderer $menus;
	private array $cache = [];

	/**
	 * Allocate the course, craft, text, and shared menu renderers.
	 *
	 * @param BitmapFont $font Font shared by the HUD and menus.
	 * @param TileMap $craftMap Tileset holding the player craft frames.
	 * @return void Renderers stay owned by the scene until close().
	 */
	public function __construct(private BitmapFont $font, TileMap $craftMap)
	{
		$this->craftAtlas = new Atlas($craftMap);
		$this->fontAtlas = new Atlas($font->map);
		$this->craft = new SpriteRenderer($this->craftAtlas);
		$this->text = new SpriteRenderer($this->fontAtlas);
		$this->triangles = new TriangleRenderer;
		$this->menus = new MenuRenderer($font);
		$this->menus->style['width'] = 620;
		$this->menus->style['panel'] = [.02,.03,.05,.96];
		$this->menus->style['highlight'] = [.09,.25,.35,1];
	}

	/**
	 * Render an interpolated course, HUD, craft or explosion, and an optional menu overlay.
	 *
	 * @param Simulation $game Simulation state to draw.
	 * @param Menu $menu Menu drawn over the course when it is open.
	 * @param string $page title, play, pause, results, help, scores, or replays.
	 * @param int $width Drawable width in pixels.
	 * @param int $height Drawable height in pixels.
	 * @param float $alpha Interpolation between the previous and current tick.
	 * @param float $seconds Elapsed time for menu animation.
	 * @param bool $touch Whether to label the on-screen steering halves.
	 * @return void Hit regions and the viewport are refreshed for the browser.
	 */
	public function render(
		Simulation $game
		, Menu $menu
		, string $page
		, int $width
		, int $height
		, float $alpha
		, float $seconds
		, bool $touch = false
	): void {
		$viewport = LogicalViewport::contain(320, 200, $width, $height);
		$scale = $viewport->scale;
		$left = $viewport->x;
		$top = $viewport->y;
		$this->viewport = $viewport->rectangle();
		$triangles = [];
		[$sin,$cos] = $game->bank($alpha);

		/**
		 * Normalize one 8-bit color channel.
		 *
		 * @param int $channel Channel value from 0 to 255.
		 * @return float Channel value from 0 to 1.
		 */
		$normalize = static fn(int $channel): float => $channel / 255;

		$ground = array_map($normalize, $game->ground());
		$points = [
			$this->project(-100, 2, 28, $sin, $cos, $left, $top, $scale)
			, $this->project(-100, 2, .1, $sin, $cos, $left, $top, $scale)
			, $this->project(100, 2, .1, $sin, $cos, $left, $top, $scale)
			, $this->project(100, 2, 28, $sin, $cos, $left, $top, $scale)
		];

		$this->quad($triangles, $points, [...$ground,1]);

		foreach($game->obstacles as $obstacle)
		$this->obstacle($triangles, $obstacle, $alpha, $sin, $cos, $left, $top, $scale);

		if($game->crashed && !$game->finished)
		$this->explosion($triangles, $game->crashTicks + 1, $left, $top, $scale);

		$this->triangles->render($triangles, $width, $height);

		if(!$game->crashed && $page !== 'title')
		{
			$frame = intdiv($game->ticks, 2) % 2;
			$bob = $game->ticks % 12 > 6 ? 22 : 24;

			if($game->score < 200) $bob = 12 + intdiv($game->score, 20);

			$sprite = new Sprite(1 + $frame, $left + 160 * $scale, $top + (200 - $bob) * $scale, .5, 0);
			$sprite->scaleX = $sprite->scaleY = $scale;
			$sprite->animated = $sprite->outline = $sprite->smooth = false;
			$this->craft->render([$sprite], new Camera(0, 0, $width, $height));
		}

		$glyphs = [];
		$this->label($glyphs, 'SCORE ' . $game->score, $left + 6 * $scale, $top + 5 * $scale, $scale);
		$this->label($glyphs, 'STAGE ' . ($game->stage + 1), $left + 314 * $scale, $top + 5 * $scale, $scale, 'right');

		if($page === 'play' && $touch)
		{
			$this->label($glyphs, 'LEFT', $left + 8 * $scale, $top + 174 * $scale, $scale);
			$this->label($glyphs, 'RIGHT', $left + 312 * $scale, $top + 174 * $scale, $scale, 'right');
		}

		$this->text->render($glyphs, new Camera(0, 0, $width, $height));
		$this->menus->render($menu, $width, $height, $seconds);
		$this->regions = $this->menus->regions;
	}

	/**
	 * Project one world point with the original banking and perspective equations.
	 *
	 * @param float $x Lateral world position.
	 * @param float $y Vertical world position.
	 * @param float $z Depth ahead of the player.
	 * @param float $sin Bank sine.
	 * @param float $cos Bank cosine.
	 * @param float $left Viewport left edge in pixels.
	 * @param float $top Viewport top edge in pixels.
	 * @param float $scale Viewport scale from the 320 by 200 course.
	 * @return array{float,float} Screen-space point.
	 */
	private function project(
		float $x
		, float $y
		, float $z
		, float $sin
		, float $cos
		, float $left
		, float $top
		, float $scale
	): array {
		$perspective = 120 / (1 + .6 * $z);
		$rx = $cos * $x + $sin * ($y - 2);
		$ry = -$sin * $x + $cos * ($y - 2) + 2;

		return [$left + (160 + $rx * $perspective) * $scale,$top + (100 + $ry * $perspective) * $scale];
	}

	/**
	 * Append one tetrahedral obstacle using its interpolated fixed-step pose.
	 *
	 * @param array $triangles Frame triangle batch to extend.
	 * @param Obstacle $obstacle Gate to draw.
	 * @param float $alpha Interpolation between the previous and current tick.
	 * @param float $sin Bank sine.
	 * @param float $cos Bank cosine.
	 * @param float $left Viewport left edge in pixels.
	 * @param float $top Viewport top edge in pixels.
	 * @param float $scale Viewport scale from the 320 by 200 course.
	 * @return void Two shaded faces are appended to the batch.
	 */
	private function obstacle(
		array &$triangles
		, Obstacle $obstacle
		, float $alpha
		, float $sin
		, float $cos
		, float $left
		, float $top
		, float $scale
	): void {
		$x = $obstacle->previousX + ($obstacle->x - $obstacle->previousX) * $alpha;
		$z = $obstacle->previousZ + ($obstacle->z - $obstacle->previousZ) * $alpha;
		$world = [
			[$x - $obstacle->width,2,$z + .5]
			, [$x,-1.4,$z]
			, [$x + $obstacle->width,2,$z + .5]
			, [$x,2,$z - .5]
		];

		/**
		 * Project one obstacle vertex with this frame's bank and viewport.
		 *
		 * @param array{float,float,float} $point World-space vertex.
		 * @return array{float,float} Screen-space vertex.
		 */
		$projectPoint = fn(array $point): array => $this->project(
			$point[0]
			, $point[1]
			, $point[2]
			, $sin
			, $cos
			, $left
			, $top
			, $scale
		);

		$projected = array_map($projectPoint, $world);

		foreach([[[3,0,1], true],[[3,2,1], false]] as [$face,$bright])
		{
			$a = $world[$face[0]];
			$b = $world[$face[1]];
			$c = $world[$face[2]];
			$u = [$b[0] - $a[0],$b[1] - $a[1],$b[2] - $a[2]];
			$v = [$c[0] - $a[0],$c[1] - $a[1],$c[2] - $a[2]];
			$normal = [
				$u[1] * $v[2] - $u[2] * $v[1]
				, $u[2] * $v[0] - $u[0] * $v[2]
				, $u[0] * $v[1] - $u[1] * $v[0]
			];

			$length = sqrt($normal[0] ** 2 + $normal[1] ** 2 + $normal[2] ** 2);
			$shade = abs($normal[2]) / max(1e-9, $length);

			/**
			 * Light one color channel for this face.
			 *
			 * @param float $channel Normalized base channel.
			 * @return float Shaded channel clamped to 1.
			 */
			$light = static fn(float $channel): float => min(1, ($bright ? $channel / .7 : $channel) * $shade);

			/**
			 * Look up one projected face vertex.
			 *
			 * @param int $index Vertex index within the obstacle.
			 * @return array{float,float} Screen-space vertex.
			 */
			$vertex = static fn(int $index): array => $projected[$index];

			$color = array_map($light, $obstacle->color);
			$p = array_map($vertex, $face);
			$triangles[] = [$p[0][0],$p[0][1],$p[1][0],$p[1][1],$p[2][0],$p[2][1],...$color,1];
		}
	}

	/**
	 * Approximate the original expanding oval as a small triangle fan.
	 *
	 * @param array $triangles Frame triangle batch to extend.
	 * @param int $tick Ticks since the crash, which sets the oval's size and color.
	 * @param float $left Viewport left edge in pixels.
	 * @param float $top Viewport top edge in pixels.
	 * @param float $scale Viewport scale from the 320 by 200 course.
	 * @return void Fan triangles are appended to the batch.
	 */
	private function explosion(array &$triangles, int $tick, float $left, float $top, float $scale): void
	{
		$x = $left + 160 * $scale;
		$y = $top + 186 * $scale;
		$rx = $tick * 8 * $scale;
		$ry = $tick * 4 * $scale;
		$color = [1,max(0, (255 - $tick * 12) / 255),max(0, (240 - $tick * 12) / 255),1];

		for($i = 0; $i < 24; $i++)
		{
			$a = 2 * M_PI * $i / 24;
			$b = 2 * M_PI * ($i + 1) / 24;
			$triangles[] = [
				$x,$y
				, $x + cos($a) * $rx
				, $y + sin($a) * $ry
				, $x + cos($b) * $rx
				, $y + sin($b) * $ry
				, ...$color
			];
		}
	}

	/**
	 * Split a screen-space quad into two triangles.
	 *
	 * @param array $triangles Frame triangle batch to extend.
	 * @param array $points Four screen-space corners in winding order.
	 * @param array $color Normalized RGBA color.
	 * @return void Two triangles are appended to the batch.
	 */
	private function quad(array &$triangles, array $points, array $color): void
	{
		$triangles[] = [$points[0][0],$points[0][1],$points[1][0],$points[1][1],$points[2][0],$points[2][1],...$color];
		$triangles[] = [$points[0][0],$points[0][1],$points[2][0],$points[2][1],$points[3][0],$points[3][1],...$color];
	}

	/**
	 * Append a cached bitmap-font label to the frame batch.
	 *
	 * @param array $batch Frame sprite batch to extend.
	 * @param string $text Label text.
	 * @param float $x Anchor x position in pixels.
	 * @param float $y Top y position in pixels.
	 * @param float $scale Glyph scale.
	 * @param string $align left or right, relative to the anchor.
	 * @return void Positioned glyph sprites are appended to the batch.
	 */
	private function label(
		array &$batch
		, string $text
		, float $x
		, float $y
		, float $scale
		, string $align = 'left'
	): void {
		$key = json_encode([$text,$scale,$this->font->theme,$align]);

		if(!isset($this->cache[$key]))
		$this->cache[$key] = $this->font->layout($text, $scale, INF, [1,1,1,1]);

		if($align === 'right') $x -= $this->cache[$key]['width'];

		foreach($this->cache[$key]['sprites'] as $cached)
		{
			$sprite = clone $cached;
			$sprite->x += $x;
			$sprite->y += $y;
			$batch[] = $sprite;
		}
	}

	/**
	 * Recreate every GPU object after a WebGL context restoration.
	 *
	 * @return void Renderers and atlases are rebuilt in dependency order.
	 */
	public function restore(): void
	{
		$this->craftAtlas->restore();
		$this->craft->restore();
		$this->fontAtlas->restore();
		$this->text->restore();
		$this->triangles->restore();
		$this->menus->restore();
	}

	/**
	 * Release all scene-owned GPU objects.
	 *
	 * @return void The scene must not render after closing.
	 */
	public function close(): void
	{
		$this->menus->close();
		$this->triangles->close();
		$this->text->close();
		$this->fontAtlas->close();
		$this->craft->close();
		$this->craftAtlas->close();
	}
}
