# RPSC

Rock Paper Scissors Checkers, built with Phonics. Two sides share a checkers board, but each plays on the squares of the other side's color, so their pieces never block or jump each other. Every checker is secretly a rock, a paper, or a scissors. Before moving, a player may challenge an opposing piece that shares an edge with one of theirs: both are turned over, rock-paper-scissors decides, and the loser leaves the board. The survivors are turned face down again.

A side wins by taking every opposing piece, or by standing on all four of its squares on the far row.

You play the computer at three levels, or another person online. Online, each browser publishes its placement locked under its own key, with a proof that it holds four of each kind, and opens single pieces with proofs when they are challenged. `docs/online.md` describes the protocol.

The PHP in `src/` owns the rules, the computer opponent, the selection and animation of the table, and every pixel. `browser/game.mjs` supplies the frame size, storage for the unfinished game and the options, a random seed, sounds, and accessible buttons over the canvas. `browser/online-match.mjs` holds an online game, and `browser/mental-poker.mjs` is the private-deck primitive it shares with PHP Poker, built by `scripts/build-mental-poker.mjs`.

## Tests

```sh
php games/rpsc/tests/run.php     # geometry, rules, saves, 300 random games
php games/rpsc/tests/bot.php     # the three bot levels and the session
php games/rpsc/tests/table.php   # selection, labels, and animation
```

None of the three needs the engine installed. `tests/rpsc-online.test.mjs`, `tests/rpsc-online-room.test.mjs`, and `tests/rpsc-parity.test.mjs` (run by `npm run test:node`) cover online play, and `tests/browser/rpsc.spec.mjs` plays the built game in Chromium, offline and online.

## Regenerating the art and sounds

`node scripts/generate-rpsc-assets.mjs` (from the repository root) draws the pieces sheet and the poster and synthesizes the seven sounds. The output is deterministic: `--check` verifies the committed files, and `--preview DIR` writes an enlarged sheet for inspection.

The code, art, and sounds are Apache-2.0, like the other example games.
