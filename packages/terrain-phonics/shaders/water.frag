#version 300 es
precision highp float;
precision highp int;
// ATMOSPHERE
// WATER
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
		result += weight * vec4(wave.w * sin(phase),slope * gradient,-slope * dynamics.x);
	}
	for(int i = 0; i < TERRAIN_MAX_WAKES; ++i)
	{
		if(i >= uWakeCount) break;
		vec4 wake = uWakeVectors[i];
		float age = time - wake.w;
		if(age <= 0.0 || age >= 8.0) continue;
		vec2 delta = position - wake.xy;
		float radius = sqrt(dot(delta,delta) + 0.04);
		// Finite pixel phase differences remain useful at the radial packet's center, where its gradient vanishes.
		vec2 deltaX = delta + pixelX;
		vec2 deltaY = delta + pixelY;
		float weight = waterPhaseFilter(2.2 * (sqrt(dot(deltaX,deltaX) + 0.04) - radius),
			2.2 * (sqrt(dot(deltaY,deltaY) + 0.04) - radius));
		if(weight == 0.0) continue;
		float q = radius - 3.0 * age;
		float fade = pow(sin(3.14159265359 * age / 8.0),2.0);
		float envelope = wake.z * exp(-0.45 * age - 0.35 * q * q);
		float phase = 2.2 * q;
		float radial = envelope * fade * (2.2 * cos(phase) - 0.7 * q * sin(phase));
		float fadeRate = 3.14159265359 / 8.0 * sin(2.0 * 3.14159265359 * age / 8.0);
		result += weight * vec4(envelope * fade * sin(phase),radial * delta / radius,
			envelope * (fadeRate - 0.45 * fade) * sin(phase) - 3.0 * radial);
	}
	return result;
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
	vec3 lower = mix(body,vec3(0.035,0.075,0.11),0.45) * illumination;
	vec3 reflected = mix(lower,upper,smoothstep(-0.12,0.04,ray.y));
	// Keep the solar disk on the true reflection direction, with its existing pixel-footprint filter.
	return reflected + terrainSolarDisk(ray,altitude,terrainTransmittance(altitude,ray.y),angularFootprint);
}

void main()
{
	vec2 pixelX = dFdx(vWorld.xz);
	vec2 pixelY = dFdy(vWorld.xz);
	vec4 wave = waterVisualSample(vWorld.xz,uWaterTime,pixelX,pixelY);
	vec3 view = normalize(-vRelative);
	vec3 n = waterFacingNormal(terrainWaterNormal(wave),view);
	vec3 reflected = reflect(-view,n);
	float fresnel = 0.02 + 0.98 * pow(1.0 - max(0.0,dot(view,n)),5.0);
	float depth = max(0.0,wave.x - vGround);
	vec3 body = mix(vec3(0.08,0.25,0.22),vec3(0.006,0.045,0.09),1.0 - exp(-depth * 0.08));
	float reflectionFootprint = max(length(dFdx(reflected)),length(dFdy(reflected)));
	float illumination = uSunIntensity / 20.0 * smoothstep(-0.1,0.05,uSunDirection.y);
	vec3 reflection = waterEnvironmentReflection(reflected,max(0.0,wave.x),reflectionFootprint,body,illumination);
	// The environment reflection includes the solar disk once, weighted by Fresnel.
	vec3 lit = mix(body * illumination,reflection,fresnel);
	float shoreDepth = wave.x - vGround;
	float shoreFootprint = max(0.001,fwidth(shoreDepth));
	float shoreCoverage = (waterFoamIntegral(shoreDepth + 0.5 * shoreFootprint)
		- waterFoamIntegral(shoreDepth - 0.5 * shoreFootprint)) / shoreFootprint;
	float foamWeight = waterPhaseFilter(pixelX.x + pixelX.y,pixelY.x + pixelY.y);
	float foam = shoreCoverage * (0.65 + 0.35 * foamWeight * sin(vWorld.x + vWorld.z + uWaterTime * 2.0));
	lit = mix(lit,vec3(0.65) * illumination,foam * 0.7);
	color = vec4(terrainToneMap(terrainAtmosphere(normalize(vRelative),uEye.y,length(vRelative),lit)),1.0);
}
