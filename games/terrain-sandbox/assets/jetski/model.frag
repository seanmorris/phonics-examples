#version 300 es
precision highp float;
// OPTICS
// NIGHT LIGHT
uniform vec2 uEyeAndWaterHeight;

in vec3 vRelative, vNormal;
in vec2 vUv;
flat in vec4 vPaint;
flat in vec2 vSurface;
uniform sampler2D uDashboard;
uniform vec3 uSunDirection, uSunlight, uNightAmbient;
uniform float uDaylight, uExposure;
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
	light = waterColumnLight(light, vRelative, uEyeAndWaterHeight.x, uEyeAndWaterHeight.y, vec3(uDaylight) + uNightAmbient);
	vec3 exposed = 1.0 - exp(-light * uExposure);
	vec3 displayColor = mix(12.92 * exposed, 1.055 * pow(exposed, vec3(1.0/2.4)) - .055,
		step(vec3(.0031308), exposed));
	color = vec4(displayColor, 1.0);
}
