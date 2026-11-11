# Architecture and performance

## Executable PHP

| File | Role |
|---|---|
| `game.php` | Creates the engine, title menu, streamed campaign, renderers, HUD, input sequences, audio, and session cleanup. |
| `modules/factories.php` | Registers the Tiled brick, paddle, and enemy factories through `PhpNoid\Module`. |
| `src/Module.php` | Defines the package's component factories and ordered gameplay systems. |

Paddle, ball, capsule, enemy, projectile, and stage behavior register as separate systems. `Playfield` coordinates shared campaign state while components retain the mutable state consumed by those systems. Browser input, loading, SDL audio, rendering, and the HTML host remain outside the simulation rules.

Each playfield owns a persistent `CollisionWorld`. Registry additions and removals update brick and ship shapes immediately. Mutable bounds trigger reindexing only when geometry changes. Balls and projectiles use continuous collision queries so fast movement cannot tunnel through a thin target.

The scene mixes `ImageRenderer` for the current Tiled background with one batched `RectRenderer` pass for gameplay. The HUD uses integer-scaled bitmap text and maintains a hidden accessibility mirror only when visible values change. Context restoration rebuilds both passes.

The browser host mounts its console frame around the canvas and runs the persisted mute, the fullscreen machine and the status, action and best-score bridges through the engine's `GameShell`. Stopping a session closes the shell, which removes the HUD, browser listeners, audio, object URLs and bridge functions, while collision listeners and scoped resources retire with the session. Stage transitions keep the game session alive while retiring only the previous stage scope.

## Tiled composition

The campaign world remains a collection of stages; its editor coordinates do not determine progression. The game selects the next map by identity through `WorldStream::select()`. Each map declares `@php-noid/stage` in `phonics.systems` and uses the `reset` lifecycle. Stage systems and dynamic projectiles, capsules, balls, and enemies belong to that map's scope, so changing stages releases them together while the persistent playfield keeps score and lives.

`worlds/phonics.classes.json` supplies the component defaults exported from the Tiled project. Brick, paddle, and enemy classes bind to registered component constructors. The legacy factory exports remain available to older worlds and tests. Tile entities remain disabled because this game's gameplay objects are authored as objects.
