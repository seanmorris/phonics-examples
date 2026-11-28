# RPSC

RPSC is Rock Paper Scissors Checkers: a checkers board, two armies that cannot touch each other diagonally, and twelve hidden checkers a side. You play the computer at one of three levels, or another person [online](online.md).

## The board

The board is an ordinary 8 by 8 checkers board with a1 a black square. Black plays on the red squares and Red plays on the black squares. Pieces move diagonally and so never leave their own color. Two consequences follow:

- Opposing pieces never share a diagonal. They cannot block each other and cannot jump each other.
- Opposing pieces can stand edge to edge, above, below, or beside each other. That is the only place they interact.

## Placement

Each side has four rocks, four papers, and four scissors. Before play, each side places its twelve face down on its first three rows. You always see your own pieces. You see an opposing piece only while a challenge shows it, and when the game is over.

The game deals you a shuffled arrangement. Tap two of your pieces to swap them and they slide across to each other's squares. **Shuffle** deals again, and **Ready** begins the game.

## A turn

Black moves first. A turn has two parts.

1. **Challenge (optional, once).** Pick one of your pieces and an opposing piece that shares an edge with it. Both are turned face up. Rock beats scissors, scissors beats paper, and paper beats rock. The loser leaves the board. If both are the same kind, neither leaves. The pieces left standing are turned face down again, so each player has to remember what was shown.
2. **Move (required).** Move one piece one square diagonally forward onto an empty square. A piece that reaches the far row is crowned and gets a gold rim. A crowned piece may also move diagonally backward.

Moving next to an opposing piece gives your opponent the first chance to challenge it, on their turn.

## Winning

You win in either of two ways:

- **Elimination.** Take every opposing piece.
- **Home row.** Stand on all four of your squares on the far row at once. The board marks them with small crowns. They lie between your opponent's four back-row squares, so your opponent can challenge the pieces that arrive there but can never occupy the squares.

The row above each side's name shows how many pieces it has left, how many far-row squares it holds, and the pieces it has lost. Lost pieces are public: both players watched each one lose its challenge.

Eighty turns in a row with no piece taken and no uncrowned piece moved end the game in a draw.

A side can always move. Only a side's own pieces can stand in its way, and a group with no free step would need a piece on every one of its four far-row squares, which has already won.

## The computer opponent

The computer is given exactly what a player would see: its own pieces, the backs of yours, and each challenge as it happens. It scores every possible challenge and move and takes the best, with some noise so games differ.

| Level | Remembers a shown piece for | Counts the kinds still in play | Noise |
| --- | --- | --- | --- |
| Easy | 6 turns | No | High |
| Normal | 30 turns | Yes | Some |
| Hard | The whole game | Yes | Almost none |

In the test suite the hard level wins 99% of its games against a player that moves at random, and 84% against the easy level.

## Controls

| Action | Pointer or touch | Keyboard | Controller |
| --- | --- | --- | --- |
| Pick up a piece | Tap it | Arrows, then Enter or Space | D-pad or stick, then A |
| Move | Tap a dotted square | Arrows, then Enter | D-pad, then A |
| Challenge | Tap a bracketed piece | Arrows, then Enter | D-pad, then A |
| Let go | Tap an empty square | Escape or X | B |
| Menu | **Menu** button | P, or Escape with nothing picked up | Start |

Every piece, every square the selected piece can reach, and every button is also a real button over the canvas, with a label such as "c3 your rock" or "d4 Red piece face down". A screen reader can read the board and play it.

## Saving

The game against the computer is stored in the browser after every action, including what the computer remembers, and **Continue** on the title brings it back. The level, the side you play, and the sound level are stored too. None of it leaves the device.

## What to study

- [Playing online](online.md) covers how two browsers hide their placements from each other, prove them, and stay in step.
- [Architecture](architecture.md) covers the rules core, the computer opponent, the table that animates each event, the renderer, the browser boundary, and the tests.

The [PHP API](api/index.html) lists every public type in the package.
