# Playing online

**Play online** on the title opens the Phonics room lobby. Sign in with a Matrix account, open a table or challenge someone, and the game starts when a second player sits down. The room picks who goes first; that player is Black.

Both players arrange their twelve pieces and press **Ready**. Black's placement is sent first, then Red's, and play begins when both have arrived. From there a turn works as it does against the computer. **Resign** in the menu concedes and leaves both players at the table; **Leave table** in the lobby's panel concedes and walks away. A player who does not answer within five minutes forfeits.

There is no server that knows both placements. The rest of this page explains how two browsers that do not trust each other play a game with hidden pieces anyway.

## What a placement has to guarantee

Red must not learn where Black's rocks are. Black must not be able to move a rock after seeing Red's challenge, and must not be able to place five. A plain commitment, such as a hash of each piece, covers the first two and not the third: nothing stops a player from committing to twelve rocks.

RPSC uses the private-deck primitive that PHP Poker uses to deal cards (`browser/mental-poker.mjs`, built from the same source). It works on points of the Ristretto255 group.

## The placement

Twelve public tokens stand for a side's pieces: four rocks, four papers, four scissors. Both browsers derive the same twelve points from the table's identity, plus a thirteenth base point.

A player's placement is one shuffle of those thirteen points:

1. The player picks a secret key and multiplies every point by it. The result of multiplying a token cannot be matched back to the token without the key.
2. The player puts the twelve results in the order of their home squares. The order is the placement.
3. The player publishes the thirteen results with a proof that they are the thirteen public points, each multiplied by one key, in some order. The proof does not say which order.

The proof is a cut-and-choose argument over 64 rounds, made non-interactive by hashing the transcript. A deck that is not a rearrangement of the twelve tokens passes with a chance of one in 2^64. The proof's hash includes the seat, so a player cannot answer with a copy of the other player's deck.

A placement with its proof is about 48 KB, and the room allows 49,152 bytes in one action, so it travels as two `placement-packet` actions of about 25 KB in one batch. The other browser rebuilds the proof from the packets and checks it before any piece appears on its board.

## A challenge

A challenge is two actions.

| Action | Sent by | Carries |
| --- | --- | --- |
| `challenge` | The player to move | The two squares, and an opening of the challenger's piece |
| `answer` | The other player | An opening of the challenged piece |

An opening names a token and proves, with a Chaum-Pedersen equality proof, that the published point on that square is that token multiplied by the same key as the rest of the deck. Both browsers check both openings, apply rock-paper-scissors, and remove the loser. The challenger then makes the turn's move.

The answer needs no decision, so the challenged browser sends it by itself.

On the board both pieces go face down again. The room's history still holds both openings, so a player who reads the raw events can recover every kind that has been shown. Remembering is a rule of the table, as it is across a real board. It is not something the protocol can enforce.

## The end of the game

When a move or a challenge decides the game, each side that still has pieces sends one `reveal` action that opens all of them. Both boards then show every survivor, as the game against the computer does, and the room records the result. A game that ends by resignation or timeout has no reveal.

## What keeps the two browsers in step

Every action names the hash of the state it was made against. Each browser applies the action to its own copy of the game and reports the hash it reached, who acts next, and the outcome if there is one. The room accepts the step only when both reports match. If a browser cannot verify an action, it refuses it and raises a dispute, which freezes the table without conceding the game.

The rules both browsers run are `Rules` in `browser/online-match.mjs`, a second copy of `Rpsc\Game`. `tests/rpsc-parity.test.mjs` has PHP play thirty random games and checks that the browser copy produces the same event, the same position, and the same legal moves after every action.

## Reloading

Each browser keeps a private record for the table in local storage: its key, its order, and any placement packets not yet sent. The record is written before the first packet is published. After a reload the game is rebuilt from the room's history, and the record supplies the player's own kinds and the key for later openings.

A browser that has lost the record can still follow the game but cannot open its own pieces, so it will forfeit on its next challenge. Records for the twenty most recent tables are kept.

The room lets one tab play a seat. A second tab on the same seat reads the first tab's record, shows the player's pieces, and sends nothing.

## Limits

- Two players, no spectators.
- The lobby's chat room is `#phonics-rpsc:matrix.org` on matrix.org. It has to exist as a public chat room before anyone can sign in to it.
- Building a placement proof and checking one each take about a second and a half on a desktop. An opening is about 200 bytes and is checked at once.
