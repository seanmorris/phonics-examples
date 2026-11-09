# Phlappy Phant

A pixel-art Flappy Bird homage built with Phonics. The elePHPant flaps between stacks of DB silo barrels; every ten barrels the sky turns from day to night. A deterministic fixed-step PHP simulation owns the flight, the seeded barrel gaps, collisions and the score; the browser module supplies taps, sounds, fullscreen, the local best score and accessible menu hit targets.

Unlike the letterboxed games, Phlappy Phant fills whatever frame it is given: the world is 480 logical units tall and as wide as the frame's aspect allows, so a phone in portrait and a widescreen desktop both play the same game and only see different amounts of the road ahead.

## Credits

The elePHPant is the PHP mascot by **Vincent Pontier**; the sprite is a rasterised, reduced-palette derivative of the vector drawing uploaded to Wikimedia Commons by **Webysther** (*Webysther 20160423 - Elephpant.svg*, 2016-04-23), which Commons lists under the GNU General Public License v2.0 or later. `art/elephpant.svg`, the sprite frames in `assets/phant.png` and `poster.png` are distributed under that licence; see `art/ELEPHPANT-LICENSE.md` and `NOTICE`. The code, the barrel, ground and cloud tiles and the sounds are Apache-2.0 like the other example games. The credits are also in the in-game About menu.

## Regenerating the art

`node scripts/generate-phant-sprites.mjs` (from the repository root) rebuilds the sprite sheet, the tileset description, the three synthesised sounds and the poster deterministically; `--check` verifies the committed files and `--preview DIR` writes enlargements for inspection.
