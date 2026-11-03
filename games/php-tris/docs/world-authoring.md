# World authoring

The package manifest, `phonics.json`, declares the application bootstrap, browser module, `#rules` module, `#board` factory, `#arcade` world, and the files available to the executable PHAR. `application.php` selects the game entry. The supplied `worlds/arcade.world` selects its content through these custom properties:

```text
phonics.import.php-tris = ../phonics.json
phonics.start = endless-01.tmj
```

Each map is a finite 10×20 grid with the map properties `mode`, `name`, `rank`, `seed`, `lineTarget`, `duration`, and `ghost`. Paint its **Initial cells** layer with the supplied tileset: seven colors, neutral garbage, goal garbage, core, and charge. Include one rank for each solo mode you offer and ranks 1–15 for each challenge track you offer. The starting map selects the initial mode and rank; its seed makes restarts reproducible. The game derives its mode selector from the world's maps. Duplicate ranks and missing stages produce source-specific loading errors. The `mode` must name an existing track; see [Challenge tracks](challenges.md).

Keep paths package-relative so the same world works in the repository preview and packed distribution. After changing resources or modules, run `phonics integrity` to refresh the manifest hashes, then `phonics validate --world worlds/arcade.world`.

The executable entry scripts are lifecycle modules rather than reusable PHP types; [Architecture](architecture.md) describes them.
