# PHPTris

PHPTris is a falling-block package with three solo modes and five progressive challenge tracks. It demonstrates deterministic board logic, a compact ECS integration, keyboard, controller, and touch input, Tiled-authored challenge metadata, and an SDL/WebGL presentation.

Load its world through the launcher, or import the package manifest to reuse individual game modules.

## How to play

Move and rotate each falling piece so completed rows span the board. Cleared rows make room and add score; a stack that reaches the top ends the run. Plan with the preview queue, hold one piece for later, soft drop to position a piece, and hard drop when the landing is safe. The three solo modes share one board, preview queue, hold slot, line clearing, and level progression and differ only in objective and scoring; the challenge tracks add ranked goals.

Keyboard, controller, and touch drive the same fixed-step board, which renders independently at display rate. Touch controls appear on touch devices, and pushing the left stick up also hard drops. A fresh-press guard keeps the button that starts a run from rotating or dropping its first piece.

## Controls

| Action | Xbox | PlayStation | Keyboard |
| --- | --- | --- | --- |
| Move | <span class="controller-glyphs"><img class="controller-glyph" src="../../../art/controllers/xbox-button-ls.svg" alt="Xbox left stick"><span>or</span><img class="controller-glyph" src="../../../art/controllers/xbox-dpad.svg" alt="Xbox D-pad"></span> | <span class="controller-glyphs"><img class="controller-glyph" src="../../../art/controllers/playstation-button-ls.svg" alt="PlayStation left stick"><span>or</span><img class="controller-glyph" src="../../../art/controllers/playstation-dpad.svg" alt="PlayStation D-pad"></span> | Left and Right |
| Soft drop | <span class="controller-glyphs"><img class="controller-glyph" src="../../../art/controllers/xbox-button-ls-down.svg" alt="Xbox left stick down"><span>or</span><img class="controller-glyph" src="../../../art/controllers/xbox-button-down.svg" alt="Xbox D-pad down"></span> | <span class="controller-glyphs"><img class="controller-glyph" src="../../../art/controllers/playstation-button-ls-down.svg" alt="PlayStation left stick down"><span>or</span><img class="controller-glyph" src="../../../art/controllers/playstation-button-down.svg" alt="PlayStation D-pad down"></span> | Down |
| Rotate clockwise | <img class="controller-glyph" src="../../../art/controllers/xbox-button-a.svg" alt="Xbox A button"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-x.svg" alt="PlayStation Cross button"> | Up or X |
| Rotate counterclockwise | <img class="controller-glyph" src="../../../art/controllers/xbox-button-b.svg" alt="Xbox B button"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-circle.svg" alt="PlayStation Circle button"> | Z or Ctrl |
| Hard drop | <span class="controller-glyphs"><img class="controller-glyph" src="../../../art/controllers/xbox-button-x.svg" alt="Xbox X button"><span>or</span><img class="controller-glyph" src="../../../art/controllers/xbox-button-up.svg" alt="Xbox D-pad up"></span> | <span class="controller-glyphs"><img class="controller-glyph" src="../../../art/controllers/playstation-button-square.svg" alt="PlayStation Square button"><span>or</span><img class="controller-glyph" src="../../../art/controllers/playstation-button-up.svg" alt="PlayStation D-pad up"></span> | Space |
| Hold piece | <img class="controller-glyph" src="../../../art/controllers/xbox-button-y.svg" alt="Xbox Y button"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-triangle.svg" alt="PlayStation Triangle button"> | C or Shift |
| Pause | <img class="controller-glyph" src="../../../art/controllers/xbox-button-start.svg" alt="Xbox Start button"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-start.svg" alt="PlayStation Options button"> | Escape or P |

## What to study

- [Challenge tracks](challenges.md) explains the ranked objectives.
- [World authoring](world-authoring.md) describes package resources and Tiled data.
- [Architecture](architecture.md) maps the game loop to public PHP types.

The [PHP API](api/index.html) lists every public type declared by this package.
