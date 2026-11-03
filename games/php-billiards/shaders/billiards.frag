#version 300 es
precision highp float;
in vec3 vPosition;
in vec3 vNormal;
in vec2 vUv;
in vec4 vTint;
flat in float vLayer;
uniform bool uInstanced;
uniform vec4 uBase;
uniform vec3 uEye;
uniform vec4 uMaterial;
uniform vec2 uTableSize;
uniform highp sampler2DArray uBallSkins;
uniform sampler2D uWood;
uniform sampler2D uFelt;
out vec4 color;

float distribution(float nDotH,float rough){float a=rough*rough;float a2=a*a;float d=nDotH*nDotH*(a2-1.0)+1.0;return a2/(3.14159*d*d);}
float specularAaRoughness(float rough,vec3 normal){vec3 dx=dFdx(normal),dy=dFdy(normal);
	float variance=.6*(dot(dx,dx)+dot(dy,dy));return clamp(sqrt(rough*rough+min(variance,.1)),rough,.5);}
float random(vec3 p){return fract(sin(dot(p,vec3(12.9898,78.233,37.719)))*43758.5453);}
float noise3(vec3 p){vec3 cell=floor(p),f=fract(p);f=f*f*(3.0-2.0*f);
	float n000=random(cell),n100=random(cell+vec3(1.0,0.0,0.0)),n010=random(cell+vec3(0.0,1.0,0.0)),n110=random(cell+vec3(1.0,1.0,0.0));
	float n001=random(cell+vec3(0.0,0.0,1.0)),n101=random(cell+vec3(1.0,0.0,1.0)),n011=random(cell+vec3(0.0,1.0,1.0)),n111=random(cell+vec3(1.0));
	return mix(mix(mix(n000,n100,f.x),mix(n010,n110,f.x),f.y),mix(mix(n001,n101,f.x),mix(n011,n111,f.x),f.y),f.z);}
vec3 bumpNormal(vec3 position,vec3 normal,float height){vec3 sigmaX=dFdx(position),sigmaY=dFdy(position);
	vec3 r1=cross(sigmaY,normal),r2=cross(normal,sigmaX);float determinant=dot(sigmaX,r1);
	vec3 gradient=sign(determinant)*(dFdx(height)*r1+dFdy(height)*r2);
	return normalize(abs(determinant)*normal-gradient);}
vec3 wood(vec3 p,vec3 n){vec3 blend=pow(abs(n),vec3(6.0));blend/=max(.001,blend.x+blend.y+blend.z);
	vec3 x=texture(uWood,p.zy*vec2(2.7,7.1)).rgb,y=texture(uWood,p.xz*vec2(2.7,7.1)).rgb;
	vec3 z=texture(uWood,p.xy*vec2(2.7,7.1)).rgb;return x*blend.x+y*blend.y+z*blend.z;}
vec3 cueWood(vec2 uv){return texture(uWood,vec2(uv.x*2.8,uv.y*.5)).rgb;}
vec3 ballSkin(vec2 uv){uv=vec2(uv.x,1.0-uv.y);vec2 size=vec2(textureSize(uBallSkins,0).xy);
	vec2 dx=dFdx(uv),dy=dFdy(uv);float footprint=max(length(dx*size),length(dy*size));
	float limit=min(1.0,64.0/max(footprint,.0001));return textureGrad(uBallSkins,vec3(uv,vLayer),dx*limit,dy*limit).rgb;}
float sightDisc(vec2 point,vec2 centre){float distanceToCentre=length(point-centre),edge=max(fwidth(distanceToCentre),.00018);
	return 1.0-smoothstep(.006-edge,.006+edge,distanceToCentre);}
float railSightMask(vec2 point){float result=0.0,offset=.0936625;
	for(int step=1;step<8;step++){if(step==4)continue;float x=-uTableSize.x*.5+float(step)*uTableSize.x/8.0;
		result=max(result,sightDisc(point,vec2(x,uTableSize.y*.5+offset)));
		result=max(result,sightDisc(point,vec2(x,-uTableSize.y*.5-offset)));}
	for(int step=1;step<4;step++){float z=-uTableSize.y*.5+float(step)*uTableSize.y/4.0;
		result=max(result,sightDisc(point,vec2(uTableSize.x*.5+offset,z)));
		result=max(result,sightDisc(point,vec2(-uTableSize.x*.5-offset,z)));}
	return result;}
vec3 fresnel(vec3 f0,float vDotH){return f0+(1.0-f0)*pow(1.0-vDotH,5.0);}
vec3 lightPbr(vec3 lightPosition,vec3 lightColor,float intensity,vec3 N,vec3 V,vec3 base,vec3 f0,float rough,float lightSize){
	rough=sqrt(rough*rough+lightSize*lightSize);
	vec3 L=normalize(lightPosition-vPosition),H=normalize(L+V);float nl=max(dot(N,L),0.0),nv=max(dot(N,V),.001);
	float nh=max(dot(N,H),0.0),vh=max(dot(V,H),0.0);vec3 F=fresnel(f0,vh);float D=distribution(nh,rough);
	float k=(rough+1.0)*(rough+1.0)/8.0,G=(nl/(nl*(1.0-k)+k))*(nv/(nv*(1.0-k)+k));
	vec3 spec=D*G*F/max(.01,4.0*nl*nv),diffuse=(1.0-F)*base/3.14159;
	vec3 diffuseLight=diffuse*nl*lightColor*intensity,specularLight=spec*nl*lightColor*intensity;
	return diffuseLight+.55*specularLight/(1.0+specularLight);}
float coatSpec(vec3 lightPosition,vec3 N,vec3 V,float rough,float lightSize){rough=sqrt(rough*rough+lightSize*lightSize);
	vec3 L=normalize(lightPosition-vPosition),H=normalize(L+V);
	float nl=max(dot(N,L),0.0),nv=max(dot(N,V),.001),nh=max(dot(N,H),0.0),vh=max(dot(V,H),0.0);
	float F=.04+.96*pow(1.0-vh,5.0),D=distribution(nh,rough),k=(rough+1.0)*(rough+1.0)/8.0;
	float G=(nl/(nl*(1.0-k)+k))*(nv/(nv*(1.0-k)+k));return D*G*F*nl/max(.01,4.0*nl*nv);}
void main(){vec4 tint=uInstanced?vTint:uBase;vec3 N=normalize(vNormal),V=normalize(uEye-vPosition),base=tint.rgb;float mode=uMaterial.z;
	// Unlit overlays for aiming aids: 7 is a flat tint, 8 is an antialiased ring on the unit disc.
	if(mode>6.5&&mode<7.5){color=vec4(base,tint.a);return;}
	if(mode>7.5&&mode<8.5){float r=length(vUv-.5)*2.0,w=max(fwidth(r),.02);
		float ring=smoothstep(.8-w,.8+w,r)*(1.0-smoothstep(1.0-w,1.0,r))+.18*(1.0-smoothstep(.8-w,.8+w,r));
		if(ring<.002)discard;color=vec4(base,tint.a*ring);return;}
	if(mode>3.5&&mode<4.5){float edge=length(vUv-.5)*2.0,opacity=(1.0-smoothstep(.18,1.0,edge))*tint.a;
		if(opacity<.002)discard;color=vec4(vec3(0.0),opacity);return;}
	if(mode>0.5&&mode<1.5)base=ballSkin(vUv);
	else if((mode>1.5&&mode<2.5)||mode>5.5){base*=(mode>5.5?cueWood(vUv):wood(vPosition,N))*1.45;
		if(mode<2.5){float sight=railSightMask(vPosition.xz)*smoothstep(.78,.96,N.y)*step(.08,vPosition.y);
			float pearl=noise3(vPosition*190.0+vec3(4.3,8.1,2.6));vec3 ivory=vec3(.72,.68,.52)+vec3(.12,.13,.15)*pearl;
			base=mix(base,ivory,sight);}
		if(mode>5.5){float sector=fract(vUv.y*6.0+.5),across=abs(sector-.5)*2.0;
			float pointFront=.34+.15*(1.0-across);float edge=max(fwidth(vUv.x-pointFront)*1.5,.0015);
			float dyed=1.0-smoothstep(pointFront-edge,pointFront+edge,vUv.x);
			vec3 blueDye=base*vec3(.16,.36,.78)+vec3(.012,.045,.14);base=mix(base,blueDye,dyed*.84);}}
	else if(mode>2.5&&mode<3.5){vec2 feltUv=vPosition.xz*1.6;
		float macro=texture(uFelt,feltUv*.55).r,grain=texture(uFelt,feltUv*7.0+vec2(.17,.43)).r;
		float looseFiber=smoothstep(.56,.62,texture(uFelt,feltUv*15.0+vec2(.61,.23)).r);
		float height=(macro-.5)*.00055+(grain-.5)*.00016+looseFiber*.00007;
		N=bumpNormal(vPosition,N,height);float top=smoothstep(.62,.92,N.y);
		float track=exp(-pow(abs(vPosition.z-.055*sin(vPosition.x*5.0))*18.0,2.0))*top;
		base*=.92+macro*.13+(grain-.5)*.045+looseFiber*.035;base=mix(base,base*1.04,track*.035);}
	float rough=uMaterial.x;if(mode>0.5&&mode<1.5)rough=clamp(rough+(random(floor(vPosition*900.0))-.5)*.012,.01,.05);
	float ior=uMaterial.y>1.0?uMaterial.y:1.5,f0Value=pow((ior-1.0)/(ior+1.0),2.0),nv=max(dot(N,V),.001);
	bool metallic=mode>4.5&&mode<5.5;
	bool varnishedWood=(mode>1.5&&mode<2.5)||mode>5.5;
	if(varnishedWood||metallic)rough=specularAaRoughness(rough,N);
	vec3 f0=metallic?mix(vec3(.04),base,.82):vec3(f0Value),key=vec3(-.45,2.8,-.7),fill=vec3(1.8,1.45,2.15);
	float skyAmbient=clamp(N.y*.5+.5,0.0,1.0);
	vec3 ambient=mix(vec3(.15,.21,.17),vec3(.25,.27,.3),skyAmbient);
	vec3 diffuseBase=metallic?base*.14:base;
	vec3 lit=diffuseBase*ambient+lightPbr(key,vec3(1.0,.94,.82),3.7,N,V,diffuseBase,f0,rough,.14)
		+lightPbr(fill,vec3(.58,.72,1.0),.58,N,V,diffuseBase,f0,rough,.18);
	if(mode>0.5&&mode<1.5){lit+=base*.035*(1.0-max(dot(N,normalize(key-vPosition)),0.0));
		float coat=coatSpec(key,N,V,.018,.16)*3.7+coatSpec(fill,N,V,.028,.2)*.58;
		lit+=vec3(.55*coat/(1.0+coat))*uMaterial.w;}
	else if(varnishedWood){float keyRough=specularAaRoughness(.11,N),fillRough=specularAaRoughness(.14,N);
		float coat=coatSpec(key,N,V,keyRough,.12)*3.7+coatSpec(fill,N,V,fillRough,.16)*.58;
		lit+=vec3(.55*coat/(1.0+coat))*uMaterial.w;}
	else if(mode>2.5&&mode<3.5){float backLight=max(dot(-N,normalize(key-vPosition)),0.0);
		vec3 fiberTint=mix(base,sqrt(max(base,vec3(0.0))),.28);
		lit+=base*(.04+.055*backLight)+fiberTint*pow(1.0-nv,1.8)*uMaterial.w*.68;}
	else lit+=base*pow(1.0-nv,3.0)*.08;
	color=vec4(lit,tint.a);
}
