# Rules and provenance

This game is inspired by SWFTE Bicycle Poker for MS-DOS. It uses original presentation and does not distribute the DOS executable, help file, art or characters.

The documentary baseline is the v2.40 `DP.WHF` help from [the archived DOS distribution](https://www.old-games.ru/game/download/15182.html). Archive SHA-256: `cd1c2de90d2a61cbc66d0eebcd325efa783d6ff620f1f02686da960ca96d8e61`. Help SHA-256: `d50e3b4c079fb328d2a3a11b12dcd83e9fa5d1e61b6fafc13671d2503fa5b2f5`.

| Behavior | Basis |
| --- | --- |
| 2–8 seats, starting with 500 chips | DOS help |
| Five-card draw, no wild cards, Jackpots default, Anything Opens alternative | DOS help; the other three games are described below |
| Dealer alone antes; first betting starts to the dealer's left | DOS help |
| A player who passes may return after someone opens | DOS help |
| Replace any or all five cards, or stand pat | DOS help |
| Final betting begins with the original opener | DOS help |
| Remaining hands are revealed at showdown; sole survivor wins without a contest | DOS help; hiding an uncontested survivor's cards is our privacy convention |
| Mid-hand credit covers a broke caller; borrowing player cannot raise; another 500 can be borrowed for the next hand | DOS help; pressing the labeled next-deal control accepts the new local loan |
| Ante 1, bets/raises 2–20, at most three raises per interval | Explicit local defaults; exact DOS defaults remain unverified |
| All-pass pot carries forward, dealer advances and antes again | Explicit local adaptation pending executable parity evidence |
| Draw order begins to dealer's left; a folded opener's next active neighbor leads final betting | Explicit local adaptations |
| Exhausted stock recycles earlier discards; the current player's new discards are withheld until replacements finish | Explicit local adaptation, tested at eight seats drawing five each |
| A qualifying opener may discard part of the qualifier; the original hand snapshot remains valid for opening | Explicit local interpretation pending executable parity evidence |
| Ace-low straight is five-high; suits do not break ties; odd chips go clockwise from dealer's left | Explicit high-poker/tie conventions |
| Online play is heads-up, Anything Opens and table stakes, with the host's ante, limits, raise caps and wild cards and a five-minute limit per turn; concealed draw choices identify encrypted allocations, and only live showdown cards are opened | Explicit decentralized-play profile |

## Hold'em and stud

The DOS help describes draw poker only. Texas Hold'em and the two stud games follow the common rules of those games, fitted to this table's spread limits and loans. None of the rows below is a claim about a DOS release.

| Behavior | Basis |
| --- | --- |
| Hold'em: two cards face down, then a three-card flop, a turn and a river on a shared board; 2–8 seats | Standard rules |
| Hold'em blinds: the seat left of the dealer posts the Limits screen's ante as the small blind and the next seat posts the early minimum bet as the big blind; heads-up, the dealer posts the small blind and acts first before the flop | Standard rules, using the table's existing two settings |
| The big blind may raise when everyone only calls | Standard rules |
| No card is burned before the flop, turn or river | Explicit local simplification |
| Five-card stud: one card down and one up, then three more up; seven-card stud: two down and one up, three more up, and a last card down | Standard rules |
| Stud: every seat antes; the lowest card showing brings in for the early minimum bet, with ties broken by suit, clubs lowest | Standard rules; the bring-in is a full minimum bet |
| Stud, later streets: the best hand showing acts first; a tie goes to the seat nearest the dealer's left | Standard rules |
| Seven-card stud seats at most seven, so the 52-card deck always covers the deal | Explicit local limit |
| Two bet ranges: the early one for Hold'em before the flop and on the flop and for the first two stud streets, the late one afterwards | Explicit local adaptation of the draw game's before-draw and after-draw ranges |
| A raise over a blind or a bring-in counts toward the raise caps | Explicit local adaptation |
| Showdown takes the best five cards of a seat's own cards and the board; a board that plays for everyone splits the pot | Standard rules |
| Loans, the single pot and the dealer rotation work as in draw | Same local profile |
| Online, the same games are heads-up and table stakes; a player who cannot cover a blind or a bring-in posts what they have | Explicit decentralized-play profile |

Wild cards are allowed in every game. In stud, wild cards among a player's exposed cards count toward the hand showing.

The evaluator exhaustively checks all 2,598,960 five-card combinations against the standard category counts. Hands of six or seven cards are scored by trying every five-card subset with that evaluator, and tests compare the result with an independent search. Money conservation includes loan liabilities: total stacks plus pot minus total debt equals the initial chips. The local loan profile has no side pots; an online table-stakes profile requires its separate all-in/side-pot implementation and reviewed settlement policy.

Single-player opponent pressure adapts automatically between completed hands using smoothed public bankroll results. It is an original Bicycle-inspired policy, not a claim about an undocumented DOS algorithm. There is no separate setting. It has no access to secret cards and does not alter the shuffled deck, hand rankings or completed results.
