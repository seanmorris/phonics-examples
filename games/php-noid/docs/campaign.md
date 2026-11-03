# Campaign and content authoring

Import `phonics.json` in Tiled and choose `@php-noid/game`. The focused `#factories` module registers brick, paddle, and enemy factories; `#brick`, `#paddle`, and `#enemy` expose their templates and typed defaults. `#campaign` and `#firstStage` export authored resources.

Bricks expose `hits`, `unbreakable`, `material`, `capsule`, and `score`. Enemies expose `family`, `effect`, `interval`, `hits`, and the `capsule` dropped by friendly ships. Map `name` properties appear in the HUD.

Capsule definitions, colors, labels, durations, and conflict groups live in `assets/capsules.json`. Stage layouts are ordinary Tiled maps. Regenerate the authored campaign with `node generate-stages.mjs`, the font atlas with `php generate-font.php`, and production manifests with `node scripts/manifests.mjs` from the repository root.

Each stage owns a distinct silhouette. Stages 1–10 each have their own authored background; stages 11–25 reuse those ten images. The package retains broad firing lanes and negative space so multiball, missiles, and ship encounters remain readable.
