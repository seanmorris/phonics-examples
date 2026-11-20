uniform ivec2 uDrawCell, uRingMin;
uniform int uSpan, uTextureMask;
uniform float uGridStep, uMorphWidth;
uniform vec2 uEyeCell;
uniform bool uPacked, uHasParent;
uniform highp sampler2D uElevations, uInk;
ivec2 texelAt(ivec2 cell) { return cell & ivec2(uTextureMask); }
float heightAt(ivec2 cell) {
	vec4 t=texelFetch(uElevations,texelAt(cell),0);
	if(!uPacked) return t.r;
	uvec4 b=uvec4(t*255.+.5);
	return uintBitsToFloat(b.x|(b.y<<8)|(b.z<<16)|(b.w<<24));
}
ivec2 clipCell() {
	return uDrawCell+ivec2(gl_VertexID/2,gl_InstanceID+(gl_VertexID&1));
}
// Collapse unmatched edge midpoints to the exact same integer coordinates as the parent.
ivec2 clipStitchedCell(ivec2 c) {
	if(!uHasParent) return c;
	ivec2 d = min(c-uRingMin, uRingMin+ivec2(uSpan)-c);
	if(d.x == 0) c.y -= c.y & 1;
	if(d.y == 0) c.x -= c.x & 1;
	return c;
}
vec3 clipNormalAt(ivec2 c, int stride) {
	return normalize(vec3(heightAt(c-ivec2(stride,0))-heightAt(c+ivec2(stride,0)),
		2.*uGridStep*float(stride), heightAt(c-ivec2(0,stride))-heightAt(c+ivec2(0,stride))));
}
vec3 clipNormal(ivec2 c, float morph) {
	if(morph<=0.) return clipNormalAt(c,1);
	ivec2 odd=c&ivec2(1), edge=ivec2(odd.x,-odd.y);
	// Interpolate the parent's vertex normals before normalizing in the fragment shader.
	// Fine and coarse triangles then shade the same while their coverage is exchanged.
	vec3 parent=clipNormalAt(c-edge,2);
	if(any(notEqual(odd,ivec2(0)))) parent=.5*(parent+clipNormalAt(c+edge,2));
	return morph>=1. ? parent : mix(clipNormalAt(c,1),parent,morph);
}
float clipMorph(ivec2 c) {
	if(!uHasParent) return 0.;
	vec2 distanceToEye=abs(vec2(c)-uEyeCell);
	float edge=float(uSpan)/2.-3.;
	// The integer grid moves in pairs. Its joining strip is already fully on the
	// parent surface when the ring advances. Include the interior vertices of triangles
	// that change when odd edge vertices are collapsed.
	return smoothstep(edge-uMorphWidth,edge,max(distanceToEye.x,distanceToEye.y));
}
float clipHeight(ivec2 c,float morph) {
	ivec2 odd=c&ivec2(1), edge=ivec2(odd.x,-odd.y);
	float h=heightAt(c);
	if(morph>0. && any(notEqual(odd,ivec2(0)))) h=mix(h,.5*(heightAt(c-edge)+heightAt(c+edge)),morph);
	return h;
}
