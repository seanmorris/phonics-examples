# Online games and deterministic seeding

Online Play uses the public, shared-history Matrix room `#php-billiards:matrix.org`, a normal chat room listed inside the `#phonics:matrix.org` Space. A room can contain several open or active tables, while one browser plays or watches one table. Lobby conversation uses ordinary Matrix `m.room.message` text, so it remains part of the same conversation in Element and other Matrix clients. Table offers and direct challenges are readable text messages with standard HTML links to the accept experience plus a namespaced Phonics annotation. A person can therefore see and follow a challenge without first using the Phonics client.

Matrix also carries presence and the durable game transcript. It does not carry a streamed simulation and the game does not establish a WebRTC channel.

Each table starts with that annotated room message as its root event. Join, seating, seed commitment, seed reveal, readiness, action, settlement, resignation, and completion events relate back to the root with namespaced Matrix relations. Actions include an explicit sequence number, actor, and pre-action state hash, so Matrix arrival order never decides the game. Both players simulate an accepted action and acknowledge the same resulting hash before the next action can begin. The acting player also publishes a versioned checkpoint. Conflicting duplicate actions or unequal state hashes freeze the table instead of choosing one player’s state.

## Fair seed exchange

After two users are seated, each browser creates a private 32-byte contribution with Web Crypto. It publishes only a SHA-256 commitment over a length-prefixed domain containing the protocol label, root event ID, session UUID, Matrix user ID, and contribution. When both commitments exist, both contributions are revealed and checked against their commitments.

The final seed hashes a second length-prefixed domain containing the root event, runtime fingerprint, application PHAR fingerprint, rule version, and both `(Matrix user ID, contribution)` pairs sorted by user ID. Sorting makes the combination independent of who committed or revealed first. Neither participant can choose a contribution after learning the other participant’s value. A seated user who does not reveal within the 60-second reveal phase forfeits; no external randomness service is involved.

The seed selects the breaker, a legal rack permutation, imperceptible rack offsets, and initial ball orientations. The 8-ball stays in the center and opposite groups stay in the two rear corners. An 8-ball pocketed on the break derives the next rack from the same seed and an incremented rack index, with the same breaker.

## Cross-platform determinism

PHP Billiards runs inside the same `php-sdl-wasm` WebAssembly artifact on x86, ARM, and other browser host CPUs. The host page fingerprints every file declared by the runtime manifest. A table also binds the exact PHAR integrity value and `casual-eight-ball-v1` rules identifier into its seed and rejects a participant with different fingerprints.

The fixed 60 Hz PHP simulation, quantized integer inputs, canonical big-endian checkpoint encoding, and exact SHA-256 state hashes avoid host byte order and JSON-number differences. The same verified WebAssembly and PHAR bytes therefore produce the same transcript on supported host CPUs. This guarantee is for the browser’s verified php-sdl-wasm path; changing the runtime, PHAR, rules, or floating-point implementation creates a different game identity. If a conforming browser still produces a different state, the two acknowledgements expose the divergence immediately and freeze the table with an exportable transcript.

## Casual eight-ball rules

The first group ball pocketed without a scratch assigns solids or stripes, including on the break; if both groups fall, the first deterministic pocket event assigns the group. Pocketing one of your group keeps the turn. Otherwise the turn changes. A scratch leaves an open table unassigned, changes the turn, and grants table-wide ball in hand. An early or scratched 8-ball loses; a legal 8-ball after clearing your group wins. There are no called pockets, first-contact fouls, rail-after-contact fouls, or shot clock.

The same Matrix account can reload and resume its seat from room history. Spectators restore the latest mutually acknowledged checkpoint and replay later actions, but they do not contribute to the seed or acknowledge state. Their camera remains local.
