# PHP Poker: table and interaction design

Status: draft for review (plan node #2279). Owner: Claude. The rules, controller and bots are Codex's
(`src/Game.php`, `src/Session.php`, `src/Bot.php`); this document only consumes `Session::view()`.

## Intent

The guide is MS-DOS Bicycle Poker (SWFTE, 1993). What we take from it is the experience: a fixed
top-down table, opponents who are characters with painted portraits, names and manners, who announce
their moves in speech balloons; one dealer line that tells you what just happened; everything about
the hand (stacks, bets, pot, stock, discards) in view at once; and a short, legible action row. What
we do not take is any asset: no Bicycle branding, characters, card faces, portraits or text are
reproduced. All art is original work in that spirit, drawn clean rather than pixel-faithful.

## Two visual modes

The layout, type, card art and controls are the same in both; only the finish changes, as a Settings
choice stored locally. **Classic** (default) is the DOS field: flat `#1f8f45` felt, square corners,
white plates with a 2 px black edge, bevelled grey buttons, no shadows or gradients, the feel of
the 1991 VGA screen at today's resolution with anti-aliased type. **Modern** rounds the corners
(3–6 px), lights the felt with a gentle radial vignette (`#25884f` centre to `#1a5f3a` edge, no
texture), adds light shadows under plates, cards and dialogs, and a dark disc behind each emblem.
Nothing in Modern is textured or distressed.

## Palette and type

| Token | Value | Use |
| --- | --- | --- |
| felt | `#1f7140`, centre `#2a8a4f`, edge vignette `#154a2b` (VGA-leaning green) | table cloth |
| rail | `#6b4323` with `#8d5c31` highlight band (tan wood) | table edge |
| room | `#17110b` | backdrop outside the rail |
| panel | `#0b1210` at 84 %, hairline `#e8d4a0` at 18 % | action bar, seat plates, menus (shared with Billiards) |
| brass | `#d9a441` / `#f5c76b` | focus, dealer button, active seat, primary button |
| text | `#f2efe6`; secondary `#a9b5ad`; on brass `#17110a` | all copy |
| danger | `#e0533d` | fold, errors |
| cards | faces from the public-domain deck in `art/cards/deck.svg` (Wikimedia Commons, CC0), rasterised per card into the atlas; back is original, `#24365c` lattice on `#d8cfb5` border | cards |
| chips | 1 white `#efe9dc`, 5 red `#c0392b`, 10 blue `#2d5fa6`, 25 green `#2e8b57`, 100 black `#23242a` | stacks and pot |

Type is Barlow Semi Condensed (Medium, SemiBold), the atlas already vendored for Billiards. Sizes:
caption 13, body 15, label 16, heading 21, display 46. Card indices use SemiBold. Every text/background
pair keeps at least 4.5:1, including disabled buttons, which fade their fill, not their text.

## Cards

`art/cards/deck.svg` is the "Public domain complete playing card deck" from Wikimedia Commons (CC0):
a 9×6 sheet of 750×1050 cells, ordered spades A–K, hearts A–K, clubs A–K, diamonds A–K, then two
jokers. The texture generator rasterises each cell with resvg into a 2048-wide atlas (cell 216×302,
giving crisp 96×134 cards at 2× DPR) and writes per-card UVs to `textures.json`. The view's card ids
map to cells as `cell = suitOrder[floor(id/13)] * 13 + (rank == 14 ? 0 : rank − 1)` with
`suitOrder = [clubs→2, diamonds→3, hearts→1, spades→0]`. The backs are `art/cards/back-red.svg` and
`back-blue.svg`, the public-domain "broken line rhombus" backs (208×303, viewBox 55.03×80.17); red is
the default, as in the DOS game, and blue is a Settings choice. Provenance and licences go in `NOTICE`.

## Screens

### Menu

Centred wordmark "Draw Poker" (display), caption "Five-card draw, jacks or better", then five items:
Play Offline, Continue Offline (hidden until `canContinue`), Play Online, How to Play, Settings. Hover and
keyboard focus share one brass selection state; Enter/A activates. Version and licence line bottom-right.
Play Offline opens the table setup card: opponents 1–7 (stepper), variant Jackpots / Anything Opens,
Start. Play Online is a stub until #2285: it explains that private tables are coming and returns.

### Table

Logical 1280×720 landscape. Portrait and narrow layouts are below.

```
 ┌──────────────────────────────────────────────────────────────────────────┐
 │ [status line: "Mara opens for 4."]                                 [≡ Menu]│
 │                                                                          │
 │        (seat 3)         (seat 4)         (seat 5)                        │
 │   (seat 2)       ╭─────────────────────────────╮        (seat 6)        │
 │                 │   stock ▯12   POT 24   disc ▯3 │                       │
 │   (seat 1)      │   [chips]  Final betting       │        (seat 7)       │
 │                  ╰─────────────────────────────╯                         │
 │   ┌──────────────────────────────────┐    ┌───────────────────────────┐  │
 │   │  You  ▣ 486   bet 4     Pair of  │    │ Fold   Check   Call 4     │  │
 │   │  [A♠][A♥][7♦][4♣][2♠]   aces     │    │ Raise to [ 8 ] −  +   ✓   │  │
 │   └──────────────────────────────────┘    └───────────────────────────┘  │
 └──────────────────────────────────────────────────────────────────────────┘
```

- **Table**: a stadium (two semicircles joined by a rectangle) 880×420 centred at (640, 330); rail 26 px.
- **Seats**: seat 0 (the human) is always the bottom plate. Seats 1..n−1 sit on the stadium's outer
  path, spread evenly over the arc from the bottom-left corner to the bottom-right corner going over the
  top, in play order clockwise from the human's left. So 2 seats: opponent at the top centre; 5 seats:
  left, top-left, top-right, right; 8 seats: seven plates across the left, top and right.
- **Opponent plate** (176×64): a framed painted portrait 48×48 (seven original characters, each with
  a distinct face, hat or hair and a stable `portrait` key), name (fit to 96 px, ellipsis), stack with
  a chip glyph, five 28×40 card backs fanned under the plate toward the table; after a draw the drawn
  cards slide in; at showdown unfolded hands turn face up. Folded plates drop to 55 % opacity.
  Current bet shows as a small chip cluster plus number between the plate and the rail.
- **Speech balloon**: each opponent's last public action is spoken, not captioned: "I'll open for 4.",
  "I'll see that and raise 4.", "Two cards.", "I'll play these.", "Too rich for me." The balloon is
  cream with the speaker's brass edge while it is their turn, points at the plate, and fades after the
  next action. Lines come from the bot's manner (Codex's `Bot`), through a `players[].line` field;
  until that exists the presenter phrases `lastAction` itself from a fixed table.
- **Dealer button**: a brass disc "D" at the rail beside the dealer's plate; it moves with an eased
  slide at next hand.
- **Active seat**: brass 2 px edge glow on the plate and a 1.5 s "thinking" shimmer on bot turns, so
  the human can see whose turn it is from across the table.
- **Centre**: pot amount (heading) over its chip stacks; stock pile with count badge to the left,
  discard pile with count to the right; phase caption under the pot ("Opening", "Draw", "Final betting",
  "Showdown"). Side pots, when they exist, line up right of the main pot with eligible seat initials.
- **Your hand**: five 96×134 cards fanned at 6° increments at the bottom-left, overlapping 24 px; the
  cards sort by rank (the view already sorts). Hand name caption ("Pair of aces") sits right of the
  fan when the hand is complete. Stack, current bet and net are in the plate above the cards.
- **Dealer line**: a full-width strip along the top, as the DOS table's single message line: hand
  number and dealer at the left, `message` centred in sentence case, Menu at the right. Errors
  (`error`) use the danger colour in the same slot. It is the one place the game talks to you.

### Action bar (bottom-right, 420×120)

Built only from `legalActions`; anything absent is not drawn, so the bar never shows an illegal move.

| Phase / flags | Buttons (left → right) | Keys |
| --- | --- | --- |
| betting, `pass` | Fold · Pass · Open for N | F · C · B |
| betting, `check` | Fold · Check · Bet N | F · C · B |
| betting, `call` | Fold · Call N · Raise to N | F · C · B |
| betting, `openingRequired` | Fold · Pass, plus caption "Jacks or better to open" | F · C |
| draw | Draw N / Stand pat (one button, label from selection) | D / Enter |
| showdown, redeal | Next hand; Take 500 loan when `loan` > 0 | Enter · L |

- The bet control is a raise-to amount with − / + steppers in `minimum` increments and a slider
  between `minBet` and `maxBet`; the button label is `betLabel` plus the amount. The caption under it
  shows "of 486" (stack after the bet) so the cost is unambiguous.
- Call shows the exact `callAmount`. Fold is always the leftmost and the only danger-coloured button.
- **Discard selection**: clicking or tapping a card in your hand toggles it (card lifts 18 px with a
  brass edge); keys 1–5 toggle by position, arrows move a focus ring, Space toggles. The Draw button
  reads "Draw 2" / "Stand pat" and nothing is discarded until it is pressed. Selection clears on a new
  view with a different `revision`.
- **Input lock**: the presenter sends at most one game intent per `revision`; after sending, the
  bar dims and ignores input until a view arrives with a new `revision` or a non-empty `error`. Bot
  turns show the bar collapsed to the one caption "Waiting for Mara…".

### Showdown

Unfolded opponents' cards flip face up; the winning seat's plate and cards get the brass glow, the
pot's chips slide to the winner, and the status line carries `message` ("Mara wins 24 chips with two
pair."). `awards` draw as a short list under the pot when the pot splits. Next hand returns the
button and deals.

### Help and Settings

Help: one scrollable sheet with the hand ranking table (illustrated with small cards), the flow of a
hand, and the controls table generated from the same binding table the keycaps use.
Settings: pace (normal / fast), reduced motion (also follows `prefers-reduced-motion`), sound on/off
and volume, card size (regular / large), high-contrast suits (four-colour deck). Settings persist in
`localStorage` through the browser bridge; none of them touch the rules.

## Responsive layouts

- **Wide (≥ 1100 logical px)**: as above.
- **Tablet (760–1100)**: the stadium shrinks to 70 % and the action bar moves under your hand as a
  full-width row; opponent plates drop the last-action caption into a tooltip-style chip.
- **Narrow / portrait (< 760 or portrait aspect)**: the stadium becomes vertical (420×880 scaled to
  fit), seats stack along both sides, your hand spans the bottom at 72×100 cards, the action bar is a
  two-row full-width dock, and the status line sits above the hand instead of the top. Touch targets
  are at least 48 px; the hand uses a tap-to-select, tap-Draw confirm, never drag.
- Everything is laid out from one `Layout::compute($width,$height,$dpr,$view)` that the renderer and
  hit-testing both use, as Billiards' `Hud::layout` does, so tests can assert regions without GL.

## Motion

Deal: cards fly from the stock to each seat at 40 ms intervals (pace normal) or 12 ms (fast). Draw:
discards slide to the discard pile, replacements fly in. Chips: bets move to the centre on commit,
the pot moves to the winner at showdown. Reduced motion: all moves become 120 ms cross-fades. No
animation ever gates an intent: the reducer's `revision` advances immediately and the presenter only
delays what it draws, not what it sends.

## Audio (browser bridge)

Original recorded samples: card slide, card flip, chip clink (three variants), button tick, win sting,
soft error. Decoded once, unlocked on the first gesture, muted state persisted; a 12 ms per-sample
throttle and an 8-voice cap, as in Billiards.

## Accessibility

Keyboard: Tab/arrows move focus between bar buttons and cards; focus ring is brass 2 px with a dark
outline so it is visible on felt and cream. Gamepad: A activates, B folds (with a confirm on the
first fold of a session), bumpers move card focus, D-pad steps the bet. `status()` exposes the
focused control, selected cards and legal actions for assistive tooling and tests. Suits in
high-contrast mode use four colours and distinct pip shapes; card indices stay ≥ 15 px at every size.

## View fields consumed

`screen`, `phase`, `turn`, `dealer`, `handNumber`, `revision`, `generation`, `message`, `error`,
`pot`, `players[].{id,name,stack,debt,net,folded,bet,totalBet,drawCount,cards,handName,lastAction}`,
`legalActions.*`, `awards`, `stockCount`, `discardCount`, `canContinue`, `pace`.
Requested additions (additive): `players[].portrait`, `players[].isHuman`, `players[].line` (the
spoken form of the last action, from the bot's manner), `history`.

## Fixtures

`tests/fixtures/views/*.json`: hand-crafted `Session::view()` snapshots for every phase at 2, 5 and 8
seats, long names, 5-digit stacks, a split pot and an `openingRequired` case. The layout tests assert
that regions do not overlap at 1280×720, 1024×768, 390×844 and 2560×1440 and that the action bar
contains exactly the legal actions.

## Open rules questions (tracked, not guessed)

Waiting on #2261: what the pot does when everyone passes; whether an opener may discard part of the
qualifying hand; stock exhaustion and discard recycling at eight seats; ace-low straights. The
design draws whatever the view says and labels these spots "per table rules" until pinned.
