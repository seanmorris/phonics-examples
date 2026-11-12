# Authoring courses in Tiled

Sane's project provides 34 concrete entity presets and placement templates, backed by nested settings classes. Open the project before opening a map so Tiled can display the inherited fields. The game still owns its movement, combat, rendering, and activation rules.

## Open the project

Run `npm install` at the examples repository root, then open [`worlds/demo.tiled-project`](../worlds/demo.tiled-project) in Tiled 1.12 or later. Enable the project extension when prompted. Its project-relative extension directory points into the installed Phonics dependency; it does not depend on another developer's home directory.

The project exposes the worlds, course assets, and `templates` folder. It contains 64 custom classes: 34 entity presets, 27 settings classes, and the three existing player/spawn classes. If only a map is open, load the project first rather than recreating missing properties by hand.

Open a course world and its map. Worlds name the available modules in the native `phonics.modules` list. Maps select `@sane/demo-map` through `phonics.systems`; the streaming world additionally selects `@sane/spatial-maps`. Edit these as list entries in Tiled's Properties panel.

## Place and configure an entity

Select an object layer and drag a template from the project's `templates` folder into the map. A template supplies its concrete class, nested settings, shape, and any tileset reference. Tile-backed templates such as rings and monitors have artwork previews; regions and path nodes use their authored geometry.

For example, place [`triple-ring-monitor.tj`](../templates/triple-ring-monitor.tj). Its class is `SanePhonics.Entity.TripleRingMonitor`. Expand **Monitor** to change the break score or collision plane, and **RingMonitorEffect** to change the ring reward, initially 30. Normal ring monitors start at 10. The five shield-monitor templates select their shield through the entity preset.

These are nested class fields, not manually named properties containing dots. A placement overriding only the reward can contain this property record:

```json
{
  "name": "RingMonitorEffect",
  "type": "class",
  "propertytype": "SanePhonics.RingMonitorEffect",
  "value": {"amount": 40}
}
```

Unchanged values inherit the class and template defaults; Tiled may omit them from the map. Keep the project, templates, and portable `worlds/phonics.classes.json` catalog together. Two settings classes can use the same field name without sharing its value. For example, a field displayed as `Monitor.points` belongs inside the `Monitor` value.

Use [`spawn.tx`](../templates/spawn.tx) for the persistent demo player's start point. It carries a `SpawnPoint` component. The older `Player` spawn class and standalone [`player.tx`](../templates/player.tx) factory remain supported, but a standalone player factory is different from a spawn marker for the demo's existing player.

## Available templates

All `.tj` presets use the `SanePhonics.Entity.` class prefix. The following names are relative to `templates/`; the settings column names the expandable groups in Tiled.

| Templates | Settings |
| --- | --- |
| `ring.tj` | `Ring`: pickup radius, ring value, score |
| `ring-monitor.tj`, `triple-ring-monitor.tj` | `Monitor`, `RingMonitorEffect` |
| `normal-shield-monitor.tj`, `electric-shield-monitor.tj`, `fire-shield-monitor.tj`, `water-shield-monitor.tj`, `star-shield-monitor.tj` | `Monitor`, `ShieldMonitorEffect` |
| `crab-meat.tj`, `mini-boss.tj`, `boss-region.tj` | `CrabMeat`, `MiniBoss`, or `BossRegion` |
| `floating-platform.tj` | `MovingPlatform` |
| `moving-platform.tj` | `MovingPlatform`, `PlatformOscillation` |
| `path-platform.tj`, `path-node.tj` | `MovingPlatform` + `PlatformPath`, or `PathNode` |
| `bendable-bridge.tj`, `bendable-rail.tj` | `BendableBridge` |
| `block.tj`, `water-region.tj`, `switch.tj` | `BuoyantPlatform`, `WaterRegion`, or `WaterSwitch` |
| `grapple-point.tj`, `rope-anchor.tj` | `GrapplePoint` or `RopeAnchor` |
| `spring.tj`, `gravity-switch.tj` | `Spring` or `GravitySwitch` |
| `skateboard.tj`, `tornado.tj`, `egg-walker.tj` | Settings named for the vehicle |
| `vehicle-region.tj`, `drop-vehicle-region.tj` | `VehicleRegion` |
| `curved-ceiling.tj`, `grinding.tj` | `Terrain` |
| `layer-switch.tj`, `breakable.tj`, `text-actor.tj` | `LayerSwitch`, `BreakableBlock`, or `TextActor` |

Rings can be painted as tiles or placed independently with `ring.tj`; both become collectible entities. Placed rings respect visibility, offsets, rotation, flips, and scale. The `Ring` settings are the same for both forms. This is Sane's game-specific promotion of ring artwork, independent of the framework's optional **Phonics Tile Entities** modes.

Ordinary demo courses discover placed enemies, monitors, and breakables, so adding one does not require changing the selected course's name. The **Streaming world** demonstration retains its separate spatial loading/rendering flow; it does not automatically install the full platformer gameplay stack in every resident map.

## Connect objects and choose defaults

Templates deliberately contain no copied demo object IDs. Use Tiled's object picker to connect targets in the same map after placement.

| Nested field | Target or behavior |
| --- | --- |
| `GrapplePoint.tiedTo` | A rope anchor or supported moving suspension object |
| `BossRegion.boss` | The boss controlled by this arena |
| `WaterSwitch.water` / `setPoint` | The water region and an object marking its target surface height |
| `PlatformPath.path` / `ridePath` | First path node; `ridePath` runs while occupied |
| `PathNode.next` | Next node; zero ends the route and linking back makes a loop |
| `BuoyantPlatform.water` | Water region; zero retains the loader's first-water fallback |

An unset platform route leaves it stationary. `MovingPlatform` starts with zero oscillator excursions; set `PlatformOscillation.oscillateX` or `oscillateY` to make it travel. A text actor starts empty; set `TextActor.content` to display text.

`collisionLayer = -1` preserves the loader's inherited/default plane; zero means shared terrain and positive values select a plane. Layer-level collision metadata remains supported. For springs and gravity switches, `length = 0` uses the object width with a minimum of 32 pixels, and a zero `normalX`/`normalY` pair derives direction from object rotation. Keep the existing shape restrictions described in [Gameplay systems](gameplay.md), such as unrotated moving-platform collision rectangles.

Units follow the existing loaders. Enemy timing and several motion settings retain 60 Hz reference-frame units; for example `PlatformPath.pathSpeed` is pixels per reference frame, while `PlatformOscillation.squinePeriod` is seconds. The gameplay reference documents these conversions. Nesting the fields does not change their units.

## Save, validate, and rebuild

Save the project as well as the map and world. The extension exports class definitions to the portable catalog referenced by the world's `phonics.classes` file property. The runtime needs that catalog because map JSON does not contain every inherited default. After adding assets, include them in the package's declared resources before packaging.

From `games/sane-phonics`, refresh integrity, validate the chosen world, and build the application:

```sh
npx phonics integrity
npx phonics validate --world worlds/demo.world
npx phonics pack
```

Select the corresponding course world when validating another course. **File → Validate and Preview Phonics World…** checks saved content and opens a running host with the application and world URLs. It does not rebuild or publish the PHAR; rebuild the application when changing bundled content.

For schema development, [`authoring.mjs`](../authoring.mjs) is the source of the shipped classes and preset defaults. From the game directory, `node examples/generate-presets.mjs` regenerates the project class definitions, catalog, and 34 templates. This replaces generated defaults and templates, so it is not an ordinary save step for hand-edited courses. Review those changes and regenerate integrity afterward. Course generators preserve nested values but regenerate their demonstration maps; use them only when intentionally rebuilding those examples.

## Runtime ownership and compatibility

[`SanePhonics\Tiled\Authoring`](../src/Tiled/Authoring.php) resolves the entity presets and nested values before Sane's existing terrain and feature loaders build their ECS components. These settings classes are not new global framework component registrations. The adapter preserves the original map data for inspection, and legacy flat classes/properties remain readable. The [gameplay reference](gameplay.md) documents those loader names alongside the current authoring fields.

Phonics also supports direct component-export bindings for objects, layers, maps, worlds, and optional tile entities. World/map scope systems decide when to run, while game code decides what a layer component means, including image layers, parallax, or moving maps. Tiled remains an optional content adapter. Sane's presets are a game-owned bridge to its existing loaders; they do not turn arbitrary sprite-sheet classes into active game logic.
