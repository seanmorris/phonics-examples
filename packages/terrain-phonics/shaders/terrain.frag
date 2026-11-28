#version 300 es
precision highp float;
precision highp int;
// MATERIAL PASSES
// ATMOSPHERE
// OPTICS
// SPOT LIGHTS
#ifdef READ_RIB_FIELD
uniform highp sampler2D uRibField;
#endif
#ifdef WRITE_RIB_FIELD
vec3 ribField = vec3(0.);
#endif
#ifdef WORLD_MATERIAL_FIELDS
uniform highp sampler2DArray uMaterialFields;
uniform vec4 uFieldWindow; // world minimum XZ, meters per texel, reciprocal texture width
bool fieldResident;
vec2 fieldUV;
vec4 fieldSample;
#endif
// LAYERS
in vec3 vRelative, vWorld, vNormal;
in vec3 vPaint0, vPaint1;
in vec4 vRoadMark;
in float vSplinePaint;
in vec4 vRoadArms;
// Area of the painted half of every repeating dash-and-gap pair.
float dashIntegral(float x) { return .5*floor(x)+min(fract(x),.5); }
float dashCoverage(vec2 phaseVector, float pixelCycles)
{
	// One 10-foot dash per 20-foot cycle. Beyond one cycle per pixel only the mean ink is resolvable.
	if(pixelCycles >= 1. || dot(phaseVector,phaseVector) < 1e-8) return .5;
	float phase = atan(phaseVector.y,phaseVector.x)*(16./6.28318530718);
	float width = max(pixelCycles,.002);
	float ink = (dashIntegral(phase+.5*width)-dashIntegral(phase-.5*width))/width;
	return mix(clamp(ink,0.,1.),.5,smoothstep(.5,1.,width));
}
// Fraction of a pixel covered by a stripe, including stripes narrower than one pixel.
float stripeCoverage(float distanceToLine,float halfWidth,float pixel) {
	return clamp((halfWidth+.5*pixel-abs(distanceToLine))/pixel,0.,min(1.,2.*halfWidth/pixel));
}
uniform vec3 uEye;
uniform float uWaterLevel;
uniform highp sampler2D uTerrainShadowMap;
uniform vec2 uShadowHeightRange;
uniform vec2 uShadowReceiverRange;
uniform float uCraft;
uniform float uTerrainShadowExtent;
uniform int uTerrainShadowReady;
#ifndef JOURNEY_REGIONS
uniform vec4 uSlopeBlend; // Start and end slopes of cover to rock, then of snow fading off steep ground.
uniform vec4 uHeightBlend; // Sand start and end above the water, then alpine rock start and end from the snowline.
uniform vec2 uSnowline; // Mean snowline and the height over which snow becomes complete.
uniform float uMacroVariation;
uniform vec2 uMountainZones; // Treeline and the foot of the tundra, in meters from the snowline.
uniform vec3 uMountainAmounts; // Talus, stain and lichen.
#endif
uniform vec3 uSkyAmbient; // The sky's light on level ground over pi, already scaled by the sky-light setting.
#ifdef WRITE_SURFACE_SAMPLES
layout(location=0) out vec4 color;
layout(location=1) out vec4 surfaceNormal;
#else
out vec4 color;
#endif
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
	float altitude=vWorld.y;
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
uniform vec3 uPaintColor[5];
#ifndef JOURNEY_REGIONS
uniform vec3 uMaterialBounce;
#endif
// REGIONS
void main()
{
	#ifndef JOURNEY_REGIONS
	float alpha = uCraft>.5 ? 1. : regionOpacity(vWorld.xz);
	if(alpha<=0.) discard;
	vec3 local = vWorld-uMaterialOrigin;
	#else
	const float alpha = 1.;
	#endif
	if(uPlayHalf>0. && max(abs(vWorld.x),abs(vWorld.z))>uPlayHalf) discard;
	vec3 n = normalize(vNormal);
	vec3 weights = pow(abs(n), vec3(4.0)); weights /= dot(weights, vec3(1.0));
	float detail = dot(weights, vec3(grain(vWorld.yz * 0.3), grain(vWorld.xz * 0.3), grain(vWorld.xy * 0.3)));
	// Neighboring fragments can be millimeters apart many kilometers from the origin.
	// Differentiate the camera-relative position to retain those small ground distances.
	vec3 worldDx = dFdx(vRelative), worldDy = dFdy(vRelative);
	#ifdef READ_SURFACE_SAMPLES
	nativeDepthSlope=vec2(dFdx(gl_FragCoord.w),dFdy(gl_FragCoord.w));
	#endif
	float footprint = max(length(worldDx), length(worldDy));
	float slopeMask = roadSlopeMask(cross(worldDx,worldDy));
	// Lateral coordinates are in road half-widths. Convert meters of paint into that coordinate
	// using its world-space gradient, so both narrow streets and wide circuits get the same paint.
	float markWeight=vRoadMark.w;
	float weight=max(markWeight,.0001);
	float crossTrack = dot(vRoadArms,vec4(1.))/weight;
	float outline=dot(abs(vRoadArms),vec4(1.))/weight;
	vec2 dx=worldDx.xz, dy=worldDy.xz;
	vec2 lateralPixel=vec2(dFdx(crossTrack),dFdy(crossTrack));
	// Outside a centerline crossing, differentiate the unsigned distance from
	// the vertices. Taking abs after extrapolation into GPU helper pixels can
	// otherwise drag the shoulder stripe several meters into the pavement.
	float unsignedTrack=vRoadMark.x/weight;
	vec2 unsignedPixel=vec2(dFdx(unsignedTrack),dFdy(unsignedTrack));
	vec2 outlinePixel=unsignedTrack-outline>.00001 ? lateralPixel : unsignedPixel;
	float roadEdge = splinePavement(outline,max(abs(outlinePixel.x)+abs(outlinePixel.y),.00001));
	float road = (vPaint1.y-vSplinePaint*(1.-roadEdge))*slopeMask;
	// Only uniform pavement can skip the underlying palette. Triangles that blend a
	// shoulder still evaluate its noise and derivatives all the way to the road edge.
	float roadGradient = fwidth(vPaint1.y);
	bool pavement = road >= .999999 && roadGradient <= .000001;
	#ifdef JOURNEY_REGIONS
	#ifdef WRITE_SURFACE_SAMPLES
	// Retain natural material just inside a spline shoulder for its full-resolution edge pixels.
	if(pavement && vSplinePaint>.99 && outline+4.*dot(abs(outlinePixel),vec2(1.))>=1.) pavement=false;
	#endif
	vec3 lightingNormal, bounce;
	vec2 finish;
	vec3 albedo = journeySurface(vWorld,n,vRelative,footprint,pavement,lightingNormal,finish,bounce);
	if(pavement) albedo = uPaintColor[4];
	#else
	vec3 bounce = uMaterialBounce;
	float macro = pavement ? 0. : landVariation(local.xz, footprint) * uMacroVariation;
	vec3 lightingNormal=n;
	vec3 albedo=uPaintColor[4];
	#ifdef MATERIAL_REFLECTION
	vec2 finish=vec2(0.);
	if(!pavement) albedo=materialSurface(local,n,vRelative,footprint,uWaterLevel-uMaterialOrigin.y,macro,lightingNormal,finish);
	#else
	if(!pavement) albedo=materialSurface(local,n,vRelative,footprint,uWaterLevel-uMaterialOrigin.y,macro,lightingNormal);
	#endif
	#endif
	#ifdef WRITE_SURFACE_SAMPLES
	float depthCode=round(log2(1.+1./gl_FragCoord.w)*(65535./17.));
	color=vec4(encodePigment(albedo),floor(depthCode/256.)/255.);
	surfaceNormal=vec4(packSurfaceNormal(lightingNormal),mod(depthCode,256.)/255.,pavement ? 0. : (1.+254.*n.y)/255.);
	#elif defined(WRITE_RIB_FIELD)
	// Preserve faint slopes in RGBA8 and reserve one code on either side of the range.
	color = vec4((128.+126.*ribField/(.02+abs(ribField)))/255.,1.);
	#else
	// Blend material weights, never categorical identifiers: a rock/road boundary must not become snow.
	float painted = clamp(dot(vPaint0,vec3(1.)) + vPaint1.x + road + vPaint1.z, 0.0, 1.0);
	vec3 craft = mix(vec3(0.06,0.12,0.17), vec3(0.95,0.28,0.03), step(0.15,vWorld.y - uWaterLevel));
	albedo = albedo*(1.-painted) + uPaintColor[0]*vPaint0.x + uPaintColor[1]*vPaint0.y + uPaintColor[2]*vPaint0.z
		+ uPaintColor[3]*vPaint1.x + uPaintColor[4]*road + craft*vPaint1.z;
	vec3 markingGlow=vec3(0.);
	if(markWeight > .65 && road > 0.) {
		float area=dx.x*dy.y-dx.y*dy.x;
		// This vector follows the line of constant crossTrack through this pixel, even on a bend.
		// A change of arm can flip the signed offset without crossing a
		// centerline. Its derivative must not widen the dash at the split.
		vec2 centerPixel=outline-abs(crossTrack)>.00001 ? unsignedPixel : lateralPixel;
		vec2 stripeGradient=outline>.5 ? outlinePixel : centerPixel;
		vec2 alongRoad=stripeGradient.x*dy-stripeGradient.y*dx;
		float alongLength=length(alongRoad);
		float unitsPerMeter=alongLength/max(abs(area),1e-10);
		float pixelAcross=max(.00001,abs(stripeGradient.x)+abs(stripeGradient.y));
		float line = stripeCoverage(outline,min(.20*unitsPerMeter,.35),pixelAcross);
		float edgeCenter = 1.-min(.35*unitsPerMeter,.30);
		float edge = stripeCoverage(outline-edgeCenter,min(.12*unitsPerMeter,.20),pixelAcross);
		vec2 tangent=alongRoad/max(alongLength,1e-10);
		float pixelCycles=(abs(dot(dx,tangent))+abs(dot(dy,tangent)))/6.096;
		float marked = smoothstep(.65,.98,markWeight)*smoothstep(.05,.6,vPaint1.y)*roadEdge*slopeMask;
		float centerPaint=line*dashCoverage(vRoadMark.yz,pixelCycles)*marked;
		float edgePaint=edge*marked*(1.-smoothstep(1.,5.,footprint));
		vec3 ochre=vec3(.72,.47,.09), white=vec3(.88);
		albedo=mix(mix(albedo,ochre,centerPaint),white,edgePaint);
		if(max(centerPaint,edgePaint)>0.) {
			float glow=.006*smoothstep(0.,.17364818,-uSunDirection.y);
			if(vWorld.y>uWaterLevel)
				glow+=retroreflection(vWorld,uEye,n,uSunDirection,clamp((uSunDirection.y+.10)/.22,0.,1.));
			markingGlow=mix(ochre*centerPaint,white,edgePaint)*glow;
		}
	}
	albedo *= 1.0 + 0.07 * detail * (1.0 - smoothstep(1.0,8.0,footprint));
	#ifdef TERRAIN_SOLAR_TABLE
	vec3 direct = terrainCachedSunlight(vWorld.y) / 3.14159265;
	#else
	vec3 direct = terrainSunlight(vWorld.y) / 3.14159265;
	#endif
	float facing = max(0.0,dot(mix(lightingNormal,n,painted),uSunDirection));
	float shadow = mountainShadow(vWorld);
	#ifdef SURFACE_LAYERS
	if(facing > 0. && uSunDirection.y > 0.)
	{
		shadow = localSunlight(vWorld.xz,footprint,shadow);
	}
	#endif
	#ifdef TERRAIN_WEATHER
	bool underDeck=uDeck.x>0. && uSunDirection.y>.015 && vWorld.y<uDeck.y;
	if(underDeck) shadow*=1.-deckBlock()*cloudsOnRay(vWorld,uSunDirection).x;
	float wet=uCraft<.5 && vWorld.y>uWaterLevel ? uGroundCover.x : 0.;
	if((wet>0. || uGroundCover.y>0.) && uCraft<.5 && vWorld.y>uWaterLevel) {
		// Pavement holds no more than its limit. The limit eases in across the road's edge with the paint.
		float snow=min(uGroundCover.y,mix(1.,uRoadSnow,clamp(road,0.,1.)))*smoothstep(.38,.88,n.y);
		albedo=mix(albedo*(1.-.31*wet),vec3(.82,.88,.93),snow);
		markingGlow*=1.-snow;
	}
	#endif
	// Approximate diffuse sky fill follows solar irradiance and fades through twilight.
	float illumination = uSunIntensity / 20.0 * smoothstep(-0.1,0.05,uSunDirection.y);
	vec3 ambient = uSkyAmbient * (0.35 + 0.65 * max(0.0,n.y));
	ambient += bounce * illumination * (1.0-max(0.,n.y));
	#ifdef TERRAIN_WEATHER
	if(underDeck) {
		// The sunlight the deck stops comes down again as even light from the whole sky: a little whiter than the
		// beam, and stronger on level ground than on a wall. The open sky's own fill is halved where cloud hides it.
		vec3 level=direct*uSunDirection.y;
		ambient*=1.-.5*uDeck.x*deckBlock();
		ambient+=mix(level,vec3(dot(level,vec3(1./3.))),.5)*deckGlow()*(.4+.6*max(0.,n.y));
	}
	#endif
	vec3 lit = albedo * (ambient + direct * facing * shadow) + markingGlow;
	#ifdef TERRAIN_WEATHER
	if(wet>0.) {
		float glint=max(0.,dot(reflect(-uSunDirection,n),normalize(-vRelative)));
		lit+=wet*(1.-uGroundCover.y)*(.06*ambient+direct*shadow*facing*.32*pow(glint,36.));
	}
	#endif
	lit += terrainNightMaterial(albedo, mix(lightingNormal,n,painted), uNightAmbient);
	lit += albedo / 3.14159265 * terrainSpotLight(vWorld, mix(lightingNormal,n,painted), uWaterLevel, uWaterOptics.z);
	#ifdef TERRAIN_WEATHER
	lit += albedo * boltLight(vWorld, mix(lightingNormal,n,painted));
	#endif
	#ifdef MATERIAL_REFLECTION
	lit=dielectricReflection(lit,lightingNormal,finish,painted,direct,shadow);
	#endif
	lit = waterColumnLight(lit, vRelative, uEye.y, uWaterLevel,
		vec3(illumination) + uNightAmbient + terrainSpotWaterColumn(uEye, vWorld, uWaterLevel));
	vec3 aerial = terrainAtmosphere(normalize(vRelative), uEye.y, length(vRelative), lit);
	color = vec4(terrainToneMap(aerial),alpha);
	#endif
}
