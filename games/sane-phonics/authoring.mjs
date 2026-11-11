// Editor schemas describe Sane's existing gameplay loaders. Entity presets compose typed settings.

const field = (name, type, value) => ({name, type, value});

const settings = (name, fields) => ({name: `SanePhonics.${name}`, members: fields});

const f = (name, value) => field(name, 'float', value);

const i = (name, value) => field(name, 'int', value);

const b = (name, value) => field(name, 'bool', value);

const s = (name, value) => field(name, 'string', value);

const ref = name => field(name, 'object', 0);
const plane = i('collisionLayer', -1); // -1 retains the loader's inherited/default collision plane.
const platform = [b('oneWay', true), f('settle', 0), plane, b('outline', true)];
const bridge = [f('droop', 32), f('segmentWidth', 16), f('response', 14), i('maxLoads', 4), plane];

const vehicle = height => [f('seatHeight', height)
	, f('seatForward', 0)
	, f('seatAngle', 0)
	, b('lockedIn', false)
	, b('quickDrop', false)
	, i('direction', 1)
	, plane
];

const edge = [f('length', 0), f('normalX', 0), f('normalY', 0), plane, i('spriteGid', 4)];
export const components = [
	settings('Ring', [f('radius', 12), i('value', 1), i('points', 0)])
	, settings('Monitor', [i('points', 10), plane])
	, settings('RingMonitorEffect', [i('amount', 10)])
	, settings('ShieldMonitorEffect', [f('starDuration', 26)])
	, settings(
		'CrabMeat'
		, [i('direction', 1)
			, f('patrolBeat', 90)
			, f('patrolPause', 25)
			, f('patrolSpeed', .25)
			, f('patrolOffset', 0)
			, f('shotTelegraph', 65)
			, f('shotDelay', 3)
			, f('projectileSpeed', 1.5)
			, f('projectileLift', 3)
			, f('projectileGravity', .15)
			, f('projectileLifetime', 200)
			, i('points', 100)
			, plane
		]
	)
	, settings(
		'MiniBoss'
		, [i('hitPoints', 8)
			, i('points', 1000)
			, f('stalkSpeed', 4.5)
			, f('bombInterval', 60)
			, f('hitCooldown', 45)
			, f('recovery', 20)
			, f('deathDelay', 6)
			, f('explosionDuration', 90)
			, f('explosionInterval', 3)
			, f('escapeSpeed', 14)
			, f('escapeDuration', 60)
			, f('gravity', .2)
			, f('bombGravity', .25)
			, f('bombLifetime', 240)
			, plane
		]
	)
	, settings('BossRegion', [ref('boss'), f('cameraAnchorY', .72)])
	, settings('MovingPlatform', platform)
	, settings(
		'PlatformOscillation'
		, [f('oscillateX', 0)
			, f('oscillateY', 0)
			, f('squinePeriod', Math.PI * 1.5)
			, f('timeX', 90)
			, f('timeY', 90)
			, f('offset', 0)
		]
	)
	, settings('PlatformPath', [ref('path'), ref('ridePath'), f('pathSpeed', 8)])
	, settings('PathNode', [ref('next')])
	, settings('BendableBridge', bridge)
	, settings(
		'BuoyantPlatform'
		, [ref('water')
			, f('density', .5)
			, b('hLock', true)
			, b('oneWay', false)
			, f('spring', 36)
			, f('damping', 12)
			, f('maxSpeed', 720)
			, plane
		]
	)
	, settings(
		'WaterRegion'
		, [f('gravity', .4)
			, f('drag', .85)
			, f('density', 1)
			, f('skimSpeed', 900)
			, f('currentX', 0)
			, f('currentY', 0)
			, field('color', 'color', '#4d147ab8')
			, f('displacement', 4)
			, f('displacementScale', 64)
			, f('displacementSpeed', 42.6666667)
		]
	)
	, settings('WaterSwitch', [ref('water'), ref('setPoint'), f('fillSpeed', 240), f('drainSpeed', 480), plane])
	, settings('GrapplePoint', [ref('tiedTo'), f('ropeLength', 64), b('noSwing', false), plane])
	, settings('RopeAnchor', [f('ropeLength', 64)])
	, settings('Spring', [...edge, f('power', 960)])
	, settings('GravitySwitch', [...edge, s('gravity', 'down')])
	, settings('Skateboard', vehicle(12))
	, settings('Tornado', vehicle(14))
	, settings('EggWalker', vehicle(48))
	, settings('VehicleRegion', [b('restricted', false), plane])
	, settings('Terrain', [b('solid', true), b('oneWay', false), plane])
	, settings('LayerSwitch', [i('fromLayer', 1), i('toLayer', 2), b('back', false), b('roll', false)])
	, settings('BreakableBlock', [i('columns', 4), i('rows', 3)])
	, settings('TextActor', [s('content', ''), field('color', 'color', '#ffffffff'), f('scale', 1), s('align', 'left')])
];

const member = (name, value = {}) => ({name, type: 'class', propertyType: `SanePhonics.${name}`, value});
export const presets = {
	ring: [member('Ring')]
	, CrabMeat: [member('CrabMeat')]
	, MiniBoss: [member('MiniBoss')]
	, BossRegion: [member('BossRegion')]
	, RingMonitor: [member('Monitor'), member('RingMonitorEffect')]
	, TripleRingMonitor: [member('Monitor'), member('RingMonitorEffect', {amount: 30})]
	, ...Object.fromEntries(
		['Normal', 'Electric', 'Fire', 'Water', 'Star'].map(
			kind => [`${kind}ShieldMonitor`, [member('Monitor'), member('ShieldMonitorEffect')]]
		)
	)
	, FloatingPlatform: [member('MovingPlatform')]
	, MovingPlatform: [member('MovingPlatform'), member('PlatformOscillation')]
	, PathPlatform: [member('MovingPlatform'), member('PlatformPath')]
	, PathNode: [member('PathNode')]
	, BendableBridge: [member('BendableBridge')]
	, BendableRail: [member('BendableBridge')]
	, Block: [member('BuoyantPlatform')]
	, WaterRegion: [member('WaterRegion')]
	, Switch: [member('WaterSwitch')]
	, GrapplePoint: [member('GrapplePoint')]
	, RopeAnchor: [member('RopeAnchor')]
	, Spring: [member('Spring')]
	, GravitySwitch: [member('GravitySwitch')]
	, Skateboard: [member('Skateboard')]
	, Tornado: [member('Tornado')]
	, EggWalker: [member('EggWalker')]
	, VehicleRegion: [member('VehicleRegion')]
	, DropVehicleRegion: [member('VehicleRegion')]
	, CurvedCeiling: [member('Terrain')]
	, Grinding: [member('Terrain', {solid: false, oneWay: true})]
	, 'layer-switch': [member('LayerSwitch')]
	, breakable: [member('BreakableBlock')]
	, TextActor: [member('TextActor')]
};
export const classes = [
	{name: 'Player', members: []}, {name: 'SanePhonics.SpawnPoint', members: []}
	, {name: 'SanePhonics.Player', members: [s('profile', 'runner')]}
	, ...components
	, ...Object.entries(presets).map(([name, members]) => ({name: `SanePhonics.Entity.${name}`, members}))
];

export const templateName = name => name.replace(/([a-z])([A-Z])/g, '$1-$2').toLowerCase();
export const templates = ['templates/player.tx'
	, 'templates/spawn.tx'
	, ...Object.keys(presets).map(name => `templates/${templateName(name)}.tj`)
];
