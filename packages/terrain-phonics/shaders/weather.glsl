// Shared world-space field for the two sides of the deck, direct light, and reflections.
#define TERRAIN_WEATHER
uniform sampler2D uCloudNoise;
uniform vec4 uDeck; // coverage, height above world zero, shadow strength, daylight
uniform vec2 uWeatherOffset, uGroundCover;
uniform float uRoadSnow; // the most snow that may lie on pavement, as a share of full cover
uniform float uCloudLight; // the share of the sunlight the deck stops that comes down again as even light
uniform float uStormDark; // how heavy the deck is, from a mild overcast at 0 to the darkest storm at 1
// How much of the sun's beam the deck stops where it stands between a place and the sun. Up to about half cover that
// is the shadow strength. As the deck closes over it rises to all of the beam, so under a closed deck no light
// arrives from the sun's direction and nothing casts a sun shadow.
float deckBlock()
{
	return mix(uDeck.z,1.,smoothstep(.55,.95,uDeck.x));
}
// What a heavy deck leaves of the light a mild one lets through: all of it at no storm darkness, a twelfth at the
// most. Everything under the deck darkens by it together.
float deckDim()
{
	return 1.-.92*uStormDark;
}
// The stopped sunlight that comes down again from the whole deck, as a share of the sun's light on level ground.
float deckGlow()
{
	return uDeck.x*deckBlock()*uCloudLight*deckDim();
}
// The share of ordinary daylight left in the air under the deck, which lights the haze over distant ground.
float deckAir()
{
	return 1.-.92*uDeck.x*deckBlock()*uStormDark;
}
// Where a lit lightning strike shines from, in meters, and the distance at which it is half as bright.
uniform vec4 uBoltAt;
uniform vec3 uBoltLight; // its color and strength, or zero while no strike lights the land
uniform sampler2D uBoltShadowMap; // the height, around that strike, below which the bolt cannot be seen
uniform vec3 uBoltShadowAt; // the map's center X and Z and the width of ground it covers, or no width without a map
uniform vec2 uBoltShadowRange; // the lowest height the map can hold and the span of heights above it, in meters
// The light a lightning strike sheds on a surface: more the more squarely the surface faces the bolt, less with
// distance, and none where ground stands between the two.
vec3 boltLight(vec3 world, vec3 normal)
{
	if(uBoltLight.r+uBoltLight.g+uBoltLight.b<=0.) return vec3(0.);
	vec3 toBolt=uBoltAt.xyz-world;
	float far=length(toBolt),seen=1.;
	if(uBoltShadowAt.z>0.) {
		vec2 spot=(world.xz-uBoltShadowAt.xy)/uBoltShadowAt.z;
		// The light ends before the edge of the map, so nothing past the map is lit without a shadow test.
		seen=1.-smoothstep(.36,.5,max(abs(spot.x),abs(spot.y)));
		if(seen>0.) {
			// The map's two bytes decode to a height. A surface is lit from 4 meters under that height upward,
			// with a little more allowance the farther it is from the bolt, where the light only grazes it.
			vec2 code=textureLod(uBoltShadowMap,spot+.5,0.).gb;
			float ceiling=uBoltShadowRange.x+uBoltShadowRange.y*dot(code,vec2(256.,1.)/257.);
			seen*=smoothstep(-4.,0.,world.y+3.+.004*far-ceiling);
		}
	}
	return uBoltLight*(max(0.,dot(normal,toBolt/far))*seen/(1.+far*far/(uBoltAt.w*uBoltAt.w)));
}
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
	// A heavy deck's underside darkens toward charcoal, a little faster than the light under it does.
	vec3 underside=mix(vec3(.83,.94,1.09),vec3(.22,.27,.34),thickness)*pow(deckDim(),1.3);
	vec3 sunlit=mix(underside,mix(vec3(.87,.94,1.02),vec3(1.48,1.52,1.55),thickness),above);
	return mix(vec3(.016,.024,.047),sunlit,uDeck.w);
}
