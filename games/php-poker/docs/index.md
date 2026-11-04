# PHP Poker

Play five-card draw at a classic 2D table against one to seven local opponents. The default is Jackpots: a pair of jacks or better is required to open. Anything Opens lets any hand start the betting.

Choose **Play Offline** to start a table. Each hand deals five cards, runs an opening betting round, lets you replace zero to five cards, and finishes with a final betting round and showdown. Select cards first, then confirm **Draw** or **Stand Pat**. Betting controls show the amount to call and the total you are raising to.

The dealer alone pays the ante. The local bank can lend chips when a player runs out; a player borrowing during a hand can call but cannot raise. Your stack, loans and net result remain part of the saved session.

Accepted actions save automatically in this browser. Return to the menu to pause, and choose **Continue Offline** to resume the same cards and turn. Starting a new table replaces the previous saved game. A save failure is shown in the game; the previous complete save remains intact.

For disconnected play, open the full game page while connected and wait for **Available offline** beneath the table. The downloaded game can then reopen without a connection. Embedded games and the rest of the example catalog are not installed by that download. Browser storage eviction or clearing site data removes saves and downloaded assets.

This release implements the offline milestone. Private online play remains in the canonical plan, pending its reviewed private-dealing protocol and disconnect/settlement policy.

See [Rules and provenance](rules.md) for documentary DOS rules and the explicitly chosen adaptations, and [Architecture and offline play](architecture.md) for implementation boundaries.
