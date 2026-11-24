<?php
declare(strict_types=1);

namespace TerrainPhonics;

use Phonics\Render\Program;

/**
 * Grass, rice and heath that stand up from the ground near the eye, drawn as stacked shells of the terrain's own
 * triangles.
 *
 * The shells never light themselves. They multiply what the terrain pass drew: the lowest shell darkens the ground to
 * the shade between the stalks, and each shell above it brightens the pixels its strands cover. A strand that reaches
 * the top shell ends brighter than the bare ground was, and a short one stays dim. The grass therefore takes the
 * terrain's sunlight, shadows, haze and tone mapping at any hour without computing them again. Multiplying gives the
 * same result in any order, so the shells need no sorting, write no depth and draw in one instanced call.
 *
 * A strand is a hash of its world cell, a pure function of position. Height tapers to nothing at the edge of the
 * drawn radius, on painted ground, on steep ground and outside the preset's height band, so the grass has no edge.
 */
final class GrassShells
{
	/**
	 * Thickness of one strand shell in meters, by quality setting. Tall grass gets more shells than short turf, so a
	 * strand looks continuous from the side at any height. Zero draws nothing.
	 */
	public const SLAB = [0., .09, .06];
	/** Fewest and most strand shells drawn above the darkening shell. */
	public const FEWEST = 3, MOST = 14;
	/** Height of the darkening shell above the terrain in meters, enough to pass the depth test from any angle. */
	private const LIFT = .02;
	/** Farthest the grass is drawn from the eye in meters, and the share of that radius at which it starts to taper. */
	private const REACH = 56., TAPER = .55;
	/** Eye height above the ground in meters beyond which strands are narrower than a pixel and the pass is skipped. */
	public const CEILING = 45.;
	/** Smallest radius worth drawing, in meters. */
	private const MINIMUM = 6.;
	/**
	 * Where grass grows and what it looks like, by surface style.
	 * - height: strand height in meters.
	 * - density: strand cells per meter. Each cell holds one strand.
	 * - radius: strand radius at its foot, as a fraction of a cell.
	 * - root: the share of the ground's light left between the stalks.
	 * - tip: how much brighter than the bare ground a full-height strand ends, per color channel.
	 * - slope: where the grass starts to thin and where it ends, as one minus the upward part of the ground normal.
	 * - above: meters above the water at which grass starts and is full.
	 * - top: absolute heights in meters at which it starts to thin and ends, or null.
	 * - wind: sway of the tips in meters.
	 */
	public const STYLES = [
		'coast' => ['height' => .30, 'density' => 7., 'tip' => [1.25, 1.35, .95], 'slope' => [.06, .16]]
		, 'limestone' => ['height' => .25, 'density' => 8., 'tip' => [1.2, 1.35, .95], 'slope' => [.06, .16]]
		, 'mountain' => ['height' => .30, 'density' => 7., 'tip' => [1.25, 1.3, .95], 'slope' => [.08, .22]]
		, 'heather-moor' => ['height' => .30, 'density' => 6., 'tip' => [1.35, 1.15, 1.25], 'slope' => [.10, .24]]
		, 'tidal-lagoon' => ['height' => .30, 'density' => 6., 'tip' => [1.3, 1.3, .95], 'above' => [1.5, 3.]]

		, 'rolling-prairie' => [
			'height' => .80
			, 'density' => 5.
			, 'tip' => [1.4, 1.3, .9]
			, 'slope' => [.12, .26]
			, 'wind' => .22
		]

		, 'river-valley' => ['height' => .50, 'density' => 6., 'tip' => [1.25, 1.4, .9], 'slope' => [.08, .2]]
		, 'wooded-foothills' => ['height' => .35, 'density' => 7., 'tip' => [1.25, 1.35, .9], 'slope' => [.06, .16]]

		, 'high-desert-steppe' => [
			'height' => .40
			, 'density' => 2.5
			, 'radius' => .30
			, 'root' => .7
			, 'tip' => [1.15, 1.2, 1.05]
			, 'slope' => [.03, .08]
		]

		, 'alaska-range' => ['height' => .25, 'density' => 5., 'tip' => [1.3, 1.25, .95], 'slope' => [.08, .2]]
		, 'chalk-headlands' => ['height' => .14, 'density' => 11., 'tip' => [1.2, 1.3, .95], 'slope' => [.05, .14]]
		, 'karst-bay' => ['height' => .50, 'density' => 5., 'tip' => [1.2, 1.4, .9], 'slope' => [.25, .45]]
		, 'tepui-highlands' => ['height' => .40, 'density' => 6., 'tip' => [1.25, 1.35, .9], 'slope' => [.15, .3]]

		, 'painted-badlands' => [
			'height' => .30
			, 'density' => 4.
			, 'radius' => .34
			, 'root' => .7
			, 'tip' => [1.3, 1.25, 1.]
			, 'slope' => [.02, .05]
		]

		, 'emerald-ridges' => ['height' => .50, 'density' => 5., 'tip' => [1.2, 1.4, .9], 'slope' => [.24, .40]]
		, 'autumn-folds' => ['height' => .40, 'density' => 6., 'tip' => [1.35, 1.3, .9], 'slope' => [.03, .06]]

		, 'black-sand-coast' => [
			'height' => .22
			, 'density' => 6.
			, 'tip' => [1.3, 1.3, .95]
			, 'slope' => [.05, .12]
			, 'above' => [2., 5.]
		]

		, 'rice-terraces' => [
			'height' => .50
			, 'density' => 7.
			, 'tip' => [1.25, 1.42, .9]
			, 'slope' => [.03, .07]
			, 'above' => [.2, .8]
			, 'top' => [80., 95.]
			, 'wind' => .10
		]

		, 'boreal-lakes' => ['height' => .20, 'density' => 7., 'tip' => [1.25, 1.3, 1.05], 'slope' => [.08, .2]]
	];
	/** Values a style inherits when it leaves them out. */
	private const DEFAULTS = [
		'radius' => .48
		, 'root' => .68
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
		uniform int uShellRows;
		uniform vec4 uShell; // strand shells, strand height, radius drawn, radius at which the taper starts
		uniform vec4 uBand; // meters above water where grass starts and is full, heights where it thins and ends
		uniform float uWaterLevel;
		out vec3 vWorld;
		out vec3 vNormal;
		out float vCover;
		out float vLayer;
		void main() {
			int row = gl_InstanceID % uShellRows, shell = gl_InstanceID / uShellRows;
			ivec2 cell = clipStitchedCell(uDrawCell + ivec2(gl_VertexID/2, row + (gl_VertexID&1)));
			float morph = clipMorph(cell);
			vec2 xz = vec2(cell)*uGridStep;
			float ground = clipHeight(cell, morph);
			// Painted cells are roads, shoulders and other dressed ground.
			float cover = texelFetch(uInk, texelAt(cell), 0).r > .001 ? 0. : 1.;
			cover *= 1. - smoothstep(uShell.w, uShell.z, distance(xz, uEye.xz));
			cover *= smoothstep(uBand.x, uBand.y, ground-uWaterLevel) * (1. - smoothstep(uBand.z, uBand.w, ground));
			vLayer = float(shell)/uShell.x;
			vCover = cover;
			vNormal = clipNormal(cell, morph);
			vWorld = vec3(xz.x, ground + LIFT + vLayer*uShell.y, xz.y);
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
		in float vLayer;
		uniform vec3 uEye;
		uniform vec4 uStrand; // cells per meter, foot radius in cells, light left between stalks, seconds
		uniform vec3 uGain; // one shell's brightening, per channel
		uniform vec2 uSlope;
		uniform vec3 uWind; // tip sway in meters along X and Z, gust wavelength in meters
		uniform vec2 uSlab; // one shell's thickness as a share of the strand height, and in meters
		out vec4 color;
		uint strandHash(ivec2 c) {
			uint h = uint(c.x)*0x9E3779B1u ^ uint(c.y)*0x85EBCA77u;
			h ^= h>>15; h *= 0x2C1B3C6Du; h ^= h>>12;
			return h;
		}
		// Whether a strand stands at this point and height. The lattice is turned so its rows never line up with the view.
		bool strand(vec2 xz, float layer, float cover) {
			vec2 p = mat2(.857,.515,-.515,.857)*xz*uStrand.x;
			vec2 base = floor(p);
			uint h = strandHash(ivec2(base));
			float tall = cover*(.35 + .65*float(h & 1023u)/1023.);
			if(layer > tall) return false;
			vec2 lean = vec2(float((h>>10) & 1023u), float((h>>20) & 1023u))/1023. - .5;
			vec2 f = p - base - .5 - .6*lean*(1. + layer);
			float r = uStrand.y*(1. - .75*layer/tall);
			return dot(f,f) < r*r;
		}
		void main() {
			float cover = vCover*(1. - smoothstep(uSlope.x, uSlope.y, 1.-normalize(vNormal).y));
			if(cover <= .01) discard;
			// The blend doubles the product, so one half leaves the ground as it was.
			if(vLayer <= 0.) { color = vec4(vec3(.5*mix(1., uStrand.z, cover)), 1.); return; }
			// Grass grows in clumps a few meters across.
			cover *= .78 + .22*sin(vWorld.x*.83 + 1.7*sin(vWorld.z*.61))*sin(vWorld.z*.97 + 1.3*sin(vWorld.x*.53));
			// Gusts roll across the field, and a strand bends more the higher up it is.
			float gust = sin(uStrand.w*1.7 + dot(vWorld.xz, vec2(.83,.55))/uWind.z)
				+ .5*sin(uStrand.w*2.9 + dot(vWorld.xz, vec2(-.41,.91))/(.37*uWind.z));
			vec2 sway = uWind.xy*gust;
			// Follow the view ray down through this shell's slab, so strands join between shells seen from the side.
			vec3 ray = normalize(vWorld-uEye);
			vec2 drift = ray.xz*(uSlab.y/max(abs(ray.y), .12));
			float reach = length(drift)*uStrand.x;
			if(reach > 1.6) drift *= 1.6/reach;
			bool hit = false;
			for(int k = 0; k < 3 && !hit; k++) {
				float s = (float(k)+.5)/3.;
				float layer = vLayer - s*uSlab.x;
				hit = strand(vWorld.xz + drift*s - sway*layer*layer, layer, cover);
			}
			if(!hit) discard;
			color = vec4(.5*uGain, 1.);
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
	 * Count the strand shells a style needs at a quality setting.
	 * @param array $style Style settings from style().
	 * @param int $quality Index into SLAB.
	 * @return int Strand shells, zero when the setting is off.
	 */
	public static function shells(array $style, int $quality): int
	{
		$slab = self::SLAB[$quality] ?? 0.;

		return $slab > 0. ? max(self::FEWEST, min(self::MOST, (int)ceil($style['height'] / $slab))) : 0;
	}

	/**
	 * Brightening applied by each strand shell, so that a strand crossing all of them ends at the tip brightness.
	 * @param array $style Style settings from style().
	 * @param int $shells Strand shells.
	 * @return array Red, green and blue factors.
	 */
	public static function gain(array $style, int $shells): array
	{
		$gain = [];

		foreach($style['tip'] as $tip) $gain[] = ($tip / $style['root']) ** (1 / $shells);

		return $gain;
	}

	/**
	 * Draw the grass over terrain that the scene has already drawn, before anything that stands on it.
	 * @param Scene $scene Scene whose resident grid supplies the triangles.
	 * @param Camera $camera Camera of the terrain pass.
	 * @param array $style Style settings from style().
	 * @param float $water Water level in meters.
	 * @param float $time Seconds, for the wind.
	 * @param int $shells Strand shells to draw, from shells().
	 * @param int $width Viewport width in pixels.
	 * @param int $height Viewport height in pixels.
	 * @param float $clearance Eye height above the ground in meters.
	 * @return bool Whether the grass was drawn.
	 */
	public function draw(
		Scene $scene
		, Camera $camera
		, array $style
		, float $water
		, float $time
		, int $shells
		, int $width
		, int $height
		, float $clearance
	): bool {
		if($shells < 1 || $clearance > self::CEILING) return false;

		$window = $scene->groundPatchWindow();

		if($window === null) return false;

		$eye = $camera->position;
		$radius = self::radius($window, $eye);

		if($radius <= 0.) return false;

		$p = $this->program ??= new Program(
			str_replace(['// GRID', 'LIFT'], [Clipmap::gridShader(), sprintf('%.3f', self::LIFT)], self::VERTEX)
			, self::FRAGMENT
		);

		$p->use();
		glUniformMatrix4fv($p->location('uViewProjection'), 1, false, $camera->viewProjection($width, $height));
		glUniform3f($p->location('uEye'), ...$eye);
		glUniform4f($p->location('uShell'), $shells, $style['height'], $radius, self::TAPER * $radius);
		glUniform2f($p->location('uSlab'), 1 / $shells, $style['height'] / $shells);

		$top = $style['top'] ?? [1e9, 2e9];

		glUniform4f($p->location('uBand'), $style['above'][0], $style['above'][1], $top[0], $top[1]);
		glUniform1f($p->location('uWaterLevel'), $water);
		glUniform2f($p->location('uSlope'), ...$style['slope']);

		glUniform4f($p->location('uStrand'), $style['density'], $style['radius'], $style['root'], fmod($time, 3600.));
		glUniform3f($p->location('uGain'), ...self::gain($style, $shells));
		glUniform3f($p->location('uWind'), .8 * $style['wind'], .6 * $style['wind'], 9.);

		// Twice the product of what is there and what the shell writes. Alpha is left alone.
		glEnable(GL_BLEND);
		glBlendFuncSeparate(GL_DST_COLOR, GL_SRC_COLOR, GL_ZERO, GL_ONE);
		glDepthMask(false);
		glDepthFunc(GL_LEQUAL);

		$drawn = $scene->drawGroundPatch(
			$p
			, $camera
			, [$eye[0] - $radius, $eye[2] - $radius, $eye[0] + $radius, $eye[2] + $radius]
			, $shells + 1
		);

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
