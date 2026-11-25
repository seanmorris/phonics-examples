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
		'plain' => 'vec3(1.+.16*macro,1.+.12*macro,1.+.05*macro)'
		, 'meadow' => 'vec3((1.+.5*tuft)*meadowShade)'
		, 'shrubs' => 'vec3(mix(1.,crowns,.6)*meadowShade)'
		, 'tundra' => 'vec3((1.+.3*clump+.4*tuft+.3*rill)*groundShade)'
		, 'lowland' => 'vec3((1.+.2*blotch+.3*tuft)*groundShade)'
		, 'crowns' => 'vec3(crowns*(1.+.3*blotch+.3*clump)*groundShade)'
		, 'talus' => 'vec3(blocks*boulders*(1.+.15*blotch))'
		, 'blocks' => 'vec3(blocks*boulders)'
		, 'joints' => 'jointTint'
		, 'slab-tone' => 'vec3(tone)'
		, 'lichen' => 'lichenTint'
		, 'sand' => 'vec3(1.-.16*stipple)'
		, 'snow' => 'vec3(1.-.35*stipple)'
	];
	private const RELIEF = [
		'facets'=>'facet', 'crowns'=>'vec3(offset.x,0.,offset.y)*.5*crownRelief*cellFade',
		'blocks'=>'blockTilt',
	];

	/** A joined view uses the same layer evaluators with a separate uniform-block record per region. */
	public static function assemble(string $source, SurfaceLayers|array $stack, bool $chart = false, bool $clipmap = false): string
	{
		$path = dirname(__DIR__).'/shaders/';
		$stacks = is_array($stack) ? $stack : [$stack];
		$joined = count($stacks) > 1;
		$features = "#define MATERIAL_STACK\n";
		if($clipmap) $features .= "#define SURFACE_LAYERS\n";
		if(!$chart && in_array(true,array_column($stacks,'reflective'),true)) $features .= "#define MATERIAL_REFLECTION\n";
		$layers = file_get_contents($path.'surface-layers.glsl');
		$noise = file_get_contents($path.'material-noise.glsl');
		if(!$joined) {
			$patterns = self::patternFlags($stacks[0]);
			return str_replace(['// REGIONS','// LAYERS','// MATERIAL NOISE','// MATERIAL STACK'], [
				file_get_contents($path.'joined-material.glsl'), $features.implode("\n",$patterns)."\n".$layers,
				$noise, self::compose($stacks[0],$chart),
			],$source);
		}

		// Each evaluator has a constant palette address and only its own pattern branches.
		// Qualify file-scope GLSL declarations when including the material library more than once.
		$library = $layers."\n".$noise;
		preg_match_all('/^(?:const\h+)?\w+\h+(\w+)\h*[(=]/m',$library,$declared);
		$symbols = array_unique($declared[1]);
		$functions = $calls = [];
		foreach($stacks as $i=>$surface) {
			$prefix = $i === 0 ? '' : 'region'.$i.'_';
			$patterns = self::patternFlags($surface);
			$defines = ["#define materialRegion $i",...$patterns];
			$undefines = ['#undef materialRegion'];
			foreach(array_keys($patterns) as $flag) $undefines[] = "#undef $flag";
			if($i > 0) foreach($symbols as $symbol) {
				$defines[] = "#define $symbol $prefix$symbol";
				$undefines[] = "#undef $symbol";
			}
			$name = $prefix.'materialSurface';
			$plain = $prefix . 'materialTint';
			$functions[] = implode("\n", $defines) . "\n" . $library . "\n" . self::compose($surface, $chart, $name)
				. "\n" . self::tint($surface, $surface->reflective && !$chart, $plain) . "\n"
				. implode("\n", $undefines);
			$args = 'local,normal,relative,footprint,water,macro,detailNormal';
			$plainArgs = 'local,normal,water';

			if($surface->reflective && !$chart)
			{
				$args .= ',detailFinish';
				$plainArgs .= ',regionFinish';
			}

			// Only the leading landscape at a pixel pays for its whole shader. The others add a plain color.
			$calls[] = <<<GLSL
				if(weights[$i] > 0.) {
					bounce += weights[$i] * palettes[$i].bounce.rgb;
					if(!pavement) {
						vec4 origin = palettes[$i].originAndVariation;
						vec3 local = world - origin.xyz;
						float water = uWaterLevel - origin.y;
						vec3 pigment = vec3(0.), regionNormal = normal;
						vec2 regionFinish = vec2(0.);
						if(detail[$i] < 1.) pigment = $plain($plainArgs);
						if(detail[$i] > 0.) {
							#ifdef WORLD_MATERIAL_FIELDS
							if(fieldResident) fieldSample = textureLod(uMaterialFields,vec3(fieldUV,$i.),0.);
							float macro = (fieldResident ? {$prefix}filteredVariation(fieldSample.rgb,footprint)
								: {$prefix}landVariation(local.xz,footprint)) * origin.w;
							#else
							float macro = {$prefix}landVariation(local.xz,footprint) * origin.w;
							#endif
							vec3 detailNormal = normal;
							vec2 detailFinish = vec2(0.);
							pigment = mix(pigment,$name($args),detail[$i]);
							regionNormal = mix(normal,detailNormal,detail[$i]);
							regionFinish = mix(regionFinish,detailFinish,detail[$i]);
						}
						albedo += weights[$i] * pigment;
						litNormal += weights[$i] * regionNormal;
						finish += weights[$i] * regionFinish;
					}
				}
				GLSL;
		}
		$functions[] = str_replace('// SAMPLE REGIONS',implode("\n\t",$calls),file_get_contents($path.'journey-surface.glsl'));
		$features .= '#define JOURNEY_REGIONS '.count($stacks)."\n".file_get_contents($path.'journey-palette.glsl');
		return str_replace(['// REGIONS','// LAYERS','// MATERIAL NOISE','// MATERIAL STACK'], [
			'', $features, '', implode("\n",$functions),
		],$source);
	}

	private static function patternFlags(SurfaceLayers $surface): array
	{
		$flags = [];
		foreach(array_unique(array_column($surface->layers,'pattern')) as $pattern) {
			$flag = 'PATTERN_'.strtoupper(str_replace('-','_',$pattern));
			$flags[$flag] = "#define $flag";
		}
		return $flags;
	}

	/**
	 * A landscape's plain color at a point, from the height and slope bands of its layers alone. It skips noise
	 * fields, patterns, relief and the noise on band edges, so it costs a few comparisons a layer. A journey uses
	 * it for every landscape but the leading one at a pixel. Layers masked by a noise field are left out.
	 * @param SurfaceLayers $stack Layers of one landscape.
	 * @param bool $finish Whether the function also reports the layers' finish.
	 * @param string $name GLSL function name.
	 * @return string GLSL source of the function.
	 */
	private static function tint(SurfaceLayers $stack, bool $finish, string $name): string
	{
		$glsl = [
			"vec3 $name(vec3 pos,vec3 normal,float water" . ($finish ? ',out vec2 finish' : '') . '){'
			, 'float slope=1.-max(0.,normal.y), bed=pos.y-dot(pos.xz,vec2(.006,-.003)), masks[16];'
			, 'vec3 result=vec3(.18), section=vec3(0.); float sectionSum=0.;'
		];

		if($finish) $glsl[] = 'vec2 sectionFinish=vec2(0.); finish=vec2(0.);';

		foreach($stack->layers as $i => $row)
		{
			$section = $stack->section($i);
			$close = '';

			if($section === 2)
			{
				$close = 'if(sectionSum>0.) { result=mix(result,section/sectionSum,sectionCover);'
					. ($finish ? 'finish=mix(finish,sectionFinish/sectionSum,sectionCover);' : '')
					. '} sectionSum=0.; section=vec3(0.);' . ($finish ? 'sectionFinish=vec2(0.);' : '');
			}

			if($row['channel'] === 'relief' || $row['mask'] !== 'all')
			{
				$glsl[] = "masks[$i]=0.; { float sectionCover=0.; $close }";
				continue;
			}

			$glsl[] =
				"{ const int i=$i; vec4 effect=uLayerEffect[i]; float visible=step(0.,effect.w), edge=1., coverage=1.;";

			// The bands of materialRange() without the noise on their edges. A repeating band gives its share of
			// a period.
			if($i > 0 && $stack->restricted($i))
			$glsl[] = 'vec4 r=uLayerRange[i],b=uLayerBlend[i],m=uLayerMode[i];
				float h=(m.y==2. ? bed : pos.y-(m.y==1. ? water : 0.)), sf=max(.00001,b.y), fade=max(.0001,b.x);
				coverage=smoothstep(r.z-sf,r.z,slope)*(1.-smoothstep(r.w,r.w+sf,slope));
				edge=m.z>0. ? clamp((r.y-r.x)/m.z,0.,1.) : smoothstep(r.x-fade,r.x,h)*(1.-smoothstep(r.y,r.y+fade,h));';

			if($i > 0 && $row['parent'] >= 0) $glsl[] = 'coverage*=masks[' . $row['parent'] . '];';

			$glsl[] = $i === 0
				? 'float amount=visible; masks[i]=1.;'
				: 'coverage*=effect.z; float amount=edge*coverage; masks[i]=amount; amount*=visible;';

			// A pattern moves a layer part of the way to its second color. Half its strength stands in for it.
			$glsl[] = $row['pattern'] === 'none'
				? 'vec3 shade=uLayerColor[i];'
				: 'vec3 shade=mix(uLayerColor[i],uLayerPatternColor[i],.5*uLayerPattern[i].y);';

			if($i === 0)
			{
				$glsl[] = 'result=mix(vec3(.18),shade,visible);' . ($finish ? 'finish=uLayerFinish[i]*visible;' : '');
			} elseif(!$section)
			{
				$glsl[] = 'result=mix(result,shade,amount);'
					. ($finish ? 'finish=mix(finish,uLayerFinish[i],amount);' : '');
			} else
			{
				$glsl[] = 'section+=mix(result,shade,visible)*edge; sectionSum+=edge; float sectionCover=coverage;'
					. ($finish ? 'sectionFinish+=mix(finish,uLayerFinish[i],visible)*edge;' : '') . $close;
			}

			$glsl[] = '}';
		}

		$glsl[] = 'return result; }';

		return implode("\n", $glsl);
	}

	private static function compose(SurfaceLayers $stack, bool $chart, string $name = 'materialSurface'): string
	{
		$fields = false;
		$reflective = $stack->reflective && !$chart;
		$advanced = $reflective;
		foreach($stack->layers as $row) {
			$fields = $fields || $row['mask'] !== 'all' || $row['field'] !== 'plain'
				|| (!$chart && !in_array($row['relief'],['pattern','none'],true));
			$advanced = $advanced || $fields || $row['channel'] !== 'color';
		}

		$head =
			"vec3 $name(vec3 pos,vec3 normal,vec3 relative,float footprint,float water,float macro,inout vec3 litNormal"
			. ($reflective ? ',out vec2 finish' : '') . '){';

		if(!$advanced)
		return $head . 'return layerSurface(pos,normal,footprint,water)*vec3(1.+.16*macro,1.+.12*macro,1.+.05*macro);}';

		$glsl = [$head, 'float slope=1.-max(0.,normal.y);'];
		if($fields) {
			$glsl[] = 'vec3 weights=pow(abs(normal),vec3(4.)); weights/=dot(weights,vec3(1.));';
			$glsl[] = 'float snowline=uSnowline.x+25.*macro; float stipple=stippleGrain(pos,1.6,1.,footprint);';
			$glsl[] = file_get_contents(dirname(__DIR__).'/shaders/material-fields.glsl');
		}
		$glsl[] = 'float bed=pos.y-dot(pos.xz,vec2(.006,-.003)), bedPixel=fwidth(bed), masks[16];';
		$glsl[] = 'vec3 result=vec3(0.), delta=vec3(0.), section=vec3(0.); float sectionSum=0.;';
		if($reflective) $glsl[] = 'vec2 sectionFinish=vec2(0.);';
		foreach($stack->layers as $i=>$row) {
			$glsl[] = "{ const int i=$i; vec4 effect=uLayerEffect[i]; float visible=step(0.,effect.w);";
			$mask = self::MASK[$row['mask']];
			$glsl[] = 'float edge=1., coverage=1.;';
			if($i > 0 && $stack->restricted($i)) $glsl[] = 'materialRange(i,pos,slope,footprint,water,bed,edge,coverage);';
			if($i > 0 && $row['parent'] >= 0) $glsl[] = 'coverage*=masks['.$row['parent'].'];';
			$section = $stack->section($i);
			$glsl[] = $i === 0 ? 'float amount=visible; masks[i]=1.;'
				: "coverage*=($mask)*effect.z; float amount=edge*coverage; masks[i]=amount; amount*=visible;";
			$pattern = match(true) {
				$fields && $row['pattern'] === 'jointed' => 'jointedPigment(pos,normal,footprint,uLayerPattern[i],sizes.zw,breakup)',
				$row['pattern'] !== 'none' => 'layerPatternWeight(i,pos,normal,footprint,bed,bedPixel)',
				default => '0.',
			};
			// Hidden pigment has no effect. A relief layer may still need its neighbors' pattern derivatives.
			if($i && $pattern !== '0.' && $row['channel'] === 'color')
				$pattern = ($section ? 'edge' : 'amount').'>0. ? '.$pattern.' : 0.';
			$glsl[] = "float pattern=$pattern;";
			if($row['channel'] !== 'relief') {
				$base = $row['field'] === 'shrubs' ? 'mix(uLayerColor[i],uLayerPatternColor[i],.55)' : 'uLayerColor[i]';
				$tint = self::SHADE[$row['field']];
				$glsl[] = "vec3 shade=mix($base,uLayerPatternColor[i],pattern)*$tint;";
				if($i === 0) $glsl[] = 'result=mix(vec3(.18),shade,visible);'.($reflective ? 'finish=uLayerFinish[i]*visible;' : '');
				elseif(!$section) {
					$glsl[] = 'result=mix(result,shade,amount);';
					if($reflective) $glsl[] = 'finish=mix(finish,uLayerFinish[i],amount);';
				} else {
					$glsl[] = 'section+=mix(result,shade,visible)*edge; sectionSum+=edge;';
					if($reflective) $glsl[] = 'sectionFinish+=mix(finish,uLayerFinish[i],visible)*edge;';
					if($section === 2) {
						$glsl[] = 'if(sectionSum>0.) { result=mix(result,section/sectionSum,coverage);';
						if($reflective) $glsl[] = 'finish=mix(finish,sectionFinish/sectionSum,coverage);';
						$glsl[] = '} sectionSum=0.; section=vec3(0.);';
						if($reflective) $glsl[] = 'sectionFinish=vec2(0.);';
					}
				}
			}
			if(!$chart) {
				$glsl[] = 'delta*=1.-amount*effect.y;';
				if($row['channel'] !== 'color') {
					$kind = $row['relief'];
					if($kind === 'pattern') $glsl[] = 'delta+=amount*effect.x*patternGradient(pos,normal,pattern);';
					elseif($kind === 'ribs') $glsl[] = 'if(visible>0.) { vec3 n=normalize(normal+delta); vec3 tangent=lift-n*dot(n,lift);
						delta=normalize(n-amount*effect.x*relief*tangent/(1.+length(tangent)))-normal; }';
					elseif($kind === 'rib-tilt') $glsl[] = 'if(visible>0.) { vec3 n=normalize(normal+delta);
						vec3 t=sizes.x*gullySlope; t-=n*dot(n,t);
						float flankWeight=4.*flank*(1.-flank)*sign(gullyNoise);
						delta=normalize(n-amount*effect.x*relief*upright*ribRelief*flankWeight*t/max(1.,length(t)))-normal; }';
					elseif(isset(self::RELIEF[$kind])) $glsl[] = 'delta+=('.self::RELIEF[$kind].')*relief*amount*effect.x;';
				}
			}
			$glsl[] = '}';
		}
		$glsl[] = 'litNormal=normalize(normal+delta); return result; }';
		return implode("\n",$glsl);
	}
}
