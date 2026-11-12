# Phlappy Phant

Phlappy Phant is a pixel-art homage to Flappy Bird starring the **elePHPant**, the PHP mascot by Vincent Pontier. Instead of pipes, the phant slips between grass pillars: one rises from the ground and its twin hangs from the sky. A deterministic fixed-step PHP simulation runs the flight, the seeded gaps, collisions, scoring, and the day cycle. The browser module supplies taps, adjustable music and sound, fullscreen, the local best score, and accessible menu hit targets.

The game fills any frame instead of letterboxing a fixed canvas. The world is 352 units tall and the visible width follows the frame, so a portrait phone and a widescreen desktop play the same course and differ only in how far ahead they can see.

## How to play

Press Play, then flap once to start. Gravity pulls the phant down and every flap replaces its vertical speed with the same upward kick. The pillars scroll toward it at 120 units a second, 4 faster for each point, until the speed levels off at 200 after 20 points. Passing a pair scores one point. Touching a pillar or the ground ends the run with a bonk; the top of the frame is safe.

The gap between a pair starts 144 units tall and narrows by 8 every eight points until it reaches 112 at 32 points, which leaves the phant's 36-unit body 76 units to spare. Every ten points the light changes: the sky warms through dusk into night, and ten points later it comes back through dawn.

The best score is kept in the browser and shown on the ready screen and the result card. Scores, bindings, and audio levels never leave the device.

## Controls

A tap or a click anywhere on the canvas flaps, so the game plays one-handed on a phone. **Controls** edits the keyboard and controller bindings separately, keeps Pause fixed, and stores valid changes locally when the browser allows it. **Audio** is available from the title and pause menus with separate SFX and BGM sliders; the speaker button remains a master mute.

| Action | Xbox | PlayStation | Keyboard and mouse |
| --- | --- | --- | --- |
| Flap | <span class="controller-glyphs"><img class="controller-glyph" src="../../../art/controllers/xbox-button-a.svg" alt="Xbox A button"><span>or</span><img class="controller-glyph" src="../../../art/controllers/xbox-button-rb.svg" alt="Xbox RB button"></span> | <span class="controller-glyphs"><img class="controller-glyph" src="../../../art/controllers/playstation-button-x.svg" alt="PlayStation Cross button"><span>or</span><img class="controller-glyph" src="../../../art/controllers/playstation-button-r1.svg" alt="PlayStation R1 button"></span> | Space, Up, W, or a tap or click on the canvas |
| Pause | <img class="controller-glyph" src="../../../art/controllers/xbox-button-start.svg" alt="Xbox Start button"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-start.svg" alt="PlayStation Options button"> | Escape or P |

## A hidden perfect player

With no points on the board, the Konami code (Up, Up, Down, Down, Left, Right, Left, Right, B, A) hands the run to a built-in player that never misses a gap and never taps faster than five times a second. The only sign is a single pixel in the top-left corner that winks every couple of seconds, and its runs never count toward the best score. [The perfect player](autopilot.md) explains how it plans.

## Credits and licenses

The elePHPant was created by Vincent Pontier in 1998. The sprite is pixel art traced from the vector drawing that Webysther uploaded to Wikimedia Commons under GPL-2.0-or-later, so the sprite sheet, the source vector, and the poster ship under that license (`art/ELEPHPANT-LICENSE.md`). The backdrop, pillars, ground, and scenery are cut from *Seasonal Platformer Tiles* by GrafxKid, released as CC0 (`art/TERRAIN-LICENSE.md`). The music is "Funktify" by Mr. Spastic, included unchanged under CC BY-NC-ND 3.0 (`art/FUNKTIFY-LICENSE.md`). The game code and the sound effects are Apache-2.0.

## What to study

- [Art and the day cycle](art.md) covers the source sheets, the parallax mask, the hand-graded dusk and night palettes, and how to change the art.
- [The perfect player](autopilot.md) covers the planner behind the Konami code and what its survival says about the course.
- [Architecture](architecture.md) covers the world-space simulation, the renderer that fills any frame, the browser boundary, and the tests.

The [PHP API](api/index.html) lists every public type in the package.
