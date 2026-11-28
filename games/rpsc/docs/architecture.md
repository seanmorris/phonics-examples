# Architecture

RPSC is seven PHP classes, one entry script, three browser modules, and one generator script. The PHP divides into three layers that never reach upward: the rules, the session, and the presentation. Online play adds a fourth part in the browser, which feeds the same presentation.

## Rules: `Board` and `Game`

`Rpsc\Board` is geometry with no state. Squares are numbered 0 to 63 from a1, `owner()` says which side plays on a square, `steps()` lists the diagonal squares a piece could move to, and `neighbors()` lists the squares that share an edge. A diagonal step keeps a square's color and an edge neighbor always has the other color, so the rule that the two sides never block each other needs no code: `Game::moves()` only has to ask whether the target square is empty.

`Rpsc\Game` holds the true position, including both sides' hidden kinds. `place()`, `challenge()`, `move()`, and `resign()` validate and apply one action each, and queue an event that says what happened. `view($side)` is the only method that decides what a side may know. It returns an opposing piece's kind as `null` until the game is over.

`export()` and `import()` write and read a save. Saves come back from browser storage, so `import()` checks every field. It refuses a piece on the wrong color, a repeated piece id, a man on the far row, and any position whose pieces on the board and pieces lost do not add up to four of each kind.

## Session: `Bot` and `Session`

`Rpsc\Bot` receives `Game::view()` for its own side and nothing else, so it cannot read a kind the rules have not shown it. A test places three different hidden arrangements against it and checks that its first decisions are identical.

The bot keeps two small tables: the opposing pieces it has seen, with the turn it saw them, and its own pieces that the opponent has seen. From the kinds that have left the board it works out what each unseen piece can still be. It scores a challenge as the chance of winning times the worth of the piece taken, less the chance of losing times the worth of its own piece, less a small cost for showing a piece. It scores a move by progress toward the far row, the value of a back-row guard, and the difference in danger between the square it leaves and the square it reaches.

`Rpsc\Session` joins the rules to a player. It turns intents such as `['type' => 'move', 'from' => 17, 'to' => 26]` into rule calls, lets the bot act after a short pause, passes every event to the bot and then to a queue, and writes the whole session as JSON. A stale or illegal intent returns `false` and changes nothing.

## Presentation: `Table` and `Scene`

The rules apply an action at once. `Rpsc\Ui\Table` shows it afterward. It keeps its own copy of the pieces as drawn and plays one event at a time: a move slides a piece, and a challenge turns both pieces face up, holds them, removes the loser, and turns the survivors back. During placement it compares each new arrangement with the last and slides the pieces whose kind changed, two for a swap and more for a shuffle; the rules send no event for that. The table also owns the selection, the keyboard cursor, the status line, and the label of every square. It has no graphics calls, so `tests/table.php` plays twelve whole games through it and checks after every event that the animated pieces match a fresh look at the game.

`Rpsc\Render\Scene` draws the table with the engine's sprite, rectangle, and bitmap text renderers. The art is pixel art, so `fit()` picks the largest whole-number scale at which the board and both sides' rows fit the frame. It tries two layouts, rows stacked above and below the board or both rows in a column beside it, and keeps the one with the larger board. A 720 by 960 frame gets the stacked layout at four times. A 1280 by 680 window gets the column at four times, where stacking would only reach two.

Each frame the scene also publishes its pressable regions: one for every piece, one for every square the selected piece can reach, and one for each button. Empty squares that would do nothing are left out, so a tap on one reaches the canvas and lets go of the selection.

## The browser boundary

`game.php` wires the classes to the engine: it builds the menus from `Phonics\Ui\Menu`, routes input, and publishes a status object every frame that changes. `browser/game.mjs` provides what PHP cannot reach.

| PHP calls | The browser module |
| --- | --- |
| `rpscViewport` | Reports the frame size in device pixels, the pixel ratio, and whether the pointer is coarse |
| `rpscAction` | Hands over the id of the last region button pressed |
| `rpscSeed` | Returns a 31-bit seed from the platform's random source |
| `rpscLoad`, `rpscSave` | Reads and writes the unfinished game |
| `rpscOptionGet`, `rpscOptionSet` | Reads and writes the level, the side, and the sound level |
| `rpscStatus` | Receives the status object, plays new sounds, and mirrors the regions as buttons |

The status object carries only the player's own view of the game. It is mirrored on the canvas as `data-rpsc`, which is what the browser tests read.

## Online play

An online game is held in the browser, not in PHP. `browser/online-match.mjs` is a reducer over the room's actions: it checks each placement proof and each opening with `browser/mental-poker.mjs`, applies its own copy of the rules, and produces a view in the shape `Session::view()` has. [Playing online](online.md) describes the protocol.

`application.php` creates the engine's room lobby after the world is composed, so the lobby returns the canvas to the game's own frame when a table ends. `browser/game.mjs` attaches to the lobby the first time **Play online** is chosen and then carries each step the way the room expects: apply the action, report the resulting hash, wait for the room to confirm both reports, and only then send the next action.

PHP sees an online game through five more calls.

| PHP calls | The browser module |
| --- | --- |
| `rpscOnlineReady` | Says whether the lobby and both online modules are installed |
| `rpscLobby` | Opens the lobby |
| `rpscOnline` | Returns the online view as JSON, or an empty string while no table is live |
| `rpscOnlineEvents` | Hands over the events applied since the last call |
| `rpscCommand` | Takes one intent: swap, shuffle, ready, move, challenge, or resign |

While `rpscOnline` returns a view, `game.php` shows it on the same `Table` and sends intents to `rpscCommand` instead of the local `Session`. The table, the renderer, and the regions do not know the difference. The lobby lifts the canvas into its own stage for a seated game, so the browser module measures the canvas itself and moves the region buttons with it.

## Assets

`scripts/generate-rpsc-assets.mjs` computes everything in `assets/` and the poster: two board squares, eight checkers, the king's rim, five white marks that the renderer tints, and seven synthesized sounds. The tileset records each tile's name in an `rpsc.tiles` property and `Scene` looks tiles up by name, so the sheet can be reordered without touching PHP.

## Tests

| File | Covers |
| --- | --- |
| `tests/run.php` | Geometry, every pairing of kinds, crowning, both wins, the draw, save validation, and 300 random games with invariants checked after every action |
| `tests/bot.php` | Legal placements, the no-peeking check, reproducibility, win rates between levels, and a session played across saves |
| `tests/table.php` | Selection, labels, the cursor for both sides, and animation against the rules over twelve games |
| `tests/browser/rpsc.spec.mjs` | The built game in Chromium: placement, a move, a challenge, the keyboard, saving, resigning, both layouts, and an online game against a scripted second player |
| `tests/rpsc-online.test.mjs` | Two browsers in one process: whole games with every hash compared, forged openings, forged and copied placements, reloads, and a lost record |
| `tests/rpsc-online-room.test.mjs` | The same two browsers over the engine's room protocol: batches, confirmations, the final reveal, the outcome, resigning, and leaving |
| `tests/rpsc-parity.test.mjs` | The browser's copy of the rules against thirty games played by PHP |
