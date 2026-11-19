// CPU parity: TerrainPhonics\Water. Units are metres and seconds; Y is up.
#define TERRAIN_MAX_WAVES 8
#define TERRAIN_MAX_WAKES 16
uniform float uWaterLevel;
uniform float uWaterTime;
uniform int uWaveCount;
uniform vec4 uWaveVectors[TERRAIN_MAX_WAVES];
uniform vec2 uWaveDynamics[TERRAIN_MAX_WAVES];
uniform int uWakeCount;
uniform vec4 uWakeVectors[TERRAIN_MAX_WAKES];

// Components: height, dHeight/dX, dHeight/dZ, dHeight/dTime.
vec4 terrainWaterSample(vec2 position, float time)
{
	vec4 result = vec4(uWaterLevel, 0.0, 0.0, 0.0);
	for(int i = 0; i < TERRAIN_MAX_WAVES; ++i)
	{
		if(i >= uWaveCount) break;
		vec4 wave = uWaveVectors[i];
		vec2 dynamics = uWaveDynamics[i];
		float phase = wave.z * dot(wave.xy, position) - dynamics.x * time + dynamics.y;
		float slope = wave.w * cos(phase);
		result += vec4(wave.w * sin(phase), slope * wave.z * wave.xy, -slope * dynamics.x);
	}
	for(int i = 0; i < TERRAIN_MAX_WAKES; ++i)
	{
		if(i >= uWakeCount) break;
		vec4 wake = uWakeVectors[i];
		float age = time - wake.w;
		if(age <= 0.0 || age >= 8.0) continue;
		vec2 delta = position - wake.xy;
		float radiusSquared = dot(delta,delta) + 0.04;
		// Past 18 m from the wavefront its Gaussian envelope is below float32 range.
		float outer = 3.0 * age + 18.0;
		if(radiusSquared > outer * outer) continue;
		float radius = sqrt(radiusSquared);
		float q = radius - 3.0 * age;
		float fade = pow(sin(3.141592653589793 * age / 8.0), 2.0);
		float envelope = wake.z * exp(-0.45 * age - 0.35 * q * q);
		float phase = 2.2 * q;
		float radial = envelope * fade * (2.2 * cos(phase) - 0.7 * q * sin(phase));
		float fadeRate = 3.141592653589793 / 8.0 * sin(2.0 * 3.141592653589793 * age / 8.0);
		result += vec4(envelope * fade * sin(phase), radial * delta / radius,
			envelope * (fadeRate - 0.45 * fade) * sin(phase) - 3.0 * radial);
	}
	return result;
}

vec3 terrainWaterNormal(vec4 sampleValue)
{
	return normalize(vec3(-sampleValue.y, 1.0, -sampleValue.z));
}
