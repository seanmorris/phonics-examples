# Architecture

## World-space simulation

`Simulation` owns every result-changing value: the phant's height and velocity, the flap impulse, the scrolling barrels and their seeded gaps, the score, collisions, the day/night palette and the run's phases (ready, playing, dead). The phant sits at world x zero and never moves horizontally; barrels move toward it. Gaps come from a portable Park–Miller `Random`, so a seed reproduces the same course on every runtime, and the course is placed up to a fixed lookahead so a wide screen never sees an empty horizon. The simulation never reads a width: only the logical height of 480 units matters, which is what lets the renderer fill any frame without changing play. `Barrel` keeps the previous pose of each stack so rendering can interpolate between fixed steps.

Native PHP tests cover the generator, the idle bob, the first flap, gravity and the fall clamp, death on the ground and the settle time, seed determinism and gap bounds, scoring and the palette cadence, the speed and gap ramps, collisions against stacks and through gaps, and the bindings.

## Viewport-filling rendering

`Render\Scene` scales the 480-unit world to the output height and derives the visible width from the frame, placing the phant at about 28 % of that width (clamped). It draws the horizon band, parallax clouds, each barrel pair as stacked cap and ring tiles, the scrolling ground and the phant, whose rotation follows its vertical velocity and which tumbles when dead. Day and night blend the device clear colour and a tint over the world. The HUD uses the engine bitmap font through a cached glyph layout, and menus come from the shared `MenuRenderer`, whose hit regions the browser mirrors as accessible buttons.

## Browser boundary

The browser entry builds on the engine's shell services: `GameShell` claims the context, mounts the frame, measures the screen into the viewport string PHP reads every frame, runs the fullscreen machine, persists the mute and holds the status, action and bridge functions; `RegionButtons` mirrors the menu hit regions. Taps and clicks on the canvas are counted and bridged, so a flap is never counted twice through a mouse binding; keyboard and controller flaps go through the engine's editable `Bindings`. Sounds are decoded lazily by `SamplePlayer` after the first gesture and played from the status counters. The best score lives in `SafeStorage` and is reported back through a bridge for the ready screen and the result card.

## Licence boundary

The elePHPant sprite and the poster are rasterised, reduced-palette derivatives of Vincent Pontier's mascot as drawn by Webysther, so they ship under GPL-2.0-or-later beside the source vector (`art/ELEPHPANT-LICENSE.md`). `scripts/generate-phant-sprites.mjs` is the only thing that touches that vector; the barrel, ground and cloud tiles, the sounds and all code are original and Apache-2.0.
