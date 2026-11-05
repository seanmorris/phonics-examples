# PHP Poker

Play five-card draw at a classic 2D table against one to seven local opponents. The default is Jackpots: a pair of jacks or better is required to open. Anything Opens lets any hand start the betting.

Choose **Play Offline** to start a table. Each hand deals five cards, runs an opening betting round, lets you replace zero to five cards, and finishes with a final betting round and showdown. Select cards first, then confirm **Draw** or **Stand Pat**. Betting controls show the amount to call and the total you are raising to.

A standard controller is supported: use the D-pad or left stick to move the gold focus frame, A / Cross to select, B / Circle to go back, Start for the game menu, and the shoulder buttons to change a raise.

In **Jacks or Better**, you need a pair of jacks or a stronger hand to open the betting. If your only choices are **Fold** and **Pass**, choose **Pass** to stay in the hand and let someone else open. If everyone passes, the pot carries into the next deal. The **Select Discards** panel appears when the opening betting round is finished and it is your turn to draw. Cards are not selectable during betting.

The dealer alone pays the ante. The local bank can lend chips when a player runs out; a player borrowing during a hand can call but cannot raise. Your stack, loans and net result remain part of the saved session.

Accepted actions save automatically in this browser. Return to the menu to pause, and choose **Continue Offline** to resume the same cards and turn. Starting a new table replaces the previous saved game. A save failure is shown in the game; the previous complete save remains intact.

For disconnected play, open the full game page while connected and wait for **Available offline** beneath the table. The downloaded game can then reopen without a connection. Embedded games and the rest of the example catalog are not installed by that download. Browser storage eviction or clearing site data removes saves and downloaded assets.

**Play Online** opens a two-player Matrix table. Both browsers contribute a secret shuffle, verify the other's
shuffle, and deal five private cards to each seat without publishing either hand. The room then carries both betting
rounds, private discards and replacement draws. Live hands publish verified card openings at showdown; folded hands
stay private. The dealer can continue into another freshly shuffled hand with the button advanced.

See [Rules and provenance](rules.md) for documentary DOS rules and the explicitly chosen adaptations, and [Architecture and private online dealing](architecture.md) for implementation boundaries.
