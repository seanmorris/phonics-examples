#version 300 es
precision highp float;
flat in vec4 vSpan,vWide;
flat in float vLight;
uniform vec3 uTint; // the glow's color; the core is white
out vec4 color;
void main()
{
	// Each pixel finds its distance to this piece. The pass keeps the brightest value written to a pixel, so where
	// pieces meet or cross, the result is the light of the nearest one, without a seam or a bright bead at a joint.
	vec2 p=gl_FragCoord.xy-vSpan.xy,ab=vSpan.zw-vSpan.xy;
	float h=clamp(dot(p,ab)/max(dot(ab,ab),.000001),0.,1.);
	float d=length(p-ab*h);
	float core=1.-smoothstep(-.6,.6,d-mix(vWide.x,vWide.y,h));
	float reach=mix(vWide.z,vWide.w,h);
	float glow=exp(-2.5*d*d/(reach*reach));
	color=vec4(min(vec3(1.),vLight*(vec3(core)+uTint*glow*.7)),1.);
}
