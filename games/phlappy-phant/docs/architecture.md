# Architecture

Phlappy Phant is a small package with one unusual constraint: it has no fixed canvas. The simulation is written so that the size of the frame cannot affect play, and the renderer is written so that any frame is filled with whole pixels.

## Files

| File | Role |
| --- | --- |
| `game.php` | Creates the engine, the pages and their menus, the Konami sequence, and the system that steps the simulation; publishes the status each frame. |
| `src/Simulation.php` | The flight, the course, scoring, collisions, the day flag, and the built-in player's planner. |
| `src/Pillar.php`, `src/Random.php` | One pair of pillars with its previous pose, and the portable Park–Miller generator that places the gaps. |
| `src/Render/Scene.php` | Lays out the frame and draws the world, the HUD, and the menus. |
| `src/Render/TerrainRenderer.php`, `shaders/terrain.*` | Draws all terrain in one instanced pass and blends two palettes per pixel. |
| `src/Input/PhantActions.php`, `src/Input/InputSettings.php` | The flap action with its default bindings, and the saved binding profile. |
| `browser/game.mjs` | The browser host: frame measurement, taps, sounds, storage, fullscreen, and accessible menu buttons. |
| `worlds/phant.tmj`, `assets/*.tsj` | The map that names the five tilesets, and the tilesets themselves. |

## World-space simulation

`Simulation` owns every value that can change a result: the phant's height and vertical speed, the pillars and their gaps, the score, collisions, the day flag, and the phase of the run. It advances in fixed steps of one sixtieth of a second, and the engine's `FixedStep` clock runs at most five steps per displayed frame.

The phant never moves horizontally. It sits at world x zero and the pillars scroll toward it. The simulation knows the height of the world and nothing about its width, which is what lets the renderer show a narrow or a wide slice of the same course without changing play.

| Rule | Value |
| --- | --- |
| World height, ground line | 352 units, with the ground at 288 |
| Sprite and hitbox | 64×52, inset 8 on every side to 48×36 |
| Gravity, flap, fall cap | 1250 units/s², a flap sets −330 units/s, falls cap at 600 units/s |
| Scroll speed | 120 units/s plus 4 per point, capped at 200 |
| Pillar width and spacing | 56 units wide, one pair every 170 units, the first at 420 |
| Gap height | 144 units, 8 less every eight points, never below 112 |
| Gap position | On the 8-unit grid, at least 32 units from the top of the world and from the ground |
| Day flag | Flips every ten points |

A run has three phases. In **ready** the phant bobs in place, the ground scrolls, and the pillars wait for the first flap. In **playing** each step applies a flap if one was pressed, then gravity, moves the pillars, scores any pair whose right edge has passed the sprite's left edge, and checks the hitbox against the ground and the pillars it overlaps. In **dead** the world stops, the phant falls to the ground, and after 0.6 seconds at rest the run is marked finished.

Gap positions come from `Random`, a Park–Miller generator that produces the same sequence on every runtime, including 32-bit WebAssembly. The browser supplies a fresh seed for each run. Pillars are placed up to 1,200 units ahead, enough to fill a very wide frame, and removed once they are 320 units behind. `Pillar` keeps each pair's previous position so the renderer can interpolate between steps, and numbers the pairs so scenery can be chosen without drawing from the seed.

Each step also records what happened as events: flap, score, bonk, and night. `game.php` counts them, and the browser plays sounds from the counters, so a sound never depends on rendering.

## Filling any frame

Pixel art has to be enlarged by whole numbers, so the game picks the largest whole **block** that still shows 320 units of height. The browser host measures its frame in physical pixels, sets the block to the frame height divided by 320 and rounded down, and sizes the drawing buffer to the frame divided by the block. The canvas is then enlarged by CSS with `image-rendering: pixelated` and anchored to the bottom of the frame.

The drawing buffer therefore holds one pixel per world unit, however large the display is. An earlier version drew at full display resolution and let every sprite cover each world unit with a block of identical pixels. That added no detail and kept a processor core busy on large and high-density displays, because the cost of a frame grew with the screen instead of with the scene.

PHP reads the buffer size every frame and resizes the device to match. `Render\Scene` then lays the frame out from that size alone:

- **Vertically**, the bottom of the world sits on the bottom of the frame. A taller frame shows more sky. A frame shorter than 352 units hides part of the top 32, where no gap can be.
- **Horizontally**, the visible width is whatever the frame provides. The phant is placed 28% of the way across, never nearer than 60 units or further than 200 from the left edge, so a wide frame sees further ahead and a narrow one still has room to react.

`Scene` itself works at any whole scale: handed a frame several times taller than 320 units, it enlarges the world to match. Text and menus follow the same scale, so glyphs are never resampled.

## Drawing the world

A frame is drawn back to front:

1. The device is cleared to the current sky color.
2. The backdrop is drawn at twice the size of the other tiles, resting on the ground line. Each band of the parallax mask is a separate strip that drifts at its own fraction of the scroll, and the river is a two-frame tile animation.
3. A cardinal crosses the sky now and then, from right to left. It sets out only in full daylight, changes through its hand-painted dusk and night colors if the light turns while it is airborne, finishes its crossing, and is timed from its own random stream so it never touches the seeded course.
4. The ground is two rows of 32-unit tiles that scroll at full speed.
5. Scenery stands on the ground to the right of each pair. One of six arrangements of tree, shrub, and fence, one of them empty, is chosen from the pair's number.
6. Each pair is built from 8-unit slices: a cap, wall slices that alternate so the dirt pattern continues, and a base that closes the outline into the grass. The hanging pillar is the same stack with every slice flipped.
7. The phant is drawn last, tilted by its vertical speed. After a bonk it switches to its left-facing frames and tips over as it falls.

Steps 2 and 4 to 6 are the terrain, and all of it changes color during the day cycle. The engine's sprite renderer would express a fade as two overlapping sprites, which doubles the work exactly when the backdrop fills the frame. `TerrainRenderer` instead sends every terrain piece as one instance with a destination rectangle and two atlas rectangles, one in the current palette and one in the next. Its fragment shader samples both and mixes them, so a fade costs one pass. [Art and the day cycle](art.md) describes the palettes.

## HUD and menus

The score, the prompts, and the wordmark use the engine's bitmap font. Every label is drawn twice, cream over an ink copy offset by one pixel, so it reads over day and night skies alike. Glyph layouts are cached by text and scale.

Menus are drawn over the world on a near-solid ink plate, so the text's contrast never depends on the sky, cloud, or field behind it: cream on the plate is at least 7:1 and the pale teal of information rows at least 5:1. The front page leaves the rest of the world undimmed under the wordmark; every other page dims it. The selected row sits on a teal band behind a blinking chevron. Information rows are text, not controls: the selection steps over them and they publish no button. They wrap, pack more tightly than choices, and break long words at a hyphen, and the page picks the largest whole scale at which everything fits between the top of the frame and the hint line. `Scene` publishes one rectangle per choice, which the browser mirrors as real buttons so the menus work with a pointer and with assistive technology, and it publishes the text block's bounds so a browser test can check that no page is cropped on a narrow phone. The bindings editor needs value columns and text entry, so it alone uses the engine's `MenuRenderer`, restyled to match.

## Browser boundary

`browser/game.mjs` builds on the engine's shell services. `GameShell` claims the WebGL context, mounts the frame, runs fullscreen and mute, and owns the functions that cross between JavaScript and PHP. `RegionButtons` mirrors the menu rectangles, `SamplePlayer` decodes and plays the three effects, and `SafeStorage` holds the best score and the bindings. The host's own `BackgroundTrack` streams the soundtrack instead of decoding the whole song into memory, and follows the same mute.

| Bridge | Direction | Carries |
| --- | --- | --- |
| `phantViewport` | Browser to PHP | The drawing-buffer size, the pixel ratio, and whether the pointer is coarse. |
| `phantTaps` | Browser to PHP | A running count of taps and clicks on the canvas. |
| `phantSeed` | Browser to PHP | A fresh 31-bit seed for each run. |
| `phantBest`, `phantSubmitScore` | Both | The stored best score and a finished run's score. |
| `phantStorageRead`, `phantStorageWrite` | Both | The saved bindings profile. |
| `phantAction` | Browser to PHP | The menu row activated through its mirrored button. |
| `phantStatus` | PHP to browser | The run's state as JSON, once per frame. |

Taps are counted in the browser and compared in PHP instead of being bound as a mouse button, so one press can never flap twice. Keyboard and controller flaps go through the engine's editable `Bindings`.

Sounds are decoded after the first gesture, as browsers require, and played when a status counter rises. At most two missed events are replayed, so a tab that was stalled does not burst into sound.

The status is also written to the canvas as `data-php-phant`, which is what the browser tests read. Closing the package closes the shell, which removes listeners and observers, leaves fullscreen, clears the bridge functions, and lets PHP release its GPU resources.

## Tests

- `php games/phlappy-phant/tests/run.php` runs 62 native checks: the generator's first values, the idle bob, the flap and its wing beats, gravity and the fall cap, the bonk and the settle time, seed determinism, gap bounds and the tile grid, scoring, the day flag, the speed and gap ramps, collisions, the bindings and their saved profile, and the built-in player.
- `php games/phlappy-phant/tests/fairness.php` flies the built-in player over forty seeded courses. [The perfect player](autopilot.md) explains what it shows.
- `tests/browser/phant.spec.mjs` at the repository root plays in Chromium. One test flaps by key and by click, lets the run end, checks the stored best score, and checks the drawing buffer and layout in a wide and a tall frame. Another enters the Konami code and follows the built-in player into the night. A third opens every text page on a narrow phone and checks that nothing is cropped and that text rows are never selected.
- `node scripts/generate-phant-sprites.mjs --check` fails when a committed asset no longer matches the sheets in `art/`.

## License boundary

The package mixes four licenses, and the files are kept apart so each is easy to honor.

- The elephant is the PHP mascot by Vincent Pontier, traced from Webysther's vector drawing. The source vector, the sprite sheet, the generated frames, and the poster are GPL-2.0-or-later; `art/ELEPHPANT-LICENSE.md` has the text and the credits.
- The terrain is cut from GrafxKid's *Seasonal Platformer Tiles*, released as CC0; `art/TERRAIN-LICENSE.md` has the credit. The dusk and night sheets and the figures in these chapters derive only from it.
- The soundtrack, `assets/funktify.mp3`, is "Funktify" by Mr. Spastic. It is included byte for byte under CC BY-NC-ND 3.0, which allows neither changes nor commercial use; `art/FUNKTIFY-LICENSE.md` has the details.
- The code and the synthesized sound effects are original and Apache-2.0.
