# Phlappy Phant

A pixel-art Flappy Bird homage built with Phonics. The elePHPant flaps between stacks of DB silo barrels; every ten barrels the sky turns from day to night. A deterministic fixed-step PHP simulation owns the flight, the seeded barrel gaps, collisions and the score; the browser module supplies taps, sounds, fullscreen, the local best score and accessible menu hit targets.

Unlike the letterboxed games, Phlappy Phant fills whatever frame it is given: the world is 352 logical units tall and as wide as the frame's aspect allows, so a phone in portrait and a widescreen desktop both play the same game and only see different amounts of the road ahead.

## Secrets

With no points on the board, the Konami code (Up, Up, Down, Down, Left, Right, Left, Right, B, A on a controller; the arrows then B and A on a keyboard) hands the run to a tool-assisted player that never misses a gap but only taps at human speed: never more than five flaps a second. It builds the set of positions from which survival is still possible over the next two and a half seconds, backward from the horizon over the velocity lattice with that tapping limit baked in, and flaps only when coasting would leave that set; because it survives every seeded course under that limit, it doubles as the proof that the course is fair. The only tell is a single pixel in the top-left corner that winks lighter every couple of seconds, and the run never counts toward the best score.

## Credits

The elePHPant is the PHP mascot by **Vincent Pontier**; the sprite is pixel art traced from the vector drawing uploaded to Wikimedia Commons by **Webysther** (*Webysther 20160423 - Elephpant.svg*, 2016-04-23), which Commons lists under the GNU General Public License v2.0 or later. `art/elephpant.svg`, the traced sheet `art/elephpant.png`, the frames in `assets/phant.png` and `poster.png` are distributed under that licence; see `art/ELEPHPANT-LICENSE.md` and `NOTICE`. The code, the barrel, ground and cloud tiles and the sounds are Apache-2.0 like the other example games. The credits are also in the in-game About menu.

## Regenerating the art

`node scripts/generate-phant-sprites.mjs` (from the repository root) copies the traced elephant frames and draws the tiles, the tileset descriptions, the three synthesised sounds and the poster deterministically; `--check` verifies the committed files and `--preview DIR` writes enlargements for inspection.
