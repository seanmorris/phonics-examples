<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';
require dirname(__DIR__, 3) . '/autoload.php';
require __DIR__ . '/fixtures/EpisodeMovement.php';

use DoomPhonics\Archive\Archive;
use DoomPhonics\Archive\Stack;
use DoomPhonics\Graphics\Palette;
use DoomPhonics\Graphics\TextureCatalog;
use DoomPhonics\Graphics\IndexedAtlas;
use DoomPhonics\Graphics\Fon2Font;
use DoomPhonics\Definition\DecorateParser;
use DoomPhonics\Definition\LanguageCatalog;
use DoomPhonics\Audio\SoundCatalog;
use DoomPhonics\Audio\DmxSound;
use DoomPhonics\Audio\SoundSample;
use DoomPhonics\Audio\SoundEmitter;
use DoomPhonics\Audio\SoundEvent;
use DoomPhonics\Audio\MusicCue;
use DoomPhonics\Audio\SoundCue;
use DoomPhonics\Adapter\ActorFactory;
use DoomPhonics\Adapter\WadAdapter;
use DoomPhonics\Adapter\WadDefinitions;
use DoomPhonics\Adapter\WadSource;
use DoomPhonics\Component\ActorState;
use DoomPhonics\Component\BeamEffect;
use DoomPhonics\Component\Body;
use DoomPhonics\Component\FloorClip;
use DoomPhonics\Component\Gravity;
use DoomPhonics\Component\HitPoints;
use DoomPhonics\Component\MapThing;
use DoomPhonics\Component\MonsterBrain;
use DoomPhonics\Component\NetworkPlayer;
use DoomPhonics\Component\PlayerCommand;
use DoomPhonics\Component\PlayerStart;
use DoomPhonics\Component\Projectile;
use DoomPhonics\Component\ProjectileBounce;
use DoomPhonics\Component\StatusFace;
use DoomPhonics\Component\Transform;
use DoomPhonics\Component\TransientEffect;
use DoomPhonics\Component\Velocity;
use DoomPhonics\Component\ViewLighting;
use DoomPhonics\Component\ViewEffects;
use DoomPhonics\Component\ViewInput;
use DoomPhonics\Component\WeaponOverlay;
use DoomPhonics\Definition\ActionDispatcher;
use DoomPhonics\Definition\StateFrame;
use DoomPhonics\Definition\WeaponSlots;
use DoomPhonics\System\ActorStateSystem;
use DoomPhonics\System\ActorPhysicsSystem;
use DoomPhonics\System\BeamEffectSystem;
use DoomPhonics\System\CombatSystem;
use DoomPhonics\System\EpisodeNetworkSystem;
use DoomPhonics\System\PickupSystem;
use DoomPhonics\System\PlayerMovementSystem;
use DoomPhonics\System\PlayerEffectSystem;
use DoomPhonics\System\PlayerCommandSystem;
use DoomPhonics\System\SectorEffectSystem;
use DoomPhonics\Game\Collision;
use DoomPhonics\Game\ActorObstacles;
use DoomPhonics\Game\BloodEffects;
use DoomPhonics\Game\DamageEvent;
use DoomPhonics\Game\DamageResolver;
use DoomPhonics\Game\FaceDirection;
use DoomPhonics\Game\HitscanImpact;
use DoomPhonics\Game\Level;
use DoomPhonics\Game\LevelIntermission;
use DoomPhonics\Game\LevelProgression;
use DoomPhonics\Game\LineSpecials;
use DoomPhonics\Game\PlayerState;
use DoomPhonics\Game\DoomMatch;
use DoomPhonics\Game\EnemyAwareness;
use DoomPhonics\Game\ProjectileImpact;
use DoomPhonics\Game\ProjectileTracer;
use Phonics\Network\MatchContext;
use Phonics\Network\PeerId;
use Phonics\Network\PlayerSlot;
use DoomPhonics\Game\GameSettings;
use DoomPhonics\Game\PitchMode;
use DoomPhonics\Game\RenderResolution;
use DoomPhonics\Game\SkillLevel;
use DoomPhonics\Game\CheatCommand;
use DoomPhonics\Game\CheatSequence;
use DoomPhonics\Game\DoomInitialization;
use DoomPhonics\Render\MapMeshBuilder;
use DoomPhonics\Render\MapMesh;
use DoomPhonics\Render\CameraHeight;
use DoomPhonics\Render\MeltColumns;
use DoomPhonics\Render\Raycaster;
use DoomPhonics\Render\RenderViewport;
use DoomPhonics\Render\SkyOcclusionMesh;
use DoomPhonics\Render\ViewProjection;
use DoomPhonics\Render\ViewWeaponPlacement;
use DoomPhonics\Graphics\ViewColorState;
use DoomPhonics\Menu\NetworkMenuSettings;
use DoomPhonics\System\CheatSystem;
use Phonics\Ecs\Registry;
use Phonics\Ecs\Scheduler;
use Phonics\Input\Controls;
use Phonics\World\AdapterContext;
use Phonics\World\ScopeContext;
use Phonics\World\SourceDefinition;

$checks = 0;

function verifyWad(bool $condition, string $message): void
{
	global $checks;
	$checks++;

	if(!$condition) throw new RuntimeException($message);
}

$lobbyConfiguration = ['homeserver' => 'https://matrix.org','room' => '#doom-phonics:matrix.org'];

$networkSettings = new NetworkMenuSettings(
	static fn(): string => json_encode($lobbyConfiguration, JSON_THROW_ON_ERROR)
	, static function(string $value) use (&$lobbyConfiguration): string {
		$lobbyConfiguration = json_decode($value, true, 16, JSON_THROW_ON_ERROR);

		return json_encode(['configuration' => $lobbyConfiguration], JSON_THROW_ON_ERROR);
	}
);

verifyWad(
	$networkSettings->homeserver() === 'https://matrix.org'
		&& $networkSettings->lobbyChannel() === '#doom-phonics:matrix.org'
	, 'network menu reads provider defaults'
);

$networkSettings->stageHomeserver('matrix.example');
verifyWad(!$networkSettings->apply() && $networkSettings->failed(), 'network menu rejects invalid homeserver');
$networkSettings->stageHomeserver('https://matrix.example');
$networkSettings->stageLobbyChannel('#games:matrix.example');

verifyWad(
	$networkSettings->dirty() && $networkSettings->apply() && !$networkSettings->dirty()
		&& $lobbyConfiguration['room'] === '#games:matrix.example'
	, 'network menu stages and persists provider configuration'
);

/** @param list<array{0:float|int,1:float|int}> $points */
function polygonArea(array $points): float
{
	$area = 0.0;
	$count = count($points);

	for($i = 0; $i < $count; $i++)
	$area += $points[$i][0] * $points[($i + 1) % $count][1]
		- $points[($i + 1) % $count][0] * $points[$i][1];

	return abs($area) / 2;
}

$path = dirname(__DIR__) . '/demo/DOOM1.WAD';
$bytes = file_get_contents($path);
verifyWad(is_string($bytes), 'canonical shareware data is readable');

verifyWad(
	hash('sha256', $bytes) === '1d7d43be501e67d927e415e0b8f3e29c3bf33075e859721816f652a526cac771'
	, 'shareware hash'
);

$archive = new Archive($bytes, $path);
$stack = new Stack($archive);
verifyWad($archive->type === 'IWAD', 'IWAD header');

$musicPath = dirname(__DIR__) . '/DOOM1_OGG.wad';
$musicBytes = file_get_contents($musicPath);
verifyWad(is_string($musicBytes), 'episode Ogg music PWAD is readable');

verifyWad(
	hash('sha256', $musicBytes) === '2f9ba854cb354a258431bfeeb792c1e1b5046521df97e2b1448ed3c695348d21'
	, 'episode Ogg music hash'
);

$musicArchive = new Archive($musicBytes, $musicPath);
$musicStack = new Stack($archive, $musicArchive);
$musicCues = [
	MusicCue::title()
	, MusicCue::level('e1m1')
	, MusicCue::level('E1M9')
	, MusicCue::intermission()
	, MusicCue::victory()
];

verifyWad(MusicCue::title()->lump() === 'D_INTRO', 'title selects D_INTRO directly');

foreach($musicCues as $cue)
verifyWad(
	str_starts_with($musicStack->lump($cue->lump())?->bytes() ?? '', 'OggS')
	, "{$cue->lump()} resolves to Ogg music"
);

verifyWad($musicArchive->lump('D_INTROA') === null, 'music overlay does not require a D_INTROA alias');

$episodeMaps = array_map(static fn(int $map): string => 'E1M' . $map, range(1, 9));

verifyWad(
	LevelProgression::next('E1M3', true, $episodeMaps) === 'E1M9'
		&& LevelProgression::next('E1M9', false, $episodeMaps) === 'E1M4'
		&& LevelProgression::next('E1M8', false, $episodeMaps) === null
	, 'episode progression follows normal, secret, and terminal exits'
);

$doomTwoMaps = array_map(static fn(int $map): string => sprintf('MAP%02d', $map), range(1, 32));

verifyWad(
	LevelProgression::next('MAP15', true, $doomTwoMaps) === 'MAP31'
		&& LevelProgression::next('MAP31', false, $doomTwoMaps) === 'MAP16'
		&& LevelProgression::next('MAP31', true, $doomTwoMaps) === 'MAP32'
		&& LevelProgression::next('MAP30', false, $doomTwoMaps) === null
	, 'commercial progression follows normal, secret, return, and terminal exits'
);

verifyWad(
	LevelProgression::par('E1M1') === 30
		&& LevelProgression::par('MAP01') === 30
		&& LevelProgression::par('CUSTOM') === null
	, 'classic maps expose par times without inventing custom-map pars'
);

$intermission = new LevelIntermission('E1M1', 'E1M2', false, 5, 10, 2, 4, 1, 2, 120, 30);
$intermission->advance(0, false);
$skipCue = $intermission->advance(0, true);

verifyWad(
	$intermission->phase() === 'done'
		&& $intermission->displayKills === 50
		&& $intermission->displayItems === 50
		&& $intermission->displaySecrets === 50
		&& $intermission->displayTime === 120
		&& $intermission->displayPar === 30
		&& $skipCue?->lump() === SoundCue::intermissionStage()->lump()
	, 'first fresh activation completes every animated intermission tally'
);

$intermission->advance(0, false);
$doneCue = $intermission->advance(0, true);

for($frame = 0; $frame < 120 && !$intermission->ready(); $frame++)
$intermission->advance(1 / 60, false);

verifyWad(
	$intermission->phase() === 'entering'
		&& $intermission->ready()
		&& $doneCue?->lump() === SoundCue::intermissionDone()->lump()
	, 'second activation shows the entering page before releasing the next map'
);

$automaticTally = new LevelIntermission('E1M1', null, false, 1, 1, 1, 1, 0, 0, 5, null);
$automaticTally->advance(0, false);

for($frame = 0; $frame < 600 && $automaticTally->phase() !== 'done'; $frame++)
$automaticTally->advance(1 / 60, false);

verifyWad(
	$automaticTally->phase() === 'done'
		&& $automaticTally->displayKills === 100
		&& $automaticTally->displayItems === 100
		&& $automaticTally->displaySecrets === 0
		&& !$automaticTally->ready()
	, 'intermission counts at real display time while fixed simulation is paused'
);

$laterIwad = new Archive($bytes, $path . '#later-iwad');
$iwadStack = new Stack($archive, $laterIwad);

verifyWad(
	$iwadStack->lump('PLAYPAL')?->archive === $laterIwad
	, 'a later IWAD participates in ordinary last-archive-wins lookup'
);

$fontPath = dirname(__DIR__) . '/demo/DBIGFONT.WAD';
$fontBytes = file_get_contents($fontPath);
verifyWad(is_string($fontBytes), 'DBIGFONT overlay is readable');

verifyWad(
	hash('sha256', $fontBytes) === '8930db8fe4c75f46973f42cdcc242bf9e6690e0ea19a8cf5d8454232948803b4'
	, 'DBIGFONT overlay hash'
);

$fontArchive = new Archive($fontBytes, $fontPath);
verifyWad($fontArchive->type === 'PWAD', 'DBIGFONT is a PWAD overlay');
$fontStack = new Stack($archive, $fontArchive);
$fontCatalog = new TextureCatalog($fontStack);
$menuFont = $fontCatalog->menuFont();
verifyWad($fontStack->lump('DBIGFONT')?->archive === $fontArchive, 'stack resolves DBIGFONT from the overlay');

verifyWad(
	$menuFont?->height === 12 && $menuFont->firstCharacter === 32 && $menuFont->lastCharacter === 96
	, 'FON2 header and character range decode'
);

verifyWad($menuFont?->kerning === -1 && $menuFont->spaceWidth === 8, 'FON2 spacing metrics decode');
$fontA = $menuFont?->glyph(ord('A'));

verifyWad(
	$fontA?->width === 15 && $fontA->height === 12 && strlen($fontA->pixels) === 180
	, 'variable-width FON2 glyph decodes'
);

verifyWad(
	$fontA !== null && substr_count($fontA->alpha, "\xff") > 0 && substr_count($fontA->alpha, "\0") > 0
	, 'FON2 glyph transparency decodes'
);

$fontN = $menuFont?->glyph(ord('N'));
$fontNRightEdge = '';

if($fontN)
for($row = 0; $row < $fontN->height; $row++)
$fontNRightEdge .= $fontN->alpha[$row * $fontN->width + $fontN->width - 1];

verifyWad(
	$fontN !== null
		&& substr_count($fontNRightEdge, "\xff") > 0
		&& substr_count(substr($fontN->alpha, -$fontN->width), "\xff") > 0
	, 'FON2 glyph preserves opaque pixels on the right and bottom edges'
);

verifyWad($menuFont?->advance(ord('A')) === 14, 'FON2 whole-font kerning applies to advances');

verifyWad(
	$fontCatalog->picture(Fon2Font::pictureName(ord('A'))) === $fontA
	, 'synthetic menu picture resolves its FON2 glyph'
);

verifyWad((new TextureCatalog($stack))->menuFont() === null, 'DBIGFONT is strictly optional');

$statusFace = new StatusFace;
verifyWad($statusFace->patch(100) === 'STFST00', 'status face begins with the healthy neutral patch');
$statusFace->advance(false, 34);
verifyWad($statusFace->patch(60) === 'STFST11', 'idle face looks around within the current health band');
$viewer = new Transform(0, 0, 0, 0);
$leftSource = new Transform(0, 64, 0, 0);
$statusFace->recordDamage(5, FaceDirection::toward($viewer, $leftSource));
verifyWad($statusFace->patch(60) === 'STFTL10', 'status face turns toward a directional attacker');
$statusFace->recordDamage(21, FaceDirection::front());
verifyWad($statusFace->patch(60) === 'STFOUCH1', 'large damage selects the surprised ouch face');
$statusFace->recordWeaponPickup();
$statusFace->recordDamage(5, FaceDirection::front());
verifyWad($statusFace->patch(60) === 'STFEVL1', 'new-weapon smile outranks an ordinary pain reaction');
verifyWad($statusFace->patch(60, true) === 'STFGOD0', 'invulnerability selects the god face');
verifyWad($statusFace->patch(0) === 'STFDEAD0', 'zero health selects the death face');
$firingFace = new StatusFace;
$firingFace->advance(true, 70);
verifyWad($firingFace->patch(100) === 'STFKILL0', 'sustained fire selects the rampage face');

$weaponPath = dirname(__DIR__) . '/demo/SKULLTAG-WEAPONS.WAD';
$weaponBytes = file_get_contents($weaponPath);
verifyWad(is_string($weaponBytes), 'additional weapon overlay is readable');

verifyWad(
	hash('sha256', $weaponBytes) === '4ad1ee4b8488cc7920567988f23af1e64be0e4de4947a11612f6eed25775e9d4'
	, 'additional weapon overlay hash'
);

$weaponArchive = new Archive($weaponBytes, $weaponPath);
$weaponStack = new Stack($archive, $weaponArchive, $fontArchive);
$weaponTextures = new TextureCatalog($weaponStack);
verifyWad($weaponArchive->type === 'PWAD', 'additional weapons are a PWAD overlay');
verifyWad((new TextureCatalog($stack))->sprite('MNGG', 'A', 0) === null, 'base IWAD has no add-on weapon sprites');
verifyWad($weaponTextures->sprite('MNGG', 'A', 0) !== null, 'stack resolves add-on weapon sprites');

$pistolPatch = $weaponTextures->sprite('PISG', 'A', 0)['image'];
$pistolPlacement = ViewWeaponPlacement::fromImage($pistolPatch);

verifyWad(
	$pistolPlacement->x === 126.0 && $pistolPlacement->y + $pistolPatch->height === 200.0
	, 'ready psprite reaches the full screen bottom before the status bar occludes it'
);

verifyWad(
	ViewWeaponPlacement::fromImage($pistolPatch, 24)->y === $pistolPlacement->y + 24
	, 'weapon lowering extends from the classic ready origin'
);

verifyWad(
	$weaponStack->lumps('DBIGFONT') === [$fontArchive->lump('DBIGFONT')]
	, 'stack enumerates layered lumps in precedence order'
);

verifyWad(
	$stack->maps() === ['E1M1','E1M2','E1M3','E1M4','E1M5','E1M6','E1M7','E1M8','E1M9']
	, 'complete Episode 1 catalog'
);

$actions = [];
$specials = [];
$things = [];

foreach($stack->maps() as $name)
{
	$map = $stack->map($name);
	verifyWad(count($map->vertices) > 0 && count($map->sectors) > 0, "$name geometry");
	verifyWad(count($map->nodes) > 0 && count($map->subsectors) > 0, "$name BSP");
	$polygons = $map->subsectorPolygons();
	verifyWad(count($polygons) === count($map->subsectors), "$name reconstructed BSP leaves");

	verifyWad(
		!array_filter($polygons, static fn(array $polygon): bool => count($polygon) > 0 && count($polygon) < 3)
		, "$name closed plane polygons"
	);

	$start = array_values(array_filter($map->things, static fn(array $thing): bool => $thing['type'] === 1));
	verifyWad(count($start) === 1, "$name player start");
	verifyWad($map->sectorAt($start[0]['x'], $start[0]['y']) >= 0, "$name start sector");

	foreach($map->linedefs as $line) if($line['action']) $actions[$line['action']] = true;

	foreach($map->sectors as $sector) if($sector['special']) $specials[$sector['special']] = true;

	foreach($map->things as $thing) $things[$thing['type']] = true;
}

ksort($actions);
ksort($specials);

verifyWad(
	array_keys($actions) === [
		1
		, 2
		, 5
		, 7
		, 8
		, 9
		, 11
		, 16
		, 18
		, 20
		, 22
		, 23
		, 26
		, 27
		, 28
		, 31
		, 32
		, 33
		, 34
		, 35
		, 36
		, 46
		, 48
		, 51
		, 62
		, 63
		, 70
		, 76
		, 82
		, 86
		, 88
		, 90
		, 91,97,98,103
	]
	, 'Episode 1 action census'
);

verifyWad(array_keys($specials) === [1,2,3,7,8,9,11,12,13,16], 'Episode 1 sector-special census');

verifyWad(
	isset($things[3004], $things[9], $things[3001], $things[3002], $things[58], $things[3003])
	, 'Episode 1 enemy families'
);

$palette = new Palette($stack);
$textures = new TextureCatalog($stack);
verifyWad($textures->iwadBloodSprite() === 'BLUD', 'base IWAD selects its available blood sprite spelling');
$wall = $textures->texture('STARTAN3');
$flat = $textures->flat('FLOOR4_8');
$title = $textures->picture('TITLEPIC');
$imp = $textures->sprite('TROO', 'A', 1);
verifyWad($wall && $wall->width === 128 && $wall->height === 128, 'composite wall texture');
verifyWad($flat && $flat->width === 64 && $flat->height === 64, 'flat texture');
verifyWad($title && $title->width === 320 && $title->height === 200, 'direct picture');
verifyWad($imp && $imp['image']->width > 0, 'directional sprite');

$zombieRotations = array_map(
	static fn(int $rotation): ?array => $textures->sprite('POSS', 'A', $rotation)
	, range(1, 8)
);

verifyWad(
	array_column(
		$zombieRotations
		, 'name'
	) === ['POSSA1','POSSA2A8','POSSA3A7','POSSA4A6','POSSA5','POSSA4A6','POSSA3A7','POSSA2A8']
	, 'directional sprite frames stay within their requested family'
);

verifyWad(
	array_column($zombieRotations, 'flipped') === [false,false,false,false,false,true,true,true]
	, 'paired directional sprite frames mirror only their reverse rotations'
);

$armorFrames = [];

foreach(str_split('ABCD') as $frame)
foreach(range(1, 8) as $rotation) $armorFrames[] = $textures->sprite('BON2', $frame, $rotation)['name'] ?? null;

verifyWad(
	$armorFrames === array_merge(
		...array_map(static fn(string $frame): array => array_fill(0, 8, 'BON2' . $frame . '0'), str_split('ABCD'))
	)
	, 'rotation-independent armor bonus frames never vary with camera angle'
);

$impImage = $imp['image'];
$rawImpBounds = $impImage->verticalBounds(96);
$groundedImpBounds = $impImage->verticalBounds(96, 96);
verifyWad($rawImpBounds['bottom'] === 91.0, 'WAD sprite origin retains its raw vertical offset when airborne');

verifyWad(
	$groundedImpBounds === ['bottom' => 96.0, 'top' => 153.0]
	, 'grounded billboard clips the patch origin at its physical floor contact'
);

$soul = $textures->sprite('SOUL', 'A', 0)['image'];

verifyWad(
	$soul->verticalBounds(96, 96) === ['bottom' => 110.0, 'top' => 135.0]
	, 'positive pickup hover offsets remain intact'
);

verifyWad(($palette->rgba(0) & 0xff000000) !== 0, 'palette and colormap');
verifyWad($palette->paletteCount() === 14, 'shareware PLAYPAL retains all view-effect pages');
verifyWad(count($palette->maps) === 34, 'shareware COLORMAP retains its two special effect rows');
verifyWad($palette->lightMapCount() === 32, 'ordinary lighting exposes only the standard COLORMAP rows');

verifyWad(
	$palette->textureRowCount() === 14 * 34
		&& strlen($palette->textureBytes()) === 256 * 4 * 14 * 34
	, 'GLES palette lookup flattens every PLAYPAL page and COLORMAP row'
);

$damagePaletteDiffers = false;

for($index = 0; $index < 256; $index++)
if($palette->rgba($index) !== $palette->rgba($index, 0, ViewColorState::FIRST_DAMAGE_PALETTE))
{
	$damagePaletteDiffers = true;
	break;
}

verifyWad($damagePaletteDiffers, 'damage PLAYPAL is distinct from the normal palette');

verifyWad(
	$palette->hasMap(ViewColorState::INVULNERABILITY_COLORMAP)
		&& $palette->rgba(80) !== $palette->rgba(80, ViewColorState::INVULNERABILITY_COLORMAP)
	, 'invulnerability COLORMAP remains addressable independently of light rows'
);

$viewPlayer = new PlayerState;
$viewEffects = new ViewEffects;
$viewPlayer->powers = ['IronFeet' => 60, 'Invulnerable' => 30];
$viewColors = $viewEffects->colors($viewPlayer);

verifyWad(
	$viewColors->palettePage === ViewColorState::RADIATION_PALETTE
		&& $viewColors->fixedColormap === ViewColorState::INVULNERABILITY_COLORMAP
	, 'radiation and invulnerability effects compose as typed palette and colormap state'
);

$viewEffects->recordPickup();
$viewEffects->recordPickup();

verifyWad(
	$viewEffects->colors($viewPlayer)->palettePage === ViewColorState::FIRST_BONUS_PALETTE + 1
	, 'stacked pickup flashes advance through the gold PLAYPAL ramp'
);

$viewEffects->recordDamage(9);

verifyWad(
	$viewEffects->colors($viewPlayer)->palettePage === ViewColorState::FIRST_DAMAGE_PALETTE + 1
	, 'post-armor damage takes precedence and selects the proportional red PLAYPAL page'
);

$viewEffects->advance(9);

verifyWad(
	$viewEffects->colors($viewPlayer)->palettePage === ViewColorState::FIRST_BONUS_PALETTE
	, 'remaining pickup color resumes after the damage counter expires'
);

$viewEffects->advance(3);
$viewPlayer->powers['Invulnerable'] = 7 / 35;
verifyWad($viewEffects->colors($viewPlayer)->fixedColormap === null, 'expiring invulnerability blink has an off phase');
$viewPlayer->powers['Invulnerable'] = 8 / 35;

verifyWad(
	$viewEffects->colors($viewPlayer)->fixedColormap === ViewColorState::INVULNERABILITY_COLORMAP
	, 'expiring invulnerability blink has an on phase'
);

$timedEffectsRegistry = new Registry;
$timedPlayer = new PlayerState;
$timedPlayer->showMessage('PICKED UP A CLIP.');
$timedPlayer->powers['Invulnerable'] = 1 / 35;
$timedView = new ViewEffects;
$timedView->recordPickup();
$timedEffectsRegistry->create($timedPlayer, $timedView);
$timedEffects = new PlayerEffectSystem;
$timedEffects->update($timedEffectsRegistry, 1 / 35);

verifyWad(
	!isset($timedPlayer->powers['Invulnerable']) && $timedView->bonusTics() === 5
		&& $timedPlayer->messageTics === PlayerState::MESSAGE_TICS - 1
	, 'player effect system expires powers and palette counters on the shared 35 Hz clock'
);

$timedEffects->update($timedEffectsRegistry, (PlayerState::MESSAGE_TICS - 1) / 35);
verifyWad($timedPlayer->message === '', 'status messages expire after the classic four-second timeout');

$eastMap = $stack->map('E1M1');
$eastThing = $eastMap->things[37];
$eastLine = $eastMap->linedefs[268];
$eastA = $eastMap->vertices[$eastLine['from']];
$eastB = $eastMap->vertices[$eastLine['to']];

$eastDistance = hypot(
	($eastA['x'] + $eastB['x']) / 2 - $eastThing['x']
	, ($eastA['y'] + $eastB['y']) / 2 - $eastThing['y']
);

$eastSector = $eastMap->sidedefs[$eastLine['right']]['sector'];

verifyWad(
	$palette->lightMap($eastMap->sectors[$eastSector]['light'], $eastDistance) === 31
	, 'E1M1 Thing 37 view toward linedef 268 stays out of special COLORMAP rows'
);

$skills = SkillLevel::all();

verifyWad(
	array_map(static fn(SkillLevel $skill): int => $skill->index(), $skills) === [0,1,2,3,4]
	, 'skill choices are ordered values'
);

verifyWad(
	array_map(static fn(SkillLevel $skill): int => $skill->actorTier(), $skills) === [0,0,2,3,3]
	, 'skill choices map to classic THING tiers'
);

$settings = new GameSettings;
$viewProjection = new ViewProjection(960, 540, 90);
$aimPitch = .35;
$aimDepth = 384.0;

verifyWad(
	abs($viewProjection->screenY($aimDepth, tan($aimPitch) * $aimDepth, $aimPitch)) < 1e-9
	, 'physical pitch rays project through the center of the view'
);

verifyWad(
	abs($viewProjection->screenY($aimDepth, tan($aimPitch) * $aimDepth, $aimPitch, PitchMode::ClassicShear)) < 1e-9
	, 'classic pitch shear keeps the physical aim ray centered'
);

verifyWad(
	abs($viewProjection->viewDepth($aimDepth, 0, $aimPitch) - $aimDepth * cos($aimPitch)) < 1e-9
		&& abs($viewProjection->viewHeight($aimDepth, 0, $aimPitch) + $aimDepth * sin($aimPitch)) < 1e-9
	, 'camera pitch rotates depth and height instead of applying a screen shear'
);

verifyWad(
	$viewProjection->viewDepth($aimDepth, 0, $aimPitch, PitchMode::ClassicShear) === $aimDepth
		&& abs(
			$viewProjection->viewHeight($aimDepth, 0, $aimPitch, PitchMode::ClassicShear)
			+ tan($aimPitch) * $aimDepth
		) < 1e-9
	, 'classic pitch mode retains depth and applies the former vertical shear'
);

$nativeViewport = new RenderViewport(1920, 1080);
$classicViewport = new RenderViewport(1920, 1080, .5);

verifyWad(
	[$nativeViewport->sceneWidth,$nativeViewport->sceneHeight,$nativeViewport->sceneScale] === [1920,1080,1.0]
		&& [$classicViewport->sceneWidth,$classicViewport->sceneHeight,$classicViewport->sceneScale] === [960,540,.5]
	, 'render viewport resolves native and classic scene dimensions'
);

verifyWad(
	$settings->skill()->index() === 2 && $settings->lookSensitivity() === 1.0
		&& $settings->renderResolution() === RenderResolution::Native
		&& $settings->pitchMode() === PitchMode::Perspective
		&& $settings->reticle()
		&& $settings->bloodSplatter() === 1.0
	, 'game settings use campaign defaults'
);

$settings->chooseSkill(SkillLevel::fromIndex(4));
$settings->setLookSensitivity(1.5);
$settings->setInvertVerticalLook(true);
$settings->setRenderResolution(RenderResolution::Classic);
$settings->setPitchMode(PitchMode::ClassicShear);
$settings->setReticle(false);
$settings->setBloodSplatter(1.75);

verifyWad(
	$settings->skill()->index() === 4 && $settings->lookSensitivity() === 1.5 && $settings->invertVerticalLook()
		&& $settings->renderResolution() === RenderResolution::Classic
		&& $settings->pitchMode() === PitchMode::ClassicShear
		&& !$settings->reticle()
		&& $settings->bloodSplatter() === 1.75
	, 'menu settings retain typed choices'
);

try
{
	$settings->setLookSensitivity(3);
	verifyWad(false, 'invalid sensitivity rejected');
}
catch(OutOfRangeException $error)
{
	verifyWad(true, 'invalid sensitivity rejected');
}

try
{
	$settings->setBloodSplatter(2.25);
	verifyWad(false, 'invalid blood splatter amount rejected');
}
catch(OutOfRangeException $error)
{
	verifyWad(true, 'invalid blood splatter amount rejected');
}

$melt = new MeltColumns(160, 200);
$melt->restart(7);
$initialColumns = $melt->positions();

verifyWad(
	count($initialColumns) === 160
		&& min($initialColumns) >= -15
		&& max($initialColumns) <= 0
	, 'screen melt initializes the classic bounded negative column delays'
);

verifyWad(
	!array_filter(
		array_keys(array_slice($initialColumns, 1, null, true))
		, static fn(int $column): bool => abs($initialColumns[$column] - $initialColumns[$column - 1]) > 1
	)
	, 'screen melt staggers adjacent columns by at most one tic'
);

$melt->advance(1 / 35);
$advancedColumns = $melt->positions();

verifyWad(
	!array_filter(
		array_keys($advancedColumns)
		, static fn(int $column): bool => $advancedColumns[$column] < $initialColumns[$column]
	)
	, 'screen melt columns move monotonically after each 35 Hz tic'
);

for($tic = 0; !$melt->complete() && $tic < 80; $tic++) $melt->advance(1 / 35);

verifyWad(
	$melt->complete()
		&& $melt->progress() === 1.0
		&& min($melt->positions()) === 200
		&& strlen($melt->textureBytes()) === 160 * 4
	, 'screen melt reaches the full logical height and emits one R32F offset per column'
);

$doomInit = DoomInitialization::fromWorld([
	'init' => [
		'startMap' => 'map04'
		, 'cheats' => ['iddqd', 'idkfa']
		, 'scenario' => 'game-owned extension data'
	]
]);

verifyWad(
	$doomInit->startMap === 'MAP04' && $doomInit->cheats === ['iddqd','idkfa']
	, 'Doom initialization reads a normalized start map and ordered cheat list from world init data'
);

$singleCheatInit = DoomInitialization::fromWorld(['init' => ['cheats' => 'idfa']]);

verifyWad(
	$singleCheatInit->startMap === null && $singleCheatInit->cheats === ['idfa']
	, 'Doom initialization accepts one cheat string and leaves the generic start scope intact'
);

$invalidInitRejected = false;

try
{
	DoomInitialization::fromWorld(['init' => ['cheats' => ['iddqd', 7]]]);
}
catch(UnexpectedValueException)
{
	$invalidInitRejected = true;
}

verifyWad($invalidInitRejected, 'Doom initialization rejects malformed game-owned cheat data');

$cheatSequence = new CheatSequence;
verifyWad($cheatSequence->feed('ordinarymovementidff') === [], 'normal key input does not resemble a cheat');
$commands = $cheatSequence->feed('a');

verifyWad(
	count($commands) === 1 && $commands[0]->type === CheatCommand::ARSENAL
	, 'IDFFA alias is recognized across browser input frames'
);

$commands = $cheatSequence->feed('idbeholdvidbeholdsIDBEHOLDL');

verifyWad(
	array_map(static fn(CheatCommand $command): ?string => $command->argument, $commands)
		=== [null,'Invulnerable',null,'Strength',null,'LightAmp']
	, 'IDBEHOLD help and power suffixes remain independently recognizable'
);

$commands = $cheatSequence->feed('idclev19');
verifyWad(count($commands) === 1 && $commands[0]->argument === 'E1M9', 'IDCLEV parses a typed episode map target');

$commands = $cheatSequence->feed('IDOOPSALLGIBS');

verifyWad(
	count($commands) === 1 && $commands[0]->type === CheatCommand::OOPS_ALL_GIBS
	, 'IDOOPSALLGIBS is recognized as a runtime cheat command'
);

$cheatSequence->feed('idsp');
verifyWad($cheatSequence->awaiting(), 'partial IDSPISPOPD input reserves P for the cheat rather than pause');
$cheatSequence->reset();

$cheatRegistry = new Registry;

$cheatEntity = $cheatRegistry->create(
	new PlayerState
	, new \DoomPhonics\Component\HitPoints(25, 100)
	, new Transform(10, 20, 30, M_PI / 2)
);

$cheats = new CheatSystem;
$cheats->apply(CheatCommand::named(CheatCommand::ARSENAL), $cheatRegistry, $cheatEntity);
$cheatPlayer = $cheatRegistry->get($cheatEntity, PlayerState::class);

verifyWad(
	$cheatPlayer->armor === 200
		&& $cheatPlayer->ammo === $cheatPlayer->ammoMaximum
		&& !empty($cheatPlayer->weapons['BFG9000'])
		&& $cheatPlayer->keys === []
	, 'IDFA and IDFFA grant the full arsenal without keys'
);

$cheats->apply(CheatCommand::named(CheatCommand::ARSENAL_AND_KEYS), $cheatRegistry, $cheatEntity);
verifyWad(count(array_filter($cheatPlayer->keys)) === 6, 'IDKFA adds all card and skull keys');
$cheats->apply(CheatCommand::named(CheatCommand::GOD), $cheatRegistry, $cheatEntity);

verifyWad(
	$cheatPlayer->godMode && $cheatRegistry->get($cheatEntity, \DoomPhonics\Component\HitPoints::class)->current === 100
	, 'IDDQD heals and enables god mode'
);

$cheats->apply(CheatCommand::named(CheatCommand::NO_CLIP), $cheatRegistry, $cheatEntity);
verifyWad($cheatPlayer->noClip, 'IDCLIP and IDSPISPOPD share typed no-clip state');
$cheats->apply(CheatCommand::power('Strength'), $cheatRegistry, $cheatEntity);

verifyWad(
	isset($cheatPlayer->powers['Strength']) && $cheatPlayer->pendingWeapon === 'Fist'
	, 'IDBEHOLDS grants berserk strength and requests the fist transition'
);

$warp = $cheats->apply(CheatCommand::warp('E1M9'), $cheatRegistry, $cheatEntity);
verifyWad($warp->warpMap === 'E1M9', 'IDCLEV returns a lifecycle-owned warp request');

$cheats->apply(CheatCommand::named(CheatCommand::OOPS_ALL_GIBS), $cheatRegistry, $cheatEntity);

verifyWad(
	$cheatPlayer->message === 'RAILGUN NOT AVAILABLE' && $cheats->monsterWeapon() === null
	, 'IDOOPSALLGIBS remains inactive when the current WAD stack has no Railgun'
);

$resource = dirname(__DIR__) . '/resources/';

$baseLanguage = LanguageCatalog::documents([
	$resource . 'language.txt' => file_get_contents($resource . 'language.txt')
]);

verifyWad(
	$baseLanguage->text('$GOTARMOR') === 'Picked up the armor.'
		&& $baseLanguage->mapTitle('E1M1') === 'E1M1: Hangar'
		&& $baseLanguage->mapTitle('MAP04') === 'level 4: the focus'
	, 'built-in LANGUAGE resolves DECORATE tokens and classic map titles'
);

verifyWad(
	str_contains($baseLanguage->text('E1TEXT'), "Once you beat the big badasses")
		&& str_contains($baseLanguage->text('E1TEXT'), "The Shores of Hell")
	, 'LANGUAGE concatenates multiline quoted strings'
);

verifyWad(
	$baseLanguage->text('STSTR_DQDON') === 'Degreelessness Mode ON'
		&& $baseLanguage->resolve('$MISSING_ADDON_TOKEN') === '$MISSING_ADDON_TOKEN'
	, 'LANGUAGE observes game conditionals and preserves unresolved DECORATE tokens'
);

$layeredLanguage = LanguageCatalog::documents([
	'base' => "[default]\nPICKUP = \"base\";\nONLY_BASE = \"fallback\";"
	, 'overlay' => "[fra default]\nPICKUP = \"fallback overlay\";\n[enu]\nPICKUP = \"English overlay\";"
]);

verifyWad(
	$layeredLanguage->text('PICKUP') === 'English overlay'
		&& $layeredLanguage->text('ONLY_BASE') === 'fallback'
	, 'later LANGUAGE documents layer exact locale strings over defaults'
);

$actors = DecorateParser::catalog([
	'decor.dec' => file_get_contents($resource . 'decor.dec')
	, 'objects.dec' => file_get_contents($resource . 'objects.dec')
	, 'weaps.dec' => file_get_contents($resource . 'weaps.dec')
	, 'doom1-enemies.dec' => file_get_contents($resource . 'doom1-enemies.dec')
]);

verifyWad(count($actors->all()) >= 150, 'DECORATE actor catalog');
verifyWad($actors->named('Minigun') === null, 'base DECORATE catalog excludes add-on weapons');

require __DIR__ . '/wiki-actors.php';

$addonDefinitions = new WadDefinitions(
	[$weaponPath . '#sidecar' => file_get_contents(dirname(__DIR__) . '/demo/SKULLTAG-WEAPONS.DEC')]
	, [$weaponPath . '#sndinfo' => file_get_contents(dirname(__DIR__) . '/demo/SKULLTAG-WEAPONS.SNDINFO')]
);

$weaponSlots = WeaponSlots::fromCatalog(
	$addonDefinitions->actors
	, static function($weapon) use ($weaponTextures): bool {
		$ready = $weapon->state('Ready');

		foreach($ready?->frames ?? [] as $frame)
		if($weaponTextures->sprite($frame->sprite, $frame->frame, 0) !== null) return true;

		return false;
	}
);

verifyWad($weaponSlots->slot(4) === ['Chaingun','Minigun'], 'slot 4 combines player declaration and weapon fallback');
verifyWad($weaponSlots->slot(5) === ['RocketLauncher','GrenadeLauncher'], 'slot 5 adds the grenade launcher fallback');
verifyWad($weaponSlots->slot(6) === ['Railgun'], 'slot 6 exposes only weapons with stack-resolved view sprites');
verifyWad($weaponSlots->slot(7) === ['BFG10K'], 'slot 7 exposes the stack-resolved BFG10K');

verifyWad(
	$addonDefinitions->sounds->resolve('weapons/minigun') === 'DSMINIGN'
	, 'stack-local SNDINFO resolves minigun audio'
);

verifyWad(
	$addonDefinitions->sounds->resolve('weapons/bfg10kcool') === 'DS10KCDN'
	, 'stack-local SNDINFO resolves BFG10K audio'
);

$stackCheatRegistry = new Registry;
$stackCheatEntity = $stackCheatRegistry->create(new PlayerState, new HitPoints(100, 100));
$stackCheatSystem = new CheatSystem($weaponSlots);

$stackCheatSystem->apply(CheatCommand::named(CheatCommand::ARSENAL), $stackCheatRegistry, $stackCheatEntity);

$stackCheatWeapons = array_keys(array_filter($stackCheatRegistry->get($stackCheatEntity, PlayerState::class)->weapons));

verifyWad(
	array_diff($weaponSlots->weapons(), $stackCheatWeapons) === []
	, 'all-weapons cheats grant every weapon available from the active WAD stack'
);

$stackCheatSystem->apply(CheatCommand::named(CheatCommand::OOPS_ALL_GIBS), $stackCheatRegistry, $stackCheatEntity);
$stackCheatPlayer = $stackCheatRegistry->get($stackCheatEntity, PlayerState::class);

verifyWad(
	array_keys(array_filter($stackCheatPlayer->weapons)) === ['Railgun']
		&& $stackCheatPlayer->pendingWeapon === 'Railgun'
		&& $stackCheatPlayer->ammo['Cell'] === $stackCheatPlayer->ammoMaximum['Cell']
		&& isset($stackCheatPlayer->infiniteAmmo['Cell'])
		&& $stackCheatSystem->monsterWeapon() === 'Railgun'
	, 'IDOOPSALLGIBS replaces player guns and activates infinite-cell runtime rail attacks'
);

$respawnCheatEntity = $stackCheatRegistry->create(new PlayerState, new HitPoints(100, 100));
$stackCheatSystem->applyActive($stackCheatRegistry, $respawnCheatEntity);
$respawnCheatPlayer = $stackCheatRegistry->get($respawnCheatEntity, PlayerState::class);

verifyWad(
	array_keys(array_filter($respawnCheatPlayer->weapons)) === ['Railgun']
		&& isset($respawnCheatPlayer->infiniteAmmo['Cell'])
		&& $stackCheatSystem->monsterWeapon() === 'Railgun'
	, 'IDOOPSALLGIBS reapplies its player and monster rules after a player respawn'
);

$stackCheatSystem->apply(CheatCommand::named(CheatCommand::OOPS_ALL_GIBS), $stackCheatRegistry, $respawnCheatEntity);

verifyWad(
	array_keys(array_filter($respawnCheatPlayer->weapons)) === $stackCheatWeapons
		&& $respawnCheatPlayer->infiniteAmmo === []
		&& $stackCheatSystem->monsterWeapon() === null
		&& $respawnCheatPlayer->message === 'OOPS! ALL GIBS OFF'
	, 'entering IDOOPSALLGIBS a second time restores the saved loadout and disables the ruleset'
);

$slotPrecedence = DecorateParser::catalog([
	'slots.dec' => <<<'DECORATE'
ACTOR Weapon {}
ACTOR TestGun : Weapon { Weapon.SlotNumber 7 }
ACTOR OtherGun : Weapon { Weapon.SlotNumber 5 }
ACTOR KeyOnlyGun : Weapon {}
ACTOR LaterKeyGun : Weapon {}
ACTOR DoomPlayer { Player.WeaponSlot 2, TestGun }
DECORATE
]);

$precedenceSlots = WeaponSlots::fromCatalog($slotPrecedence);
verifyWad($precedenceSlots->slot(2) === ['TestGun'], 'DoomPlayer weapon slots take precedence');
verifyWad($precedenceSlots->slot(7) === [], 'Weapon.SlotNumber is only a fallback');

$keyconfSlots = WeaponSlots::fromCatalog($slotPrecedence, null, [
	'first.wad#KEYCONF' => <<<'KEYCONF'
weaponsection Stress
setslot 2 TestGun KeyOnlyGun
addslot 6 LaterKeyGun
KEYCONF
	, 'second.wad#KEYCONF' => <<<'KEYCONF'
setslot 9 OtherGun KeyOnlyGun
addslotdefault 4 LaterKeyGun
KEYCONF
]);

verifyWad(
	$keyconfSlots->slot(2) === ['TestGun','KeyOnlyGun']
		&& $keyconfSlots->slot(5) === ['OtherGun']
		&& $keyconfSlots->slot(6) === ['LaterKeyGun']
		&& $keyconfSlots->slot(9) === []
	, 'KEYCONF appends to existing slots while the first global weapon-name assignment wins'
);

verifyWad(
	count(
		array_filter(
			array_merge(...array_values($keyconfSlots->all()))
			, static fn(string $weapon): bool => strcasecmp($weapon, 'KeyOnlyGun') === 0
		)
	) === 1
	, 'layered KEYCONF documents cannot assign one weapon name more than once'
);

$demo = dirname(__DIR__) . '/demo/';
$keyconfFixtureLumps = [
	'DECORATE' => "ACTOR KeyConfFixtureGun : Pistol\n{\n}\n"
	, 'KEYCONF' => "weaponsection Fixture\nsetslot 9 KeyConfFixtureGun Pistol\n"
	, 'LANGUAGE' => "[enu default]\nGOTCLIP = \"Layered clip message.\";\n"
];

$keyconfFixtureData = '';
$keyconfFixtureDirectory = '';
$keyconfFixtureOffset = 12;

foreach($keyconfFixtureLumps as $name => $data)
{
	$keyconfFixtureData .= $data;
	$keyconfFixtureDirectory .= pack('V2a8', $keyconfFixtureOffset, strlen($data), $name);
	$keyconfFixtureOffset += strlen($data);
}

$keyconfFixtureWad = 'PWAD'
	. pack('V2', count($keyconfFixtureLumps), 12 + strlen($keyconfFixtureData))
	. $keyconfFixtureData
	. $keyconfFixtureDirectory;

$keyconfFixtureUrl = 'memory:keyconf-fixture.wad';

$sourceDefinition = new SourceDefinition('episode', '@doom/wad', [
	['url' => $path, 'role' => 'iwad']
	, ['url' => $weaponPath, 'role' => 'pwad']
	, ['url' => $keyconfFixtureUrl, 'role' => 'pwad']
	, ['url' => $demo . 'SKULLTAG-WEAPONS.DEC', 'role' => 'decorate']
	, ['url' => $demo . 'SKULLTAG-WEAPONS.SNDINFO', 'role' => 'sndinfo']
	, ['url' => $fontPath, 'role' => 'pwad']
], [], $demo . 'episode-one.phonics-world.json'
);

$source = (new WadAdapter)->open(
	$sourceDefinition
	, new AdapterContext(null, static function(string $url) use ($keyconfFixtureUrl, $keyconfFixtureWad): string {
		if($url === $keyconfFixtureUrl) return $keyconfFixtureWad;

		$bytes = file_get_contents($url);

		if(!is_string($bytes)) throw new RuntimeException("Cannot read $url");

		return $bytes;
	})
);

$descriptors = iterator_to_array($source->catalog());
$scopeRegistry = new Registry;
$scopeContext = new ScopeContext('episode', $descriptors[0], $scopeRegistry, new Scheduler($scopeRegistry));
$stackedContent = $source->activate('E1M1', $scopeContext)->content();
verifyWad($stackedContent->actors->named('Minigun') !== null, 'WAD adapter applies declared DECORATE sidecars');

verifyWad(
	$stackedContent->sounds->resolve('weapons/minigun') === 'DSMINIGN'
	, 'WAD adapter applies declared SNDINFO sidecars'
);

verifyWad(
	$stackedContent->language->text('$GOTCLIP') === 'Layered clip message.'
	, 'WAD adapter layers embedded LANGUAGE lumps after built-in strings'
);

$directLanguageStack = new Stack($archive, new Archive($keyconfFixtureWad, $keyconfFixtureUrl));
$directLanguageSource = new WadSource($directLanguageStack);
$directLanguageDescriptors = iterator_to_array($directLanguageSource->catalog());
$directLanguageRegistry = new Registry;

$directLanguageContext = new ScopeContext(
	'direct-language'
	, $directLanguageDescriptors[0]
	, $directLanguageRegistry
	, new Scheduler($directLanguageRegistry)
);

$directLanguageContent = $directLanguageSource->activate('E1M1', $directLanguageContext)->content();

verifyWad(
	$directLanguageContent->language->text('$GOTCLIP') === 'Layered clip message.'
	, 'direct WAD stack loading discovers embedded LANGUAGE overrides through the lump loader'
);

verifyWad(
	$stackedContent->weapons->slot(4) === ['Chaingun','Minigun']
	, 'active WAD scope owns its composed weapon banks'
);

verifyWad(
	$stackedContent->weapons->slot(9) === ['KeyConfFixtureGun']
		&& count(
			array_filter(
				$stackedContent->weapons->weapons()
				, static fn(string $weapon): bool => strcasecmp($weapon, 'Pistol') === 0
			)
		) === 1
	, 'WAD adapter loads embedded KEYCONF after DECORATE without duplicating an existing weapon'
);

$plainSource = new WadSource($stack);
$plainDescriptors = iterator_to_array($plainSource->catalog());
$plainRegistry = new Registry;
$plainContext = new ScopeContext('plain', $plainDescriptors[0], $plainRegistry, new Scheduler($plainRegistry));
$plainContent = $plainSource->activate('E1M1', $plainContext)->content();
verifyWad($plainContent->actors->named('Minigun') === null, 'separate IWAD source does not inherit add-on definitions');

verifyWad(
	!in_array('Minigun', $plainContent->weapons->weapons(), true)
	, 'separate IWAD source does not inherit add-on slots'
);

// Component-only tests miss an incorrectly installed transform-history system.
foreach([60, 144] as $renderRate)
{
	$episodeMovement = new \DoomPhonics\Tests\EpisodeMovement($plainSource);
	$previousRenderX = $episodeMovement->advance(0)['players'][1]['renderX'];
	$spawnX = $previousRenderX;
	$worstReversal = 0.0;
	$longestInterval = 0.0;

	for($frame = 0; $frame < $renderRate; $frame++)
	{
		$sample = $episodeMovement->advance(1 / $renderRate)['players'][1];
		$worstReversal = min($worstReversal, $sample['renderX'] - $previousRenderX);
		$longestInterval = max($longestInterval, abs($sample['x'] - $sample['previousX']));
		$previousRenderX = $sample['renderX'];
	}

	$episodeMovement->scope->close();
	verifyWad($sample['x'] > $spawnX + 100, "$renderRate Hz scheduled player actually moves away from spawn");

	verifyWad(
		$worstReversal >= -1e-8
		, "$renderRate Hz scheduled render must not snap toward spawn (reversal $worstReversal)"
	);

	verifyWad(
		$longestInterval <= 420 / 35 + 1e-8
		, "$renderRate Hz interpolation must span only the latest simulation step (span $longestInterval)"
	);
}

$zombie = $actors->numbered(3004);
verifyWad($zombie?->name === 'ZombieMan' && $zombie->value('Health') === 20, 'enemy editor number and properties');
verifyWad($zombie?->hasFlag('Monster') && $zombie->hasFlag('SOLID'), 'enemy shorthand and inherited flags');
verifyWad($zombie?->state('See')?->frames[0]->action === 'A_Chase', 'enemy state action');
$barrelDefinition = $actors->numbered(2035);

verifyWad(
	$barrelDefinition?->value('Mass') === 100 && $barrelDefinition->hasFlag('DONTGIB')
	, 'barrel inherits DECORATE mass and explicitly forbids gibbing'
);

$bulletPuff = $actors->named('BulletPuff');
verifyWad($bulletPuff?->value('Alpha') === 0.5, 'BulletPuff retains declarative translucency');
verifyWad($bulletPuff?->value('VSpeed') === 1, 'BulletPuff retains declarative vertical speed');

verifyWad(
	$bulletPuff?->state('Spawn')?->flow === 'goto'
		&& strtolower((string)$bulletPuff->state('Spawn')?->target) === 'melee'
	, 'DECORATE state labels preserve implicit fallthrough'
);

$spectre = $actors->numbered(58);
verifyWad($spectre?->value('Health') === 150 && $spectre->hasFlag('SHADOW'), 'actor inheritance');
$pistol = $actors->named('Pistol');
verifyWad($pistol?->value('Weapon.AmmoType') === 'Clip' && $pistol->value('Weapon.AmmoUse') === 1, 'weapon data');
verifyWad($pistol?->state('Fire')?->frames[1]->action === 'A_FirePistol', 'weapon state action');

verifyWad(
	$actors->named('Demon')?->state('See')?->frames[0]->action === 'A_Chase'
	, 'DECORATE Fast qualifier preserves its following action'
);

$clip = $actors->numbered(2007);
verifyWad($clip?->value('Inventory.Amount') === 10 && $clip->value('Inventory.MaxAmount') === 200, 'ammo data');
$sounds = SoundCatalog::parse(file_get_contents($resource . 'sndinfo.txt'));
verifyWad($sounds->resolve('grunt/attack') === 'DSPISTOL', 'SNDINFO alias');

verifyWad(
	in_array($sounds->resolve('grunt/sight', 1), ['DSPOSIT1','DSPOSIT2','DSPOSIT3'], true)
	, 'SNDINFO random group'
);

$factory = new ActorFactory($actors);
$effectMap = $stack->map('E1M1');
$effectStart = array_values(array_filter($effectMap->things, static fn(array $thing): bool => $thing['type'] === 1))[0];
$effectSector = $effectMap->sectors[$effectMap->sectorAt($effectStart['x'], $effectStart['y'])];
$effectRegistry = new Registry;

$bloodActors = DecorateParser::catalog([
	'blood-fixture.dec' => <<<'DECORATE'
ACTOR Actor
{
}
ACTOR BloodTarget : Actor
{
	BloodType NormalBlood, SplatterBlood
}
ACTOR NoBloodTarget : BloodTarget
{
	+NOBLOOD
}
ACTOR SplatterAttack : Actor
{
	+BLOODSPLATTER
}
ACTOR NormalBlood : Actor
{
	States
	{
	Spawn:
		BLUD A 8
		Stop
	}
}
ACTOR SplatterBlood : Actor
{
	States
	{
	Spawn:
		BLUD C 8
		Stop
	}
}
DECORATE
]);

$bloodFactory = new ActorFactory($bloodActors);
$bloodRegistry = new Registry;

$bloodTarget = $bloodRegistry->create(
	new Transform($effectStart['x'], $effectStart['y'], 32)
	, new ActorState($bloodActors->named('BloodTarget'))
);

$noBloodTarget = $bloodRegistry->create(new ActorState($bloodActors->named('NoBloodTarget')));

$bloodAttack = $bloodRegistry->create(
	new Transform($effectStart['x'] - 16, $effectStart['y'], 32)
	, new ActorState($bloodActors->named('SplatterAttack'))
);

$bloodSettings = new GameSettings;
$bloodEffects = new BloodEffects(new Level($effectMap), $bloodActors, $bloodFactory, 'BLUD', $bloodSettings);
$bloodImpact = new HitscanImpact(16, $effectStart['x'], $effectStart['y'], 32, $bloodTarget);

$splatterDrops = $bloodEffects->spawn(
	$bloodRegistry
	, $bloodTarget
	, $bloodImpact
	, $bloodRegistry->get($bloodAttack, Transform::class)
	, $bloodAttack
);

verifyWad(
	count($splatterDrops) === 5 && !array_filter(
		$splatterDrops
		, static fn(int $entity): bool => $bloodRegistry->get(
			$entity
			, ActorState::class
		)->definition->name !== 'SplatterBlood'
	)
	, 'BLOODSPLATTER selects the second DECORATE BloodType actor'
);

$normalDrops = $bloodEffects->spawn(
	$bloodRegistry
	, $bloodTarget
	, $bloodImpact
	, $bloodRegistry->get($bloodAttack, Transform::class)
);

verifyWad(
	count($normalDrops) === 5 && !array_filter(
		$normalDrops
		, static fn(int $entity): bool => $bloodRegistry->get(
			$entity
			, ActorState::class
		)->definition->name !== 'NormalBlood'
	)
	, 'ordinary damage selects the first DECORATE BloodType actor'
);

verifyWad(
	$bloodEffects->spawn(
		$bloodRegistry
		, $noBloodTarget
		, $bloodImpact
		, $bloodRegistry->get($bloodAttack, Transform::class)
		, $bloodAttack
	) === []
	, 'NOBLOOD suppresses damage spray'
);

$bloodSettings->setBloodSplatter(2);

verifyWad(
	count(
		$bloodEffects->spawn(
			$bloodRegistry
			, $bloodTarget
			, $bloodImpact
			, $bloodRegistry->get($bloodAttack, Transform::class)
		)
	) === 10
	, 'blood splatter setting scales the live droplet count upward'
);

$bloodSettings->setBloodSplatter(0);

verifyWad(
	$bloodEffects->spawn(
		$bloodRegistry
		, $bloodTarget
		, $bloodImpact
		, $bloodRegistry->get($bloodAttack, Transform::class)
	) === []
	, 'zero blood splatter disables the effect'
);

$fallbackActors = DecorateParser::catalog([
	'blood-fallback.dec' => <<<'DECORATE'
ACTOR Actor
{
}
ACTOR MissingBloodTarget : Actor
{
	BloodType MissingBlood
}
DECORATE
]);

$fallbackRegistry = new Registry;
$fallbackTarget = $fallbackRegistry->create(new ActorState($fallbackActors->named('MissingBloodTarget')));
$fallbackSource = new Transform($effectStart['x'] - 16, $effectStart['y'], 32);

$fallbackDrops = (new BloodEffects(
	new Level($effectMap)
	, $fallbackActors
	, new ActorFactory($fallbackActors)
	, 'BLOD'
))->spawn(
	$fallbackRegistry
	, $fallbackTarget
	, new HitscanImpact(16, $effectStart['x'], $effectStart['y'], 32, $fallbackTarget)
	, $fallbackSource
);

verifyWad(
	count($fallbackDrops) === 5
		&& $fallbackRegistry->get($fallbackDrops[0], ActorState::class)->current()?->sprite === 'BLOD'
	, 'missing blood actors fall back to the selected base-IWAD sprite'
);

$effectEntity = $factory->spawn(
	$effectRegistry
	, $bulletPuff
	, [
		'x' => $effectStart['x']
		, 'y' => $effectStart['y']
		, 'z' => 32
		, 'angle' => 0
		, 'type' => 0
		, 'index' => -1
		, 'flags' => []
	]
	, $effectSector
);

$effectRegistry->add($effectEntity, new TransientEffect);
$effectStates = new ActorStateSystem(new ActionDispatcher);

for($i = 0; $i < 9; $i++) $effectStates->update($effectRegistry, 1 / 35);

verifyWad(
	strtolower($effectRegistry->get($effectEntity, ActorState::class)->label) === 'melee'
	, 'BulletPuff runs through its DECORATE fallthrough label'
);

for($i = 0; $i < 12; $i++) $effectStates->update($effectRegistry, 1 / 35);
$effectRegistry->flush();
verifyWad(!$effectRegistry->alive($effectEntity), 'finite transient DECORATE effects retire after Stop');

foreach($stack->maps() as $name)
{
	$registry = new Registry;
	$result = $factory->populate($registry, $stack->map($name));
	verifyWad($result['missing'] === [], "$name DECORATE coverage");
	verifyWad(count($result['entities']) > 0, "$name ECS population");
}

$registry = new Registry;
$result = $factory->populate($registry, $stack->map('E1M1'));
verifyWad(count($registry->query(PlayerStart::class)) === 1, 'player start component');
verifyWad(count($registry->query(MonsterBrain::class)) > 0, 'monster brain components');
$playerEntity = $registry->query(PlayerStart::class)[0];
verifyWad($registry->has($playerEntity, StatusFace::class), 'player start owns an independent HUD face component');

$networkDescriptor = [
	'settings' => ['mode' => 'deathmatch','skill' => '2','fragLimit' => '10']
];

$deathmatch = new DoomMatch(
	new MatchContext(
		'host'
		, new PlayerSlot(1)
		, new PlayerSlot(2)
		, new PeerId('host')
		, new PeerId('guest')
		, 'sha256-AAAA='
		, $networkDescriptor
	)
);

$networkRegistry = new Registry;
$factory->populate($networkRegistry, $stack->map('E1M1'), 2, true, $deathmatch);
$networkPlayers = $networkRegistry->query(NetworkPlayer::class);
verifyWad(count($networkPlayers) === 2, 'deathmatch materializes two players from map spawn markers');

verifyWad(
	array_map(
		static fn(int $entity): int => $networkRegistry->get($entity, PlayerStart::class)->number
		, $networkPlayers
	) === [1,2]
	, 'network players retain deterministic slot order'
);

verifyWad(
	$networkRegistry->get($networkPlayers[0], NetworkPlayer::class)->local
		&& !$networkRegistry->get($networkPlayers[1], NetworkPlayer::class)->local
	, 'host camera ownership follows the negotiated local slot'
);

verifyWad(
	$networkRegistry->has($networkPlayers[0], PlayerCommand::class)
		&& $networkRegistry->has($networkPlayers[1], PlayerCommand::class)
	, 'each network player owns an independent command component'
);

$replication = EpisodeNetworkSystem::replication();
require __DIR__ . '/network-projectiles.php';
require __DIR__ . '/network-beams.php';
require __DIR__ . '/network-respawn.php';
require __DIR__ . '/network-actors.php';
$networkDeath = new PlayerState;
$networkDeath->deathTime = .8;
$networkDeath->viewHeight = 13;
$networkDeath->previousViewHeight = 14;
$staleDeath = new PlayerState;
$staleDeath->deathTime = .2;
$staleDeath->viewHeight = 34;
$staleDeath->previousViewHeight = 35;

$decodedDeath = $replication->codec('doom.player-state')->decode(
	$replication->codec('doom.player-state')->encode($staleDeath)
	, $networkDeath
);

verifyWad(
	$decodedDeath === $networkDeath
		&& $networkDeath->deathTime === .8
		&& $networkDeath->viewHeight === 13.0
		&& $networkDeath->previousViewHeight === 14.0
	, 'stale player snapshots cannot restart an in-progress network death camera'
);

$remotePose = new Transform(10, 20, 30, .25);
$remoteVelocity = new Velocity(1, 2, 3);

$decodedPose = $replication->codec('doom.transform')->decode([
	'x' => 18
	, 'y' => 28
	, 'z' => 38
	, 'angle' => .5
], $remotePose
);

$decodedVelocity = $replication->codec('doom.velocity')->decode([
	'x' => 4
	, 'y' => 5
	, 'z' => 6
], $remoteVelocity
);

$remotePose->beginStep();

verifyWad(
	$decodedPose === $remotePose && $decodedVelocity === $remoteVelocity
		&& $remoteVelocity->x === 4.0 && $remoteVelocity->y === 5.0 && $remoteVelocity->z === 6.0
	, 'network snapshots reconcile cached motion components in place'
);

verifyWad(
	$remotePose->x === 18.0 && $remotePose->previousX === 10.0 && $remotePose->xAt(.5) === 14.0
		&& $remotePose->yAt(.5) === 24.0 && $remotePose->zAt(.5) === 34.0
	, 'remote snapshot corrections retain one fixed-step render interval'
);

$cooperative = new DoomMatch(
	new MatchContext(
		'guest'
		, new PlayerSlot(2)
		, new PlayerSlot(1)
		, new PeerId('guest')
		, new PeerId('host')
		, 'sha256-BBBB='
		, ['settings' => ['mode' => 'cooperative']]
	)
);

$cooperativeRegistry = new Registry;
$factory->populate($cooperativeRegistry, $stack->map('E1M1'), 2, true, $cooperative);
$cooperativePlayers = $cooperativeRegistry->query(NetworkPlayer::class);

verifyWad(
	!$cooperativeRegistry->get($cooperativePlayers[0], NetworkPlayer::class)->local
		&& $cooperativeRegistry->get($cooperativePlayers[1], NetworkPlayer::class)->local
	, 'co-op guest camera ownership selects player two without changing spawn order'
);

$guestMovementLevel = new Level($stack->map('E1M1'));

$guestMovement = new PlayerMovementSystem(
	new Collision($guestMovementLevel)
	, new LineSpecials($guestMovementLevel)
	, new Controls
	, simulateRemotePlayers: false
);

$guestRemote = $cooperativePlayers[0];
$guestLocal = $cooperativePlayers[1];
$guestRemotePose = $cooperativeRegistry->get($guestRemote, Transform::class);
$guestRemoteVelocity = $cooperativeRegistry->get($guestRemote, Velocity::class);
$guestRemoteBefore = [$guestRemotePose->x,$guestRemotePose->y,$guestRemotePose->z,$guestRemotePose->angle];
$guestRemoteVelocity->x = 160;
$guestLocalPose = $cooperativeRegistry->get($guestLocal, Transform::class);
$guestLocalBefore = [$guestLocalPose->x,$guestLocalPose->y];
$cooperativeRegistry->get($guestLocal, PlayerCommand::class)->apply(['forward' => 1]);
$guestMovement->update($cooperativeRegistry, 1 / 35);

verifyWad(
	[$guestRemotePose->x,$guestRemotePose->y,$guestRemotePose->z,$guestRemotePose->angle] === $guestRemoteBefore
		&& $guestRemoteVelocity->x === 160.0
		&& [$guestLocalPose->x,$guestLocalPose->y] !== $guestLocalBefore
	, 'guest predicts its local player without advancing the remote player between host snapshots'
);

$pose = $registry->get($playerEntity, Transform::class);
$beforeX = $pose->x;
$beforeY = $pose->y;
$movementLevel = new Level($stack->map('E1M1'));
$movementControls = new Controls;
$movementControls->set([\DoomPhonics\Input\DoomActions::STRAFE => 1]);

$movement = new PlayerMovementSystem(
	new Collision($movementLevel)
	, new LineSpecials($movementLevel)
	, $movementControls
);

$movement->update($registry, 1 / 35);
$rightTravel = ($pose->x - $beforeX) * sin($pose->angle) - ($pose->y - $beforeY) * cos($pose->angle);
verifyWad($rightTravel > 0, 'right strafe follows screen-right view basis');

verifyWad(
	strcasecmp($registry->get($playerEntity, ActorState::class)->label, 'See') === 0
	, 'moving player selects the third-person walking animation'
);

$cameraHeight = new CameraHeight;
verifyWad($cameraHeight->follow(32) === 32.0, 'vertical camera initializes at the physical view height');
verifyWad($cameraHeight->follow(40) === 34.0, 'vertical camera eases upward over a full stair step');
verifyWad($cameraHeight->follow(40) === 35.5, 'vertical camera retains upward easing across render frames');
verifyWad($cameraHeight->follow(40) === 36.625, 'vertical camera approaches an elevated floor gradually');
verifyWad($cameraHeight->follow(40) === 40.0, 'vertical camera snaps the final four map units');

verifyWad(
	$cameraHeight->follow(72, false) === 72.0
	, 'vertical camera smoothing can snap across a portal discontinuity'
);

verifyWad($cameraHeight->follow(80) === 74.0, 'vertical camera smoothing resumes from the snapped portal height');
verifyWad($cameraHeight->follow(16) === 16.0, 'vertical camera follows downward motion immediately');
$liftCamera = new CameraHeight;
$liftCamera->follow(0);

verifyWad(
	$liftCamera->follow(8, true, true) === 8.0
		&& $liftCamera->follow(16, true, true) === 16.0
	, 'vertical camera remains attached to a rising lift instead of smoothing into its floor'
);

$reversingCamera = new CameraHeight;
$reversingCamera->follow(32);
$reversingCamera->follow(40);

verifyWad(
	$reversingCamera->follow(38) === 34.0
	, 'vertical camera does not continue rising after the physical eye starts falling'
);

verifyWad($reversingCamera->follow(30) === 30.0, 'falling eye catches the camera without smoothing');

$velocity = $registry->get($playerEntity, Velocity::class);
$rightMomentum = $velocity->x * sin($pose->angle) - $velocity->y * cos($pose->angle);
$beforeX = $pose->x;
$beforeY = $pose->y;
$movementControls->set([]);
$movement->update($registry, 1 / 35);
$coastTravel = ($pose->x - $beforeX) * sin($pose->angle) - ($pose->y - $beforeY) * cos($pose->angle);
$coastMomentum = $velocity->x * sin($pose->angle) - $velocity->y * cos($pose->angle);

verifyWad(
	$coastTravel > 0 && $coastMomentum > 0 && $coastMomentum < $rightMomentum
	, 'released movement coasts under fixed-tic friction'
);

$velocity->x = $velocity->y = 0;
$movement->update($registry, 0);

verifyWad(
	strcasecmp($registry->get($playerEntity, ActorState::class)->label, 'Spawn') === 0
	, 'stationary player returns to the third-person idle frame'
);

$beforeAngle = $pose->angle;
$movementControls->set([\DoomPhonics\Input\DoomActions::TURN => 1]);
$movement->update($registry, 1 / 35);
verifyWad($pose->angle < $beforeAngle, 'right turn rotates clockwise in WAD space');

$wallPose = new Transform(1408, -3504, $movementLevel->sectors[5]->floor);
$wallMotion = (new Collision($movementLevel))->moveAndSlide($wallPose, new Body(16, 56), -40, 60);

verifyWad(
	$wallMotion['collided'] && $wallMotion['dx'] > -16.1 && $wallMotion['dx'] < -15.9
	, 'Minkowski sweep stops the player radius at a wall'
);

verifyWad($wallMotion['dy'] > 59.9, 'Minkowski impact preserves tangential slide motion');
$overlapPose = new Transform(1388, -3504, $movementLevel->sectors[5]->floor);
$overlapMotion = (new Collision($movementLevel))->moveAndSlide($overlapPose, new Body(16, 56), 0, 10);

verifyWad(
	$overlapMotion['collided'] && $overlapMotion['dy'] === 10.0
	, 'depenetration retains the requested follow-up motion'
);

$obstacleRegistry = new Registry;
$actorPose = new Transform(1056, -3616, 0);
$actorBody = new Body(16, 56, true);
$movingActor = $obstacleRegistry->create($actorPose, $actorBody);
$blockingPose = new Transform(1120, -3616, 0);
$blockingBody = new Body(10, 42, true);
$blockingActor = $obstacleRegistry->create($blockingPose, $blockingBody);
$actorCollision = new Collision($movementLevel, 24, new ActorObstacles($obstacleRegistry));
$actorMotion = $actorCollision->moveAndSlide($actorPose, $actorBody, 100, 40, $movingActor);

verifyWad(
	$actorMotion['collided'] && abs($actorMotion['dx'] - 37.99) < .02
	, 'solid actors use square DECORATE radius bounds through Minkowski expansion'
);

verifyWad(
	abs($actorMotion['dy'] - 40) < .02 && $actorMotion['normals'][0] === [-1.0,0.0]
		&& $actorMotion['actors'] === [$blockingActor]
	, 'solid actor contact preserves tangential slide motion'
);

$actorPose->x = 1056;
$actorPose->y = -3616;
$blockingBody->solid = false;
$passMotion = $actorCollision->moveAndSlide($actorPose, $actorBody, 100, 0, $movingActor);
verifyWad(!$passMotion['collided'] && $passMotion['dx'] === 100.0, 'non-solid actors remain passable');

$blockingBody->solid = true;
$blockingPose->z = 64;
$actorPose->x = 1056;
$actorPose->y = -3616;
$verticalPass = $actorCollision->moveAndSlide($actorPose, $actorBody, 100, 0, $movingActor);
verifyWad(!$verticalPass['collided'], 'separated actor heights do not block one another');

// E1M1 linedef 92 opens from sector 35 at z=104 onto sector 24 at z=-8.
$pose->x = 60.22291236000336;
$pose->y = -3153.888543819998;
$pose->z = 104;
$pose->previousX = $pose->x;
$pose->previousY = $pose->y;
$pose->previousZ = $pose->z;
$pose->angle = atan2(35.77708736, 71.55417528);
$velocity->x = $velocity->y = $velocity->z = 0;
$registry->get($playerEntity, Body::class)->grounded = true;
$movementControls->set([\DoomPhonics\Input\DoomActions::FORWARD => 1]);
$crossedLedge = $fell = $landed = false;
$ledgeTick = $landingTick = null;

for($tick = 0; $tick < 100; $tick++)
{
	$movement->update($registry, 1 / 35);
	$sector = $movementLevel->sectorAt($pose->x, $pose->y);

	if(!$crossedLedge && $sector->index === 24)
	{
		$crossedLedge = true;
		$ledgeTick = $tick;
		$movementControls->set([]);
	}

	if($crossedLedge && $pose->z < 103.9 && $velocity->z < 0) $fell = true;

	if($fell && $registry->get($playerEntity, Body::class)->grounded)
	{
		$landed = true;
		$landingTick = $tick;
		break;
	}
}

verifyWad($crossedLedge && $fell, 'walking off a tall ledge starts vertical gravity');

verifyWad(
	$landed && abs($pose->z - $movementLevel->sectors[24]->floor) < .001 && $velocity->z === 0.0
	, 'falling player lands on the lower sector floor'
);

verifyWad(
	$landingTick - $ledgeTick >= 12 && $landingTick - $ledgeTick <= 16
	, 'ledge fall matches the vanilla one-unit-per-tic gravity cadence'
);

$thing8Entity = null;

foreach($registry->query(MapThing::class) as $entity)
if($registry->get($entity, MapThing::class)->index === 8)
{
	$thing8Entity = $entity;
	break;
}

verifyWad($thing8Entity !== null, 'E1M1 Thing 8 retains its map provenance');
verifyWad($registry->has($thing8Entity, Gravity::class), 'DoomImp DECORATE gravity becomes an ECS component');
$thing8Pose = $registry->get($thing8Entity, Transform::class);
$thing8Body = $registry->get($thing8Entity, Body::class);
$thing8Velocity = $registry->get($thing8Entity, Velocity::class);
$thing8Pose->x = 3440;
$thing8Pose->y = -3472;
$thing8Pose->z = 96;
$thing8Velocity->x = $thing8Velocity->y = $thing8Velocity->z = 0;
$thing8Body->grounded = true;
$thing8Collision = new Collision($movementLevel);
$thing8Collision->move($thing8Pose, $thing8Body, 0, 72);

verifyWad(
	$movementLevel->sectorAt($thing8Pose->x, $thing8Pose->y)->index === 57 && $thing8Pose->z === 96.0
	, 'E1M1 Thing 8 can cross its tall ledge without an early floor snap'
);

$actorPhysics = new ActorPhysicsSystem($thing8Collision);
$actorPhysics->update($registry, 1 / 35);

verifyWad(
	$thing8Pose->z < 96 && $thing8Velocity->z < 0 && !$thing8Body->grounded
	, 'E1M1 Thing 8 begins falling after A_Chase carries it off the ledge'
);

for($tick = 0; $tick < 60 && !$thing8Body->grounded; $tick++)
$actorPhysics->update($registry, 1 / 35);

verifyWad(
	$thing8Body->grounded && abs($thing8Pose->z - $movementLevel->sectors[57]->floor) < .001
	, 'E1M1 Thing 8 lands on the lower floor'
);

$e1m1 = $stack->map('E1M1');
$closedArea = array_sum(array_map('polygonArea', $e1m1->subsectorPolygons()));
$endpointArea = 0.0;

foreach($e1m1->subsectors as $subsector)
{
	$points = [];

	for($i = 0; $i < $subsector['count']; $i++)
	{
		$seg = $e1m1->segs[$subsector['first'] + $i];
		$vertex = $e1m1->vertices[$seg['start']];
		$points[] = [$vertex['x'], $vertex['y']];
	}

	$endpointArea += polygonArea($points);
}

verifyWad($closedArea > $endpointArea * 2, 'classic BSP miniseg gaps reconstructed for planes');

/** @param array{0:float,1:float} $point @param array{x:int,y:int} $from @param array{x:int,y:int} $to */
function pointLineDistance(array $point, array $from, array $to): float
{
	$dx = $to['x'] - $from['x'];
	$dy = $to['y'] - $from['y'];

	return abs(($point[0] - $from['x']) * $dy - ($point[1] - $from['y']) * $dx) / hypot($dx, $dy);
}

foreach([194 => [166,170],197 => [170,173]] as $lineIndex => [$firstLeaf,$secondLeaf])
{
	$line = $e1m1->linedefs[$lineIndex];
	$from = $e1m1->vertices[$line['from']];
	$to = $e1m1->vertices[$line['to']];
	$shared = [];

	foreach($e1m1->subsectorPolygons()[$firstLeaf] as $firstPoint)
	foreach($e1m1->subsectorPolygons()[$secondLeaf] as $secondPoint)
	if(hypot($firstPoint[0] - $secondPoint[0], $firstPoint[1] - $secondPoint[1]) < 1e-7)
	$shared[] = $firstPoint;

	verifyWad(
		(bool)array_filter($shared, static fn(array $point): bool => pointLineDistance($point, $from, $to) < 1e-7)
		, "E1M1 linedef $lineIndex ceiling leaves share a stitched source-line vertex"
	);
}

$e1m1Level = new Level($e1m1);
$e1m1Atlas = IndexedAtlas::forLevel($e1m1Level, $textures, 1024, null, $palette);
verifyWad($e1m1Atlas->id('ui', 'backdrop') >= 0, 'menu backdrop is packed with the map atlas');
verifyWad($e1m1Atlas->id('ui', 'reticle') >= 0, 'palette-matched target reticle is packed with the map atlas');
verifyWad($e1m1Atlas->id('picture', 'TITLEPIC') >= 0, 'title screen is packed with the map atlas');
verifyWad($e1m1Atlas->id('picture', 'M_NGAME') >= 0, 'menu labels are packed with the map atlas');
verifyWad($e1m1Atlas->id('picture', 'STCFN065') >= 0, 'menu text font is packed with the map atlas');

foreach(['WIMAP0','WILV00','WIF','WIOSTK','WIOSTI','WISCRT2','WITIME','WIPAR','WIPCNT','WINUM0'] as $patch)
verifyWad($e1m1Atlas->id('picture', $patch) >= 0, "intermission patch $patch is packed with the map atlas");

for($digit = 0; $digit <= 9; $digit++)
verifyWad(
	$e1m1Atlas->id('picture', 'STYSNUM' . $digit) >= 0
	, "small ammo-table digit $digit is packed with the map atlas"
);
$fontAtlas = IndexedAtlas::forLevel($e1m1Level, $fontCatalog);

verifyWad(
	$fontAtlas->id('picture', Fon2Font::pictureName(ord('A'))) >= 0
	, 'optional DBIGFONT glyphs are packed with the map atlas'
);

foreach(['STFTL00','STFTR10','STFOUCH2','STFEVL3','STFKILL4','STFGOD0','STFDEAD0'] as $face)
verifyWad($fontAtlas->id('picture', $face) >= 0, "status face $face is packed with the map atlas");

$e1m1Mesh = (new MapMeshBuilder($e1m1Level, $e1m1Atlas))->build();

$line131UpperWalls = array_filter(
	$e1m1Mesh->wallSpans
	, static fn(array $span): bool => $span['line'] === 131 && $span['slot'] === 'upper'
);

verifyWad(!$line131UpperWalls, 'E1M1 linedef 131 preserves the shared-sky opening');

$sharedSkyClosures = array_filter(
	$e1m1Mesh->sky->boundarySpans
	, static fn(array $span): bool => in_array($span['line'], [250,251,252,253,254], true)
);

verifyWad(!$sharedSkyClosures, 'E1M1 shared-sky portals remain open across differing ceiling heights');

$line205Sky = array_values(
	array_filter($e1m1Mesh->sky->boundarySpans, static fn(array $span): bool => $span['line'] === 205)
);

$line205 = $e1m1->linedefs[205];
$line205From = $e1m1->vertices[$line205['from']];
$line205To = $e1m1->vertices[$line205['to']];

$line205Vertices = array_chunk(
	array_slice(
		$e1m1Mesh->sky->vertices
		, $line205Sky[0]['first'] * SkyOcclusionMesh::FLOATS
		, $line205Sky[0]['count'] * SkyOcclusionMesh::FLOATS
	)
	, SkyOcclusionMesh::FLOATS
);

verifyWad(
	count($line205Sky) === 1
		&& $line205Sky[0]['sector'] === 22
		&& array_map('floatval', array_slice($line205Vertices[0], 0, 2)) === [
			(float)$line205From['x'],(float)$line205From['y']
		]
		&& array_map('floatval', array_slice($line205Vertices[1], 0, 2)) === [
			(float)$line205To['x'],(float)$line205To['y']
		]
		&& array_column($line205Vertices, 3) === [0.0,0.0,1.0,0.0,1.0,1.0]
	, 'E1M1 linedef 205 sky mask starts at its own terminal ceiling and extends upward'
);

$allSkyCapsAreTerminal = !array_filter(
	$e1m1Mesh->sky->boundarySpans
	, static fn(array $span): bool => !($e1m1Level->terminalSector($span['line'])?->hasSkyCeiling() ?? false)
);

verifyWad(
	$allSkyCapsAreTerminal
		&& $e1m1Mesh->sky->vertexCount() === count($e1m1Mesh->sky->boundarySpans) * 6
	, 'sky depth masks belong only to one-sided outdoor boundaries'
);

$windowThing = $e1m1->things[35];
$windowTarget = $e1m1->linedefs[190];
$windowA = $e1m1->vertices[$windowTarget['from']];
$windowB = $e1m1->vertices[$windowTarget['to']];
$windowX = ($windowA['x'] + $windowB['x']) / 2;
$windowY = ($windowA['y'] + $windowB['y']) / 2;
$windowDx = $windowX - $windowThing['x'];
$windowDy = $windowY - $windowThing['y'];
$windowDistance = hypot($windowDx, $windowDy);
$windowHits = [];

foreach($e1m1Level->spatial->cast(
	$windowThing['x']
	, $windowThing['y']
	, $windowDx
	, $windowDy
	, $windowDistance + 1
) as $hit
) $windowHits[$hit['line']] = $hit['distance'];

verifyWad(
	isset($windowHits[250], $windowHits[159], $windowHits[190])
		&& $windowHits[250] < $windowHits[190]
		&& $windowHits[159] < $windowHits[190]
		&& array_filter(
			$e1m1Mesh->sky->boundarySpans
			, static fn(array $span): bool => $span['line'] === 159 && $span['sector'] === 47
		)
	, 'E1M1 terminal sky boundary masks linedef 190 without closing the shared-sky portal'
);

$bigDoor2 = $e1m1Atlas->id('wall', 'BIGDOOR2');

$door151 = array_values(
	array_filter(array_chunk($e1m1Mesh->vertices, MapMesh::FLOATS), static fn(array $vertex): bool => $vertex[
		0
	] === 1536.0 && in_array($vertex[1], [-2432.0,-2560.0], true) && (int)$vertex[4] === $bigDoor2
	)
);

$doorTextureHeight = (float)$e1m1Atlas->rects[$bigDoor2]['height'];
verifyWad(count($door151) === 6, 'E1M1 door linedef 151 mesh');

verifyWad(
	!array_filter($door151, static fn(array $vertex): bool => array_slice($vertex, 8, 3) !== [
		3.0
		, (float)MapMesh::MIN_CEILING
		, $doorTextureHeight
	])
	, 'default upper door texture remains bottom pegged to its moving ceiling'
);

$dispatcher = new ActionDispatcher;
$lookCalls = 0;
$dispatcher->register('A_Look', static function() use (&$lookCalls): void { $lookCalls++; });

$registry = new Registry;
$stateEntity = $registry->create(new ActorState($zombie));
$states = new ActorStateSystem($dispatcher);
$states->update($registry, 1 / 35);
verifyWad($lookCalls === 1, 'DECORATE action dispatch on state entry');

for($i = 0; $i < 10; $i++) $states->update($registry, 1 / 35);
verifyWad($lookCalls === 2 && $registry->get($stateEntity, ActorState::class)->frame === 1, 'DECORATE state timing');

verifyWad(!array_diff_key($actions, LineSpecials::ACTIONS), 'all Episode 1 linedef actions implemented');

foreach($stack->maps() as $name)
{
	$level = new Level($stack->map($name));
	$atlas = IndexedAtlas::forLevel($level, $textures);
	$mesh = (new MapMeshBuilder($level, $atlas))->build();
	verifyWad($mesh->vertexCount() > 1000 && $mesh->vertexCount() % 3 === 0, "$name GLES mesh");
	verifyWad(strlen($atlas->pixels) === $atlas->width * $atlas->height * 2, "$name indexed atlas");

	foreach($atlas->rects as $rect)
	verifyWad(
		$rect['x'] + $rect['width'] <= $atlas->width && $rect['y'] + $rect['height'] <= $atlas->height
		, "$name atlas bounds"
	);
}

$map = $stack->map('E1M1');
$start = array_values(array_filter($map->things, static fn(array $thing): bool => $thing['type'] === 1))[0];
$level = new Level($map);

$rays = $level->spatial->cast(
	$start['x']
	, $start['y']
	, cos(deg2rad($start['angle']))
	, sin(deg2rad($start['angle']))
	, 8192
);

verifyWad(count($rays) > 0 && $rays[0]['distance'] > 0, 'spatial ray traversal');

$projectileTracer = new ProjectileTracer($level);
$projectileBody = new Body(6, 8, false, false, true);
$projectileSector = $level->sectorAt(3440, -3420);

// E1M1's local door sector finishes at ceiling height 68. A horizontal
// hitscan at z=41 must cross as soon as the moving plane reaches the ray,
// rather than waiting for the door to finish opening. The inverse case
// covers a floor lowering past the same ray.
$partialDoorLevel = new Level($map);
$partialDoorTracer = new ProjectileTracer($partialDoorLevel);
$hitscanBody = new Body(0, 0);
$partialDoor = $partialDoorLevel->sectors[4];
$partialDoor->ceiling = $partialDoor->previousCeiling = 40;
$closedCeilingHit = $partialDoorTracer->trace(new Transform(1450, -2496, 41), $hitscanBody, 120, 0, 0);
$partialDoor->ceiling = $partialDoor->previousCeiling = 41;
$openCeilingHit = $partialDoorTracer->trace(new Transform(1450, -2496, 41), $hitscanBody, 120, 0, 0);
$partialDoor->floor = $partialDoor->previousFloor = 42;
$partialDoor->ceiling = $partialDoor->previousCeiling = 72;
$closedFloorHit = $partialDoorTracer->trace(new Transform(1450, -2496, 41), $hitscanBody, 120, 0, 0);
$partialDoor->floor = $partialDoor->previousFloor = 41;
$openFloorHit = $partialDoorTracer->trace(new Transform(1450, -2496, 41), $hitscanBody, 120, 0, 0);

verifyWad(
	$closedCeilingHit?->surface === ProjectileImpact::WALL
		&& $closedCeilingHit->line === 151
		&& $openCeilingHit === null
		&& $partialDoor->ceiling > 41
	, 'hitscan crosses a rising door as soon as its ceiling clears the ray'
);

verifyWad(
	$closedFloorHit?->surface === ProjectileImpact::WALL
		&& $closedFloorHit->line === 151
		&& $openFloorHit === null
	, 'hitscan crosses a lowering door as soon as its floor clears the ray'
);

$floorImpact = $projectileTracer->trace(
	new Transform(3440, -3420, $projectileSector->floor + 20)
	, $projectileBody
	, 0
	, 0
	, -100
);

$ceilingImpact = $projectileTracer->trace(
	new Transform(3440, -3420, $projectileSector->ceiling - 20)
	, $projectileBody
	, 0
	, 0
	, 100
);

$skySector = $level->sectorAt(1392, -3632);

$openSkyPlaneImpact = $projectileTracer->trace(
	new Transform(1392, -3632, $skySector->ceiling - 20)
	, $projectileBody
	, 0
	, 0
	, 100
);

// A shot from Sector 20 crosses above its low sky ceiling through shared-sky
// linedef 253. Linedef 205 remains solid up to Sector 22's own ceiling, then
// becomes the terminal boundary through which a higher shot escapes.
$line205LowerImpact = $projectileTracer->trace(new Transform(1960, -3700, 40), $projectileBody, 80, 0, 0);
$line205UpperImpact = $projectileTracer->trace(new Transform(1960, -3700, 80), $projectileBody, 80, 0, 0);

$wallImpact = $projectileTracer->trace(
	new Transform(1408, -3504, $level->sectors[5]->floor + 32)
	, $projectileBody
	, -40
	, 60
	, 0
);

// E1M1 sector 46 is a narrow window between sectors 5 and 61. Its two portal
// linedefs carry ML_BLOCKING for actors, but Doom projectiles pass through the opening.
$windowBody = new Body(6, 8, false, false, true);
$windowEast = $projectileTracer->trace(new Transform(2720, -3236, 64), $windowBody, 64, 0, 0);
$windowWest = $projectileTracer->trace(new Transform(2768, -3236, 64), $windowBody, -64, 0, 0);
$windowHitscan = $projectileTracer->trace(new Transform(2720, -3236, 64), new Body(0, 0), 64, 0, 0);
$windowUpperWall = $projectileTracer->trace(new Transform(2720, -3236, 132), $windowBody, 64, 0, 0);

verifyWad(
	$floorImpact?->surface === ProjectileImpact::FLOOR && abs($floorImpact->z - $projectileSector->floor) < .001
	, 'projectile sweep collides with sector floors'
);

verifyWad(
	$ceilingImpact?->surface === ProjectileImpact::CEILING
		&& abs($ceilingImpact->z + $projectileBody->height - $projectileSector->ceiling) < .001
	, 'projectile sweep collides with sector ceilings'
);

verifyWad(
	$skySector->index === 5 && $skySector->hasSkyCeiling()
		&& $openSkyPlaneImpact === null
	, 'Sector 5 open sky is not a solid local ceiling plane'
);

verifyWad(
	$level->sectorAt(1960, -3700)->index === 20
		&& $level->sectors[20]->ceiling === 24.0
		&& $level->sectors[22]->ceiling === 64.0
		&& $line205LowerImpact?->surface === ProjectileImpact::WALL
		&& $line205LowerImpact->line === 205
		&& $line205UpperImpact?->escapesIntoSky()
		&& $line205UpperImpact->line === 205
	, 'linedef 205 blocks shots below its own ceiling and releases shots above it'
);

$escapingProjectile = new Projectile(1, 20);
$escapingProjectile->escapeIntoSky();

verifyWad(
	$escapingProjectile->escapedSky && $escapingProjectile->active
	, 'sky escape preserves projectile flight state'
);

$skyRegistry = new Registry;
$skyOwner = $skyRegistry->create(new Transform(1900, -3700, $level->sectors[20]->floor));
$rocketDefinition = $actors->named('Rocket');

$line205WallRocket = $factory->spawn($skyRegistry, $rocketDefinition, [
	'x' => 1960
	, 'y' => -3700
	, 'z' => 0
	, 'angle' => 0
	, 'type' => 0
	, 'index' => -1
	, 'flags' => []
], $map->sectors[20]);

$line205WallPose = $skyRegistry->get($line205WallRocket, Transform::class);
$line205WallPose->x = 1960;
$line205WallPose->y = -3700;
$line205WallPose->z = 40;
$line205WallVelocity = $skyRegistry->get($line205WallRocket, Velocity::class);
$line205WallVelocity->x = 320;
$line205WallVelocity->y = $line205WallVelocity->z = 0;
$skyRegistry->add($line205WallRocket, new Projectile($skyOwner, 20));

$skyRocket = $factory->spawn($skyRegistry, $rocketDefinition, [
	'x' => 1960
	, 'y' => -3700
	, 'z' => 0
	, 'angle' => 0
	, 'type' => 0
	, 'index' => -1
	, 'flags' => []
], $map->sectors[20]);

$skyRocketPose = $skyRegistry->get($skyRocket, Transform::class);
$skyRocketPose->x = 1960;
$skyRocketPose->y = -3700;
$skyRocketPose->z = 80;
$skyRocketVelocity = $skyRegistry->get($skyRocket, Velocity::class);
$skyRocketVelocity->x = 320;
$skyRocketVelocity->y = $skyRocketVelocity->z = 0;
$skyRegistry->add($skyRocket, new Projectile($skyOwner, 20));
$skyCombat = new CombatSystem($level, $actors, $factory, new Collision($level), new Controls, new ActionDispatcher);
$skyCombat->update($skyRegistry, .25);
$skyRocketProjectile = $skyRegistry->get($skyRocket, Projectile::class);
$line205WallProjectile = $skyRegistry->get($line205WallRocket, Projectile::class);

verifyWad(
	!$line205WallProjectile->active
		&& $skyRegistry->get($line205WallRocket, ActorState::class)->label === 'Death'
		&& $skyRocketProjectile->escapedSky && $skyRocketProjectile->active
		&& $skyRocketPose->x > 2009
		&& $skyRegistry->get($skyRocket, ActorState::class)->label !== 'Death'
	, 'live rockets impact the lower wall and keep receding through the upper sky boundary'
);

$skyRocketProjectile->ttl = 0;
$skyCombat->update($skyRegistry, 1 / 35);
$skyRegistry->flush();
verifyWad(!$skyRegistry->alive($skyRocket), 'escaped rocket retires silently at its far-flight lifetime');

verifyWad(
	$wallImpact?->surface === ProjectileImpact::WALL && $wallImpact->line === 37
	, 'projectile sweep retains solid linedef collision'
);

verifyWad(
	$windowEast === null && $windowWest === null && $windowHitscan === null
		&& $level->sectorAt(2784, -3236)->index === 61
		&& $level->sectorAt(2704, -3236)->index === 5
	, 'grenades, rockets, and bullet raycasts cross Sector 46 actor-blocking window portals'
);

verifyWad(
	$windowUpperWall?->surface === ProjectileImpact::WALL && $windowUpperWall->line === 276
	, 'Sector 46 window portal still blocks projectiles above its vertical opening'
);

$image = (new Raycaster($level, $textures, $palette, 160, 90))->render(
	$start['x']
	, $start['y']
	, $level->sectorAt($start['x'], $start['y'])->floor
	, deg2rad($start['angle'])
);

verifyWad(
	strlen($image->bytes()) === 160 * 90 * 4 && count(array_unique($image->pixels)) > 16
	, 'deterministic fallback rendering'
);

$specials = new LineSpecials($level);
verifyWad(count($specials->scroll) > 0, 'automatic scrolling walls indexed');
$playerState = new PlayerState;

$locked = array_values(
	array_filter($map->linedefs, static fn(array $line): bool => in_array($line['action'], [26,27,28], true))
);

if($locked)
{
	$line = $locked[0];
	$side = $line['right'] >= 0 ? $map->sidedefs[$line['right']]['sector'] : $map->sidedefs[$line['left']]['sector'];
	verifyWad(!$specials->activate($line['index'], 'use', $side, $playerState), 'locked door rejects missing key');
	verifyWad(str_contains($playerState->message, 'KEY'), 'locked door diagnostic');
}

$registry = new Registry;
$playerDefinition = $actors->numbered(1);

$playerEntity = $factory->spawn(
	$registry
	, $playerDefinition
	, $start
	, $map->sectors[$map->sectorAt($start['x'], $start['y'])]
);

$player = $registry->get($playerEntity, PlayerState::class);
$clipDefinition = $actors->numbered(2007);

$factory->spawn($registry, $clipDefinition, [
	'x' => $start['x']
	, 'y' => $start['y']
	, 'z' => 0
	, 'angle' => 0
	, 'type' => 2007
	, 'index' => 999
	, 'flags' => []
], $map->sectors[$map->sectorAt($start['x'], $start['y'])]);

(new PickupSystem($actors, true, $baseLanguage))->update($registry, 1 / 35);
$registry->flush();
verifyWad($player->ammo['Clip'] === 60, 'DECORATE ammo pickup applied through ECS');

verifyWad(
	$player->message === 'Picked up a clip.' && $player->messageTics === PlayerState::MESSAGE_TICS
	, 'DECORATE pickup messages resolve through LANGUAGE with a classic timeout'
);

verifyWad(
	$registry->get($playerEntity, ViewEffects::class)->bonusTics() === 6
	, 'accepted pickups add the classic gold palette flash'
);

require __DIR__ . '/pickups.php';
require __DIR__ . '/drops.php';

$facePickupRegistry = new Registry;

$facePickupPlayer = $factory->spawn(
	$facePickupRegistry
	, $playerDefinition
	, $start
	, $map->sectors[$map->sectorAt($start['x'], $start['y'])]
);

$shotgunDefinition = $actors->numbered(2001);

$factory->spawn($facePickupRegistry, $shotgunDefinition, [
	'x' => $start['x']
	, 'y' => $start['y']
	, 'z' => 0
	, 'angle' => 0
	, 'type' => 2001
	, 'index' => 1000
	, 'flags' => []
], $map->sectors[$map->sectorAt($start['x'], $start['y'])]);

(new PickupSystem($actors))->update($facePickupRegistry, 1 / 35);

verifyWad(
	$facePickupRegistry->get($facePickupPlayer, StatusFace::class)->expression() === 'grin'
	, 'new DECORATE weapon pickup triggers the HUD smile through ECS'
);

$controls = new Controls;
$controls->set([\DoomPhonics\Input\DoomActions::FIRE => 1]);
$dispatcher = new ActionDispatcher;
$combat = new CombatSystem($level, $actors, $factory, new Collision($level), $controls, $dispatcher);
$combat->update($registry, 1 / 35);
$stateSystem = new ActorStateSystem($dispatcher);

for($i = 0; $i < 24; $i++) $stateSystem->update($registry, 1 / 35);
verifyWad($player->weaponEntity !== null && $registry->alive($player->weaponEntity), 'DECORATE weapon state entity');
verifyWad($player->ammo['Clip'] < 60, 'weapon action consumed DECORATE ammo');

$referenceActions = [
	'A_BFG10K_FIRE_LAUNCH','A_BFG10K_FIRE_START','A_BFG10K_IDLE'
	, 'A_BFG9K_FIRE_LAUNCH','A_BFG9K_FIRE_START','A_BarrelDestroy','A_BruisAttack'
	, 'A_CHAINGUN_FIRE'
	, 'A_CPosAttack'
	, 'A_CPosRefire'
	, 'A_Chase'
	, 'A_CyberAttack'
	, 'A_Explode'
	, 'A_FaceTarget'
	, 'A_GRENADE_FIRE','A_HeadAttack','A_Look','A_NoBlocking','A_PISTOL_FIRE'
	, 'A_PLASMA_FIRE','A_PUNCH_MELEE','A_Pain','A_PosAttack','A_RAILGUN_FIRE'
	, 'A_ROCKET_FIRE','A_Respawn','A_SAW_IDLE','A_SAW_INIT','A_SAW_MELEE'
	, 'A_SAW_MELEE_SILENT','A_SHOTGUN_FIRE','A_SPosAttackUseAtkSound'
	, 'A_SUPERSHOTGUN_FIRE'
	, 'A_SUPERSHOTGUN_RELOAD'
	, 'A_SargAttack'
	, 'A_Scream'
	, 'A_PlayerScream'
	, 'A_SetFloorClip','A_SkullAttack','A_SpidRefire','A_TroopAttack'
	, 'A_UnSetFloorClip','A_XScream','A_GunFlash'
];

verifyWad(
	!array_filter($referenceActions, static fn(string $action): bool => !$dispatcher->handles($action))
	, 'every action implemented by the reference renderer is bound to a Doom-Phonics primitive'
);

$controls->set([]);

for($tick = 0; $tick < 30; $tick++)
{
	$stateSystem->update($registry, 1 / 35);
	$registry->flush();
}

$weaponEntity = $player->weaponEntity;
verifyWad($weaponEntity !== null, 'equipped weapon remains available for overlay actions');
$dispatcher->dispatch($weaponEntity, new StateFrame('MISG', 'B', 8, false, 'A_GunFlash'), $registry);
$flashEntities = $registry->query(WeaponOverlay::class);
verifyWad(count($flashEntities) === 1, 'A_GunFlash creates one independent ECS weapon layer');
$flashState = $registry->get($flashEntities[0], ActorState::class);

verifyWad(
	$flashState->label === 'Flash' && $flashState->current()?->sprite === 'PISF'
	, 'gun flash starts the weapon Flash sequence'
);

verifyWad(
	$registry->get($playerEntity, ActorState::class)->label === 'Missile'
	, 'gun flash animates the external player actor'
);

$stateSystem->update($registry, 1 / 35);

verifyWad(
	$registry->get($playerEntity, ViewLighting::class)->mapLightIncrease() === 16
	, 'A_Light1 raises view lighting through a typed component'
);

for($tick = 0; $tick < 10; $tick++)
{
	$stateSystem->update($registry, 1 / 35);
	$registry->flush();
}

verifyWad($registry->query(WeaponOverlay::class) === [], 'completed gun flash layer cleans itself up');

verifyWad(
	$registry->get($playerEntity, ViewLighting::class)->mapLightIncrease() === 0
	, 'LightDone restores normal view lighting'
);

$registry->add($playerEntity, new \DoomPhonics\Component\ViewInput(0, .4));
$beforePitchedShot = count($registry->query(Projectile::class));
$dispatcher->dispatch($weaponEntity, new StateFrame('MISG', 'B', 8, true, 'A_FireMissile'), $registry);
$pitchedShots = $registry->query(Projectile::class);
$pitchedRocket = $pitchedShots[array_key_last($pitchedShots)];

verifyWad(
	count($pitchedShots) === $beforePitchedShot + 1
		&& $registry->get($pitchedRocket, ActorState::class)->definition->name === 'Rocket'
		&& $registry->get($pitchedRocket, Velocity::class)->z > 0
	, 'player missiles inherit upward view pitch'
);

$registry->destroy($pitchedRocket);

$player->weapons['Chainsaw'] = true;
$registry->get($playerEntity, \DoomPhonics\Component\ViewInput::class)->requestWeaponSlot(1);
$controls->set([\DoomPhonics\Input\DoomActions::SLOT_1 => 1]);
$combat->update($registry, 1 / 35);

verifyWad(
	$player->weapon === 'Pistol' && $player->pendingWeapon === 'Fist'
	, 'browser and SDL number-key input share one declarative weapon-bank request'
);

verifyWad(
	$registry->get($weaponEntity, ActorState::class)->label === 'Deselect'
	, 'weapon switch enters the current weapon Deselect state'
);

$controls->set([]);
$loweredBeforeSwap = false;

for($tick = 0; $tick < 50; $tick++)
{
	$combat->update($registry, 1 / 35);
	$stateSystem->update($registry, 1 / 35);
	$registry->flush();
	$weaponOwner = $registry->get($weaponEntity, \DoomPhonics\Component\WeaponOwner::class);

	if($weaponOwner->offsetY > 0 && $player->weapon === 'Pistol') $loweredBeforeSwap = true;

	if($player->weapon === 'Fist' && $player->pendingWeapon === null
		&& $weaponOwner->offsetY === 0.0
		&& $registry->get($weaponEntity, ActorState::class)->label === 'Ready'
	) break;
}

verifyWad($loweredBeforeSwap, 'active weapon remains selected while its sprite lowers');

verifyWad(
	$player->weapon === 'Fist' && $player->pendingWeapon === null
		&& $registry->get($weaponEntity, ActorState::class)->label === 'Ready'
	, 'replacement weapon raises through Select before becoming ready'
);

$registry->get($playerEntity, \DoomPhonics\Component\ViewInput::class)->stepWeapon(1);
$combat->update($registry, 1 / 35);

verifyWad(
	$player->pendingWeapon === 'Chainsaw'
		&& $registry->get($weaponEntity, ActorState::class)->label === 'Deselect'
	, 'mouse-wheel step selects the next owned stack weapon and lowers the current one'
);

$projectileLifecycle = static function(
	string $name
	, float $x
	, float $y
	, float $z
	, float $vx
	, float $vy
	, float $vz
)
use ($registry, $factory, $actors, $level, $playerEntity, $combat, $stateSystem): int {
	$definition = $actors->named($name);
	$sector = $level->sectorAt($x, $y);

	$entity = $factory->spawn(
		$registry
		, $definition
		, ['x' => $x, 'y' => $y, 'z' => 0, 'angle' => 0, 'type' => 0, 'index' => -1, 'flags' => []]
		, ['floor' => $sector->floor, 'ceiling' => $sector->ceiling]
	);

	$pose = $registry->get($entity, Transform::class);
	$pose->z = $z;
	$velocity = $registry->get($entity, Velocity::class);
	$velocity->x = $vx;
	$velocity->y = $vy;
	$velocity->z = $vz;
	$registry->add($entity, new Projectile($playerEntity, (int)$definition->value('Damage', 3)));
	$combat->update($registry, 1 / 35);

	return $entity;
};

$impBall = $projectileLifecycle('DoomImpBall', 3440, -3420, $projectileSector->floor + 10, 0, 0, -700);

verifyWad(
	!$registry->get($impBall, Projectile::class)->active
		&& $registry->get($impBall, ActorState::class)->label === 'Death'
		&& abs($registry->get($impBall, Transform::class)->z - $projectileSector->floor) < .001
	, 'Imp fireballs explode on the floor'
);

$rocket = $projectileLifecycle('Rocket', 1408, -3504, $level->sectors[5]->floor + 32, -1400, 2100, 0);

verifyWad(
	!$registry->get($rocket, Projectile::class)->active
		&& $registry->get($rocket, ActorState::class)->label === 'Death'
		&& $registry->get($rocket, Transform::class)->x < 1408
		&& $registry->get($rocket, Transform::class)->x > 1376
	, 'rockets explode at the swept wall impact'
);

for($tick = 0; $tick < 24; $tick++)
{
	$stateSystem->update($registry, 1 / 35);
	$combat->update($registry, 1 / 35);
	$registry->flush();
}

verifyWad(
	!$registry->alive($impBall) && !$registry->alive($rocket)
	, 'completed fireball and rocket explosions leave the ECS'
);

$beforeProjectileCount = count($registry->query(Projectile::class));
$playerPose = $registry->get($playerEntity, Transform::class);
$cacoDefinition = $actors->named('Cacodemon');

$cacoEntity = $factory->spawn(
	$registry
	, $cacoDefinition
	, [
		'x' => $playerPose->x + 160
		, 'y' => $playerPose->y
		, 'z' => 0
		, 'angle' => 180
		, 'type' => 3005
		, 'index' => -1
		, 'flags' => []
	]
	, $map->sectors[$map->sectorAt((int)($playerPose->x + 160), (int)$playerPose->y)]
);

$registry->get($cacoEntity, MonsterBrain::class)->target = $playerEntity;
$dispatcher->dispatch($cacoEntity, new StateFrame('HEAD', 'D', 5, true, 'A_HeadAttack'), $registry);
$projectiles = $registry->query(Projectile::class);
verifyWad(count($projectiles) === $beforeProjectileCount + 1, 'A_HeadAttack spawns an ECS projectile');
$cacoBall = $projectiles[array_key_last($projectiles)];

verifyWad(
	$registry->get($cacoBall, ActorState::class)->definition->name === 'CacodemonBall'
	, 'Cacodemon attack uses DECORATE projectile data'
);

$player->powers['Invisibility'] = 60;
$beforeShadowProjectileCount = count($registry->query(Projectile::class));
mt_srand(7);
$dispatcher->dispatch($cacoEntity, new StateFrame('HEAD', 'D', 5, true, 'A_HeadAttack'), $registry);
$shadowProjectiles = $registry->query(Projectile::class);
$shadowBall = $shadowProjectiles[array_key_last($shadowProjectiles)];
mt_srand();

verifyWad(
	count($shadowProjectiles) === $beforeShadowProjectileCount + 1
		&& abs($registry->get($shadowBall, Velocity::class)->y) > .01
	, 'IDBEHOLDI invisibility perturbs monster projectile aim'
);

unset($player->powers['Invisibility']);

$explosionRegistry = new Registry;

$explosionPlayer = $factory->spawn(
	$explosionRegistry
	, $playerDefinition
	, ['x' => $start['x'], 'y' => $start['y'], 'z' => 0, 'angle' => 0, 'type' => 1, 'index' => -1, 'flags' => []]
	, $map->sectors[$map->sectorAt($start['x'], $start['y'])]
);

$barrel = $factory->spawn(
	$explosionRegistry
	, $barrelDefinition
	, [
		'x' => $start['x'] + 32
		, 'y' => $start['y']
		, 'z' => 0
		, 'angle' => 0
		, 'type' => 2035
		, 'index' => -1
		, 'flags' => []
	]
	, $map->sectors[$map->sectorAt((int)$start['x'] + 32, (int)$start['y'])]
);

$explosionActions = new ActionDispatcher;
new CombatSystem($level, $actors, $factory, new Collision($level), new Controls, $explosionActions);
$healthBeforeExplosion = $explosionRegistry->get($explosionPlayer, \DoomPhonics\Component\HitPoints::class)->current;
$explosionActions->dispatch($barrel, new StateFrame('BEXP', 'D', 5, true, 'A_Explode'), $explosionRegistry);

verifyWad(
	$explosionRegistry->get($explosionPlayer, \DoomPhonics\Component\HitPoints::class)->current < $healthBeforeExplosion
	, 'A_Explode applies radial damage through ECS bodies'
);

verifyWad(
	$explosionRegistry->get($explosionPlayer, ViewEffects::class)->damageTics() > 0
	, 'combat damage adds a post-armor red palette flash'
);

verifyWad(
	$explosionRegistry->get($explosionPlayer, StatusFace::class)->expression() === 'ouch'
	, 'combat damage records the large-hit HUD expression'
);

$protectedHealth = $explosionRegistry->get($explosionPlayer, \DoomPhonics\Component\HitPoints::class)->current;
$explosionRegistry->get($explosionPlayer, PlayerState::class)->powers['Invulnerable'] = 30;
$explosionActions->dispatch($barrel, new StateFrame('BEXP', 'D', 5, true, 'A_Explode'), $explosionRegistry);

verifyWad(
	$explosionRegistry->get($explosionPlayer, \DoomPhonics\Component\HitPoints::class)->current === $protectedHealth
	, 'IDBEHOLDV and invulnerability pickups block combat damage'
);

$explosionActions->dispatch($barrel, new StateFrame('TNT1', 'A', 1, false, 'A_BarrelDestroy'), $explosionRegistry);
$barrelBody = $explosionRegistry->get($barrel, Body::class);
verifyWad(!$barrelBody->solid && !$barrelBody->shootable, 'A_BarrelDestroy releases collision and targeting');
$barrelPose = $explosionRegistry->get($barrel, Transform::class);
$barrelPose->x += 80;
$explosionActions->dispatch($barrel, new StateFrame('TNT1', 'A', 1, false, 'A_Respawn'), $explosionRegistry);

verifyWad(
	$barrelBody->solid && $barrelBody->shootable && abs($barrelPose->x - ($start['x'] + 32)) < .001
	, 'A_Respawn restores the original typed spawn point'
);

$explosionActions->dispatch($barrel, new StateFrame('TNT1', 'A', 1, false, 'A_SetFloorClip'), $explosionRegistry);

verifyWad(
	$explosionRegistry->has($barrel, FloorClip::class)
	, 'A_SetFloorClip adds presentation state without changing the body'
);

$explosionActions->dispatch($barrel, new StateFrame('TNT1', 'A', 1, false, 'A_UnSetFloorClip'), $explosionRegistry);
verifyWad(!$explosionRegistry->has($barrel, FloorClip::class), 'A_UnSetFloorClip clears presentation state');

$damageResolver = new DamageResolver;
$deathRegistry = new Registry;

$damageSource = $factory->spawn(
	$deathRegistry
	, $playerDefinition
	, ['x' => $start['x'], 'y' => $start['y'], 'z' => 0, 'angle' => 0, 'type' => 1, 'index' => -1, 'flags' => []]
	, $map->sectors[$map->sectorAt($start['x'], $start['y'])]
);

$spawnTarget = static function(
	Registry $registry
	, object $definition
	, int $offset
	, int $type
)
use ($factory, $start, $map): int {
	$x = (int)$start['x'] + $offset;
	$y = (int)$start['y'];

	return $factory->spawn(
		$registry
		, $definition
		, ['x' => $x, 'y' => $y, 'z' => 0, 'angle' => 180, 'type' => $type, 'index' => -1, 'flags' => []]
		, $map->sectors[$map->sectorAt($x, $y)]
	);
};

$formerHuman = $spawnTarget($deathRegistry, $zombie, 64, 3004);
$normalDeath = new DamageEvent($formerHuman, $damageSource, 21);
verifyWad($damageResolver->apply($deathRegistry, $normalDeath), 'a live Former Human accepts lethal damage');
$formerHealth = $deathRegistry->get($formerHuman, HitPoints::class);
$formerState = $deathRegistry->get($formerHuman, ActorState::class);
$formerBody = $deathRegistry->get($formerHuman, Body::class);
$sourcePlayer = $deathRegistry->get($damageSource, PlayerState::class);

verifyWad(
	$formerHealth->current === -1 && $formerState->label === 'Death'
		&& !$formerBody->shootable && $sourcePlayer->kills === 1
	, 'ordinary lethal damage enters Death and immediately retires targeting once'
);

$formerState->frame = 2;
$formerState->ticks = 3;
$formerState->entered = true;

verifyWad(
	!$damageResolver->apply($deathRegistry, new DamageEvent($formerHuman, $damageSource, 100))
		&& $formerHealth->current === -1 && $formerState->label === 'Death'
		&& $formerState->frame === 2 && $formerState->ticks === 3.0 && $sourcePlayer->kills === 1
	, 'a Former Human cannot be shot into restarting its death animation'
);

$gibbedHuman = $spawnTarget($deathRegistry, $zombie, 96, 3004);
$damageResolver->apply($deathRegistry, new DamageEvent($gibbedHuman, $damageSource, 40));

verifyWad(
	$deathRegistry->get($gibbedHuman, HitPoints::class)->current === -20
		&& $deathRegistry->get($gibbedHuman, ActorState::class)->label === 'XDeath'
	, 'damage at the DECORATE gib threshold selects the Former Human XDeath sequence'
);

$deadPlayer = $spawnTarget($deathRegistry, $playerDefinition, 160, 1);
$deathRegistry->add($deadPlayer, new PlayerCommand);
$deathRegistry->add($deadPlayer, new ViewInput);
$deadCommand = $deathRegistry->get($deadPlayer, PlayerCommand::class);

$deadCommand->apply([
	'forward' => 1
	, 'strafe' => 1
	, 'turn' => 1
	, 'yaw' => .5
	, 'pitch' => .25
	, 'fire' => true
	, 'altFire' => true
	, 'use' => true
	, 'weaponSlot' => 5
	, 'weaponSteps' => 1
]);

$damageResolver->apply($deathRegistry, new DamageEvent($deadPlayer, $damageSource, 101));
$deadState = $deathRegistry->get($deadPlayer, PlayerState::class);
$deadEffects = $deathRegistry->get($deadPlayer, ViewEffects::class);

verifyWad(
	$deadState->deathTime === 0.0
		&& $deathRegistry->get($deadPlayer, ActorState::class)->label === 'Death'
		&& !$deathRegistry->get($deadPlayer, Body::class)->shootable
		&& $deadEffects->damageTics() === 100
		&& $deadEffects->colors($deadState)->palettePage === ViewColorState::FIRST_DAMAGE_PALETTE
			+ ViewColorState::DAMAGE_PALETTE_COUNT - 1
	, 'lethal player damage begins the corpse state and holds the strongest red PLAYPAL page'
);

(new PlayerEffectSystem)->update($deathRegistry, .5);
(new PlayerCommandSystem(new Controls))->update($deathRegistry, 1 / 35);
$deadActions = $deadCommand->actions();

verifyWad(
	abs($deadState->deathTime - .5) < 1e-9
		&& $deadState->viewHeight < PlayerState::VIEW_HEIGHT
		&& $deadState->viewHeight > PlayerState::DEATH_VIEW_HEIGHT
		&& !$deadState->canRestart()
		&& $deadActions['forward'] === 0.0 && $deadActions['strafe'] === 0.0
		&& $deadActions['turn'] === 0.0 && $deadActions['yaw'] === 0.0
		&& !$deadActions['fire'] && !$deadActions['altFire'] && !$deadActions['use']
	, 'dead players lose gameplay control while their camera descends'
);

(new PlayerEffectSystem)->update($deathRegistry, .6);
verifyWad($deadState->canRestart(), 'dead players can restart after the death presentation delay');

$thrustBarrel = $spawnTarget($deathRegistry, $barrelDefinition, 32, 2035);
$thrustPose = $deathRegistry->get($thrustBarrel, Transform::class);
$thrustVelocity = $deathRegistry->get($thrustBarrel, Velocity::class);
$beforeThrustX = $thrustPose->x;
$damageResolver->apply($deathRegistry, new DamageEvent($thrustBarrel, $damageSource, 5));
$initialThrust = $thrustVelocity->x;
(new ActorPhysicsSystem(new Collision($level)))->update($deathRegistry, 1 / 35);

verifyWad(
	$initialThrust > 0 && $thrustPose->x > $beforeThrustX
		&& $thrustVelocity->x > 0 && $thrustVelocity->x < $initialThrust
	, 'barrel Mass scales damage thrust and actor physics advances its damped momentum'
);

$damageResolver->apply($deathRegistry, new DamageEvent($thrustBarrel, $damageSource, 100));
$barrelHealth = $deathRegistry->get($thrustBarrel, HitPoints::class);
$barrelDeath = $deathRegistry->get($thrustBarrel, ActorState::class);
$barrelDeath->frame = 1;
$barrelDeath->ticks = 4;
$barrelDeath->entered = true;

verifyWad(
	$barrelDeath->label === 'Death' && !$deathRegistry->get($thrustBarrel, Body::class)->shootable
		&& !$damageResolver->apply($deathRegistry, new DamageEvent($thrustBarrel, $damageSource, 100))
		&& $barrelHealth->current === -85 && $barrelDeath->frame === 1 && $barrelDeath->ticks === 4.0
	, 'DONTGIB barrels remain untargetable without restarting their explosion'
);

$doorSample = DmxSound::decode($stack->lump('DSDOROPN'));
$doorWav = $doorSample->wav();

verifyWad(
	substr($doorWav, 0, 12) === 'RIFF' . pack('V', strlen($doorWav) - 8) . 'WAVE'
	, 'DMX sound wraps as a valid in-memory WAV'
);

verifyWad(
	substr($doorWav, 36, 4) === 'data' && strlen($doorWav) === 44 + strlen($doorSample->samples) * 2
	, 'WAV PCM payload dimensions'
);

verifyWad(SoundSample::wav($stack->lump('DSDOROPN')) === $doorWav, 'sound dispatcher converts native DMX');
$railWave = $weaponStack->lump('RAILGF1');

verifyWad(
	$railWave !== null && SoundSample::wav($railWave) === $railWave->bytes()
	, 'sound dispatcher preserves add-on RIFF/WAVE samples'
);

try
{
	SoundSample::wav('RIFF' . pack('V', 4) . 'NOPE', 'broken add-on sound');
	verifyWad(false, 'malformed RIFF sound must fail');
}
catch(UnexpectedValueException $error)
{
	verifyWad(str_contains($error->getMessage(), 'not WAVE'), 'malformed RIFF sound reports its format');
}

// A normal local door rises two units per tic, waits 150 tics, then closes.
$doorLevel = new Level($stack->map('E1M1'));
$doorRegistry = new Registry;

$sound = new class implements SoundEmitter {
	/** @var list<SoundEvent> */
	public array $events = [];

	public function beginScope(Stack $wad): void {}

	public function play(SoundEvent $event): void { $this->events[] = $event; }

	public function playMusic(MusicCue $cue): void {}

	public function stopScope(): void {}

	public function close(): void {}
};

$grenadeRegistry = new Registry;
$grenadeFactory = new ActorFactory($addonDefinitions->actors);

$grenadeCombat = new CombatSystem(
	$level
	, $addonDefinitions->actors
	, $grenadeFactory
	, new Collision($level)
	, new Controls
	, new ActionDispatcher
	, null
	, $sound
	, $addonDefinitions->sounds
);

$grenadeDefinition = $addonDefinitions->actors->named('Grenade');

$spawnGrenade = static function(
	float $x
	, float $y
	, float $z
	, float $vx
	, float $vy
	, float $vz
)
use ($grenadeRegistry, $grenadeFactory, $grenadeDefinition, $level): int {
	$sector = $level->sectorAt($x, $y);

	$entity = $grenadeFactory->spawn(
		$grenadeRegistry
		, $grenadeDefinition
		, ['x' => $x,'y' => $y,'z' => 0,'angle' => 0,'type' => 0,'index' => -1,'flags' => []]
		, ['floor' => $sector->floor,'ceiling' => $sector->ceiling]
	);

	$pose = $grenadeRegistry->get($entity, Transform::class);
	$pose->z = $z;
	$velocity = $grenadeRegistry->get($entity, Velocity::class);
	$velocity->x = $vx;
	$velocity->y = $vy;
	$velocity->z = $vz;
	$grenadeRegistry->add($entity, new Projectile(0, (int)$grenadeDefinition->value('Damage', 20)));

	return $entity;
};

$floorSector = $level->sectorAt(3440, -3420);
$floorGrenade = $spawnGrenade(3440, -3420, $floorSector->floor + 10, 0, 0, -700);
$grenadeCombat->update($grenadeRegistry, 1 / 35);
$floorBounce = $grenadeRegistry->get($floorGrenade, ProjectileBounce::class);

verifyWad(
	$grenadeRegistry->get($floorGrenade, Projectile::class)->active
		&& $grenadeRegistry->get($floorGrenade, Velocity::class)->z > 0
		&& $floorBounce->count === 1 && $floorBounce->maximum === 5
		&& abs($floorBounce->floorFactor - .7) < 1e-9
	, 'DECORATE Doom grenades reverse vertical velocity instead of exploding on the floor'
);

verifyWad($sound->events[array_key_last($sound->events)]->cue->lump() === 'DSBOUNCE', 'floor bounce emits BounceSound');

$ceilingGrenade = $spawnGrenade(3440, -3420, $floorSector->ceiling - 18, 0, 0, 700);
$grenadeCombat->update($grenadeRegistry, 1 / 35);

verifyWad(
	$grenadeRegistry->get($ceilingGrenade, Projectile::class)->active
		&& $grenadeRegistry->get($ceilingGrenade, Velocity::class)->z < 0
		&& $grenadeRegistry->get($ceilingGrenade, ProjectileBounce::class)->count === 1
	, 'DECORATE Doom grenades reverse vertical velocity instead of exploding on the ceiling'
);

$wallFloor = $level->sectors[5]->floor;
$wallGrenade = $spawnGrenade(1408, -3504, $wallFloor + 32, -1400, 2100, 0);
$incomingWallSpeed = hypot(-1400, 2100);
$grenadeCombat->update($grenadeRegistry, 1 / 35);
$wallVelocity = $grenadeRegistry->get($wallGrenade, Velocity::class);
$wallBounce = $grenadeRegistry->get($wallGrenade, ProjectileBounce::class);

verifyWad(
	$grenadeRegistry->get($wallGrenade, Projectile::class)->active
		&& $wallBounce->count === 1 && abs($wallBounce->wallFactor - .75) < 1e-9
		&& abs(hypot($wallVelocity->x, $wallVelocity->y) - $incomingWallSpeed * .75) < .01
		&& ($wallVelocity->x !== -1400.0 || $wallVelocity->y !== 2100.0)
	, 'DECORATE Doom grenades reflect from swept walls with WallBounceFactor damping'
);

verifyWad($sound->events[array_key_last($sound->events)]->cue->lump() === 'DSBOUNCE', 'wall bounce emits BounceSound');
$sound->events = [];

$railRegistry = new Registry;
$railFactory = new ActorFactory($addonDefinitions->actors);
$railPlayerDefinition = $addonDefinitions->actors->numbered(1);

$railPlayerEntity = $railFactory->spawn(
	$railRegistry
	, $railPlayerDefinition
	, $start
	, $map->sectors[$map->sectorAt($start['x'], $start['y'])]
);

$railPlayer = $railRegistry->get($railPlayerEntity, PlayerState::class);
$railPlayer->ammo['Cell'] = 20;
$railDefinition = $addonDefinitions->actors->named('Railgun');

$railWeapon = $railRegistry->create(
	new ActorState($railDefinition)
	, new \DoomPhonics\Component\WeaponOwner($railPlayerEntity)
);

$railActions = new ActionDispatcher;

new CombatSystem(
	$level
	, $addonDefinitions->actors
	, $railFactory
	, new Collision($level)
	, new Controls
	, $railActions
	, null
	, $sound
	, $addonDefinitions->sounds
);

$railActions->dispatch($railWeapon, new StateFrame('RLGG', 'E', 12, false, 'A_RAILGUN_FIRE'), $railRegistry);
$railBeams = $railRegistry->query(BeamEffect::class);
verifyWad(count($railBeams) === 1, 'railgun action creates one independent ECS beam');
$railBeamEntity = $railBeams[0];
$railBeam = $railRegistry->get($railBeamEntity, BeamEffect::class);
$railDx = $railBeam->to->x - $railBeam->from->x;
$railDy = $railBeam->to->y - $railBeam->from->y;
$railDz = $railBeam->to->z - $railBeam->from->z;
$railDistance = sqrt($railDx * $railDx + $railDy * $railDy + $railDz * $railDz);

verifyWad(
	$railDistance > 1 && $railBeam->duration === .75 && $railBeam->width === 8.0
		&& $railPlayer->ammo['Cell'] === 10
	, 'rail beam follows the resolved hitscan endpoint with reference duration and width'
);

verifyWad(
	$sound->events[array_key_last($sound->events)]->cue->lump() === 'RAILGF1'
	, 'railgun action emits its stack-local sound'
);

$railCheat = new CheatSystem($weaponSlots);
$railCheat->apply(CheatCommand::named(CheatCommand::OOPS_ALL_GIBS), $railRegistry, $railPlayerEntity);
$infiniteCells = $railPlayer->ammo['Cell'];
$railActions->dispatch($railWeapon, new StateFrame('RLGG', 'E', 12, false, 'A_RAILGUN_FIRE'), $railRegistry);

verifyWad(
	$railPlayer->ammo['Cell'] === $infiniteCells
	, 'IDOOPSALLGIBS Railgun attacks do not consume the player cell reserve'
);

$beamSystem = new BeamEffectSystem;
$beamSystem->update($railRegistry, .375);

verifyWad(
	abs($railBeam->remaining(0) - 1) < 1e-9 && abs($railBeam->remaining(1) - .5) < 1e-9
	, 'rail beam narrows smoothly between fixed simulation states'
);

$beamSystem->update($railRegistry, .375);
$railRegistry->flush();
verifyWad(!$railRegistry->alive($railBeamEntity), 'expired rail beams leave the ECS');
$sound->events = [];

$enemySoundRegistry = new Registry;

$enemyPlayer = $factory->spawn(
	$enemySoundRegistry
	, $playerDefinition
	, ['x' => $start['x'], 'y' => $start['y'], 'z' => 0, 'angle' => 0, 'type' => 1, 'index' => -1, 'flags' => []]
	, $map->sectors[$map->sectorAt($start['x'], $start['y'])]
);

$enemy = $factory->spawn(
	$enemySoundRegistry
	, $zombie
	, [
		'x' => $start['x'] + 8
		, 'y' => $start['y']
		, 'z' => 0
		, 'angle' => 180
		, 'type' => 3004
		, 'index' => -1
		, 'flags' => []
	]
	, $map->sectors[$map->sectorAt($start['x'], $start['y'])]
);

$enemyActions = new ActionDispatcher;
new CombatSystem($level, $actors, $factory, new Collision($level), new Controls, $enemyActions, null, $sound, $sounds);
$enemyActions->dispatch($enemy, new StateFrame('POSS', 'A', 10, false, 'A_Look'), $enemySoundRegistry);

verifyWad(
	in_array($sound->events[0]->cue->lump(), ['DSPOSIT1','DSPOSIT2','DSPOSIT3'], true)
		&& $sound->events[0]->listener?->x === $enemySoundRegistry->get($enemyPlayer, Transform::class)->x
	, 'A_Look emits the waking enemy sight sound with a player listener'
);

$sound->events = [];
$enemyActions->dispatch($enemy, new StateFrame('POSS', 'F', 8, false, 'A_PosAttack'), $enemySoundRegistry);
verifyWad($sound->events[0]->cue->lump() === 'DSPISTOL', 'Former Human attacks emit their DECORATE AttackSound');

$sound->events = [];
$enemyActions->dispatch($enemyPlayer, new StateFrame('PLAY', 'I', 10, false, 'A_PlayerScream'), $enemySoundRegistry);
verifyWad($sound->events[0]->cue->lump() === 'DSPLDETH', 'A_PlayerScream emits the player death sound');

$sound->events = [];

$monsterRailRegistry = new Registry;

$monsterRailPlayer = $railFactory->spawn(
	$monsterRailRegistry
	, $railPlayerDefinition
	, $start
	, $map->sectors[$map->sectorAt($start['x'], $start['y'])]
);

$monsterRailEnemy = $railFactory->spawn(
	$monsterRailRegistry
	, $addonDefinitions->actors->named('ZombieMan')
	, [
		'x' => $start['x'] + 64
		, 'y' => $start['y']
		, 'z' => 0
		, 'angle' => 180
		, 'type' => 3004
		, 'index' => -1
		, 'flags' => []
	]
	, $map->sectors[$map->sectorAt($start['x'] + 64, $start['y'])]
);

$monsterRailCheat = new CheatSystem($weaponSlots);
$monsterRailCheat->apply(CheatCommand::named(CheatCommand::OOPS_ALL_GIBS), $monsterRailRegistry, $monsterRailPlayer);
$monsterRailRegistry->get($monsterRailEnemy, MonsterBrain::class)->target = $monsterRailPlayer;
$monsterRailActions = new ActionDispatcher;

new CombatSystem(
	$level
	, $addonDefinitions->actors
	, $railFactory
	, new Collision($level)
	, new Controls
	, $monsterRailActions
	, null
	, $sound
	, $addonDefinitions->sounds
	, \DoomPhonics\Game\DoomGravity::ACCELERATION
	, null
	, $weaponSlots
	, true
	, true
	, $monsterRailCheat
);

$monsterRailActions->dispatch(
	$monsterRailEnemy
	, new StateFrame('POSS', 'F', 8, false, 'A_PosAttack')
	, $monsterRailRegistry
);

verifyWad(
	$monsterRailRegistry->get($monsterRailPlayer, HitPoints::class)->current === 0
		&& count($monsterRailRegistry->query(BeamEffect::class)) === 1
		&& $sound->events[array_key_last($sound->events)]->cue->lump() === 'RAILGF1'
	, 'IDOOPSALLGIBS redirects gun-bearing monster attacks through the Railgun runtime primitive'
);

$sound->events = [];

$demon = $factory->spawn(
	$enemySoundRegistry
	, $actors->named('Demon')
	, [
		'x' => $start['x'] + 16
		, 'y' => $start['y']
		, 'z' => 0
		, 'angle' => 180
		, 'type' => 3002
		, 'index' => -1
		, 'flags' => []
	]
	, $map->sectors[$map->sectorAt($start['x'], $start['y'])]
);

$enemySoundRegistry->get($demon, MonsterBrain::class)->target = $enemyPlayer;
$enemyActions->dispatch($demon, new StateFrame('SARG', 'G', 8, false, 'A_SargAttack'), $enemySoundRegistry);
verifyWad($sound->events[0]->cue->lump() === 'DSSGTATK', 'melee enemies emit their DECORATE AttackSound');

$sound->events = [];

$listener = new Transform(1056, -3616, 0, deg2rad(90));
$doorRegistry->create(new PlayerStart, $listener);
$doorSpecials = new LineSpecials($doorLevel, $doorRegistry, null, $sound);
$doorPlayer = new PlayerState;
verifyWad($doorSpecials->activate(151, 'use', 3, $doorPlayer), 'manual door starts an ECS sector mover');

verifyWad(
	count($doorRegistry->query(\DoomPhonics\Component\SectorMover::class)) === 1
	, 'sector mover is queryable through ECS'
);

verifyWad($sound->events[0]->cue->lump() === 'DSDOROPN', 'door emits a typed sound cue');
verifyWad($sound->events[0]->listener?->x === $listener->x, 'sound event carries the listener pose as one value');

$doorStart = $doorLevel->sectors[4]->ceiling;
$doorSpecials->update(1 / 35);

verifyWad(
	$doorLevel->sectors[4]->previousCeiling === $doorStart
		&& $doorLevel->sectors[4]->ceiling === $doorStart + 2
		&& $doorLevel->sectors[4]->ceilingAt(.5) === $doorStart + 1
	, 'moving door planes retain fixed-step endpoints for smooth render interpolation'
);

for($i = 1; $i < 34; $i++)$doorSpecials->update(1 / 35);
verifyWad($doorLevel->sectors[4]->ceiling === 68.0, 'door reaches the neighboring ceiling minus four');

for($i = 0; $i < 150; $i++)$doorSpecials->update(1 / 35);
verifyWad($doorLevel->sectors[4]->ceiling === 68.0, 'normal door observes the classic 150-tic wait');

for($i = 0; $i < 34; $i++)$doorSpecials->update(1 / 35);

verifyWad(
	$doorLevel->sectors[4]->ceiling === 0.0 && $doorLevel->sectors[4]->ceilingMover === null
	, 'completed door releases its plane lock'
);

$reverseLevel = new Level($stack->map('E1M1'));
$reverseSpecials = new LineSpecials($reverseLevel);
$reversePlayer = new PlayerState;
$reverseSpecials->activate(151, 'use', 3, $reversePlayer);

for($i = 0; $i < 10; $i++)$reverseSpecials->update(1 / 35);
$reverseSpecials->activate(151, 'use', 3, $reversePlayer);

for($i = 0; $i < 10; $i++)$reverseSpecials->update(1 / 35);
verifyWad($reverseLevel->sectors[4]->ceiling === 0.0, 'using a moving manual door reverses it');

// A lowering platform carries supported actors rather than leaving them suspended.
$liftMap = $stack->map('E1M2');
$liftLevel = new Level($liftMap);
$liftRegistry = new Registry;
[$liftX,$liftY] = $liftLevel->sectorCenter(137);

$liftEntity = $factory->spawn($liftRegistry, $playerDefinition, [
	'x' => $liftX
	, 'y' => $liftY
	, 'z' => 0
	, 'angle' => 0
	, 'type' => 1
	, 'index' => -1
	, 'flags' => []
], $liftMap->sectors[137]);

$liftPose = $liftRegistry->get($liftEntity, Transform::class);
$liftSpecials = new LineSpecials($liftLevel, $liftRegistry);

verifyWad(
	$liftSpecials->activate(178, 'use', 121, $liftRegistry->get($liftEntity, PlayerState::class))
	, 'repeatable lift starts'
);

$liftPose->beginStep();
$liftSpecials->update(1 / 35);

verifyWad(
	$liftLevel->sectors[137]->floor === 244.0
		&& $liftLevel->sectors[137]->floorAt(.5) === 246.0
		&& $liftPose->z === 244.0
		&& $liftPose->zAt(.5) === 246.0
	, 'moving floor and its supported ECS actors share smooth render interpolation'
);

$liftMovement = new PlayerMovementSystem(new Collision($liftLevel), $liftSpecials, new Controls);
$liftMovement->update($liftRegistry, 1 / 35);

verifyWad(
	$liftPose->previousZ === 248.0 && $liftPose->zAt(.5) === $liftLevel->sectors[137]->floorAt(.5)
	, 'later player movement preserves the lift carry interpolation endpoints'
);

$liftPose->beginStep();
$liftSpecials->update(1 / 35);
$liftMovement->update($liftRegistry, 1 / 35);

verifyWad(
	$liftPose->previousZ === 244.0 && $liftPose->z === 240.0 && $liftPose->zAt(.5) === 242.0
	, 'successive lift tics interpolate from the immediately preceding carried height'
);

$liftStayedSupported = true;
$liftReturnedUpward = false;
$lastLiftFloor = $liftLevel->sectors[137]->floor;

for($i = 0; $i < 300 && $liftRegistry->query(\DoomPhonics\Component\SectorMover::class); $i++)
{
	$liftPose->beginStep();
	$liftSpecials->update(1 / 35);
	$liftMovement->update($liftRegistry, 1 / 35);
	$currentLiftFloor = $liftLevel->sectors[137]->floor;

	if($currentLiftFloor > $lastLiftFloor) $liftReturnedUpward = true;

	$liftStayedSupported = $liftStayedSupported && abs($liftPose->z - $currentLiftFloor) <= .01;
	$lastLiftFloor = $currentLiftFloor;
}

verifyWad(
	$liftReturnedUpward && $liftStayedSupported
		&& $liftPose->z === $liftLevel->sectors[137]->floor
	, 'E1M2 sector 137 carries its player throughout the complete down-wait-up lift cycle'
);

// Switch and scrolling changes patch only their recorded map-mesh spans.
$switchLevel = new Level($liftMap);
$switchAtlas = IndexedAtlas::forLevel($switchLevel, $textures);
$switchMesh = (new MapMeshBuilder($switchLevel, $switchAtlas))->build();
$switchSpecials = new LineSpecials($switchLevel);
$switchPlayer = new PlayerState;
$oldSwitch = $switchLevel->sideTextures[489]['middle'];
verifyWad($switchSpecials->activate(389, 'use', 111, $switchPlayer), 'one-shot tagged door switch activates');

verifyWad(
	$oldSwitch === 'SW1COMP' && $switchLevel->sideTextures[489]['middle'] === 'SW2COMP'
	, 'switch texture changes immediately'
);

verifyWad(count($switchMesh->sync($switchLevel, $switchAtlas)) === 1, 'switch texture produces one bounded VBO update');

for($i = 0; $i < 40; $i++)$switchSpecials->update(1 / 35);
verifyWad($switchLevel->sideTextures[489]['middle'] === 'SW2COMP', 'one-shot switch stays changed');

$buttonLevel = new Level($stack->map('E1M3'));
$buttonSpecials = new LineSpecials($buttonLevel);
$buttonPlayer = new PlayerState;
$buttonSide = $buttonLevel->map->linedefs[520]['right'];
$buttonTexture = $buttonLevel->sideTextures[$buttonSide]['upper'];
verifyWad($buttonSpecials->activate(520, 'use', 71, $buttonPlayer), 'repeatable button activates');
verifyWad($buttonLevel->sideTextures[$buttonSide]['upper'] !== $buttonTexture, 'button displays its pressed texture');

for($i = 0; $i < 35; $i++)$buttonSpecials->update(1 / 35);
verifyWad($buttonLevel->sideTextures[$buttonSide]['upper'] === $buttonTexture, 'button restores after 35 tics');

$scrollLevel = new Level($stack->map('E1M1'));
$scrollAtlas = IndexedAtlas::forLevel($scrollLevel, $textures);
$scrollMesh = (new MapMeshBuilder($scrollLevel, $scrollAtlas))->build();
$scrollSpecials = new LineSpecials($scrollLevel);
$scrollSpecials->update(1 / 35);

$scrollingSpans = array_filter(
	$scrollMesh->wallSpans
	, static fn(array $span): bool => isset($scrollSpecials->scroll[$span['line']])
);

$scrollOffsets = array_map(
	static fn(array $span): int => $span['first'] * MapMesh::FLOATS * 4
	, array_values($scrollingSpans)
);

verifyWad(
	array_column($scrollMesh->sync($scrollLevel, $scrollAtlas), 0) === $scrollOffsets
	, 'automatic walls upload only their scrolling spans'
);

// Teleports reject the back side, then relocate pose, velocity, angle, and recovery state.
$teleportLevel = new Level($stack->map('E1M5'));
$teleportRegistry = new Registry;
$teleportPose = new Transform(0, 0, 32);
$teleportVelocity = new Velocity(10, 20, 30);
$teleportPlayer = new PlayerState;
$teleportView = new \DoomPhonics\Component\ViewInput(.4, .2);
$teleportRegistry->create($teleportPose, $teleportVelocity, $teleportPlayer, $teleportView, new PlayerStart);
$teleportSpecials = new LineSpecials($teleportLevel, $teleportRegistry);

verifyWad(
	!$teleportSpecials->activate(787, 'walk', 0, $teleportPlayer, $teleportPose, false)
	, 'teleport ignores a back-side crossing'
);

verifyWad(
	$teleportSpecials->activate(787, 'walk', 0, $teleportPlayer, $teleportPose, true)
	, 'teleport accepts a front-side crossing'
);

verifyWad($teleportView->yaw === 0.0, 'teleport discards pending look from the previous position');

verifyWad(
	$teleportVelocity->x === 0.0 && $teleportVelocity->y === 0.0 && $teleportVelocity->z === 0.0 && $teleportPlayer
		->reactionTics === 18
	, 'teleport clears momentum and applies recovery tics'
);

verifyWad(
	in_array($teleportLevel->sectorAt($teleportPose->x, $teleportPose->y)->index, $teleportLevel->tags[5], true)
	, 'teleport lands in its tagged destination sector'
);

// A real pistol trace reaches action 46 and opens its tagged door.
$shootMap = $stack->map('E1M2');
$shootLevel = new Level($shootMap);
$shootLine = $shootMap->linedefs[572];
$shootA = $shootMap->vertices[$shootLine['from']];
$shootB = $shootMap->vertices[$shootLine['to']];
$shootMidX = ($shootA['x'] + $shootB['x']) / 2;
$shootMidY = ($shootA['y'] + $shootB['y']) / 2;
$shootDx = $shootB['x'] - $shootA['x'];
$shootDy = $shootB['y'] - $shootA['y'];
$shootLength = hypot($shootDx, $shootDy);
$shooterX = $shootMidX + $shootDy / $shootLength * 24 + $shootDx / $shootLength * 12;
$shooterY = $shootMidY - $shootDx / $shootLength * 24 + $shootDy / $shootLength * 12;
$shootRegistry = new Registry;

$shooter = $factory->spawn($shootRegistry, $playerDefinition, [
	'x' => $shooterX
	, 'y' => $shooterY
	, 'z' => 0
	, 'angle' => rad2deg(atan2($shootMidY - $shooterY, $shootMidX - $shooterX))
	, 'type' => 1
	, 'index' => -1
	, 'flags' => []
], $shootMap->sectors[183]);

$shootControls = new Controls;
$shootControls->set([\DoomPhonics\Input\DoomActions::FIRE => 1]);
$shootActions = new ActionDispatcher;
$shootSpecials = new LineSpecials($shootLevel, $shootRegistry);

$shootCombat = new CombatSystem(
	$shootLevel
	, $actors
	, $factory
	, new Collision($shootLevel)
	, $shootControls
	, $shootActions
	, $shootSpecials
);

$shootCombat->update($shootRegistry, 1 / 35);
$shootStates = new ActorStateSystem($shootActions);

for($i = 0; $i < 24; $i++)$shootStates->update($shootRegistry, 1 / 35);
verifyWad($shootLevel->sectors[188]->ceilingMover !== null, 'hitscan impact activates a gun-triggered door');

$shootDoorMover = $shootRegistry->get(
	$shootLevel->sectors[188]->ceilingMover
	, \DoomPhonics\Component\SectorMover::class
);

verifyWad(
	isset($shootSpecials->consumed[572])
		&& $shootSpecials->definition(46)?->repeatable === false
		&& $shootDoorMover->returnTarget === null
		&& !$shootDoorMover->reversesWhenBlocked()
	, 'E1M2 linedef 572 starts a one-shot open-and-stay door'
);

$shotPuffs = $shootRegistry->query(TransientEffect::class);
verifyWad(count($shotPuffs) > 0, 'hitscan geometry impacts spawn DECORATE bullet puffs');
$shotPuff = $shotPuffs[0];
$shotPuffState = $shootRegistry->get($shotPuff, ActorState::class);
$shotPuffPose = $shootRegistry->get($shotPuff, Transform::class);
$shotPuffVelocity = $shootRegistry->get($shotPuff, Velocity::class);
$shooterPose = $shootRegistry->get($shooter, Transform::class);
$puffFacing = atan2($shooterPose->y - $shootMidY, $shooterPose->x - $shootMidX);
$puffAngleError = atan2(sin($shotPuffPose->angle - $puffFacing), cos($shotPuffPose->angle - $puffFacing));

verifyWad(
	$shotPuffState->definition->name === 'BulletPuff' && !$shootRegistry->has($shotPuff, Body::class)
	, 'bullet puff is a non-blocking catalog actor'
);

verifyWad(abs($puffAngleError) < .001, 'bullet puff faces the hitscan originator');

verifyWad(
	$shotPuffVelocity->z === 35.0 && abs(hypot($shotPuffVelocity->x, $shotPuffVelocity->y) - 7) < .001
	, 'bullet puff applies DECORATE VSpeed and reference wall drift'
);

verifyWad(
	abs($shotPuffVelocity->x * $shootDx / $shootLength + $shotPuffVelocity->y * $shootDy / $shootLength) < .001
		&& $shotPuffVelocity->x * ($shooterX - $shootMidX) + $shotPuffVelocity->y * ($shooterY - $shootMidY) > 0
	, 'oblique bullet puff drift follows the struck wall normal'
);

$shootWeapon = $shootRegistry->get($shooter, PlayerState::class)->weaponEntity;
verifyWad($shootWeapon !== null, 'hitscan test player has an equipped weapon actor');
$puffsBeforeFallbacks = count($shootRegistry->query(TransientEffect::class));

$shootActions->dispatch(
	$shootWeapon
	, new StateFrame('TEST', 'A', 0, false, 'A_FireBullets', [0,0,1,1,0,1])
	, $shootRegistry
);

$zeroPuffs = $shootRegistry->query(TransientEffect::class);

verifyWad(
	count($zeroPuffs) === $puffsBeforeFallbacks + 1
		&& $shootRegistry->get(
			$zeroPuffs[array_key_last($zeroPuffs)]
			, ActorState::class
		)->definition->name === 'BulletPuff'
	, 'numeric zero A_FireBullets puff argument uses the default BulletPuff actor'
);

$shootActions->dispatch(
	$shootWeapon
	, new StateFrame('TEST', 'A', 0, false, 'A_FireBullets', [0,0,1,1,'UnavailablePuff',1])
	, $shootRegistry
);

$missingPuffs = $shootRegistry->query(TransientEffect::class);

verifyWad(
	count($missingPuffs) === count($zeroPuffs) + 1
		&& $shootRegistry->get(
			$missingPuffs[array_key_last($missingPuffs)]
			, ActorState::class
		)->definition->name === 'BulletPuff'
	, 'unknown optional puff actors fall back without interrupting combat'
);

verifyWad(
	!$shootSpecials->activate(572, 'shoot', 183, $shootRegistry->get($shooter, PlayerState::class))
		&& $shootDoorMover->target > $shootLevel->sectors[188]->ceiling
		&& $shootDoorMover->returnTarget === null
	, 'E1M2 linedef 572 cannot repeat or reverse its active door'
);

verifyWad(
	count($shootRegistry->query(\DoomPhonics\Component\SectorMover::class)) === 1
	, 'busy gun trigger does not duplicate its sector mover'
);

// Light and damaging-sector thinkers use live sector state consumed by the renderer.
$effectLevel = new Level($stack->map('E1M1'));
$effectSpecials = new LineSpecials($effectLevel);
$effects = new SectorEffectSystem($effectLevel, $effectSpecials);
$high = $effectLevel->sectors[44]->light;
$effects->update(new Registry, 1 / 35);
verifyWad($effectLevel->sectors[44]->light === $high - 8, 'glowing sector changes live light by eight per tic');
$damageRegistry = new Registry;
[$damageX,$damageY] = $effectLevel->sectorCenter(13);

$damageEntity = $factory->spawn($damageRegistry, $playerDefinition, [
	'x' => $damageX
	, 'y' => $damageY
	, 'z' => 0
	, 'angle' => 0
	, 'type' => 1
	, 'index' => -1
	, 'flags' => []
], $effectLevel->map->sectors[13]);

$damagePose = $damageRegistry->get($damageEntity, Transform::class);
$damagePose->z = $effectLevel->sectors[13]->floor;

for($i = 0; $i < 32; $i++)$effects->update($damageRegistry, 1 / 35);

verifyWad(
	$damageRegistry->get($damageEntity, \DoomPhonics\Component\HitPoints::class)->current === 95
	, 'damage floor applies only on its 32-tic cadence'
);

verifyWad(
	$damageRegistry->get($damageEntity, ViewEffects::class)->damageTics() === 5
	, 'damaging floors feed the same player view effect as combat damage'
);

[$secretX,$secretY] = $effectLevel->sectorCenter(68);
$damagePose->x = $secretX;
$damagePose->y = $secretY;
$damagePose->z = $effectLevel->sectors[68]->floor;
$effects->update($damageRegistry, 1 / 35);

verifyWad(
	$damageRegistry->get($damageEntity, PlayerState::class)->secrets === 1 && $effectLevel->sectors[68]->special === 0
	, 'secret sector is claimed and cleared once'
);

$damagePose->x = $damageX;
$damagePose->y = $damageY;
$damagePose->z = $effectLevel->sectors[13]->floor;
$damageHealth = $damageRegistry->get($damageEntity, HitPoints::class);
$damageHealth->current = 1;

for($i = 0; $i < 31; $i++)$effects->update($damageRegistry, 1 / 35);

$floorDeath = $damageRegistry->get($damageEntity, PlayerState::class);
$floorDeathState = $damageRegistry->get($damageEntity, ActorState::class);
$floorDeath->advanceDeath(.25);
$floorDeathState->frame = 2;
$floorDeathState->ticks = 3;
$floorDeathState->entered = true;
$fallenHeight = $floorDeath->viewHeight;

for($i = 0; $i < 32; $i++)$effects->update($damageRegistry, 1 / 35);

verifyWad(
	$damageHealth->current === -4
		&& $floorDeath->deathTime === .25
		&& $floorDeath->viewHeight === $fallenHeight
		&& $floorDeathState->frame === 2
		&& $floorDeathState->ticks === 3.0
	, 'repeated damaging-floor pulses cannot replay a dead player camera or death state'
);

$exitLevel = new Level($stack->map('E1M8'));
$exitRegistry = new Registry;
[$exitX,$exitY] = $exitLevel->sectorCenter(66);

$exitEntity = $factory->spawn($exitRegistry, $playerDefinition, [
	'x' => $exitX
	, 'y' => $exitY
	, 'z' => 0
	, 'angle' => 0
	, 'type' => 1
	, 'index' => -1
	, 'flags' => []
], $exitLevel->map->sectors[66]);

$exitPlayer = $exitRegistry->get($exitEntity, PlayerState::class);
$exitPlayer->godMode = true;
$exitRegistry->get($exitEntity, \DoomPhonics\Component\HitPoints::class)->current = 10;
$exitEffects = new SectorEffectSystem($exitLevel, new LineSpecials($exitLevel, $exitRegistry));
$exitEffects->update($exitRegistry, 1 / 35);
verifyWad(!$exitPlayer->godMode && $exitPlayer->exited, 'E1M8 damaging exit clears god mode and exits at ten health');

// Every E1 action number dispatches through a typed definition on its first real map occurrence.
$firstActions = [];

foreach($stack->maps() as $name)
foreach($stack->map($name)->linedefs as $line)
if($line['action'] && !isset($firstActions[$line['action']]))$firstActions[$line['action']] = [$name,$line['index']];

foreach($firstActions as $number => [$name,$lineId])
{
	$action = LineSpecials::ACTIONS[$number];

	if($action['trigger'] === 'automatic')continue;

	$actionLevel = new Level($stack->map($name));
	$actionLine = $actionLevel->map->linedefs[$lineId];
	$actionPlayer = new PlayerState;
	$actionPlayer->keys = ['BlueCard' => true,'YellowCard' => true,'RedCard' => true];
	$actionPose = new Transform(0, 0, 0);
	$actorSector = $actionLine['right'] >= 0?$actionLevel->map->sidedefs[$actionLine['right']]['sector']:0;

	verifyWad(
		(new LineSpecials($actionLevel))->activate(
			$lineId
			, $action['trigger']
			, $actorSector
			, $actionPlayer
			, $actionPose
		)
		, "E1 action $number dispatches"
	);
}

try
{
	new Archive('PWAD' . pack('V2', 1, 12) . pack('V2a8', 200, 1, 'BAD'), 'broken.wad');
	verifyWad(false, 'truncated lump rejected');
}
catch(UnexpectedValueException $error)
{
	verifyWad(str_contains($error->getMessage(), 'broken.wad'), 'malformed diagnostics name source');
}

require __DIR__ . '/ports.php';

// Original Doom wake rules: forward sight, sector sound flooding, ambush, and damage retaliation.
$wakeMap = \DoomPhonics\Tests\PortMap::map();
$wakeLevel = new Level($wakeMap);
$wakeRegistry = new Registry;

$wakePlayer = $factory->spawn($wakeRegistry, $playerDefinition, [
	'x' => 32
	, 'y' => 64
	, 'z' => 0
	, 'angle' => 0
	, 'type' => 1
	, 'index' => -1
	, 'flags' => []
], $wakeMap->sectors[0]);

$rearEnemy = $factory->spawn($wakeRegistry, $zombie, [
	'x' => 112
	, 'y' => 64
	, 'z' => 0
	, 'angle' => 0
	, 'type' => 3004
	, 'index' => -1
	, 'flags' => []
], $wakeMap->sectors[0]);

$wakeActions = new ActionDispatcher;

new CombatSystem(
	$wakeLevel
	, $actors
	, $factory
	, new Collision($wakeLevel)
	, new Controls
	, $wakeActions
	, awareness: new EnemyAwareness($wakeLevel)
);

$wakeActions->dispatch($rearEnemy, new StateFrame('POSS', 'A', 10, false, 'A_Look'), $wakeRegistry);

verifyWad(
	!$wakeRegistry->get($rearEnemy, MonsterBrain::class)->awake
		&& strcasecmp($wakeRegistry->get($rearEnemy, ActorState::class)->label, 'Spawn') === 0
	, 'A_Look does not see a player more than melee range behind the monster'
);

$wakeRegistry->get($wakePlayer, Transform::class)->x = 64;
$wakeActions->dispatch($rearEnemy, new StateFrame('POSS', 'A', 10, false, 'A_Look'), $wakeRegistry);

verifyWad(
	$wakeRegistry->get($rearEnemy, MonsterBrain::class)->target === $wakePlayer
		&& strcasecmp($wakeRegistry->get($rearEnemy, ActorState::class)->label, 'See') === 0
	, 'A_Look preserves Doom close-behind wake behavior inside melee range'
);

$hearingMap = \DoomPhonics\Tests\PortMap::map();
$hearingMap->linedefs[0]['flags'] |= 0x40;
$hearingLevel = new Level($hearingMap);
// Keep the inter-sector opening audible while putting the raised monster eye above it.
$hearingLevel->sectors[1]->floor = $hearingLevel->sectors[1]->previousFloor = 100;
$hearingRegistry = new Registry;

$hearingPlayer = $factory->spawn($hearingRegistry, $playerDefinition, [
	'x' => 32
	, 'y' => 64
	, 'z' => 0
	, 'angle' => 180
	, 'type' => 1
	, 'index' => -1
	, 'flags' => []
], $hearingMap->sectors[0]);

$raisedSector = $hearingMap->sectors[1];
$raisedSector['floor'] = 100;

$hearingEnemy = $factory->spawn($hearingRegistry, $zombie, [
	'x' => 192
	, 'y' => 64
	, 'z' => 0
	, 'angle' => 0
	, 'type' => 3004
	, 'index' => -1
	, 'flags' => []
], $raisedSector
);

$ambushEnemy = $factory->spawn($hearingRegistry, $zombie, [
	'x' => 208
	, 'y' => 64
	, 'z' => 0
	, 'angle' => 0
	, 'type' => 3004
	, 'index' => -1
	, 'flags' => ['ambush' => true]
], $raisedSector
);

$hearingAwareness = new EnemyAwareness($hearingLevel);
$hearingControls = new Controls;
$hearingControls->set([\DoomPhonics\Input\DoomActions::FIRE => 1]);
$hearingActions = new ActionDispatcher;

$hearingCombat = new CombatSystem(
	$hearingLevel
	, $actors
	, $factory
	, new Collision($hearingLevel)
	, $hearingControls
	, $hearingActions
	, awareness: $hearingAwareness
);

$hearingCombat->update($hearingRegistry, 1 / 35);
$hearingStates = new ActorStateSystem($hearingActions);

for($tick = 0; $tick < 24; $tick++) $hearingStates->update($hearingRegistry, 1 / 35);

verifyWad(
	$hearingAwareness->target(1) === $hearingPlayer
		&& $hearingRegistry->get($hearingEnemy, MonsterBrain::class)->target === $hearingPlayer
		&& $hearingRegistry->get($hearingEnemy, MonsterBrain::class)->awake
	, 'player weapon fire crosses one open sound-blocking line and wakes an ordinary monster'
);

verifyWad(
	$hearingRegistry->get($ambushEnemy, MonsterBrain::class)->ambush
		&& !$hearingRegistry->get($ambushEnemy, MonsterBrain::class)->awake
	, 'ambush map things retain their flag and ignore weapon noise without line of sight'
);

$ambushHealth = $hearingRegistry->get($ambushEnemy, HitPoints::class);
$ambushHealth->painChance = 0;

verifyWad(
	(new DamageResolver)->apply($hearingRegistry, new DamageEvent($ambushEnemy, $hearingPlayer, 1))
		&& $hearingRegistry->get($ambushEnemy, MonsterBrain::class)->target === $hearingPlayer
		&& $hearingRegistry->get($ambushEnemy, MonsterBrain::class)->threshold === 100
		&& strcasecmp($hearingRegistry->get($ambushEnemy, ActorState::class)->label, 'See') === 0
	, 'direct damage wakes an ambush monster and locks retaliation to the source'
);

require __DIR__ . '/line-specials.php';
require __DIR__ . '/sector-volumes.php';
require __DIR__ . '/layered-navigation.php';
require __DIR__ . '/seeded-generation.php';
require __DIR__ . '/game-modes.php';
require __DIR__ . '/input.php';
require __DIR__ . '/graphics-atlas.php';

echo "$checks WAD parser checks passed\n";
