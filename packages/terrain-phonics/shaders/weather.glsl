// Shared world-space field for the two sides of the deck, direct light, and reflections.
#define TERRAIN_WEATHER
uniform sampler2D uCloudNoise;
uniform vec4 uDeck; // coverage, height above world zero, shadow strength, daylight
uniform vec2 uWeatherOffset, uGroundCover;
vec2 cloudSample(vec2 world)
{
	vec2 uv = (world-uWeatherOffset)/16384.;
	float field = texture(uCloudNoise,uv).r*.77 + texture(uCloudNoise,uv.yx*4.+.37).r*.23;
	float cutoff = mix(.94,.12,uDeck.x);
	// Keep variations in optical thickness even where the cloud is completely opaque.
	float opacity=smoothstep(cutoff-.13,cutoff+.13,field)*smoothstep(0.,.11,uDeck.x);
	return vec2(opacity,smoothstep(.24,.78,field));
}
vec2 cloudsOnRay(vec3 origin, vec3 ray)
{
	if(abs(ray.y)<.0001) return vec2(0.);
	float t=(uDeck.y-origin.y)/ray.y;
	if(t<=0. || t>=60000.) return vec2(0.);
	vec2 cloud=cloudSample(origin.xz+t*ray.xz);
	cloud.x*=1.-smoothstep(35000.,60000.,t);
	return cloud;
}
vec3 cloudRadiance(float thickness, float above)
{
	vec3 underside=mix(vec3(.83,.94,1.09),vec3(.22,.27,.34),thickness);
	vec3 sunlit=mix(underside,mix(vec3(.87,.94,1.02),vec3(1.48,1.52,1.55),thickness),above);
	return mix(vec3(.016,.024,.047),sunlit,uDeck.w);
}
