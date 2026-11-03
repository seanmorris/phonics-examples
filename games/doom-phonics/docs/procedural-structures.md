# Parametric room structures

E1M4 provides useful examples of nested contours, circulation around a central structure, and neighboring rooms shaped by shared walls. The seeded generator uses those construction ideas with new dimensions and layouts. It does not copy the level.

## Measurements from E1M4

These measurements come from the local demo `DOOM1.WAD`, using zero-based editor sector and linedef numbers. A sector is a floor/ceiling region: lighting, stairs, recesses and holes can divide one architectural room into many sectors.

| Structure | Measured geometry | Useful parameters |
| --- | --- | --- |
| Central chamber, sector 103 | Center `(32, 672)`; 448 × 448 bounds; octagon with 128-unit corner cuts; four 128-unit entrances; floor 144, ceiling 336 | Center, half-extents, corner cut, entrance width, floor, headroom |
| Structure surrounding that chamber | Sector 6's inner contour has 12 sides and 608 × 608 bounds, centered at the same point | Separate outer contour, entrance recesses and decorative pockets |
| Outer circulation, sector 6 | Outer contour has 16 sides after removing collinear vertices; 1,120 × 1,088 bounds; cardinal gaps to the inner contour are 240 units east/north/south and 272 west; floor 136, ceiling 328 | Outer contour, minimum circulation clearance, asymmetry, elevation difference |
| A thin diagonal wall | Linedefs 130 and 677 are opposing one-sided faces on `y − x = 960` and `y − x = 984`; perpendicular separation is `24 / sqrt(2)`, approximately 16.97 units | Shared normal, offset distance, finite overlapping span |
| Straight stair run, sectors 59–66 | Seven 32-unit treads followed by a 96-unit landing; successive floors rise 8 units, from 136 to 192 | Tread depth, riser height, count, landing depth |

The chamber's outer octagon has area 167,936 square units. Sector 103 also contains a separate 48 × 48 hole, so its exposed floor area is smaller. Likewise, the 80-unit difference between the central chamber's cardinal extent and its surrounding structure includes recesses and other sectors; it is **not** a uniform 80-unit solid wall. The outer hall is faceted and slightly asymmetric, rather than an exact circle.

## Walls as shared solid strips

For an integer normal `n = (nx, ny)`, write a dividing line as:

```text
nx*x + ny*y = d
```

Give its neighboring rooms faces at `d − h` and `d + h`. Their perpendicular separation is:

```text
wall thickness = 2*h / sqrt(nx*nx + ny*ny)
```

Both faces use the same normal, so they remain parallel throughout their overlapping span. Intersect each room's adjacent face lines to form its corners. At junctions the reserved strips join into solid material; the room interiors never overlap. Doorways cut rectangular passages across those strips. Recessed doors subdivide a passage into fixed lintels and a moving panel.

This describes the small voids between E1M4's walls. In a Doom map, the void itself need not be a sector: the two neighboring air spaces supply opposing one-sided linedefs. A two-sided sector boundary may instead represent a step, light change or opening, so it cannot automatically be classified as a solid wall.

## Nested polygons and radial clusters

A chamfered square centered at `(cx, cy)`, with half-extent `a` and corner cut `c`, can be described by four pairs of inequalities. With `u = x − cx` and `v = y − cy`:

```text
|u| <= a
|v| <= a
|u + v| <= 2*a − c
|u − v| <= 2*a − c
```

Sector 103's outer contour uses `a = 224`, `c = 128`. It is not a regular octagon: its cardinal sides are 192 units long and its diagonal sides are approximately 181.02 units long. Different cut lengths, separate horizontal/vertical extents, and unequal support distances generate related polygons without assuming perfect rotational symmetry.

For a surrounding circulation hall, subtract an inner structural contour from an outer contour and split the resulting ring into convex sectors. Entrances connect the central chamber across the intervening structure. The center can hold a prize, ordinary room, staircase or recessed portal; its purpose is independent of its shape. The existing upper hub uses a faceted outer hall and four optional door entrances around its portal well.

For the ground cluster, `OutlinePacking::cluster()` first unions a broad octagonal body and two tapered wings. The result has concave exterior bays. `ConformingRooms::radial()` packs an octagonal core and peripheral partitions into that outline. `RoomIntrusion::partition()` then subtracts an invading polygon from those partitions and gives the new space its own logical owner. Shared wall offsets follow the new cut, so neighboring faces remain parallel. Small surviving wedges join neighboring rooms instead of becoming cramped separate rooms. Convex fragments with the same owner have open seams; they are not extra walls.

The current safe seeded family uses:

| Parameter | Values |
| --- | --- |
| Maximum exterior span | 2,400 × 2,400 units |
| Main body span | 2,016 × 2,400 units, with tapered wings restoring cardinal entrances |
| Main body corner cuts | 240 or 288 units |
| Core partition half-extent | 576 units |
| Core partition corner cut | 288 units |
| Minimum wall thickness | 128 units; snapped offsets make actual thickness slightly larger |
| Minimum clear logical room diameter | 384 units; smaller cut fragments merge with neighboring rooms |
| Minimum passage width | 128 units |

The portal hub uses only 90, 180 or 270 degrees of its faceted outer arc. Straight gallery bays occupy the remaining sweep. Its four entrances remain independently usable, with optional BIGDOOR2 doors, and the center remains a recessed portal well.

The core partition is measured **before** reserving its wall thickness. Walls use shared exact equations, not independently snapped polygons. The six-unit contour grid and offset increments compatible with each normal's squared length keep all emitted vertices integral. Oblique openings use the same compatible lattice. Openings wider than BIGDOOR2's 128-unit panel remain open; the generator no longer uses half-width passages. The reusable door helper still supports left/right texture crops for other authored layouts.

The helper accepts a contour, center, radius and wall width. Its current radial construction uses an octagon with corner cuts equal to half the radius, and requires a radius divisible by 12. Arbitrary rotations, arbitrary cut ratios and bent multi-segment wall networks are not yet supported by this helper. New parameter families must pass clearance, integer-coordinate, overlap and runtime traversal checks; a mathematically valid partition alone does not establish player connectivity.

## Curved stairs and short room joins

For `N` treads through sweep `S`, each tread turns by `delta = S/N` and rises by the configured step height. Its four corners lie on two concentric faceted arcs:

```text
angle(i) = i * delta
inner(i) = round(r * [cos(angle(i)), sin(angle(i))])
outer(i) = round(R * [cos(angle(i)), sin(angle(i))])
floor(i) = starting floor + (i + 1) * rise
```

Adjacent treads reuse the same rounded endpoints. The inner radius is at least `(minimum tread depth + margin) / (2*sin(delta/2))`, so clipping out the origin cannot leave a thin triangular tip in the walking path. The radial band grows by approximately `width/cos(delta/2)`, with a rounding margin, to preserve the requested clear width between its straight facets. Tests measure the emitted polygons and walk the stairs in both directions using game physics.

`SpiralStaircase` accepts 90-, 180- and 270-degree sweeps and mirrored variants, with 160-unit-wide treads in SEEDED. The inter-storey hall selects 90 or 180 degrees. `CornerStaircase` uses gradually turning treads through 90 degrees to reach a ledge along a room wall. Its step count follows the difference between a room’s low and high entrances. It reserves a flat landing across the higher doorway and preserves the low floor inside the arc.

Ordinary room footprints are bounded separately from their placement bays: long stair approaches can enlarge a bay without enlarging its room. `RoomJoin` considers rectangular neighbors with matching floor and ceiling planes and a gap of at most 256 units. It replaces the short hall with their full overlapping wall span, at least 384 units wide. A bounded combined area prevents the join from recreating oversized rooms. Offset rectangles produce L-shaped or stepped unions; their missing corners remain solid space. These pairs are preferred over unions that would be another rectangle.

## Trench rooms and directional circulation

E1M4 sectors 95, 96 and 97 share a ceiling at 128. Sector 95 is a parallelogram trench with 128 units of vertical separation between its long edges with floor −64; sectors 96 and 97 have floors at zero. Both long trench boundaries have displacement `(672, 64)`, so their slope is exactly `2/21`. The parallel faces give the space a coherent direction.

Sector 97 connects at floor zero to sectors 84 and 121. The trench connects at floor −64 to sectors 98 and 110. Crossing from either high neighbor into the trench is a 64-unit drop, beyond the normal 24-unit step-up limit in the reverse direction. Sector 96 is a narrow raised strip with no other two-sided exit in this local group. These are sector transitions, not an assumption that every entrance shares one elevation or connects directly to every other entrance.

`RoomElevation` applies entrance heights to the room itself: opposing entrances occupy low and high landings, and broad 16-unit stairs connect them. Side entrances attach to a flat middle landing. Shared passage anchors remain fixed, so the change does not introduce a down-and-up detour in adjacent hallways. The older trench/bowl helper remains available for experimentation, but those center pits are no longer generated automatically. The runtime-derived graph records individual drop directions, staircase connections and water-dependent movement; the report also summarizes paths between each room's entrances without allowing those paths to detour through other rooms. Dry ledges in the pool chamber stay at or above maximum water height and are naturally excluded by flood selection.

## Courtyards and edge gardens

The same polygon subtraction supports a fully enclosed courtyard or a garden meeting the outer boundary. The current ground cluster combines an enclosed, clipped octagonal courtyard with an invading edge garden. Cut edges must have enough shared span for the opening and its jambs. Necessary connections keep the surviving room graph connected; additional connections are seeded rather than opening every possible wall.

Doom II MAP01 provides a useful outdoor reference. In its western garden, sector 11 has a grass floor at 8 and sky ceiling at 264. Sector 12 forms a raised border at 64; sector 13 has both floor and sky ceiling at 64, making a solid boundary with sky above it. The other outdoor area includes water at −40 (sector 14), grass at −32 (sector 17), and a higher edge at 24 (sector 15), bounded by another collapsed sky sector (16). Thus an outdoor space can contain several elevations and distinct routes, rather than being one flat rectangle.

`OutdoorSpace` uses that sky-wall construction with new dimensions: an 80-unit raised solid edge and a sky ceiling collapsed onto it. It reserves room entrances before choosing the edge. The open sky ceiling is 32 units above the adjacent indoor ceiling. Upper wall textures cover that difference above openings, giving the building a visible roof edge. Garden floors remain continuous with their entrances; decorative center trenches are no longer added. A sewer network is not generated yet.

## Reproduce the measurements

From the repository root:

```sh
php packages/doom-phonics/tools/measure-map.php \
  packages/doom-phonics/demo/DOOM1.WAD E1M4 \
  scratch/e1m4-structures/measured
```

This produces a JSON report with simplified contour loops, holes, bounds, floor/ceiling heights, edge lengths and one-sided wall equations, plus an annotated SVG plan. It reads through the game's normal WAD parser. Output stays in the ignored scratch directory; the tool also accepts another WAD, map name and output prefix.

[Inspect the generated level](/engine/packages/doom-phonics/demo/seeded-navigation.html) or [play SEEDED](/games/doom-phonics/?world=/engine/packages/doom-phonics/demo/seeded-level.phonics-world.json).
