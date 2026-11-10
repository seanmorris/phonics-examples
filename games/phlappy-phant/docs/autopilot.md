# The perfect player

Phlappy Phant hides a player that cannot lose. Enter the Konami code before the first point and `Simulation::plannedFlap` decides every flap for the rest of the run. It is limited to the speed of a human thumb, which makes it more than an Easter egg: when a player that never taps faster than a person can still never bonks, the course it flew was passable.

## Summoning it

Enter Up, Up, Down, Down, Left, Right, Left, Right, B, A on the ready screen or early in a run, while the score is still zero. On a controller that is the D-pad or left stick and the B and A buttons. On a keyboard it is the arrow keys and the B and A keys.

- Each step must follow the previous one within a second and a half. Unrelated presses in between do not cancel the code.
- Up is also a flap. While a code is in progress on the ready screen the flap is held back, so the first steps do not start the run and leave the phant falling while the rest is entered. If the code is abandoned, flaps work again once its timeout passes.
- When the code lands, the score bell rings and the player takes over. From then on, one pixel in the top-left corner winks for a tenth of a second every two and a half seconds. Nothing else on screen changes.
- Its runs are not the player's. They never reach the best score, and the next run starts under manual control again.

`game.php` watches for the code with the engine's `Sequences` recognizer, fed each frame from the input layer's snapshot of the standard buttons.

## Why reacting is not enough

The obvious autopilot flaps whenever the phant sinks below the middle of the next gap. It works at the start and fails once the course speeds up, for three reasons.

- A flap is not adjustable. It always sets the same upward speed, which carries the phant up about 41 units before it starts to fall, and the gap leaves only 76 units of room at its narrowest.
- Pillars arrive every 0.85 seconds at the speed cap, and consecutive gaps can sit 112 units apart.
- The phant is clear of both pillars for only a third of a second between them. From rest it falls about 68 units in that time, so a long drop is out of reach unless it was already low in the previous gap.

So the phant has to start moving toward the next gap while it is still inside the current one, and how early depends on where both gaps are. That is planning, and the planner below does it exactly.

## Velocity as a counter

The state of the flight is small. A flap replaces the vertical speed with a fixed value, and gravity then adds a fixed amount each step until the fall speed reaches its cap. The speed is therefore a function of one whole number, the count of steps since the last flap, and that count only matters up to 44, where the cap is reached. Together with the height, one of 45 counter values describes the phant completely.

The pillars are just as predictable. They only scroll, and their speed depends on the score, which rises at moments the planner can compute. For each of the next 180 steps it advances the pillars exactly as the run will and writes down the band of heights where the hitbox touches nothing: below the top of the world, above the ground, and inside the gap whenever a pillar overlaps the hitbox. Each band is shrunk by one unit so rounding never counts as safe.

## The safe sets

A height is **safe** at some step, for some counter value, if at least one sequence of future flaps keeps the phant inside every band up to the horizon. The planner builds these sets backward, starting at the horizon, where everything inside the band is safe:

```text
safe(t, k) = band(t) ∩ ( coast(safe(t + 1, k + 1))  ∪  flap(safe(t + 1, 0)) )
```

Here `coast` shifts a set by the distance the phant falls in one step with counter `k + 1`, and `flap` shifts it by the rise of a fresh flap. The flap term is only included when `k + 1` is at least 12. That single condition is the human limit: 12 steps at 60 Hz is 200 ms, so no plan ever relies on two taps closer together than a fifth of a second.

Each set is a short sorted list of height intervals. Shifting, clipping to a band, and merging overlaps are a few comparisons per interval, and in practice a set is one or two intervals.

## Choosing the flap

With the sets in hand the decision is two lookups: is the height after coasting safe, and is the height after flapping safe. If only one is, the player takes it.

When both are safe the player has a choice, and it uses it to fly well instead of merely surviving. A player that always flaps as late as possible skims the top of every lower pillar. So it looks at the arc it is on: if the top of that arc would stay more than 24 units below the middle of the next gap, it flaps now. A flap rises about 41 units, so starting one 24 below the middle makes the arc straddle the middle. The safe sets still have the last word, because the early flap is only taken when the height it leads to is safe.

The player still taps far less often than the limit allows. In the test run described below it averages about two taps a second.

By construction, a safe height always has a safe successor, so a player that starts inside the sets stays inside them. The run begins safe: the first pillar is 420 units away.

## How far to look

The sets are only as good as the horizon. With a short horizon a height can look safe because the planner cannot yet see that the gap after next is out of reach from there. This was observed during development: with a 90-step horizon and the tapping limit, the player occasionally found itself in a position that the next plan, seeing one pillar further, marked as hopeless.

The horizon is 180 steps, which is three seconds, or three and a half pillar spacings at the speed cap. At that depth, being safe to the horizon and being safe indefinitely agree in practice, and the sweep described below is the evidence.

## Cost

A full backward pass fills 180 steps of 45 sets each, which takes about four and a half milliseconds in native PHP. Paying that in one step would cost a frame now and then, so the work is spread out. The pillars only scroll, so a plan stays valid as the run advances, and the player keeps the rows for the next sixty steps. When eighteen of them remain it snapshots the course and builds the next plan twelve backward rows at a time, one batch per step, so the new plan is ready before the old one runs out and no step pays for a whole pass. Even the last row of a plan has two seconds of proven flight ahead of it.

## What it shows

The speed and gap ramps were tuned by hand. The player checks them: if it survives a course without tapping faster than five times a second, a person could in principle fly that course too.

The package's native tests run it for a minute of game time on a fixed seed, long enough to pass the speed cap and reach the narrowest gap. They assert that it is still flying, that it has scored at least 60, that no two flaps came closer than 12 steps, that it flapped on fewer than a quarter of the steps, and that it crossed the gaps around their middle instead of hugging the lower pillar. A metronome that flaps every 24 steps is run on the same seed as a control, and it bonks within ten seconds.

A wider sweep is kept out of the regular tests because it takes about two minutes:

```sh
php games/phlappy-phant/tests/fairness.php
```

It flies forty seeded courses for ninety seconds each, far past the point where the speed and the gap stop changing, and fails if any run bonks or any two taps come closer than 200 ms. Pass a seed count and a duration in seconds to change the sweep; `npm run test:phant:fairness` runs the default.

The browser test enters the code through the keyboard, follows the player until it has scored ten and the sky has turned to night, and checks that the stored best score is still zero afterward.
