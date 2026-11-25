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
	private const GRIDS = 4;
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
	 * - top: absolute heights in meters at which it starts to thin and ends, or null.
	 * - wind: sway of the tips in meters.
	 */
	public const STYLES = [
		'coast' => ['height' => .30, 'density' => 19., 'tip' => [1.25, 1.35, .95], 'slope' => [.06, .16]]
		, 'limestone' => ['height' => .25, 'density' => 20., 'tip' => [1.2, 1.35, .95], 'slope' => [.06, .16]]
		, 'mountain' => ['height' => .30, 'density' => 19., 'tip' => [1.25, 1.3, .95], 'slope' => [.08, .22]]
		, 'heather-moor' => ['height' => .30, 'density' => 16., 'tip' => [1.35, 1.15, 1.25], 'slope' => [.10, .24]]
		, 'tidal-lagoon' => ['height' => .30, 'density' => 16., 'tip' => [1.3, 1.3, .95], 'above' => [1.5, 3.]]

		, 'rolling-prairie' => [
			'height' => .80
			, 'density' => 13.
			, 'tip' => [1.4, 1.3, .9]
			, 'slope' => [.12, .26]
			, 'wind' => .22
		]

		, 'river-valley' => ['height' => .50, 'density' => 16., 'tip' => [1.25, 1.4, .9], 'slope' => [.08, .2]]
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
		, 'chalk-headlands' => ['height' => .14, 'density' => 22., 'tip' => [1.2, 1.3, .95], 'slope' => [.05, .14]]
		, 'karst-bay' => ['height' => .50, 'density' => 13., 'tip' => [1.2, 1.4, .9], 'slope' => [.25, .45]]
		, 'tepui-highlands' => ['height' => .40, 'density' => 16., 'tip' => [1.25, 1.35, .9], 'slope' => [.15, .3]]

		, 'painted-badlands' => [
			'height' => .30
			, 'density' => 10.
			, 'width' => .03
			, 'root' => .96
			, 'tip' => [1.3, 1.25, 1.]
			, 'slope' => [.02, .05]
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
		out vec4 vRoad;
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
			vRoad = vec4(0.);
			if(marked) {
				vRoad = vec4(road.x, roadStep(road, cell, ivec2(1, 0))/uGridStep, roadStep(road, cell, ivec2(0, 1))/uGridStep, 1.);
			}
			float cover = road.y < 4. ? 1. : 0.;
			cover *= 1. - smoothstep(uReach.y, uReach.x, distance(xz, uEye.xz));
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
		in vec4 vRoad; // signed distance from a road's centerline in half-widths, its change per meter along X and Z, weight
		uniform vec3 uEye;
		uniform float uWaterLevel;
		uniform vec2 uView; // radians per pixel, seconds
		uniform int uSteps;
		// REGIONS
		out vec4 color;
		uint bladeHash(ivec2 c) {
			uint h = uint(c.x)*0x9E3779B1u ^ uint(c.y)*0x85EBCA77u;
			h ^= h>>15; h *= 0x2C1B3C6Du; h ^= h>>12;
			return h;
		}
		void main() {
			vec3 normal = normalize(vNormal);
			float steep = 1. - normal.y;
			float open = vCover;
			vec4 kind; // cells per meter, half width at the foot, lean as a share of height, tallest blade
			vec4 shade; // light left between the blades, slope where grass thins and ends, tip sway
			vec3 tip;
			float cover;
			if(uRegions < 2) {
				kind = uBlades[0];
				shade = uShades[0];
				tip = uTips[0];
				cover = open*heightBand(0, vGround)*(1. - smoothstep(shade.y, shade.z, steep));
			} else {
				// Where landscapes meet, their grasses merge. Each gives its height, shape and color in proportion to
				// how much of it grows here.
				vec3 weight = regionWeights(vWorld.xz);
				float total = 0., most = 0.;
				kind = vec4(0.);
				shade = vec4(0.);
				tip = vec3(0.);
				for(int r = 0; r < uRegions; r++) {
					vec4 blades = uBlades[r], tones = uShades[r];
					float share = weight[r]*blades.w*heightBand(r, vGround)*(1. - smoothstep(tones.y, tones.z, steep));
					total += share;
					kind.yzw += share*blades.yzw;
					shade.xw += share*tones.xw;
					tip += share*uTips[r];
					// A lattice cannot blend, so it comes from whichever grass there is more of.
					if(share > most) { most = share; kind.x = blades.x; }
				}
				if(total <= .0001) discard;
				kind.yzw /= total;
				shade.xw /= total;
				tip /= total;
				cover = open*total/kind.w;
			}
			float tallest = kind.w;
			if(tallest <= 0. || cover <= .01) discard;
			// Grass grows in clumps a few meters across.
			float clump = .75 + .25*sin(vWorld.x*.83 + 1.7*sin(vWorld.z*.61))*sin(vWorld.z*.97 + 1.3*sin(vWorld.x*.53));
			float top = max(vTop, .01); // height of this surface above the ground
			// The walk starts where the tallest blade here could end. Starting at the surface would spend most of its
			// segments above short grass and leave the rest to chance.
			float start = min(tallest*cover, top);
			float full = start*clump; // tallest blade here
			vec3 toEye = vWorld-uEye;
			float range = length(toEye);
			vec3 ray = toEye/range;
			// The ray's run across the ground from this surface down to the slope under it: first to the blade tops,
			// then through the grass.
			vec2 run = ray.xz*(top*normal.y/max(-dot(ray, normal), .05));
			// Near a road, the distance from its centerline at any point close by. Pavement ends one half-width out.
			bool roadside = vRoad.w > .0001;
			float lateral = roadside ? vRoad.x/vRoad.w : 9.;
			vec2 grade = roadside ? vRoad.yz/vRoad.w : vec2(0.);
			// The pass multiplies the ground the pixel shows, so a blade in front of pavement would only darken it.
			if(roadside && abs(lateral + dot(grade, run)) < 1.03) discard;
			vec2 enter = vWorld.xz + run*(1. - start/top);
			run *= start/top;
			// Neighbors lean the same way and bend more toward the tip. Gusts push the tips further.
			float turn = 2.4*sin(vWorld.x*.071 + 1.3*sin(vWorld.z*.053)) + 1.9*sin(vWorld.z*.067);
			float gust = sin(uView.y*1.7 + dot(vWorld.xz, vec2(.83,.55))/9.)
				+ .5*sin(uView.y*2.9 + dot(vWorld.xz, vec2(-.41,.91))/3.33);
			vec2 lean = vec2(cos(turn), sin(turn))*kind.z + vec2(.8,.6)*shade.w*gust/max(tallest, .05);
			// A blade is never drawn narrower than most of a pixel, so distant grass keeps its cover without shimmering.
			float halfWidth = max(kind.y, .7*range*uView.x);
			// Start each ray at a different depth. Neighboring pixels then find a blade at different heights, which joins
			// it into one stroke instead of a piece per segment.
			float offset = fract(52.9829189*fract(dot(gl_FragCoord.xy, vec2(.06711056,.00583715))));
			vec3 light = vec3(mix(1., shade.x, cover));
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
				if(roadside) tall *= smoothstep(1.03, 1.14, abs(lateral + dot(grade, middle - vWorld.xz)));
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
						light = mix(light, blade, min(1., cover*4.));
						break;
					}
				}
				h0 = h1;
				at0 = at1;
			}
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
	 * Work out how far from the eye the grass can be drawn inside the terrain's finest grid.
	 * @param array $window Minimum X and Z, then maximum X and Z, of the grid in meters.
	 * @param array $eye Eye position.
	 * @return float Radius in meters, zero when the grid leaves too little room around the eye.
	 */
	public static function radius(array $window, array $eye): float
	{
		$room = min($eye[0] - $window[0], $window[2] - $eye[0], $eye[2] - $window[1], $window[3] - $eye[2]);
		$radius = min(self::REACH, $room);

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

		// Draw on as many grids as it takes to reach the full distance, or as many as are resident.
		$eye = $camera->position;
		$radius = 0.;
		$grids = 0;

		while($grids < self::GRIDS && $radius < self::REACH)
		{
			$window = $scene->groundPatchWindow($grids);

			if($window === null) break;

			$radius = max($radius, self::radius($window, $eye));
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
	 * Release the program.
	 * @return void Leaves the next draw to compile it again.
	 */
	public function close(): void
	{
		$this->program?->close();
		$this->program = null;
	}
}
