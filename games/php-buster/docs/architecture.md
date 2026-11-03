# Architecture

## Fixed simulation

`Simulation` owns all result-changing state: player movement, seeded wave construction, enemy behavior, firing cooldowns, collision phases, drops, scoring, hit grace, stage transitions, and transient effect events. The original 45 ms timing values are expressed in seconds and sampled by a 60 Hz fixed step. `Actor` retains current and previous poses so rendering can interpolate without changing gameplay.

The port deliberately excludes replay and network code. Local runs use a portable Park–Miller generator, while each new run receives a fresh browser seed. Native PHP tests cover deterministic random values, movement normalization, pointer clamping, fire cadence, wave timing, collision grace, stage completion, safe accuracy calculation, bindings, and logical code ranges.

## Rendering and browser boundary

`Render\Scene` draws the scrolling starfield, centered SpriteLib cells, actors, HUD, intermission text, and shared menus. It contains the original 320×480 canvas inside any output size and uses the same transform for input. Actor poses interpolate between fixed steps.

The browser entry owns resize observation, mouse and touch pointers, accessible menu buttons, lazy sound decode and playback, safe local storage, mute/fullscreen controls, and cleanup. Menu DOM only changes when the region signature changes. Closing the package aborts listeners, disconnects observers, closes audio, restores the canvas, clears bridge functions, and releases PHP/GPU state.

## Reusable engine work

Two general mechanisms were promoted to Phonics during the port:

- `Phonics\Render\LogicalViewport` provides aspect-preserving containment, point containment, coordinate conversion, and renderer-ready rectangles for fixed logical canvases.
- `SamplePlayer` provides lazy, cached, failure-tolerant playback of package-local effects through Web Audio.

PHP Slalom now uses both utilities as well. Buster keeps its wave rules, actors, scoring, package assets, and browser profile in `packages/php-buster`.
