#version 300 es
precision highp float;
precision highp int;
// A bolt is drawn as short straight pieces, one instance each: a main channel from the cloud to the ground, forks that
// leave it and end in the air, and twigs that leave the forks. Every position comes from the bolt's seed, so nothing
// is uploaded for a strike but its two ends.
uniform mat4 uViewProjection;
uniform vec3 uTop,uBottom; // where the bolt leaves the cloud and where it lands, from the eye, in meters
uniform vec4 uBolt; // seed, how much of the bolt has come down, main channel light, fork light
uniform vec3 uView; // angle one pixel spans, then the viewport's width and height in pixels
uniform vec3 uShape; // thickness, zigzag and the share of forks shown, each 1 as designed
flat out vec4 vSpan; // the piece's two ends in pixels
flat out vec4 vWide; // the core's radius at each end, then the glow's radius at each end, in pixels
flat out float vLight;
const int MAIN=128,FORKS=10,FORK=32,TWIGS=14,TWIG=16;
uint mixBits(uint x) { x=(x^(x>>16u))*2246822519u; x=(x^(x>>13u))*3266489917u; return x^(x>>16u); }
float unit(uint n) { return float(mixBits(n)>>8u)/16777216.; }
vec2 pair(uint n) { uint a=mixBits(n); return vec2(float(a>>8u),float(mixBits(a+1u)>>8u))/8388608.-1.; }
// How far a path has strayed to the side at u, from 0 where it starts to 1 where it ends. Bends of several sizes are
// laid over one another, each half as large and twice as frequent as the last, and each is straight between its
// corners. That gives a path of straight runs with sharp turns at every scale. `first` weights the largest bend,
// which sets how far the whole path bows. A path starts on its line, and a pinned one ends on it.
vec2 stray(uint seed, float u, int sizes, bool pinned, float first)
{
	vec2 sum=vec2(0.);
	float reach=first;
	for(int o=0;o<sizes;o++) {
		int spans=2<<o;
		float x=u*float(spans);
		int k=min(int(x),spans-1);
		uint row=mixBits(seed+uint(o));
		vec2 a=k==0 ? vec2(0.) : pair(row+uint(k));
		vec2 b=pinned && k+1==spans ? vec2(0.) : pair(row+uint(k+1));
		sum+=reach*mix(a,b,x-float(k));
		reach=(o==0 ? 1. : reach)*.5;
	}
	return sum;
}
void frame(vec3 d, out vec3 a, out vec3 b)
{
	a=normalize(cross(d,abs(d.y)<.9 ? vec3(0.,1.,0.) : vec3(1.,0.,0.)));
	b=cross(d,a);
}
// A heading tilted away from d, kept pointing downward.
vec3 turned(vec3 d, float tilt, float turn)
{
	vec3 a,b;
	frame(d,a,b);
	vec3 t=d*cos(tilt)+(a*cos(turn)+b*sin(turn))*sin(tilt);
	return normalize(vec3(t.x,min(t.y,-.2),t.z));
}
vec3 mainAt(float joint)
{
	vec3 line=uBottom-uTop,a,b;
	frame(normalize(line),a,b);
	vec2 s=stray(uint(uBolt.x),joint/float(MAIN),7,true,2.2)*(.08*uShape.y*length(line));
	return uTop+line*(joint/float(MAIN))+a*s.x+b*s.y;
}
// A fork leaves the main channel at one of its joints, 20 to 52 degrees off its heading, and stops short of the
// height the strike lands at. `begun` is how far down the main channel it leaves, in meters.
void fork(int n, out uint seed, out vec3 from, out vec3 heading, out float reach, out float begun)
{
	seed=mixBits(uint(uBolt.x)+977u*uint(n+1));
	float joint=floor(mix(10.,104.,unit(seed)));
	float whole=length(uBottom-uTop);
	from=mainAt(joint);
	heading=turned(normalize(mainAt(joint+3.)-mainAt(joint-3.)),mix(.35,.9,unit(seed+1u)),6.2832*unit(seed+2u));
	reach=max(0.,min(whole*mix(.1,.34,unit(seed+3u)),.85*(from.y-uBottom.y)/-heading.y));
	begun=joint/float(MAIN)*whole;
}
vec3 forkAt(uint seed, vec3 from, vec3 heading, float reach, float joint)
{
	vec3 a,b;
	frame(heading,a,b);
	vec2 s=stray(seed+9u,joint/float(FORK),5,false,1.)*(.12*uShape.y*reach);
	return from+heading*reach*(joint/float(FORK))+a*s.x+b*s.y;
}
void main()
{
	int id=gl_InstanceID,forks=int(float(FORKS)*uShape.z+.5);
	bool shown=true;
	float whole=length(uBottom-uTop);
	vec3 p0,p1;
	float along; // meters of path from the cloud to where this piece starts
	vec2 core; // the core's radius at each end in meters
	// Glow radius in meters, brightness, and the narrowest the core may be in pixels. That least width is a share of
	// the view's height, about three pixels across for the main channel on a 1,264-pixel view, so a strike many
	// kilometers off is still a clear line.
	float glow,light,least;
	if(id<MAIN) {
		p0=mainAt(float(id));
		p1=mainAt(float(id+1));
		along=float(id)/float(MAIN)*whole;
		core=vec2(.45);
		glow=3.5;
		// The channel fades in over its first twentieth, where it comes out of the cloud.
		light=uBolt.z*smoothstep(0.,.05,(float(id)+.5)/float(MAIN));
		least=max(.9,.0012*uView.z);
	} else if(id<MAIN+FORKS*FORK) {
		int n=(id-MAIN)/FORK,i=(id-MAIN)%FORK;
		uint seed;
		vec3 from,heading;
		float reach,begun;
		fork(n,seed,from,heading,reach,begun);
		shown=n<forks;
		p0=forkAt(seed,from,heading,reach,float(i));
		p1=forkAt(seed,from,heading,reach,float(i+1));
		vec2 u=vec2(float(i),float(i+1))/float(FORK);
		along=begun+u.x*reach;
		core=mix(vec2(.28),vec2(.1),u);
		glow=1.6;
		// A fork is nearly as white as the main channel where it leaves it. What sets it apart is that it is thinner.
		light=uBolt.w*mix(1.,.6,u.x);
		least=max(.6,.0007*uView.z);
	} else {
		int t=id-MAIN-FORKS*FORK,n=t/TWIG,i=t%TWIG;
		uint seed=mixBits(uint(uBolt.x)+3301u*uint(n+1)),parentSeed;
		vec3 from,heading,a,b;
		float reach,begun;
		int parent=int(mixBits(seed)%uint(FORKS));
		fork(parent,parentSeed,from,heading,reach,begun);
		// A twig goes when its fork does, and the twigs thin out in step with the forks.
		shown=parent<forks && n<int(float(TWIGS)*uShape.z+.5);
		float joint=floor(mix(5.,26.,unit(seed+1u)));
		vec3 start=forkAt(parentSeed,from,heading,reach,joint);
		vec3 off=turned(heading,mix(.35,.85,unit(seed+2u)),6.2832*unit(seed+3u));
		float twig=max(0.,min(reach*(1.-joint/float(FORK))*mix(.4,.8,unit(seed+4u)),.85*(start.y-uBottom.y)/-off.y));
		frame(off,a,b);
		vec2 u=vec2(float(i),float(i+1))/float(TWIG);
		float sway=.13*uShape.y*twig;
		vec2 s0=stray(seed+9u,u.x,4,false,1.)*sway,s1=stray(seed+9u,u.y,4,false,1.)*sway;
		p0=start+off*twig*u.x+a*s0.x+b*s0.y;
		p1=start+off*twig*u.y+a*s1.x+b*s1.y;
		along=begun+joint/float(FORK)*reach+u.x*twig;
		core=mix(vec2(.14),vec2(.05),u);
		glow=.8;
		light=uBolt.w*mix(.8,.35,u.x);
		least=max(.5,.0005*uView.z);
	}
	vec4 c0=uViewProjection*vec4(p0,1.),c1=uViewProjection*vec4(p1,1.);
	vSpan=vWide=vec4(0.);
	vLight=0.;
	// The longest path, a twig at the end of a low fork, is one and a half times the main channel's length. A piece
	// behind the eye, or one the bolt has not yet reached on its way down, is not drawn.
	if(!shown || light<=0. || along>=uBolt.y*1.5*whole || min(c0.w,c1.w)<.3) {
		gl_Position=vec4(2.,2.,2.,1.);
		return;
	}
	vec2 s0=(c0.xy/c0.w*.5+.5)*uView.yz,s1=(c1.xy/c1.w*.5+.5)*uView.yz;
	vec2 pixel=vec2(c0.w,c1.w)*uView.x; // meters one pixel spans at each end
	vec2 radius=max(core/pixel,vec2(least))*uShape.x;
	// The glow is at least four times the core's radius, and otherwise no wider than 96 pixels.
	vec2 halo=max(radius*4.,min(glow*uShape.x/pixel,vec2(96.)));
	vec2 axis=s1-s0;
	float span=length(axis);
	axis=span>.001 ? axis/span : vec2(1.,0.);
	// The quad covers the piece and its glow on every side, flat on the screen.
	vec2 corner=vec2(float(gl_VertexID&1),float(gl_VertexID>>1))*2.-1.;
	float pad=max(halo.x,halo.y)*1.7+1.5;
	vec2 at=mix(s0,s1,corner.y*.5+.5)+(axis*corner.y+vec2(-axis.y,axis.x)*corner.x)*pad;
	gl_Position=vec4(at/uView.yz*2.-1.,mix(c0.z/c0.w,c1.z/c1.w,corner.y*.5+.5),1.);
	vSpan=vec4(s0,s1);
	vWide=vec4(radius,halo);
	vLight=light;
}
