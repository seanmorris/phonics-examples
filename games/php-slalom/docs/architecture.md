# Architecture

## Simulation

`Simulation` owns all state that changes the result: score, stage, steering velocity, course objects, collision, and crash timing. `Random` reproduces the original wrapped 32-bit generator. `Stage` holds each generator's mutable road state, and `Obstacle` retains current and prior fixed poses for interpolation. None of these classes reads the DOM, WebGL, storage, or wall-clock time.

`Replay` packs four input ticks into each byte and validates its complete payload before exposing input. Native PHP tests cover generator values, steering, neutral drag, opposing controls, stage transitions, color blending, collision timing, frozen crash state, replay round trips, corruption rejection, and default input families.

## Rendering and browser boundary

`Render\Scene` projects the course at the original 320×200 coordinate system, then uses `LogicalViewport` to letterbox it into the current physical canvas. It submits ground, gates, and the impact fan through the engine's batched `TriangleRenderer`; craft and text use existing atlas and sprite renderers. Fixed poses interpolate at display refresh rate without changing the simulation.

The browser entry owns resize observation, touch pointers, accessible menu buttons, `SamplePlayer` audio, safe storage, replay import/export, mute/fullscreen controls, and cleanup. It only mutates menu hit targets when their region signature changes. Closing the package aborts listeners, disconnects observers, closes audio, restores the canvas, clears bridge functions, and releases PHP/GPU state.

## Reusable engine work

The port and subsequent Buster work promoted five pieces that apply beyond one game:

- `Phonics\Render\TriangleRenderer` batches solid screen-space triangles for simple projected geometry and effects.
- `Phonics\Ui\BindingMenu` provides device-specific binding pages, neutral capture, conflict choices, defaults, and glyph-aware controller values.
- `SafeStorage` wraps browser storage with in-memory fallback and is shared by all example games.
- `Phonics\Render\LogicalViewport` keeps fixed logical canvases and pointer conversion on one aspect-preserving transform.
- `SamplePlayer` lazily decodes, caches, and plays package-local effects without coupling PHP to Web Audio.

The game keeps its course rules, checkpoint policy, replay schema, presentation, and browser profile in `packages/php-slalom`. This preserves package independence while moving generally useful mechanisms into Phonics.
