// RGBA8 stores companded linear pigment, an octahedral lighting normal, a 16-bit log depth and geometric slope.
// Sample only this frame's triangles. All lighting, paint coverage and markings use the native display geometry.
vec3 encodePigment(vec3 pigment) { return sqrt(max(pigment,0.)/(1.+max(pigment,0.))); }
vec3 decodePigment(vec3 encoded) { vec3 p=encoded*encoded; return p/max(vec3(.0001),1.-p); }
vec2 packSurfaceNormal(vec3 n) {
	n/=abs(n.x)+abs(n.y)+abs(n.z);
	return .5+.5*(n.y>=0. ? n.xz : (1.-abs(n.zx))*mix(vec2(-1.),vec2(1.),greaterThanEqual(n.xz,vec2(0.))));
}
vec3 unpackSurfaceNormal(vec2 encoded) {
	vec2 p=2.*encoded-1.;
	vec3 n=vec3(p.x,1.-abs(p.x)-abs(p.y),p.y);
	n.xz-=clamp(-n.y,0.,1.)*mix(vec2(-1.),vec2(1.),greaterThanEqual(n.xz,vec2(0.)));
	return normalize(n);
}
#ifdef WRITE_SURFACE_SAMPLES
// The source grid covers the same viewport. Evaluate the procedural bands at the display's pixel footprint.
uniform vec2 uSourceScale;
#define dFdx(v) (dFdx(v)*uSourceScale.x)
#define dFdy(v) (dFdy(v)*uSourceScale.y)
#define fwidth(v) (abs(dFdx(v))+abs(dFdy(v)))
#endif
#ifdef READ_SURFACE_SAMPLES
uniform highp sampler2D uMaterialPigment,uMaterialNormal;
uniform vec2 uSurfacePixel;
vec2 nativeDepthSlope;
bool sampleSurface(vec3 normal,inout vec3 pigment,inout vec3 lighting) {
	vec2 size=vec2(textureSize(uMaterialPigment,0));
	vec2 pixel=gl_FragCoord.xy*uSurfacePixel*size-.5;
	vec2 f=fract(pixel), invScale=1./(uSurfacePixel*size);
	ivec2 cell=ivec2(floor(pixel)), last=ivec2(size)-1;
	// Reciprocal depth is affine across the current visible triangle. Predict its log depth at each tap.
	float w=gl_FragCoord.w, center=log2(1.+1./w);
	vec2 depthSlope=(-1.44269504/(w*(1.+w)))*nativeDepthSlope*invScale;
	vec3 total=vec3(0.); vec2 tilt=vec2(0.);
	float coverage=0.;
	// At a depth or grade break, interpolate only the samples that belong to this face.
	for(int y=0;y<2;y++) for(int x=0;x<2;x++) {
		ivec2 at=clamp(cell+ivec2(x,y),ivec2(0),last);
		vec4 a=texelFetch(uMaterialPigment,at,0), b=texelFetch(uMaterialNormal,at,0);
		float expected=center+dot(vec2(x,y)-f,depthSlope);
		float logDepth=dot(vec2(a.a,b.b),vec2(65280.,255.))*(17./65535.);
		float error=abs(logDepth-expected);
		if(b.a<.001 || error>.0025 || abs(b.a-(1.+254.*normal.y)/255.)>.035) continue;
		float weight=(x==0?1.-f.x:f.x)*(y==0?1.-f.y:f.y);
		total+=weight*a.rgb; tilt+=weight*b.rg; coverage+=weight;
	}
	if(coverage<.05) {
		// At a silhouette the matching face can just miss the four central samples.
		float best=1.e10;
		for(int y=-1;y<=2;y++) for(int x=-1;x<=2;x++) {
			ivec2 at=clamp(cell+ivec2(x,y),ivec2(0),last);
			vec4 a=texelFetch(uMaterialPigment,at,0), b=texelFetch(uMaterialNormal,at,0);
			if(b.a<.001) continue;
			vec2 offset=vec2(x,y)-f;
			float expected=center+dot(offset,depthSlope);
			float logDepth=dot(vec2(a.a,b.b),vec2(65280.,255.))*(17./65535.);
			float cost=abs(logDepth-expected)+.08*abs(b.a-normal.y)+.0001*dot(offset,offset);
			if(cost<best) { best=cost; total=a.rgb; tilt=b.rg; }
		}
		if(best==1.e10) return false;
		coverage=1.;
	}
	pigment=decodePigment(total/coverage); lighting=unpackSurfaceNormal(tilt/coverage);
	return true;
}
#endif
