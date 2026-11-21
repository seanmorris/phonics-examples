<?php
declare(strict_types=1);
namespace TerrainSandbox;

use Phonics\Render\Program;
use TerrainPhonics\Camera;

/** Opaque cardboard or drop markers, followed by one depth-tested instanced smoke draw. */
final class DeliveryBeacon
{
	private Program $solid, $smoke;
	private array $vao = [];

	public function __construct()
	{
		glGenVertexArrays(1,$this->vao);
		$this->solid = new Program(<<<'GLSL'
#version 300 es
uniform mat4 vp;
uniform vec3 origin;
uniform int dropSite;
out vec2 uv;
flat out int face;
const vec3 corners[8] = vec3[8](vec3(-1,0,-1),vec3(1,0,-1),vec3(1,0,1),vec3(-1,0,1),
	vec3(-1,1,-1),vec3(1,1,-1),vec3(1,1,1),vec3(-1,1,1));
const ivec4 faces[6] = ivec4[6](ivec4(0,1,5,4),ivec4(1,2,6,5),ivec4(2,3,7,6),
	ivec4(3,0,4,7),ivec4(4,5,6,7),ivec4(3,2,1,0));
const int indices[6] = int[6](0,1,2,0,2,3);
void main(){
	face = gl_VertexID/6;
	vec3 v = corners[faces[face][indices[gl_VertexID%6]]];
	uv = (face >= 4 ? v.xz : vec2(face%2==0 ? v.x : v.z,2.*v.y-1.))*.5+.5;
	vec3 p = v*vec3(1.05,1.55,.83) - vec3(0,.22,0);
	if(dropSite != 0){
		vec2 corner = corners[gl_InstanceID].xz;
		p = vec3(corner.x*4.+v.x*.22,v.y*2.8-1.2,corner.y*4.+v.z*.22);
	}
	gl_Position = vp*vec4(origin+p,1.);
}
GLSL, <<<'GLSL'
#version 300 es
precision mediump float;
precision highp int;
uniform int dropSite;
in vec2 uv;
flat in int face;
out vec4 color;
void main(){
	vec3 ink = vec3(.68,.49,.29);
	if(dropSite!=0) ink = uv.y>.73 && uv.y<.90 && face<4 ? vec3(.96,.87,.72) : vec3(.90,.18,.11);
	else if((face==4 || face==0 || face==2) && abs(uv.x-.5)<.07)
		ink = abs(uv.x-.5)<.008 ? vec3(.40,.27,.15) : vec3(.83,.66,.43);
	float shade = face==4 ? 1. : (face==0 || face==3 ? .84 : .68);
	color = vec4(ink*shade,1.);
}
GLSL);
		$this->smoke = new Program(<<<'GLSL'
#version 300 es
uniform mat4 vp;
uniform vec3 origin;
uniform vec2 focal;
uniform float clock, plumeHeight;
out vec2 disk;
out float seed, life;
const vec2 quad[6] = vec2[6](vec2(-1,-1),vec2(1,-1),vec2(1,1),vec2(-1,-1),vec2(1,1),vec2(-1,1));
void main(){
	// Sorted layers rise together. Each new puff keeps its own shape all the way to the top.
	float rank = float(35-gl_InstanceID), cycles = clock*4.;
	life = (rank+fract(cycles))/36.;
	seed = mod(rank-floor(cycles),97.);
	float spin = seed*2.39996+life*3.;
	float width = mix(1.35,13.5,sqrt(life));
	vec3 p = origin + vec3(cos(spin)*width*.31, 1.+life*plumeHeight, sin(spin)*width*.31);
	vec4 clip = vp*vec4(p,1.);
	disk = quad[gl_VertexID];
	clip.xy += disk*focal*width;
	gl_Position = clip;
}
GLSL, <<<'GLSL'
#version 300 es
precision highp float;
uniform vec3 tint;
in vec2 disk;
in float seed, life;
out vec4 color;
float hash(vec2 p){
	vec3 q=fract(vec3(p.xyx)*.1031);
	q+=dot(q,q.yzx+33.33);
	return fract((q.x+q.y)*q.z);
}
float noise(vec2 p){
	vec2 i=floor(p), f=fract(p); f=f*f*(3.-2.*f);
	return mix(mix(hash(i),hash(i+vec2(1,0)),f.x),
		mix(hash(i+vec2(0,1)),hash(i+1.),f.x),f.y);
}
void main(){
	vec2 p = disk*2.7 + vec2(seed*2.1,life*1.7);
	float billow = noise(p)*.66 + noise(2.2*p)*.34;
	float mask = 1.-smoothstep(.42,1.,length(disk)+(.5-billow)*.62);
	float vein = noise(vec2(1.5*p.x+billow*2.8,1.9*p.y+billow))*.68+billow*.32;
	float ash = smoothstep(.49,.71,vein)*.91;
	vec3 ink = mix(tint*(.85+.15*billow),mix(vec3(.35,.36,.34),vec3(.72,.73,.68),billow),ash);
	color = vec4(ink,mask*.82*(1.-smoothstep(.73,1.,life)));
}
GLSL);
	}

	private function place(Program $p, array $site, Camera $eye, int $w, int $h): void
	{
		$p->use();
		glBindVertexArray($this->vao[0]);
		glUniformMatrix4fv($p->location('vp'),1,false,$eye->viewProjection($w,$h));
		glUniform3f($p->location('origin'),$site['x']-$eye->position[0],$site['y']-$eye->position[1],$site['z']-$eye->position[2]);
	}

	public function solid(array $site, Camera $eye, int $w, int $h): void
	{
		$this->place($this->solid,$site,$eye,$w,$h);
		$drop = $site['kind'] === 'drop';
		glUniform1i($this->solid->location('dropSite'),(int)$drop);
		glDisable(GL_CULL_FACE);
		glDrawArraysInstanced(GL_TRIANGLES,0,36,$drop ? 4 : 1);
		glBindVertexArray(0);
	}

	public function smoke(array $site, Camera $eye, int $w, int $h, float $time): void
	{
		$p = $this->smoke;
		$this->place($p,$site,$eye,$w,$h);
		$f = 1/tan($eye->fov/2);
		glUniform2f($p->location('focal'),$f*$h/$w,$f);
		glUniform1f($p->location('clock'),fmod($time,873.));
		glUniform1f($p->location('plumeHeight'),DeliveryRun::HEIGHT);
		glUniform3f($p->location('tint'),...NavigationArrow::rgb($site['color']));
		glEnable(GL_DEPTH_TEST);
		glDepthMask(false);
		glDisable(GL_CULL_FACE);
		glEnable(GL_BLEND);
		glBlendFuncSeparate(GL_SRC_ALPHA,GL_ONE_MINUS_SRC_ALPHA,GL_ZERO,GL_ONE);
		glDrawArraysInstanced(GL_TRIANGLES,0,6,36);
		glDisable(GL_BLEND);
		glDepthMask(true);
		glBindVertexArray(0);
	}

	public function close(): void
	{
		$this->solid->close();
		$this->smoke->close();
		glDeleteVertexArrays(1,$this->vao);
	}
}
