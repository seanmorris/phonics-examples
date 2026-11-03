# Rendering and performance

The renderer builds visible subsectors from BSP data, resolves wall spans, batches flats and walls by material, and submits sprites, particles, projectiles, beams, sky, and HUD in ordered passes. Every pass receives the same camera yaw and pitch.

Large ZDoom and Skulltag maps stress sector triangulation, portals, 3D presentation features, actor counts, and texture lookup. Static geometry and material bindings are cached after load. Per-frame DOM work is limited to diagnostics that actually changed; the canvas renderer owns normal gameplay presentation.

Hexen-format camera portals support `Line_SetPortal` visual, teleporter, interactive, and linked types, `Sector_SetPortal` floor and ceiling views, and Skulltag's 9077/9078 stacked-sector markers. Floor and ceiling links preserve the camera's height relative to the corresponding plane. Portal cameras recursively render visible portals in their destination geometry, with bounded depth and a per-frame view budget for cyclic pairs. Visible links use stable link order instead of camera distance, and the allocator divides that budget equally among visible siblings at every level, so crossing a sector cannot change capture membership or recursion depth. Portal-space actors are submitted only for the camera's ordinary connected sectors, and an unresolved front-facing portal writes depth so actors cannot float over missing geometry.

When liquid rises through a reciprocal floor/ceiling portal, its surface continues into the destination aperture. The renderer clips that continuation to the opening and retains the original texture coordinates and live model height. Each room draws its own part of the liquid, so the surface remains visible from above and below without duplicate blending. Solid floors still hide coplanar liquid bottoms to prevent z-fighting, and draining removes both parts together.

Portal and mirror views retain the actual observer's underwater color, even when their transformed camera lies outside the destination's flooded area. The color applies once through nested captures, so walls beneath the surface do not change tint as the player approaches the opening. Independent camera textures use their own camera's water state.

The checked-in [portal demonstration world](../demo/portal-demo.phonics-world.json) starts the generated `PORTALS` map over the shareware IWAD. Its eight rooms exercise bidirectional passable line portals, an isolated explicit sector floor/ceiling pair, and a stacked-sector pair with destinations separate from the line-portal route.

The same PWAD includes `PORTAL2`, launched directly by the [two-room portal world](../demo/portal-rooms.phonics-world.json). It has two 768-square rooms in disconnected map space. A recessed 256-square linked floor portal occupies the upper room's center ninth, looks into the lower room's matching ceiling portal, and permits vertical traversal in both directions. The upper room opens into an east hallway and the lower room into a west hallway; their ends form a reciprocal passable line-portal pair. This combines plane and line portals in one traversable layout while keeping the ordinary sector components separate. Regenerate the freely distributable geometry-only PWAD with `php tools/generate-portals.php` from the package directory.

`CAMERAS`, launched by the [camera and mirror world](../demo/camera-mirrors.phonics-world.json), tests `CAMERATEXTURE` declarations, a sweeping `SecurityCamera`, source-level `SetCameraToTexture`, a one-sided `Line_Mirror`, and the custom `DOMTEXTURE` extension. Camera textures use their declared capture resolution and optional `FIT` dimensions. Mirrors capture the scene from the player camera reflected across the mirror linedef. Turn left from the player start to see the live PHP-info document rasterized by the browser into the north wall. Two raised switches below it run constant `ScrollDomTexture("PHPINFO", amount)` calls through repeatable `ACS_Execute` lines; the left switch scrolls up and the right switch scrolls down.

`DOMTEXTURE name width height [FIT width height] [WorldPanning]` reserves an ordinary wall or flat material for browser-rendered document pixels. The declaration contains no markup. The game host must register a trusted `DomTexture` bridge under the same eight-character name before the WAD scope activates; an absent or temporarily unready provider leaves a black placeholder. Each document has independent contents and scroll state, shares the engine canvas through `DomTexture::fork()`, and draws into the renderer's current offscreen framebuffer without changing the caller's WebGL state. `showSurface()` fills that framebuffer edge to edge without the title, footer, margins, or backdrop used by full-screen information modals.

Use the browser benchmark [`scripts/bench-doom-browser.mjs`](../../../scripts/bench-doom-browser.mjs) to separate world-load cost, PHP simulation time, visibility traversal, draw submission, and browser composition. D2ST9 is the package stress map because it combines a large layout with extended actors and renderer conditionals. With a local server already running on port 9130 and the D2ST9 archives prepared as described in the package README, run it from the repository root:

```sh
node scripts/bench-doom-browser.mjs \
  --views=packages/doom-phonics/benchmarks/d2st9-views.json \
  --seconds=8
```

Pass `--url=http://...` to measure another server or world.

Context restoration rebuilds GPU resources from mounted archive data. Map changes and session disposal release the old map's buffers, textures, audio, DOM overlays, and scoped actors.
