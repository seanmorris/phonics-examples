<?php
declare(strict_types=1);
namespace TerrainSandbox;

use Phonics\Render\Program;
use TerrainPhonics\Camera;

/** Depth-tested navigation lamps and nozzle halos, batched after the scene fills its depth buffer. */
final class FlightLights
{
	private Program $program;
	private array $vao=[], $buffer=[];

	public function __construct()
	{
		$this->program=new Program(<<<'GLSL'
#version 300 es
layout(location=0) in vec4 pointRadius;
layout(location=1) in vec4 tintStrength;
uniform mat4 vp;
uniform vec2 viewport;
uniform float focalPixels;
out vec2 disk;
out vec4 light;
const vec2 corners[6]=vec2[6](vec2(-1,-1),vec2(1,-1),vec2(1,1),vec2(-1,-1),vec2(1,1),vec2(-1,1));
void main(){
	disk=corners[gl_VertexID];
	vec4 p=vp*vec4(pointRadius.xyz,1.);
	float radius=clamp(pointRadius.w*focalPixels/max(.25,p.w),3.8,32.);
	p.xy+=disk*(2.*radius/viewport)*p.w;
	gl_Position=p;
	light=tintStrength;
}
GLSL,<<<'GLSL'
#version 300 es
precision mediump float;
in vec2 disk;
in vec4 light;
out vec4 color;
void main(){
	float r=length(disk);
	float halo=1.-smoothstep(0.,1.,r);
	float core=1.-smoothstep(.13,.46,r);
	color=vec4(light.rgb*(core+.35*halo*halo),light.a);
}
GLSL);
		glGenVertexArrays(1,$this->vao); glBindVertexArray($this->vao[0]);
		glGenBuffers(1,$this->buffer); glBindBuffer(GL_ARRAY_BUFFER,$this->buffer[0]);
		for($i=0;$i<2;$i++) {
			glEnableVertexAttribArray($i); glVertexAttribPointer($i,4,GL_FLOAT,false,32,16*$i); glVertexAttribDivisor($i,1);
		}
		glBindVertexArray(0);
	}

	public static function beaconPulse(float $time): float
	{
		return max(0.,1.-abs(fmod($time,1.)-.14)/.095);
	}

	public static function lamps(array $pose,bool $plane,Camera $eye,float $time,float $night): string
	{
		$basis=VehicleModel::basis($pose);
		$center=[$pose['x']-$eye->position[0],$pose['y']-$eye->position[1],$pose['z']-$eye->position[2]];
		// In the baked F5, +X is the pilot's left and +Z is the nose.
		$left=-($center[0]*$basis[0]+$center[1]*$basis[1]+$center[2]*$basis[2]);
		$front=-($center[0]*$basis[6]+$center[1]*$basis[7]+$center[2]*$basis[8]);
		$bearing=rad2deg(atan2($left,$front));
		$bytes='';
		$lamp=static function(float $x,float $y,float $z,float $radius,array $rgb,float $gain) use (&$bytes,$basis,$center): void {
			if($gain<=0.) return;
			$p=$center;
			foreach($p as $i=>$v) $p[$i]=$v+$basis[$i]*$x+$basis[3+$i]*$y+$basis[6+$i]*$z;
			$bytes.=pack('g*',$p[0],$p[1],$p[2],$radius,$rgb[0],$rgb[1],$rgb[2],$gain);
		};
		$range=hypot(hypot($center[0],$center[2]),$center[1]);
		$gain=(.48+.52*$night)*min(1.,1400./max(1.,$range));
		$aft=max(0.,min(1.,(-$front/max(1.,$range)+.18)/.8));
		if($plane) {
			$side=min(1.,max(0.,(112.-abs($bearing))/4.));
			$lamp(4.21,-.43,-1.95,.64,[1.,.022,.012],$gain*$side*min(1.,max(0.,1.+$bearing/3.)));
			$lamp(-4.21,-.43,-1.95,.64,[.015,1.,.12],$gain*$side*min(1.,max(0.,1.-$bearing/3.)));
			$lamp(0.,.04,-6.34,.63,[1.,.96,.88],$gain*(1.-$side));
			$pulse=self::beaconPulse($time);
			$lamp(0.,1.05,-.6,.92,[1.,.05,.025],$gain*$pulse);
			$power=$pose['power'] ?? .65;
			foreach([-.29,.29] as $x) $lamp($x,-.23,-6.32,.74,[1.,.29,.07],$aft*sqrt(max(0.,$power)));
		} else {
			$lamp(0.,0.,-2.03,.55,[1.,.47,.12],$aft);
		}
		return $bytes;
	}

	public function draw(array $sources,Camera $eye,int $width,int $height,float $time,float $night): void
	{
		$bytes='';
		foreach($sources as [$pose,$plane]) $bytes.=self::lamps($pose,$plane,$eye,$time,$night);
		if($bytes==='') return;
		glBindVertexArray($this->vao[0]); glBindBuffer(GL_ARRAY_BUFFER,$this->buffer[0]);
		glBufferData(GL_ARRAY_BUFFER,strlen($bytes),$bytes,GL_DYNAMIC_DRAW);
		$p=$this->program; $p->use();
		glUniformMatrix4fv($p->location('vp'),1,false,$eye->viewProjection($width,$height));
		glUniform2f($p->location('viewport'),$width,$height);
		glUniform1f($p->location('focalPixels'),.5*$height/tan($eye->fov/2));
		glDepthMask(false); glEnable(GL_BLEND); glBlendFuncSeparate(GL_SRC_ALPHA,GL_ONE,GL_ZERO,GL_ONE);
		glDrawArraysInstanced(GL_TRIANGLES,0,6,intdiv(strlen($bytes),32));
		glDisable(GL_BLEND); glDepthMask(true); glBindVertexArray(0);
	}

	public function close(): void
	{
		glDeleteBuffers(1,$this->buffer); glDeleteVertexArrays(1,$this->vao); $this->program->close();
	}
}
