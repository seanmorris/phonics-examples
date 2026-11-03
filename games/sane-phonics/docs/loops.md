# Loop-de-loops and collision layers

Select **Loop-de-loop** in the live demo and hold Right from the start. After exiting, hold Left to return through the loop. The character needs momentum to stay attached to walls and ceilings. The status line reports the selected collision layer.

Open [`examples/assets/loop.tmj`](../examples/assets/loop.tmj) in Tiled. Its external [`loop.tsj`](../examples/assets/loop.tsj) tileset contains original procedural artwork and collision polygons. Run `php examples/generate-assets.php` to regenerate the demo assets.

Select **Grinding rail** from the pause menu's **Level** list for the extended test course; the main
menu's level list does not include it. Its
[`grinding.tmj`](../examples/assets/grinding.tmj) begins with separate grinding, ordinary-ground,
and grinding polygons sharing a level surface. Four visible quarter bends then raise and lower the
rail before it reaches the loop. [`grinding.tsj`](../examples/assets/grinding.tsj) retains matching
curved artwork and the loop's exact collision polygons. The loop layers add `grinding` to shared
plane 0 and selectable planes 1 and 2. Together they prove that grinding state leaves and resumes on
ordinary ground, follows both directions through 90-degree bends, survives wall and ceiling
adhesion, and crosses all three directional switches. The loop crown remains shared, the lower left
route is on plane 1, and the lower right route is on plane 2.

![Shared crown and floor, two selectable lower halves, and three directional gates](loop-layout.svg)

## What moving-boxes-2 does

The reference's `app/assets/map/big-loop.png` is a 256×256 collision mask divided into 64 tiles of 32×32 pixels. Opaque pixels lie outside the circular hole. Tile IDs run left to right, then top to bottom. `TileMap::getSolid` combines Collision 0 with the actor's selected collision layer.

`app/assets/map/quartz-test.json` places that tileset at tile (68,90). Collision 0 contains the first two rows of the circle, a cap above it, and the floor at row 98. Collision 1 contains the left four columns, and Collision 2 the right four columns, with padding outside the walls. The shared crown also appears in those two layers. `sonic-movement.json` uses the same route arrangement with a taller shared crown and without duplicating its tiles into the selectable layers. The file named `big-loop.json` itself uses the larger `giant-loop.png` tileset and is not the three-switch example.

The three switch objects in `quartz-test.json` are tile objects, whose Tiled anchor is at the bottom left:

| Gate | Tiled x,y | Size | Rectangle top | back |
| --- | --- | --- | --- | --- |
| Left | 2080,3144 | 64×304 | 2840 | 0 |
| Crown | 2288,2912 | 32×56 | 2856 | 1 |
| Right | 2464,3144 | 56×304 | 2840 | 0 |

`LayerSwitch::collideA` chooses the destination from the sign of ground speed. Positive speed at a normal gate selects layer 2; negative speed selects layer 1. The crown's `back` flag reverses that rule while grounded. Ground speed retains its sign around the loop, even though horizontal velocity reverses at the ceiling. Airborne passage uses horizontal direction and cancels `back`. The reference checks destination occupancy before switching and retries a blocked switch later.

Coming from the left, layer 2 makes the near left wall passable and the far right wall solid. The character climbs the right wall. At the crown, switching to layer 1 keeps the descending left wall solid and makes the right exit passable. Both outside gates use the same settings, so the route also works in reverse.

## PHP map layout

The demo places the circle at (768,192), with center (896,320) and floor y=448. It uses the same division of the 8×8 tiles, without duplicating the shared crown:

| Local tile rows | Columns 0–3 | Columns 4–7 |
| --- | --- | --- |
| 0–1 | Collision 0 | Collision 0 |
| 2–7 | Collision 1 | Collision 2 |
| Floor below row 7 | Collision 0 | Collision 0 |

Tiles retain their original positions in the circle. Splitting the collision layers does not translate or flip either half. The left gate covers x=704–736, the right gate x=1056–1088, and both span y=176–464. The crown gate covers x=880–912 and y=168–232 with `back=true`.

The collision polygons follow the circular boundary and meet at tile edges. PHP uses those Tiled shapes; it does not interpret PNG alpha as collision geometry. Rendering follows the ordinary map layer order and visibility, independently of the body's selected collision layer. An optional art layer can reuse tiles while setting `collision=false` to avoid adding another collision copy.

## Authoring properties

Set the integer **collisionLayer** property on collision tile or object layers. Zero means shared terrain. Positive values select one additional plane, and `Body::$collisionLayer` defaults to 1. Names such as `Collision 0`, `Collision 1`, and `Collision 2` also work when the property is omitted. Other layers default to shared terrain; their tiles still need collision shapes or `solid` metadata. An individual solid object can override its layer's `collisionLayer`.

Create rectangle objects with class **layer-switch** (or **LayerSwitch**). Convex polygon regions also work. They are nonsolid and recognize these custom properties:

| Property | Default | Meaning |
| --- | --- | --- |
| fromLayer | 1 | Source for positive travel through a normal gate; destination for negative travel |
| toLayer | 2 | Destination for positive travel through a normal gate; source for negative travel |
| back | false | Reverse the direction rule while grounded, as needed at a loop crown |
| roll | false | Require a rolling body no taller than 28 pixels |

The two layer IDs must be distinct positive integers. A gate only switches from the expected source; unrelated planes remain selected. A body on layer 0 may enter either route. Rectangles use Tiled's top-left origin, while tile objects use their bottom-left anchor. Object rotation, group offsets, map placement, and isometric object projection are applied before collision queries.

PHP gates use the **body center**. Give entrance gates enough height to include grounded and jumping bodies, place them outside the walls, and place the crown gate in shared terrain where either destination is clear. Grounded direction uses tangent speed, then horizontal velocity or the retained movement direction when stopped. Airborne direction uses horizontal velocity, then retained movement direction. Rolling restrictions leave an ineligible body on its current layer.

## Continuous switching and collision

`Platformer::step()` moves each body through a private sweep that compares the first terrain impact with the first gate crossing along the entire displacement. It moves to whichever comes first. After a switch, it casts the remaining displacement against the new plane. Multiple gates are processed in travel order, regardless of object order, and do not consume the six-contact terrain budget. A wall before a gate prevents reaching that gate. Narrow regions cannot be skipped by a fast dash or airborne move.

`Terrain::castBody` expands terrain segments by the oriented body to prevent tunneling. Short `Terrain::raycast` calls keep the feet attached and handle clearance and climbing. Both queries include shared terrain plus the body's selected plane. Opposite tile edges cancel only when both neighboring shapes are active, including joins between shared terrain and a selected half.

Before switching, the engine rejects a solid destination at the body center or a newly active solid edge intersecting its interior. That second check catches thin obstacles between sample points. Existing shared contact surfaces do not block the switch. A rejected gate is retried on subsequent steps while the body remains inside it; the engine never teleports the body out of destination geometry.

Tests traverse the actual Tiled map clockwise and counterclockwise, walking and rolling, including 6000 px/s runs. Separate regressions cross subpixel gates at 60000 px/s, check destination clearance and tile seams, and verify jumps and loss of adhesion. The live browser test runs through the loop in both directions using keyboard input.
