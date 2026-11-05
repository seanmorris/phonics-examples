# PHP Poker: table and interaction design

Status: reflects what is built (plan node #2279). Owner: Claude. The rules, controller and bots are Codex's
(`src/Game.php`, `src/Session.php`, `src/Bot.php`); the presentation only consumes `Session::view()`.

## Intent

The guide is MS-DOS Bicycle Poker (SWFTE, 1991–93). What we keep from it is the composition: the whole screen is
felt; every seat is a white status plate that says what the player did, over a stepped fan of five cards, with
denomination stacks in front; each player has a small object emblem; the pot and deck sit in the
centre between "Total pot" and "Current bet" plates; a menu bar runs along the top; dialogs are ruled boxes
with the title set into the top rule. What we do not take is any asset: no Bicycle branding, characters, card
faces or portraits are reproduced.

## Two looks

The layout, type, card art and controls are the same in both; only the finish changes, from Settings.
**Classic** (default) is the DOS field: flat `#1f8f45` felt, square corners, white plates with a 2 px black
edge, bevelled grey buttons, no shadows or gradients, all text in Fixedsys Excelsior. **Modern** rounds the
corners, lights the felt with a gentle radial vignette (no texture), adds light shadows under plates, cards
and dialogs, a dark disc behind each emblem, keycaps on the buttons, and sets text in Barlow Semi Condensed.

## Art

- Card faces: `art/cards/deck.svg`, the public-domain Wikimedia Commons deck, a 9×6 sheet of 750×1050 cells in
  the order spades, hearts, clubs, diamonds, two jokers. Backs: `art/cards/back-red.svg` (default) and
  `back-blue.svg`, the public-domain broken-line rhombus backs. Emblems: `art/icons/*.svg`, Codex's original
  64×64 whole-pixel drawings keyed by `players[].portrait`, with distinct material palettes and one-pixel contours. All of these ship as SVG; at boot PHP hands their text to the browser,
  which rasterises them into one RGBA atlas (card cells 125×175, or 150×210 above 1.25 dpr; emblems at their native 64×64 resolution
  with no smoothing) and returns it once. Until it arrives, cards draw as plain faces with their index and
  emblems as discs with an initial.
- Type: `art/typography/barlow-semi-condensed.json` (OFL) and `fixedsys-excelsior.json` (public domain),
  baked by `scripts/generate-poker-textures.mjs` into one signed-distance atlas with faces `body`, `bold`
  and `dos`. The poster is rendered from `art/poster.svg`.
- Palette and sizes live in `src/Render/Theme.php`.

## Layout

`src/Ui/Layout.php` is pure and shared by painting and hit-testing. It carries three design profiles and picks
one from the canvas: **wide**, a 1280×720 field, for landscape canvases of 760 CSS px or more; **tall**, a
450×800 portrait field, for portrait or narrower canvases; **landscape**, a 900×400 field with a separate right-hand control panel, for landscape canvases below 480 CSS px high. The field scales uniformly to fit and is centred. Interactive controls are at least 48 CSS pixels high across device-pixel ratios; short-screen menus use two columns.

- Seats sit on an oval (wide 470×250, tall 165×260) with the human at the bottom. Opponents are spaced evenly
  from the human's left. Its upper half stretches upward by 24 design pixels on wide screens, 6 in portrait
  and 12 in short landscape, opening space around the centre while keeping the human hand in place.
  In the wide profile the action buttons sit to the lower right of the human, so no seat
  may come lower on that side than .42 rad below the horizontal; from six seats up the opponents spread over
  the arc that stops short of it. In the tall profile the opponents spread over the arc starting sixty degrees
  either side of the human.
- A seat is a plate (wide 200×24, human 250) over a fan (wide opponent cards 68×95 stepped 30; the human's
  90×126 stepped 38), chip stacks in front of the fan's outer corner (the human's beside the hand), a caption
  with the name and stack under the fan, and the emblem on the inside of the oval between the seat and the
  centre. The human's combined YOU and total caption is centered directly below the occupied chip stacks;
  their Bet indicator has its own space to the right of the stacks, clear of denomination labels and cards.
- Plates: white for the last action or "Thinking", rose for Fold, straw for the hand name at showdown; a brass
  edge marks the seat whose turn it is. The dealer gets a small "D" disc beside the plate.
- Centre: "Total pot (n)" plate, the deck (a back the size of a hand card), the pot's chips in front of it,
  and "Current bet (n)".
- Draw order: plates, cards, chips, emblems and markers, centre, message, discard dialog, actions, then modal history/welcome dialogs. Discard cards never overlap the Draw target.

## Actions

Built only from `legalActions`, so an illegal move never has a button. Wide: a row to the lower right of the
human; tall: a touch-sized row across the bottom with the raise stepper on a second row.

| State | Buttons | Keys |
| --- | --- | --- |
| hand not dealt | Deal | Enter |
| betting, may pass | Fold · Pass · Open for N | F · C · B |
| betting, may check | Fold · Check · Bet N | F · C · B |
| betting, facing a bet | Fold · Call N · Raise to N | F · C · B |
| betting, jacks or better not met | Fold · Pass | F · C |
| draw | Keep all · Draw N / Stand pat | D |
| showdown or redeal | History · Deal, or "Borrow 500 & deal" when broke | H · Enter |

The raise-to amount has − / + steppers (also − / + and the arrow keys) clamped to `minBet`..`maxBet`.
Discard selection is presentation state: tapping a card in the SELECT DISCARDS dialog or pressing 1–5 lifts it
with a brass edge; nothing leaves the hand until Draw. Selection clears on a new revision.
Raised cards keep their hit regions with their faces. The browser uses the presenter's normalized hit regions
for the hand cursor, including updates when a control changes beneath a stationary pointer. Geometry updates
before input on a resized canvas.

Input lock: the presenter sends at most one game intent per `revision`, stamped with the view's generation
and revision, then dims the bar and ignores game input until a view with a new revision or a non-empty
`error` arrives. Card and chip transfers also hold game input until arrival; bot delays begin afterward.
Menu and history remain available. Resuming a saved game does not replay an already committed deal or draw.

## Card and chip motion

`Motion` consumes consecutive authorized views. Deals travel from the deck to seats in round-robin order;
discards travel to the discard pile before replacements leave the deck. Positions interpolate linearly,
without easing, fades, bounce or rotation. Card travel takes 0.22 seconds, staggered by 0.055 seconds. Chips
travel for 0.25 seconds, staggered by 0.025 seconds. Resizing recomputes their endpoints from the same layout.
Menu/background pauses hold presentation time. Movement stops drawing frames when it finishes.

`Chips` maintains exact visual inventories, separate from rules and saves. White chips are 1, red 5, green 25,
black 100, purple 500 and gold 1,000; larger denominations are supported for larger balances. Every visible
chip is counted and marked with its value. Denominations nest closely, with larger denominations in front.
Banks exchange denominations at chip departures and arrivals so each larger denomination has fewer chips than
the occupied stacks behind it. Stacks above twenty chips automatically color up into larger denominations;
for extremely large balances the allocator finds the shortest exact arrangement compatible with that ordering.
The timeline records each exchange and the actual chip endpoints, preserving overlapping constant-speed flights.
Pot chips form a loose, mixed cluster whose positions stay fixed between exchanges.
Payments, antes and awards remove chips from their actual stack positions and
append them to the destination stacks. In-flight chips are counted in neither stack, so value is conserved
throughout the movement. Empty banks show no chips. Reloading reconstructs denominations from exact balances.

The winning hand is displayed beside its owner, without a duplicate announcement at the top. Only winners'
hand labels blink, alternating every 0.3 seconds for four seconds, then remain visible. The flash does not
block the next deal and schedules no further changes after it ends.

## Screens and dialogs

- Menu: wordmark, caption, then Play Offline, Continue Offline (when `canContinue`), Online (coming soon; a notice
  until #2285), How to Play, Settings. Enter plays offline, C continues, H opens help, M opens settings, Escape returns.
- Table setup (local to the presenter): opponents 1–7, Jacks or better / Anything opens, Back, Deal. The
  Deal sends `start{players, variant}`.
- Dialogs: DRAW POKER welcome before the first deal, SELECT DISCARDS during the human's draw, ROUND HISTORY
  (H) listing the last public lines, HOW TO PLAY, SETTINGS (Classic / Modern with M, Normal / Fast pace with P). All are ruled boxes.
- Message: phase-specific guidance (or `error`, in rose) centered below Current bet, wrapping on compact screens.
  In short landscape layouts, the stock and discard piles flank the bet to leave room for the status and hand. Opening bets explain
  that discarding comes afterward; a hand that cannot open is told that Pass keeps it in. The menu bar names
  the current phase. A player waiting for another seat's draw is not told to select cards yet.

## Status and accessibility

`Presenter::status()` returns `['ui' => …]` with the selected discards, raise amount, mode, local screen,
history flag, lock, setup choices, phase guidance, normalized hit regions, exact chip inventories, animation
timing, winner-flash status, whether the card atlas has arrived and the active profile; the browser
publishes it merged with the view on `canvas.dataset.phpPoker`. `browser/game.mjs` also keeps a polite live
region with the error, message and whose turn it is, and exposes `phpPokerDebug.command()` for tests.
Keyboard covers play and menus; pointer and touch share one click path. Canceled gestures and drags do not click, repeated keydown events do not toggle a card twice, and the human hand has a private screen-reader description. Graphics restoration preserves the saved hand and replaces its accessible description cleanly.

## Not built

Audio, gamepad bindings, speech balloons and painted portraits are not implemented.

## Open rules questions (tracked, not guessed)

Exact DOS parity remains open in #2261: what the pot does when everyone passes; whether an opener may discard part of the qualifying
hand; stock exhaustion and discard recycling at eight seats; ace-low straights. The presentation draws the explicitly documented `bicycle-inspired-local-v1` profile in `rules.md`; unresolved historical behavior is labeled as an adaptation there.
