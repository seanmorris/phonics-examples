# WAD and DECORATE support

The archive layer indexes lumps, namespaces, maps, textures, flats, palettes, sprites, sounds, music, and text definitions. Map loading supports Doom and Hexen-format geometry plus the sector, linedef, and thing metadata needed by ZDoom-family maps.

DECORATE parsing supplies actor inheritance, replacements, editor numbers, states, sprites, sounds, inventory definitions, projectiles, and supported action functions. Built-in adapters cover Doom actors while package or loose definitions can add Skulltag and ZDoom actors without modifying the engine.

A map whose things include an editor number with no actor definition fails to load with an `Unresolved WAD thing types` error that lists the missing numbers. For inspection worlds, set `inspectUnsupportedThings: true` in the `@doom/episode` binding's `options`, as the D2ST9 stress world does; the map then loads without those things, and their editor numbers appear as `skippedThings` in the session diagnostics. Decorative particle fountains and mode-specific score objects can degrade to presentation-only or inert actors when their complete game mode is unavailable.

Map specials include doors, floors, ceilings, lifts, switches, teleports, scrollers, lights, damage, exits, and mutable textures. The renderer resolves missing upper and lower wall spans against adjacent sector heights.
