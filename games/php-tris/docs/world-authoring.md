# World authoring

Open `worlds/arcade.tiled-project` in Tiled 1.12 or later before opening the world. Run `npm install` at the repository root first and enable the project extension when prompted. The project supplies the custom types and finds the extension through a relative path.

The package manifest, `phonics.json`, declares the application bootstrap, browser module, `#components` module, `#stage` system set, `#arcade` world, and the files available to the executable PHAR. `application.php` selects the game entry. The supplied `worlds/arcade.world` selects its content through these custom properties:

```text
phonics.import.php-tris = ../phonics.json
phonics.start = endless-01.tmj
```

Each map is a finite 10×20 grid with the map properties `mode`, `name`, `rank`, `seed`, `lineTarget`, `duration`, and `ghost`. Paint its **Initial cells** layer with the supplied tileset: seven colors, neutral garbage, goal garbage, core, and charge. Include one rank for each solo mode you offer and ranks 1–15 for each challenge track you offer. The starting map selects the initial mode and rank; its seed makes restarts reproducible. The game derives its mode selector from the world's maps. Duplicate ranks and missing stages produce source-specific loading errors. The `mode` must name an existing track; see [Challenge tracks](challenges.md).

The world uses native Tiled lists for `phonics.modules`, and every board map selects `@php-tris/stage` in its `phonics.systems` list. Place one `templates/board.tx` object. Its nested `Components` preset creates the five independent `Board`, `Active`, `Queue`, `Input`, and `Run` components. Mode and seed stay on the map, so the piece queue and run share one configuration. The portable `phonics.classes.json` records the project defaults and explicit component bindings.

A selected board owns its components and systems for the run. Restarting or choosing another mode releases them. Challenge rank changes retain the run and read the next map as stage data. The legacy `#board` factory and `#rules` module remain available for standalone PHP composition.

Keep paths package-relative so the same world works in the repository preview and packed distribution. After changing resources or modules, run `phonics integrity` to refresh the manifest hashes, then `phonics validate --world worlds/arcade.world`.

The executable entry scripts are lifecycle modules rather than reusable PHP types; [Architecture](architecture.md) describes them.
