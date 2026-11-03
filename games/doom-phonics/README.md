# Doom-Phonics

`@seanmorris/doom-phonics` is a source-format adapter, renderer, and reusable set of first-person systems for Phonics. It parses IWAD/PWAD resources and classic binary maps in native PHP and does not depend on Sane-Phonics or a Tiled runtime.

Player and author guides live in [`docs/`](docs/index.md): [the launcher](docs/launcher.md), [WAD and DECORATE support](docs/wad-and-decorate.md), [multiplayer](docs/multiplayer.md), and [rendering](docs/rendering.md).

The browser host resizes the SDL/WebGL framebuffer to the canvas's physical display size, including fullscreen and high-density displays. Native resolution is the default. The Episode system set's `renderScale` option defines the alternate Classic resolution scale; `0.5` renders the three-dimensional scene at half width and height, upscales it with nearest sampling, and keeps the weapon, status bar, menus, and screen wipes at the full output resolution. Indexed WAD textures and sprites retain their blocky nearest-neighbor sampling in both modes while Native keeps polygon and billboard edges sharp. The renderer batches static map geometry, visible masked actors, and the HUD separately; it also uses BSP sectors, the WAD REJECT table, and camera-frustum culling before expanding sprite vertices. A compact depth-only mesh preserves finite sky ceilings and shared-sky portal clipping without drawing seams over the panoramic sky.

Its optional `movement` object defines `maxSpeed`, `acceleration`, `gravity`, `maxStep`, and a `friction` multiplier per 35 Hz simulation tic. Player motion accumulates in the ECS `Velocity` component, falls between sector planes, and slides along a swept circular body rather than resolving only its final position.

Episode 1 linedef specials are interpreted as typed `LineAction` definitions. Doors, lifts, floors, stairs, and donuts run as `SectorMover` ECS components with one explicit lock per sector plane. Walk, use, and gun triggers preserve their repeat and front-side rules; switches, buttons, scrolling walls, teleports, damage floors, secrets, and animated lights update the same live state consumed by collision and rendering. Map geometry stays static while small recorded VBO spans and the sector data texture carry visual changes to GLES.

World effects use the WAD's DMX samples through a map-scoped audio service. Door, platform, switch, lock, and teleport sounds are attenuated and positioned relative to the player, cached across maps from the same WAD stack, and released with the game session. Browser effects use Web Audio; Ogg music uses browser media playback so connection work and PHP frames cannot stall its audio callback. Native consumers retain the SDL_mixer fallback. The service keeps only the active music track resident and selects `D_INTRO` for the title and `D_ExMy` for episode maps. The repository demo mounts `DOOM1_OGG.wad` after the base IWAD, so the game owns the title mapping directly and does not require a duplicate `D_INTROA` lump. MUS parsing remains available through `MusScore` for consumers that provide a sequencer.

DECORATE actions dispatch into ECS combat primitives. Weapon fire creates an independent `WeaponOverlay` entity for the `Flash` state, applies `A_Light0`–`A_Light2` through `ViewLighting`, and retires the layer when its state flow completes. Actor actions cover the reference renderer's hitscan, projectile, charge, explosion, respawn, floor-clip, pain, death, and boss behaviors. Inline `Goto`, `Loop`, `Wait`, and `Stop` edges remain attached to the frame that declares them, so alternate chaingun and plasma flashes do not run as one long sequence.

Weapon banks combine `Player.WeaponSlot`, each weapon's `Weapon.SlotNumber`, and embedded or loose `KEYCONF` documents in that order. KEYCONF `setslot`, `addslot`, and `addslotdefault` declarations append to the effective banks; the first case-insensitive occurrence of a weapon name keeps its assignment so layered add-ons cannot duplicate or move an already configured weapon.

The optional WRW compatibility harness accepts a locally supplied archive and keeps the parser and slot assertions independent of whether the WAD ships with the repository:

```sh
php packages/doom-phonics/tests/wrw-stress.php /path/to/wrw.wad [/path/to/base.wad]
```

The demo worlds load [SKULLTAG_ACTORS.lmp](demo/SKULLTAG_ACTORS.lmp) as a standalone resource with `role: "decorate"`. Its 103 actor definitions are read directly as text, without a WAD container. It precedes `SKULLTAG-WEAPONS.DEC` so the existing weapon definitions retain precedence. Maps, sprites, and sounds still come from the selected WAD stack; the online world pins the actor lump's integrity so both peers use the same definitions.

[wiki-actors.dec](resources/wiki-actors.dec) fills missing Doom, Doom II, and Skulltag classes and their referenced dependencies. It loads with the built-in definitions before WAD and sidecar overrides. The import includes the Doom II monsters, their projectile/effect actors, stealth variants, scripted marines, team items, and missing powerup parents. Definitions supply properties and states; native actions, invasion spawning, scoring, and ZScript-only features still require engine implementations.

The XML backup, extractor, its tests, and the full extraction report are maintained in a separate import workspace outside this repository. Building the game uses the generated definitions directly and does not require the backup or extractor. The import chooses the newest complete DECORATE definition for each class, expands wiki links/templates, and restores editor numbers from page metadata. It excludes existing classes and tutorial examples, rejects unresolved markup and number conflicts, and records historical fallbacks. Only the Doom/Skulltag subset and required dependencies ship with the game. [Source revisions and transformations](resources/wiki-actors.sources.json), [attribution](resources/wiki-actors.NOTICE), and the [wiki license](resources/wiki-actors.COPYING) accompany the runtime data.

The game opens on a WAD-rendered title menu. Press Enter or the controller A button to select, use the arrows, WASD, or D-pad to move, and press Escape, P, or Start to pause. The pause menu can resume, restart the current map, start a new game at any of the five skill levels, show controls, or return to the title. Its options change look sensitivity, vertical inversion, Native or Classic render resolution, and Perspective or Classic pitch projection without rebuilding the active map.

**WAD Stack** opens the **Game launcher**. Drop every archive into one list, drag or use the arrow controls to set its order, then choose a starting map, mode, and skill. The first archive must be an IWAD. Every later archive uses normal last-archive-wins lookup regardless of its IWAD or PWAD header, so a later complete archive can contribute weapons, sprites, sounds, and other resources while an earlier archive supplies the selected map. **Start locally** runs Single Player, Co-op, or Deathmatch with one local player. The chosen mode controls spawn selection and Doom/Hexen object flags independently of the network connection. **Play online** opens the lobby with the same stack, map, and compatible settings for a two-player offer. Local file bytes and object URLs remain in the browser session. A failed map load reports its error in the launcher and recreates the previous map with its previous mode and skill so another selection can be tried; it does not preserve in-progress gameplay state.

Single Player requires a normal player-one start. Co-op prefers numbered player starts; Deathmatch prefers deathmatch starts. Multiplayer modes can also use team markers on arenas such as D2ST9, assigning occupied slots across the available bases. To explore D2ST9, select **Deathmatch** or **Co-op**, then **Start locally**. These modes do not implement Skulltag team scoring, objectives, or ACS scripts.

**Online Play** contains separate **Enter Lobby** and **Networking** pages. Networking edits and persists the Matrix homeserver and lobby channel through the native Phonics menu before a connection begins. The lobby opens directly into chat or a compact Matrix OAuth action. **Game settings** opens the same launcher as a modal above chat, and offering or accepting a match uses that modal before sending the response. The game never handles a Matrix password. Match offers carry the selected map, archive order, safe filenames, profiles, sizes, and integrity digests. Locally owned archive URLs and bytes are not transmitted; each player selects their own copies. A compatible freely distributable profile may satisfy the first stack entry, while every later override must match exactly.

The host simulates every monster and shootable thing; guests present their replicated state without running AI or damage. Each guest predicts its own movement and replays it over the host's result, so the host's pushback reaches it. The host also simulates projectiles from both players' fire commands and replicates their stable identities, poses, and DECORATE animation state. The same path handles rockets, plasma, BFG balls, bouncing grenades, monster missiles, and custom projectile definitions. Guests display those missiles and explosions without running collision or damage actions again. Railgun beams replicate their world endpoints, width, duration, and fade progress; the host resolves rail damage and line triggers once. Guests continue fading between snapshots without restarting the effect or creating a second predicted beam. Local weapon animation responds immediately; the guest's projectile or beam appears when its host snapshot arrives. These effects are removed by host despawn snapshots and are owned by the active map scope. Ordinary hitscan puffs use a separate combat path.

Starting or restarting a game, changing maps, warping, and returning to the title use the classic staggered-column screen melt. A session-owned compositor retains the outgoing complete frame while map-owned render resources are replaced, then reveals the incoming 3D scene, HUD, or title at the original 35 Hz logical timing. Gameplay input and simulation remain paused until the transition finishes.

Exiting a map opens the WAD's intermission screen with kill, item, and secret percentages, the level time, and the classic par time for Doom and Doom II maps. Fire, Use, or menu confirmation completes the tally and then continues. Normal and secret exits follow the original episode and Doom II routing, including the `ExM9` and `MAP31`/`MAP32` secret levels; the game returns to the title when the next map is not in the WAD stack or the episode ends.

During play, WASD or the left stick moves forward/back and strafes, the left and right arrows or right stick turn, and the mouse looks. Left click, Space, or controller RT/R2 fires; right click or controller LT/L2 uses a weapon's alternate fire. **E** or controller A/Cross opens doors and uses switches. Number keys 0–9 select weapon slots; the mouse wheel and D-pad cycle owned weapons (left/down for previous, right/up for next). The Controls screen shows button hints for the last-used input device.

Classic keyboard sequences are recognized during play. `IDFA` and the accepted `IDFFA` alias grant weapons, full ammo, and armor; `IDKFA` also grants every key. `IDDQD` toggles damage immunity, while `IDCLIP` and `IDSPISPOPD` toggle collision. `IDBEHOLD` shows the power suffixes and `V`, `S`, `I`, `R`, `A`, or `L` toggles its corresponding power. `IDCHOPPERS`, `IDMYPOS`, and `IDCLEV` followed by an episode and map digit from 1 to 9 are also active; `IDCLEV` reaches only Doom-style `ExMy` maps. With a Railgun in the active WAD stack, `IDOOPSALLGIBS` leaves the player with that weapon and infinite cells and redirects gun-bearing monsters to runtime rail attacks. It changes live state rather than replacing DECORATE. Cheat input is matched incrementally without taking ordinary movement keys away from the input system. Cheats are disabled in online matches.

Lethal damage now enters the player's DECORATE death sequence while the simulation continues. Control and the view weapon are removed, the camera lowers, the player scream and held red PLAYPAL effect play, and Fire or Use restarts after the death delay. In an online match the level keeps running: the host respawns the player at a start with a fresh loadout.

The repository demo uses the unmodified shareware data file under its own redistribution terms. That data is not part of the published npm or Composer package.

## Portal demonstration world

The checked-in [portal demonstration](demo/portal-demo.phonics-world.json) loads the generated [PORTALS.WAD](demo/PORTALS.WAD) over the shareware IWAD. It chains six rooms through paired passable `Line_SetPortal` walls, uses a seventh off-route room for the Skulltag 9077/9078 stacked-sector pair, and an eighth for the reciprocal `Sector_SetPortal` floor and ceiling views. Keeping both plane-portal destinations outside the line route prevents one actor from appearing through multiple overlapping paths. The rooms deliberately use different floor heights, light levels, and decorations so incorrect camera transforms are visible immediately.

The same generated PWAD includes a `CAMERAS` map used by the [camera and mirror demonstration](demo/camera-mirrors.phonics-world.json). It declares an `ANIMDEFS` `CAMERATEXTURE`, binds a 9025 `SecurityCamera` with a constant `SetCameraToTexture` call in an `OPEN` source script, and reflects the player view through Hexen special 182 (`Line_Mirror`). Its north wall uses the custom `DOMTEXTURE` declaration to display a live browser-rasterized `phpinfo()` document. Raised repeatable switches below the display use `ACS_Execute` with the safe constant `ScrollDomTexture` source subset to scroll that surface. The WAD only names, sizes, and controls the surface; trusted markup is registered by the game host and cannot be supplied by archive data. Camera things remain structural map objects and do not spawn gameplay actors.

The PWAD also contains `PORTAL2`. Its [direct-launch world](demo/portal-rooms.phonics-world.json) joins two large rooms through a recessed center floor/ceiling portal and a reciprocal pair of passable hallway portals. The rooms occupy separate map-space components, making the map a focused test for nested plane and line portal rendering.

`POOL3D` adds a floating solid platform and stepping stones above one swimmable pool, using Hexen `Sector_Set3dFloor` (160). The [pool world](demo/pool-3d.phonics-world.json) starts at the north rim. Look up/down while moving forward to swim; the submerged staircase at the west side also provides a way out after draining. On the north wall, the western brown switch drains the pool and the eastern computer switch refills it. Use either with your bound Use action (E / controller A by default). Both are repeatable and take about three seconds; an active move finishes before the next command. They drive the water model's ceiling with `Ceiling_MoveToValue` (47), leaving the platform fixed.

A linked ceiling portal covers the pool at height 320 and opens into a middle gallery. Another ceiling portal at 576 connects that gallery to a third room, whose roof is at 832. Reciprocal floor openings let you look down through both galleries onto the water, stairs, and floating platform. All openings cover the same 896 × 896 footprint; the surrounding 128-unit rims stay solid. The galleries occupy separate map-space rooms 1,536 and 3,072 units north, connected by `Sector_SetPortal` (57), type 6. Portal copies share one lower ceiling link across tags 100–103, so stair and platform boundaries do not create extra views. Actors and shots can cross the full stack in either direction; solid slabs still block them. The galleries have no separate staircase from the lower room. This three-room stack exercises nested portal views and water updates seen through two portals.

Solid volumes supply top/underside/side collision and stop hitscan, projectile and sight traces. Liquid volumes use live control heights for swimming, translucent surfaces and an underwater tint. `Transfer_Heights` (209; classic 242/280/350/351) is supported separately as fake visual planes with optional swimming: its real floor remains the collision floor. The pool deliberately uses 3D floors only; transferred heights are exercised in parser/physics fixtures. Custom Boom colormap lumps, volumetric fog and full per-height lighting bands are not yet implemented.

The reusable map sources live in [tools/generate-portals.php](tools/generate-portals.php), [tools/generate-pool.php](tools/generate-pool.php), and [tools/LayeredPrototype.php](tools/LayeredPrototype.php). The pool builder returns the named Hexen map lumps for `POOL3D` and its original procedural `P3WATER` flat; the main generator assembles all five maps and writes the PWAD directory. These are development tools kept in the repository, outside the browser runtime.

To adapt the pool, edit the layout at the start of `generate-pool.php`. It starts with a 9 × 9 room of 128-unit cells and a 7 × 7 pool, then carves rectangular exit stairs into the floor. The stairs have twelve 64-unit-deep, 128-unit-wide treads and thirteen 16-unit rises, including the pool bottom and rim. Four disconnected model sectors supply the water and platforms. The model heights, textures, and lighting drive the visible volumes; target cells retain their own physical floors and gameplay boundaries. Rendering merges compatible rectangular volume faces without changing those cells.

[tools/Staircase.php](tools/Staircase.php) provides reusable `Staircase::treads()` and `Staircase::carve()` helpers. Configure the start coordinates, starting/destination heights, maximum rise, tread depth (`run`), width, and direction (`east`, `west`, `north`, or `south`). The destination can be above or below the start; a final partial rise handles heights that are not multiples of the requested rise. Landings come from the surrounding layout. For example, the pool's flight climbs west from −192 to 16:

```php
$treads = Staircase::treads(
    x: 896, y: 512, fromHeight: -192, toHeight: 16,
    rise: 16, run: 64, width: 128, direction: 'west'
);
$cells = Staircase::carve($cells, $treads);
```

The start coordinates describe the leading edge on the starting landing. Width extends along positive Y for east/west flights or positive X for north/south flights. Carving splits only intersecting rectangles and preserves sector tags, textures, and other properties; stair dimensions do not need to match the surrounding grid.

Before writing the WAD, [tools/RectangularCells.php](tools/RectangularCells.php) greedily merges adjacent rectangles with identical heights, textures, lighting, tags, and other metadata. This removes the old grid's internal linedefs and sector records: the lower room and four model sectors use 28 sectors and 91 linedefs, down from 91 sectors and 214 linedefs. Two five-sector galleries and eleven portal control lines bring the complete `POOL3D` map to 38 sectors and 134 linedefs. Different stair heights and volume tags remain separate; holes and nonrectangular regions retain the rectangles needed to describe their outlines. The two switch faces retain explicit endpoints on the merged north rim. This generation pass is separate from runtime render-mesh merging.

| Sector tag | Purpose |
| --- | --- |
| 100 | Water-only pool cells |
| 101 | Water plus the main floating platform |
| 102 / 103 | Water plus the two approach stones |
| 150 | Middle gallery floor/ceiling portals into the pool and top room |
| 160 | Top gallery floor portal into the middle gallery |
| 200 | Water model controlled by the drain/refill switches |

The four water control lines share one model and target tags 100–103. Each solid model has a single control line, so adjacent cells do not acquire duplicate volumes. The generator also writes the segs, subsectors, and BSP nodes for these rectangular cells. It splits shared edges at neighboring corners, allowing short rectangular treads to meet longer walls without collision barriers. Cells must not overlap, and the layout must be recursively separable along rectangle edges; arbitrary intersecting polygons require a more general node builder.

Regenerate the PWAD deterministically and verify it with the package checks:

```sh
php packages/doom-phonics/tools/generate-portals.php
npm run test:generated
```

For experiments, pass a different output filename to keep the checked-in demo intact:

```sh
php packages/doom-phonics/tools/generate-portals.php /tmp/PORTALS-experiment.wad
```

Regeneration replaces the entire output WAD; editor changes must first be reflected in the generator or saved to a separate WAD. Generating the checked-in demo also refreshes the world definitions’ WAD integrity hashes. Run `npm run build:manifests` to refresh package inventories, then `npm run build:docs` to repack it into the local documentation build.

The PWAD contains original map records and a generated water flat at approximately 65% opacity. Other textures come from `DOOM1.WAD`; it does not embed copied IWAD data or require Doom II for its water surface.

## Layered navigation prototype

`LAYERS` is a small playable proof of conditional room connectivity, also included in `PORTALS.WAD`. Open the [direct-launch world](demo/layered-navigation.phonics-world.json), or select `LAYERS` from the WAD stack launcher. With the documentation site running, use `/games/doom-phonics/?world=/engine/packages/doom-phonics/demo/layered-navigation.phonics-world.json` and activate the game poster.

Start in the basement and walk east across the dry cavern to the pump room. On its east wall, the southern computer switch fills the connected basin, including the basement, pump and lower passages; the northern brown switch drains it. Use E / controller A by default and let the move finish. Return to the cavern, look up, and swim forward toward its northern ledge. Its two upper doors connect the building to the west and courtyard to the east. Inside the building, take the eastern staircase north to the upper landing, turn west through the portal, then follow the gallery hallway south. The western doorway leads to an outdoor balcony with a soulsphere. The central gallery opening is a floor portal back into the room below. There are no enemies or exit switch in this navigation fixture.

[FloodBasin](tools/FloodBasin.php) selects water sectors during generation by following shared openings and wall portals below the maximum surface height. A low ceiling does not keep a connected room dry; isolated low rooms, closed openings and sills at or above the surface stop the fill. The selected sectors share one live water model, so both switches affect the entire basin. This selection assumes static openings and a common architectural water height; it does not simulate changing hydraulic connectivity or separate water levels over time.

[PortalRecess](tools/PortalRecess.php) authors 64-unit-deep roofed passages at the wall portals, with matching apertures and unchanged floor heights. The gallery's floor portal opens into a 32-unit ceiling recess below. These real sectors provide textured reveals, solid walls above the water, and visible thickness around the openings.

The cavern has four doors but two traversal regions: its lower pair connects in both directions, as does its upper pair. Dropping from upper to lower is always possible for the default player; swimming back up needs full water. The search tracks `(region, water state)`, including local fill/drain actions, and retains a witness route plus states that cannot return to the starting refuge. Putting the fill action on the inaccessible upper ledge creates a circular dependency, which the tests reject. Half-full water is deliberately insufficient.

[SpatialLayout](tools/SpatialLayout.php) pins independently authored layers using doorway and floor/ceiling anchors. Architectural coordinates place basements, storeys, and exterior spaces together; separate packed WAD coordinates keep overlapping layers apart and portals reconnect them. Contradictory anchor cycles and unpinned layers fail explicitly. [RectangularMap](tools/RectangularMap.php) writes a slicing BSP from convex rectangles; [MapConnectivity](tools/MapConnectivity.php) checks openings and portal witnesses against the emitted geometry using game collision, gravity, and switch logic. [TraversalGraph](tools/TraversalGraph.php) performs finite-state reachability and return-path checks. None of these tools run during normal gameplay.

Regenerate the map and its [interactive navigation report](demo/layered-navigation.html):

```sh
php packages/doom-phonics/tools/generate-portals.php
php packages/doom-phonics/tools/generate-layered-report.php
php packages/doom-phonics/tests/run.php
```

The report is also served at `/engine/packages/doom-phonics/demo/layered-navigation.html`. Select the start, initial water height, and whether reachable controls may be used; it highlights reachable regions and the route to the balcony, and can download the graph and physical witnesses as JSON. Regenerate the report after changing the layout. `LayeredPrototype::build(depth: 240)` demonstrates pushing the basement/cavern down while leaving the building and upper storey pinned in place.

This bounded prototype uses empty convex rectangles, translation-only anchors, one water model, and settled water states. It is not yet a random planar subdivision generator, arbitrary obstacle navmesh, or timed flood solver. General polygon rooms, more environmental state, sloped catwalks and vertical halls remain in the full Virtual Office design (plan #2228).

## Seeded layered level generator

[Seeded levels](docs/seeded-levels.md) extend the prototype into a deterministic three-layer room generator. It writes a separate `SEEDED.WAD`, world JSON and interactive report; it does not replace `PORTALS.WAD`. Room networks vary by seed, with recessed portal passages, local elevation, a flood-dependent ledge, callable lifts, and curved stairs around a solid core. Disconnected candidates gain hallways; height repairs or lifts resolve short stair approaches; layer spacing expands to retain overhead clearance.

```sh
php packages/doom-phonics/tools/generate-seeded.php --seed=first-light --rooms=5
```

Open `/games/doom-phonics/?world=/engine/packages/doom-phonics/demo/seeded-level.phonics-world.json` after rebuilding manifests, application archives and the site. The report is at `/engine/packages/doom-phonics/demo/seeded-navigation.html`. See the guide for arbitrary seeds, alternate output directories, gameplay, deterministic repair records and the exact connectivity guarantee.

## Skulltag D2ST9 stress world

The local [stress world](demo/skulltag-stress.phonics-world.json) loads **Skulltag v0.97d5's D2ST9** over Doom II. Prepare locally owned archives, start the development server, and open the printed world URL:

```sh
node scripts/prepare-doom-stress.mjs /path/to/DOOM2.WAD /path/to/Skulltag-v097d5.wad
npm start
```

The archives are copied into the ignored `demo/local-wads` directory. The documentation build includes the world definition but does not copy these archives. For a local static preview, copy that directory into `docs/dist/engine/packages/doom-phonics/demo/` after building.

D2ST9 is a Hexen-format map with 6,995 linedefs, 1,123 sectors, 4,762 subsectors, and 261 things. Examining `doom-renderer/src/Room.js` found the relevant compatibility branch in `map.format === 'HEXEN'`, particularly linedef 181 (`Plane_Align`); it does not have separate renderer implementations selected by ZDoom, GZDoom, Skulltag, or Zandronum names. Those ports share individual map/resource features, so this adapter dispatches by map format and action arguments.

The implemented subset covers the map's non-interlaced 8-bit indexed/RGB/RGBA PNG pictures, signed `grAb` origins, Hexen skill and game-mode flags, floor/ceiling slopes, plane light transfers, doors, lifts, destination-TID teleports, scrolling walls, and flickering/glowing sectors. Floor and ceiling materials prefer flats and fall back to composite wall textures, preserving their dimensions and WAD overrides; this includes D2ST9's `LITE3` light strips. Sloped heights feed rendering, player spawning and movement, moving sectors, and projectile plane intersections. Hexen actions have a separate dispatch path so their numbers cannot accidentally invoke unrelated classic Doom actions. PNG inflation uses the browser runtime's zlib extension.

This is an **arena inspection world**, not a complete Skulltag match implementation. `playerStartType: 5080` starts one player at the blue base, bypassing the ACS team-selection room. `inspectUnsupportedThings: true` records skipped actor types in diagnostics; ordinary worlds still reject missing definitions. The standalone actor lump supplies `HellScorePillar` (5020), `InvisibilitySphere` (5035), `RedParticleFountain` (9027), and `BlueParticleFountain` (9029), resolving all D2ST9 thing types. Their definitions do not implement the native scoring, translucency power, or particle emission behavior. ACS `BEHAVIOR` scripts, team scoring and resets, `Line_Horizon` (9), `Line_SetIdentification` (121), and `TranslucentLine` (208) remain unsupported. ACS-controlled gates and switches, scripted panning, and team objectives therefore do not operate. Unsupported action counts and skipped thing types are exposed in the session diagnostics.

The slope and action argument semantics were checked against GZDoom's [slope loader](https://github.com/ZDoom/gzdoom/blob/master/src/maploader/slopes.cpp), [action implementations](https://github.com/ZDoom/gzdoom/blob/master/src/playsim/p_lnspec.cpp), and [static specials](https://github.com/ZDoom/gzdoom/blob/master/src/maploader/specials.cpp).

Run the browser benchmark against an already running local server:

```sh
node scripts/bench-doom-browser.mjs \
  --views=packages/doom-phonics/benchmarks/d2st9-views.json \
  --seconds=8
```

The default URL uses port 9130; pass `--url=http://...` for another server. The harness starts a new game, samples the blue base, rotates in the central arena, and walks through a ramp at both Native and Classic resolution. It writes frame samples, summary JSON, and screenshots under `scratch/d2st9/performance`. Frame intervals are measured at each animation frame; PHP CPU timings are the latest 10 Hz diagnostic sample. Headless Chromium uses SwiftShader by default; `--software=false` removes that override, and the report records the actual GL renderer. Treat software-renderer results as comparisons within that environment, not hardware-GPU frame-rate predictions.

Browser diagnostics are available as `session.doomState` (`window.previewSession.doomState` in the preview host). They update in memory instead of rewriting a canvas data attribute. Accessible status text and the PHP-info back button only change when their displayed state changes. A [five-second DOM trace](benchmarks/d2st9-dom-updates.json) recorded 117 mutations during idle D2ST9 gameplay before this change and zero afterward, with 39 diagnostic updates in both samples.

Startup now indexes sprite frame/rotation lookup once, resolves repeated DECORATE frames once per atlas, and packs palette/alpha data by whole rows. A [startup comparison](benchmarks/startup-optimization.json) measured the PHP game-start phase at 12.2 → 3.6 seconds for E1M1 and 31.5 → 13.9 seconds for the D2ST9 stress world in headless Chromium with SwiftShader. Each is one fresh-context observation per version; remaining work includes image decoding and map geometry construction. Native comparisons verified identical sprite selection, mirroring, atlas pixels, rectangles, and metadata for E1M1, D2ST9, and stacked MAP04, including all 2,219 D2ST9 atlas images.

A [fresh A/B benchmark of the DOM cleanup and ECS component-name cache](benchmarks/d2st9-dom-cache-optimization.json) ran each view twice for six seconds per version, in current/baseline/baseline/current order. Both versions retain the earlier WebGL optimizations. Pooled median render CPU time improved by 0.6–1.1 ms in four of six view/resolution combinations and increased by 0.2 ms in the other two. Native blue-base rendering remained 15.6 ms. FPS results were mixed, with substantial variation between repetitions; this does not establish a consistent frame-rate gain. The report retains individual runs, pooled frame percentiles, and simulation time per step. The status callback runs outside the reported render CPU timer.

On October 24, 2026, Chromium 152 with SwiftShader loaded the world in 19.1 seconds and completed all six eight-second samples without browser errors. [Recorded results](benchmarks/d2st9-swiftshader.json):

| View | Native 960×540 FPS | Classic 480×270 FPS |
| --- | ---: | ---: |
| Blue base | 15.31 | 19.09 |
| Central arena, turning | 18.17 | 21.21 |
| Ramp movement and wall collision | 13.29 | 22.08 |

The initial blue-base run averaged 4.71 FPS with a median 157.9 ms in map rendering. Material synchronization was visiting every recorded vertex span each frame. Grouping spans by sector/plane and linedef reduced that to 25.4 ms by updating only changed flats, switches, and scrolling walls.

A subsequent profile found that the GLES bridge's `UNPACK_ROW_LENGTH` query forced a graphics-driver wait before every sector texture upload. The browser now caches pixel-layout reads, invalidates them on every `pixelStorei()` call, and queries the native result after a write. Context loss and restoration clear the cache. Unchanged plane materials also avoid temporary arrays, and sector interpolation clamps its fraction once per frame.

[Before/after measurements](benchmarks/d2st9-render-optimization.json) use six-second samples at the same views and resolutions in Chromium 152 with SwiftShader. All runs completed without browser errors:

| View | Resolution | Map CPU ms, before → after | Total render CPU ms, before → after | FPS, before → after |
| --- | --- | ---: | ---: | ---: |
| Blue base | 960×540 | 26.1 → 4.0 | 34.5 → 16.1 | 13.88 → 14.84 |
| Central arena, turning | 960×540 | 29.8 → 3.9 | 36.2 → 12.3 | 10.39 → 15.46 |
| Ramp movement | 960×540 | 28.7 → 4.1 | 37.9 → 13.6 | 10.63 → 14.75 |
| Blue base | 480×270 | 15.6 → 4.3 | 23.6 → 17.8 | 14.71 → 14.84 |
| Central arena, turning | 480×270 | 16.6 → 4.2 | 21.5 → 12.6 | 23.54 → 22.27 |
| Ramp movement | 480×270 | 19.3 → 4.0 | 27.3 → 13.6 | 18.28 → 21.98 |

CPU values are medians and include driver waits; they do not measure GPU execution time. Removing the synchronous query allows graphics work to overlap CPU work, but SwiftShader still executes the draw workload on the CPU. Frame-rate gains are therefore smaller and vary by view. The map still draws 99,444 vertices with the same shaders, textures, and resolution. The ramp sample moved from `(32, 2384, 4)` to `(32, 2703.99, 32)` and stopped at the wall, exercising slope ascent and collision. `render.mapStagesMs` exposes material preparation, mesh uploads, sector preparation/uploads, sky, and draw submission separately for future profiling.
