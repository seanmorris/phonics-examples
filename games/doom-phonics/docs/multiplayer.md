# Multiplayer and replication

Doom-Phonics uses host-authoritative fixed-step simulation. Peers send compact input commands stamped with simulation ticks; the host validates ownership, command order, and an acceptance window before applying them. Snapshots replicate scoped entity and component state.

Local camera look updates at display rate while simulation remains at 35 Hz. Pending yaw is consumed exactly once by the next command without changing the displayed orientation. Batched commands accumulate accepted yaw deltas and retain the latest absolute pitch.

Player correction interpolates network-owned remote actors. The local player keeps immediate prediction and reconciles against authoritative state without treating a spawn snapshot as a fresh teleport. Projectile and beam effects use replicated identities so every peer creates and retires the same effect.

Transport close is a session event. Sending stops, the replication scope is released, and the game returns to its disconnected UI instead of allowing a closed WebRTC bridge to terminate PHP.

## Playing online

Online play is available in worlds that declare a `network` section, such as the [online episode world](../demo/episode-one-online.phonics-world.json) embedded on the documentation page. That world imports the [Phonics Matrix provider](../../phonics-matrix/README.md) as its lobby and uses WebRTC for the match connection. Matches have exactly two players and run Deathmatch or Co-op at a chosen skill; Single Player is offered only for local starts.

1. On the title menu, open **Online Play → Networking** to review the Matrix **Homeserver** and **Lobby channel**. The defaults are `https://matrix.org` and `#doom-phonics:matrix.org`, a normal chat room listed inside the `#phonics:matrix.org` Space. Edit either value, then choose **Apply Settings**; the values are saved for later sessions.
2. Choose **Online Play → Enter Lobby**. The lobby opens into chat, or shows a Matrix sign-in action that completes in a popup. The game never asks for a Matrix password.
3. Open **Game settings** above the chat to choose the WAD stack, map, mode, and skill, then offer a match to another player. The **Play online** button in the WAD Stack launcher opens the same lobby with the current stack and settings.
4. Accepting an offer uses the same launcher form. Each player selects their own copies of the archives: offers carry the map, archive order, safe filenames, profiles, sizes, and integrity digests, never archive bytes or local URLs. A compatible freely distributable base archive may stand in for the first stack entry, while every later archive must match exactly.

Closing the connection returns the game to its disconnected screen, as described above.

## Invite someone without a Matrix account

1. Sign in to the lobby on a homeserver that permits automatic guest registration. Keep the hosting tab open.
2. Select **Create invite link**, choose the WAD stack, map, mode, and skill, and copy the link. Creating another link cancels the old one.
3. The recipient opens the link and selects **Join game** on the gameplay poster. Previewing the link does not claim it. No Matrix login or account setup is shown.
4. Available resources load automatically. If a required local WAD is missing, the recipient selects their own compatible file in the indicated stack position. The host's match settings remain fixed; WAD bytes are never transferred between players.
5. The first valid recipient starts the connection automatically. **Cancel invitation** closes an unused link. Links expire after fifteen minutes without admission.

The default Matrix server is not guaranteed to allow guests. The lobby checks guest registration once and hides **Create invite link** when the selected server cannot support an anonymous recipient. Select a guest-enabled server in Networking to enable it. The receiving player is never sent through an account-registration flow. Relay support also depends on the server's TURN configuration; some networks cannot connect without it.

During a temporary connection loss, **Reconnecting…** pauses gameplay while the display and menus continue. The host attempts ICE recovery and a full authoritative state refresh before resuming. Recovery has a thirty-second limit. Closing the match schedules host-owned cleanup of both players' encrypted signaling messages; a thirty-minute room retention request is an additional, server-dependent backstop. The signaling room remains available throughout a live match, including map changes.
