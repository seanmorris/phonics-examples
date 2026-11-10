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

`src/Ui/Layout.php` is pure and shared by painting and hit-testing. It carries three design profiles:
**wide**, the 1280×720 oval of the DOS game; **tall**, a 470×836 portrait field with the opponents in a column
down each side; and **landscape**, a 900×400 field with side columns and a right-hand control panel. A landscape field with height to spare, such as a 16:9 frame embedded in a page, seats the opponents in one row along the top instead, with the pot between the row and the hand; a phone on its side is too short for that and keeps the columns. Interactive
controls are at least 48 CSS pixels high across device-pixel ratios; short-screen menus use two columns.

- **Choosing a profile.** The oval is the table's own composition, so it is tried first on every canvas that is
  at least 760 CSS px wide, at least 480 CSS px high and not narrower than 55% of the field's aspect. It is kept
  when nothing on it collides: no seat block (plate, fan, emblem with its caption, chip bank) with another, with
  the centre plates and stock, with the action lane or with the field edge, and with one status line free between
  the bet plate and the human's plate. Cards and chips are first tried at their preferred size and otherwise given
  the largest size at which nothing collides, down to 60%; plates, emblems, controls and type keep their CSS size
  throughout. Only when the oval cannot fit does a landscape canvas under 720 CSS px high use **landscape** and
  any other canvas **tall**. Canvases under 480 CSS px high go straight to **landscape**; portrait and narrow ones
  to **tall**. A 1280×720 or 1366×768 browser window therefore plays on the oval.
- **Scaling.** The wide field always fills the canvas height; its x axis compresses independently on narrower
  canvases (`Layout::stretch()` is the ratio). Card, emblem and chip artwork are counter-scaled so they never
  distort, and emblem lanes, chip spacing and the dealer button are measured across the compressed axis. Sparse
  tables (two to four seats) grow their cards while no named pair of blocks gains an overlap. The tall and
  landscape fields scale uniformly and grow along whichever axis the canvas leaves free.
- **Dialogs.** Menus, setup, wild cards, help, history and the discard dialog hold text and controls of fixed CSS
  size, so on a compressed field they are laid out on a square twin of the layout and mapped back; they are never
  squeezed with the table. The discard dialog shrinks its five cards rather than overlapping them when the canvas
  is narrower than the dialog. The custom wild-card matrix is shown only where each cell holds a checkbox and a
  label such as 10♣; otherwise the dialog pages by suit, ace first as in the matrix. A button label that cannot
  share its button with the key legend keeps the label and drops the legend.
- Seats sit on an oval (wide 470×250) with the human at the bottom. Opponents are spaced evenly from the
  human's left. Its upper half stretches upward by 24 design pixels, opening space around the centre while
  keeping the human hand in place. The action buttons stand in a lane to the right of the human's hand, so no
  seat may come lower on that side than `ACTION_CLEARANCE`; from six seats up the opponents spread over the arc
  that stops short of it.
- The centre cluster (pot plate, loose pot, stock and discards, bet plate) keeps its canonical place when it fits
  between the opponent standing over it and the status line above the human's plate. Otherwise it moves, and the
  stock and discards shrink, until the pot plate clears the cards and chips above it; heads-up on a phone the
  loose pot and piles shrink the same way.
- The dealer button stands at the inner end of a seat's plate. The human's stands in a 34-px gutter between the
  plate and the action lane, and an opponent's moves to the emblem's corner when a neighbour's cards or chips
  are in the way.
- A seat is a plate exactly as wide as its five-card fan (wide opponent cards 68×95 stepped 30; the human's
  90×126 stepped 38), with chip stacks in front of the fan's outer corner (the human's beside the hand).
  A 32×32 object icon sits on the plate's outer edge; the name and total form two centered lines beneath it.
  the cards. Right-side identities and denomination stacks mirror the left-side layout. Cards begin 8px below the
  plate. At dense tables each complete side group shifts inward by the same amount, so only the gap where the two
  layouts meet changes. The human has a 32px YOU disc with a gold ring left of the plate, the numeric total centered
  beneath it, and a chip bank using the same card-bottom-plus-4px corner anchor as the CPUs. The human hand lifts
  enough to retain field and phone-control clearance. Their Bet indicator has its own space to the left, clear of denominations and cards.
- Plates: white for the last action or "Thinking", rose for Fold, straw for the hand name at showdown; a brass
  edge marks the seat whose turn it is. The dealer gets a small "D" disc beside the plate.
- Centre: "Total pot (n)" plate, the deck (a back the size of a hand card), the pot's chips in front of it,
  and "Current bet (n)".
- Draw order: plates, cards, chips, emblems and markers, centre, message, discard dialog, actions, then modal history/welcome dialogs. Discard cards never overlap the Draw target.

## Actions

Built only from `legalActions`, so an illegal move never has a button. Wide and landscape: a vertical lane to
the right of the human's hand, with the raise stepper beneath it; tall: a touch-sized row across the bottom with
the raise stepper on a second row. While the discard dialog is open it carries the instruction, so a centre plate
it would cut through is not drawn.

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

Standard controllers use the engine's shared SDL mapping in browser and native builds. The D-pad and left stick
move a persistent gold focus frame spatially; A / Cross activates the focused card or control, B / Circle goes back, and Start
opens or resumes the game menu. During a draw focus begins on the first card. The shoulder buttons decrease and
increase the raise amount. Directional input repeats only after a deliberate hold, while actions fire once per
press. Keyboard or pointer activity hides the controller focus and restores keyboard legends.

Input lock: the presenter sends at most one game intent per `revision`, stamped with the view's generation
and revision, then dims the bar and ignores game input until a view with a new revision or a non-empty
`error` arrives. Card and chip transfers also hold game input until arrival; bot delays begin afterward.
Menu and history remain available. Resuming a saved game does not replay an already committed deal or draw.

## Card and chip motion

`Motion` consumes consecutive authorized views. Deals travel from the deck to seats in round-robin order;
discards travel to the discard pile before replacements leave the deck. Positions interpolate linearly,
without easing, fades, bounce or rotation. Card travel takes 0.22 seconds, staggered by 0.055 seconds. Chips
travel for 0.25 seconds, staggered by 0.025 seconds. Resizing recomputes their endpoints from the same layout.
When a player's balance changes, its fixed total counts linearly through whole values over the chip timeline;
the numerals do not slide or roll. Menu/background pauses hold presentation time. Movement stops drawing frames
when it finishes.

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

- Menu: wordmark, caption, then Play Offline, Continue Offline (when `canContinue`), Play Online, How to Play,
  Settings. Online opens the Matrix table lobby. Enter plays offline, C continues, H opens help, M opens settings,
  Escape returns. On a controller, the D-pad or left stick moves, A selects, B returns, and Start resumes a saved table.
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
