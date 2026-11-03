# PHP-Noid

PHP-Noid is an original-art, 25-stage brick-breaker campaign. It shows a self-contained game package owning its maps, assets, factories, simulation systems, WebGL rendering, audio, menus, persistence, and lifecycle on top of Phonics services.

The package imports no Sane-Phonics code or assets. Load `worlds/campaign.world`, or import `phonics.json` in Tiled and select the `@php-noid/game` module.

## How to play

Launch the ball, keep it above the paddle, and clear every destructible brick. Ordinary, copper, and silver bricks take one, two, and three hits; gold is permanent, and green metal needs red energy. Catch capsules to resize the paddle, split or alter the ball, equip lasers or missiles, gain a life, or exit the stage. Compatible powers run on independent timers: paddle size, Catch, one weapon, Power Ball, and ball speed can coexist, while Expand/Shrink, Fast/Slow, and Lasers/Missiles each replace only their counterpart.

Enemy ships add hazards and supplies. Menacers alter the board, Destroyers fire back, Annihilators home in on the paddle, Floaters obstruct the arena, and Sympathizers drop helpful capsules.

Move with the pointer, keyboard, or controller. One Fire control launches a waiting ball, releases a caught ball, fires the active weapon, and continues from the current stage after game over. There are no on-screen touch buttons; touch reaches the playfield as mouse input. The side console shows score, best score, stage, lives, active powers and their timers, and capsule help. Scores persist for the current world when browser storage is available.

## Controls

| Action | Xbox | PlayStation | Keyboard and mouse |
| --- | --- | --- | --- |
| Move paddle | <span class="controller-glyphs"><img class="controller-glyph" src="../../../art/controllers/xbox-button-ls.svg" alt="Xbox left stick"><span>or</span><img class="controller-glyph" src="../../../art/controllers/xbox-dpad.svg" alt="Xbox D-pad"></span> | <span class="controller-glyphs"><img class="controller-glyph" src="../../../art/controllers/playstation-button-ls.svg" alt="PlayStation left stick"><span>or</span><img class="controller-glyph" src="../../../art/controllers/playstation-dpad.svg" alt="PlayStation D-pad"></span> | Mouse, Left/Right, or A/D |
| Launch, release, or fire | <img class="controller-glyph" src="../../../art/controllers/xbox-button-a.svg" alt="Xbox A button"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-x.svg" alt="PlayStation Cross button"> | Left click, Space, Z, or Enter |
| Pause | <img class="controller-glyph" src="../../../art/controllers/xbox-button-start.svg" alt="Xbox Start button"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-start.svg" alt="PlayStation Options button"> | Right click, P, or Escape |
| Restart | <img class="controller-glyph" src="../../../art/controllers/xbox-button-back.svg" alt="Xbox Back button"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-select.svg" alt="PlayStation Select button"> | R |

## Scoring

A destroyed brick awards its Tiled `score` property, 100 by default. The campaign sets 100 points per hit, so ordinary, copper, and silver bricks award 100, 200, and 300; a brick converted by Menacer energy keeps its original value, and green metal and gold award nothing. Destroyers and Annihilators award 500, Menacers and Floaters 250, and Sympathizers nothing. A ship retired by paddle contact scores nothing, and capsules, lives, and stage clears add no points.

## What to study

- [Campaign authoring](campaign.md) describes stage maps, factories, and capsule data.
- [Architecture and performance](architecture.md) explains the ECS split, collision indexes, HUD, and cleanup.

The [PHP API](api/index.html) lists every public type declared by this package.
