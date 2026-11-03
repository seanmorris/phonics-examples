<?php
declare(strict_types=1);
namespace DoomPhonics\Menu;

use DoomPhonics\Render\GlesMapRenderer;

/** Map-renderer extension point for a game-owned, WAD-styled menu overlay. */
interface MenuOverlay
{

	/** Whether the opaque menu background makes the three-dimensional scene unnecessary. */
	public function coversWorld(): bool;

	public function render(GlesMapRenderer $renderer): void;

	/** Rebuild optional overlay graphics after a WebGL context recovery. */
	public function restore(): void;
}
