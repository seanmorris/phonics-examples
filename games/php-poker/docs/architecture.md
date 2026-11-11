# Architecture and private online dealing

`Game` is the deterministic local reducer. It validates a complete intent against the current revision on a copy, verifies cards/chips, then commits once. `Profile` freezes the local rules, including which game is dealt.

Each game is data. `variants.json` lists, for five-card draw, Texas Hold'em and the two stud games, the forced bets, the seat limit and one entry per street: how many cards go face down, face up and to the board, which of the profile's two bet ranges the round uses, and who acts first. `Variant` exposes that table to PHP, and `Game` walks it: it posts the forced bets, deals a street, runs the betting round, and then deals the next street or settles. Draw's opener rule and its draw are the one special case, between its two streets. The online reducer in JavaScript holds a copy of the same table, and tests fail if the copies differ.

`Hand::evaluate` ranks five cards exactly. `Hand::best` finds the best five of up to seven by scoring every five-card subset, and `Hand::showing` orders a stud player's exposed cards for the first-to-act rule. A view gives each card a face, `up` or `down`: other seats see a player's `up` cards while that player is in the hand, and everything at a showdown. The reusable `Phonics\Random\RandomStream` and `Phonics\Cards\PileSet` provide rejection-sampled deterministic randomness and finite ordered/random piles.

`Session` owns menu state, the game, independent per-seat bot streams, adaptation and cosmetic thinking delays. It calls `Bot` with that seat's authorized view only. Actual stock and opponent hands never enter the policy input. `Adaptation` observes only public completed-hand results, uses integer state and changes opponent pressure by at most 40 per adjustment on its 0–600 scale, with a one-hand cooldown. It cannot modify deck randomness. Saved states retain every policy counter.

`Layout` composes every seat for a five-card fan and places the game's hand inside it, so opponents' plates and chips and the action column stay where they are when the game changes: two Hold'em cards are spread and centred, and seven stud cards share the fan's width. The human's plate alone is cut to their full hand, and the YOU disc and bank follow it. The board stands between the pot and bet plates, sized to the room the opponents leave. `Motion` deals each street card by card from the stock. `GameText` holds every word that depends on the game. `HelpPage` sets a game's How to Play copy in rows for the canvas and answers what a view starting on any row shows, where paging lands and where the scrollbar's thumb stands; the presenter scrolls through that result and the painter draws it, so both agree on what is in view. `PhpInfo` reads PHP's `phpinfo()` report, as HTML in the browser or as text on the command line, into sections that `HelpPage` sets in the same window.

`Presenter` owns the 2D scene, selection/focus and input locking. The PHP application gives it `Session::view()` and receives intents carrying generation and revision. Presentation never owns card identity, betting legality or outcome selection. The browser sees only the local seat view; private offline state goes to the separate persistence module, not public history or debug status.

An offline snapshot contains every local hand and deck order. It is continuation data on the player's device, not an adversarially private multiplayer checkpoint. It is explicitly marked `mode: offline` and is never used for an online table.

`mental-poker.mjs` supplies the presentation-side cryptographic primitive. It maps the agreed 52-card corpus to
Ristretto255 points, lets each player apply a secret commutative scalar and permutation, and verifies every shuffle
with a transcript-bound 64-round cut-and-choose proof. Chaum-Pedersen proofs bind later layer removals to the
verified shuffle key. Each shuffle packs its proof into four bounded Matrix actions, pairing 16-round commitments
with their responses. The ten initial removals travel as two five-card layer batches. The room batches each complete
shuffle and the complete private deal into three settlement handshakes, so packet fragments do not each emit two
Matrix acknowledgements. Both clients still execute and hash every proof record before the batch boundary can settle.
The current hand's private scalar and unfinished local proof plan stay in local storage, with the player's own card
ids for every finished hand as hand history; the scalar is erased once its hand finishes. The newest 20 tables keep
their records, and a record written by an earlier build to session storage moves on first load. Only one tab plays a
seat: a Web Lock per table and player admits the first tab, and any other tab watches read-only, never writing the
record, submitting, reporting or resigning. Neither room history nor the public state hash receives concealed faces
or private keys. The PHP presenter receives the current local card ids so it can draw that player's hand, but it never
receives a scalar or proof plan.

`OnlineDeal` is the public transcript reducer for the complete two-player flow. The next two paragraphs describe five-card draw; Hold'em and stud follow them. The players shuffle in the shared
starting-gun order, then ten cards are assigned round-robin from the encrypted deck. For each card, the other player
removes and proves their layer. The recipient removes the final layer locally and maps the point to a face. Both
peers hash the same public state after every event, while each `privateHand()` contains only that browser's five
cards.

Betting, folds, checks and calls are deterministic public actions. A draw publishes the encrypted allocation slots
being discarded, not their faces. Replacement cards come from the next positions of the verified stock and use the
same proved layer-removal path as the opening deal. At a contested showdown, each remaining player publishes five
Chaum-Pedersen card-opening proofs; both reducers verify the openings, evaluate the hands and award the pot from the
same transcript. A fold settles without opening either hand. The dealer can then advance the button and begin a new
joint shuffle with fresh per-hand keys. Online play is heads-up with a table-stakes bankroll, always **Anything
Opens**: a Jackpots opener's cards stay private, so nobody could check the openers. The host chooses every other offline
setting when opening a table or a challenge: ante, bet ranges before and after the draw, the total and per-player
raise caps, and wild cards, jokers included. The engine lobby asks for them in its House rules dialog, from the form
`onlineSettingsForm()` describes. Its folded sections follow the offline TABLE SETUP rows (Opponents, fixed at one;
Wild cards; Limits) with the same row summaries and limit captions; there is no Opening row, because online tables
always play Anything opens. The host's last choice is kept in local storage. The settings travel in the
table offer, so the lobby labels the table with them and a joiner sees them before accepting; a build that cannot play
them lists the table but cannot join it. They are bound into the proof contexts, the card corpus and the hashed state,
so browsers that disagree fail at the first handshake. When a browser first seats or watches a table, Poker > House
Rules (R) opens by itself. The browser evaluator ports `Hand::evaluate`, wild cards included, and
tests compare it with PHP. Single-player loans and adaptation remain offline rules.

Hold'em and stud deal street by street through the same private path, with one more step. A card the rules turn face up is dealt to one player like any other card: a stud up-card to its owner, and each board card to one of the two players in turn. Once the other player has removed their layer, the recipient must publish an `open-cards` action, the same Chaum-Pedersen opening proof a showdown uses, and betting on that street starts only after both reducers have verified it. The recipient of a board card therefore learns it one step before the other player does, but cannot change it, and a player who stalls instead of opening loses on time. At the showdown each player opens only the cards still face down. The table's settings carry the game; a draw table leaves it out so an omitted game and an explicit draw choice canonicalize identically. A build without these games lists such a table but cannot join it.

The [enforceable multiplayer rules](rules.md#enforceable-multiplayer-rules) define the online boundary. Revision v6 caps Stud antes at the smaller available stack, rejects unanswerable raises and removes a funded player's queued option once an opponent calls all-in. `pendingBettors()` is used when opening and advancing a betting round. `assertState()` checks integer bankrolls, contribution accounting, total chip conservation and valid betting queues before and after each public action; failures use the same atomic rollback as a rejected proof. Contested street-game settlement also checks equal total contributions. Draw's dead dealer ante and carried pots are explicitly separate. This rules revision is pinned in both the PHP lobby and JavaScript reducer, so peers using the older payout semantics cannot negotiate a v6 table.

Each step a player owes has a five-minute deadline: a betting or draw decision, the next deal, and the automatic
shuffle, layer and settlement steps. The engine room protocol (`actionTimeoutMs`) runs it from the Matrix server
timestamp of the last step that moved the match forward and ignores steps stamped after it. Five seconds past the
deadline, the waiting player's browser claims the match, which ends as a win on time. Inside a batch, the engine asks
the local `OnlineDeal` who acts next; it reports that with every fragment. If both players owe a step, the table
expires without a winner. The in-game sidebar counts down a step's last minute.

Reload replays confirmed room actions and resumes a partially published proof from the saved per-hand local plan.
Incomplete durable batches are replayed after the last confirmed boundary. Shuffle packets settle once per complete
shuffle, the initial ten-card allocation settles once, and a replacement draw settles after all requested cards are
privately readable. The room protocol's history checkpoint mode avoids copying a large snapshot into each action
acknowledgement.

The persistence bridge atomically replaces one browser-local document after an accepted action. It retains the old document on a storage failure. Restore checks schema, profile version, the complete profile-specific card inventory, the cards each seat and the board should hold on the current street, turn queue and chip/loan conservation. Saves made before the other games existed load as five-card draw. Corrupt or unsupported saves produce a visible recovery message instead of rerolling a replacement hand. Menus and background tabs stop bot progression.

The full poker page installs a service worker scoped to its own route. Its build manifest pins the page, PHP/WASM runtime, engine browser modules, PHAR and bootstrap by SHA-256 and size. Installation completes only after every asset is verified. Updates wait for old controlled pages to close; an open hand keeps one build. No Matrix response, private save or unrelated game's page enters the asset cache. The offline browser test disables networking, closes the page, reopens it and verifies exact saved-turn continuation.

Run `npm run test:php`, `npm run test:node`, `npm run test:poker:exhaustive`, package validation, the site build and poker browser tests for this milestone. `poker-online-room.test.mjs` drives two in-memory room clients through seating, seed exchange, both proved shuffles, the private deal, both betting rounds, draws, verified showdown and settlement.
