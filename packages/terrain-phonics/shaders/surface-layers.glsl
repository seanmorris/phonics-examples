#define SURFACE_LAYERS
uniform int uLayerCount;
uniform vec3 uLayerColor[12];
uniform vec4 uLayerRange[12],uLayerBlend[12],uLayerMode[12];
uniform vec2 uPaletteOffset;
float layerCorner(ivec2 cell) {
	uvec2 p=uvec2(cell);
	uint h=p.x*1597334677u ^ p.y*3812015801u;
	h=(h^(h>>16))*2246822519u;
	return float(h>>8)*(2./16777216.)-1.;
}
float layerNoise(vec2 xz,float scale,float footprint) {
	vec2 uv=(xz+uPaletteOffset)/scale,t=fract(uv);
	t=t*t*(3.-2.*t);
	ivec2 c=ivec2(floor(uv));
	return (1.-smoothstep(.3,1.,footprint/scale))*mix(
		mix(layerCorner(c),layerCorner(c+ivec2(1,0)),t.x),
		mix(layerCorner(c+ivec2(0,1)),layerCorner(c+1),t.x),t.y);
}
vec3 layerSurface(vec3 pos,vec3 normal,float footprint,float water) {
	float masks[12];
	vec3 result=uLayerColor[0];
	float slope=1.-clamp(normal.y,0.,1.);
	masks[0]=1.;
	for(int i=1;i<12;i++) {
		if(i>=uLayerCount) break;
		vec4 r=uLayerRange[i],b=uLayerBlend[i],m=uLayerMode[i];
		float noise=b.z>0. ? layerNoise(pos.xz,b.w,footprint) : 0.;
		float h=pos.y+(b.z*noise)-(m.y==1.?water:(m.y==2.?dot(pos.xz,vec2(.006,-.003)):0.));
		if(m.z>0.) h=mod(h+.5*m.z,m.z);
		float fade=max(.0001,b.x), sf=max(.00001,b.y);
		float mask=smoothstep(r.x-fade,r.x,h)*(1.-smoothstep(r.y,r.y+fade,h));
		mask*=smoothstep(r.z-sf,r.z,slope)*(1.-smoothstep(r.w,r.w+sf,slope));
		if(m.z>0.) mask=mix(mask,clamp((r.y-r.x)/m.z,0.,1.),smoothstep(.25,1.,fwidth(pos.y)/m.z));
		if(m.x>=0.) mask*=masks[int(m.x)];
		masks[i]=mask;
		result=mix(result,uLayerColor[i],mask);
	}
	return result;
}
