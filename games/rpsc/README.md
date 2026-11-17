# RPSC

Rock Paper Scissors Checkers, built with Phonics. Two sides share a checkers board, but each plays on the squares of the other side's colour, so their pieces never block or jump each other. Every checker is secretly a rock, a paper, or a scissors. Before moving, a player may challenge an opposing piece that shares an edge with one of theirs: both are turned over, rock-paper-scissors decides, and the loser leaves the board. The survivors are turned face down again.

A side wins by taking every opposing piece, or by standing on all four of its squares on the far row.

The PHP in `src/` owns the rules, the computer opponent, the selection and animation of the table, and every pixel. The browser module supplies the frame size, storage for the unfinished game and the options, a random seed, sounds, and accessible buttons over the canvas.

## Tests

```sh
php games/rpsc/tests/run.php     # geometry, rules, saves, 300 random games
php games/rpsc/tests/bot.php     # the three bot levels and the session
php games/rpsc/tests/table.php   # selection, labels, and animation
```

None of the three needs the engine installed. `tests/browser/rpsc.spec.mjs` plays the built game in Chromium.

## Regenerating the art and sounds

`node scripts/generate-rpsc-assets.mjs` (from the repository root) draws the pieces sheet and the poster and synthesises the seven sounds. The output is deterministic: `--check` verifies the committed files, and `--preview DIR` writes an enlarged sheet for inspection.

The code, art, and sounds are Apache-2.0, like the other example games.
