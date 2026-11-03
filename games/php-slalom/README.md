# PHP Slalom

PHP Slalom is a deterministic PHP/Phonics port of the rare Jet Slalom Java game, originally programmed by MR-C. It preserves the original 55 ms course simulation, steering response, stage thresholds, road generator, perspective, craft frames, palette, and impact timing while adding a responsive browser shell, saved input bindings, local score tables, and a compact replay format. The same attribution appears in the in-game Credits screen.

Play the packaged world at `/games/php-slalom/`, or run `dist/game.phar` through `Phonics.run`.

## Controls

Steer with Left/Right, A/D, J/L, the mouse buttons, the controller D-pad, or the controller left stick. Escape, P, and controller Start pause. Touch devices expose two full-width steering zones. Keyboard/mouse and controller bindings can be edited independently from **Input settings**.

New game starts at score zero. Stage checkpoint starts at one of the six original score thresholds and records the result separately. Fresh and checkpoint runs each keep ten local scores. The latest run can be replayed or exported as a `.pslr` file; imported files are size-limited, versioned, and checksum-validated.

## Package

The `@php-slalom/game` export owns the browser host and PHP lifecycle. The `#slalom` asset export names the example world. The simulation and replay codec have no browser dependency, so native PHP tests can verify the exact fixed-step course.

```text
phonics.import.php-slalom = ../phonics.json
phonics.start = slalom.tmj
```

The two craft strips and impact sample were derived from the supplied Jet Slalom archive for this preservation port. The original JAR is not distributed by this package. The port deliberately defines a new replay format; it does not attempt compatibility with recordings from the Java build.

See `docs/` for gameplay, replay, architecture, and engine-integration details.
