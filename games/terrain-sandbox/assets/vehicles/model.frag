#version 300 es
precision highp float;
// OPTICS
// NIGHT LIGHT
uniform vec2 uEyeAndWaterHeight;

in vec3 vRelative, vNormal;
in vec2 vUv;
flat in vec4 vPaint;
flat in vec2 vSurface;
// Light a lamp gives off, zero on every other surface.
flat in vec3 vGlow;
uniform sampler2D uDashboard;
uniform vec3 uSunDirection, uSunlight, uNightAmbient;
uniform float uDaylight, uExposure, uEngineThrust;
uniform mat3 uRotation;
uniform vec3 uOrigin;
out vec4 color;

vec3 linearColor(vec3 rgb)
{
	return mix(rgb / 12.92, pow((rgb + .055) / 1.055, vec3(2.4)), step(vec3(.04045), rgb));
}

void main()
{
	vec3 normal = normalize(gl_FrontFacing ? vNormal : -vNormal);
	vec3 view = normalize(-vRelative);
	vec3 base = linearColor(vPaint.rgb);
	if(vSurface.y > .5) base *= linearColor(texture(uDashboard, vUv).rgb);

	float roughness = max(vPaint.a, .08);
	float metallic = vSurface.x;
	float gloss = mix(96.0, 3.0, roughness);
	float facingSun = max(0.0, dot(normal, uSunDirection));
	vec3 halfway = normalize(view + uSunDirection);
	vec3 reflectance = mix(vec3(.04), base, metallic);
	vec3 diffuse = base * (1.0 - metallic);
	float highlight = pow(max(0.0, dot(normal, halfway)), gloss) * (gloss + 2.0) / 8.0;

	vec3 sky = mix(vec3(.07,.09,.11), vec3(.24,.32,.40), normal.y * .5 + .5) * uDaylight;
	vec3 light = sky * (diffuse + metallic * reflectance)
		+ uSunlight * facingSun * (diffuse + reflectance * highlight);
	// At grazing angles the blue sky also catches the edges of the dark fittings.
	float rim = pow(1.0 - clamp(dot(normal,view),0.0,1.0), 3.0);
	light += terrainNightMaterial(diffuse + metallic * reflectance, normal, uNightAmbient) + 0.10 * rim * uNightAmbient;
	// A lamp is brightest seen along its axis. The glow stays on the lamp: nothing else on the model receives it.
	light += vGlow * (.6 + .4 * max(0.0, dot(normal, view)));
	// Tag 249 is the twin exhaust material. Shade the recessed throats in model space so
	// the nozzle walls occlude the hot cores as the aircraft turns or banks.
	if(int(vSurface.y * 255.0 + .5) == 249)
	{
		vec3 local = (vRelative - uOrigin) * uRotation;
		vec2 fromAxis = vec2(abs(local.x + .0034) - .293, local.y + .233);
		float r = length(fromAxis) / .235;
		float throat = 1.0 - smoothstep(.90, 1.02, r);
		float core = 1.0 - smoothstep(.20, .96, r);
		float power = clamp((uEngineThrust - .30) / 1.10, 0.0, 1.0);
		float boost = smoothstep(1.0, 1.4, uEngineThrust);
		light += throat * (vec3(1.5,.25,.035) * power*power +
			vec3(4.8,1.8,.58) * boost * (.32 + .68*core));
	}
	light = waterColumnLight(light, vRelative, uEyeAndWaterHeight.x, uEyeAndWaterHeight.y, vec3(uDaylight) + uNightAmbient);
	vec3 exposed = 1.0 - exp(-light * uExposure);
	vec3 displayColor = mix(12.92 * exposed, 1.055 * pow(exposed, vec3(1.0/2.4)) - .055,
		step(vec3(.0031308), exposed));
	color = vec4(displayColor, 1.0);
}
