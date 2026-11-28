// Sequential source-over weights for west, northeast, southeast. The first visible pass is opaque.
uniform vec3 uJoinMaterial; // region count (zero on a single map), index, connector width
uniform vec3 uMaterialOrigin;
float regionOpacity(vec2 world)
{
	if(uJoinMaterial.x==0.) return 1.;
	vec2 t=clamp(world/uJoinMaterial.z+.5,0.,1.);
	vec2 weight=t*t*t*(t*(t*6.-15.)+10.);
	float west=1.-weight.x;
	float south=uJoinMaterial.x>2. ? weight.x*weight.y : 0.;
	float north=weight.x-south;
	if(uJoinMaterial.y<.5) return west>0. ? 1. : 0.;
	if(uJoinMaterial.y>1.5) return south;
	return north/max(1.e-8,west+north);
}
