uniform ivec2 uDrawCell, uRingMin;
uniform int uSpan;
uniform float uGridStep;
uniform bool uPacked;
uniform highp sampler2D uElevations, uInk;
ivec2 texelAt(ivec2 cell) { int n=textureSize(uElevations,0).x; return cell & ivec2(n-1); }
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
	ivec2 d = min(c-uRingMin, uRingMin+ivec2(uSpan)-c);
	if(d.x == 0) c.y -= c.y & 1;
	if(d.y == 0) c.x -= c.x & 1;
	return c;
}
vec3 clipNormal(ivec2 c) {
	return normalize(vec3(heightAt(c-ivec2(1,0))-heightAt(c+ivec2(1,0)),2.*uGridStep,
		heightAt(c-ivec2(0,1))-heightAt(c+ivec2(0,1))));
}
float clipMorph(ivec2 c) {
	ivec2 d=min(c-uRingMin,uRingMin+ivec2(uSpan)-c);
	return 1.-smoothstep(0.,4.,float(min(d.x,d.y)));
}
float clipHeight(ivec2 c,float morph) {
	ivec2 odd=c&ivec2(1), edge=ivec2(odd.x,-odd.y);
	float h=heightAt(c);
	if(morph>0. && any(notEqual(odd,ivec2(0)))) h=mix(h,.5*(heightAt(c-edge)+heightAt(c+edge)),morph);
	return h;
}
