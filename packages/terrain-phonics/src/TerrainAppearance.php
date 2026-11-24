<?php
declare(strict_types=1);
namespace TerrainPhonics;

use Phonics\Render\Program;

/**
 * Colors, rock banding, material blending and the mountain material for the terrain shader.
 *
 * Settings are flat so that an application can store them beside its own. Colors are sRGB hex strings. Heights are
 * in meters. A slope is 1 minus the upward component of the surface normal: 0 on level ground, 1 on a vertical face.
 */
final class TerrainAppearance
{
	/** Coast and canyon terrain as it looked before these settings existed, and the mountain material as tuned. */
	public const DEFAULTS = [
		'grassColor' => '#55693a'
		, 'soilColor' => '#815f40'
		, 'rockColor' => '#867c6f'
		, 'rockBandColor' => '#af957c'
		, 'sandColor' => '#bfaf8b'
		, 'snowColor' => '#eaf1f8'
		, 'roadColor' => '#373b3d'
		, 'canyonRustColor' => '#8a4f35'
		, 'canyonShaleColor' => '#6b5546'
		, 'canyonSandstoneColor' => '#b07a4f'
		, 'canyonCapColor' => '#c2aa86'
		, 'forestColor' => '#1d291b'
		, 'meadowColor' => '#48581f'
		, 'tundraColor' => '#484230'
		, 'graniteColor' => '#565a5c'
		, 'stainColor' => '#74605a'
		, 'talusColor' => '#7b7b77'
		, 'bandSpacing' => 2 * M_PI / .12
		, 'bandContrast' => 1.0
		, 'bandSharpness' => 1.0
		, 'rockSlopeStart' => .08
		, 'rockSlopeEnd' => .38
		, 'beachHeight' => 3.0
		, 'beachWidth' => 15.0
		, 'snowline' => 340.0
		, 'snowTransition' => 65.0
		, 'alpineStart' => -110.0
		, 'alpineEnd' => -30.0
		, 'snowSlopeStart' => .25
		, 'snowSlopeEnd' => .65
		, 'macroVariation' => 1.0
		, 'treeline' => -150.0
		, 'tundraStart' => -90.0
		, 'talusAmount' => .5
		, 'stainAmount' => .3
		, 'lichenAmount' => .5
	];
	/** Starting colors and material thresholds for the named landscapes. */
	public const PRESET_DEFAULTS = [
		// Stronger broad variation gives open pasture drier and lusher patches without another noise pattern.
		'coast' => ['macroVariation' => 2.6]
		, 'mountain' => [
			'forestColor' => '#22301f'
			, 'meadowColor' => '#5a6943'
			, 'tundraColor' => '#5d5a47'
			, 'grassColor' => '#53663e'
			, 'graniteColor' => '#616b76'
			, 'talusColor' => '#80847f'
			, 'stainColor' => '#70615b'
			, 'snowColor' => '#eaf1f8'
			, 'snowline' => 460.
			, 'snowTransition' => 80.
			, 'beachHeight' => 1.2
			, 'beachWidth' => 4.
		]
		, 'limestone' => [
			'grassColor' => '#566b40'
			, 'rockColor' => '#8a9193'
			, 'rockBandColor' => '#a3a8a5'
			, 'sandColor' => '#a69e87'
			, 'snowline' => 3000.
			, 'rockSlopeStart' => .25
			, 'rockSlopeEnd' => .73
		]
		, 'basalt-fjords' => [
			'grassColor' => '#57603f'
			, 'soilColor' => '#4a463c'
			, 'rockColor' => '#283039'
			, 'rockBandColor' => '#454d54'
			, 'sandColor' => '#424951'
			, 'snowColor' => '#dce6e8'
			, 'snowline' => 820.
			, 'snowTransition' => 140.
			, 'rockSlopeStart' => .14
			, 'rockSlopeEnd' => .32
			, 'beachHeight' => 1.2
			, 'beachWidth' => 5.
		]
		, 'arctic-meltwater' => [
			'soilColor' => '#4c5459'
			, 'rockColor' => '#404c56'
			, 'rockBandColor' => '#537f95'
			, 'sandColor' => '#60625d'
			, 'snowColor' => '#c7d2d5'
			, 'grassColor' => '#555e51'
			, 'rockSlopeStart' => .21
			, 'rockSlopeEnd' => .43
			, 'snowline' => 33.
			, 'snowTransition' => 20.
			, 'snowSlopeStart' => .075
			, 'snowSlopeEnd' => .22
		]
		, 'tidal-lagoon' => [
			'grassColor' => '#364f40'
			, 'soilColor' => '#485e53'
			, 'rockColor' => '#4e625d'
			, 'rockBandColor' => '#6c776f'
			, 'sandColor' => '#a29880'
			, 'beachHeight' => 2.2
			, 'beachWidth' => 2.4
		]
		, 'amber-dunes' => [
			'sandColor' => '#c59053'
			, 'soilColor' => '#a1663f'
			, 'rockColor' => '#765941'
			, 'rockBandColor' => '#d7c8a4'
		]
		// Red siltstone and pale caps over a red sand plain.
		, 'red-mesas' => [
			'canyonRustColor' => '#8f3f24'
			, 'canyonShaleColor' => '#6e4231'
			, 'canyonSandstoneColor' => '#b4552f'
			, 'canyonCapColor' => '#b98a63'
			, 'sandColor' => '#c27f52'
			, 'macroVariation' => 2.
			, 'bandContrast' => .45
		]
		// Heather over peat, straw grass in the hollows, rust bracken on the flanks and lichened granite.
		, 'heather-moor' => [
			'meadowColor' => '#433d33'
			, 'grassColor' => '#6f6c4a'
			, 'forestColor' => '#76533a'
			, 'soilColor' => '#3b3026'
			, 'rockColor' => '#87837a'
			, 'rockBandColor' => '#a6a58c'
			, 'sandColor' => '#6b5e48'
			, 'rockSlopeStart' => .024
			, 'rockSlopeEnd' => .036
			, 'beachHeight' => 1.6
			, 'beachWidth' => 1.8
			, 'macroVariation' => .6
		]
		, 'salt-pans' => [
			'snowColor'=>'#d1ceca', 'sandColor'=>'#b79e82', 'soilColor'=>'#867068',
			'rockColor'=>'#756462', 'rockBandColor'=>'#a18e80', 'rockSlopeStart'=>.042, 'rockSlopeEnd'=>.14,
		]
		, 'cinder-highlands' => [
			'rockColor'=>'#323438', 'rockBandColor'=>'#504b48', 'soilColor'=>'#754833',
			'sandColor'=>'#807570', 'grassColor'=>'#686b52', 'rockSlopeStart'=>.105, 'rockSlopeEnd'=>.25,
		]
		, 'rolling-prairie' => [
			'grassColor'=>'#797a43', 'meadowColor'=>'#55683e', 'sandColor'=>'#aa965e',
			'rockColor'=>'#8b8572', 'rockBandColor'=>'#a19882', 'rockSlopeStart'=>.027, 'rockSlopeEnd'=>.09,
		]
		, 'river-valley' => [
			'grassColor'=>'#647745', 'meadowColor'=>'#768050', 'soilColor'=>'#665947',
			'rockColor'=>'#87857a', 'sandColor'=>'#a5987d', 'beachHeight'=>1.524, 'beachWidth'=>2.4384,
		]
		, 'wooded-foothills' => [
			'grassColor'=>'#747d4e', 'meadowColor'=>'#576c43', 'forestColor'=>'#344b3a',
			'soilColor'=>'#655b46', 'rockColor'=>'#787a72', 'rockBandColor'=>'#63665d',
			'rockSlopeStart'=>.085, 'rockSlopeEnd'=>.20, 'snowline'=>1219.2, 'treeline'=>-121.92,
		]
		, 'high-desert-steppe' => [
			'grassColor'=>'#777b62', 'soilColor'=>'#a38465', 'sandColor'=>'#bdad8e',
			'rockColor'=>'#796c62', 'rockBandColor'=>'#9b8974', 'rockSlopeStart'=>.023, 'rockSlopeEnd'=>.08,
		]
		, 'alaska-range' => [
			'grassColor'=>'#77714e', 'meadowColor'=>'#514f3e', 'sandColor'=>'#888783',
			'talusColor'=>'#737778', 'rockColor'=>'#525b65', 'rockBandColor'=>'#83888b',
			'snowColor'=>'#d3dce0', 'snowline'=>1280.16, 'snowTransition'=>152.4,
			'rockSlopeStart'=>.11, 'rockSlopeEnd'=>.27, 'snowSlopeStart'=>.12, 'snowSlopeEnd'=>.37,
		]
		, 'chalk-headlands' => [
			'grassColor'=>'#526742', 'meadowColor'=>'#728258', 'rockColor'=>'#bfc1b8',
			'rockBandColor'=>'#818582', 'soilColor'=>'#7b837e', 'sandColor'=>'#a29988',
			'rockSlopeStart'=>.065, 'rockSlopeEnd'=>.18, 'beachHeight'=>2.4384, 'beachWidth'=>2.1336,
		]
		// Grey limestone streaked dark by rain, with forest clinging to every ledge it can hold.
		, 'karst-bay' => [
			'grassColor' => '#4f6a3b'
			, 'meadowColor' => '#3c5732'
			, 'forestColor' => '#253d25'
			, 'soilColor' => '#5f5a4a'
			, 'rockColor' => '#8a8a82'
			, 'rockBandColor' => '#43473f'
			, 'sandColor' => '#b2aa91'

			, 'rockSlopeStart' => .30
			, 'rockSlopeEnd' => .55
			, 'beachHeight' => 1.5
			, 'beachWidth' => 1.5
			, 'snowline' => 1219.2
			, 'treeline' => -600.
		]
		// Rainforest covers the floor to the foot of the walls. Dark sandstone, black algae streaks, and scrub on
		// the summits.
		, 'tepui-highlands' => [
			'grassColor' => '#6b7448'
			, 'meadowColor' => '#3f5a33'
			, 'forestColor' => '#1f3a22'
			, 'soilColor' => '#6a4a34'
			, 'rockColor' => '#5d4b43'
			, 'rockBandColor' => '#94694f'
			, 'sandColor' => '#cdbd98'

			, 'rockSlopeStart' => .20
			, 'rockSlopeEnd' => .38
			, 'beachHeight' => 1.2
			, 'beachWidth' => 2.
			, 'snowline' => 2000.
			, 'treeline' => -1150.
		]
		// Maroon and gold clays, lavender ash and cream tuff, bare on every slope above the dust.
		, 'painted-badlands' => [
			'canyonRustColor' => '#76372a'
			, 'canyonShaleColor' => '#544a52'
			, 'canyonSandstoneColor' => '#a07a42'
			, 'canyonCapColor' => '#a38b6d'
			, 'sandColor' => '#b09276'
			, 'grassColor' => '#7d7a55'

			, 'rockSlopeStart' => .04
			, 'rockSlopeEnd' => .12
			, 'bandContrast' => .65
			, 'macroVariation' => 1.
		]
		// Fern and rainforest over every slope that holds soil, mossy fluted cliffs, red earth where the steepest
		// faces slip, and black sand where the ridges meet the sea.
		, 'emerald-ridges' => [
			'grassColor' => '#2f5a26'
			, 'meadowColor' => '#244a20'
			, 'forestColor' => '#17361e'
			, 'soilColor' => '#8a4a2e'
			, 'rockColor' => '#284a24'
			, 'rockBandColor' => '#3f6a32'
			, 'sandColor' => '#3d3b38'

			, 'rockSlopeStart' => .28
			, 'rockSlopeEnd' => .48
			, 'beachHeight' => 1.5
			, 'beachWidth' => 3.
			, 'snowline' => 1500.
			, 'treeline' => -1000.
		]
		// Rust maples and gold birches with dark hemlock on the folds, harvest fields in the valleys and pale
		// sandstone where the crests break through.
		, 'autumn-folds' => [
			'grassColor' => '#8f8a4c'
			, 'meadowColor' => '#8e6420'
			, 'forestColor' => '#6a3020'
			, 'soilColor' => '#6b5038'
			, 'rockColor' => '#8c8577'
			, 'rockBandColor' => '#6e685c'
			, 'sandColor' => '#a89a7c'

			, 'rockSlopeStart' => .30
			, 'rockSlopeEnd' => .50
			, 'beachHeight' => 1.
			, 'beachWidth' => 2.
			, 'snowline' => 2500.
			, 'treeline' => -1500.
		]
		// Red dune sand with darker slip faces, pale gravel between the dunes and a wet beach where they meet the sea.
		, 'namib-coast' => [
			'sandColor' => '#c86a3a'
			, 'soilColor' => '#9e4a2a'
			, 'rockColor' => '#b5a184'
			, 'rockBandColor' => '#7d6a55'
			, 'grassColor' => '#8a8660'

			, 'beachHeight' => 2.
			, 'beachWidth' => 6.
		]
		// Black basalt scree streaked downhill, moss and yellow lyme grass on the low ground, black sand at the sea
		// and a little snow in the high gullies.
		, 'black-sand-coast' => [
			'grassColor' => '#4a5236'
			, 'meadowColor' => '#a8975a'
			, 'soilColor' => '#3a3a3c'
			, 'rockColor' => '#1e1e22'
			, 'rockBandColor' => '#3a3a3f'
			, 'sandColor' => '#262628'
			, 'snowColor' => '#e8ecee'

			, 'rockSlopeStart' => .07
			, 'rockSlopeEnd' => .18
			, 'beachHeight' => 8.
			, 'beachWidth' => 10.
			, 'snowline' => 600.
			, 'snowTransition' => 80.
			, 'snowSlopeStart' => .30
			, 'snowSlopeEnd' => .55
		]
		// Wind-scoured tan clay streaked down its flanks, pale caps and rippled sand in the corridors.
		, 'yardang-desert' => [
			'sandColor' => '#d6bf95'
			, 'rockColor' => '#b89770'
			, 'rockBandColor' => '#8a6a4c'
			, 'soilColor' => '#c4a880'
			, 'snowColor' => '#e2d6be'

			, 'rockSlopeStart' => .06
			, 'rockSlopeEnd' => .16
		]
	];
	/** Inclusive range of each numeric setting. A value outside it is clamped. */
	public const RANGES = [
		'bandSpacing' => [5.0, 1000.0], 'bandContrast' => [0.0, 1.0], 'bandSharpness' => [1.0, 8.0],
		'rockSlopeStart' => [0.0, 1.0], 'rockSlopeEnd' => [0.0, 1.0], 'beachHeight' => [-100.0, 500.0],
		'beachWidth' => [.5, 500.0], 'snowline' => [-1000.0, 5000.0], 'snowTransition' => [1.0, 1000.0],
		'alpineStart' => [-2000.0, 0.0], 'alpineEnd' => [-2000.0, 0.0], 'snowSlopeStart' => [0.0, 1.0],
		'snowSlopeEnd' => [0.0, 1.0], 'macroVariation' => [0.0, 3.0], 'treeline' => [-2000.0, 0.0],
		'tundraStart' => [-2000.0, 0.0], 'talusAmount' => [0.0, 1.0], 'stainAmount' => [0.0, 1.0],
		'lichenAmount' => [0.0, 1.0],
	];
	/**
	 * Start and end of each blend, with the smallest gap between them. An end that is too low moves up to the start
	 * plus the gap, rounded to nine decimal places. When that passes the top of the end's range, the end stays at the
	 * top and the start moves down. A difference that falls short of the gap by less than SLACK counts as the gap.
	 */
	public const ORDER = [
		['rockSlopeStart', 'rockSlopeEnd', .01], ['snowSlopeStart', 'snowSlopeEnd', .01],
		['alpineStart', 'alpineEnd', 1.0], ['treeline', 'tundraStart', 1.0],
	];
	/** Shortfall from an ORDER gap that floating-point subtraction can leave between values one gap apart. */
	private const SLACK = 1e-9;
	/** Settings in panel order, grouped by what they control. */
	public const GROUPS = [
		'colors' => [
			'grassColor', 'soilColor', 'rockColor', 'rockBandColor', 'sandColor', 'snowColor', 'roadColor',
			'canyonRustColor', 'canyonShaleColor', 'canyonSandstoneColor', 'canyonCapColor',
			'forestColor', 'meadowColor', 'tundraColor', 'graniteColor', 'stainColor', 'talusColor',
		],

		'banding' => ['bandSpacing', 'bandContrast', 'bandSharpness'],

		'blending' => [
			'rockSlopeStart', 'rockSlopeEnd', 'beachHeight', 'beachWidth', 'snowline', 'snowTransition',
			'alpineStart', 'alpineEnd', 'snowSlopeStart', 'snowSlopeEnd', 'macroVariation', 'treeline', 'tundraStart',
		],

		'rock' => ['talusAmount', 'stainAmount', 'lichenAmount'],
	];
	/**
	 * The settings each terrain style reads, in GROUPS order, so a panel can show only those. The keys are what style()
	 * returns. Colors that only the material tool's paint uses count, because painted ground shows them.
	 */
	public const USES = [
		'coast' => [
			'grassColor'
			, 'rockColor'
			, 'rockBandColor'
			, 'sandColor'
			, 'snowColor'
			, 'roadColor'
			, 'bandSpacing', 'bandContrast', 'bandSharpness'
			, 'rockSlopeStart'
			, 'rockSlopeEnd'
			, 'beachHeight'
			, 'beachWidth'
			, 'snowline'
			, 'snowTransition'
			, 'snowSlopeStart', 'snowSlopeEnd', 'macroVariation'
		]
		, 'mountain' => [
			'grassColor', 'sandColor', 'snowColor', 'roadColor'
			, 'forestColor'
			, 'meadowColor'
			, 'tundraColor'
			, 'graniteColor'
			, 'stainColor'
			, 'talusColor'
			, 'rockSlopeStart'
			, 'rockSlopeEnd'
			, 'beachHeight'
			, 'beachWidth'
			, 'snowline'
			, 'snowTransition'
			, 'alpineStart'
			, 'alpineEnd'
			, 'snowSlopeStart'
			, 'snowSlopeEnd'
			, 'macroVariation'
			, 'treeline'
			, 'tundraStart'
			, 'talusAmount', 'stainAmount', 'lichenAmount'
		]
		, 'canyon-v1' => [
			'grassColor'
			, 'soilColor'
			, 'rockColor'
			, 'rockBandColor'
			, 'sandColor'
			, 'snowColor'
			, 'roadColor'
			, 'bandSpacing', 'bandContrast', 'bandSharpness'
			, 'rockSlopeStart'
			, 'rockSlopeEnd'
			, 'beachHeight'
			, 'beachWidth'
			, 'macroVariation'
		]
		, 'basalt-fjords' => [
			'grassColor'
			, 'soilColor'
			, 'rockColor'
			, 'rockBandColor'
			, 'sandColor'
			, 'snowColor'
			, 'roadColor'
			, 'rockSlopeStart'
			, 'rockSlopeEnd'
			, 'beachHeight'
			, 'beachWidth'
			, 'snowline'
			, 'snowTransition'
			, 'snowSlopeStart', 'snowSlopeEnd', 'macroVariation'
		]
		, 'arctic-meltwater' => [
			'grassColor'
			, 'soilColor'
			, 'rockColor'
			, 'rockBandColor'
			, 'sandColor'
			, 'snowColor'
			, 'roadColor'
			, 'rockSlopeStart'
			, 'rockSlopeEnd'
			, 'snowline'
			, 'snowTransition'
			, 'snowSlopeStart'
			, 'snowSlopeEnd'
		]
		, 'tidal-lagoon' => [
			'grassColor'
			, 'soilColor'
			, 'rockColor'
			, 'rockBandColor'
			, 'sandColor'
			, 'snowColor'
			, 'roadColor'
			, 'beachHeight', 'beachWidth'
		]
		, 'amber-dunes' => [
			'grassColor'
			, 'soilColor'
			, 'rockColor'
			, 'rockBandColor'
			, 'sandColor'
			, 'snowColor'
			, 'roadColor'
			, 'macroVariation'
		]
		, 'rolling-prairie' => [
			'grassColor','rockColor','rockBandColor','sandColor','roadColor','meadowColor','rockSlopeStart','rockSlopeEnd',
		]
		, 'river-valley' => [
			'grassColor','soilColor','rockColor','sandColor','roadColor','meadowColor','beachHeight','beachWidth',
		]
		, 'wooded-foothills' => [
			'grassColor','soilColor','rockColor','rockBandColor','roadColor','forestColor','meadowColor',
			'rockSlopeStart','rockSlopeEnd','snowline','treeline',
		]
		, 'high-desert-steppe' => [
			'grassColor','soilColor','rockColor','rockBandColor','sandColor','roadColor','rockSlopeStart','rockSlopeEnd',
		]
		, 'alaska-range' => [
			'grassColor','rockColor','rockBandColor','sandColor','snowColor','roadColor','meadowColor','talusColor',
			'rockSlopeStart','rockSlopeEnd','snowline','snowTransition','snowSlopeStart','snowSlopeEnd',
		]
		, 'chalk-headlands' => [
			'grassColor','soilColor','rockColor','rockBandColor','sandColor','roadColor','meadowColor',
			'rockSlopeStart','rockSlopeEnd','beachHeight','beachWidth',
		]
		, 'karst-bay' => [
			'grassColor'
			, 'soilColor'
			, 'rockColor'
			, 'rockBandColor'
			, 'sandColor'
			, 'roadColor'
			, 'forestColor'
			, 'meadowColor'
			, 'rockSlopeStart'
			, 'rockSlopeEnd'
			, 'beachHeight'
			, 'beachWidth'
			, 'snowline'
			, 'treeline'
		]
		, 'tepui-highlands' => [
			'grassColor'
			, 'soilColor'
			, 'rockColor'
			, 'rockBandColor'
			, 'sandColor'
			, 'roadColor'
			, 'forestColor'
			, 'meadowColor'
			, 'rockSlopeStart'
			, 'rockSlopeEnd'
			, 'beachHeight'
			, 'beachWidth'
			, 'snowline'
			, 'treeline'
		]
		, 'emerald-ridges' => [
			'grassColor'
			, 'soilColor'
			, 'rockColor'
			, 'rockBandColor'
			, 'sandColor'
			, 'roadColor'
			, 'forestColor'
			, 'meadowColor'
			, 'rockSlopeStart'
			, 'rockSlopeEnd'
			, 'beachHeight'
			, 'beachWidth'
			, 'snowline'
			, 'treeline'
		]
		, 'autumn-folds' => [
			'grassColor'
			, 'soilColor'
			, 'rockColor'
			, 'rockBandColor'
			, 'sandColor'
			, 'roadColor'
			, 'forestColor'
			, 'meadowColor'
			, 'rockSlopeStart'
			, 'rockSlopeEnd'
			, 'beachHeight'
			, 'beachWidth'
			, 'snowline'
			, 'treeline'
		]
		, 'namib-coast' => [
			'grassColor'
			, 'soilColor'
			, 'rockColor'
			, 'rockBandColor'
			, 'sandColor'
			, 'roadColor'
			, 'beachHeight'
			, 'beachWidth'
		]
		, 'black-sand-coast' => [
			'grassColor'
			, 'soilColor'
			, 'rockColor'
			, 'rockBandColor'
			, 'sandColor'
			, 'snowColor'
			, 'roadColor'
			, 'meadowColor'
			, 'rockSlopeStart'
			, 'rockSlopeEnd'
			, 'beachHeight'
			, 'beachWidth'
			, 'snowline'
			, 'snowTransition'
			, 'snowSlopeStart'
			, 'snowSlopeEnd'
		]
		, 'yardang-desert' => [
			'soilColor'
			, 'rockColor'
			, 'rockBandColor'
			, 'sandColor'
			, 'snowColor'
			, 'roadColor'
			, 'rockSlopeStart'
			, 'rockSlopeEnd'
		]
		, 'salt-pans' => [
			'grassColor', 'soilColor', 'rockColor', 'rockBandColor', 'sandColor', 'snowColor', 'roadColor',
			'rockSlopeStart', 'rockSlopeEnd',
		]
		, 'cinder-highlands' => [
			'grassColor', 'soilColor', 'rockColor', 'rockBandColor', 'sandColor', 'snowColor', 'roadColor',
			'rockSlopeStart', 'rockSlopeEnd',
		]
		, 'heather-moor' => [
			'grassColor'
			, 'soilColor'
			, 'rockColor'
			, 'rockBandColor'
			, 'sandColor'
			, 'snowColor'
			, 'roadColor'
			, 'forestColor', 'meadowColor'
			, 'rockSlopeStart'
			, 'rockSlopeEnd'
			, 'beachHeight'
			, 'beachWidth'
			, 'macroVariation'
		]
		, 'canyon' => [
			'grassColor', 'sandColor', 'snowColor', 'roadColor'
			, 'canyonRustColor'
			, 'canyonShaleColor'
			, 'canyonSandstoneColor'
			, 'canyonCapColor'
			, 'bandContrast', 'bandSharpness'
			, 'rockSlopeStart'
			, 'rockSlopeEnd'
			, 'beachHeight'
			, 'beachWidth'
			, 'macroVariation'
		]
	];
	/**
	 * Linear canyon shades in the default palette. Each keeps its ratio to the color it follows: ledges follow the
	 * cap rock and red beds the rust. Dust, beds too fine to resolve and the light the walls throw into shadow follow
	 * the beds' average weighted by thickness. Each derived channel stops at 1.
	 */
	private const LEDGE = [.42, .32, .19], RED_BEDS = [.31, .10, .045], DUST = [.396, .252, .112],
		DISTANT = [.354, .206, .115], BOUNCE = [.158, .102, .052];
	/** Validated settings with the keys and order of DEFAULTS. Colors are lower case. */
	public readonly array $settings;
	/** Shader uniform values by uniform name. */
	private readonly array $uniforms;

	/**
	 * Validate appearance settings over the defaults.
	 * @param array $options Settings by DEFAULTS key. Other keys are ignored, so a larger settings array can be passed.
	 * @return void Stores the clamped and ordered settings and their uniform values.
	 */
	public function __construct(array $options = [])
	{
		$settings = self::DEFAULTS;

		foreach(array_intersect_key($options, $settings) as $name => $value)
		{
			$settings[$name] = is_string(self::DEFAULTS[$name])
				? self::color($name, $value)
				: self::number($name, $value);
		}

		foreach(self::ORDER as [$start, $end, $gap])
		{
			// In binary, .21 - .2 is a little less than .01, so values one slider step apart need the slack.
			if($settings[$end] - $settings[$start] >= $gap - self::SLACK) continue;
			$top = self::RANGES[$end][1];

			// Rounding puts a pushed end on the next step, where .05 + .01 alone would give 0.060000000000000005.
			if($settings[$start] + $gap <= $top) $settings[$end] = round($settings[$start] + $gap, 9);
			else [$settings[$start], $settings[$end]] = [$top - $gap, $top];
		}

		$this->settings = $settings;
		$this->uniforms = self::uniformsFor($settings);
	}

	/**
	 * Read one color setting.
	 * @param string $name Setting name, used in the error message.
	 * @param mixed $value Value from a file or a page.
	 * @return string Lower-case six-digit hex color.
	 */
	private static function color(string $name, mixed $value): string
	{
		if(!is_string($value) || !preg_match('/^#[0-9a-f]{6}$/iD', $value))
		throw new \InvalidArgumentException("Terrain appearance $name must be a six-digit hex color such as #425b2d.");

		return strtolower($value);
	}

	/**
	 * Read one numeric setting.
	 * @param string $name Setting name, used in the error message and to find its range.
	 * @param mixed $value Value from a file or a page. Numeric strings are rejected.
	 * @return float Value clamped to the setting's range.
	 */
	private static function number(string $name, mixed $value): float
	{
		if(!is_int($value) && !is_float($value) || !is_finite((float)$value))
		throw new \InvalidArgumentException("Terrain appearance $name must be a finite number.");

		[$min, $max] = self::RANGES[$name];

		return max($min, min($max, (float)$value));
	}

	/**
	 * Convert an sRGB hex color to linear RGB.
	 * @param string $hex Six-digit hex color with a leading #. Anything else throws InvalidArgumentException.
	 * @return array Red, green and blue from 0 to 1.
	 */
	public static function linear(string $hex): array { return self::decode(self::color('color', $hex)); }

	/**
	 * Give the layer editor the same colors as the original canyon material.
	 * @return array Linear colors for the bed sequence, dust, distant strata, and reflected light.
	 */
	public function canyonColors(): array { return self::canyonPalette($this->settings); }

	/**
	 * Name the terrain style a recipe draws with, as Scene chooses its material.
	 * @param array $recipe Landscape recipe with its preset and version.
	 * @return string The key in USES for this recipe.
	 */
	public static function style(array $recipe): string
	{
		return match($recipe['preset'] ?? null)
		{
			'canyon' => ($recipe['version'] ?? 0) === 2 ? 'canyon' : 'canyon-v1',
			// Mesas and badlands are canyon country seen from the floor: the same bedded walls and dust.
			'red-mesas', 'painted-badlands' => 'canyon',
			'mountain', 'basalt-fjords', 'amber-dunes', 'arctic-meltwater', 'tidal-lagoon',
			'heather-moor', 'salt-pans', 'cinder-highlands',
			'rolling-prairie', 'river-valley', 'wooded-foothills', 'high-desert-steppe',
			'alaska-range', 'chalk-headlands', 'karst-bay', 'tepui-highlands', 'emerald-ridges', 'autumn-folds',
			'namib-coast', 'black-sand-coast', 'yardang-desert' => $recipe['preset'],
			default => 'coast'
		};
	}

	/**
	 * Convert a color that has already been validated.
	 * @param string $hex Six-digit hex color with a leading #.
	 * @return array Red, green and blue from 0 to 1.
	 */
	private static function decode(string $hex): array
	{
		$rgb = [];

		foreach(str_split(substr($hex, 1), 2) as $channel)
		{
			$c = hexdec($channel) / 255;
			$rgb[] = $c <= .04045 ? $c / 12.92 : (($c + .055) / 1.055) ** 2.4;
		}

		return $rgb;
	}

	/**
	 * Work out the canyon palette from its four chosen colors.
	 * @param array $settings Validated settings.
	 * @return array Linear colors by name: the six beds in the shader's order, then dust, distant and bounce.
	 */
	private static function canyonPalette(array $settings): array
	{
		$beds = self::canyonBeds($settings);
		$average = self::bedAverage($beds);
		$reference = self::bedAverage(self::canyonBeds(self::DEFAULTS));

		return $beds + [
			'dust' => self::shade($average, $reference, self::DUST),
			'distant' => self::shade($average, $reference, self::DISTANT),
			'bounce' => self::shade($average, $reference, self::BOUNCE),
		];
	}

	/**
	 * List the canyon's beds from the bottom of the section up.
	 * @param array $settings Validated settings.
	 * @return array Linear rust, shale, ledge, sandstone, red bed and cap colors by name.
	 */
	private static function canyonBeds(array $settings): array
	{
		$rust = self::decode($settings['canyonRustColor']);
		$cap = self::decode($settings['canyonCapColor']);

		return [
			'rust' => $rust, 'shale' => self::decode($settings['canyonShaleColor']),
			'ledge' => self::shade($cap, self::decode(self::DEFAULTS['canyonCapColor']), self::LEDGE),
			'sandstone' => self::decode($settings['canyonSandstoneColor']),
			'redBeds' => self::shade($rust, self::decode(self::DEFAULTS['canyonRustColor']), self::RED_BEDS),
			'cap' => $cap,
		];
	}

	/**
	 * Average the beds over one repeat of the section, weighted by thickness.
	 * @param array $beds Linear bed colors in the order of canyonBeds().
	 * @return array Linear average color.
	 */
	private static function bedAverage(array $beds): array
	{
		$c = CanyonBeds::CONTACTS;
		// The sandstone crosses the section twice: below the red beds and again above them.
		$thickness = [
			$c[1] - $c[0], $c[2] - $c[1], $c[3] - $c[2], $c[4] - $c[3] + $c[6] - $c[5], $c[5] - $c[4], $c[7] - $c[6],
		];
		$average = [0.0, 0.0, 0.0];

		foreach(array_values($beds) as $i => $color)
		for($j = 0; $j < 3; $j++) $average[$j] += $color[$j] * $thickness[$i] / ($c[7] - $c[0]);

		return $average;
	}

	/**
	 * Keep a shade's ratio to the color it follows, channel by channel, up to 1. Red beds are brighter than the rust
	 * and dust than the bed average, so a light source would otherwise give an albedo above 1.
	 * @param array $source Linear color the shade follows.
	 * @param array $default Linear source color in the default palette.
	 * @param array $shade Linear shade in the default palette.
	 * @return array Linear shade of the current source color.
	 */
	private static function shade(array $source, array $default, array $shade): array
	{
		return [
			min(1.0, $source[0] * $shade[0] / $default[0]), min(1.0, $source[1] * $shade[1] / $default[1]),
			min(1.0, $source[2] * $shade[2] / $default[2]),
		];
	}

	/**
	 * Work out the shader uniforms for a set of validated settings.
	 * @param array $settings Validated settings.
	 * @return array Float lists by uniform name.
	 */
	private static function uniformsFor(array $settings): array
	{
		$uniforms = [];

		foreach(['grass', 'soil', 'rock', 'rockBand', 'sand', 'snow', 'road'] as $name)
		$uniforms['u' . ucfirst($name) . 'Color'] = self::decode($settings[$name . 'Color']);

		$palette = self::canyonPalette($settings);
		$uniforms['uCanyonColors[0]'] = array_merge(...array_values(array_diff_key($palette, ['bounce' => 0])));
		$uniforms['uCanyonBounce'] = $palette['bounce'];
		$s = $settings;
		$uniforms['uBanding'] = [2 * M_PI / $s['bandSpacing'], $s['bandContrast'], $s['bandSharpness']];
		$uniforms['uSlopeBlend'] = [$s['rockSlopeStart'], $s['rockSlopeEnd'], $s['snowSlopeStart'], $s['snowSlopeEnd']];
		$uniforms['uHeightBlend'] = [$s['beachHeight'], $s['beachHeight'] + $s['beachWidth'], $s['alpineStart'],
			$s['alpineEnd']];
		$uniforms['uSnowline'] = [$s['snowline'], $s['snowTransition']];
		$uniforms['uMacroVariation'] = [$s['macroVariation']];

		$mountain = [];

		foreach(['forest', 'meadow', 'tundra', 'granite', 'stain', 'talus'] as $name)
		array_push($mountain, ...self::decode($s[$name . 'Color']));

		$uniforms['uMountainColors[0]'] = $mountain;
		$uniforms['uMountainZones'] = [$s['treeline'], $s['tundraStart']];
		$uniforms['uMountainAmounts'] = [$s['talusAmount'], $s['stainAmount'], $s['lichenAmount']];

		return $uniforms;
	}

	/**
	 * Report the values that bind() uploads.
	 * @return array Float lists by uniform name. Lists longer than four are palettes of three-channel colors: eight
	 * for the canyon and six for the mountain.
	 */
	public function uniforms(): array { return $this->uniforms; }

	/** Bind the five final paint inks in terrain material-ID order. */
	public function bindPaint(Program $program): void
	{
		$colors = [];
		foreach(SurfaceLayers::INKS as $key) array_push($colors,...self::linear($this->settings[$key]));
		glUniform3fv($program->location('uPaintColor[0]'),5,$colors);
	}

	/**
	 * Upload the appearance to the active terrain program.
	 * @param Program $program Program that consumes the appearance uniforms.
	 * @return void Sets every appearance uniform.
	 */
	public function bind(Program $program): void
	{
		foreach($this->uniforms as $name => $values)
		{
			$location = $program->location($name);

			match(count($values))
			{
				1 => glUniform1f($location, $values[0]),
				2 => glUniform2f($location, ...$values),
				3 => glUniform3f($location, ...$values),
				4 => glUniform4f($location, ...$values),

				// Only the canyon and mountain palettes are longer than a vec4. Both are arrays of vec3.
				default => glUniform3fv($location, intdiv(count($values), 3), $values),
			};
		}
	}
}
