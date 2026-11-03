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

Stopping a session removes the HUD, browser listeners, audio, object URLs, collision listeners, and scoped resources. Stage transitions keep the game session alive while retiring only the previous stage scope.
