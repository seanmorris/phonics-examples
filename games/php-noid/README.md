# PHP-Noid

An original-art brick-breaker campaign built on Phonics, playable at `/games/php-noid/` or by loading `worlds/campaign.world`. Install `@seanmorris/php-noid` with `@seanmorris/phonics`, or `seanmorris/php-noid` through Composer. Its PHP namespace is `PhpNoid\`. It has no Sane-Phonics code or asset dependency.

Move with the mouse, Left/Right, A/D, or a controller. Left click, Space, Z, Enter, or controller A launches, releases a caught ball, and fires equipped weapons. Right click, P, Escape, or controller Start pauses. R or controller Back/Select restarts. Fire after game over continues from the current stage; fire after the finale restarts the campaign. The HUD includes controls, a capsule legend, active powers and individual timers, and a persistent Top 10 for the current world on this browser. Sounds are original synthesized effects unlocked by a keyboard or pointer gesture. Scores remain usable in memory when browser storage is unavailable.

Enter **↑ ↑ ↓ ↓ ← → ← → B A** on the keyboard or controller to enable infinite lives for the current run (allow no more than two seconds between presses). The HUD displays **PADDLES X INF**. Losing a ball or taking a lethal hit still resets equipment and serves a fresh ball, but costs no life. The effect survives level changes; restarting or continuing resets it. Keyboard X then Z also works as the controller-button equivalent.

## Display and campaign

The complete display is 16:9: a left-side console occupies one quarter of the width beside the unchanged 4:3 playfield. Score, personal best, level name, remaining paddles, active powers, and individual countdown meters stay visible in windowed and fullscreen play. The arrangement follows the instrument-panel structure of the local `scratch/electranoid/electranoid.gif` reference using an original eight-color gunmetal rail. A separate transparent sprite atlas supplies the HUD's wide 5-by-7 block face; live values retain hidden text for accessibility. Fullscreen fits the entire display and uses the same corner mute/fullscreen icons as Sane-Phonics, supplied by the core `QuickControls` service; pointer coordinates still refer only to the playfield. The visible HUD renders through SDL/WebGL with integer-scaled bitmap text; its hidden accessibility mirror updates only when displayed values change. Closing the game restores the canvas to its original DOM position and revokes the mounted-art object URLs.

The session opens on a guarded PHP-Noid title screen with **Start Game** and **PHP Info**. SDL draws the title at the display’s native pixel resolution, using integer glyph scales and positions so browser resizing cannot erase strokes; transparent browser buttons provide mouse and accessible activation. The same title remains behind PHP Info without swapping to a differently sized copy. Mouse, keyboard, and controller navigation share the framework's menu input cadence, and a held Start/confirm press is quarantined before play begins. PHP Info captures the active WASM runtime's complete `phpinfo()` document into the framework `DomTextureModal`; it scrolls inside the WebGL playfield with arrows, Page Up/Down, Home/End, wheel, or controller input, then returns to the unchanged title selection. Gameplay and timers remain paused throughout both screens.

Ball artwork uses an original stepped circle with clear corners, no rectangular glow, and integer drawing coordinates. Simulation positions retain their subpixel precision. The game disables WebGL multisampling and requests nearest-neighbor canvas scaling, including fractional fullscreen sizes; the optional core HTML overlay acquires its context lazily so it does not override the game's rendering settings.

First Light has forty bricks and ten guaranteed capsule drops: three Expand, three Catch, two Three Balls, and two Twin Lasers. Its four lowest bricks each carry a different helpful power. Later stages introduce the other capsules and ship families. Every stage has a distinct silhouette and more than one power-up type:

| Stage | Layout |
| --- | --- |
| First Light | Open central chamber with descending wings and a low pickup row. |
| Copper Steps | Mirrored staircases with reinforced steps and diagonal passages. |
| Magnetic Field | Two open arches around an isolated center pair. |
| Prism Split | Paired diamonds above separate lower targets and two gold deflectors. |
| Laser Lattice | Horizontal crossbars, vertical rails, and interrupted gold supports. |
| Column Fire | Three aligned brick towers for column missiles. |
| Menacer Crossing | Crossing diagonal bands and wide outer flight lanes. |
| Verdant Circuit | Split chamber with green targets and open access from below. |
| Destroyer Orbit | A reinforced ring around an open center with two gold deflectors. |
| Pulse Finale | Four reinforced sectors connected around a broken central cross. |
| Copper Cascade | A reinforced cascade with open space on alternating sides. |
| Prism Gardens | Three separated diamond gardens for multiball. |
| Flux Gates | Paired gates and outer gold deflectors with a center channel. |
| Solar Sails | A reinforced sail spreading above a narrow lower mast. |
| Missile Wells | Aligned deep wells with missile pickups on the approach. |
| Silver Spiral | An open silver spiral wrapped around a copper core. |
| Crossfire | Crossing reinforced diagonals under combat ships. |
| Greenhouse | Green target clusters with a Red Menacer and rescue support. |
| Ion Bridges | Broken bridges with gaps between gold supports. |
| Twin Reactors | Twin reactor chambers above scattered approach targets. |
| Copper Crown | Crowned copper tiers and two separated lower staircases. |
| Shard Storm | Offset diamond shards with broad flight paths. |
| Night Watch | A defensive chevron under Destroyer and Annihilator pressure. |
| Core Breach | A broken reactor shell with green targets and missile access. |
| Last Light | A final open diamond around green targets, with mixed equipment and friendly supply. |

The first ten stages each have an original 640-by-480 pixel-art background authored for its theme: First Light's orbital dawn, Copper Steps' foundry stairs, Magnetic Field's paired flux arcs, through Pulse Finale's distant pulse engine. The stage maps carry these as ordinary Tiled image layers with package-relative URLs. Stages 11–25 revisit these illustrated environments with new layouts and encounters. All ten images are opaque and quantized without dithering to the same eight dark blue-gray colors; the brightest background value is `#20344d`, preserving at least 3.3:1 contrast with the primary green, teal, gold, and red game pieces. Nearest-neighbor texture and canvas scaling retain the pixel grid.

Clearing a level or collecting Stage Exit starts a 0.3-second fade-out. The map changes under full cover, followed by a 0.4-second title hold and 0.35-second reveal. Gameplay and power timers freeze during the transition; a slow load remains covered, pause stops its clock, and held fire cannot launch the next serve. The transition adds one rectangle to the existing render batch and uses the same session cleanup as the game.

## Capsules

Compatible powers stack with independent timers: paddle size, Catch, a weapon, Power Ball, and ball speed can all be active together. Only Expand/Shrink, Fast/Slow, and Lasers/Missiles conflict; collecting one replaces its counterpart without changing other equipment. Collecting the same power refreshes only its own timer. Split, Extra Life, and Stage Exit preserve equipment until the life or stage ends. Temporary effects expire individually and all reset on life loss (including game over) or stage changes. Definitions, conflict groups, durations, colors, and labels live in `assets/capsules.json`.

| Tiled capsule | Effect |
| --- | --- |
| `expand` | Widens the paddle to 132 pixels. |
| `shrink` | Narrows it to 52 pixels. |
| `catch` | Returning player balls stick to the center of the paddle; its tips still reflect. Fire releases them. |
| `split` | Splits one player ball into three, launching held balls. Any surviving player ball preserves the life. |
| `laser` | Twin blasters. Each shot deals one hit to a brick or ship. |
| `missile` | Clears the impacted vertical column; deals three laser units to ships in that column. Gold and green metal survive. |
| `power` | The player ball passes through and destroys breakable bricks and ordinary ships. |
| `fast` / `slow` | Temporarily multiplies player-ball speed by 1.35 / 0.7. Effects replace each other. |
| `extra` | Adds one life. |
| `exit` | Advances to the next stage, or completes the final stage. |

Continuous collision queries resolve the first contact even at high ball or projectile speeds. Gold bricks are permanent obstacles. Ordinary, copper, and silver bricks take one, two, and three hits. Green metal requires red energy and awards no points. If green bricks remain after their last red ship or ball disappears, a red rescue ball attaches to the paddle: fire to launch it. A board cannot become permanently blocked by losing its red source.

## Ships and energy

Ships descend and move across the arena. Ordinary ships take three laser hits or one missile/player-ball impact. Tiled can configure stronger ships with `hits`. Paddle contact retires a ship without scoring.

| Family / effect | Behavior |
| --- | --- |
| `menacer` / `cyan` | Its energy temporarily hides the paddle, leaving a small shadow. |
| `menacer` / `copper` (also `bronze`) | Energy changes eligible bricks to two-hit copper and removes their capsules. |
| `menacer` / `silver` | Changes eligible bricks to three-hit silver and removes their capsules. |
| `menacer` / `green` | Changes eligible bricks to green metal and removes their capsules. |
| `menacer` / `red` | Energy destroys green metal; other bricks reflect it unharmed. |
| `menacer` / `pewter` | Slow decoy balls. |
| `destroyer` | Aims lethal projectiles at the paddle. A hit costs a life even with multiball or Catch. |
| `annihilator` | Lethal projectiles steer toward the paddle at a bounded turn rate. |
| `sympathizer` | A fast friendly ship that drops its configured helpful capsule. |
| `floater` | A moving obstruction without an attack. |

Menacer energy is visually marked and remains separate from player balls: it can bounce from the paddle, but never sustains a life or earns player brick points. Destroyer and Annihilator attacks are separate projectile components, outlined in red. Menacers award 250 points; combat ships award 500. Friendly ships award none.

## Authoring and modules

Import `phonics.json` in Tiled and choose `@php-noid/game`. `#factories` independently registers brick, paddle, and enemy factories. `#brick`, `#paddle`, and `#enemy` export their templates and typed defaults; `#campaign` and `#firstStage` export authored resources. PHP classes and Tiled classes use `PhpNoid`.

Bricks expose `hits`, `unbreakable`, `material` (`normal`, `copper`, `silver`, `green`), `capsule`, and `score`. Enemies expose `family`, `effect`, `interval` in seconds, `hits`, and `capsule` for friendly drops. Maps use a `name` property for the HUD. The 25 original stages progressively introduce all eleven capsules, six Menacer effects, and the additional ship families. Edit the maps in Tiled or regenerate them with `node generate-stages.mjs`. Regenerate the sprite atlas with `php generate-font.php`. From the repository root, `node scripts/manifests.mjs` refreshes production file lists and integrity hashes after an asset or module changes; `npm run generate` also invokes it after rebuilding generated assets.

After three consecutive indestructible-surface bounces, a nearly horizontal or vertical player ball gains a small escape angle while retaining its speed. A bounded contact history also detects diagonal cycles, including Laser Lattice's corner-to-gold orbit, with a fallback after twelve contacts without progress. The correction points away from the surface and still uses continuous collision checks; it never destroys gold, costs a life, or changes enemy-ball behavior.

Paddle, ball, capsule, enemy, projectile, and stage systems register separately through the framework context. Input state, rendering, loading, and browser UI remain separate from those systems. Stopping a session removes its HUD, listeners, audio, and scoped resources. Ordinary stage transitions preserve the game lifecycle and retire imported stage entities.

## Reference provenance and scope

The local reference archive is `scratch/electranoid/Electranoid_DOS_EN.zip`. Its `ENOID.RES` contains embedded `INSTRUCT.TXT` and `HELP.TXT` resources. Inspection confirms eleven pellet actions, six Menacer colors, three-hit lasers versus one-hit missiles for ordinary ships, and red energy's role in clearing green bricks. The separate `README.DOC` covers system requirements and troubleshooting. The supplied AI summaries are not treated as official documentation.

Archive inspection also found ten level resources, `LEVEL000.RMP` through `LEVEL009.RMP`. The RES directory begins with a little-endian 16-bit count of 39, followed by 22-byte records: a name-length byte, twelve filename bytes, a 32-bit little-endian offset, a 32-bit size, and a flag byte. Level payloads start at byte 141538, each 238 bytes long. Interpreting those bytes as fourteen columns of seventeen cells produces coherent symmetric layouts with empty lower rows. This grid orientation is inferred from the data; individual numeric brick codes were not decoded or assigned historical gameplay meanings.

The inferred silhouettes include a widening fan (`000`), three detached clusters (`001`), diagonal bands (`003`), an hourglass (`005`), a tapered chamber (`007`), and a nested central enclosure (`008`). These informed the use of negative space, separated targets, and firing lanes in the new campaign. The generator defines its own ten-column layouts and capsule placement; it never reads the archive. The GIF is a separate visual reference and is not claimed to depict a particular decoded level.

Sympathizers, Floaters, and homing Annihilators are PHP-Noid variants informed by the supplied references; those names and details were not confirmed in the embedded instructions inspected here. Timed effects, original layouts, scoring for ordinary bricks, and the green-brick rescue are PHP-Noid design choices. This package contains 25 original stages, no copied DOS artwork, sounds, level data, or manual text, and no claim of recreating the original 100-stage campaign or cinematics. Scratch material is excluded from distributions.

`tests/run.php` covers simulation, power stacking, conflicts and independent expiration, continuous collision, ship attacks, recovery, and campaign lifecycle. Browser tests cover input, pause, score persistence, progression, cleanup, and packed distributions without Sane-Phonics downloads.

## Collision performance

Each playfield owns a `CollisionWorld`. Balls and weapons share persistent brick and ship geometry. Registry additions and removals update shapes immediately; each collision system checks mutable bounds before its first cast and reindexes only changed targets. Damage and material changes retain the same geometry. Separate small indexes preserve arena, paddle-top, and hostile-projectile collision rules. Closing the context releases geometry and registry listeners.

PHP-Noid mixes the framework `ImageRenderer` for the current Tiled background with its batched `RectRenderer` gameplay pass. Each rendered frame performs the required image-program and rectangle-program bindings; the rectangle batch submits every game primitive in one draw. Stage changes close the retired image renderer after the new map is ready, and graphics restoration rebuilds both renderers. A regression test bounds the program switches and verifies that both passes resume after context loss.

From the repository root, run:

```sh
npm run bench:php-noid
npm run bench:php-noid:browser
PHONICS_PERF_BUDGET=1 npx playwright test packages/php-noid/tests/browser/performance.spec.mjs
```

The browser benchmark mounts core and PHP-Noid directly into PHP-WASM. Its forty-brick fixture uses a fixed four-row arrangement so campaign edits do not change the workload. Both benchmarks accept `--iterations` and `--samples`; defaults measure 200 individual updates after warmup with a monotonic clock. Every update verifies movement and entity counts; the destruction case also verifies scoring and reflection. Timings include geometry synchronization, impact removal, and fixture resets. The destruction case includes creation and removal of nine targets per update. These collision timings exclude rendering; the sustained-play browser test measures complete frame callbacks, rendering, HUD updates, and fullscreen.

Recorded on 2026-10-21 with PHP-WASM 8.4.1 (wasm32), Chromium 152.0.7977.75, and an Intel i7-7700K:

| First-stage workload (40 bricks) | Median / p95 |
| --- | --- |
| One ball | 0.4 / 0.6 ms |
| Three balls | 0.4 / 0.6 ms |
| Three balls and six lasers | 0.5 / 0.7 ms |
| Repeated wall bounces | 1.0 / 1.4 ms |
| Moving ships and paddle, three balls and six lasers | 1.4 / 1.8 ms |
| Nine destroyed targets per update | 3.4 / 4.9 ms |

With rendering, HUD, three moving ships, multiball, and sustained laser fire, 240 measured frames per mode produced a frame callback p95 of 8.9 ms in both modes, with 60.0 FPS windowed and 57.4 FPS fullscreen. Neither mode hit the catch-up cap; the largest update count was two. The test uses an expanded paddle and durable targets while retaining the 60 Hz simulation interval and eight-update catch-up cap.

CI runs deterministic geometry-reuse and lifecycle regressions, validates benchmark outcomes, and retains timing reports. `PHONICS_PERF_BUDGET=1` additionally enforces frame callback p95 below 16.7 ms and observed presentation above 55 FPS on an idle reference machine; shared CI runners report timings without that hardware-dependent gate. Benchmarks and tests are excluded from runtime packages.

The frame counter includes rendered frames between fixed simulation ticks, and a regression check limits sidebar DOM mutations while extended powers count down. Raw reports remain development artifacts and are excluded from runtime packages.
