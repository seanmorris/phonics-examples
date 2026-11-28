<?php
declare(strict_types=1);

namespace TerrainPhonics;

use Phonics\Render\Program;

/**
 * Grass, rice and heath that stand up from the ground near the eye.
 *
 * One copy of the terrain's nearest triangles is drawn at the height of the tallest blades. Each of its pixels
 * follows the view ray down through the grass and stops at the first blade it meets, so near blades hide far ones
 * and every pixel is shaded once, however tall or dense the grass is.
 *
 * The pass never lights the grass. It multiplies what the terrain pass drew: ground seen between the blades is
 * darkened to the shade there, and a blade is darkest at its foot and brighter than the bare ground at its tip. The
 * grass therefore takes the terrain's sunlight, shadows, haze and tone mapping at any hour without computing them
 * again, and it writes no depth.
 *
 * A blade is a hash of its world cell, a pure function of position: thin, flat, tapering to a point, turned at
 * random and leaning with its neighbors. Height tapers to nothing at the edge of the drawn radius, on painted ground,
 * on steep ground and outside the preset's height band, so the grass has no edge.
 */
final class GrassShells
{
	/** Segments each pixel's ray is divided into on its way down through the grass, by quality setting. */
	public const STEPS = [0, 6, 10];
	/** Height of the surface above the terrain where no grass grows, in meters, enough to pass the depth test. */
	private const LIFT = .02;
	/** Farthest the grass is drawn from the eye in meters, and the share of that radius at which it starts to taper. */
	private const REACH = 120., TAPER = .6;
	/** Eye height above the ground in meters beyond which blades are narrower than a pixel and the pass is skipped. */
	public const CEILING = 60.;
	/** Smallest radius worth drawing, in meters. */
	private const MINIMUM = 6.;
	/** Most terrain grids the grass is drawn on, counted from the finest. Each reaches twice as far as the last. */
	private const GRIDS = 7;
	/** Farthest that distant grass is marked with dots, in meters. */
	private const FAR = 1200.;
	/** How strongly a dot shows on snow. */
	private const DOT = .45;
	/** How strongly a dot shows on bare ground, as a share of its strength on snow. */
	private const DRY_DOT = .65;
	/** Tallest the grass is drawn, as a share of the eye's height above the ground, so the eye stays above it. */
	private const HEADROOM = .8;
	/**
	 * Where grass grows and what it looks like, by surface style.
	 * - height: tallest blade in meters.
	 * - density: blade cells per meter. Each cell holds one blade.
	 * - width: blade width at its foot, in meters.
	 * - root: the share of the ground's light left between the blades and at their feet.
	 * - tip: how much brighter than the bare ground a full-height blade ends, per color channel.
	 * - lean: how far a blade's tip stands from its foot, as a share of its height.
	 * - slope: where the grass starts to thin and where it ends, as one minus the upward part of the ground normal.
	 * - above: meters above the water at which grass starts and is full.
	 * - bare: surface layers that grow no grass, such as a beach's sand. clear() starts the grass where they end.
	 * - top: absolute heights in meters at which it starts to thin and ends, or null.
	 * - wind: sway of the tips in meters.
	 */
	public const STYLES = [
		'coast' => [
			'height' => .30
			, 'density' => 19.
			, 'tip' => [1.25, 1.35, .95]
			, 'slope' => [.06, .16]
			, 'bare' => ['sand']
		]

		, 'limestone' => [
			'height' => .25
			, 'density' => 20.
			, 'tip' => [1.2, 1.35, .95]
			, 'slope' => [.06, .16]
			, 'bare' => ['sand']
		]

		, 'mountain' => ['height' => .30, 'density' => 19., 'tip' => [1.25, 1.3, .95], 'slope' => [.08, .22]]

		, 'heather-moor' => [
			'height' => .30
			, 'density' => 16.
			, 'tip' => [1.35, 1.15, 1.25]
			, 'slope' => [.10, .24]
			, 'bare' => ['peat-banks']
		]

		, 'tidal-lagoon' => ['height' => .30, 'density' => 16., 'tip' => [1.3, 1.3, .95], 'above' => [1.5, 3.]]

		, 'rolling-prairie' => [
			'height' => .80
			, 'density' => 13.
			, 'tip' => [1.4, 1.3, .9]
			, 'slope' => [.12, .26]
			, 'wind' => .22
		]

		, 'river-valley' => [
			'height' => .50
			, 'density' => 16.
			, 'tip' => [1.25, 1.4, .9]
			, 'slope' => [.08, .2]
			, 'bare' => ['wet-silt']
		]

		, 'wooded-foothills' => ['height' => .35, 'density' => 19., 'tip' => [1.25, 1.35, .9], 'slope' => [.06, .16]]

		, 'high-desert-steppe' => [
			'height' => .40
			, 'density' => 6.
			, 'width' => .06
			, 'root' => .96
			, 'tip' => [1.15, 1.2, 1.05]
			, 'slope' => [.03, .08]
		]

		, 'alaska-range' => ['height' => .25, 'density' => 13., 'tip' => [1.3, 1.25, .95], 'slope' => [.08, .2]]
		, 'chalk-headlands' => [
			'height' => .14
			, 'density' => 22.
			, 'tip' => [1.2, 1.3, .95]
			, 'slope' => [.05, .14]
			, 'bare' => ['wet-chalk']
		]

		, 'karst-bay' => ['height' => .50, 'density' => 13., 'tip' => [1.2, 1.4, .9], 'slope' => [.25, .45]]
		, 'tepui-highlands' => ['height' => .40, 'density' => 16., 'tip' => [1.25, 1.35, .9], 'slope' => [.15, .3]]

		, 'painted-badlands' => [
			'height' => .30
			, 'density' => 10.
			, 'width' => .03
			, 'root' => .96
			, 'tip' => [1.3, 1.25, 1.]
			, 'slope' => [.02, .05]
			, 'bare' => ['sand']
		]

		, 'emerald-ridges' => ['height' => .50, 'density' => 13., 'tip' => [1.2, 1.4, .9], 'slope' => [.24, .40]]
		, 'autumn-folds' => ['height' => .40, 'density' => 16., 'tip' => [1.35, 1.3, .9], 'slope' => [.03, .06]]

		, 'black-sand-coast' => [
			'height' => .22
			, 'density' => 16.
			, 'tip' => [1.3, 1.3, .95]
			, 'slope' => [.05, .12]
			, 'above' => [2., 5.]
		]

		, 'rice-terraces' => [
			'height' => .50
			, 'density' => 19.
			, 'tip' => [1.25, 1.42, .9]
			, 'slope' => [.03, .07]
			, 'above' => [.2, .8]
			, 'top' => [80., 95.]
			, 'wind' => .10
		]

		, 'boreal-lakes' => ['height' => .20, 'density' => 19., 'tip' => [1.25, 1.3, 1.05], 'slope' => [.08, .2]]
	];
	/** Values a style inherits when it leaves them out. */
	private const DEFAULTS = [
		'width' => .032
		, 'root' => .93
		, 'lean' => .45

		, 'slope' => [.06, .16]
		, 'above' => [.5, 2.]
		, 'bare' => []
		, 'top' => null
		, 'wind' => .08
	];
	private const VERTEX = <<<'GLSL'
		#version 300 es
		precision highp float;
		precision highp int;
		// GRID
		uniform mat4 uViewProjection;
		uniform vec3 uEye;
		uniform vec2 uReach; // radius drawn, radius at which the taper starts
		uniform float uWaterLevel;
		// REGIONS
		out vec3 vWorld;
		out vec3 vNormal;
		out float vCover;
		out float vGround;
		out float vTop;
		out vec4 vArms; // each road arm's distance from its centerline in half-widths, signed, times the weight
		out vec4 vArmsX, vArmsZ; // how those distances change per meter along X and Z, times the weight
		out float vRoadWeight; // whether this corner is a road cell
		out float vInked; // whether this corner is painted or a road cell
		// A road cell's signed distance from its centerline in half-widths and the arm it belongs to, as the terrain
		// shader reads them. The arm is -1 away from roads and 4 on other painted ground.
		vec2 roadCell(ivec2 cell) {
			ivec4 ink = ivec4(texelFetch(uInk, texelAt(cell), 0)*255. + .5);
			int code = (ink.g<<16) | (ink.b<<8) | ink.a;
			if(ink.r != 5 || code == 0) return vec2(0., ink.r == 0 ? -1. : 4.);
			return vec2(float((code>>12) - 2048)/1024., float((code>>10) & 3));
		}
		// How the distance changes over one cell along an axis, from whichever neighbors are on the same arm.
		float roadStep(vec2 here, ivec2 cell, ivec2 axis) {
			vec2 before = roadCell(cell - axis), after = roadCell(cell + axis);
			bool low = before.y == here.y, high = after.y == here.y;
			return low && high ? .5*(after.x - before.x) : (high ? after.x - here.x : (low ? here.x - before.x : 0.));
		}
		void main() {
			ivec2 cell = clipStitchedCell(clipCell());
			float morph = clipMorph(cell);
			vec2 xz = vec2(cell)*uGridStep;
			float ground = clipHeight(cell, morph);
			// Painted cells are dressed ground and grow nothing. A road marks a band wider than its pavement, so its
			// cells stay open here and the fragment ends the grass at the pavement's edge.
			vec2 road = roadCell(cell);
			bool marked = road.y >= 0. && road.y < 4.;
			vInked = road.y >= 0. ? 1. : 0.;
			// Each arm of a junction keeps its own distance. Blended into one, the distances from two centerlines
			// would cancel where the arms meet and read as pavement.
			vArms = vArmsX = vArmsZ = vec4(0.);
			vRoadWeight = 0.;
			if(marked) {
				int arm = int(road.y);
				vArms[arm] = road.x;
				vArmsX[arm] = roadStep(road, cell, ivec2(1, 0))/uGridStep;
				vArmsZ[arm] = roadStep(road, cell, ivec2(0, 1))/uGridStep;
				vRoadWeight = 1.;
			}
			// The surface stays at blade height over paint and pavement. What grows there is decided for each pixel and
			// each blade from the ground's own paint. A surface that dropped to the ground over paint and rose again
			// beyond it could cross one line of sight twice, and the pass would be applied twice there.
			float cover = 1. - smoothstep(uReach.y, uReach.x, distance(xz, uEye.xz));
			// The tallest blade here. Where landscapes meet, each adds its share.
			vec3 weight = regionWeights(xz);
			float grown = 0.;
			for(int r = 0; r < uRegions; r++) grown += weight[r]*uBlades[r].w*heightBand(r, ground);
			vCover = cover;
			vGround = ground;
			vTop = cover*grown;
			vNormal = clipNormal(cell, morph);
			// The surface rides on the tips of the tallest blades and comes down to the ground where the grass ends.
			vWorld = vec3(xz.x, ground + LIFT + vTop, xz.y);
			gl_Position = uViewProjection*vec4(vWorld-uEye, 1.);
		}
		GLSL;
	private const FRAGMENT = <<<'GLSL'
		#version 300 es
		precision highp float;
		precision highp int;
		in vec3 vWorld;
		in vec3 vNormal;
		in float vCover;
		in float vGround;
		in float vTop;
		in vec4 vArms; // each road arm's distance from its centerline in half-widths, signed, times the weight
		in vec4 vArmsX, vArmsZ; // how those distances change per meter along X and Z, times the weight
		in float vRoadWeight; // how much of this pixel's ground is road cells
		in float vInked; // above zero where a corner of this pixel's surface is painted or a road cell
		uniform vec3 uEye;
		uniform float uWaterLevel;
		uniform vec2 uView; // radians per pixel, seconds
		uniform int uSteps;
		uniform float uBuried; // share of the blades that snow hides
		uniform float uSnowCover; // snow cover on level ground, as the terrain pass draws it
		uniform vec2 uReach; // radius the blades are drawn to, radius at which they start to taper
		// Radius to which distant grass is marked with dots, how strongly a dot shows on snow, and its strength on bare
		// ground as a share of that.
		uniform vec3 uDots;
		uniform highp sampler2D uInk; // this grid's paint and road codes, one texel for each cell
		uniform int uTextureMask; // wraps a cell onto its texel
		uniform float uGridStep; // meters across a cell of this grid
		uniform highp sampler2D uDotTable; // 64 by 64 blocks for each of 16 block sizes, stacked: see dotTable()
		uniform highp sampler2D uElevations; // this grid's ground heights, one texel for each cell
		uniform bool uPacked; // whether a height is packed into the texel's four bytes
		// REGIONS
		out vec4 color;
		uint bladeHash(ivec2 c) {
			uint h = uint(c.x)*0x9E3779B1u ^ uint(c.y)*0x85EBCA77u;
			h ^= h>>15; h *= 0x2C1B3C6Du; h ^= h>>12;
			return h;
		}
		// Whether this pixel holds a dot. Past the blades, grass standing in snow is marked by one dot for each block of
		// cells, a single pixel at a fixed place in the block. It does not move. The blocks grow with distance so that
		// about one pixel in fourteen holds a dot, as many as the blades mark where they end. Each block's dot is also
		// the dot of one of the four blocks it divides into, so coming closer adds dots between the ones already
		// showing and moves none.
		vec2 dotAt(vec2 ground, vec2 ahead, float pixel, float slope, float cells) {
			float fine = max(log2(pixel*sqrt(14./slope)*cells), 1.);
			int whole = min(int(fine), 14);
			float rising = min(fine - float(whole), 1.);
			float scale = exp2(-float(whole));
			vec2 g = mat2(.8,.6,-.6,.8)*ground*cells;
			vec2 deep = mat2(.8,.6,-.6,.8)*ahead*(pixel/slope)*cells; // the ground one pixel spans in depth, in cells
			// A pixel looks along several blocks at this angle. It samples its depth at points about a block apart.
			int last = clamp(int(ceil(length(deep)*scale)), 1, 3);
			float best = 0., tone = 0.;
			for(int n = 0; n <= last; n++) {
				ivec2 block = ivec2(floor((g + deep*(float(n)/float(last) - .5))*scale));
				// Where the dot sits in its block was worked out once and is read from the table, as is the quarter of
				// the parent block that keeps its dot.
				vec4 own = texelFetch(uDotTable, ivec2(block.x & 63, (block.y & 63) + 64*whole), 0);
				int keeps = int(texelFetch(uDotTable, ivec2((block.x>>1) & 63, ((block.y>>1) & 63) + 64*whole + 64), 0).a*255. + .5);
				vec2 off = (mat2(.8,-.6,.6,.8)*(vec2(block) + (own.xy*255. + .5)/256.))/(scale*cells) - ground;
				// Where the dot lies from this pixel's middle, in pixels across and up the screen.
				vec2 at = vec2(dot(off, vec2(-ahead.y, ahead.x)), dot(off, ahead)*slope)/pixel;
				// A block that is not the one its parent block keeps fades out as the blocks grow to the parent's size.
				bool kept = (block.x & 1) == (keeps & 1) && (block.y & 1) == (keeps>>1);
				float shows = step(max(abs(at.x), abs(at.y)), .5)*(kept ? 1. : 1. - rising);
				if(shows > best) { best = shows; tone = own.b; }
			}
			return vec2(best, tone); // how much of a dot the pixel shows, and a number from 0 to 1 that is the dot's own
		}
		// Distance from a road's centerline in half-widths, a few meters from this pixel's surface point. Pavement ends
		// one half-width out. Each arm's distance is blended on its own and the arms are then added, as the terrain
		// does, so the pass and the ground agree on what is paved where roads meet.
		float roadAt(vec2 offset) {
			return dot(abs(vArms + vArmsX*offset.x + vArmsZ*offset.y), vec4(1.))/vRoadWeight;
		}
		// The ground's height at a point, blended from the four grid corners around it.
		float cornerHeight(ivec2 cell) {
			vec4 t = texelFetch(uElevations, cell & ivec2(uTextureMask), 0);
			if(!uPacked) return t.r;
			uvec4 b = uvec4(t*255. + .5);
			return uintBitsToFloat(b.x | (b.y<<8) | (b.z<<16) | (b.w<<24));
		}
		float groundAt(vec2 point) {
			vec2 at = point/uGridStep;
			ivec2 corner = ivec2(floor(at));
			vec2 part = at - vec2(corner);
			return mix(
				mix(cornerHeight(corner), cornerHeight(corner + ivec2(1, 0)), part.x)
				, mix(cornerHeight(corner + ivec2(0, 1)), cornerHeight(corner + ivec2(1, 1)), part.x)
				, part.y
			);
		}
		// How much of the ground at a point is dressed, as the terrain draws it there: paint, and pavement within a
		// road's half-width. The terrain blends the paint of the grid corners around a point, so this reads the same
		// four corners. It is looked up at the ground the pixel shows, with nothing extrapolated, so the pass and the
		// ground agree on every pixel.
		float dressedAt(vec2 point) {
			vec2 at = point/uGridStep;
			ivec2 corner = ivec2(floor(at));
			vec2 part = at - vec2(corner);
			float paint = 0., weight = 0.;
			vec4 arms = vec4(0.);
			for(int i = 0; i < 4; i++) {
				ivec2 hop = ivec2(i & 1, i>>1);
				float share = (hop.x == 1 ? part.x : 1. - part.x)*(hop.y == 1 ? part.y : 1. - part.y);
				ivec4 ink = ivec4(texelFetch(uInk, (corner + hop) & ivec2(uTextureMask), 0)*255. + .5);
				int code = (ink.g<<16) | (ink.b<<8) | ink.a;
				if(ink.r == 5 && code != 0) {
					arms[(code>>10) & 3] += share*float((code>>12) - 2048)/1024.;
					weight += share;
				} else if(ink.r != 0) paint += share;
			}
			// Pavement ends one half-width from the centerline. Each arm is blended on its own and the arms are added.
			float paved = weight > .0001 ? weight*(1. - smoothstep(1., 1.06, dot(abs(arms), vec4(1.))/weight)) : 0.;
			return paint + paved;
		}
		void main() {
			vec3 normal = normalize(vNormal);
			float steep = 1. - normal.y;
			float open = vCover;
			vec4 kind; // cells per meter, half width at the foot, lean as a share of height, tallest blade
			vec4 shade; // light left between the blades, slope where grass thins and ends, tip sway
			vec3 tip, grass;
			float cover, grows; // how fully grass grows here, with and without the taper toward the blades' radius
			if(uRegions < 2) {
				kind = uBlades[0];
				shade = uShades[0];
				tip = uTips[0];
				grass = uGrass[0];
				grows = heightBand(0, vGround)*(1. - smoothstep(shade.y, shade.z, steep));
				cover = open*grows;
			} else {
				// Where landscapes meet, their grasses merge. Each gives its height, shape and color in proportion to
				// how much of it grows here.
				vec3 weight = regionWeights(vWorld.xz);
				float total = 0., most = 0.;
				kind = vec4(0.);
				shade = vec4(0.);
				tip = vec3(0.);
				grass = vec3(0.);
				for(int r = 0; r < uRegions; r++) {
					vec4 blades = uBlades[r], tones = uShades[r];
					float share = weight[r]*blades.w*heightBand(r, vGround)*(1. - smoothstep(tones.y, tones.z, steep));
					total += share;
					kind.yzw += share*blades.yzw;
					shade.xw += share*tones.xw;
					tip += share*uTips[r];
					grass += share*uGrass[r];
					// A lattice cannot blend, so it comes from whichever grass there is more of.
					if(share > most) { most = share; kind.x = blades.x; }
				}
				if(total <= .0001) discard;
				kind.yzw /= total;
				shade.xw /= total;
				tip /= total;
				grass /= total;
				cover = open*total/kind.w;
				grows = total/kind.w;
			}
			float tallest = kind.w;
			vec3 toEye = vWorld-uEye;
			float range = length(toEye);
			vec3 ray = toEye/range;
			// The surface is only ever entered from above. Where the ground falls away faster than the line of sight, the
			// ray comes back out through it from underneath, and that crossing draws nothing.
			if(dot(ray, normalize(vNormal)) >= 0.) discard;
			// The pass multiplies what the terrain drew, and under snow that is the snow's white. A blade keeps the grass's
			// color instead: it is scaled by how far the snow lifted the ground's color from the landscape's grass color.
			float snowed = uSnowCover*smoothstep(.38, .88, normal.y);
			vec3 standing = grass/mix(grass, vec3(.82,.88,.93), snowed);
			// Past the blades, grass standing in snow shows as dots. They fade in where the blades shrink away, and grow
			// paler from there to their own radius.
			float speck = 0.;
			float outward = distance(vWorld.xz, uEye.xz); // meters from the eye along the ground
			float snowy = smoothstep(.15, .5, snowed);
			float marked = tallest <= 0. ? 0. : uDots.y*mix(uDots.z, 1., snowy)*min(1., grows*4.)
				*smoothstep(.5*(uReach.x + uReach.y), uReach.x, outward)*(1. - smoothstep(uReach.x, uDots.x, outward));
			float slope = max(-dot(ray, normal), .01); // sine of the angle between the line of sight and the ground
			vec2 under = vWorld.xz + ray.xz*(max(vTop, 0.)*normal.y/max(slope, .05)); // the ground this pixel shows
			vec2 spot = marked > .004 ? dotAt(under, normalize(ray.xz + vec2(1e-6, 0.)), range*uView.x, slope, kind.x) : vec2(0.);
			speck = marked*spot.x;
			// On snow every dot is a dark tip. On bare ground a dot is a blade somewhere between its shaded foot and its
			// lit tip, so distant grass is speckled light and dark like the grass nearer by.
			vec3 dotTone = mix(vec3(.9*shade.x), 1.29*tip, mix(spot.y, .8, snowy))*standing;
			if(tallest <= 0. || cover <= .01) {
				// No blades here. A dot is drawn on its own, though not on pavement.
				if(speck <= .004 || dressedAt(under) > .1) discard;
				color = vec4(.5*min(mix(vec3(1.), dotTone, speck), vec3(2.)), 1.);
				return;
			}
			// Grass grows in clumps a few meters across.
			float clump = .75 + .25*sin(vWorld.x*.83 + 1.7*sin(vWorld.z*.61))*sin(vWorld.z*.97 + 1.3*sin(vWorld.x*.53));
			float top = max(vTop, .01); // height of this surface above the ground
			// The walk starts where the tallest blade here could end. Starting at the surface would spend most of its
			// segments above short grass and leave the rest to chance.
			float start = min(tallest*cover, top);
			float full = start*clump; // tallest blade here
			// The ray's run across the ground from this surface down to the slope under it: first to the blade tops,
			// then through the grass.
			vec2 run = ray.xz*(top*normal.y/max(-dot(ray, normal), .05));
			// Near a road, the distance from its centerline at any point close by. Pavement ends one half-width out.
			bool roadside = vRoadWeight > .0001;
			// The pass multiplies the ground the pixel shows, so a blade in front of pavement or paint would only darken
			// it. The shade between the blades ends with them.
			float dressed = dressedAt(vWorld.xz + run);
			// The walk assumes the ground continues as it lies under this pixel's surface point. Where it falls away
			// instead, the ray passes through blade tops and leaves the grass again, to enter it once more further on.
			// Such a pixel shows blades, but the shade belongs to the ground the ray finally reaches, and the pass
			// applies it there. Casting it here as well would darken that ground twice.
			// A ray that comes down steeply reaches the ground before any bank could drop away from it.
			bool passing = false;
			if(dot(ray, normal) > -.3) {
				float above = vWorld.y + ray.y*length(run)/max(length(ray.xz), 1e-4) - groundAt(vWorld.xz + run);
				passing = above > .5*top + .1;
			}
			if(!passing && dressed > .3) discard;
			float verge = passing ? 0. : 1. - smoothstep(0., .3, dressed);
			// No blade is rooted in paint. The ray may pass over paint on its way to open ground, so the paint is also
			// read where the walk starts and halfway along it, and each blade is held against the paint at its place.
			// Most ground has no paint near it. Where neither the surface point nor the ground point touches any, the
			// short way between them is taken to be clear, and nothing more is looked up.
			float dressedIn = 0., dressedMid = 0.;
			if(vInked > 0. || dressed > 0.) {
				dressedIn = dressedAt(vWorld.xz + run*(1. - min(tallest*cover, top)/top));
				dressedMid = dressedAt(vWorld.xz + run*(1. - .5*min(tallest*cover, top)/top));
			}
			vec2 enter = vWorld.xz + run*(1. - start/top);
			run *= start/top;
			// Neighbors lean the same way and bend more toward the tip. Gusts push the tips further.
			float turn = 2.4*sin(vWorld.x*.071 + 1.3*sin(vWorld.z*.053)) + 1.9*sin(vWorld.z*.067);
			float gust = sin(uView.y*1.7 + dot(vWorld.xz, vec2(.83,.55))/9.)
				+ .5*sin(uView.y*2.9 + dot(vWorld.xz, vec2(-.41,.91))/3.33);
			vec2 lean = vec2(cos(turn), sin(turn))*kind.z + vec2(.8,.6)*shade.w*gust/max(tallest, .05);
			// A blade is never drawn narrower than most of a pixel, so distant grass keeps its cover without shimmering.
			float halfWidth = max(kind.y, .7*range*uView.x);
			// A blade widened to stay most of a pixel covers more ground than it has. It is made fainter in proportion,
			// so distant grass keeps its true weight on the ground's color and single pixels do not flicker as the eye moves.
			float seen = min(1., kind.y/max(.7*range*uView.x, 1e-6));
			// Start each ray at a different depth. Neighboring pixels then find a blade at different heights, which joins
			// it into one stroke instead of a piece per segment.
			float offset = fract(52.9829189*fract(dot(gl_FragCoord.xy, vec2(.06711056,.00583715))));
			// The snow between the blades is not shaded by them.
			vec3 light = vec3(mix(1., shade.x, cover*verge*(1. - snowed)));
			// Beyond a few tens of meters a blade is a pixel wide, and a few segments find as much as many do up close.
			int count = max(3, int(float(uSteps)*(1. - .65*smoothstep(12., 45., range)) + .5));
			float steps = float(count);
			float h0 = start;
			vec2 at0 = enter;
			for(int k = 0; k < count; k++) {
				float s = min(1., (float(k)+offset+.5)/steps);
				float h1 = start*(1.-s);
				vec2 at1 = enter + run*s;
				// The blade rooted in the cell under the middle of this segment, in the frame where its neighbors stand upright.
				float hm = .5*(h0+h1), bend = hm/max(tallest, .05);
				vec2 sag = lean*hm*(.45 + .55*bend);
				// The lattice is turned by an exact rotation, so turning back finds the same point.
				vec2 middle = .5*(at0+at1);
				vec2 grid = mat2(.8,.6,-.6,.8)*(middle - sag)*kind.x;
				vec2 base = floor(grid);
				uint h = bladeHash(ivec2(base));
				// Grass starts just past the pavement's edge and reaches its height a little further out.
				float tall = full*(.4 + .6*float(h & 1023u)/1023.);
				// Snow hides whole blades where they stand. Each cell's turn is fixed, so no blade moves as the cover changes.
				if(float((h*0x9E3779B1u)>>22)/1023. < uBuried) tall = 0.;
				if(roadside) tall *= smoothstep(1.03, 1.14, roadAt(middle - vWorld.xz));
				tall *= 1. - smoothstep(.05, .3, s < .5 ? mix(dressedIn, dressedMid, 2.*s) : mix(dressedMid, dressed, 2.*s - 1.));
				if(h1 < tall) {
					vec2 jitter = vec2(float((h>>10) & 1023u), float((h>>20) & 1023u))/1023. - .5;
					// From the blade's foot to the middle of the segment, worked out inside the cell: world coordinates are
					// thousands of meters, and a blade is centimeters wide.
					vec2 fromFoot = (mat2(.8,-.6,.6,.8)*(grid - base - .5 - .7*jitter))/kind.x;
					float own = .85 + .3*jitter.x; // each blade leans a little more or less than its neighbors
					float b0 = h0/max(tallest, .05), b1 = h1/max(tallest, .05);
					vec2 halfSegment = .5*(at1-at0);
					vec2 d0 = fromFoot - halfSegment + sag - own*lean*h0*(.45 + .55*b0);
					vec2 d1 = fromFoot + halfSegment + sag - own*lean*h1*(.45 + .55*b1);
					// A blade is flat: wide across its face and thin through it. It faces one of four ways.
					uint facing = (h>>22) & 3u;
					vec2 across = facing == 0u ? vec2(1.,0.) : (facing == 1u ? vec2(.7071,.7071)
						: (facing == 2u ? vec2(0.,1.) : vec2(-.7071,.7071)));
					vec2 e0 = vec2(dot(d0, across), 3.5*dot(d0, vec2(-across.y, across.x)));
					vec2 e1 = vec2(dot(d1, across), 3.5*dot(d1, vec2(-across.y, across.x)));
					vec2 along = e1-e0;
					// The nearest the segment comes to the blade's middle, below the blade's tip.
					float lowest = clamp((h0-tall)/max(h0-h1, 1e-5), 0., 1.);
					float u = clamp(-dot(e0, along)/max(dot(along, along), 1e-8), lowest, 1.);
					float height = mix(h0, h1, u)/tall;
					vec2 e = e0 + along*u;
					float w = halfWidth*(1. - height*sqrt(height));
					if(dot(e, e) < w*w) {
						// Shaded at the foot, bright at the tip, and no two blades quite the same green. A few have dried.
						float rise = height*tall/max(tallest, .05);
						vec3 blade = mix(vec3(.9*shade.x), 1.29*tip, rise)*(.66 + .68*float((h>>5) & 255u)/255.);
						if((h & 15u) == 0u) blade *= vec3(1.2, 1.08, .75);
						light = mix(light, blade*standing, min(1., cover*4.)*seen);
						break;
					}
				}
				h0 = h1;
				at0 = at1;
			}
			light = mix(light, dotTone, speck);
			// The blend doubles the product, so one half leaves the ground as it was.
			color = vec4(.5*min(light, vec3(2.)), 1.);
		}
		GLSL;
	/** Declarations and helpers both shaders share: each landscape's grass and where the landscapes meet. */
	private const REGIONS = <<<'GLSL'
		uniform int uRegions; // landscapes drawn: one on a single map, two or three on a journey
		uniform float uJoinWidth; // meters across the connector where a journey's landscapes meet
		uniform vec4 uBands[3]; // meters above water where grass starts and is full, heights where it thins and ends
		uniform vec4 uBlades[3]; // cells per meter, half width at the foot, lean as a share of height, tallest blade
		uniform vec4 uShades[3]; // light left between the blades, slope where grass thins and ends, tip sway in meters
		uniform vec3 uTips[3]; // brightness of a full-height blade's tip against the bare ground, per channel
		uniform vec3 uGrass[3]; // the scene's grass color, which a blade keeps above the snow
		// Each landscape's share of a point, as the terrain blends them: west, then northeast, then southeast.
		vec3 regionWeights(vec2 world) {
			if(uRegions < 2) return vec3(1., 0., 0.);
			vec2 t = clamp(world/uJoinWidth + .5, 0., 1.);
			vec2 w = t*t*t*(t*(t*6. - 15.) + 10.);
			float south = uRegions > 2 ? w.x*w.y : 0.;
			return vec3(1. - w.x, w.x - south, south);
		}
		// How fully a landscape's grass grows at a height.
		float heightBand(int region, float ground) {
			vec4 band = uBands[region];
			return smoothstep(band.x, band.y, ground-uWaterLevel)*(1. - smoothstep(band.z, band.w, ground));
		}
		GLSL;
	private ?Program $program = null;
	/** The texture that holds the distant dots' layout, once made. */
	private array $dots = [];

	/**
	 * Look up how grass grows on a surface style.
	 * @param string $style Surface style or preset name.
	 * @return array|null The style's settings with every default filled in, or null where no grass grows.
	 */
	public static function style(string $style): ?array
	{
		return isset(self::STYLES[$style]) ? self::STYLES[$style] + self::DEFAULTS : null;
	}

	/**
	 * Keep a style's grass off the surface layers it names as bare, such as a beach's sand.
	 *
	 * Grass is placed by height above the water. A bare layer is painted up from the water to its own height and
	 * fades out above it. The grass's start is raised to where half of that fade is behind it, and it is full with
	 * nine tenths behind it, so the grass follows the layer when the layer is edited or removed.
	 *
	 * @param ?array $style A style from style(), or null where no grass grows.
	 * @param SurfaceLayers $layers The surface layers the landscape is drawn with.
	 * @return ?array The style with its start raised past its bare layers.
	 */
	public static function clear(?array $style, SurfaceLayers $layers): ?array
	{
		if($style === null) return null;

		foreach($layers->layers as $row)
		{
			if(!in_array($row['id'], $style['bare'], true) || $row['datum'] !== 'water' || $row['channel'] !== 'color')
			continue;

			$style['above'] = [
				max($style['above'][0], $row['high'] + .5 * $row['fade'])
				, max($style['above'][1], $row['high'] + .9 * $row['fade'])
			];
		}

		return $style;
	}

	/**
	 * Work out the share of blades that a snow cover hides.
	 *
	 * The blades it hides are picked cell by cell, so each one goes and returns where it stands. The spacing of the
	 * blades must not follow the snow: the lattice is laid out from the world's origin, and changing its spacing
	 * slides every blade across the ground.
	 *
	 * @param float $snow Snow cover from 0 to 1.
	 * @return float Share of blades hidden, from 0 to 0.9.
	 */
	public static function buried(float $snow): float
	{
		return .9 * max(0., min(1., $snow));
	}

	/**
	 * Lay out the distant dots once: where each block's dot sits, for every size of block.
	 *
	 * A block is a square of lattice cells, and each size has blocks four times the area of the size below. Every
	 * block has one dot, and that dot is the dot of one of the four smaller blocks it divides into. So as the eye
	 * comes closer and the pass turns to smaller blocks, the dots already showing stay where they are and others
	 * appear between them. The layout repeats every 64 blocks.
	 *
	 * @return string 64 by 1024 texels of four bytes: where the dot sits across and along its block, its tone, and
	 * which quarter of the block holds it, for 16 sizes of block stacked from the smallest.
	 */
	public static function dotTable(): string
	{
		static $table = null;

		if($table !== null) return $table;

		$table = '';
		$below = [];

		for($size = 0; $size < 16; $size++)
		{
			$here = [];

			for($y = 0; $y < 64; $y++)
			for($x = 0; $x < 64; $x++)
			{
				$h = crc32("grass dots $size $x $y");

				if($size === 0) $here[] = [($h & 255) + .5, (($h >> 8) & 255) + .5, ($h >> 16) & 255, 0];
				else
				{
					$quarter = ($h >> 24) & 3;
					$child = $below[((2 * $y + ($quarter >> 1)) & 63) * 64 + ((2 * $x + ($quarter & 1)) & 63)];

					$here[] = [
						128 * ($quarter & 1) + $child[0] / 2
						, 128 * ($quarter >> 1) + $child[1] / 2
						, $child[2]
						, $quarter
					];
				}
			}

			foreach($here as $dot) $table .= pack('C4', (int)$dot[0], (int)$dot[1], $dot[2], $dot[3]);

			$below = $here;
		}

		return $table;
	}

	/**
	 * Work out how far from the eye the grass can be drawn inside the terrain's finest grid.
	 * @param array $window Minimum X and Z, then maximum X and Z, of the grid in meters.
	 * @param array $eye Eye position.
	 * @param float $reach Farthest radius wanted, in meters.
	 * @return float Radius in meters, zero when the grid leaves too little room around the eye.
	 */
	public static function radius(array $window, array $eye, float $reach = self::REACH): float
	{
		$room = min($eye[0] - $window[0], $window[2] - $eye[0], $eye[2] - $window[1], $window[3] - $eye[2]);
		$radius = min($reach, $room);

		return $radius >= self::MINIMUM ? $radius : 0.;
	}

	/**
	 * Count the segments each ray is divided into at a quality setting.
	 * @param int $quality Index into STEPS.
	 * @return int Segments, zero when the setting is off.
	 */
	public static function steps(int $quality): int
	{
		return self::STEPS[$quality] ?? 0;
	}

	/**
	 * Draw the grass over terrain that the scene has already drawn, before anything that stands on it.
	 * @param Scene $scene Scene whose resident grids supply the triangles.
	 * @param Camera $camera Camera of the terrain pass.
	 * @param array $styles One entry from style() for a single map, or two or three for a journey's landscapes in the
	 *   order west, northeast, southeast. A null entry is a landscape that grows no grass.
	 * @param float $water Water level in meters.
	 * @param float $time Seconds, for the wind.
	 * @param int $steps Segments per ray, from steps().
	 * @param int $width Viewport width in pixels.
	 * @param int $height Viewport height in pixels.
	 * @param float $clearance Eye height above the ground in meters.
	 * @return bool Whether the grass was drawn.
	 */
	public function draw(
		Scene $scene
		, Camera $camera
		, array $styles
		, float $water
		, float $time
		, int $steps
		, int $width
		, int $height
		, float $clearance
	): bool {
		$styles = array_slice(array_values($styles), 0, 3);

		if($steps < 1 || $clearance > self::CEILING || !array_filter($styles)) return false;

		// Draw on as many grids as it takes to reach the full distance, or as many as are resident. Blades reach a
		// short way. Beyond them the grass is marked with dots, which takes further grids.
		$eye = $camera->position;
		$snow = $scene->weather?->ground($time)[1] ?? 0.;
		$radius = 0.;
		$far = 0.;
		$grids = 0;

		while($grids < self::GRIDS && $far < self::FAR)
		{
			$window = $scene->groundPatchWindow($grids);

			if($window === null) break;

			$radius = max($radius, self::radius($window, $eye));
			$far = max($far, self::radius($window, $eye, self::FAR));
			$grids++;
		}

		if($radius <= 0.) return false;

		$p = $this->program ??= new Program(
			str_replace(
				['// GRID', '// REGIONS', 'LIFT']
				, [Clipmap::gridShader(), self::REGIONS, sprintf('%.3f', self::LIFT)]
				, self::VERTEX
			)
			, str_replace('// REGIONS', self::REGIONS, self::FRAGMENT)
		);

		$p->use();
		glUniformMatrix4fv($p->location('uViewProjection'), 1, false, $camera->viewProjection($width, $height));
		glUniform3f($p->location('uEye'), ...$eye);
		glUniform2f($p->location('uReach'), $radius, self::TAPER * $radius);
		glUniform1f($p->location('uWaterLevel'), $water);
		glUniform2f($p->location('uView'), 2 * tan($camera->fov / 2) / max(1, $height), fmod($time, 3600.));
		glUniform1i($p->location('uSteps'), $steps);
		glUniform1i($p->location('uRegions'), count($styles));
		glUniform1f($p->location('uJoinWidth'), JoinedTerrain::GAP);

		glUniform3f($p->location('uDots'), max($far, $radius), self::DOT, self::DRY_DOT);
		glActiveTexture(GL_TEXTURE0 + 6);

		if(!$this->dots)
		{
			glGenTextures(1, $this->dots);
			glBindTexture(GL_TEXTURE_2D, $this->dots[0]);
			glTexImage2D(GL_TEXTURE_2D, 0, GL_RGBA8, 64, 1024, 0, GL_RGBA, GL_UNSIGNED_BYTE, self::dotTable());
			glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MIN_FILTER, GL_NEAREST);
			glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MAG_FILTER, GL_NEAREST);
		}

		glBindTexture(GL_TEXTURE_2D, $this->dots[0]);
		glUniform1i($p->location('uDotTable'), 6);
		glActiveTexture(GL_TEXTURE0);
		glUniform1f($p->location('uBuried'), self::buried($snow));
		glUniform1f($p->location('uSnowCover'), $snow);
		$grass = TerrainAppearance::linear($scene->appearance->settings['grassColor']);

		// Grass no taller than the eye allows: a surface above the eye would hide the blades under it.
		$headroom = self::HEADROOM * max(0., $clearance);

		foreach($styles as $index => $style)
		{
			$style ??= ['height' => 0.] + self::style('coast');
			$top = $style['top'] ?? [1e9, 2e9];

			glUniform4f($p->location("uBands[$index]"), $style['above'][0], $style['above'][1], $top[0], $top[1]);

			glUniform4f(
				$p->location("uBlades[$index]")
				, $style['density']
				, .5 * $style['width']
				, $style['lean']
				, min($style['height']
					, $headroom
				)
			);

			glUniform4f(
				$p->location("uShades[$index]")
				, $style['root']
				, $style['slope'][0]
				, $style['slope'][1]
				, $style['wind']
			);

			glUniform3f($p->location("uTips[$index]"), ...$style['tip']);
			glUniform3f($p->location("uGrass[$index]"), max(.01, $grass[0]), max(.01, $grass[1]), max(.01, $grass[2]));
		}

		// Twice the product of what is there and what the pass writes. Alpha is left alone.
		glEnable(GL_BLEND);
		glBlendFuncSeparate(GL_DST_COLOR, GL_SRC_COLOR, GL_ZERO, GL_ONE);
		glDepthMask(false);
		glDepthFunc(GL_LEQUAL);

		$drawn = $scene->drawGroundRings($p, $camera, $grids) > 0;

		glDepthFunc(GL_LESS);
		glDepthMask(true);
		glDisable(GL_BLEND);
		glBindVertexArray(0);

		return $drawn;
	}

	/**
	 * Release the program and the dots' texture.
	 * @return void Leaves the next draw to compile it again.
	 */
	public function close(): void
	{
		$this->program?->close();
		$this->program = null;

		if($this->dots) glDeleteTextures(1, $this->dots);

		$this->dots = [];
	}
}
