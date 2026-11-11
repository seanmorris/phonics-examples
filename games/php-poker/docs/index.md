# PHP Poker

Play poker at a classic 2D table against one to seven local opponents, or heads-up online. The table deals four games: five-card draw, Texas Hold'em, five-card stud and seven-card stud.

## The games

Choose **Play Offline**, then press the **Game** button on the Table Setup card. It opens a list of the games; pick one and press **Deal**.

| Game | Cards | Forced bets | Betting rounds | Seats |
| --- | --- | --- | --- | --- |
| Five-card draw | Five face down, then one draw | The dealer antes | Two, around the draw | 2–8 |
| Texas Hold'em | Two face down, five shared on the board | Small and big blinds | Four: before the flop, on the flop, turn and river | 2–8 |
| Five-card stud | One face down, four face up | Everyone antes; the lowest card showing brings in | Four, one per street | 2–8 |
| Seven-card stud | Two down, four up, one down | Everyone antes; the lowest card showing brings in | Five, one per street | 2–7 |

Draw has two opening rules. The default is Jackpots: a pair of jacks or better is required to open. Anything Opens lets any hand start the betting.

In Hold'em your hand is the best five of your two cards and the five on the board. In seven-card stud it is the best five of your seven. In stud your own face-down cards are shaded, so you can tell them from the cards everyone sees. The **Limits** button sets the blinds or ante and two bet ranges: one for the early streets and one for the later ones.

## Five-card draw

Each hand deals five cards, runs an opening betting round, lets you replace zero to five cards, and finishes with a final betting round and showdown. Select cards first, then confirm **Draw** or **Stand Pat**. Betting controls show the amount to call and the total you are raising to.

A standard controller is supported: use the D-pad or left stick to move the gold focus frame, A / Cross to select, B / Circle to go back, Start for the game menu, and the shoulder buttons to change a raise.

In **Jacks or Better**, you need a pair of jacks or a stronger hand to open the betting. If your only choices are **Fold** and **Pass**, choose **Pass** to stay in the hand and let someone else open. If everyone passes, the pot carries into the next deal. The **Select Discards** panel appears when the opening betting round is finished and it is your turn to draw. Cards are not selectable during betting.

The dealer alone pays the ante. The local bank can lend chips when a player runs out; a player borrowing during a hand can call but cannot raise. Your stack, loans and net result remain part of the saved session.

Accepted actions save automatically in this browser. Return to the menu to pause, and choose **Continue Offline** to resume the same cards and turn. Starting a new table replaces the previous saved game. A save failure is shown in the game; the previous complete save remains intact.

For disconnected play, open the full game page while connected and wait for **Available offline** beneath the table. The downloaded game can then reopen without a connection. Embedded games and the rest of the example catalog are not installed by that download. Browser storage eviction or clearing site data removes saves and downloaded assets.

## Online

**Play Online** opens a two-player Matrix table. Opening a table or a challenge shows a House rules dialog with the
offline game's choices (the game, ante or blinds, betting limits, raise caps and wild cards). Online draw always
plays Anything Opens. Both players see the house rules when the match starts. Both browsers contribute a secret
shuffle, verify the other's shuffle, and deal each seat its private cards without publishing either hand. In draw the
room then carries both betting rounds, private discards and replacement draws. In Hold'em and stud it deals street by
street: a card the rules turn face up, such as a stud up-card or a board card, is proved by the player it was dealt
to before anyone bets. Live hands publish verified card openings at showdown; folded hands stay private. The dealer can continue into another freshly shuffled hand with the button advanced. Each turn has a
five-minute limit, long enough for a short break: a player who lets it run out loses the match on time.

See [Rules and provenance](rules.md) for documentary DOS rules and the explicitly chosen adaptations, and [Architecture and private online dealing](architecture.md) for implementation boundaries.
