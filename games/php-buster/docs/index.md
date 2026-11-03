# PHP Buster

PHP Buster ports **Blockade Buster**, programmed by Greg Troutman, to a self-contained Phonics package. Graphics are courtesy of Ari Feldman's SpriteLib. A deterministic fixed-step PHP simulation runs the enemy waves, projectiles, pickups, collision, score, and stage transitions. The browser module supplies pointer and touch input, storage, sound, fullscreen, and accessible menu hit targets.

The port keeps the original four-phase stage shape (fighter formations, asteroid groups, artillery, and a heavy fighter), three stages per level, increasing level pressure, firing intervals, upgrade drops, shield drops, accuracy bonus, and scrolling 320×480 presentation. It replaces the applet host with an explicit package lifecycle, editable bindings, a local top-ten table, and input that works across keyboard, mouse, controller, and touch.

## How to play

Move the fighter around the playfield, keep firing, and avoid enemy shots and collisions. Each stage alternates fighter formations and asteroid groups before artillery and a heavy fighter arrive; destroying the heavy fighter finishes the stage, and three completed stages advance the level. The heavy fighter's ruby upgrades the missile pattern through four weapon levels, and mini fighters drop shields. A collision resets the weapon and removes one shield, followed by 450 ms of grace so one overlap cannot empty the shield counter. The stage bonus is the level multiplied by shot accuracy; a stage with no shots awards zero. After the last shield is lost, enter three initials to save the score. The browser keeps the best ten locally, and scores and bindings never leave the device.

Mouse position targets the playfield directly. On touch screens, drag the craft and use the Fire button; a drag starts from the craft's current position so it never jumps to the finger, and touch has no pause control. Controller stick movement is analog and keyboard movement is full-speed; both are normalized, so diagonals are no faster than cardinal movement. **Input settings** edits keyboard/mouse and controller bindings separately, keeps Pause fixed, and stores valid changes locally when the browser allows it.

## Controls

| Action | Xbox | PlayStation | Keyboard and mouse |
| --- | --- | --- | --- |
| Move | <img class="controller-glyph" src="../../../art/controllers/xbox-button-ls.svg" alt="Xbox left stick"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-ls.svg" alt="PlayStation left stick"> | Arrow keys, W/A/S/D, or mouse position |
| Fire | <span class="controller-glyphs"><img class="controller-glyph" src="../../../art/controllers/xbox-button-a.svg" alt="Xbox A button"><span>or</span><img class="controller-glyph" src="../../../art/controllers/xbox-button-rb.svg" alt="Xbox RB button"></span> | <span class="controller-glyphs"><img class="controller-glyph" src="../../../art/controllers/playstation-button-x.svg" alt="PlayStation Cross button"><span>or</span><img class="controller-glyph" src="../../../art/controllers/playstation-button-r1.svg" alt="PlayStation R1 button"></span> | Space or left click |
| Pause | <img class="controller-glyph" src="../../../art/controllers/xbox-button-start.svg" alt="Xbox Start button"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-start.svg" alt="PlayStation Options button"> | Escape or P |

## What to study

- [Architecture](architecture.md) explains simulation ownership, rendering, the browser boundary, and the engine utilities promoted during the port.

The [PHP API](api/index.html) lists every public type in the package.
