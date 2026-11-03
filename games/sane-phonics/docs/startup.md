# Intro cards and the main menu

Press the **Play Sane-Phonics** poster on the [Sane-Phonics page](index.md) to try the complete
startup sequence. It loads `worlds/demo.world`, whose `sane.intro` property enables the intro:

1. The browser loads PHP SDL, then shows progress for the engine and font files.
2. A PHP loading card waits for the title map and its image dependencies.
3. Timed engine and controls cards accept Start, Enter, or a click after 0.5 seconds.
4. The title shows six scrolling Tiled image layers. Its blinking prompt accepts
   a fresh Start, Enter, or click after two seconds.
5. The main menu offers Play, Level, Movement, Settings, Credits, and Replay intro.

The loading card fades out one second after its assets are ready and uses
quarter-second entrance and exit fades. Cached assets still get a readable
completed file count before the transition. The following engine credit card
stays fully visible for four seconds, with half-second entrance and exit fades.

The title's background keeps its offset and animation clock when the main menu
opens. Gameplay time stays paused throughout startup. Level assets download when
you choose **Play**. **Settings** demonstrates live text, numbers, a slider,
choices, and booleans using the same bindings as the pause menu.

In a game, choose **Main menu** from the pause menu to keep the level available
under **Resume game**. Choosing a different level and Play replaces it within the
existing PHP session. PHP, the engine, player identity, and settings survive.

## Example files

| File | Responsibility |
| --- | --- |
| `examples/intro.php` | Starts the game with startup UI enabled |
| `examples/cards.php` | Card text, order, timing, and readiness gate |
| `examples/DemoStartup.php` | Input routing and transitions between cards, menu, and game |
| `examples/DemoBackdrop.php` | Title map dependencies and image renderer ownership |
| `examples/assets/title.tmj` | Editable image layers, scrolling, repetition, and parallax |
| `examples/menu.php` | Shared live settings and the pause menu |

The image-only title map opens in Tiled. It uses the existing generated coastal
images, with animated water shine and different horizontal parallax factors.
`ImageRenderer` draws each repeating strip with one quad. The title camera and
image animation use active UI time, independent of `Engine::$time`.

The executable application selects its game entry. The world's `sane.intro`
enables its startup UI; `sane.scene` and `sane.profile` choose the level and
movement profile. The repository's legacy demo page
(`docs/index.html`, served at `/sane-phonics/` by `npm start`) also exposes console
globals. They exist only on that page:

```js
await bootEngine({intro: true, scene: 'loop', profile: 'runner'});
await bootEngine({intro: false, scene: 'breakables'});
await stopEngine();
```

`bootEngine()` defaults to direct gameplay for scripts and fixtures. That page's
Run PHP button passes `intro: true`. An explicit boot starts a fresh session;
in-session transitions use these PHP methods instead:

```php
$game->startup->showMenu();
$game->startup->title();
$game->startup->replay();
$game->startup->scene = 'loop';
$game->startup->play();
$game->startup->resume();
```

`$game->startup` exists for the intro entry point. Its `phase` is `intro`, `menu`,
`loading`, or `playing`. `cards` and `menu` expose the reusable PHP state objects.
The canvas's `data-intro` JSON publishes card ID, fade state, elapsed UI time,
background pan, simulation time, discovered-file counts, and errors for tests.

## Reusable card API

The framework's [UI reference](../../../docs/ui.md) documents the `Phonics\Ui` card,
menu, and renderer classes. This section summarizes how the demo uses them.

`Ui\Card` describes a screen. `Ui\CardSequence` owns its timers and transitions.
Neither requires SDL, browser timers, or a renderer.

```php
use Phonics\Ui\Card;
use Phonics\Ui\CardSequence;

$assetsReady = false;
$cards = new CardSequence([
    new Card(
        'loading', 'Loading', duration: .3, skippable: false,
        ready: function() use (&$assetsReady): bool { return $assetsReady; }
    ),
    new Card('studio', 'Your studio', 'Your game', duration: 2.5),
    new Card('title', 'Your game', duration: null, skipAfter: 2)
]);
$cards->start();

// In the UI frame loop, after polling your loader and input:
$assetsReady = true; // Set this only when the required assets are usable.
$cards->update(1 / 60, false); // Active seconds, then current confirm-button state.
$current = $cards->current();
$alpha = $cards->opacity();
```

| Card argument | Behavior |
| --- | --- |
| `id` | Nonempty identifier, unique within the sequence |
| `title`, `text` | UTF-8 content; explicit line breaks are supported |
| `duration` | Ready seconds before the exit fade; `null` waits for activation |
| `skipAfter` | Minimum active seconds before activation, default 0.5 |
| `fadeIn`, `fadeOut` | Fade durations in seconds, default 0.25 each |
| `skippable` | Allows user or scripted activation, default true |
| `ready` | Polled `callable(): bool`; timeout and activation wait for true |
| `onEnter` | `callable(Card): void` for application work or audio |
| `onLeave` | `callable(Card, bool): void`; the boolean reports cancellation |
| `align` | `left`, `center`, or `right`; `null` inherits the card renderer's style |

Readiness latches once true. `duration` includes the entrance fade; the exit fade
is additional. Early presses are consumed. Holding a button past `skipAfter` or
across successive cards never becomes a new activation. The first input after
`start()` must be released before it can activate a card.

`advance()` is the scripting verb for a skip; it returns false while gated,
unskippable, already closing, or inside the minimum display time. `complete()`
reports normal exhaustion. `cancel()` leaves the current card once without
entering another. `start()` cancels an unfinished visit and replays from the
beginning. `onLeave` is suitable for releasing card-specific audio or resources.

The public `state` is `idle`, `opening`, `waiting`, `shown`, `closing`, `complete`,
or `cancelled`. Feed active UI seconds, not simulation time. The demo caps frame
deltas and uses Engine's zero-delta recovery after hidden tabs or context loss.
It resets input on transitions so the press opening a menu cannot also select Play.

## Rendering and loading

`Ui\CardRenderer` draws aligned, wrapped bitmap text, fades, prompts, and a
known-file progress bar. The application renders any backdrop first and supplies
the prompt, including its blink behavior:

```php
use Phonics\Ui\CardRenderer;

// Borrow the matching font atlas from an existing menu renderer.
$renderer = new CardRenderer($pauseMenu->renderer->font, $pauseMenu->renderer->atlas);
$renderer->style['heading'] = [.4, 1, 1, 1];
$renderer->style['scale'] = 2;
$renderer->style['align'] = 'center'; // Default; also accepts left and right.
$renderer->render($cards, 960, 540, 'Press Start / Enter', backdrop: true);
```

Each line aligns independently within the middle 85 percent of the viewport.
Set `align` on an individual card to override the renderer for its heading, body,
and prompt, for example `new Card('credits', 'Credits', "Code\nArtwork", align: 'left')`.

Pass `progress: ['ready' => 3, 'total' => 8, 'failed' => 0]` to draw a bar. Those
counts describe dependencies discovered so far: parsing a map or tileset can
increase the total. The browser runtime phase stays indeterminate because the
demo has no complete byte total for WASM initialization. No gameplay map is
included in the title preload.

The demo uses `WorldStream` for dependency discovery and `AssetLoader::scope()`
for transfer ownership. The title and a level share file downloads and mounts.
Each scope exposes `request`, `progress`, `release`, and `close`. Files remain
mounted until their last owner releases them. GPU image caches belong to each
renderer. Startup keeps its small backdrop available while playing so returning
to the main menu needs no download.

A title download failure stays on the loading card with a Retry prompt. A level
failure offers Retry or Back to the main menu. Stopping cancels pending requests
and ignores late responses. An active level remains available until a replacement
is prepared. Context restoration rebuilds the shared menu atlas first, then the
card renderer and backdrop. Close the card renderer before its borrowed atlas;
without an atlas argument it owns and restores its own font atlas.

## Reference behavior

This implementation follows moving-boxes-2's `Card`, `SkippableCard`, `Series`,
`TitleScreenCard`, and `Viewport::introCards()` / `homeCards()` flow: readiness
before dismissal, guarded fresh presses, timed cards, a title prompt,
and a main menu sharing the title backdrop. The reference also supplies a
51-second title timeout; this demo sets `duration: null` to wait for input.
Set `duration: 51` in `examples/cards.php` to reproduce that automatic transition.
The demo configures its own card
order and generated artwork. `onEnter` and `onLeave` provide hooks for a game's
music and other resources. Network play, saved games, and reference-game branding
are application features and are not included in this example.

See [menus and font annotations](menus.md) for item definitions, editable fields,
controller themes, and input behavior.
