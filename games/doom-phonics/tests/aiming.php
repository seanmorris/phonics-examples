<?php
declare(strict_types=1);

use DoomPhonics\Adapter\WadSource;
use DoomPhonics\Component\Body;
use DoomPhonics\Component\HitPoints;
use DoomPhonics\Component\MonsterBrain;
use DoomPhonics\Component\PlayerCommand;
use DoomPhonics\Component\PlayerStart;
use DoomPhonics\Component\Transform;
use DoomPhonics\Game\AutoAim;
use DoomPhonics\Game\GameSettings;
use DoomPhonics\Game\Level;
use DoomPhonics\Game\PlayerState;
use DoomPhonics\Game\ProjectileImpact;
use DoomPhonics\Game\ProjectileTracer;
use DoomPhonics\Input\DoomActions;
use DoomPhonics\System\EpisodeSystems;
use Phonics\Ecs\Registry;
use Phonics\Ecs\Scheduler;
use Phonics\FixedStep;
use Phonics\Input\Controls;
use Phonics\World\ScopeContext;

// E1M3's west doorway: the sector 115 alcove (floor 104) faces door sector 116, whose floor of 136 forms a 32-unit
// ledge, and the open door looks down into room 32 (floor 64). A player pressed against the ledge stops 16 units
// from the door line, on the 114/115 border, and should shoot over the ledge exactly as the original does.
(static function() use ($stack): void {
	$level = new Level($stack->map('E1M3'));
	$level->sectors[116]->ceiling = $level->lowestNeighborCeiling(116) - 4;
	$tracer = new ProjectileTracer($level);
	$eye = 104 + 41;
	$ray = static fn(float $x, float $slope): ?ProjectileImpact => $tracer->trace(
		new Transform($x, -1864, $eye)
		, new Body(0, 0)
		, 2048
		, 0
		, $slope * 2048
	);

	verifyWad(
		$level->sectors[116]->ceiling === 172.0 && $level->sectorAt(-1760.00001, -1864)->index === 114
		, 'E1M3 doorway fixture opens door 116 to 172 beside the 114/115 border'
	);

	// The cast does not report a crossing within its skin of the origin. Tracking the sector reached so far then
	// left the shot in 114 at the 115|116 door line, which it treated as a solid wall.
	foreach([-1760.00001, -1760.0, -1759.99] as $x)
	verifyWad($ray($x, 0)?->line === 48, "a level shot from x $x passes the open door over its ledge");

	$past = static fn(?ProjectileImpact $impact): bool => $impact !== null && $impact->x > -1736;

	// The shot clears the ledge only if it is above 136 where it leaves the door sector, 24 units out.
	verifyWad($past($ray(-1760.00001, (136.2 - $eye) / 24)), 'a shot above the far ledge edge reaches room 32');
	verifyWad(!$past($ray(-1760.00001, (135.8 - $eye) / 24)), 'a shot below the far ledge edge is stopped at the door');
	verifyWad(
		$ray(-1760.00001, (135 - $eye) / 16)?->line === 145
		, 'a shot below the ledge at the door line hits its lower wall'
	);

	$level->sectors[116]->ceiling = 136.0;
	verifyWad($ray(-1760.00001, 0)?->line === 145, 'the closed door still stops the shot');
	$level->sectors[116]->ceiling = 172.0;

	// A zombie 110 units out stands in room 32 below the ledge, with its top 25 units under the eye.
	$aim = new AutoAim($level);
	$targets = [7 => [new Transform(-1650, -1864, 64), new Body(20, 56, true, true)]];
	$found = $aim->aim(-1760.00001, -1864, $eye, 0, AutoAim::RANGE, 0, $targets);
	$ledge = (136 - $eye) / 24;
	$top = (120 - $eye) / 110;

	verifyWad(
		$found !== null && $found['target'] === 7 && $found['slope'] > $ledge && $found['slope'] < $top
		, 'autoaim narrows its window at the ledge and aims at the zombie part above it'
	);

	verifyWad($past($ray(-1760.00001, $found['slope'])), 'the autoaimed shot clears the ledge');
	verifyWad(!$past($ray(-1760.00001, (92 - $eye) / 110)), "a shot at the zombie's hidden centre hits the ledge");

	verifyWad(
		$aim->aim(-1760.00001, -1864, $eye, 0, AutoAim::RANGE, 0, [7 => [new Transform(-1712, -1864, 64), $targets[7][1]]])
			=== null
		, 'a zombie wholly below the ledge cannot be aimed at'
	);

	verifyWad(
		$aim->aim(-1760.00001, -1864, $eye, 0, AutoAim::RANGE, .6, $targets) === null
		, 'the autoaim window follows the freelook pitch'
	);

	$level->sectors[116]->ceiling = 136.0;
	verifyWad($aim->aim(-1760.00001, -1864, $eye, 0, AutoAim::RANGE, 0, $targets) === null, 'a closed door ends autoaim');
})();

// The same doorway through the installed systems: one pistol shot at a level pitch over the zombie's head. Only the
// shooter's own Vertical Autoaim option, which its PlayerCommand carries, turns it down onto the zombie.
(static function(): void {
	$shoot = static function(bool $autoAim): array {
		$settings = new GameSettings;
		$settings->setAutoAim($autoAim);
		$source = new WadSource(new DoomPhonics\Archive\Stack(new DoomPhonics\Archive\Archive(
			file_get_contents(dirname(__DIR__) . '/demo/DOOM1.WAD')
			, 'DOOM1.WAD'
		)));

		$registry = new Registry;
		$controls = new Controls;
		$fixed = new FixedStep(1 / 35);
		$descriptor = null;

		foreach($source->catalog() as $candidate) if($candidate->id === 'E1M3') $descriptor = $candidate;

		$scope = new ScopeContext(
			'episode'
			, $descriptor
			, $registry
			, new Scheduler($registry)
			, services: ['controls' => $controls, 'doom-settings' => $settings]
		);

		$scope->setContent($source->activate('E1M3', $scope)->content());
		(new EpisodeSystems)->activate($scope);
		$level = $scope->content()->level;
		$level->sectors[116]->ceiling = 172.0;
		$zombie = null;

		foreach($registry->query(MonsterBrain::class) as $entity)
		{
			$pose = $registry->get($entity, Transform::class);

			if($zombie === null && $level->sectorAt($pose->x, $pose->y)->index === 32)
			{
				$zombie = $entity;
				$registry->remove($entity, MonsterBrain::class);
				[$pose->x, $pose->y, $pose->z] = [-1650.0, -1864.0, 64.0];
				$pose->beginStep();
			}
			else $registry->destroy($entity);
		}

		foreach($registry->query(PlayerStart::class) as $player)
		{
			$pose = $registry->get($player, Transform::class);
			[$pose->x, $pose->y, $pose->z, $pose->angle] = [-1760.00001, -1864.0, 104.0, 0.0];
			$pose->beginStep();
			$registry->get($player, PlayerState::class)->godMode = true;
		}

		$tick = static function(int $count) use ($fixed, $scope): void {
			for(; $count > 0; $count--) $fixed->advance(1 / 35, [$scope->scheduler, 'update']);
		};

		$tick(20);
		$health = $registry->get($zombie, HitPoints::class)->current;
		$controls->set([DoomActions::FIRE => 1]);
		$tick(1);
		$controls->set([]);
		$tick(12);

		return [
			$health
			, $registry->alive($zombie) ? $registry->get($zombie, HitPoints::class)->current : 0
			, $registry->get($player, PlayerCommand::class)->autoAim
		];
	};

	[$before, $after, $carried] = $shoot(false);
	verifyWad(!$carried && $after === $before, 'without autoaim a level shot passes over the zombie below the ledge');

	[$before, $after, $carried] = $shoot(true);
	verifyWad($carried && $after < $before, 'with Vertical Autoaim the same shot is aimed over the ledge into the zombie');
})();
