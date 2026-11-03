# PHP Slalom

PHP Slalom recreates Jet Slalom, originally programmed by MR-C, as a self-contained Phonics package. A fixed-step PHP simulation generates the course and collision state. WebGL renders the original low-resolution presentation into a responsive canvas, and the browser module supplies storage, sound, replay files, touch controls, fullscreen, and accessible menu hit targets.

The port keeps the values that determine a run: 55 ms ticks, steering acceleration and drag, the six score stages, seeded obstacle placement, road motion, stage color transitions, and the twenty-tick impact sequence. It replaces Java applet integration and unbounded browser state with an explicit package lifecycle and bounded persistence.

## How to play

Bank the jet left and right past the oncoming obstacles for as long as you can. Every live tick awards twenty points, including the tick that detects the impact that ends the run; the course and score then freeze while the impact animation plays. The first three stages add obstacle density, stages four and five replace scattered gates with a moving road, stage five adds breaks and wider blockers, and stage six continues indefinitely.

A fresh run starts from zero. The checkpoint menu can start at any stage threshold: Stage 2 at 8,000 points, Stage 3 at 12,000, Stage 4 at 25,000, Stage 5 at 40,000, and Stage 6 at 100,000. Checkpoint results, including the zero-score Stage 1 checkpoint, keep their own score table.

Steering is not analog. The simulation samples whether each direction is held once per tick, so a partly tilted stick steers at full strength once it registers; holding both directions keeps the current course velocity, and releasing both applies drag. Touch screens show left and right steering zones over the bottom of the game. **Input settings** edits keyboard/mouse and controller bindings separately, keeps Pause fixed, and stores valid changes locally when the browser allows it.

## Controls

| Action | Xbox | PlayStation | Keyboard and mouse |
| --- | --- | --- | --- |
| Steer left | <span class="controller-glyphs"><img class="controller-glyph" src="../../../art/controllers/xbox-button-ls-left.svg" alt="Xbox left stick left"><span>or</span><img class="controller-glyph" src="../../../art/controllers/xbox-button-left.svg" alt="Xbox D-pad left"></span> | <span class="controller-glyphs"><img class="controller-glyph" src="../../../art/controllers/playstation-button-ls-left.svg" alt="PlayStation left stick left"><span>or</span><img class="controller-glyph" src="../../../art/controllers/playstation-button-left.svg" alt="PlayStation D-pad left"></span> | Left, A, J, or left click |
| Steer right | <span class="controller-glyphs"><img class="controller-glyph" src="../../../art/controllers/xbox-button-ls-right.svg" alt="Xbox left stick right"><span>or</span><img class="controller-glyph" src="../../../art/controllers/xbox-button-right.svg" alt="Xbox D-pad right"></span> | <span class="controller-glyphs"><img class="controller-glyph" src="../../../art/controllers/playstation-button-ls-right.svg" alt="PlayStation left stick right"><span>or</span><img class="controller-glyph" src="../../../art/controllers/playstation-button-right.svg" alt="PlayStation D-pad right"></span> | Right, D, L, or right click |
| Pause | <img class="controller-glyph" src="../../../art/controllers/xbox-button-start.svg" alt="Xbox Start button"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-start.svg" alt="PlayStation Options button"> | Escape or P |

## Replays

Every non-replay run records two input bits per tick. A `.pslr` file holds a magic marker, version, starting checkpoint, seed, starting score, tick count, packed input stream, and CRC-32 checksum; imports reject unknown versions, invalid checkpoints, mismatched starting scores, malformed lengths, corrupt checksums, and files over one MiB. Replays drive the same `Simulation` class as live play without reading bindings or touch state, so playback is deterministic for a package version. The latest local run can be watched or exported, and fresh-run personal bests keep their replay separately from checkpoint results.

## What to study

- [Architecture](architecture.md) explains the deterministic simulation, rendering pipeline, browser boundary, and reusable engine work.

The [PHP API](api/index.html) lists every public type in the package.
