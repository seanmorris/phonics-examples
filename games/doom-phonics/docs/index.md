# Doom-Phonics

Doom-Phonics adapts Doom-format archives to Phonics. It reads WAD maps and resources, builds DECORATE-driven ECS actors, runs mutable sector specials, and renders the world directly through GLES. The published package contains no commercial game data.

Its launcher stacks an IWAD with later WAD archives, whose DECORATE, KEYCONF, SNDINFO, and music lumps join the effective resources. Loose definition files come from the world manifest. Offline and peer-to-peer sessions use the same fixed-step gameplay commands.

## How to play

The [seeded layered level generator](seeded-levels.md) provides an exploration map with branching rooms, flood-controlled access, elevators, and a spiral staircase.

The demonstration starts on the shareware `DOOM1.WAD`. Choose **New Game** and one of the five skills to play its starting map, or, in worlds that show **WAD Stack**, replace the base with another IWAD you own, add PWADs, and start Single Player, Co-op, or Deathmatch from the [launcher](launcher.md). Each mode uses its own player starts, and difficulty filters keep unflagged objects while including multiplayer-only objects only in multiplayer. Explore each map, operate switches, collect keys, weapons, ammunition, health, and armor, fight the monsters blocking the route, and reach the exit. The status bar shows health, armor, ammo, keys, face state, arms, and the relevant ammo maximums; pickups and cheats update the same inventory that weapons fire from. After dying, press Fire or Use once the death sequence finishes to restart the map.

The title menu offers **New Game**, **Settings** for Input, Sound, and Video, **Read This**, and **PHP Info**. Worlds that declare a `network` section add **Online Play** and **WAD Stack**, described in [Multiplayer and replication](multiplayer.md#playing-online). The pause menu resumes, starts a new game, opens Settings, restarts the level, or quits to the title. Video settings include a Blood Splatter range from off to 200%; the default 100% produces five droplets per damaging hit.

## Controls

| Action | Xbox | PlayStation | Keyboard and mouse |
| --- | --- | --- | --- |
| Move and strafe | <img class="controller-glyph" src="../../../art/controllers/xbox-button-ls.svg" alt="Xbox left stick"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-ls.svg" alt="PlayStation left stick"> | W, A, S, D |
| Look and aim | <img class="controller-glyph" src="../../../art/controllers/xbox-button-rs.svg" alt="Xbox right stick"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-rs.svg" alt="PlayStation right stick"> | Mouse |
| Fire | <img class="controller-glyph" src="../../../art/controllers/xbox-button-rt.svg" alt="Xbox RT button"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-r2.svg" alt="PlayStation R2 button"> | Left click or Space |
| Alternate fire | <img class="controller-glyph" src="../../../art/controllers/xbox-button-lt.svg" alt="Xbox LT button"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-l2.svg" alt="PlayStation L2 button"> | Right click |
| Use doors and switches | <img class="controller-glyph" src="../../../art/controllers/xbox-button-a.svg" alt="Xbox A button"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-x.svg" alt="PlayStation Cross button"> | E |
| Previous or next weapon | <img class="controller-glyph" src="../../../art/controllers/xbox-dpad.svg" alt="Xbox D-pad"> | <img class="controller-glyph" src="../../../art/controllers/playstation-dpad.svg" alt="PlayStation D-pad"> | Mouse wheel |
| Select weapon slot | — | — | Number keys 0–9 |
| Pause | <img class="controller-glyph" src="../../../art/controllers/xbox-button-start.svg" alt="Xbox Start button"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-start.svg" alt="PlayStation Options button"> | Escape or P |

Up and Down also move, the Left and Right arrows turn, and Z, Enter, or left Ctrl also fire. D-pad left or down selects the previous weapon and right or up the next. Click the game to capture the mouse for look; while it is captured, the browser may use the first Escape press only to release it.

**Settings → Input** stores gameplay bindings independently for keyboard/mouse and controller. Capture waits for neutral input, allows alternative bindings and removal, and offers Replace, Share, or Cancel for conflicts; Escape, P, and Start stay reserved for Pause. Right-stick look defaults to a 12% radial deadzone, a rescaled 1.5 exponent response, and 180 degrees per second horizontally and 120 vertically. Controller sensitivity ranges from 0.25 to 3 and the deadzone from 0 to 30%; mouse sensitivity ranges from 0.5 to 2, and vertical inversion is optional. Look renders at display rate, and the next fixed-step command consumes pending yaw exactly once, offline and online alike. Profiles and look settings are saved in browser storage as `doom-phonics.controls.v1`; invalid entries fall back independently, and unavailable storage allows session-only edits.

## Finishing a map

Reaching an exit opens the WAD's intermission screen, which counts up kill, item, and secret percentages, then the level time and, for Doom `E1M1`–`E4M9` and Doom II `MAP01`–`MAP32`, the original par time. Fire, Use, or menu confirmation completes the tally at once; pressing again names the next map and loads it. Normal exits advance to the next map. Secret exits lead to `E1M9` from `E1M3`, `E2M9` from `E2M5`, `E3M9` from `E3M6`, and `E4M9` from `E4M2`, and each `ExM9` returns to the map after the one that led there; in Doom II, `MAP15` leads to `MAP31`, `MAP31` to `MAP32`, and both return to `MAP16`. `ExM8` and `MAP30` end the episode. When the destination is missing from the WAD stack, or the episode ends, the game returns to the title.

## Cheats

Type a cheat during active gameplay; it takes effect as soon as the complete code is recognized. The same codes can be supplied through a world manifest's `init.cheats` value, as described in [WAD stacks and launcher](launcher.md).

| Code | Effect |
| --- | --- |
| `IDDQD` | Toggles god mode. |
| `IDFA` or `IDFFA` | Grants every weapon and ammo type in the active WAD stack, fills the ammo pools, and gives 200 armor. |
| `IDKFA` | Grants the full arsenal and all card and skull keys. |
| `IDCLIP` or `IDSPISPOPD` | Toggles collision clipping. |
| `IDBEHOLD` | Shows the available power-up suffixes. |
| `IDBEHOLDV` | Toggles invulnerability. |
| `IDBEHOLDS` | Toggles berserk strength, restores health up to 100, and selects the fist. |
| `IDBEHOLDI` | Toggles partial invisibility. |
| `IDBEHOLDR` | Toggles radiation protection. |
| `IDBEHOLDA` | Toggles the computer area map. |
| `IDBEHOLDL` | Toggles the light-amplification visor. |
| `IDCHOPPERS` | Grants the chainsaw and 30 seconds of invulnerability. |
| `IDMYPOS` | Displays the player's world coordinates and facing angle. |
| `IDCLEVxy` | Warps to episode `x`, map `y`; for example, `IDCLEV19` loads `E1M9`. Both digits must be 1–9, so only Doom-style `ExMy` maps are reachable; Doom II `MAPxx` maps cannot be selected this way. |
| `IDOOPSALLGIBS` | When the active WAD stack supplies a Railgun, toggles a Railgun-only mode with infinite cells for the player and gun-bearing enemies. The mode survives death and map restarts until disabled. Without a Railgun in the stack, the cheat reports that it is unavailable. |

## Executable PHP

| File | Role |
|---|---|
| `game.php` | Creates the 35 Hz game session, input profile, menu, frame pipeline, status reporting, and lifecycle cleanup. |
| `modules/episode.php` | Exposes the ordered Doom gameplay system set to world manifests. |
| `adapters/wad.php` | Exposes the WAD content adapter used to turn archive maps and resources into playable scopes. |

## What to study

- [WAD stacks and launcher](launcher.md) explains archive selection and self-contained exports.
- [WAD and DECORATE support](wad-and-decorate.md) covers content adaptation.
- [Multiplayer and replication](multiplayer.md) describes timing and authority.
- [Rendering and performance](rendering.md) explains visibility, batches, and large maps.

The [PHP API](api/index.html) lists every public type declared by this package.
