# Game setup and world loading

This chapter starts with a small PHP game, then adds input, map loading, and streaming. It also identifies the demo’s startup, menus, and diagnostics so you can reuse the relevant pieces. `SanePhonics\` classes belong to the game package; `Phonics\` classes belong to the framework. File paths are relative to the Sane game directory unless they link into the framework. For repository setup and tests, use the [repository README](../../../README.md) and [contributing guide](../../../site/pages/contributing.md).

For placement templates and nested settings, see [Authoring courses in Tiled](authoring.md). The [gameplay guide](gameplay.md) lists demo controls and the other reference chapters.

## Create a minimal PHP game

~~~php
require 'autoload.php'; // Or Composer's vendor/autoload.php.

use Phonics\Engine;
use Phonics\Tiled\Loader;
use SanePhonics\Physics\{Body,Platformer,TerrainCompiler};
use Phonics\Render\{Camera,Device,MapRenderer};

$map = (new Loader)->map('/assets/level.tmj');
$device = new Device(960,540);
$engine = new Engine($device);
$camera = new Camera(0,0,960,540);
$renderer = new MapRenderer($map);
$movement = new Platformer(TerrainCompiler::build($map));
$player = new Body(80,100,'runner');
$entity = $engine->entities->create($player);

$engine->systems->add('movement',function($entities,$dt) use ($movement,$player,$engine) {
	$movement->step($player,$engine->controls,$dt);
});

$engine->start(function() use ($device,$renderer,$camera,$engine) {
	$device->begin();
	$renderer->render($camera,$engine->time * 1000);
	$device->present();
},function() use ($renderer) {
	$renderer->close();
},function() use ($renderer) {
	$renderer->restore();
});
~~~

[examples/game.php](../examples/game.php) adds sprites, camera following, reset, and statistics. PHP schedules frames through vrzno; SDL_Delay is never used to yield. Engine::tick lets another PHP host supply time. Engine::stop cancels the callback and closes the device after your cleanup callback; create a new device to restart.

### Restore graphics without rebuilding game state

Engine pauses simulation and drawing while the document is hidden or its WebGL context is lost. On restoration, Device reapplies GL state, then Engine calls the third `start()` callback before rendering. Restore each renderer there. MapRenderer restores its atlas and image layers in place; restore any SpriteRenderer that borrows that atlas afterward. WorldRenderer restores its resident maps. Restore a separately owned Atlas before its SpriteRenderer. Custom GPU resources also belong in this callback.

PHP entities, map edits, camera state, and simulation time survive context loss. Resume discards elapsed idle time and held keys. Stop removes the browser listeners and remains available while graphics are lost. If Chrome discards the whole page, it reloads with a new PHP runtime; saving game state across that reload requires application persistence.

## Register components and order systems

Registry stores PHP objects as components, indexes classes/interfaces/ancestors, and assigns monotonically increasing entity IDs. Replacing a component updates its indexes. Queries return snapshots.

~~~php
use Phonics\Ecs\Query;
$players = $engine->entities->query(Query::all(
	Body::class
	, Query::not(Frozen::class)
));
$engine->systems->add('animation',$animate,after: ['movement']);
$engine->entities->defer(fn($entities) => $entities->destroy($entity));
~~~

Query::all, any, and not compose filters. Registry::on subscribes to added/removed components. Scheduler rejects missing dependencies and cycles, then flushes deferred changes after the systems finish. ObjectSpawner maps Tiled object classes to factories returning components.

The registry evaluates boolean queries with indexed set operations and caches composed-query snapshots until a structural change. Shared ancestor memberships use counts, and query caches have fixed entry and ID budgets. The API and snapshot ordering are preserved. See the framework's [ECS benchmarks](../../../docs/ecs-performance.md) for native PHP and browser measurements and reproduction commands.

## Read input and handle focus changes

`Engine` initializes SDL game controllers and discovers pads connected before or during a run. Disconnecting a pad releases its input. Keyboard and gamepad input combine for one player; D-pad input overrides the left stick on that axis. Stick movement remains proportional after a 20% dead zone, matching moving-boxes-2. Keyboard and D-pad movement use full strength.

~~~php
$engine->input->gamepads->setDeadZone(.2); // Range: 0 <= dead zone < 1.
$horizontal = $engine->controls->axis('left','right'); // -1..1
$jumped = $engine->controls->pressed('jump');
$jumpHeld = $engine->controls->down('jump');
$jumpReleased = $engine->controls->released('jump');
$sameJump = $engine->controls->pressed(0); // Space, Xbox A, or PlayStation Cross.

// For custom bindings or separate players, read an individual SDL instance ID.
foreach($engine->input->gamepads->ids() as $id)
{
	$look = $engine->input->gamepads->axis(SDL_CONTROLLER_AXIS_RIGHTX,$id);
	$interact = $engine->input->gamepads->button(SDL_CONTROLLER_BUTTON_X,$id);
}

// Pulse the controller that most recently supplied input.
$engine->input->gamepads->rumble(120, 1, .5);
~~~

SDL supplies device mappings. `axis()` returns stick values in -1..1 and trigger values in 0..1; `button()` returns a boolean. Omitting the instance ID combines buttons from all pads and selects the strongest magnitude for each axis. IDs change on reconnect, even when the browser reuses a slot. `Controls` exposes standard button numbers 0–16, including shoulders, triggers and stick clicks, alongside the existing named actions. The right stick remains available through `Gamepads::axis()`.

### Send rumble and retain short input presses

`rumble()` uses the active controller's SDL handle, not a browser-wide heuristic.
It returns false when no controller is active, the device does not advertise
rumble, or the loaded php-sdl-wasm runtime predates the rumble bindings. The demo's
Controller rumble preference clears queued ECS pulses and
stops the active motors when disabled.

`Engine` buffers keyboard presses and sampled gamepad action presses until the next simulation step, so short taps survive frames without an update. Custom loops should call `SdlInput::poll()` each frame and pass `SdlInput::consume()` to `Controls::set()` once per simulation step. `snapshot()` previews the pending input without consuming it.

### Pause and recover input safely

Start pauses simulation while rendering and controller polling continue. `Engine::$userPaused` reports this state. Losing canvas focus clears input. After focus, tab visibility or WebGL recovery, held gamepad buttons and axes must return to neutral before they can act again. This prevents an idle tab from replaying a jump or dash. `Engine::stop()` closes controller handles before shutting down SDL. Native hosts that drive `tick()` themselves should call `$engine->input->close()` before `$device->close()`.

### Recognize timed input sequences

Observe timed codes and fighting-game inputs through the same logical surface:

~~~php
$codes = $engine->input->sequences;
$codes->add('konami', [12, 12, 13, 13, 14, 15, 14, 15, 1, 0], .75);
$codes->add('special', ['down', 'forward', 1], .25);
$codes->add('uppercut', [['press' => 2, 'held' => ['down']]]);

// In a frame callback, independently of simulation stepping:
foreach($codes->drain() as $match)
{
	// Dispatch $match['name'] to your gameplay code.
}
~~~

Sequences retain ordered keyboard taps, support chords, diagonals, charged
releases, facing-relative directions, overlapping prefixes and timing limits.
Text editing and input recovery clear partial history. The demo's Konami and
moving-boxes debug codes enable its debug panel, which also reports example
fighting inputs. See [logical input and sequences](../../../docs/input.md) for default
bindings, remapping, timing semantics, replay input and runnable examples.

## Load and edit Tiled maps

Loader accepts JSON maps (.tmj/.json), external JSON tilesets (.tsj), image collections, and explicit .world map lists. Relative paths resolve against the referring file. Assets caches reads; supply a reader callback for another storage backend.

Open `examples/assets/stream.world` or `demo.world` in Tiled to edit the demo maps. Generated JSON includes `visible: true` and `opacity: 1` on every layer. Tiled 1.12.2 imports layers without those fields as hidden and fully transparent, even when their tile data is present. The generator also writes `parallaxy: 0` for anchored backgrounds so saving in Tiled preserves their vertical behavior.

Tile rendering supports orthogonal/isometric projections, finite maps, sparse infinite chunks with negative coordinates, inherited group visibility/offsets/opacity/tint/parallax, tile animations, tileset margins/spacing/offsets, and flip flags. MapRenderer draws tile and image layers in map order; SpriteRenderer draws actors. Maps retain object metadata. Tiled text and object templates are not automatically rendered.

JSON arrays and base64 layers need no extra extension. Compressed layers require PHP zlib, loaded through the base runtime's dependency resolver:

~~~js
import * as zlib from 'php-wasm-zlib';
import {PhpSdl} from 'php-sdl-wasm/php8.4-sdl.mjs';
const php = new PhpSdl({canvas, sharedLibs: [zlib]});
~~~

The demo does not download zlib for its uncompressed maps. Tests request it for compressed fixtures. Zstd, XML TMX/TSX, hexagonal/staggered/oblique projections, non-normal blend modes, and world filename patterns are unsupported. Unsupported projections, encodings, and blend modes raise errors.

~~~php
$loader = new Loader;
$world = $loader->world('/assets/game.world');
$worldRenderer = new Phonics\Render\WorldRenderer($world,residentLimit: 8);
$worldRenderer->render($camera,$timeMilliseconds);
$map->layer('Terrain')->set(-2,3,17);
~~~

World metadata loads first; intersecting maps load when requested. WorldRenderer evicts older off-screen GPU resources beyond its residency limit. Visible maps stay resident. World::unload releases a loader cache entry. Rebuild MapTerrain after collision tile changes. Visual edits update the changed GPU slice.

## Load nearby maps and release distant ones

`WorldStream` loads maps near the camera and releases them after they leave the retained area. It checks map bounds before loading map content. The `.world` manifest loads first. PHP selects maps within a preload rectangle, requests their JSON and external tilesets, then requests tileset images and image-layer animation frames. A map becomes ready only after all dependencies are mounted. Distant maps receive no requests.

~~~php
use Phonics\Tiled\{Loader,WorldStream};
use Phonics\Render\WorldRenderer;

$world = (new Loader)->world('/engine/examples/assets/stream.world');
$stream = new WorldStream(
    $world,
    request: vrzno_env('requestAsset'),
    release: vrzno_env('releaseAsset'),
    loadMargin: 256,
    retainMargin: 768
);
$renderer = new WorldRenderer($stream);

// Each frame, before rendering. Coordinates are world pixels, from the top-left.
$stream->update($camera->x, $camera->y,
    $camera->width / $camera->zoom, $camera->height / $camera->zoom);
$renderer->render($camera, $timeMilliseconds, margin: 0);
~~~

`loadMargin` expands the requested area. The larger `retainMargin` keeps recently visited maps available around boundaries. Leaving that area drops the map from the PHP cache and releases files once no other map in the stream needs them. WorldRenderer drops the corresponding GPU resources on its next render or restoration. In-memory edits survive while retained; use `exited` to save edits and entity state if revisits should preserve them. The stream owns its Loader cache and requested files; use a dedicated loader and asset transport for each stream.

### Supply the browser asset transport

The [browser asset transport](../../../browser/AssetLoader.mjs) fetches files and mounts them in WASM. It deduplicates pending requests, cancels released requests, rejects writes from obsolete responses, and reports errors without calling back into PHP. It serves paths under one configured HTTP source and filesystem root. PHP owns proximity decisions and Tiled dependency parsing. Hosts give PHP an asset scope through `vrzno_env('createAssetScope')`; [DemoLevel.php](../examples/DemoLevel.php) passes its `request(path, retry)` and `release(path)` methods to `WorldStream`. The request callback returns `true` when mounted, `false` while pending, or an error string. Omit both callbacks for files already available to PHP.

### Handle map activation, failures, and readiness

`loaded()` returns ready maps keyed by resolved path, in manifest order. `entered` and `exited` contain this update's activation/removal changes for spawning or saving entities and rebuilding collision terrain. `pending()` lists maps that are not ready, including failures; `errors` maps paths to messages. `retry($path)` retries one failed map. Polling does not repeatedly retry failures.

Use `isReady($x, $y, $width, $height)` before moving a player or completing a teleport into loading maps. Supply the body's entire swept bounds, including its extents and proposed displacement, and keep that area inside the rectangle passed to `update()`. Wait while it returns false. Once ready, build collision terrain from the required loaded maps and use the existing continuous casts for movement. Empty space outside all manifest bounds counts as ready. Streaming does not automatically spawn entities or attach collision terrain.

### Own and release scene resources

Call `$renderer->restore()` in Engine's restoration callback. Close the renderer before `$stream->close()` in cleanup. [examples/world.php](../examples/world.php) starts the streaming scene in the shared demo session. [DemoGame.php](../examples/DemoGame.php) owns the persistent engine session; [DemoLevel.php](../examples/DemoLevel.php) owns replaceable map assets, collision, and graphics. Switching levels prepares the destination before retiring the old scene. Asset scopes share transfers and retain files until their last scene reference is released, including across WebGL recovery.

### Define bounds and change the manifest

World bounds are indexed in PHP, including negative coordinates, overlaps, and sparse distant maps. `World::entries()` queries metadata without loading maps; `World::visible()` remains the synchronous API for mounted files. Manifest filenames must be unique, with finite nonnegative bounds dimensions. Streaming requires explicit `width` and `height` in pixels. For infinite maps with negative chunks or maps with overhanging artwork, add an entry-level `bounds` rectangle in local projected pixels, such as `"bounds":{"x":-1024,"y":0,"width":3072,"height":640}`. It changes the indexed extent while preserving the entry's `x`/`y` as the map origin. Without it, bounds start at the map origin. Call `reindex()` after changing manifest bounds; recreate a stream when changing its manifest. Streaming operates on whole maps, not individual chunks within a map.

## Connect the startup flow

`Ui\Card` and `Ui\CardSequence` provide timed cards, readiness gates, guarded
skipping, fades, and lifecycle callbacks without SDL. `Ui\CardRenderer` draws
bitmap content and loading progress over an application backdrop. The demo uses
an image-only Tiled map for the title and keeps its parallax offset continuous
behind the main menu. Gameplay assets download when Play is selected.

[examples/intro.php](../examples/intro.php) starts the complete flow;
[examples/cards.php](../examples/cards.php) defines card text and timing. The
[startup guide](startup.md) covers reusable APIs, input, retries, resource
ownership, and the moving-boxes-2 reference behavior. Worlds choose startup or direct
gameplay with the `sane.intro` property; the `bootEngine()` console global exists only on the
repository's legacy demo page. In-session transitions retain the same engine.

## Connect menus and bitmap fonts

Press P, Escape, or Start in a running demo to open its pause menu. Settings
includes live text editing, stepped numbers, sliders, cycling selections,
boolean toggles, and read-only output. Submenus restore the previous selection
and scroll position. The game stays paused while the menu accepts input.

`Ui\Menu` holds declarative rows and callbacks without requiring SDL.
`Ui\PauseMenu` connects it to engine input and rendering. Keyboard, Xbox,
PlayStation, and generic numbered button glyphs switch with the last input used;
applications can choose a theme explicitly. The font reads characters, spacing,
and themed variants from annotated TSJ properties. No character positions are
hard-coded in PHP.

See [menus and font annotations](menus.md) for a PHP example, controls,
bindings, lazy submenus, graphics lifecycle, and custom appearance.
[examples/menu.php](../examples/menu.php) builds the live demo menu.

## Inspect the running game

Press **F9** with the game focused, or toggle **Settings → Debug overlay** from the
pause or main menu. The upper-right bitmap panel follows moving-boxes-2's debug OSD:
player position, ground and air velocity, grounded state, surface orientation,
collision layer, and ground/air angles. Speeds use pixels per second and angles use
radians. Camera-only scenes report **None (camera)** instead of stale player values.

The panel also shows camera position and zoom, entity count, ready/pending/failed
map counts, world draw calls and instances, tile uploads, collision casts per rendered
frame, and simulation time. World draw counts exclude the overlay and menus. Detailed
values refresh every rendered frame; glyph layouts are cached and the overlay adds two
batched draw calls using the menu's existing font atlas.

**Settings → FPS meter** independently controls the unboxed lower-left FPS/frame-interval
display, enabled by default. FPS measures browser frames, including paused menus,
and discards the idle interval after tab or graphics recovery. Both preferences
survive level changes. Reset settings restores the defaults.

The example keeps the implementation in [DemoDebugOverlay.php](../examples/DemoDebugOverlay.php).
It renders inside the canvas, so fullscreen, camera zoom, and context recovery keep
the panel in screen space. Example PHP can control it directly:

```php
$game->debug->enabled = true;
$game->debug->showFps = false;
```

`canvas.dataset.debug` contains the current display state and live rows for browser
inspection. The overlay stays hidden during intro cards and the main menu.
