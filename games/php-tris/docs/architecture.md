# Architecture

## Executable PHP

| File | Role |
|---|---|
| `game.php` | Creates the engine, mode menus, match lifecycle, controls, renderer, and browser-facing session. |
| `modules/components.php` | Registers the five independently authored board components. |
| `modules/stage.php` / `src/StageSystems.php` | Validates the selected board and attaches its ordered systems to the map scope. |
| `modules/rules.php` | Preserves the standalone factory and global-system API for PHP consumers. |
| `src/Module.php` | Constructs match components and exposes the reusable factory and system registration methods. |

The Tiled world is a collection of boards, not a spatial game world. Only the selected run activates a map. Its systems query that map’s entities, so multiple scopes cannot advance one another’s boards. Closing the scope releases both components and system registrations.

Board and session state remain independent of the renderer. Systems advance piece input, gravity, locking, line clearing, challenge evaluation, and UI transitions. Components expose the small pieces of state required by the ECS registry.

The renderer reads an immutable view of the current frame and submits the board, ghost, active piece, preview, hold, and HUD in bounded batches. The browser module builds on the engine's `GameShell` (context, frame, fullscreen, the catalog, status and action bridges) and `RegionButtons` (the menu and hold regions PHP reports; held ids feed the touch bridge), keeps its device-pixel snapping, synthesized sounds and local records, and closes through the shell when the session ends.

This split lets PHP tests exercise rotation, collision, scoring, and challenge rules without a browser. Browser tests then cover bindings, layout, lifecycle cleanup, and packed exports.
