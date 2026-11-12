# Drawing the world and effects

Draw backgrounds and terrain first, then actors and screen-space UI. The sections below cover image layers, animated sprites, composite actors, and effects that capture earlier drawing passes. Each example states which resources it owns and how to restore them.

For placement templates and nested settings, see [Authoring courses in Tiled](authoring.md). The [gameplay guide](gameplay.md) lists demo controls and the other reference chapters.

## Build scrolling and parallax backgrounds

Add Tiled image layers before the terrain. Set `repeatx`, `repeaty`, `parallaxx`, and `parallaxy` on each layer. A factor of 0 stays fixed relative to the view, 1 follows the map, and intermediate values simulate distance. Group factors multiply. Tile and image layers share Tiled's [view-center and map-origin reference](https://doc.mapeditor.org/en/stable/manual/layers/#parallax-reference-point), including maps placed in a world.

### Anchor a background stack to the viewport

For a viewport-anchored stack like tigl's backgrounds, set the image layer's custom `from` string property to `top`, `center`, or `bottom`. Position strips using `offsety`. Layers with the same anchor share a stack height computed from their image heights and offsets. `stackHeight` can override that height. Anchored strips default to horizontal repetition and zero vertical parallax; explicit `repeatx` and `parallaxy` values override those defaults.

Anchored strips scroll from world-camera coordinates. Map placement and map-local parallax origins do not change their phase. Use the same layer offsets, factors, images, and stack heights in neighboring maps to keep backgrounds aligned across boundaries. Tiled displays these images using its native layer rules; the engine applies the custom anchoring, scrolling, and frame animation during play.

WorldRenderer draws one leading anchored stack, selected from the ready map containing the camera center. While crossing a gap or waiting for that map, it keeps the previous visible ready stack, or uses the first visible ready map. Manifest order breaks ties for overlapping maps. This avoids blending the same translucent background more than once. Regular Tiled image layers remain attached to each map; image layers after terrain retain their map order.

### Scroll or animate image layers

Custom properties add motion like moving-boxes-2's strips:

| Property | Type | Effect |
| --- | --- | --- |
| `scrollX`, `scrollY` | float | Automatic image movement in pixels per second; positive values move right/down. |
| `frames` | string | JSON list of `{"image":"cloud-0.png","duration":150}` entries. Durations are milliseconds; image paths are relative to the map. |

Pass simulation time to `MapRenderer::render($camera, $milliseconds)` so scrolling and animation stop when simulation time stops. Opacity, tint, PNG alpha, and Tiled's `transparentcolor` are supported. Images keep their native proportions when the camera zooms. Repetition works with non-power-of-two images and negative world coordinates.

PHP can also create and change layers directly:

~~~php
$clouds = new Phonics\Tiled\Layer(20, 'Clouds', 'imagelayer');
$clouds->image = '/assets/clouds.png';
$clouds->repeatX = true;
$clouds->parallaxX = .15;
$clouds->parallaxY = 0;
$clouds->properties = ['from' => 'bottom', 'scrollX' => 6];
array_unshift($map->layers, $clouds);
~~~

For programmatic animation, set `$clouds->imageFrames` to an array of image/duration entries with resolved image paths.

ImageRenderer caches each image once per map renderer and draws one clipped quad per image layer. Its `draws` and `uploads` counters are available through `$renderer->images`. MapRenderer's draw count includes images; its instance/upload counts still describe tile chunks. Close the MapRenderer to release both renderers' textures. WorldRenderer draws the selected anchored stack and regular leading image layers before drawing terrain, so a neighboring map's sky cannot cover existing terrain.

The live demo includes original procedural sky, cloud, mountain, water, and shimmer strips. `php examples/generate-assets.php` regenerates them along with the demo maps.

## Draw and animate sprites

SpriteSheet loads a Tiled tileset. Tile classes/types name animations; numeric local IDs work for unnamed tiles.

~~~php
use Phonics\Render\{Atlas,SpriteSheet,SpriteRenderer};
$sheet = new SpriteSheet($loader,'/assets/player.tsj');
$atlas = new Atlas($sheet->map);
$sprites = new SpriteRenderer($atlas);
$sprite = $sheet->sprite('run',120,200);
$sprite->flipX = true;
$sprite->rotation = .2;
$sprites->render([$sprite],$camera,$timeMilliseconds);
$sprites->close();
$atlas->close();
~~~

Sprites support anchors, rotation, scaling, flips, tint, opacity, animation offsets, and black outlines. A batch shares one atlas and one instanced draw. The caller supplies painter order, for example sorting by feet Y in an isometric scene. Atlas ownership stays with its creator.

### Crop and anchor sprite artwork

Set `$sprite->crop = [$x, $y, $width, $height]` to draw an integer pixel region relative to the current tile frame. The crop must fit inside that frame; `null` draws the whole image. Cropped dimensions determine the sprite's size and anchor. Set `animated = false` to treat `gid` as a concrete frame, even when that tile also defines an animation.

Anchor the visible soles, accounting for transparent image padding. The demo's 32-pixel sprites have two transparent rows below their feet, so they use `originY: 30 / 32`. Their world anchor follows the body's rotated contact normal, keeping the artwork against floors, walls, and ceilings.

### Start and change animations

Switch an existing sprite by name with `SpriteSheet::play()`. Pass the same simulation time, in milliseconds, that you pass to SpriteRenderer:

~~~php
$now = $engine->time * 1000;
$animation = $body->grounded ? 'run' : 'jump';
$sheet->play($sprite,$animation,$now);
$sprites->render([$sprite],$camera,$now);
~~~

A changed animation starts at its first frame. Repeated requests for the current animation keep its playback position, so calling `play()` every update does not freeze it. `play($sprite,'jump',$now,restart: true)` explicitly restarts it. The method returns `true` when playback starts or restarts, and `false` when unchanged. It preserves the sprite object, position, transform, tint, and opacity, and reuses its atlas.

Tiled frame durations control looping; static tiles also work. Each sprite has its own clock offset. An animation created by `sprite()` initially follows the renderer's clock; use `restart: true` to start that same animation at a later time. Unknown names or non-finite times throw without changing the sprite. Use animations from the tileset backing the renderer's atlas.

## Control sprite outlines

Sprites have a black outline by default. The shader expands the silhouette by one world pixel along each of the sprite's local axes, following its rotation. Scaling the artwork keeps that thickness at one world pixel; camera zoom scales it on screen. Transparent gaps get the same outline, and samples stay inside the current animation frame or crop.

~~~php
$sprite->outline = false; // Disable the outline for this sprite.
$sprite->outline = true;  // Restore the default.
~~~

Each member of a `SpriteGroup` has its own flag. The renderer first draws every member's artwork, then draws their enabled outlines at a shared, farther depth. This produces one composite entity silhouette: a member's outline cannot cross or hide sibling artwork. Separate entities retain ordinary painter ordering. Artwork and outline instances still share one draw and atlas. The original artwork keeps its anchor and dimensions, so body and block collision bounds stay the same. RGB tint leaves the outline black. Source alpha, tint alpha, and sprite opacity control its fade; colored and partially transparent artwork retains its original appearance.

The default follows moving-boxes-2's actor policy. Vehicles, monitors, moving platforms, switches, springs, projectiles, effects, and particles are outlined. Regions and terrain-like structures opt out: ordinary blocks and breakable blocks, buoyant block platforms, bridge segments, and repeated rope strips remain unoutlined. The engine's offscreen water, motion-blur, and backdrop targets include depth buffers so composite silhouettes survive postprocessing captures.

`BreakableBlocks::create()` disables outlines on its sprite, and the resulting shards inherit that setting. `ParticleBurst::grid()` copies the source flag for other effects. Disable it before splitting an image when pieces should meet without black seams.

## Draw several sprites on one entity

Keep a `Sprite` component for the usual single-image entity. Attach a `SpriteGroup` when an entity needs several independently positioned or animated images. The registry still keeps one component per concrete class.

~~~php
use Phonics\Render\{Sprite, SpriteGroup};

$player = $engine->entities->create(new Sprite(7, 80, 448));
$assembly = $engine->entities->create(new SpriteGroup(
    new Sprite(1, 320, 448),
    new Sprite(5, 320, 400)
));

$group = $engine->entities->get($assembly, SpriteGroup::class);
$group->sprites[1]->rotation = .2;
$sprites->renderEntities($engine->entities, $camera, $engine->time * 1000);
~~~

Group positions are world coordinates. Each member has its own transform, crop, and animation clock. `renderEntities()` collects both component types in entity creation order, drawing a primary `Sprite` before its group members if an entity has both. Pass `entityIds: [$assembly, $player]` to choose painter order. `SpriteGroup::collectGroups()` preserves entity boundaries for composite rendering; `collect()` still returns the flat list for custom sorting or the existing `render()` API, where each supplied sprite is treated as a separate outline group. Every selected sprite must use the renderer's atlas. `SpriteRenderer::$instances` counts logical sprites rather than the internal ink instances.

## Distort the background around the player

`BackdropTwistRenderer` displaces a localized 64-world-pixel region of the map while leaving player, entity, particle, and HUD sprites intact. Call `begin()` before rendering the background, `compose()` with the field's world center and signed strength, then render foreground sprites. Changing the sign reverses the tangential field.

The demo follows moving-boxes-2's background-filter ordering and placement. Braking against retained ground momentum scales twist from tangent speed and clamps it to ±256. A held spindash charge scales it to ±120 using the retained movement direction. The field follows the player's support point with the reference effect's separate braking and spindash offsets; it never changes the body or sprite pose.

~~~php
use Phonics\Render\BackdropTwistRenderer;

$twist = new BackdropTwistRenderer($device->width, $device->height);
$twist->begin();
$mapRenderer->render($camera, $milliseconds);
$twist->compose($camera, $centerX, $centerY, 120 * $charge * $direction);
$spriteRenderer->render($sprites, $camera, $milliseconds);
~~~

An active effect adds one full-background copy and one clipped effect draw. Skip capture when strength is zero. `begin()` remembers the current framebuffer, so the pass can nest inside `WaterRenderer`: begin water, begin the twist, draw the map, compose the twist, draw sprites, then compose water. `restore()` rebuilds all owned GPU resources after context loss.

## Blur the background during camera movement

`MotionBlur` ports moving-boxes-2's camera-travel response curve without tying it to the renderer.
Sample it after camera following and clamping. It converts world travel to output pixels, normalizes
the result to a 60 Hz reference step, squares and caps each axis, and returns the reference Gaussian
strength plus a normalized screen-space direction. The first sample, a zero-duration recovery frame,
a zoom change, or a disabled sample establishes a new baseline without producing a stale-motion flash.

~~~php
use Phonics\Render\{MotionBlur, MotionBlurRenderer};

$motion = new MotionBlur;
$renderer = new MotionBlurRenderer($device->width, $device->height);

// After updating the camera, before rendering terrain:
$blur = $motion->sample($camera, $renderSeconds, $motionBlurEnabled);

if($blur['active'])
{
	$renderer->begin();
}

$mapRenderer->render($camera, $milliseconds);

if($blur['active'])
{
	$renderer->compose($blur['sigma'], $blur['x'], $blur['y']);
}

// Actors and screen-space UI drawn here remain sharp.
$spriteRenderer->render($sprites, $camera, $milliseconds);
~~~

The renderer uses one RGBA8 capture texture and a directional Gaussian pass with edge clamping.
Inactive frames add no framebuffer or draw work. `begin()` retains the current framebuffer, so the
pass nests around `BackdropTwistRenderer` and inside `WaterRenderer`: water capture, motion capture,
backdrop capture and composition, motion composition, actor sprites, then water composition. This
keeps map foregrounds blurred, actors and the HUD sharp, and underwater refraction in front of both.

Call `reset()` after teleports and scene changes. Call both `reset()` and the renderer's `restore()`
after WebGL recovery; call `close()` before closing the device. The live demo exposes the effect as
**Settings → Motion blur** and publishes the current sample in `canvas.dataset.motionBlur`. A world
may set `sane.motionBlur` to false, and scripted boots on the legacy demo page may pass
`motionBlur: false` to `bootEngine()`, when deterministic frame observation or a low-cost graphics
fixture matters; the browser-facing default remains enabled.

## Renderer behavior and limits

MapRenderer uploads 32×32 tile-ID planes into GL_TEXTURE_2D_ARRAY. Unsigned RGBA8 texels store dense atlas IDs and flip bits. GLSL fetches IDs and atlas rectangles; PHP does not submit one draw per tile.

Orthogonal chunks/layers use glDrawArraysInstanced with per-instance placement, parallax, opacity, and tint. IDs upload initially and after edits. Isometric rendering merges diagonals across chunk boundaries in painter order, using additional draws to preserve overlapping tall tiles. Sprite batches are instanced. MapRenderer::stats reports draws, instances, and uploads.

Atlas images and metadata must fit GPU texture limits; capacity errors are explicit. Sampling is nearest-neighbor. Close resources explicitly. Atlas paging, lighting, and audio abstractions are not included. For the optional Tiled editor integration, see [Authoring courses in Tiled](authoring.md).

Apache-2.0. Runtime licenses and notices ship with its preview archive. No code or artwork from the reference games is copied into this project.
