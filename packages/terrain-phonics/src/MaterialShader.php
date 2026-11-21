<?php
declare(strict_types=1);
namespace TerrainPhonics;

/** Assemble trusted GLSL expressions from a validated material stack. User text never enters a shader. */
final class MaterialShader
{
	private const MASK = [
		'all'=>'1.', 'shrubs'=>'shrubs', 'tundra'=>'tundraCover', 'lowland'=>'lowland*level',
		'trees'=>'trees', 'scree'=>'scree', 'blocks'=>'blockCover', 'slabs'=>'graniteCover',
		'facet-cover'=>'bare*steep', 'block-cover'=>'scree+bare*(1.-steep)', 'crown-cover'=>'trees',
		'lichen'=>'lichen', 'joints'=>'joints', 'gullies'=>'1.-smoothstep(.03,.25,abs(gully))', 'crowns'=>'crown',
		'stains'=>'stainCover', 'shore'=>'shore', 'snow'=>'snow',
	];
	private const SHADE = [
		'plain'=>'vec3(1.+.07*macro)', 'meadow'=>'vec3((1.+.5*tuft)*meadowShade)',
		'shrubs'=>'vec3(mix(1.,crowns,.6)*meadowShade)', 'tundra'=>'vec3((1.+.3*clump+.4*tuft+.3*rill)*groundShade)',
		'lowland'=>'vec3((1.+.2*blotch+.3*tuft)*groundShade)', 'crowns'=>'vec3(crowns*(1.+.3*blotch+.3*clump)*groundShade)',
		'talus'=>'vec3(blocks*boulders*(1.+.15*blotch))', 'blocks'=>'vec3(blocks*boulders)',
		'joints'=>'jointTint', 'slab-tone'=>'vec3(tone)', 'lichen'=>'lichenTint', 'sand'=>'vec3(1.-.16*stipple)', 'snow'=>'vec3(1.-.35*stipple)',
	];
	private const RELIEF = [
		'facets'=>'facet*slabFade*settled', 'crowns'=>'vec3(offset.x,0.,offset.y)*.5*crown*cellFade',
		'blocks'=>'vec3(offset.x,0.,offset.y)*.6*block*cellFade',
	];

	public static function assemble(string $source, SurfaceLayers $stack, bool $chart = false, bool $clipmap = false): string
	{
		$path = dirname(__DIR__).'/shaders/';
		$features = "#define MATERIAL_STACK\n";
		foreach(array_unique(array_column($stack->layers,'pattern')) as $pattern)
			$features .= '#define PATTERN_'.strtoupper($pattern)."\n";
		$patterns = $features.($clipmap ? "#define SURFACE_LAYERS\n" : '').file_get_contents($path.'surface-layers.glsl');
		return str_replace(['// LAYERS','// MATERIAL NOISE','// MATERIAL STACK'], [
			$patterns, file_get_contents($path.'material-noise.glsl'), self::compose($stack,$chart),
		],$source);
	}

	private static function compose(SurfaceLayers $stack, bool $chart): string
	{
		$fields = false;
		$advanced = false;
		foreach($stack->layers as $row) {
			$fields = $fields || $row['mask'] !== 'all' || $row['field'] !== 'plain'
				|| (!$chart && !in_array($row['relief'],['pattern','none'],true));
			$advanced = $advanced || $fields || $row['channel'] !== 'color';
		}
		$head = 'vec3 materialSurface(vec3 pos,vec3 normal,vec3 relative,float footprint,float water,float macro,inout vec3 litNormal){';
		if(!$advanced) return $head.'return layerSurface(pos,normal,footprint,water)*(1.+.07*macro);}';
		$glsl = [$head, 'float slope=1.-max(0.,normal.y);'];
		if($fields) {
			$glsl[] = 'vec3 weights=pow(abs(normal),vec3(4.)); weights/=dot(weights,vec3(1.));';
			$glsl[] = 'float snowline=uSnowline.x+25.*macro; float stipple=0.;';
			if(!$chart) $glsl[] = 'stipple=patternVisibility(footprint,1.6,STIPPLE_PATTERN)*projectedPattern(pos,weights,1.6,1.,vec2(73.37,19.81),STIPPLE_PATTERN);';
			$glsl[] = file_get_contents(dirname(__DIR__).'/shaders/material-fields.glsl');
		}
		$glsl[] = 'float bed=pos.y-dot(pos.xz,vec2(.006,-.003)), bedPixel=fwidth(bed), masks[16];';
		$glsl[] = 'vec3 result=vec3(0.), delta=vec3(0.), section=vec3(0.); float sectionSum=0.;';
		foreach($stack->layers as $i=>$row) {
			$glsl[] = "{ const int i=$i; vec4 effect=uLayerEffect[i], mode=uLayerMode[i];";
			$mask = self::MASK[$row['mask']];
			$glsl[] = 'float edge=1., coverage=1.;';
			if($i > 0) $glsl[] = 'if(effect.w<.5) materialRange(i,pos,slope,footprint,water,bed,edge,coverage);
				if(mode.x>=0.) coverage*=masks[int(mode.x)];';
			$glsl[] = $i === 0 ? 'float amount=1.; masks[i]=1.;'
				: "coverage*=($mask)*effect.z; float amount=edge*coverage; masks[i]=amount;";
			$pattern = $row['pattern'] !== 'none';
			$glsl[] = $pattern ? 'float pattern=layerPatternWeight(i,pos,normal,footprint,bed,bedPixel);' : 'float pattern=0.;';
			if($row['channel'] !== 'relief') {
				$base = $row['field'] === 'shrubs' ? 'mix(uLayerColor[i],uLayerPatternColor[i],.55)' : 'uLayerColor[i]';
				$tint = self::SHADE[$row['field']];
				$glsl[] = "vec3 shade=mix($base,uLayerPatternColor[i],pattern)*$tint;";
				$glsl[] = $i === 0 ? 'result=shade;' : 'if(mode.w==0.) result=mix(result,shade,amount);
					else { section+=shade*edge; sectionSum+=edge;
					if(mode.w==2.) { if(sectionSum>0.) result=mix(result,section/sectionSum,coverage); sectionSum=0.; section=vec3(0.); } }';
			}
			if(!$chart) {
				$glsl[] = 'delta*=1.-amount*effect.y;';
				if($row['channel'] !== 'color') {
					$kind = $row['relief'];
					if($kind === 'pattern') $glsl[] = 'delta+=amount*effect.x*patternGradient(pos,normal,pattern);';
					elseif($kind === 'ribs') $glsl[] = 'vec3 n=normalize(normal+delta); vec3 tangent=lift-n*dot(n,lift);
						delta=normalize(n-amount*effect.x*relief*tangent/(1.+length(tangent)))-normal;';
					elseif(isset(self::RELIEF[$kind])) $glsl[] = 'delta+=('.self::RELIEF[$kind].')*relief*amount*effect.x;';
				}
			}
			$glsl[] = '}';
		}
		$glsl[] = 'litNormal=normalize(normal+delta); return result; }';
		return implode("\n",$glsl);
	}
}
