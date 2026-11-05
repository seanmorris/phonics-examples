# Architecture and private online dealing

`Game` is the deterministic local reducer. It validates a complete intent against the current revision on a copy, verifies cards/chips, then commits once. `Hand` evaluates five cards exactly; `Profile` freezes the local rules. The reusable `Phonics\Random\RandomStream` and `Phonics\Cards\PileSet` provide rejection-sampled deterministic randomness and finite ordered/random piles.

`Session` owns menu state, the game, independent per-seat bot streams, adaptation and cosmetic thinking delays. It calls `Bot` with that seat's authorized view only. Actual stock and opponent hands never enter the policy input. `Adaptation` observes only public completed-hand results, uses integer state and changes opponent pressure by at most 40 per adjustment on its 0–600 scale, with a one-hand cooldown. It cannot modify deck randomness. Saved states retain every policy counter.

`Presenter` owns the 2D scene, selection/focus and input locking. The PHP application gives it `Session::view()` and receives intents carrying generation and revision. Presentation never owns card identity, betting legality or outcome selection. The browser sees only the local seat view; private offline state goes to the separate persistence module, not public history or debug status.

An offline snapshot contains every local hand and deck order. It is continuation data on the player's device, not an adversarially private multiplayer checkpoint. It is explicitly marked `mode: offline` and is never used for an online table.

`mental-poker.mjs` supplies the presentation-side cryptographic primitive. It maps the agreed 52-card corpus to
Ristretto255 points, lets each player apply a secret commutative scalar and permutation, and verifies every shuffle
with a transcript-bound 64-round cut-and-choose proof. Chaum-Pedersen proofs bind later layer removals to the
verified shuffle key. Each shuffle packs its proof into four bounded Matrix actions, pairing 16-round commitments
with their responses. The ten initial removals travel as two five-card layer batches. The room batches each complete
shuffle and the complete private deal into three settlement handshakes, so packet fragments do not each emit two
Matrix acknowledgements. Both clients still execute and hash every proof record before the batch boundary can settle. The
private scalar, unfinished local proof plan and decrypted faces stay in session storage; neither room history nor
the PHP view receives them.

`OnlineDeal` is the public transcript reducer. Two seated players shuffle in the shared starting-gun order, then
ten cards are assigned round-robin from the encrypted deck. For each card, the non-recipient removes and proves
their layer. The recipient removes the final layer locally and maps the point to a face. Both peers hash the same
public state after every event, while each `privateHand()` contains only that browser's five cards. Reload replays
confirmed room actions and resumes a partially published proof from the saved local plan. Incomplete durable batches
are replayed after the last confirmed boundary. The room protocol's history checkpoint mode avoids copying a large
snapshot into each action acknowledgement.

The first online milestone ends after a verified private deal and deliberately uses **Anything Opens**. Betting,
discard/draw, showdown openings, disconnect adjudication and multi-hand bankroll settlement remain outside this
transcript version; the table says so instead of accepting an unverified private-hand claim.

The persistence bridge atomically replaces one browser-local document after an accepted action. It retains the old document on a storage failure. Restore checks schema, profile version, all 52 instances, complete five-card hands, turn queue and chip/loan conservation. Corrupt or unsupported saves produce a visible recovery message instead of rerolling a replacement hand. Menus and background tabs stop bot progression.

The full poker page installs a service worker scoped to its own route. Its build manifest pins the page, PHP/WASM runtime, engine browser modules, PHAR and bootstrap by SHA-256 and size. Installation completes only after every asset is verified. Updates wait for old controlled pages to close; an open hand keeps one build. No Matrix response, private save or unrelated game's page enters the asset cache. The offline browser test disables networking, closes the page, reopens it and verifies exact saved-turn continuation.

Run `npm run test:php`, `npm run test:node`, `npm run test:poker:exhaustive`, package validation, the site build and poker browser tests for this milestone. `poker-online-room.test.mjs` drives two in-memory room clients through seating, seed exchange, both proved shuffles and all ten private draws.
