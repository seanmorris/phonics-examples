<?php
declare(strict_types=1);
namespace DoomPhonics\System;

use DoomPhonics\Adapter\ActorFactory;
use DoomPhonics\Adapter\WadLevel;
use DoomPhonics\Component\BeamEffect;
use DoomPhonics\Component\MapThing;
use DoomPhonics\Component\PlayerStart;
use DoomPhonics\Component\Projectile;
use DoomPhonics\Component\Transform;
use DoomPhonics\Component\ViewInput;
use DoomPhonics\Component\PlayerCommand;
use DoomPhonics\Component\WeaponOverlay;
use DoomPhonics\Component\WeaponOwner;
use DoomPhonics\Definition\ActionDispatcher;
use DoomPhonics\Game\Collision;
use DoomPhonics\Game\ActorObstacles;
use DoomPhonics\Game\BloodEffects;
use DoomPhonics\Game\DoomGravity;
use DoomPhonics\Game\DoomMatch;
use DoomPhonics\Game\GameSettings;
use DoomPhonics\Game\LineSpecials;
use DoomPhonics\Game\PlayerState;
use DoomPhonics\Game\SkillLevel;
use DoomPhonics\Menu\MenuOverlay;
use DoomPhonics\Render\DoomRenderPass;
use DoomPhonics\Render\GlesMapRenderer;
use DoomPhonics\Render\ScreenWipe;
use Phonics\Ecs\Registry;
use Phonics\World\ScopeContext;
use Phonics\World\SystemSet;

/** Installs the E1 DECORATE ECS, collision, specials, and direct GLES pass for a WAD map. */
final class EpisodeSystems implements SystemSet
{

	public function activate(ScopeContext $scope, array $options = []): void
	{
		$content = $scope->content();

		if(!$content instanceof WadLevel)
		throw new \LogicException('Episode systems require a WAD map scope');

		$actors = $content->actors;
		$factory = new ActorFactory($actors);
		$interpolated = [];

		$owned = static function(Registry $registry, int $entity, object $component) use ($scope, &$interpolated):
		void {
			if($component instanceof MapThing) $interpolated[$entity] = true;

			if($component instanceof MapThing || $component instanceof Projectile
				|| $component instanceof WeaponOwner || $component instanceof WeaponOverlay
				|| $component instanceof BeamEffect
			)
			$scope->own($entity);
		};

		$scope->registry->on('added', $owned);
		$scope->onClose(fn() => $scope->registry->off('added', $owned));

		$settings = $scope->service('doom-settings');

		if($settings !== null && !$settings instanceof GameSettings)
		throw new \LogicException('doom-settings service must be a GameSettings value');

		$skill = $settings?->skill() ?? SkillLevel::fromIndex((int)($options['skill'] ?? 2));
		$match = $scope->service('doom-match');

		if($match !== null && !$match instanceof DoomMatch)
		throw new \LogicException('doom-match service must be a DoomMatch value');

		$host = $match?->network->host() ?? true;
		$cheats = $scope->service('doom-cheats');

		if($cheats !== null && !$cheats instanceof CheatSystem)
		throw new \LogicException('doom-cheats service must be a CheatSystem value');

		$result = $factory->populate(
			$scope->registry
			, $content->level->map
			, $skill->actorTier()
			, (bool)($options['multiplayer'] ?? false)
			, $match
			, isset($options['playerStartType']) ? (int)$options['playerStartType'] : null
			, $content->level
			, $settings?->multiplayerMode()
		);

		if($result['missing'] && empty($options['inspectUnsupportedThings']))
		throw new \UnexpectedValueException('Unresolved WAD thing types: ' . implode(', ', $result['missing']));

		$content->skippedThings = $result['missing'];

		if(!array_filter(
			$result['entities']
			, fn(int $entity): bool => $scope->registry->has($entity, PlayerState::class)
		))
		throw new \UnexpectedValueException(
			"Map {$content->level->map->name} has no playable player start or gameplay binding. "
			. 'For an arena with multiplayer starts, choose Deathmatch or Co-op in the launcher.'
		);

		foreach($result['entities'] as $entity) $interpolated[$entity] = true;

		foreach($scope->registry->query(PlayerStart::class) as $entity)
		{
			if(!$scope->registry->has($entity, ViewInput::class)) $scope->registry->add($entity, new ViewInput);

			if(!$scope->registry->has($entity, PlayerCommand::class)) $scope->registry->add($entity, new PlayerCommand);
		}

		$motion = is_array($options['movement'] ?? null) ? $options['movement'] : [];

		$collision = new Collision(
			$content->level
			, (float)($motion['maxStep'] ?? 24)
			, new ActorObstacles($scope->registry, $content->level)
		);

		$sound = $scope->service('doom-sound', new \DoomPhonics\Audio\NullSoundEmitter);

		if(!$sound instanceof \DoomPhonics\Audio\SoundEmitter)
		throw new \LogicException('doom-sound service must implement SoundEmitter');

		$sound->beginScope($content->wad);
		$scope->onClose([$sound,'stopScope']);

		$specials = new LineSpecials(
			$content->level
			, $scope->registry
			, [$scope,'own']
			, $sound
			, $factory
			, $content->language
		);

		$actions = new ActionDispatcher;

		$combat = new CombatSystem(
			$content->level
			, $actors
			, $factory
			, $collision
			, $this->controls($scope)
			, $actions
			, $specials
			, $sound
			, $content->sounds
			, (float)($motion['gravity'] ?? DoomGravity::ACCELERATION)
			, null
			, $content->weapons
			, $host
			, $host
			, $cheats
			, new BloodEffects($content->level, $actors, $factory, $content->textures->iwadBloodSprite(), $settings)
		);

		$movement = new PlayerMovementSystem(
			$collision
			, $specials
			, $this->controls($scope)
			, (float)($motion['maxSpeed'] ?? 420)
			, (float)($motion['acceleration'] ?? 1531.25)
			, (float)($motion['friction'] ?? .90625)
			, (float)($motion['gravity'] ?? DoomGravity::ACCELERATION)
			, $host
		);

		$pickups = new PickupSystem($actors, $host, $content->language);
		$states = new ActorStateSystem($actions);
		$beams = new BeamEffectSystem;
		$physics = new ActorPhysicsSystem($collision, (float)($motion['gravity'] ?? DoomGravity::ACCELERATION));
		$effects = new SectorEffectSystem($content->level, $specials, $content->language);
		$playerEffects = new PlayerEffectSystem;
		$statusFaces = new StatusFaceSystem($this->controls($scope));
		$commands = new PlayerCommandSystem($this->controls($scope));
		$network = $match
			? new EpisodeNetworkSystem($scope->registry, $match, $scope->key(), $actors, null, $content->level)
			: null;

		if($commands) $scope->system('player-commands', [$commands,'update']);

		if($network)
		{
			$scope->system('network-receive', [$network,'receive'], ['player-commands']);
			$scope->onClose([$network,'close']);
		}

		$inputDependencies = $network ? ['network-receive'] : ($commands ? ['player-commands'] : []);
		$scope->system('player-effects', [$playerEffects,'update'], $inputDependencies);

		$scope->system(
			'transform-history'
			, static function(Registry $registry, float $dt) use (&$interpolated): void {
				foreach(array_keys($interpolated) as $entity)
				{
					if(!$registry->alive($entity) || !$registry->has($entity, Transform::class))
					{
						unset($interpolated[$entity]);
						continue;
					}

					$registry->get($entity, Transform::class)->beginStep();
				}
			}
			, ['player-effects']
		);

		$scope->system(
			'geometry'
			, static fn(Registry $registry, float $dt) => $specials->update($dt)
			, ['transform-history']
		);

		$scope->system('movement', [$movement,'update'], ['geometry']);
		$scope->system('combat', [$combat,'update'], ['movement']);
		$scope->system('pickups', [$pickups,'update'], ['combat']);
		$scope->system('actor-states', [$states,'update'], ['combat']);
		$scope->system('beam-effects', [$beams,'update'], ['actor-states']);
		$scope->system('actor-physics', [$physics,'update'], ['actor-states']);
		$scope->system('sector-effects', [$effects,'update'], ['movement','actor-physics']);
		$scope->system('status-faces', [$statusFaces,'update'], ['pickups','sector-effects']);

		if($network) $scope->system('network-send', [$network,'send'], ['status-faces','beam-effects']);

		if($scope->pipeline)
		{
			$menu = $scope->service('doom-menu');

			if($menu !== null && !$menu instanceof MenuOverlay)
			throw new \LogicException('doom-menu service must implement MenuOverlay');

			$wipe = $scope->service('doom-wipe');

			if($wipe !== null && !$wipe instanceof ScreenWipe)
			throw new \LogicException('doom-wipe service must be a ScreenWipe value');

			$renderer = new GlesMapRenderer(
				$content->level
				, $content->textures
				, $content->palette
				, $scope->pipeline->device->width
				, $scope->pipeline->device->height
				, (float)($options['fov'] ?? 90)
				, $actors
				, (float)($options['renderScale'] ?? .5)
			);

			$content->renderer = $renderer;
			$scope->onClose(static function() use ($content): void { $content->renderer = null; });

			$scope->pass(
				new DoomRenderPass($scope->key() . '/world', $scope->registry, $renderer, $menu, $wipe, $settings)
			);
		}
	}

	private function controls(ScopeContext $scope): \Phonics\Input\Controls
	{
		$controls = $scope->service('controls');

		if(!$controls instanceof \Phonics\Input\Controls)
		throw new \LogicException('Doom-Phonics game did not provide input controls');

		return $controls;
	}
}
