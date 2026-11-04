# PHP Billiards

PHP Billiards is a pool table for local practice and room-backed two-player eight-ball. PHP owns deterministic fixed-step simulation, swept free flight and compliant ball contacts, cushion response, sliding and rolling friction, spin transfer, pockets, scratches, turn rules, and exact checkpoints. Its application-owned WebGL 2 renderer draws the table, balls, shadows, and SVG-derived material textures.

The [PHP API](api/index.html) lists every public type declared by the package.

The game runs directly from its executable PHAR. It does not create a world or import Tiled, Doom, or WAD code. This makes it a compact example of the generic application lifecycle beneath Phonics' optional content systems. The game session and sixteen numbered balls are registered with Phonics' ECS. Ball components reference the exact objects owned by the deterministic simulation, while stable identity components survive rack and checkpoint replacement.

**Free Play** records a fresh random rack seed at startup and on New Rack; shot reset and replay preserve it. Ball contacts transmit force only after the physical gap closes, and balls remain solid while falling into pockets. **Online Play** opens the shared Matrix lobby, where you can open a table, challenge a person, join an open table, or spectate an active game. The letterboxed game stays above the room chat after a table starts, and the people list remains in the right rail.

[Online games and deterministic seeding](online.html) explains the commit/reveal exchange, exact-build checks, action transcript, and x86/ARM behavior.

Drag on the cloth to aim, or use the left stick or arrow/WASD keys, holding **Shift** for fine aim. Set power with up/down or the power meter, or draw down from the meter and release to shoot. The spin disc, Q/E, Z/C, triggers, and shoulders set top, draw, and side spin. Press **Space** or A to shoot. Right-drag or the right stick orbits the camera, the wheel zooms, and 0 or R3 returns behind the cue. After a scratch, move the ghost cue ball to a clear spot and confirm it. **Escape** or Start opens the menu, and **?** shows every control.

- [Open the full-page game](https://seanmorris.github.io/phonics-examples/games/php-billiards/)
- [Browse the examples repository](https://github.com/seanmorris/phonics-examples)
