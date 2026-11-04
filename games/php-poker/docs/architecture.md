# Architecture and offline play

`Game` is the deterministic local reducer. It validates a complete intent against the current revision on a copy, verifies cards/chips, then commits once. `Hand` evaluates five cards exactly; `Profile` freezes the local rules. The reusable `Phonics\Random\RandomStream` and `Phonics\Cards\PileSet` provide rejection-sampled deterministic randomness and finite ordered/random piles.

`Session` owns menu state, the game, independent per-seat bot streams, adaptation and cosmetic thinking delays. It calls `Bot` with that seat's authorized view only. Actual stock and opponent hands never enter the policy input. `Adaptation` observes only public completed-hand results, uses integer state and changes opponent pressure by at most 40 per adjustment on its 0–600 scale, with a one-hand cooldown. It cannot modify deck randomness. Saved states retain every policy counter.

`Presenter` owns the 2D scene, selection/focus and input locking. The PHP application gives it `Session::view()` and receives intents carrying generation and revision. Presentation never owns card identity, betting legality or outcome selection. The browser sees only the local seat view; private offline state goes to the separate persistence module, not public history or debug status.

An offline snapshot contains every local hand and deck order. It is continuation data on the player's device, not an adversarially private multiplayer checkpoint. It is explicitly marked `mode: offline`; no network backend is supplied in this milestone.

The persistence bridge atomically replaces one browser-local document after an accepted action. It retains the old document on a storage failure. Restore checks schema, profile version, all 52 instances, complete five-card hands, turn queue and chip/loan conservation. Corrupt or unsupported saves produce a visible recovery message instead of rerolling a replacement hand. Menus and background tabs stop bot progression.

The full poker page installs a service worker scoped to its own route. Its build manifest pins the page, PHP/WASM runtime, engine browser modules, PHAR and bootstrap by SHA-256 and size. Installation completes only after every asset is verified. Updates wait for old controlled pages to close; an open hand keeps one build. No Matrix response, private save or unrelated game's page enters the asset cache. The offline browser test disables networking, closes the page, reopens it and verifies exact saved-turn continuation.

Run `npm run test:php`, `npm run test:node`, `npm run test:poker:exhaustive`, package validation, the site build and poker browser tests for this milestone. The canonical Virtual Office plan retains separate private online feasibility, failure-policy and security review gates.
