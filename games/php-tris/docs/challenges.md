# Challenge tracks

PHPTris includes five challenge tracks with fifteen ranks apiece. A track selects an objective and progressively tighter target values while retaining the ordinary piece and scoring rules.

Challenge state records the active track, rank, progress, target, completion, and failure conditions. The HUD reads that state without owning it, which keeps challenge evaluation testable without WebGL or browser input.

The track list is fixed in PHP. Each rank map supplies its name, seed, ghost setting, and starting cells, while the rank targets come from `Run::target()` in `src/Component/Run.php`. A map whose `mode` is not a key of `PhpTris\Module::MODES` fails to load (`browser/game.mjs` and `src/Module.php` both reject it). A new track therefore needs PHP changes: a `Module::MODES` entry, its target in `Run::target()`, objective scoring in `src/System/ResolutionSystem.php`, any piece-bag or board rules in `src/Component/Queue.php`, `src/MatchState.php` and `src/Rules.php`, and HUD text in `src/Render/Scene.php`.
