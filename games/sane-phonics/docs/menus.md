# Bitmap fonts and menus

This chapter covers the Sane-Phonics demo menus. The reusable `Phonics\Ui`
classes (`Menu`, `PauseMenu`, `MenuRenderer`, `TextActors`, and the rest) are
documented in the framework's [UI reference](../../../docs/ui.md).

Press P, Escape, or Start in a live demo to open its pause menu. Settings
contains text, numeric, slider, selection, boolean, read-only, and **PHP info**
rows. PHP info captures the running runtime's complete `phpinfo()` document and
rasterizes it into a scrollable WebGL texture inside the game canvas. Valid
changes apply immediately. The simulation stops while menu input and rendering
continue.

## Demo browser controls

**Level** changes scenes within the running PHP engine. The clock, registry,
player object, selected movement profile, and menu settings survive. Each new
level resets the player's spawn pose and releases the previous level's graphics,
collision, blocks, and particles. **Movement** changes the current player's
profile and sprite in place, preserving position and settings.

The current level stays available while the destination downloads. Select
another level to replace a pending request, or use **Cancel level change**.
Failed downloads leave the current level intact; select the failed level again
to retry. Maps, tilesets, and images load on demand. Shared files stay mounted
until the last level or streamed map releases them.

On the game page, the **Play Sane-Phonics** poster loads `worlds/demo.world`.
Its `sane.intro` property starts the preloader, intro cards, parallax title,
and main menu. Choose Play to load a level. Main menu in the pause menu keeps
the level available to resume. See the [startup guide](startup.md) for that flow.
Other hosts choose the first level, profile, and intro with the world properties
`sane.scene`, `sane.profile`, and `sane.intro`.

The `bootEngine({scene, profile})`, `bootEngine({intro: true})`, and
`stopEngine()` globals exist only on the repository's legacy demo page
(`docs/index.html`, served at `/sane-phonics/` by `npm start`). Inside running
PHP, use:

```php
$game->selectScene('loop');      // Stage a level; the frame loop commits it when ready.
$game->selectProfile('glider');  // Update the current actor immediately.
$game->cancelLoad();            // Retain the active scene.
```

`$game->scene` identifies the active level, `$game->pending?->id` identifies a
pending destination, and `$game->error` contains the most recent load failure.
These are demo session methods in `examples/DemoGame.php`; the engine's reusable
loading primitives remain `WorldStream` and the host `AssetLoader`.

**Mute audio** and **Fullscreen** appear in the pause menu and as two icon buttons
at the bottom-right of the game. The buttons have keyboard focus, accessible
labels, and pressed states. Fullscreen includes only the game frame, canvas, and
corner controls. The page text stays outside it. Menu text editing
also stays inside the fullscreen frame, including the SDL textarea fallback.

Fullscreen state follows the browser, including exits through Escape. If a
browser rejects a gamepad-triggered request, click the corner fullscreen button.
Browsers without fullscreen support hide both fullscreen controls.

Mute uses a master gain on the pinned runtime's SDL audio output. Channel/music
volumes, fades, and playback positions are preserved, and newly opened output
devices inherit the setting. The preference survives level changes and page
reloads when local storage is available. The sample levels currently have no
soundtrack; the audio regression plays a generated tone through the native mixer
and checks the signal after this gain.

The PHP menu calls `DemoGame` for scene and movement changes. The browser host
at `vrzno_env('demoControls')` supplies fullscreen and persistent mute. Browser
controls live in `DemoControls` in this package's
[`browser/game.mjs`](../browser/game.mjs). The framework's
[`browser/DomTexture.mjs`](../../../browser/DomTexture.mjs) is a separate
browser primitive for trusted HTML: it measures and rasterizes one complete SVG
`foreignObject` shot, splits it into GPU-height tiles in the shared WebGL2
context, then scrolls those prebuilt tiles without rerasterizing. It restores
the engine's GL state afterward and rebuilds its textures after a context
restore. `DomTextureModal` adapts its JSON-friendly state and scroll controls
to PHP. The reusable `Menu` API does not require a browser host.

In the PHP info panel, arrows/WASD, D-pad, and left stick scroll by a line;
mouse wheel scrolls by two lines; Page Up/Down or shoulders scroll a page;
Home/End jump to either end. Escape/B returns to Settings, while P/Start closes
the whole menu.

## World text and message overlays

`Phonics\Ui\TextActors` promotes visible Tiled object-layer objects with class
`TextActor` or moving-boxes-2's `text-actor` into non-solid ECS labels. Use a
`content` string property (or Tiled's native text payload), optional `color`,
`scale`, and `align` (`left`, `center`, or `right`). Labels inherit their map,
layer, tint, opacity, and offset, use the same themed bitmap font as menus, and
are removed with their level. The Platformer scene includes two small authored
examples.

`Ui\TextMessages` is the DOM-free counterpart for MB2's center message,
focus prompt, typed dialog, and recording/replay badge. `TextRenderer` draws it
after HUDs and before menus, so menus and PHP info remain modal. Dialogs accept
a speaker name and `left`/`right` placement but intentionally use a generic
speaker header: the MB2 portrait art is not part of this project.

```php
$messages = $game->textMessages();
$messages->showCenter('READY', [1, .9, .3, 1], 2.5);
$messages->showDialog(['Find the next panel.', '⓿ continues.'], 'GUIDE', 'left');
$messages->showFocus();              // Default keyboard/controller focus prompt.
$messages->showDemo('play', 2.5);    // Or 'record'.
```

`update($elapsed)` advances durations and dialog reveal time using unpaused UI
time. `setFocused(bool)` supplies the standard blur prompt and delayed return
dismissal. The Settings page's **Text system demo** previews all four layers.
`canvas.dataset.messages` and `canvas.dataset.text` expose diagnostic snapshots
for browser tests and the inspector.

## Demo diagnostics

**Settings → Debug overlay** enables the upper-right player, camera, collision, and
rendering panel. **F9** toggles it while the game has keyboard focus. Repeated keydown
events do not repeatedly toggle the panel. Camera-only scenes report no active player.

**Settings → FPS meter** controls a separate lower-left display, enabled by default.
It shows browser FPS and average frame interval in milliseconds, even while gameplay
is paused. Level changes preserve both settings; Reset settings restores the defaults.
The panel and meter appear during gameplay and remain hidden on intro/title screens.

## Define a menu

```php
use Phonics\Ui\Menu;

$settings = (object)['name' => 'Player', 'hints' => true];
$menu = new Menu([
    'resume' => [
        'label' => 'Resume',
        'callback' => fn(Menu $menu) => $menu->close()
    ],
    'settings' => [
        'label' => 'Settings',
        'children' => [
            'name' => [
                'label' => 'Name', 'input' => 'string', 'default' => 'Player',
                'get' => fn() => $settings->name,
                'set' => fn($value) => $settings->name = $value
            ],
            'hints' => [
                'label' => 'Hints', 'input' => 'boolean', 'default' => true,
                'get' => fn() => $settings->hints,
                'set' => fn($value) => $settings->hints = $value
            ],
            'speed' => [
                'label' => 'Speed', 'input' => 'number', 'subType' => 'range',
                'min' => .5, 'max' => 2, 'step' => .25, 'value' => 1
            ],
            'difficulty' => [
                'label' => 'Difficulty', 'input' => 'select',
                'options' => ['Easy', 'Normal', 'Hard'], 'value' => 'Normal'
            ],
            'score' => ['label' => 'Score', 'input' => 'output', 'value' => 100]
        ]
    ]
], 'Paused');
```

IDs are stable string keys, independent of labels. IDs beginning with `@` are
reserved for generated Back/Retry rows. Labels default to their IDs. `subtext`
supplies the selected row's description. `hidden` and `disabled` accept booleans
or callbacks receiving the menu. Disabled rows remain readable but cannot
activate, adjust, or reset.

`get()` supplies a live value. `set($value)` runs once per accepted change,
never merely because a page redraws. `value` stores local state when there is
no getter. Numbers snap to `step` relative to `min` (zero without a minimum)
and clamp to `min`/`max`. Empty selection lists are disabled. Selection options
are scalar values compared strictly and displayed as strings.

Text is single-line UTF-8; pasted line breaks become spaces. Cursor and selection
positions use Unicode codepoints. IME preedit stays separate from committed text.
Enter, Escape, or B leaves a field and keeps valid live changes. Leaving an
incomplete number retains its last valid value. The bitmap font does not shape
complex scripts: unknown characters render its fallback without changing the
stored value.

Use `default` for per-item reset, or `revert(Menu $menu)` for application-specific
reset behavior. A revert callback can return a value or mutate state read by
`get()`. Persistence belongs in application callbacks.

## Attach to the engine

```php
use Phonics\Asset\Resource;
use Phonics\Render\BitmapFont;
use Phonics\Tiled\Loader;
use Phonics\Ui\PauseMenu;

$font = new BitmapFont(
	new Loader
	, Resource::framework('resources/font.tsj')
	, [Resource::framework('resources/buttons.tsj')]
);
$pauseMenu = new PauseMenu($engine, $menu, $font);

// After the world render and before Device::present():
$pauseMenu->render();

// In the context restoration callback:
$pauseMenu->restore();

// In the engine cleanup callback, before closing the device:
$pauseMenu->close();
```

Use one `PauseMenu` adapter per engine. It owns the optional input handler and UI
graphics, captures opening/closing frames, and clears buffered gameplay actions.
Release held controls before returning to gameplay. Pages and button themes
share one font atlas; changing a theme downloads no extra runtime or image.

`Menu` also exposes `open()`, `close()`, `select($id)`, `move($distance, $wrap)`,
`activate()`, `adjust($direction)`, `back()`, `revert()`, `setValue($id, $value)`,
`rows()`, and `refresh()`. These commands work without SDL. `MenuInput::update()`
accepts named buttons, elapsed seconds, and ordered UI events. `MenuRenderer`
can draw menus without pausing anything.

Custom integrations use
`Engine::setInputHandler(callable(Engine, float): bool)`. The callback runs before
pause handling and simulation; return true to capture the frame. Without a
handler, the engine retains its original gameplay input and pause behavior.

`children` accepts an item array or a loader callback receiving the menu. Return
an array when ready, or `null` while pending. Only the active page polls its
loader. Exceptions produce Back/Retry controls. Hosts manage asynchronous
requests separately; PHP requires no JavaScript Promise type.

## Controls and appearance

| Input | Command |
| --- | --- |
| Arrows/WASD, D-pad, left stick | Move or adjust |
| Enter, A/Cross | Activate or finish editing |
| Space | Activate outside a field; type a space while editing |
| Escape, B/Circle | Leave field, parent page, then menu |
| X/Ctrl | Back outside a field; normal editing inside a field |
| P, Start/Options | Open/close; P types normally inside a field |
| Page Up/Down, shoulders | Move seven rows, clamping at the ends |
| Home/End | First/last row, or field boundary while editing |
| Hold keyboard Z / pad Y or Triangle for 500 ms, middle-click | Reset outside text editing |
| Mouse | Select rows, drag sliders or text selections |
| Wheel | Move one visible row per event, clamping at the ends; ignore scroll magnitude |

Navigation repeats after 500 ms, every 250 ms initially, then every 50 ms after
1.5 seconds. Editing supports Shift-selection, Ctrl/Cmd+A, deletion, and
SDL/browser clipboard transport. Clipboard permissions and on-screen keyboard
activation follow the runtime's browser behavior.

`$pauseMenu->theme` defaults to `auto`. Overrides are `keyboard`, `xbox`,
`playstation`, and `generic` (numbered generic/10-button pads). Auto mode follows
fresh keyboard/pointer or controller activity. Unknown controller names use
generic glyphs. This changes hints, not SDL's button mapping.

The font accepts moving-boxes-2's characters, including `⓿`, `❶` through `❿`, `⓫`, `✚`,
`←→↑↓`, `⬲`, `⟴`, and stick-direction arrows. For example,
`⓿ Select   ❶ Back   Hold ❸ Reset` becomes themed keycaps or buttons. These
glyphs retain their original colors when surrounding text is tinted.

| Character | Keyboard | Xbox | PlayStation |
| --- | --- | --- | --- |
| `⓿` | Space | A | Cross |
| `❶` | Ctrl | B | Circle |
| `❸` | Z | Y | Triangle |
| `❾` | P | Menu | Options |
| `✚` | Arrow keys | D-pad | D-pad |
| `⬲` | WASD | Left stick | Left stick |
| `⟴` | Numpad directions | Right stick | Right stick |

Generic pads use numbered buttons. Arrow variants highlight individual directions.
The complete character-to-artwork mapping is in `art/controllers/manifest.json`.

```php
$pauseMenu->renderer->style['selected'] = [.4, 1, 1, 1];
$pauseMenu->renderer->style['panel'] = [.05, .05, .1, .9];
$pauseMenu->renderer->style['scale'] = 2;
$pauseMenu->renderer->showHints = true;
```

Style also provides `width`, `padding`, `foreground`, `disabled`, `highlight`,
`selection`, and `dimmer`. Layout uses drawing-buffer dimensions and screen
coordinates independent of the world camera. Source-pixel cropping clips glyphs
without changing GL scissor state. Render UI in the normal full-screen viewport.

### Text alignment

Set `align` to `left`, `center`, or `right`. Menus default to `left`. Optional
`titleAlign`, `valueAlign`, and `hintAlign` style entries override it for the
heading, value column, and control hints. Labels align within their label column;
action rows use the full row width. Subtext follows the menu's alignment.

```php
$pauseMenu->renderer->style['align'] = 'left';
$pauseMenu->renderer->style['titleAlign'] = 'center';
$pauseMenu->renderer->style['valueAlign'] = 'right';

$menu = new Menu([
    'play' => ['label' => 'Play', 'align' => 'center'],
    'name' => [
        'label' => 'Name', 'input' => 'string', 'value' => 'Player',
        'align' => 'left', 'valueAlign' => 'right'
    ]
]);
```

An item's `align` overrides the menu's alignment for its label. Values use the
item's `valueAlign`, then the renderer's `valueAlign`, then the item's effective
alignment. Carets, selections, IME previews, and mouse hit testing follow the
aligned text. Long fields scroll to keep the caret visible and realign when the
text fits again. Sliders retain their track and align the adjacent value text.

Use the same alignment directly with the bitmap font:

```php
$layout = $font->layout("Heading\nSupporting text", maxWidth: 320, align: 'center');
$field = $font->layout('Player', align: 'right', alignWidth: 160);
```

Each explicit or wrapped line aligns independently. `maxWidth` controls wrapping
and supplies the alignment width. With infinite `maxWidth`, lines align within
the widest measured line. `alignWidth` supplies a separate alignment width without
enabling wrapping, as in the field example. Lines wider than that box start at its
left edge. Returned dimensions measure the content; sprites and caret positions
include the alignment offsets. Invalid alignment names and nonpositive or
nonfinite explicit alignment widths throw `InvalidArgumentException`.

## Annotate a tileset

Character identity and metrics belong in Tiled custom properties. Tiles can
move, use different column counts, or live in image collections without PHP edits.

| Location | Property | Meaning |
| --- | --- | --- |
| Font tileset | `font.lineHeight` | Line advance in logical font pixels |
| Font tileset | `font.spaceAdvance` | Space width without a drawn glyph |
| Font tileset | `font.fallback` | Annotated replacement character, default `?` |
| Font tileset | `font.caseFold` | Resolve ASCII lowercase through uppercase glyphs |
| Tile | `glyph.char` | One Unicode character identifying this glyph |
| Tile | `glyph.aliases` | Additional characters sharing this image |
| Tile | `glyph.theme` | Optional theme; omit for ordinary text |
| Tile | `glyph.advance` | Horizontal cursor advance |
| Tile | `glyph.width`, `glyph.height` | Visible source crop dimensions |
| Tile | `glyph.cropX`, `glyph.cropY` | Crop origin inside the tile |
| Tile | `glyph.pixelRatio` | Source pixels per logical font pixel; positive, default 1 |
| Tile | `glyph.smooth` | Interpolate within the crop; default false for pixel art |
| Tile | `glyph.offsetX`, `glyph.offsetY` | Placement relative to the text origin |
| Tile | `glyph.tint` | False preserves colored keycap/button artwork |

Unannotated tiles are ignored. Duplicate characters within a theme, invalid
metrics/crops, and missing fallback glyphs are rejected. `BitmapFont::layout()`
returns sprites, dimensions, and caret positions; `measure()` returns the same
dimensions. Both work without SDL or GPU allocation.

Controller and keyboard SVGs are rasterized at four times their logical size.
Their annotations keep advances, offsets, wrapping, and menu clipping in logical
pixels. Only the image crop uses source pixels. When `glyph.advance` is omitted,
it defaults to the crop width divided by `glyph.pixelRatio`.

The button glyphs enable `glyph.smooth`; the bitmap letters keep nearest-pixel
sampling. Both share an atlas and an instanced draw. Filtering clamps to each
sprite's crop and interpolates premultiplied colors to avoid neighboring tiles
and dark transparent edges. Other sprites can opt in with `$sprite->smooth = true`.

The font comes from Tigl, annotated using moving-boxes-2's character mapping
and CSS spacing. Button artwork and its regeneration recipe are recorded in
[`art/controllers/README.md`](../../../art/controllers/README.md).
