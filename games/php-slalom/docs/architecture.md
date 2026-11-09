# Architecture

## Simulation

`Simulation` owns all state that changes the result: score, stage, steering velocity, course objects, collision, and crash timing. `Random` reproduces the original wrapped 32-bit generator. `Stage` holds each generator's mutable road state, and `Obstacle` retains current and prior fixed poses for interpolation. None of these classes reads the DOM, WebGL, storage, or wall-clock time.

`Replay` packs four input ticks into each byte and validates its complete payload before exposing input. Native PHP tests cover generator values, steering, neutral drag, opposing controls, stage transitions, color blending, collision timing, frozen crash state, replay round trips, corruption rejection, and default input families.

## Rendering and browser boundary

`Render\Scene` projects the course at the original 320×200 coordinate system, then uses `LogicalViewport` to letterbox it into the current physical canvas. It submits ground, gates, and the impact fan through the engine's batched `TriangleRenderer`; craft and text use existing atlas and sprite renderers. Fixed poses interpolate at display refresh rate without changing the simulation.

The browser entry builds on the engine's shell services: `GameShell` claims the context, mounts the frame, measures the screen into the viewport string with the coarse-pointer flag, runs the fullscreen machine, persists the mute in the game's options record and holds the status, action and bridge functions; `RegionButtons` mirrors the menu hit regions and only touches the DOM when the region signature changes; `TouchPad` provides the LEFT and RIGHT steer buttons. The entry keeps `SamplePlayer` audio, the profile and replay import/export, and the status summary. Closing the package closes the shell, which aborts listeners, disconnects observers, leaves fullscreen, restores the canvas, clears bridge functions, and lets the game release audio and PHP/GPU state.

## Reusable engine work

The port, the Buster work and the later primitives pass promoted pieces that apply beyond one game:

- `Phonics\Render\TriangleRenderer` batches solid screen-space triangles for simple projected geometry and effects.
- `Phonics\Ui\BindingMenu` provides device-specific binding pages, neutral capture, conflict choices, defaults, and glyph-aware controller values.
- `SafeStorage` wraps browser storage with in-memory fallback and is shared by all example games.
- `Phonics\Render\LogicalViewport` keeps fixed logical canvases and pointer conversion on one aspect-preserving transform.
- `SamplePlayer` lazily decodes, caches, and plays package-local effects without coupling PHP to Web Audio.
- `GameShell`, `RegionButtons` and `TouchPad` hold the browser host chores the small games shared: context, frame, viewport, fullscreen, mute, bridges, accessible hit regions and held touch buttons.

The game keeps its course rules, checkpoint policy, replay schema, presentation, and browser profile in `packages/php-slalom`. This preserves package independence while moving generally useful mechanisms into Phonics.
