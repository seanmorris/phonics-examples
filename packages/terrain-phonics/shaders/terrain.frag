#version 300 es
precision highp float;
precision highp int;
// ATMOSPHERE
// OPTICS
// SPOT LIGHTS
// LAYERS
in vec3 vRelative, vWorld, vNormal;
in vec3 vPaint0, vPaint1;
in vec3 vRoadOffset;
uniform vec3 uEye;
uniform float uWaterLevel;
uniform highp sampler2D uTerrainShadowMap;
uniform vec2 uShadowHeightRange;
uniform vec2 uShadowReceiverRange;
uniform float uCraft;
uniform float uTerrainShadowExtent;
uniform int uTerrainShadowReady;
uniform vec4 uSlopeBlend; // Start and end slopes of cover to rock, then of snow fading off steep ground.
uniform vec4 uHeightBlend; // Sand start and end above the water, then alpine rock start and end from the snowline.
uniform vec2 uSnowline; // Mean snowline and the height over which snow becomes complete.
uniform float uMacroVariation;
uniform vec2 uMountainZones; // Treeline and the foot of the tundra, in meters from the snowline.
uniform vec3 uMountainAmounts; // Talus, stain and lichen.
out vec4 color;
float mountainShadow(vec3 position)
{
	if(uSunDirection.y <= 0.0) return 0.0;
	if(uTerrainShadowReady == 0) return 1.0;
	vec2 uv = position.xz / uTerrainShadowExtent + 0.5;
	if(any(lessThan(uv, vec2(0.0))) || any(greaterThan(uv, vec2(1.0)))) return 1.0;
	vec3 sampleMap = texture(uTerrainShadowMap, uv).rgb;
	float ceiling = uShadowHeightRange.x + uShadowHeightRange.y * dot(sampleMap.gb,vec2(256.,1.)/257.);
	float ledge = smoothstep(-max(2.,.0001*uTerrainShadowExtent),0.,position.y+3.-ceiling);
	if(uCraft > .5 || uShadowReceiverRange.y <= 0.) return ledge;
	// At distance, coarse mesh triangles can cut straight through a cliff. The overview
	// compares caster and receiver on the same fixed terrain surface before filtering.
	vec2 distanceToEye = abs(position.xz-uEye.xz);
	return mix(ledge,sampleMap.r,smoothstep(uShadowReceiverRange.x,uShadowReceiverRange.y,max(distanceToEye.x,distanceToEye.y)));
}
#ifdef SURFACE_LAYERS
uniform highp sampler2D uShadowTriangles;
uniform vec3 uShadowGrid;

float localSunlight(vec2 world, float footprint, float broad)
{
	if(uShadowGrid.z <= 0. || uCraft > .5 || broad <= 0.) return broad;
	vec2 grid = (world-uShadowGrid.xy)/uShadowGrid.z;
	// The cache moves in blocks of at most eight cells. Its inner 28-cell radius is
	// resident on both sides of a snap. Anchor the blend to the moving eye within it.
	vec2 offset = abs(world-uEye.xz)/uShadowGrid.z;
	float reach = max(offset.x,offset.y);
	if(reach >= 28. || any(lessThan(grid,vec2(0.))) || any(greaterThanEqual(grid,vec2(64.)))) return broad;
	ivec2 cell = ivec2(floor(grid));
	vec2 f = fract(grid);
	bool upper = f.x+f.y > 1.;
	if(upper) f = 1.-f;
	vec4 record = texelFetch(uShadowTriangles,ivec2(2*cell.x+int(upper),cell.y),0);
	int kind = int(round(255.*record.r));
	float shade = float(kind);
	if(kind >= 2)
	{
		vec3 w = vec3(1.-f.x-f.y,f);
		vec3 cut = max(record.gba,vec3(1./255.));
		float side, area;
		bool singleLit;
		if(kind < 8)
		{
			int i = (kind-2)%3;
			side = 1.-w[(i+1)%3]/cut.x-w[(i+2)%3]/cut.y;
			area = cut.x*cut.y;
			singleLit = kind >= 5;
		}
		else
		{
			int lo = w.x <= min(w.y,w.z) ? 0 : (w.y <= w.z ? 1 : 2);
			int j = (lo+1)%3, k = (lo+2)%3;
			side = 1.-(w[j]-w[lo])/cut[j]-(w[k]-w[lo])/cut[k];
			area = dot(cut,cut.yzx)/3.;
			singleLit = kind == 9;
		}
		float pixel = footprint/uShadowGrid.z;
		float aa = clamp(pixel,.035,.4) / max(.05,min(cut.x,cut.y));
		shade = mix(smoothstep(-aa,aa,side),area,smoothstep(.15,.75,pixel));
		if(!singleLit) shade = 1.-shade;
	}
	return mix(min(broad,shade),broad,smoothstep(14.,28.,reach));
}
#endif
float grain(vec2 p)
{
	return sin(p.x * 1.73 + sin(p.y * 2.1)) * sin(p.y * 1.43 + sin(p.x * 1.9));
}
// MATERIAL NOISE
// MATERIAL STACK
#ifdef MATERIAL_REFLECTION
vec3 dielectricReflection(vec3 diffuse,vec3 normal,vec2 finish,float painted,vec3 direct,float shadow)
{
	vec3 view=normalize(-vRelative);
	normal=normalize(normal+view*max(0.,.001-dot(normal,view)));
	float nv=clamp(dot(normal,view),0.,1.);
	vec3 ray=reflect(-view,normal);
	// Use the geometric normal for the pixel cone. The bump normal already contains screen derivatives.
	vec3 geometricRay=reflect(-view,normalize(vNormal));
	float pixelCone=max(length(dFdx(geometricRay)),length(dFdy(geometricRay)));
	float cover=finish.x*(1.-painted)*smoothstep(-.1,.2,vWorld.y-uWaterLevel);
	if(cover<=0.) return diffuse;
	float rough=finish.y/max(finish.x,.00001);
	// Air to ice has an index ratio near 1.31, giving 0.018 normal-incidence reflectance.
	float fresnel=.018+(1.-.018)*(1.-rough)*pow(1.-nv,5.);
	vec3 broadRay=normalize(mix(ray,normal,.65*rough));
	vec3 upper=normalize(vec3(broadRay.x,max(.003,broadRay.y),broadRay.z));
	float altitude=max(0.,vWorld.y);
	vec3 sky=skyRadiance(upper,altitude,0.,false);
	float horizon=smoothstep(-.05-.45*rough,.03+.45*rough,broadRay.y);
	vec3 surface=mix(diffuse,mix(diffuse,sky,horizon),cover*fresnel);
	float nl=dot(normal,uSunDirection);
	if(shadow<=0. || nl<=0. || uSunDirection.y<=0.) return surface;
	// Smooth ice resolves the solar disc. Frost spreads the same directional light through a GGX lobe.
	float frosted=smoothstep(.006,.035,rough);
	vec3 solar=vec3(0.);
	if(frosted<1.) {
		vec3 transmittance=direct*(TERRAIN_PI/max(.0001,uSunIntensity));
		solar=(1.-frosted)*fresnel*terrainSolarDisk(ray,altitude,transmittance,pixelCone);
	}
	if(frosted>0.) {
		vec3 halfVector=normalize(view+uSunDirection);
		float nh=clamp(dot(normal,halfVector),0.,1.);
		float vh=clamp(dot(view,halfVector),0.,1.);
		float a2=min(1.,rough*rough+.25*(pixelCone*pixelCone+uAngularRadius*uAngularRadius));
		float den=(1.-nh*nh)+nh*nh*a2;
		float distribution=a2/(den*den);
		float visibility=.5/max(.0001,nl*sqrt(a2+(1.-a2)*nv*nv)+nv*sqrt(a2+(1.-a2)*nl*nl));
		solar+=frosted*direct*nl*distribution*visibility*(.018+.982*pow(1.-vh,5.));
	}
	return surface+cover*shadow*solar;
}
#endif
uniform float uPlayHalf;
uniform vec3 uPaintColor[5], uMaterialBounce;
void main()
{
	if(uPlayHalf>0. && max(abs(vWorld.x),abs(vWorld.z))>uPlayHalf) discard;
	vec3 n = normalize(vNormal);
	vec3 weights = pow(abs(n), vec3(4.0)); weights /= dot(weights, vec3(1.0));
	float detail = dot(weights, vec3(grain(vWorld.yz * 0.3), grain(vWorld.xz * 0.3), grain(vWorld.xy * 0.3)));
	float footprint = max(length(dFdx(vWorld)), length(dFdy(vWorld)));
	// Only uniform pavement can skip the underlying palette. Triangles that blend a
	// shoulder still evaluate its noise and derivatives all the way to the road edge.
	float roadGradient = fwidth(vPaint1.y);
	bool pavement = vPaint1.y >= .999999 && roadGradient <= .000001;
	float macro = pavement ? 0. : landVariation(vWorld.xz, footprint) * uMacroVariation;
	vec3 lightingNormal=n;
	vec3 albedo=uPaintColor[4];
	#ifdef MATERIAL_REFLECTION
	vec2 finish=vec2(0.);
	if(!pavement) albedo=materialSurface(vWorld,n,vRelative,footprint,uWaterLevel,macro,lightingNormal,finish);
	#else
	if(!pavement) albedo=materialSurface(vWorld,n,vRelative,footprint,uWaterLevel,macro,lightingNormal);
	#endif
	// Blend material weights, never categorical identifiers: a rock/road boundary must not become snow.
	float painted = clamp(dot(vPaint0 + vPaint1, vec3(1.0)), 0.0, 1.0);
	vec3 craft = mix(vec3(0.06,0.12,0.17), vec3(0.95,0.28,0.03), step(0.15,vWorld.y - uWaterLevel));
	albedo = albedo*(1.-painted) + uPaintColor[0]*vPaint0.x + uPaintColor[1]*vPaint0.y + uPaintColor[2]*vPaint0.z
		+ uPaintColor[3]*vPaint1.x + uPaintColor[4]*vPaint1.y + craft*vPaint1.z;
	// Filter a 20 cm painted stripe in world meters, including at grazing angles.
	vec2 lineOffset=vRoadOffset.xy/max(.001,vRoadOffset.z);
	float pixel=max(.015,max(length(dFdx(lineOffset)),length(dFdy(lineOffset))));
	float line=clamp((.10+.5*pixel-length(lineOffset))/pixel,0.,min(1.,.20/pixel));
	albedo=mix(albedo,vec3(.72,.47,.09),line*smoothstep(.65,.98,vRoadOffset.z)*vPaint1.y*(1.-smoothstep(1.,5.,footprint)));
	albedo *= 1.0 + 0.07 * detail * (1.0 - smoothstep(1.0,8.0,footprint));
	vec3 direct = terrainSunlight(max(0.0,vWorld.y)) / 3.14159265;
	float facing = max(0.0,dot(mix(lightingNormal,n,painted),uSunDirection));
	float shadow = mountainShadow(vWorld);
	#ifdef SURFACE_LAYERS
	if(facing > 0. && uSunDirection.y > 0.)
	{
		shadow = localSunlight(vWorld.xz,footprint,shadow);
	}
	#endif
	// Approximate diffuse sky fill follows solar irradiance and fades through twilight.
	float illumination = uSunIntensity / 20.0 * smoothstep(-0.1,0.05,uSunDirection.y);
	vec3 ambient = vec3(0.08,0.13,0.20) * (0.35 + 0.65 * max(0.0,n.y)) * illumination;
	ambient += uMaterialBounce * illumination * (1.0-max(0.,n.y));
	vec3 lit = albedo * (ambient + direct * facing * shadow);
	lit += terrainNightMaterial(albedo, mix(lightingNormal,n,painted), uNightAmbient);
	lit += albedo / 3.14159265 * terrainSpotLight(vWorld, mix(lightingNormal,n,painted), uWaterLevel, uWaterOptics.z);
	#ifdef MATERIAL_REFLECTION
	lit=dielectricReflection(lit,lightingNormal,finish,painted,direct,shadow);
	#endif
	lit = waterColumnLight(lit, vRelative, uEye.y, uWaterLevel,
		vec3(illumination) + uNightAmbient + terrainSpotWaterColumn(uEye, vWorld, uWaterLevel));
	vec3 aerial = terrainAtmosphere(normalize(vRelative), uEye.y, length(vRelative), lit);
	color = vec4(terrainToneMap(aerial),1.0);
}
