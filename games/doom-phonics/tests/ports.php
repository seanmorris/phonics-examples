<?php
declare(strict_types=1);

use DoomPhonics\Adapter\ActorFactory;
use DoomPhonics\Adapter\WadDefinitions;
use DoomPhonics\Archive\Stack;
use DoomPhonics\Component\ActorState;
use DoomPhonics\Component\BeamEffect;
use DoomPhonics\Component\Body;
use DoomPhonics\Component\HitPoints;
use DoomPhonics\Component\MapThing;
use DoomPhonics\Component\PlayerStart;
use DoomPhonics\Component\Projectile;
use DoomPhonics\Component\SectorMover;
use DoomPhonics\Component\SpawnPoint;
use DoomPhonics\Component\Transform;
use DoomPhonics\Component\Velocity;
use DoomPhonics\Component\ViewInput;
use DoomPhonics\Component\WeaponOwner;
use DoomPhonics\Definition\ActionDispatcher;
use DoomPhonics\Definition\ActorDefinition;
use DoomPhonics\Definition\StateFrame;
use DoomPhonics\Game\ActorObstacles;
use DoomPhonics\Game\Collision;
use DoomPhonics\Game\CameraTextureSetup;
use DoomPhonics\Game\Level;
use DoomPhonics\Game\LineSpecials;
use DoomPhonics\Game\PlayerState;
use DoomPhonics\Game\ProjectileImpact;
use DoomPhonics\Game\ProjectileTracer;
use DoomPhonics\Game\VerticalPhysics;
use DoomPhonics\Graphics\IndexedAtlas;
use DoomPhonics\Graphics\AnimDefsParser;
use DoomPhonics\Graphics\Palette;
use DoomPhonics\Graphics\PngDecoder;
use DoomPhonics\Graphics\TextureCatalog;
use DoomPhonics\Render\MapMeshBuilder;
use DoomPhonics\System\CombatSystem;
use DoomPhonics\Tests\PortMap;
use Phonics\Ecs\Registry;
use Phonics\Input\Controls;

require __DIR__ . '/fixtures/PortMap.php';

$chunk = static fn(string $name, string $data): string => pack('N', strlen($data)) . $name . $data
	. hash('crc32b', $name . $data, true);

$grays = implode('', array_map(static fn(int $i): string => str_repeat(chr($i), 3), range(0, 255)));
$portPalette = new Palette(new Stack(PortMap::archive(['PLAYPAL' => $grays])));
// Precomputed rows exercise None, Sub, Up, Average and Paeth, including left edges.
$filtered = pack('C*', 0, 1, 2, 3, 4, 1, 5, 1, 1, 1, 2, 4, 4, 4, 4, 3, 9, 3, 3, 3, 4, 4, 1, 1, 1);
$png = PngDecoder::SIGNATURE . $chunk('IHDR', pack('N2C5', 4, 5, 8, 3, 0, 0, 0))
	. $chunk('PLTE', $grays) . $chunk('tRNS', "\xff\0\x80") . $chunk('grAb', pack('N2', -7, 12))
	. $chunk('IDAT', gzcompress($filtered)) . $chunk('IEND', '');

$image = PngDecoder::decode(PortMap::archive(['PNG' => $png])->lump('PNG'), $portPalette);

verifyWad(
	$image->width === 4 && $image->height === 5 && $image->pixels === pack('C*', ...range(1, 20))
	, 'PNG reconstructs all five row filters'
);

verifyWad(
	$image->leftOffset === -7 && $image->topOffset === 12 && substr($image->alpha, 0, 3) === "\0\x80\xff"
	, 'PNG preserves signed grAb and indexed transparency'
);

foreach([2,6] as $type)
{
	$raw = $type === 6 ? pack('C*', 0, 40, 40, 40, 0, 99, 99, 99, 128) : pack('C*', 0, 40, 40, 40, 99, 99, 99);
	$rgb = PngDecoder::SIGNATURE . $chunk('IHDR', pack('N2C5', 2, 1, 8, $type, 0, 0, 0))
		. ($type === 2 ? $chunk('tRNS', pack('n3', 40, 40, 40)) : '')
		. $chunk('IDAT', gzcompress($raw)) . $chunk('IEND', '');

	$image = PngDecoder::decode(PortMap::archive(['RGB' => $rgb])->lump('RGB'), $portPalette);

	verifyWad(
		$image->pixels === chr(40) . chr(99) && $image->alpha === ($type === 6 ? "\0\x80" : "\0\xff")
		, "PNG type $type maps RGB and alpha to PLAYPAL"
	);
}

$badCrc = $png;
$badCrc[29] = chr(ord($badCrc[29]) ^ 1);

foreach([$badCrc, substr($png, 0, -4), str_replace(PngDecoder::SIGNATURE, 'badmagic', $png)] as $badPng)
{
	try
	{
		PngDecoder::decode(PortMap::archive(['BROKEN' => $badPng])->lump('BROKEN'), $portPalette);
		verifyWad(false, 'corrupt PNG must fail');
	}
	catch(UnexpectedValueException $error)
	{
		verifyWad(str_contains($error->getMessage(), 'BROKEN'), 'PNG rejection identifies its lump');
	}
}

$portMap = PortMap::map();

verifyWad(
	$portMap->format === 'HEXEN' && $portMap->sectorAt(32, 64) === 0 && $portMap->sectorAt(160, 64) === 1
	, 'Hexen binary records and BSP parse'
);

verifyWad(
	$portMap->things[5]['flags']['single']
	&& !$portMap->things[5]['flags']['deathmatch']
	&& $portMap->things[8]['flags']['deathmatch']
	, 'Hexen game mode bits are distinct from Doom multiplayer-only'
);

$portMap->linedefs[2]['action'] = 210;
$portMap->linedefs[2]['args'][0] = 2;
$portMap->linedefs[3]['action'] = 211;
$portMap->linedefs[3]['args'][0] = 2;
$portLevel = new Level($portMap);

verifyWad(
	$portLevel->sectors[0]->floorZ(0, 64) === 0.0 && $portLevel->sectors[0]->floorZ(128, 64) === 64.0 && $portLevel
		->sectors[0]->ceilingZ(64, 64) === 160.0
	, 'Plane_Align anchors both slopes at the shared edge and far vertex'
);

verifyWad(
	$portLevel->sectors[1]->floorLightSource === 0 && $portLevel->sectors[1]->ceilingLightSource === 0
	, 'Hexen floor and ceiling light transfers resolve sector tags'
);

$backMap = PortMap::map();
$backMap->linedefs[0]['args'] = [2,0,0,0,0];
$backLevel = new Level($backMap);

verifyWad(
	$backLevel->sectors[1]->floorZ(128, 64) === 0.0 && $backLevel->sectors[1]->floorZ(256, 64) === 64.0 && $backLevel
		->sectors[0]->floorZ(64, 64) === 0.0
	, 'Plane_Align honors back-side and unchanged-plane arguments'
);

$linePortalMap = PortMap::map();
$linePortalMap->linedefs[1]['action'] = 156;
$linePortalMap->linedefs[1]['args'] = [22,11,1,0,0];
$linePortalMap->linedefs[1]['left'] = 0;
$linePortalMap->linedefs[4]['action'] = 156;
$linePortalMap->linedefs[4]['args'] = [11,22,1,0,0];
$linePortalMap->linedefs[4]['left'] = 6;
$linePortalLevel = new Level($linePortalMap);
$lineCameraPortal = $linePortalLevel->cameraPortalForLine(1)?->link;

verifyWad(
	$lineCameraPortal?->destinationLine === 4 && $lineCameraPortal->passable()
	, 'Line_SetPortal resolves Hexen source and destination line IDs'
);

verifyWad(
	$lineCameraPortal->reciprocalId === $linePortalLevel->cameraPortalForLine(4)?->link->id
	, 'paired line portals identify the immediate reciprocal view'
);

$portalCamera = $lineCameraPortal->camera(16, 64, 8, 0, $linePortalLevel);

verifyWad(
	abs($portalCamera['x'] - 192) < .001
	&& abs($portalCamera['y'] - 144) < .001
	&& abs($portalCamera['angle'] - M_PI_2) < .001
	, 'line camera portals rotate and translate the view around destination v2'
);

$linePortalRegistry = new Registry;
$linePortalPose = new Transform(10, 64, 8, 0);
$linePortalPose->x = -10;
$linePortalVelocity = new Velocity(40, 0, 0);
$linePortalRegistry->create($linePortalPose, $linePortalVelocity);
$linePortalSpecials = new LineSpecials($linePortalLevel, $linePortalRegistry);

verifyWad(
	$linePortalSpecials->cross(10, 64, $linePortalPose, new PlayerState) === 1 && abs($linePortalPose->x - 192) < .001
		&& abs($linePortalPose->y - 118) < .001 && abs($linePortalPose->previousX - 192) < .001 && abs(
			$linePortalPose->previousY - 127.98
		) < .001 && abs($linePortalVelocity->x) < .001 && abs($linePortalVelocity->y - 40) < .001
	, 'passable camera portals interpolate from the destination crossing and rotate velocity'
);

$tinyPortalRegistry = new Registry;
$tinyPortalPose = new Transform(.001, 64, 8, 0);
$tinyPortalPose->x = -.001;
$tinyPortalRegistry->create($tinyPortalPose);

verifyWad(
	(new LineSpecials($linePortalLevel, $tinyPortalRegistry))->cross(
		.001
		, 64
		, $tinyPortalPose
		, new PlayerState
	) === 1 && abs($tinyPortalPose->previousY - 127.98) < .001 && abs($tinyPortalPose->y - 127.98) < .001
	, 'sub-pixel portal crossings inset both render history and position beyond the portal near plane'
);

verifyWad(
	$linePortalLevel->boundsCrossCameraPortal(8, 64, 16) && !$linePortalLevel->boundsCrossCameraPortal(32, 64, 16)
	, 'camera portal crossings include the player footprint before its center reaches the line'
);

verifyWad(
	$linePortalLevel->retargetCameraPortals(11, 0) && !$lineCameraPortal->active
	, 'Line_SetPortalTarget can disable a non-linked portal'
);

verifyWad(
	!$linePortalLevel->boundsCrossCameraPortal(8, 64, 16)
	, 'disabled camera portals do not suppress camera smoothing'
);

verifyWad(
	$lineCameraPortal->reciprocalId === null && $linePortalLevel->cameraPortalForLine(4)?->link->reciprocalId === null
	, 'retargeting clears stale reciprocal portal suppression'
);

verifyWad(
	$linePortalLevel->retargetCameraPortals(11, 22) && $lineCameraPortal->active && $lineCameraPortal
		->reciprocalId === $linePortalLevel->cameraPortalForLine(4)?->link->id
	, 'Line_SetPortalTarget restores a non-linked destination and its reciprocal pair'
);

$portalShot = (new ProjectileTracer($linePortalLevel))->tracePath(
	new Transform(16, 64, 96, M_PI)
	, new Body(0, 0)
	, -200
	, 0
	, 0
);

verifyWad(
	count($portalShot['segments']) === 2 && count($portalShot['portals']) === 1
		&& abs($portalShot['segments'][0]['dx'] + 16) < .001
		&& abs($portalShot['segments'][1]['x'] - 192) < .001
		&& $portalShot['segments'][1]['dy'] < -127.9
	, 'hitscan paths continue from the transformed side of a passable line portal'
);

verifyWad(
	$portalShot['impact']?->surface === ProjectileImpact::WALL
		&& abs($portalShot['impact']->x - 192) < .001
		&& abs($portalShot['impact']->y) < .001
		&& abs($portalShot['impact']->fraction - .72) < .001
	, 'portal hitscan distance and static impact remain continuous across map spaces'
);

$portalActors = (new WadDefinitions)->actors;
$portalCombatFactory = new ActorFactory($portalActors);
$portalCombatRegistry = new Registry;
$portalShooter = $portalCombatRegistry->create(new Transform(16, 64, 64, M_PI), new PlayerState);

$portalTarget = $portalCombatRegistry->create(
	new Transform(192, 80, 64)
	, new Body(8, 56, true, true)
	, new HitPoints(20, 20)
	, new ActorState($portalActors->numbered(3004))
);

$portalWeapon = $portalCombatRegistry->create(
	new ActorState($portalActors->named('Pistol'))
	, new WeaponOwner($portalShooter)
);

$portalActions = new ActionDispatcher;

new CombatSystem(
	$linePortalLevel
	, $portalActors
	, $portalCombatFactory
	, new Collision($linePortalLevel)
	, new Controls
	, $portalActions
);

mt_srand(1);

$portalActions->dispatch(
	$portalWeapon
	, new StateFrame('PISG', 'A', 0, false, 'A_FireBullets', [0,0,1,5,'BulletPuff',0])
	, $portalCombatRegistry
);

verifyWad(
	$portalCombatRegistry->get($portalTarget, HitPoints::class)->current === 10
	, 'one hitscan actor impact follows the transformed ray beyond a line portal without duplicating damage'
);

$portalActors->add(new ActorDefinition('PortalTestRailgun', 'Weapon'));
$portalRailRegistry = new Registry;
$portalRailShooter = $portalRailRegistry->create(new Transform(16, 64, 64, M_PI), new PlayerState);
$portalRailHealth = new HitPoints(1000, 1000);

$portalRailRegistry->create(
	new Transform(192, 80, 64)
	, new Body(8, 56, true, true)
	, $portalRailHealth
	, new ActorState($portalActors->numbered(3004))
);

$portalRailWeapon = $portalRailRegistry->create(
	new ActorState($portalActors->named('PortalTestRailgun'))
	, new WeaponOwner($portalRailShooter)
);

$portalRailActions = new ActionDispatcher;

new CombatSystem(
	$linePortalLevel
	, $portalActors
	, $portalCombatFactory
	, new Collision($linePortalLevel)
	, new Controls
	, $portalRailActions
);

$portalRailActions->dispatch(
	$portalRailWeapon
	, new StateFrame('RLGG', 'E', 0, false, 'A_FireRailgun')
	, $portalRailRegistry
);

$portalRailBeams = $portalRailRegistry->query(BeamEffect::class);
$portalRailEntrance = $portalRailRegistry->get($portalRailBeams[0], BeamEffect::class);
$portalRailExit = $portalRailRegistry->get($portalRailBeams[1], BeamEffect::class);

verifyWad(
	$portalRailHealth->current === 900 && count($portalRailBeams) === 2
		&& abs($portalRailEntrance->to->x) < .001 && abs($portalRailEntrance->to->y - 64) < .001
		&& abs($portalRailExit->from->x - 192) < .001 && abs($portalRailExit->from->y - 128) < .001
	, 'rail damage and beam segments pass through line portals without spanning disconnected rooms'
);

$portalProjectileRegistry = new Registry;
$portalProjectileOwner = $portalProjectileRegistry->create(new Transform(16, 64, 64, M_PI));

$portalProjectile = $portalProjectileRegistry->create(
	new Transform(16, 64, 96, M_PI)
	, new Velocity(-400, 0, 0)
	, new Body(2, 8, false, false, true)
	, new ActorState($portalActors->named('Rocket'))
	, new Projectile($portalProjectileOwner, 20)
);

$portalProjectileActions = new ActionDispatcher;

$portalProjectileCombat = new CombatSystem(
	$linePortalLevel
	, $portalActors
	, $portalCombatFactory
	, new Collision($linePortalLevel)
	, new Controls
	, $portalProjectileActions
);

$portalProjectileCombat->update($portalProjectileRegistry, .25);
$portalProjectilePose = $portalProjectileRegistry->get($portalProjectile, Transform::class);
$portalProjectileVelocity = $portalProjectileRegistry->get($portalProjectile, Velocity::class);

verifyWad(
	count($portalProjectileRegistry->query(Projectile::class)) === 1
		&& abs($portalProjectilePose->x - 192) < .001 && abs($portalProjectilePose->y - 44) < .001
		&& abs($portalProjectileVelocity->x) < .001 && abs($portalProjectileVelocity->y + 400) < .001
		&& abs($portalProjectilePose->previousX - 192) < .001
	, 'one moving projectile continues from the portal exit with rotated velocity and interpolation'
);

$sectorPortalMap = PortMap::map();
$sectorPortalMap->linedefs[1]['action'] = 57;
$sectorPortalMap->linedefs[1]['args'] = [1,0,0,0,0];
$sectorPortalMap->linedefs[4]['action'] = 57;
$sectorPortalMap->linedefs[4]['args'] = [1,0,0,1,0];
$sectorPortalLevel = new Level($sectorPortalMap);
$sectorCameraPortal = $sectorPortalLevel->cameraPortalForPlane(0, 0)?->link;

verifyWad(
	$sectorCameraPortal?->destinationSector === 1
	&& $sectorCameraPortal->alignment === DoomPhonics\Game\CameraPortalLink::ALIGN_FLOOR
	&& $sectorCameraPortal->destinationAlignment === DoomPhonics\Game\CameraPortalLink::ALIGN_CEILING
	&& $sectorCameraPortal->camera(32, 64, 8, 0, $sectorPortalLevel)['x'] === 224.0
	&& $sectorCameraPortal->camera(32, 64, 8, 0, $sectorPortalLevel)['z'] === 200.0
	, 'Sector_SetPortal anchor and reference lines assign a camera floor to tagged sectors'
);

$stackPortalMap = PortMap::map();
$stackPortalMap->things[] = ['type' => 9077,'id' => 77,'x' => 32,'y' => 64,'z' => 0,'args' => [0,0,0,0,0]];
$stackPortalMap->things[] = ['type' => 9078,'id' => 77,'x' => 192,'y' => 64,'z' => 0,'args' => [0,0,0,0,0]];
$stackPortalLevel = new Level($stackPortalMap);

verifyWad(
	$stackPortalLevel->cameraPortalForPlane(0, 0)?->link->destinationSector === 1 && $stackPortalLevel
		->cameraPortalForPlane(1, 1)?->link->destinationSector === 0 && $stackPortalLevel->cameraPortalForPlane(0, 0)
		?->link->destinationAlignment === DoomPhonics\Game\CameraPortalLink::ALIGN_CEILING && $stackPortalLevel
		->cameraPortalForPlane(1, 1)?->link->destinationAlignment === DoomPhonics\Game\CameraPortalLink::ALIGN_FLOOR
		&& $stackPortalLevel->cameraPortalForPlane(0, 0)?->link->camera(32, 64, 8, 0, $stackPortalLevel)['z'] === 184.0
	, '9077/9078 stack points create paired floor and ceiling camera surfaces'
);

$stackDown = $stackPortalLevel->cameraPortalForPlane(0, 0)?->link;
$stackUp = $stackPortalLevel->cameraPortalForPlane(1, 1)?->link;

verifyWad(
	$stackDown?->reciprocalId === $stackUp?->id && $stackUp?->reciprocalId === $stackDown?->id
	, 'stacked-sector portal pairs identify the reciprocal plane'
);

$portalDemoArchive = new DoomPhonics\Archive\Archive(
	file_get_contents(
		__DIR__ . '/../demo/PORTALS.WAD'
	) ?: throw new RuntimeException('Cannot read generated portal demo')
	, 'PORTALS.WAD'
);

$portalDemoMap = new DoomPhonics\Map\MapData('PORTALS', $portalDemoArchive->mapLumps('PORTALS'));
$portalDemoLevel = new Level($portalDemoMap);

verifyWad(
	$portalDemoArchive->maps() === ['PORTALS','PORTAL2','CAMERAS','POOL3D','LAYERS'] && $portalDemoMap->format === 'HEXEN'
	&& array_map(static fn(array $point): int => $portalDemoMap->sectorAt(...$point), [
		[128,128]
		, [640,128]
		, [1152,128]
		, [128,640]
		, [640,640]
		, [1152,640]
		, [640,1152]
		, [1152,1152]
	]) === [0,1,2,3,4,5,6,7]
	, 'generated portal demo has eight valid BSP rooms'
);

verifyWad(
	count($portalDemoLevel->cameraPortalLinks()) === 14 && count($portalDemoLevel->cameraPortalSurfaces()) === 14
		&& $portalDemoLevel->cameraPortalForLine(2)?->link->passable() && $portalDemoLevel->cameraPortalForPlane(7, 0)
		?->link->alignment === DoomPhonics\Game\CameraPortalLink::ALIGN_FLOOR && $portalDemoLevel->cameraPortalForPlane(
			6
			, 1
		)?->link->alignment === DoomPhonics\Game\CameraPortalLink::ALIGN_CEILING
	, 'generated portal demo exercises line, sector and stacked-sector camera portals'
);

$cameraDefinitions = AnimDefsParser::cameraTextures(<<<'ANIMDEFS'
// A layered declaration may replace the original logical dimensions.
CameraTexture monitor 64 32 HDR
CameraTexture "MONITOR" 128 64 FIT 256 128 WorldPanning
DOMTexture PHPINFO 512 256 FIT 512 128 WorldPanning
ANIMDEFS
);

verifyWad(
	count($cameraDefinitions) === 2 && $cameraDefinitions['MONITOR']->width === 128 && $cameraDefinitions['MONITOR']
		->height === 64 && $cameraDefinitions['MONITOR']->displayWidth() === 256 && $cameraDefinitions['MONITOR']
		->worldPanning && $cameraDefinitions['PHPINFO']->kind === DoomPhonics\Graphics\CameraTextureDefinition::DOM
	, 'ANIMDEFS dynamic textures support layering, DOMTexture, FIT, HDR and WorldPanning'
);

$cameraMap = new DoomPhonics\Map\MapData('CAMERAS', $portalDemoArchive->mapLumps('CAMERAS'));
$cameraLevel = new Level($cameraMap);
$cameraCatalog = new TextureCatalog(new Stack($archive, $portalDemoArchive));
CameraTextureSetup::apply($cameraLevel, $cameraCatalog);

$domBridge = new class {
	/** @var list<float> */
	public array $scrolls = [];

	public function render(int $width, int $height, int $headerHeight): bool { return true; }

	public function scrollBy(float $amount): void { $this->scrolls[] = $amount; }
};

DoomPhonics\Game\DomTextureSetup::apply($cameraLevel, $cameraCatalog, ['phpinfo' => $domBridge]);
$camera = $cameraLevel->camera(100);
$cameraBinding = $cameraLevel->cameraTextureBinding('camtex');
$domBinding = $cameraLevel->domTextureBinding('phpinfo');

verifyWad(
	$cameraMap->format === 'HEXEN' && count($cameraLevel->cameras()) === 1 && $camera
		?->type === DoomPhonics\Game\CameraViewpoint::SECURITY && $camera->args[2] === 16 && count(
			$cameraMap->sectors
		) === 19 && $cameraLevel->sectorAt(256, 96)->floorZ(256, 96) === -32.0 && $cameraLevel->sectorAt(
			240
			, 208
		)->floorZ(240, 208) === 0.0 && $cameraLevel->sectorAt(272, 208)->floorZ(272, 208) === 0.0 && $cameraBinding
		?->camera === $camera && $cameraBinding->fov === 90.0 && $cameraBinding->texture->width === 128 && count(
			$cameraLevel->mirrors()
		) === 1 && $domBinding?->bridge === $domBridge && $domBinding->texture->width === 512
		&& $domBinding->texture->displayHeight() === 256
	, 'camera objects, edited trench geometry, OPEN camera calls and trusted DOM providers bind generated dynamic surfaces'
);

$scrollLines = array_values(array_filter($cameraMap->linedefs, static fn(array $line): bool => $line['action'] === 80));
usort($scrollLines, static fn(array $a, array $b): int => $a['args'][0] <=> $b['args'][0]);
$cameraSpecials = new LineSpecials($cameraLevel);
$cameraPlayer = new PlayerState;

foreach($scrollLines as $line)
verifyWad($cameraSpecials->activate($line['index'], 'use', 0, $cameraPlayer), 'DOM-texture switch activates');
$scrolls = $domBridge->scrolls;
sort($scrolls);

verifyWad(
	count($scrollLines) === 4
	&& $scrolls === [-96.0,-96.0,96.0,96.0]
	&& array_reduce(
		$scrollLines
		, static fn(bool $valid, array $line): bool => $valid
			&& $cameraLevel->sideTextures[$line['right']]['lower'] === 'SW2COMP'
		, true
	)
	, 'raised repeatable switches execute safe ACS DOM-texture scroll calls'
);

$cameraAngle = $camera->angle;
$cameraLevel->update(.125);
verifyWad(abs($camera->angle - $cameraAngle) > .01, 'SecurityCamera things sweep their captured view over time');
$mirrorCamera = $cameraLevel->mirrors()[0]->camera($cameraLevel, 128, 128, 41, M_PI_2);

verifyWad(
	abs($mirrorCamera['x'] - 128) < .001
	&& abs($mirrorCamera['y'] + 128) < .001
	&& abs($mirrorCamera['angle'] - M_PI * 1.5) < .001
	, 'Line_Mirror reflects camera position and heading across its linedef'
);

$cameraAtlas = IndexedAtlas::forLevel($cameraLevel, $cameraCatalog);
$cameraMesh = (new MapMeshBuilder($cameraLevel, $cameraAtlas))->build();

verifyWad(
	count($cameraMap->subsectorPolygons()[0] ?? []) === 4 && count($cameraMesh->planeSpans) >= 4 && $cameraMesh
		->cameraTextures->vertexCount() >= 12 && $cameraMesh->mirrors->vertexCount() === 6
	, 'partitioned floors, ceilings, camera, DOM-texture and mirror walls build distinct surfaces'
);

$cameraRegistry = new Registry;

$cameraPopulation = (new ActorFactory((new WadDefinitions)->actors))->populate(
	$cameraRegistry
	, $cameraMap
	, 2
	, false
	, null
	, null
	, $cameraLevel
);

verifyWad(
	$cameraPopulation['missing'] === [] && count($cameraPopulation['entities']) === 3
	, 'camera things remain structural while the camera demonstration spawns gameplay actors'
);

$portalDemoLineDestination = $portalDemoLevel->cameraPortalForLine(18)?->link->destinationSector;
$portalDemoStackDestination = $portalDemoLevel->cameraPortalForPlane(4, 0)?->link->destinationSector;

verifyWad(
	$portalDemoLineDestination === 5 && $portalDemoStackDestination === 6
	, 'line and stacked portals use distinct destination spaces instead of drawing actors twice'
);

$portalDemoCeiling = $portalDemoLevel->cameraPortalForPlane(2, 1)?->link;
$portalDemoFloor = $portalDemoLevel->cameraPortalForPlane(7, 0)?->link;

verifyWad(
	$portalDemoCeiling?->destinationSector === 7
	&& $portalDemoCeiling->destinationAlignment === DoomPhonics\Game\CameraPortalLink::ALIGN_FLOOR
	&& $portalDemoCeiling->camera(
		$portalDemoCeiling->originX
		, $portalDemoCeiling->originY
		, 0
		, 0
		, $portalDemoLevel
	)['z'] === -128.0
	&& $portalDemoCeiling->reciprocalId === $portalDemoFloor?->id
	&& $portalDemoFloor?->destinationSector === 2
	&& $portalDemoFloor->destinationAlignment === DoomPhonics\Game\CameraPortalLink::ALIGN_CEILING
	&& $portalDemoFloor->camera(
		$portalDemoFloor->originX
		, $portalDemoFloor->originY
		, 0
		, 0
		, $portalDemoLevel
	)['z'] === 128.0
	&& $portalDemoFloor->reciprocalId === $portalDemoCeiling?->id
	, 'explicit sector portals use an isolated reciprocal pair and cross between opposite planes'
);

$portalDemoStack = $portalDemoLevel->cameraPortalForPlane(4, 0)?->link;

verifyWad(
	$portalDemoStack?->camera(
		$portalDemoStack->originX
		, $portalDemoStack->originY
		, 128
		, 0
		, $portalDemoLevel
		, PlayerState::VIEW_HEIGHT
	)['z'] === 128.0 && $portalDemoStack->flipsVertical()
			&& !$portalDemoLevel->cameraPortalForLine(18)?->link->flipsVertical()
	, 'opposite plane portal captures retain the camera on the approach side of the destination plane'
);

$portalDemoTypes = array_count_values(array_column($portalDemoMap->things, 'type'));

verifyWad(
	($portalDemoTypes[35] ?? 0) === 1 && ($portalDemoTypes[2028] ?? 0) === 1 && ($portalDemoTypes[2035] ?? 0) === 2
	, 'portal demo decorations remain distinguishable after the first barrel is destroyed'
);

$portalDemoCenterBlockers = array_filter(
	$portalDemoMap->things
	, static fn(array $thing): bool => in_array($thing['type'], [35,2028], true)
		&& in_array($thing['y'], [128,640], true)
);

verifyWad(!$portalDemoCenterBlockers, 'portal demo keeps solid decorations off the traversal centerline');
$portalDemoDefinitions = (new WadDefinitions)->actors;
$portalDemoCatalog = new TextureCatalog($stack);
$portalDemoInvisibleSolids = [];

foreach($portalDemoMap->things as $thing)
{
	$definition = $portalDemoDefinitions->numbered((int)$thing['type']);
	$frame = $definition?->states['spawn']?->frames[0] ?? null;

	if($definition?->hasFlag('SOLID') && (!$frame || !$portalDemoCatalog->sprite($frame->sprite, $frame->frame)))
	$portalDemoInvisibleSolids[] = $thing['type'];
}

verifyWad($portalDemoInvisibleSolids === [], 'portal demo has no solid actors whose sprites are absent from Doom 1');
$portalDemoFactory = new ActorFactory($portalDemoDefinitions);
$portalDemoRegistry = new Registry;

$portalDemoPopulation = $portalDemoFactory->populate(
	$portalDemoRegistry
	, $portalDemoMap
	, 2
	, false
	, null
	, null
	, $portalDemoLevel
);

verifyWad(
	$portalDemoPopulation['missing'] === [] && count($portalDemoPopulation['entities']) === 5
	, 'generated portal demo treats stack viewpoints as structural map markers'
);

$portalRoomMap = new DoomPhonics\Map\MapData('PORTAL2', $portalDemoArchive->mapLumps('PORTAL2'));
$portalRoomLevel = new Level($portalRoomMap);
$portalRoomPoints = [];

foreach([0,256,512] as $y) foreach([0,256,512] as $x) $portalRoomPoints[] = [$x + 128,$y + 128];
$portalRoomPoints[] = [1024,384];

foreach([1536,1792,2048] as $y) foreach([0,256,512] as $x) $portalRoomPoints[] = [$x + 128,$y + 128];
$portalRoomPoints[] = [-256,1920];

verifyWad(
	$portalRoomMap->format === 'HEXEN' && count($portalRoomMap->sectors) === 20 && array_map(
		static fn(array $point): int => $portalRoomMap->sectorAt(...$point)
		, $portalRoomPoints
	) === range(0, 19)
	, 'second portal demo has two valid nine-sector rooms and one hallway per room'
);

verifyWad(
	$portalRoomMap->sectors[4]['floor'] === 112
	&& array_map(
		static fn(int $sector): int => $portalRoomMap->sectors[$sector]['floor']
		, [0,1,2,3,5,6,7,8]
	) === array_fill(0, 8, 128)
	&& $portalRoomMap->sectors[14]['ceiling'] === 112
	, 'upper room center is a one-ninth floor recess aligned with the lower room ceiling'
);

$portalRoomActions = array_values(
	array_filter($portalRoomMap->linedefs, static fn(array $line): bool => $line['action'] === 156)
);

verifyWad(
	count($portalRoomActions) === 2
	&& $portalRoomActions[0]['args'] === [202,201,1,1,0]
	&& $portalRoomActions[1]['args'] === [201,202,1,1,0]
	&& $portalRoomLevel->cameraPortalForLine($portalRoomActions[0]['index'])?->link->passable()
	&& $portalRoomLevel->cameraPortalForLine($portalRoomActions[1]['index'])?->link->passable()
	, 'hallway ends form one reciprocal passable Line_SetPortal pair'
);

$portalRoomEast = $portalRoomLevel->cameraPortalForLine($portalRoomActions[0]['index'])?->link;
$portalRoomWest = $portalRoomLevel->cameraPortalForLine($portalRoomActions[1]['index'])?->link;
$portalRoomEastExit = $portalRoomEast?->point(1290, 384);
$portalRoomWestExit = $portalRoomWest?->point(-520, 1920);

verifyWad(
	$portalRoomEastExit !== null
	&& $portalRoomWestExit !== null
	&& abs($portalRoomEastExit[0] + 502) < .01
	&& abs($portalRoomEastExit[1] - 1920) < .01
	&& $portalRoomMap->sectorAt((int)round($portalRoomEastExit[0]), (int)round($portalRoomEastExit[1])) === 19
	&& abs($portalRoomWestExit[0] - 1272) < .01
	&& abs($portalRoomWestExit[1] - 384) < .01
	&& $portalRoomMap->sectorAt((int)round($portalRoomWestExit[0]), (int)round($portalRoomWestExit[1])) === 9
	, 'crossing either hallway seam lands inside the reciprocal hallway'
);

verifyWad(
	$portalRoomEast?->alignment === DoomPhonics\Game\CameraPortalLink::ALIGN_FLOOR
	&& $portalRoomWest?->alignment === DoomPhonics\Game\CameraPortalLink::ALIGN_FLOOR
	&& $portalRoomEast->z(128, $portalRoomLevel) === -144.0
	&& $portalRoomWest->z(-144, $portalRoomLevel) === 128.0
	&& $portalRoomMap->sectors[9]['ceiling'] - $portalRoomMap->sectors[9]['floor'] === 256
	&& $portalRoomMap->sectors[19]['ceiling'] - $portalRoomMap->sectors[19]['floor'] === 256
	, 'hallway portals align equal-height openings by their floors'
);

$portalRoomDown = $portalRoomLevel->cameraPortalForPlane(4, 0)?->link;
$portalRoomUp = $portalRoomLevel->cameraPortalForPlane(14, 1)?->link;

verifyWad(
	count($portalRoomLevel->cameraPortalLinks()) === 4 && $portalRoomDown?->destinationSector === 14 && $portalRoomUp
		?->destinationSector === 4 && $portalRoomDown->passable() && $portalRoomUp->passable() && $portalRoomDown
		->reciprocalId === $portalRoomUp->id && $portalRoomUp->reciprocalId === $portalRoomDown->id
	, 'center floor and ceiling form the isolated reciprocal stacked-sector pair'
);

verifyWad(
	abs($portalRoomDown->bodyZ(160, 8, $portalRoomLevel) + 4 - $portalRoomDown->cameraZ(164, $portalRoomLevel)) < .001
	, 'plane portal traversal maps a projectile center continuously with the portal camera'
);

$portalRoomShot = (new ProjectileTracer($portalRoomLevel))->tracePath(
	new Transform(384, 384, 169, 0)
	, new Body(0, 0)
	, 400
	, 0
	, -400
);

verifyWad(
	count($portalRoomShot['segments']) === 2
	&& count($portalRoomShot['portals']) === 1
	&& abs($portalRoomShot['segments'][0]['dx'] - 57) < .001
	&& abs($portalRoomShot['segments'][1]['x'] - 441) < .001
	&& abs($portalRoomShot['segments'][1]['y'] - 1920) < .001
	&& abs($portalRoomShot['segments'][1]['z'] - 112) < .001
	, 'downward hitscan paths continue from a floor portal through the remote ceiling'
);

verifyWad(
	$portalRoomShot['impact']?->surface === ProjectileImpact::FLOOR && abs($portalRoomShot['impact']->x - 697) < .001
		&& abs($portalRoomShot['impact']->z + 144) < .001
	, 'a floor-portal hitscan reaches solid geometry in the destination room'
);

$portalRoomCombatRegistry = new Registry;

$portalRoomShooter = $portalRoomCombatRegistry->create(
	new Transform(384, 384, 128, 0)
	, new PlayerState
	, new ViewInput(0, -.75)
);

$portalRoomTargetHealth = new HitPoints(20, 20);

$portalRoomTarget = $portalRoomCombatRegistry->create(
	new Transform(672, 1920, -144)
	, new Body(8, 56, true, true)
	, $portalRoomTargetHealth
	, new ActorState($portalActors->numbered(3004))
);

$portalRoomWeapon = $portalRoomCombatRegistry->create(
	new ActorState($portalActors->named('Pistol'))
	, new WeaponOwner($portalRoomShooter)
);

$portalRoomActions = new ActionDispatcher;

new CombatSystem(
	$portalRoomLevel
	, $portalActors
	, $portalCombatFactory
	, new Collision($portalRoomLevel)
	, new Controls
	, $portalRoomActions
);

mt_srand(1);

$portalRoomActions->dispatch(
	$portalRoomWeapon
	, new StateFrame('PISG', 'A', 0, false, 'A_FireBullets', [0,0,1,5,'BulletPuff',0])
	, $portalRoomCombatRegistry
);

verifyWad(
	$portalRoomTargetHealth->current === 10
	, 'player bullets damage actors below a floor portal without striking its surface'
);

$portalRoomProjectileRegistry = new Registry;
$portalRoomProjectileOwner = $portalRoomProjectileRegistry->create(new Transform(384, 384, 128));

$portalRoomProjectile = $portalRoomProjectileRegistry->create(
	new Transform(384, 384, 160, 0)
	, new Velocity(100, 0, -100)
	, new Body(2, 8, false, false, true)
	, new ActorState($portalActors->named('Rocket'))
	, new Projectile($portalRoomProjectileOwner, 20)
);

$portalRoomProjectileCombat = new CombatSystem(
	$portalRoomLevel
	, $portalActors
	, $portalCombatFactory
	, new Collision($portalRoomLevel)
	, new Controls
	, new ActionDispatcher
);

$portalRoomProjectileCombat->update($portalRoomProjectileRegistry, 1);
$portalRoomProjectilePose = $portalRoomProjectileRegistry->get($portalRoomProjectile, Transform::class);

verifyWad(
	count(
		$portalRoomProjectileRegistry->query(Projectile::class)
	) === 1 && abs(
		$portalRoomProjectilePose->x - 484
	) < .001 && abs(
		$portalRoomProjectilePose->y - 1920
	) < .001 && abs(
		$portalRoomProjectilePose->z - 60
	) < .001 && abs(
		$portalRoomProjectilePose->previousY - 1920
	) < .001 && abs(
		$portalRoomProjectilePose->previousZ - 160
	) < .001 && abs(
		$portalRoomProjectilePose->zAt(.5) - 110
	) < .001
	, 'rockets cross floor portals without a vertical interpolation discontinuity'
);

$portalRoomRemotePose = new Transform(384, 384, 160, 0);
$portalRoomRemotePose->reconcile(484, 1920, 60, 0);

$portalRoomRemotePortal = (new DoomPhonics\Game\PortalInterpolation($portalRoomLevel))->restore(
	$portalRoomRemotePose
	, 8
);

verifyWad(
	$portalRoomRemotePortal === $portalRoomDown && abs(
		$portalRoomRemotePose->previousX - 384
	) < .001 && abs(
		$portalRoomRemotePose->previousY - 1920
	) < .001 && abs(
		$portalRoomRemotePose->previousZ - 160
	) < .001 && abs(
		$portalRoomRemotePose->xAt(.5) - 434
	) < .001 && abs(
		$portalRoomRemotePose->yAt(.5) - 1920
	) < .001 && abs(
		$portalRoomRemotePose->zAt(.5) - 110
	) < .001
	, 'remote rockets rebase snapshot interpolation through the crossed floor portal'
);

$portalRoomOrdinaryPose = new Transform(300, 384, 160, 0);
$portalRoomOrdinaryPose->reconcile(340, 384, 160, 0);

verifyWad(
	(new DoomPhonics\Game\PortalInterpolation($portalRoomLevel))->restore($portalRoomOrdinaryPose, 8) === null
		&& $portalRoomOrdinaryPose->previousX === 300.0
	, 'ordinary remote projectile corrections retain their local interpolation endpoint'
);

verifyWad(
	!$portalRoomLevel->boundsWithinCameraPortalPlane(260, 384, 16, 4, 0) && $portalRoomLevel
		->boundsWithinCameraPortalPlane(272, 384, 16, 4, 0) && !$portalRoomLevel->boundsWithinCameraPortalPlane(
			260
			, 1920
			, 16
			, 14
			, 1
		) && $portalRoomLevel->boundsWithinCameraPortalPlane(272, 1920, 16, 14, 1)
	, 'plane portal traversal waits until the complete square actor footprint is inside the opening'
);

$portalRoomRimPose = new Transform(260, 384, 128);
$portalRoomRimBody = new Body(16, 56, true);
$portalRoomRimVelocity = new Velocity;
(new VerticalPhysics($portalRoomLevel))->step($portalRoomRimPose, $portalRoomRimVelocity, $portalRoomRimBody, 1 / 35);

verifyWad(
	$portalRoomRimPose->z === 128.0 && $portalRoomRimBody->grounded && $portalRoomRimVelocity->z === 0.0
	, 'the surrounding floor supports a player whose footprint still overlaps the portal rim'
);

$portalRoomPose = new Transform(384, 384, 71.25);
$portalRoomBody = new Body(16, 56, true);
$portalRoomVelocity = new Velocity(0, 0, -10);
$portalRoomGravity = new VerticalPhysics($portalRoomLevel);
$portalRoomGravity->step($portalRoomPose, $portalRoomVelocity, $portalRoomBody, .01, 0, PlayerState::VIEW_HEIGHT);

verifyWad(
	abs($portalRoomPose->z - 71.15) < .001 && abs($portalRoomPose->y - 384) < .001
	, 'a downward plane portal does not warp while the player eye remains above the opening'
);

$portalRoomGravity->step($portalRoomPose, $portalRoomVelocity, $portalRoomBody, .02, 0, PlayerState::VIEW_HEIGHT);

verifyWad(
	abs($portalRoomPose->x - 384) < .01 && abs($portalRoomPose->y - 1920) < .01 && abs(
		$portalRoomPose->z - 70.95
	) < .001 && abs($portalRoomPose->previousZ - 70.98) < .001 && $portalRoomVelocity->z === -10.0
		&& !$portalRoomBody->grounded
	, 'linked floor portals preserve eye depth and begin interpolation below the remote ceiling'
);

$portalRoomVelocity->z = 100;
$portalRoomGravity->step($portalRoomPose, $portalRoomVelocity, $portalRoomBody, .03, 0, PlayerState::VIEW_HEIGHT);

verifyWad(
	abs($portalRoomPose->x - 384) < .01 && abs($portalRoomPose->y - 384) < .01 && $portalRoomPose->z > 71 && abs(
		$portalRoomPose->previousZ - 71.02
	) < .001 && $portalRoomVelocity->z === 100.0
	, 'linked ceiling portals preserve eye depth and begin interpolation above the remote floor'
);

$portalRoomShallowPose = new Transform(384, 384, 71.0001);
$portalRoomShallowBody = new Body(16, 56, false);
$portalRoomShallowVelocity = new Velocity(0, 0, -.01);

$portalRoomGravity->step(
	$portalRoomShallowPose
	, $portalRoomShallowVelocity
	, $portalRoomShallowBody
	, 1 / 35
	, 0
	, PlayerState::VIEW_HEIGHT
);

verifyWad(
	abs($portalRoomShallowPose->y - 1920) < .01 && abs($portalRoomShallowPose->previousZ - 70.98) < .001 && abs(
		$portalRoomShallowPose->z - 70.98
	) < .001
	, 'sub-pixel floor portal crossings inset both render history and position beyond the portal plane'
);

$portalRoomUpper = array_keys($portalRoomMap->connectedSectors(0));
sort($portalRoomUpper);
$portalRoomLower = array_keys($portalRoomMap->connectedSectors(10));
sort($portalRoomLower);

verifyWad(
	$portalRoomUpper === range(0, 9) && $portalRoomLower === range(10, 19)
	, 'ordinary geometry connects each room to its hallway without joining portal spaces'
);

$portalRoomAtlas = IndexedAtlas::forLevel($portalRoomLevel, new TextureCatalog($stack));
$portalRoomMesh = (new MapMeshBuilder($portalRoomLevel, $portalRoomAtlas))->build();

verifyWad(
	$portalRoomMesh->cameraPortals->vertexCount() === 24
	, 'second portal demo builds two line quads and two one-cell plane surfaces'
);

$portalRoomProjection = new DoomPhonics\Render\ViewProjection(640, 360, 90);

$portalRoomSeamViews = $portalRoomMesh->cameraPortals->visibleLinks(
	$portalRoomLevel
	, 1279.98
	, 384
	, 128
	, 0
	, 8
	, 90
	, 9
	, $portalRoomProjection
);

verifyWad(
	array_map(static fn($link): int => $link->id, $portalRoomSeamViews) === [0]
	, 'a reverse hallway crossing keeps its portal visible inside the reduced portal near plane'
);

$portalRoomRootViews = $portalRoomMesh->cameraPortals->visibleLinks(
	$portalRoomLevel
	, 1250
	, 384
	, 128
	, 0
	, 8
	, 90
	, 9
	, $portalRoomProjection
	, .3
);

$portalRoomLineCamera = $portalRoomRootViews[0]->camera(1250, 384, 128, 0, $portalRoomLevel, PlayerState::VIEW_HEIGHT);

$portalRoomCeilingViews = array_values(
	array_filter(
		$portalRoomMesh->cameraPortals->visibleLinks(
			$portalRoomLevel
			, $portalRoomLineCamera['x']
			, $portalRoomLineCamera['y']
			, $portalRoomLineCamera['z']
			, $portalRoomLineCamera['angle']
			, 8
			, 90
			, $portalRoomRootViews[0]->destinationSector
			, $portalRoomProjection
			, .3
		)
		, static fn($link): bool => $link->id !== $portalRoomRootViews[0]->reciprocalId
	)
);

$portalRoomCeilingCamera = $portalRoomCeilingViews[0]->camera(
	$portalRoomLineCamera['x']
	, $portalRoomLineCamera['y']
	, $portalRoomLineCamera['z']
	, $portalRoomLineCamera['angle']
	, $portalRoomLevel
	, PlayerState::VIEW_HEIGHT
);

$portalRoomRepeatedViews = array_values(
	array_filter(
		$portalRoomMesh->cameraPortals->visibleLinks(
			$portalRoomLevel
			, $portalRoomCeilingCamera['x']
			, $portalRoomCeilingCamera['y']
			, $portalRoomCeilingCamera['z']
			, $portalRoomCeilingCamera['angle']
			, 8
			, 90
			, $portalRoomCeilingViews[0]->destinationSector
			, $portalRoomProjection
			, .3
		)
		, static fn($link): bool => $link->id !== $portalRoomCeilingViews[0]->reciprocalId
	)
);

verifyWad(
	array_map(static fn($link): int => $link->id, $portalRoomRootViews) === [0]
	&& array_map(static fn($link): int => $link->id, $portalRoomCeilingViews) === [3]
	&& array_map(static fn($link): int => $link->id, $portalRoomRepeatedViews) === [0]
	, 'hallway-to-ceiling portal views can see the hallway again as a bounded recursive view'
);

$portalRoomRegistry = new Registry;

$portalRoomPopulation = $portalDemoFactory->populate(
	$portalRoomRegistry
	, $portalRoomMap
	, 2
	, false
	, null
	, null
	, $portalRoomLevel
);

verifyWad(
	$portalRoomPopulation['missing'] === [] && count($portalRoomPopulation['entities']) === 5
	, 'second portal demo spawns its player and decorations while ignoring stack markers'
);

$portalObstacleRegistry = new Registry;
$portalMoverPose = new Transform(240, 128, 0);
$portalMoverBody = new Body(16, 56, true);
$portalMover = $portalObstacleRegistry->create($portalMoverPose, $portalMoverBody);
$foreignObstacle = $portalObstacleRegistry->create(new Transform(560, 128, 0), new Body(16, 56, true));
$portalObstacles = new ActorObstacles($portalObstacleRegistry, $portalDemoLevel);

verifyWad(
	$portalObstacles->sweep($portalMover, $portalMoverPose, $portalMoverBody, 400, 0) === null
	, 'actors in disconnected portal spaces do not create premature collision'
);

$portalMoverPose->x = 513;

verifyWad(
	$portalObstacles->sweep($portalMover, $portalMoverPose, $portalMoverBody, 40, 0)?->entity === $foreignObstacle
	, 'actors block movement after the mover enters their portal space'
);

$portalDemoAtlas = IndexedAtlas::forLevel($portalDemoLevel, new TextureCatalog($stack));
$portalDemoMesh = (new MapMeshBuilder($portalDemoLevel, $portalDemoAtlas))->build();

verifyWad(
	$portalDemoMesh->cameraPortals->vertexCount() === 84
	, 'generated portal demo builds every line and plane portal surface'
);

$portalDemoVisible = $portalDemoMesh->cameraPortals->visibleLinks($portalDemoLevel, 64, 128, 0, 0, 8, 90);
$portalDemoCamera = $portalDemoVisible[0]->camera(64, 128, 0, 0, $portalDemoLevel);

$portalDemoChildren = $portalDemoMesh->cameraPortals->visibleLinks(
	$portalDemoLevel
	, $portalDemoCamera['x']
	, $portalDemoCamera['y']
	, $portalDemoCamera['z']
	, $portalDemoCamera['angle']
	, 8
	, 90
	, $portalDemoVisible[0]->destinationSector
);

verifyWad(
	array_map(static fn($link): int => $link->id, $portalDemoVisible) === [0] && array_map(
		static fn($link): int => $link->id
		, $portalDemoChildren
	) === [2]
	, 'portal view selection follows the logical destination geometry instead of disconnected map coordinates'
);

$portalDemoRoomTraversal = array_map(
	static fn(int $x): array => array_map(
		static fn($link): int => $link->id
		, $portalDemoMesh->cameraPortals->visibleLinks($portalDemoLevel, $x, 752, 128, 0, 8, 90)
	)
	, [64,128,192,224]
);

verifyWad(
	count(array_unique(array_map('serialize', $portalDemoRoomTraversal))) === 1 && $portalDemoRoomTraversal[0] === [6]
	, 'portal capture order stays stable while crossing a room with line and sector portals'
);

$portalProjection = new DoomPhonics\Render\ViewProjection(640, 360, 90);

$portalDemoLevelView = static fn(float $pitch): array => array_map(
	static fn($link): int => $link->id
	, $portalDemoMesh->cameraPortals->visibleLinks(
		$portalDemoLevel
		, 640
		, 640
		, 128
		, 0
		, 8
		, 90
		, 4
		, $portalProjection
		, $pitch
		, DoomPhonics\Game\PitchMode::Perspective
	)
);

$portalLevelAtHorizon = $portalDemoLevelView(0);
$portalLevelLookingUp = $portalDemoLevelView(1.0);

verifyWad(
	$portalLevelAtHorizon === [8,12] && $portalLevelLookingUp === [8]
	, 'pitched portal culling does not capture a floor portal below the displayed view'
);

verifyWad(
	array_keys($portalDemoMap->connectedSectors(0)) === [0] && array_keys($portalDemoMap->connectedSectors(1)) === [1]
	, 'ordinary sector components keep disconnected portal spaces separate'
);

verifyWad(
	$portalDemoMap->sectorComponent(4) === 4 && $portalDemoMap->sectorComponent(5) === 5 && $portalDemoMap
		->sectorComponent(6) === 6
	, 'portal-adjacent rooms retain stable geometry component IDs'
);

$portFactory = new ActorFactory((new WadDefinitions)->actors);
$portRegistry = new Registry;
$populated = $portFactory->populate($portRegistry, $portMap, 2, false, null, 5080, $portLevel);
$players = iterator_to_array($portRegistry->query(PlayerStart::class));

verifyWad(
	!$populated['missing'] && count($players) === 1 && count($populated['entities']) === 2
	, 'inspection spawns one team player and only the eligible single-player pickup'
);

$spawnPose = $portRegistry->get($players[0], Transform::class);

verifyWad(
	$spawnPose->x === 32.0 && $spawnPose->z === 16.0 && $portRegistry->get($players[0], SpawnPoint::class)->z === 16.0
	, 'actor and respawn origins sit on the sloped floor'
);

$coopRegistry = new Registry;
$portFactory->populate($coopRegistry, $portMap, 2, true);
$coopPickups = [];

foreach($coopRegistry->query(MapThing::class) as $entity)
if($coopRegistry->get($entity, MapThing::class)->type === 2010)
$coopPickups[] = $coopRegistry->get($entity, Transform::class)->x;
verifyWad($coopPickups === [72.0], 'cooperative mode selects cooperative things at the chosen skill');

$portPose = new Transform(16, 64, 8);
$portBody = new Body(8, 56, true);
$portVelocity = new Velocity;
$portCollision = new Collision($portLevel);
$portGravity = new VerticalPhysics($portLevel);

foreach([32,64,96,120,136,120,96,64,32,16] as $x)
{
	$portCollision->moveAndSlide($portPose, $portBody, $x - $portPose->x, 0);
	$portGravity->step($portPose, $portVelocity, $portBody, 1 / 35);

	verifyWad(
		abs($portPose->x - $x) < .01 && abs($portPose->z - min(64, $x * .5)) < .01 && $portBody->grounded
		, "movement follows the ramp and adjoining floor at x=$x"
	);
}

$tracer = new ProjectileTracer($portLevel);
$impact = $tracer->trace(new Transform(16, 64, 32), new Body(2, 8, false, false, true), 100, 0, 0);

verifyWad(
	$impact?->surface === ProjectileImpact::FLOOR && abs($impact->x - 64) < .01
	, 'horizontal projectile intersects a rising floor'
);

$impact = $tracer->trace(new Transform(112, 64, 168), new Body(2, 8, false, false, true), -100, 0, 0);

verifyWad(
	$impact?->surface === ProjectileImpact::CEILING && abs($impact->x - 96) < .01
	, 'horizontal projectile intersects a descending ceiling'
);

verifyWad(
	!$portLevel->portal(0)->admitsProjectile(60, 8, .001, 128, 64) && $portLevel->portal(0)->admitsProjectile(
		70
		, 8
		, .001
		, 128
		, 64
	)
	, 'projectile portal tests use slope height at the crossing'
);

$doorMap = PortMap::map();
$doorMap->linedefs[0]['action'] = 12;
$doorMap->linedefs[0]['flags'] = 1540;
$doorMap->linedefs[0]['args'] = [0,16,105,0,0];
$doorMap->sectors[1]['ceiling'] = 64;
$doorLevel = new Level($doorMap);
$doorRegistry = new Registry;
$hexenDoors = new LineSpecials($doorLevel, $doorRegistry);
$portPlayer = new PlayerState;

verifyWad(
	!$hexenDoors->activate(0, 'walk', 0, $portPlayer) && $hexenDoors->activate(0, 'use', 0, $portPlayer)
	, 'Hexen Door_Raise obeys use activation flags'
);

$mover = $doorRegistry->get($doorLevel->sectors[1]->ceilingMover, SectorMover::class);

verifyWad(
	$mover->speed === 70.0 && $mover->waitTics === 105 && !$hexenDoors->consumed
	, 'Hexen door speed, delay and repeat flag are honored'
);

$hexenDoors->update(1 / 35);
verifyWad($doorLevel->sectors[1]->ceiling === 66.0, 'Hexen door moves in map units per Doom tic');

$liftMap = PortMap::map();
$liftMap->linedefs[0]['action'] = 206;
$liftMap->linedefs[0]['flags'] = 1028;
$liftMap->linedefs[0]['args'] = [2,128,35,2,0];
$liftLevel = new Level($liftMap);
$liftRegistry = new Registry;
$hexenLift = new LineSpecials($liftLevel, $liftRegistry);
verifyWad($hexenLift->activate(0, 'use', 0, $portPlayer), 'Hexen lift activates tagged sectors');
$mover = $liftRegistry->get($liftLevel->sectors[1]->floorMover, SectorMover::class);

verifyWad(
	$mover->target === 2.0 && $mover->speed === 560.0 && $mover->waitTics === 35 && isset($hexenLift->consumed[0])
	, 'Hexen lift respects lip, speed, delay and one-shot flag'
);

$teleportMap = PortMap::map();
$teleportMap->linedefs[0]['action'] = 70;
$teleportMap->linedefs[0]['flags'] = 516;
$teleportMap->linedefs[0]['args'] = [2,0,0,0,0];
$teleportMap->things = [
	[
		'type' => 14
		, 'id' => 1
		, 'x' => 16
		, 'y' => 64
		, 'angle' => 0
	]
	, [
		'type' => 14
		, 'id' => 2
		, 'x' => 192
		, 'y' => 64
		, 'angle' => 90
	]
];

$hexenTeleport = new LineSpecials(new Level($teleportMap));
$teleportPose = new Transform(16, 64, 0);

verifyWad(
	!$hexenTeleport->activate(0, 'walk', 0, $portPlayer, $teleportPose, false) && !$hexenTeleport->activate(
		0
		, 'use'
		, 0
		, $portPlayer
		, $teleportPose
	)
	, 'Hexen teleport rejects reverse crossing and Doom action-70 use semantics'
);

verifyWad(
	$hexenTeleport->activate(0, 'walk', 0, $portPlayer, $teleportPose) && $teleportPose->x === 192.0 && $teleportPose
		->z === 64.0 && $teleportPose->previousX === 192.0
	, 'Hexen teleport selects TID and resets interpolation'
);

$scrollMap = PortMap::map();
$scrollMap->linedefs[0]['action'] = 101;
$scrollMap->linedefs[0]['args'] = [32,0,0,0,0];
$scrollMap->linedefs[2]['action'] = 80;
$scrollMap->linedefs[3]['action'] = 121;
$scrollLevel = new Level($scrollMap);
$scrollSpecials = new LineSpecials($scrollLevel);
$scrollSpecials->update(1 / 35);

verifyWad(
	$scrollLevel->wallScroll[0] === -.5 && $scrollLevel->unsupportedSpecials === []
	, 'Hexen texture scrolling recognizes ACS execution and line identification'
);

verifyWad(
	!$scrollSpecials->activate(2, 'walk', 0, $portPlayer)
	, 'unbound ACS execution never falls through to Doom dispatch'
);

$portAtlas = IndexedAtlas::forLevel($portLevel, new TextureCatalog($stack));
$portMesh = (new MapMeshBuilder($portLevel, $portAtlas))->build();
$linePortalAtlas = IndexedAtlas::forLevel($linePortalLevel, new TextureCatalog($stack));
$linePortalMesh = (new MapMeshBuilder($linePortalLevel, $linePortalAtlas))->build();

verifyWad(
	$linePortalMesh->cameraPortals->vertexCount() === 12 && !array_filter(
		$linePortalMesh->wallSpans
		, static fn(array $span): bool => in_array($span['line'], [1,4], true)
	)
	, 'line camera portal surfaces replace their material wall spans'
);

$sectorPortalAtlas = IndexedAtlas::forLevel($sectorPortalLevel, new TextureCatalog($stack));
$sectorPortalMesh = (new MapMeshBuilder($sectorPortalLevel, $sectorPortalAtlas))->build();

verifyWad(
	$sectorPortalMesh->cameraPortals->vertexCount() > 0 && !array_filter(
		$sectorPortalMesh->planeSpans
		, static fn(array $span): bool => $span['sector'] === 0 && $span['plane'] === 0
	)
	, 'sector camera portal surfaces replace the material plane so reciprocal captures retain an aperture'
);

verifyWad($portMesh->sync($portLevel, $portAtlas) === [], 'unchanged map material sync emits no vertex uploads');
$portLevel->setWallScroll(1, -.5);

verifyWad(
	count($portMesh->sync($portLevel, $portAtlas)) > 0 && $portMesh->sync($portLevel, $portAtlas) === []
	, 'only changed scroll offsets produce uploads'
);

unset($portLevel->wallScroll[1]);

verifyWad(
	count($portMesh->sync($portLevel, $portAtlas)) > 0 && $portMesh->sync($portLevel, $portAtlas) === []
	, 'removing a scroll offset restores original UVs once'
);
