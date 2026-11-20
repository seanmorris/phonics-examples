#version 300 es
precision highp float;
precision highp int;
// ATMOSPHERE
// OPTICS
// WATER
// SPOT LIGHTS
in vec3 vRelative, vWorld;
in float vGround;
uniform vec3 uEye;
out vec4 color;

float waterPhaseFilter(float phaseX, float phaseY)
{
	// Preserve resolved waves, then remove frequencies before they cross the pixel Nyquist limit.
	return 1.0 - smoothstep(1.57079632679,3.14159265359,max(abs(phaseX),abs(phaseY)));
}

vec4 waterVisualSample(vec2 position, float time, vec2 pixelX, vec2 pixelY)
{
	// Shading only: the shared CPU/GPU water field and displaced vertices retain every physical wave.
	vec4 result = vec4(uWaterLevel,0.0,0.0,0.0);
	for(int i = 0; i < TERRAIN_MAX_WAVES; ++i)
	{
		if(i >= uWaveCount) break;
		vec4 wave = uWaveVectors[i];
		vec2 dynamics = uWaveDynamics[i];
		vec2 gradient = wave.z * wave.xy;
		float weight = waterPhaseFilter(dot(gradient,pixelX),dot(gradient,pixelY));
		if(weight == 0.0) continue;
		float phase = dot(gradient,position) - dynamics.x * time + dynamics.y;
		float slope = wave.w * cos(phase);
		result.xyz += weight * vec3(wave.w * sin(phase),slope * gradient);
	}
	vec4 wake = terrainFrameWakes(position,pixelX,pixelY,true);
	// The visual sample uses its fourth channel for aerated water coverage.
	return vec4(result.xyz + wake.xyz,wake.w);
}

float waterFoamIntegral(float depth)
{
	// Antiderivative of the shoreline's 1-smoothstep(0,1.2,depth) coverage.
	float x = clamp(depth / 1.2,0.0,1.0);
	return min(depth,0.0) + 1.2 * (x - x*x*x + 0.5*x*x*x*x);
}

vec3 waterFacingNormal(vec3 normal, vec3 view)
{
	// Small-wave shading normals must remain visible from the coarse displaced surface. Project an
	// away-facing normal toward the view hemisphere rather than flipping it or producing negative Fresnel angles.
	return normalize(normal + view * max(0.0,0.001 - dot(normal,view)));
}

vec3 waterEnvironmentReflection(vec3 ray, float altitude, float angularFootprint, vec3 body, float illumination)
{
	// Below-horizon water sees an approximate lit water/environment field, not an unlit planet silhouette.
	// This is a smooth shading fallback; it does not claim terrain reflections or multiple scattering.
	vec3 upperRay = normalize(vec3(ray.x,max(0.002,ray.y),ray.z));
	vec3 upper = skyRadiance(upperRay,altitude,angularFootprint,false);
	vec3 lower = mix(body,vec3(0.035,0.075,0.11),0.45) * (vec3(illumination) + uNightAmbient);
	vec3 reflected = mix(lower,upper,smoothstep(-0.12,0.04,ray.y));
	// Keep the solar disk on the true reflection direction, with its existing pixel-footprint filter.
	return reflected + terrainSolarDisk(ray,altitude,terrainTransmittance(altitude,ray.y),angularFootprint);
}

uniform float uPlayHalf;
void main()
{
	if(uPlayHalf>0. && max(abs(vWorld.x),abs(vWorld.z))>uPlayHalf) discard;
	vec2 pixelX = dFdx(vWorld.xz);
	vec2 pixelY = dFdy(vWorld.xz);
	vec4 wave = waterVisualSample(vWorld.xz,uWaterTime,pixelX,pixelY);
	vec3 view = normalize(-vRelative);
	vec3 n = waterFacingNormal(terrainWaterNormal(wave),view);
	vec3 reflected = reflect(-view,n);
	float fresnel = uWaterOptics.y * (0.02 + 0.98 * pow(1.0 - clamp(dot(view,n),0.0,1.0),5.0));
	vec3 body = uWaterTint;
	float reflectionFootprint = max(length(dFdx(reflected)),length(dFdy(reflected)));
	float illumination = uSunIntensity / 20.0 * smoothstep(-0.1,0.05,uSunDirection.y);
	vec3 reflection = waterEnvironmentReflection(reflected,max(0.0,wave.x),reflectionFootprint,body,illumination);
	float shoreDepth = wave.x - vGround;
	float shoreFootprint = max(0.001,fwidth(shoreDepth));
	float shoreCoverage = (waterFoamIntegral(shoreDepth + 0.5 * shoreFootprint)
		- waterFoamIntegral(shoreDepth - 0.5 * shoreFootprint)) / shoreFootprint;
	float foamWeight = waterPhaseFilter(pixelX.x + pixelX.y,pixelY.x + pixelY.y);
	float foam = shoreCoverage * (0.65 + 0.35 * foamWeight * sin(vWorld.x + vWorld.z + uWaterTime * 2.0));
	float wash = wave.w;
	if(wash > 0.0)
	{
		float footprint = max(length(pixelX),length(pixelY));
		float detail = (1.0-smoothstep(0.06,0.3,footprint)) * sin(vWorld.x*11.0 + sin(vWorld.z*7.0)) * sin(vWorld.z*13.0 - uWaterTime*3.0);
		wash *= (0.82+0.18*detail) * (1.0-smoothstep(0.5,2.0,footprint));
	}
	foam = clamp(max(foam,wash) * uWaterOptics.w,0.0,1.0);
	float reflectionWeight = (1.0 - foam) * fresnel;
	float tintWeight = (1.0 - foam) * (1.0 - fresnel) * (1.0 - uWaterOptics.x);
	float alpha = foam + reflectionWeight + tintWeight;
	vec3 surfaceLight = vec3(illumination) + uNightAmbient;
	// Lamps, in the units of illumination, where 20 is full sunlight.
	surfaceLight += terrainSpotLight(vWorld, n, uWaterLevel, 0.0) / 20.0;
	vec3 lit = (foam * mix(vec3(0.65),vec3(1.6,1.85,1.95),wash) * surfaceLight + reflectionWeight * reflection
		+ tintWeight * body * surfaceLight) / max(alpha, 0.00001);
	color = vec4(terrainToneMap(terrainAtmosphere(normalize(vRelative),uEye.y,length(vRelative),lit)),alpha);
}
