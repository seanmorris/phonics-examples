# PHPTris

PHPTris is an independent Phonics puzzle game with three solo modes, five challenge tracks, and original graphics and synthesized sounds. Play at `/games/php-tris/`, or run `dist/game.phar` through `Phonics.run`. It depends only on Phonics.

## Play

Start Game begins the selected mode. Choose Mode offers Endless, 40 Lines, Time Attack, Squares, Cascade, Target Rows, Color Bonds, and Reactor. How to Play explains controls. PHP Info displays the running PHP environment over the unchanged title; use the large top-right back hint to close it with a mouse or the indicated controller button.

| Action | Keyboard | Controller |
| --- | --- | --- |
| Move | Left / Right | D-pad or left stick Left / Right |
| Soft drop | Down | D-pad or left stick Down |
| Rotate clockwise | Up / X | A |
| Rotate counterclockwise | Z / Ctrl | B |
| Hard drop | Space | D-pad Up / X |
| Hold | C / Shift | Y |
| Pause | P / Escape | Start |

Touch devices display buttons for every gameplay action. Multiple fingers can move and rotate together. Menus work with mouse, touch, keyboard, and controller. Pause provides Resume, Restart, and Back to Title. Muting and fullscreen use Phonics' shared corner controls.

The board has ten columns, twenty visible rows, and twenty hidden spawning rows. Six upcoming pieces and one held piece are displayed. Each seven-piece bag contains every ordinary shape once. Hold is allowed once per placement. The faint landing preview shows the hard-drop destination.

Successful movement or rotation resets the half-second locking timer without a reset limit. Holding rotation repeats after 167 ms, then every 100 ms. Horizontal movement repeats after 167 ms, then every 33 ms. Failed movement does not reset locking. Hard drop and hold require fresh presses. Losing focus clears held input.

## Solo modes and scores

Endless increases the level every ten lines. 40 Lines records the time to clear forty lines. Time Attack lasts two minutes; an already-locked piece's chain finishes before results. Menus, pauses, and rank transitions stop gameplay clocks. Challenge records prioritize rank, then score. Records are local to this browser, world, and mode; unavailable storage falls back to memory.

Ordinary clears score 100, 300, 500, and 800 points. Full spins score 400, 800, 1,200, and 1,600; mini spins score 100, 200, and 400. Apply the current level multiplier. Consecutive difficult clears earn a 1.5 multiplier. Consecutive scoring placements add 50 points per combo step, and later cascade waves add 100 per wave step, before the level multiplier. Clearing the whole board adds 2,000 times the level. Soft/hard drop add one/two points per cell.

## Challenge tracks

Each track has fifteen ranks. Objective counters are separate from score: drop points never advance objectives. Rank changes have a visible transition. Squares, Cascade, and Target Rows preserve the board and surplus goal units; Color Bonds and Reactor start a fresh layout.

| Track | Objective |
| --- | --- |
| Squares | Build a 4×4 square from four intact pieces. Same-shape squares award ten additional goal units per cleared square row; mixed squares award five. A qualifying spin clear makes cells fall individually and removes remaining square bonuses. Earn ten times the rank in goal units. |
| Cascade | Cleared rows release unsupported fragments, which fall as connected pieces and can trigger further clears. Earn five times the rank in units. Each wave awards `2 × (lines + wave) − 3`, starting at wave one. |
| Target Rows | Rows 5, 10, 14, 17, 19, and 20 from the bottom award one through six units. Other rows still clear. Ten percent of pieces have separate color fragments. Earn five times the rank. |
| Color Bonds | Matching neighboring colors join. Groups of at least 25 disappear, and unsupported groups fall. Twenty percent of pieces contain two colors. Clear the tagged bottom garbage layer. Neutral garbage cannot disappear as a colored group. |
| Reactor | Seven ordinary pieces and three single-cell charges form each shuffled bag. Charges touching the anchored core join it. Charges and core cells survive row clears. Connect twice the rank in charges. |

Ordinary goal-unit clears award 1, 3, 5, and 8; spin clears award 3, 7, and 6. Original garbage layouts leave an alternating access shaft. All 78 maps are committed Tiled resources, generated reproducibly without reading reference archives.

## Author a world

Install `@seanmorris/php-tris` with `@seanmorris/phonics`, or use Composer package `seanmorris/php-tris`. PHP classes live in `PhpTris\`. Before registry publication, install the built npm tarballs or Composer archives; the package names describe the intended releases.

Run `npm install` at the repository root, then open `worlds/arcade.tiled-project` in Tiled 1.12 or later before opening the world. Enable the project extension when Tiled asks. The project provides the component classes and a relative extension path. The supplied world uses:

```text
phonics.import.php-tris = ../phonics.json
phonics.start = endless-01.tmj
```

Map properties declare `mode`, `name`, `rank`, `seed`, `lineTarget`, `duration`, and `ghost`. Each map is a finite 10×20 grid. Paint its **Initial cells** layer with the supplied tileset: seven colors, neutral garbage, goal garbage, core, and charge. Include one rank for each solo mode you offer and ranks 1–15 for each challenge track you offer. The starting map selects the initial mode and rank; its seed makes restarts reproducible. The game derives its selector from the world's maps. Duplicate ranks and missing stages produce source-specific loading errors.

Each board object has a nested `Components` preset containing `Board`, `Active`, `Queue`, `Input`, and `Run`. The map remains the single source for mode and seed; queue and run components read those settings when the map activates. Its native `phonics.systems` list selects `@php-tris/stage`, while the world lists the component and system modules. Only the selected run owns live components and systems; restarting or changing modes releases them. Challenge rank progression reuses that run and reads the next map as stage data. The legacy `#board` factory and standalone `#rules` module remain available to PHP consumers. `#arcade` exports the example world. Board occupancy uses a bounded grid; piece IDs and color identity survive movement and line clearing. Rendering and input bridges are separate from simulation. No platformer or brick-breaker package is loaded.

The display renders through SDL/WebGL at the screen's physical pixel dimensions. Bitmap glyph scales are integers; portrait phones rearrange the same HUD around a taller board. Transparent accessible buttons provide hit targets over SDL text. Board geometry and text layouts are cached until their content changes.

After editing your own package, run `phonics integrity`, `phonics validate --world worlds/arcade.world`, and `phonics build`. See the Phonics CLI documentation for packaging and preview commands.
