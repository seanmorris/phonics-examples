# Phlappy Phant

Phlappy Phant is a pixel-art homage to Flappy Bird starring the **elePHPant**, the PHP mascot by Vincent Pontier. Instead of pipes, the phant slips between grass towers, one rising from the ground and one hanging from the sky. A deterministic fixed-step PHP simulation runs the flight, the seeded tower gaps, collisions, scoring and the day/night cycle; the browser module supplies taps, sounds, fullscreen, the local best score and accessible menu hit targets.

The game fills any frame rather than letterboxing a fixed canvas: the world is 352 logical units tall and the visible width follows the frame's aspect, so a portrait phone and a widescreen desktop play the same simulation and only see different amounts of the road ahead.

## How to play

Press Play, then flap to start. Gravity pulls the phant down; each flap gives it the same upward kick, and the towers scroll toward it at a speed that climbs slowly with the score. Passing a pair scores one; touching a tower or the ground ends the run with a bonk. Gaps shrink a step every eight points, and every ten towers the sky warms through dusk into night, then back through dawn. The best score is kept locally in the browser and shown on the ready screen and the result card.

Taps and clicks anywhere on the canvas flap, as do Space, Up, W and the controller's A or RB. **Input settings** edits the keyboard and controller bindings separately, keeps Pause fixed, and stores valid changes locally when the browser allows it.

## Secrets

With no points on the board, the Konami code (Up, Up, Down, Down, Left, Right, Left, Right, B, A; the arrows then B and A on a keyboard) hands the run to a tool-assisted player that never misses a gap yet never taps faster than five times a second. The only tell is a single pixel in the top-left corner that winks lighter every couple of seconds, and the run never counts toward the best score.

## Controls

| Action | Xbox | PlayStation | Keyboard and mouse |
| --- | --- | --- | --- |
| Flap | <span class="controller-glyphs"><img class="controller-glyph" src="../../../art/controllers/xbox-button-a.svg" alt="Xbox A button"><span>or</span><img class="controller-glyph" src="../../../art/controllers/xbox-button-rb.svg" alt="Xbox RB button"></span> | <span class="controller-glyphs"><img class="controller-glyph" src="../../../art/controllers/playstation-button-x.svg" alt="PlayStation Cross button"><span>or</span><img class="controller-glyph" src="../../../art/controllers/playstation-button-r1.svg" alt="PlayStation R1 button"></span> | Space, Up, W, or a tap or click on the canvas |
| Pause | <img class="controller-glyph" src="../../../art/controllers/xbox-button-start.svg" alt="Xbox Start button"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-start.svg" alt="PlayStation Options button"> | Escape or P |

## Credits and licences

The elePHPant is by Vincent Pontier; the sprite is pixel art traced from the vector uploaded to Wikimedia Commons by Webysther, licensed GPL-2.0-or-later, and the sprite sheet, source vector and poster are distributed under that licence (`art/ELEPHPANT-LICENSE.md`). The terrain, towers and scenery are from *Seasonal Platformer Tiles* by GrafxKid (CC0, `art/TERRAIN-LICENSE.md`). The game code and the sounds are Apache-2.0.

## What to study

- [Architecture](architecture.md) explains the world-space simulation, the viewport-filling renderer, the browser boundary and the licence boundary.

The [PHP API](api/index.html) lists every public type in the package.
