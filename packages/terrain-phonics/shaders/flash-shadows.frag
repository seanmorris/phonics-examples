#version 300 es
precision highp float;
precision highp int;
// The shadows one lightning strike casts on the ground around it. Each point of the map looks along the straight
// line from itself to the bolt. Every rise of ground on the way hides whatever lies below the line from the bolt
// over that rise, and the map keeps the highest such line where it passes over the point: the height below which
// the bolt cannot be seen there. The ground shader compares each pixel's own height with it, so a cliff face is
// shadowed up to the right height even where the map is coarser than the cliff.
uniform highp sampler2D uTerrainHeights,uFine;
uniform bool uPacked;
uniform float uTerrainShadowExtent;
uniform vec4 uBoltShadow; // the light's X, height and Z in meters, and the width of ground the map covers
uniform float uBoltShadowSize;
uniform vec2 uShadowHeightRange; // the lowest height the map can hold and the span of heights above it, in meters
// The fine grid of heights near the eye: the lowest X and Z and the highest X and Z it can be read between, and the
// meters between its heights. With no fine grid the box is empty and the coarse heights serve everywhere.
uniform vec4 uFineBox;
uniform float uFineStep;
out vec4 color;
float elevation(highp sampler2D field, ivec2 c)
{
	vec4 t=texelFetch(field,c,0);
	if(!uPacked) return t.r;
	uvec4 b=uvec4(t*255.+.5);
	return uintBitsToFloat(b.x|(b.y<<8)|(b.z<<16)|(b.w<<24));
}
// Height between the four nearest stored heights. The fine grid wraps around its texture; the coarse one does not.
float heightIn(highp sampler2D field, vec2 cell, bool torus)
{
	ivec2 a=ivec2(floor(cell)),b=a+1,last=textureSize(field,0)-1;
	vec2 f=fract(cell);
	a=torus ? a&last : clamp(a,ivec2(0),last);
	b=torus ? b&last : clamp(b,ivec2(0),last);
	return mix(mix(elevation(field,a),elevation(field,ivec2(b.x,a.y)),f.x),
		mix(elevation(field,ivec2(a.x,b.y)),elevation(field,b),f.x),f.y);
}
float heightAt(vec2 position)
{
	if(all(greaterThanEqual(position,uFineBox.xy)) && all(lessThanEqual(position,uFineBox.zw)))
		return heightIn(uFine,position/uFineStep,true);
	float side=float(textureSize(uTerrainHeights,0).x-1);
	return heightIn(uTerrainHeights,clamp(position/uTerrainShadowExtent+.5,0.,1.)*side,false);
}
void main()
{
	vec2 position=uBoltShadow.xz+(gl_FragCoord.xy/uBoltShadowSize-.5)*uBoltShadow.w;
	vec2 toLight=uBoltShadow.xz-position;
	float far=length(toLight),ceiling=uShadowHeightRange.x;
	vec2 along=toLight/max(far,.001);
	// Forty looks at the ground on the way to the bolt. The first is one fine step from the point, where a cliff
	// just above it would be, and each later one is 9 percent farther on than the last, which carries them about
	// five kilometers from a 16 meter start.
	float stride=uFineBox.z>uFineBox.x ? uFineStep : uTerrainShadowExtent/float(textureSize(uTerrainHeights,0).x-1);
	float run=stride;
	for(int i=0;i<40;i++) {
		if(run>=far*.97) break;
		float rise=heightAt(position+along*run);
		// The line from the light over this rise, carried on to where the point is.
		ceiling=max(ceiling,uBoltShadow.y+(rise-uBoltShadow.y)*far/(far-run));
		stride*=1.09;
		run+=stride;
	}
	int code=int(round(clamp((ceiling-uShadowHeightRange.x)/uShadowHeightRange.y,0.,1.)*65535.));
	color=vec4(0.,vec2(code>>8,code&255)/255.,1.);
}
