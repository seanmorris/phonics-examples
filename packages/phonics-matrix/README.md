# Phonics Matrix provider

This optional package supplies Matrix OAuth authentication, expiring lobby presence, invitations, signaling, and
chat behind the Phonics `LobbyProvider` contract. It defaults to `https://matrix.org`; a game's networking settings
can select another homeserver and room when the host policy allows them. Login opens the homeserver's authorization page
in a popup, then resumes the running lobby through a same-origin callback. It uses authorization code, PKCE, dynamic client
registration, and refresh tokens. Access tokens are never sent through the popup message, and Phonics never asks for or
receives a Matrix password.

Matrix carries lobby and WebRTC signaling messages. Gameplay and content bytes travel outside Matrix. Signals to one peer within `signalBatch` milliseconds (250 by default) share one room event, so trickle ICE costs a few sends rather than one per candidate. A send the homeserver rate-limits waits out its `retry_after_ms` and retries with the same transaction for up to 30 seconds, instead of failing the match.

The lobby's **Sign out** action stops the live sync connection, calls Matrix logout, revokes OAuth access and refresh
tokens when the authorization server supports revocation, and removes the stored browser session. Homeserver and room
preferences remain available for the next sign-in.

## Use

Install `@seanmorris/phonics-matrix` beside `@seanmorris/phonics`. A Phonics world document (`"type": "phonics-world"`; Tiled `.world` files have no `network` block) enables it by importing this package's `phonics.json` and naming its `lobby` export, a `lobby-provider`, in the world's `network` block. A transport is still required; the core `@phonics/webrtc` export provides one:

```json
"imports": {
  "matrix": "path/or/url/to/phonics-matrix/phonics.json",
  "phonics": "path/or/url/to/phonics/phonics.json"
},
"network": {
  "lobby": {
    "provider": "@matrix/lobby",
    "options": {"homeserver": "https://matrix.org", "room": "#my-game:matrix.org"}
  },
  "transport": "@phonics/webrtc"
}
```

The provider defaults to `https://matrix.org`, but every Matrix-enabled game must declare its own normal chat room. The public `#phonics:matrix.org` address is a discovery Space and is not a lobby timeline. Current game rooms such as `#doom-phonics:matrix.org` and `#php-billiards:matrix.org` can be children of that Space. A host can override the homeserver and game room through `run(url, {network: {lobby: {homeserver, room}}})`. If the host sets `policy.signalingOrigins`, the homeserver's origin must be in that list or loading fails. The OAuth return page must be served over HTTPS or from an HTTP loopback address (`localhost`, `127.0.0.1` or `[::1]`). [`application-network.json`](https://github.com/seanmorris/php-wasm-engine/blob/HEAD/packages/doom-phonics/application-network.json) is a complete example, including player counts and match fields.

## Private invitation links

`LobbyController.createInviteLink(selection)`, `joinInviteLink(link, prepareResources)`, and `cancelInviteLink()` use optional provider capabilities. Existing direct lobby invitations still work with providers that do not implement links.

The host signs in and creates a single-use link. The recipient opens the normal game page and explicitly selects **Join game**. Opening or unfurling the URL does not register a guest or claim the link. The guest has no sign-in screen: the provider automatically obtains an ephemeral Matrix guest token on the host's selected homeserver, keeps it in memory, and never replaces a saved host session. The host's browser must remain open. A replacement link cancels the previous invitation.

The selected homeserver **must permit guest registration, guest to-device messaging, and guest membership in private rooms**. Matrix does not publish guest registration through its read-only capabilities API, so an authenticated host lobby verifies support once by registering a disposable guest and immediately revoking its token. The private-link controls remain hidden unless that probe completes successfully. The client does not substitute a mailbox, create an ordinary account, or present a CAPTCHA/login fallback. Do not assume the default `matrix.org` server enables this feature. Production pages and homeservers should use HTTPS; localhost development can use HTTP.

A versioned URL fragment carries a 256-bit capability secret and the host/device locator, never a Matrix account token, room ID, SDP, or executable URL. The host stores the frozen match invitation in a dedicated unlisted, invite-only, non-federated room. The first authenticated encrypted to-device claim wins admission. HKDF/AES-GCM separates purposes and senders, authenticates fixed-order headers (Matrix can reorder JSON object keys), and rejects replayed or altered messages. Room version 11 is selected explicitly for predictable creator power levels. Shared history lets the admitted guest read the stored invitation; guests cannot change room state, invite other users, or redact messages.

Only after local resources are resolved do the peers create WebRTC channels and exchange encrypted SDP and trickle ICE in that room. The room stays active throughout the match, including map changes and connection recovery. Matrix is the only signaling backend; WebRTC carries gameplay. A bearer link grants its holder admission, so share it privately. Matrix operators can still observe account, room membership, timing, and ciphertext metadata.

### Recovery and relay configuration

The host restarts ICE after three seconds of disconnection, immediately on ICE failure, and once more after ten seconds. A thirty-second deadline or closed data channel ends the match gracefully. Doom pauses gameplay/input while rendering and network processing continue, then uses a full-state resynchronization barrier to correct the local predicted player once. Entity IDs and reliable-event deduplication survive recovery. A Matrix outage by itself does not close working data channels.

Explicit `policy.iceServers`, `network.transport.iceServers`, or world transport ICE servers take precedence in that order, including `[]`. Otherwise each peer independently requests its own credentials from `/_matrix/client/v3/voip/turnServer`, caches them until near expiry, and refreshes them for restart. A missing relay is reported in signaling diagnostics. Synapse deployments must enable `turn_allow_guests` and configure a reachable TURN server; credentials are never embedded in the application.

### Cleanup and retention

A direct invitation to someone in the lobby lasts ninety seconds. Unused links expire after fifteen minutes. Guest registration/admission has a two-minute budget, resource selection fifteen minutes, negotiation two minutes, and connection recovery thirty seconds. These setup timers do not limit an established match.

On cancellation, expiry, setup failure, or final disconnect, the host closes admission and guest writes, disables guest membership, and redacts the stored invitation and **both peers'** signaling events. Cleanup has an independent ten-second request window, even during application disposal. Only after redaction succeeds does the host leave and forget the room. A journal scoped to the host account and homeserver records room lifecycle and event IDs, without invitation secrets, SDP, or guest credentials. Unfinished work retries while authenticated and on the next host connection. Web Locks protect another live tab's room; without locks, only explicitly pending cleanup is retried automatically.

Rooms request `m.room.retention.max_lifetime = 1800000` (thirty minutes) as a backstop. Homeserver enforcement is optional and excludes state events. Client redaction removes message content through the Matrix protocol; leaving/forgetting a room is **not** a server-side room purge. A browser crash or unavailable host/server may delay cleanup until the host returns. Host migration, reload recovery, and reusable or multi-guest links are outside this protocol.

### Verification

Run `node --test tests/packages/matrix-invite.test.mjs tests/packages/webrtc-transport.test.mjs` and `php tests/network-timing.php` from the repository root. The [real-service integration suite](../../tests/integration/README.md) uses independent Chromium contexts, guest-enabled/disabled Synapse fixtures, and TURN-only connectivity.
