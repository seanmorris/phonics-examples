# Architecture

## Fixed simulation

`Simulation` owns all result-changing state: player movement, seeded wave construction, enemy behavior, firing cooldowns, collision phases, drops, scoring, hit grace, stage transitions, and transient effect events. The original 45 ms timing values are expressed in seconds and sampled by a 60 Hz fixed step. `Actor` retains current and previous poses so rendering can interpolate without changing gameplay.

The port deliberately excludes replay and network code. Local runs use a portable Park–Miller generator, while each new run receives a fresh browser seed. Native PHP tests cover deterministic random values, movement normalization, pointer clamping, fire cadence, wave timing, collision grace, stage completion, safe accuracy calculation, bindings, and logical code ranges.

## Rendering and browser boundary

`Render\Scene` draws the scrolling starfield, centered SpriteLib cells, actors, HUD, intermission text, and shared menus. It contains the original 320×480 canvas inside any output size and uses the same transform for input. Actor poses interpolate between fixed steps.

The browser entry builds on the engine's shell services: `GameShell` claims the context, mounts the frame, measures the screen into the viewport string with the coarse-pointer flag, runs the fullscreen machine, persists the mute in the game's options record and holds the status, action and bridge functions; `RegionButtons` mirrors the menu hit regions and only touches the DOM when the region signature changes; `TouchPad` provides the FIRE button. The entry keeps the paddle's mouse and touch pointer handling, lazy sound decode and playback, the score table and the status summary. Closing the package closes the shell, which aborts listeners, disconnects observers, leaves fullscreen, restores the canvas, clears bridge functions, and lets the game release audio and PHP/GPU state.

## Reusable engine work

General mechanisms promoted to Phonics during the port and the later primitives pass:

- `Phonics\Render\LogicalViewport` provides aspect-preserving containment, point containment, coordinate conversion, and renderer-ready rectangles for fixed logical canvases.
- `SamplePlayer` provides lazy, cached, failure-tolerant playback of package-local effects through Web Audio.
- `GameShell`, `RegionButtons` and `TouchPad` hold the browser host chores Buster, Slalom and Tris had each written: context, frame, viewport, fullscreen, mute, bridges, accessible hit regions and held touch buttons.

PHP Slalom now uses both utilities as well. Buster keeps its wave rules, actors, scoring, package assets, and browser profile in `packages/php-buster`.
