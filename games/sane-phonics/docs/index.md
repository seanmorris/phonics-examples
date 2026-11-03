# Sane-Phonics

Sane-Phonics is the platformer package built on Phonics. It combines reusable movement profiles, terrain following, loops, vehicles, water, enemies, menus, and streamed Tiled worlds without moving those game rules into the engine.

The package manifest exposes focused modules as well as the complete game. Import `phonics.json#platformer` for movement and the `SanePhonics.Player` factory, or load the demo world for the title flow, level selection, and all gameplay systems.

## How to play

Choose a level and a Runner, Glider, or Flyer movement profile from the main menu. Follow the terrain, collect rings, defeat or avoid enemies, use springs and moving platforms, and carry momentum through slopes, bends, rails, water, and loops. Runner abilities include rolling, spindash, drop dash, and light dash; the other profiles turn a second jump into gliding or flight. Vehicle courses add their own tricks and movement while mounted.

The pause menu can change the level or movement profile without restarting the engine. Reset returns the player to the current spawn, while Restart Level restores rings, enemies, monitors, blocks, platforms, and the level timer.

## Controls

| Action | Xbox | PlayStation | Keyboard |
| --- | --- | --- | --- |
| Move, pan, or climb | <span class="controller-glyphs"><img class="controller-glyph" src="../../../art/controllers/xbox-button-ls.svg" alt="Xbox left stick"><span>or</span><img class="controller-glyph" src="../../../art/controllers/xbox-dpad.svg" alt="Xbox D-pad"></span> | <span class="controller-glyphs"><img class="controller-glyph" src="../../../art/controllers/playstation-button-ls.svg" alt="PlayStation left stick"><span>or</span><img class="controller-glyph" src="../../../art/controllers/playstation-dpad.svg" alt="PlayStation D-pad"></span> | Arrow keys or W/A/S/D |
| Jump, glide, or fly | <img class="controller-glyph" src="../../../art/controllers/xbox-button-a.svg" alt="Xbox A button"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-x.svg" alt="PlayStation Cross button"> | Space, Z, or Enter |
| Crouch or roll | <span class="controller-glyphs"><img class="controller-glyph" src="../../../art/controllers/xbox-button-ls-down.svg" alt="Xbox left stick down"><span>or</span><img class="controller-glyph" src="../../../art/controllers/xbox-button-down.svg" alt="Xbox D-pad down"></span> | <span class="controller-glyphs"><img class="controller-glyph" src="../../../art/controllers/playstation-button-ls-down.svg" alt="PlayStation left stick down"><span>or</span><img class="controller-glyph" src="../../../art/controllers/playstation-button-down.svg" alt="PlayStation D-pad down"></span> | Down or S |
| Charge a spindash or drop dash | <img class="controller-glyph" src="../../../art/controllers/xbox-button-b.svg" alt="Xbox B button"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-circle.svg" alt="PlayStation Circle button"> | X, Shift, or Ctrl |
| Light dash | <img class="controller-glyph" src="../../../art/controllers/xbox-button-x.svg" alt="Xbox X button"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-square.svg" alt="PlayStation Square button"> | Hold C near airborne rings |
| Pause | <img class="controller-glyph" src="../../../art/controllers/xbox-button-start.svg" alt="Xbox Start button"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-start.svg" alt="PlayStation Options button"> | P or Escape |
| Reset position | <img class="controller-glyph" src="../../../art/controllers/xbox-button-back.svg" alt="Xbox Back button"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-select.svg" alt="PlayStation Select button"> | R |
| Restart level | <span class="controller-glyphs">Hold <img class="controller-glyph" src="../../../art/controllers/xbox-button-b.svg" alt="Xbox B button"><span>+</span><img class="controller-glyph" src="../../../art/controllers/xbox-button-back.svg" alt="Xbox Back button"></span> | <span class="controller-glyphs">Hold <img class="controller-glyph" src="../../../art/controllers/playstation-button-circle.svg" alt="PlayStation Circle button"><span>+</span><img class="controller-glyph" src="../../../art/controllers/playstation-button-select.svg" alt="PlayStation Select button"></span> | Shift+R |
| Fire-shield air thrust | <span class="controller-glyphs"><img class="controller-glyph" src="../../../art/controllers/xbox-button-lb.svg" alt="Xbox LB button"><span>or</span><img class="controller-glyph" src="../../../art/controllers/xbox-button-rb.svg" alt="Xbox RB button"></span> | <span class="controller-glyphs"><img class="controller-glyph" src="../../../art/controllers/playstation-button-l1.svg" alt="PlayStation L1 button"><span>or</span><img class="controller-glyph" src="../../../art/controllers/playstation-button-r1.svg" alt="PlayStation R1 button"></span> | Q or E |

## Executable PHP

| File | Role |
|---|---|
| `modules/platformer.php` | Registers the reusable player factory and terrain-backed platform movement system. |
| `examples/game.php` | Builds the complete demo engine, player, menus, HUD, startup flow, input, and render lifecycle. |
| `examples/*.php` | Defines the demo's scenes, cards, menus, world flow, breakables, and presentation helpers. |

## What to study

- [Gameplay systems](gameplay.md) explains actors, abilities, combat, and world ownership.
- [Loops and collision layers](loops.md) covers high-speed terrain traversal.
- [Menus and browser controls](menus.md) covers the canvas UI and PHP-info modal.
- [Startup](startup.md) follows the preloader, intro cards, title, and play transition.

The [PHP API](api/index.html) lists every public type declared by this package.
