<?php
declare(strict_types=1);
namespace PhpBuster\Render;

use Phonics\Render\Atlas;
use Phonics\Render\BitmapFont;
use Phonics\Render\Camera;
use Phonics\Render\LogicalViewport;
use Phonics\Render\Sprite;
use Phonics\Render\SpriteRenderer;
use Phonics\Tiled\TileMap;
use Phonics\Ui\Menu;
use Phonics\Ui\MenuRenderer;
use PhpBuster\Actor;
use PhpBuster\Simulation;

/** Render the original 320 by 480 playfield, actors, HUD, and shared menu overlay. */
final class Scene
{
	public array $regions = [];
	public array $viewport = [0,0,320,480];
	private Atlas $atlas;
	private Atlas $fontAtlas;
	private SpriteRenderer $sprites;
	private SpriteRenderer $text;
	private MenuRenderer $menus;
	private array $cache = [];

	/**
	 * Prepare sprite, text, and menu renderers for the Buster atlas.
	 *
	 * @param BitmapFont $font Shared bitmap font used by HUD and menus.
	 * @param TileMap $map Buster sprite and starfield atlas description.
	 * @return void Renderer resources are retained until close is called.
	 */
	public function __construct(private BitmapFont $font, TileMap $map)
	{
		$this->atlas = new Atlas($map);
		$this->fontAtlas = new Atlas($font->map);
		$this->sprites = new SpriteRenderer($this->atlas);
		$this->text = new SpriteRenderer($this->fontAtlas);
		$this->menus = new MenuRenderer($font);
		$this->menus->style['width'] = 390;
		$this->menus->style['panel'] = [.015,.02,.06,.94];
		$this->menus->style['highlight'] = [.45,.06,.1,1];
	}

	/**
	 * Render one display frame and expose accessible menu regions.
	 *
	 * @param Simulation $game Current deterministic game state.
	 * @param Menu $menu Shared menu model rendered above the playfield.
	 * @param string $page Current application page identifier.
	 * @param int $width Physical output width in pixels.
	 * @param int $height Physical output height in pixels.
	 * @param float $alpha Fixed-step interpolation fraction.
	 * @param float $seconds Elapsed UI animation time.
	 * @return void Draw calls are submitted to the active device.
	 */
	public function render(
		Simulation $game
		, Menu $menu
		, string $page
		, int $width
		, int $height
		, float $alpha
		, float $seconds
	): void {
		$viewport = LogicalViewport::contain(Simulation::WIDTH, Simulation::HEIGHT, $width, $height);
		$this->viewport = $viewport->rectangle();
		$camera = new Camera(0, 0, $width, $height);
		$sprites = [];
		$scroll = $game->scroll * $viewport->scale;

		foreach([-1,0,1] as $copy)
		{
			$star = new Sprite(101, $viewport->x, $viewport->y + $scroll + $copy * $viewport->height, 0, 0);
			$star->scaleX = $star->scaleY = $viewport->scale;
			$star->animated = $star->outline = $star->smooth = false;
			$sprites[] = $star;
		}

		foreach($game->actors as $actor)
		{
			if($actor === $game->player && $page !== 'play') continue;

			if($actor === $game->player && $game->hitGrace > 0
				&& ((int)floor($game->hitGrace / .06) % 2) === 0
			) continue;

			$sprites[] = $this->actor($actor, $viewport, $alpha);
		}

		$this->sprites->render($sprites, $camera);
		$labels = [];
		$textScale = max(.75, $viewport->scale * .78);

		$this->label(
			$labels
			, 'SCORE ' . $game->score
			, $viewport->x + 5 * $viewport->scale
			, $viewport->y + 5
			, $textScale
		);

		$this->label(
			$labels
			, 'SHIELDS ' . $game->shields
			, $viewport->x + $viewport->width / 2
			, $viewport->y + 5
			, $textScale
			, 'center'
		);

		$this->label(
			$labels
			, $game->level . '-' . $game->stage
			, $viewport->x + $viewport->width - 5 * $viewport->scale
			, $viewport->y + 5
			, $textScale
			, 'right'
		);

		if($page === 'play' && $game->phase === 'intermission')
		{
			$this->label(
				$labels
				, 'LEVEL ' . $game->level . ' STAGE ' . $game->stage . ' COMPLETE'
				, $width / 2
				, $height * .44
				, $textScale
				, 'center'
			);

			$this->label($labels, 'ACCURACY ' . $game->accuracy . '%', $width / 2, $height * .49, $textScale, 'center');
			$this->label($labels, 'BONUS ' . $game->bonus, $width / 2, $height * .54, $textScale, 'center');
		}

		if($page === 'play' && $game->phase === 'gameover')
		$this->label($labels, 'GAME OVER', $width / 2, $height / 2, $textScale * 1.35, 'center');

		$this->text->render($labels, $camera);
		$this->menus->render($menu, $width, $height, $seconds);
		$this->regions = $this->menus->regions;
	}

	/**
	 * Convert one simulation actor into an interpolated atlas sprite.
	 *
	 * @param Actor $actor Actor to display.
	 * @param LogicalViewport $viewport Logical-to-output transform.
	 * @param float $alpha Fixed-step interpolation fraction.
	 * @return Sprite Configured sprite ready for the actor batch.
	 */
	private function actor(Actor $actor, LogicalViewport $viewport, float $alpha): Sprite
	{
		$gid = match($actor->type)
		{
			'bomb' => 1
			, 'flare' => 2 + $actor->frame
			, 'maxi' => 6 + $actor->frame
			, 'player' => 9 + $actor->frame
			, 'midi' => 12 + $actor->frame
			, 'mini' => 15 + $actor->frame
			, 'missile' => 18 + $actor->variant * 4 + $actor->frame
			, 'rock' => 34 + $actor->variant
			, 'ruby' => 42
			, 'shield' => 43
			, 'explosion' => 44 + $actor->frame
			, default => 1
		};

		$x = $actor->previousX + ($actor->x - $actor->previousX) * $alpha;
		$y = $actor->previousY + ($actor->y - $actor->previousY) * $alpha;

		$sprite = new Sprite(
			$gid
			, $viewport->x + ($x + $actor->width / 2) * $viewport->scale
			, $viewport->y + ($y + $actor->height / 2) * $viewport->scale
			, .5
			, .5
		);

		$sprite->scaleX = $sprite->scaleY = $viewport->scale;
		$sprite->animated = $sprite->outline = $sprite->smooth = false;

		return $sprite;
	}

	/**
	 * Append a cached bitmap-font label to a sprite batch.
	 *
	 * @param array $batch Destination sprite batch passed by reference.
	 * @param string $text Label contents.
	 * @param float $x Horizontal output position.
	 * @param float $y Vertical output position.
	 * @param float $scale Bitmap-font scale.
	 * @param string $align Horizontal alignment name.
	 * @return void Cloned glyph sprites are appended to the batch.
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
		elseif($align === 'center') $x -= $this->cache[$key]['width'] / 2;

		foreach($this->cache[$key]['sprites'] as $cached)
		{
			$sprite = clone $cached;
			$sprite->x += $x;
			$sprite->y += $y;
			$batch[] = $sprite;
		}
	}

	/**
	 * Restore graphics resources after a WebGL context loss.
	 *
	 * @return void Every owned renderer and atlas is restored.
	 */
	public function restore(): void
	{
		$this->atlas->restore();
		$this->sprites->restore();
		$this->fontAtlas->restore();
		$this->text->restore();
		$this->menus->restore();
	}

	/**
	 * Release graphics and menu resources.
	 *
	 * @return void Owned render resources are closed once.
	 */
	public function close(): void
	{
		$this->menus->close();
		$this->text->close();
		$this->fontAtlas->close();
		$this->sprites->close();
		$this->atlas->close();
	}
}
