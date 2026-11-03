# Architecture

## Executable PHP

| File | Role |
|---|---|
| `game.php` | Creates the engine, mode menus, match lifecycle, controls, renderer, and browser-facing session. |
| `modules/rules.php` | Registers board factories and the ordered movement, gravity, resolution, and progress systems. |
| `src/Module.php` | Constructs match components and exposes the reusable factory and system registration methods. |

Board and session state remain independent of the renderer. Systems advance piece input, gravity, locking, line clearing, challenge evaluation, and UI transitions. Components expose the small pieces of state required by the ECS registry.

The renderer reads an immutable view of the current frame and submits the board, ghost, active piece, preview, hold, and HUD in bounded batches. The browser module owns touch controls and canvas integration, then releases them through the package context when the session closes.

This split lets PHP tests exercise rotation, collision, scoring, and challenge rules without a browser. Browser tests then cover bindings, layout, lifecycle cleanup, and packed exports.
