<?php
declare(strict_types=1);
namespace TerrainSandbox;

use Phonics\Render\Program;
use TerrainPhonics\Camera;

/** Draw all arcade tracer streaks in one depth-tested submission. */
final class TracerBatch
{
	private array $vao = [], $buffers = [];
	private Program $program;

	public function __construct()
	{
		$this->program = new Program(<<<'GLSL'
#version 300 es
layout(location=0) in vec3 tail;
layout(location=1) in vec3 tip;
layout(location=2) in vec2 corner;
uniform mat4 vp;
uniform vec2 viewport;
void main() {
	vec4 a = vp * vec4(tail,1.), b = vp * vec4(tip,1.);
	// Clip the segment before the perspective divide, including when the jet flies past a shot.
	if(max(a.w,b.w) <= .26) { gl_Position = vec4(2.,2.,2.,1.); return; }
	if(a.w < .26) a = mix(a,b,(.26-a.w)/(b.w-a.w));
	if(b.w < .26) b = mix(b,a,(.26-b.w)/(a.w-b.w));
	vec2 delta = (b.xy/b.w-a.xy/a.w)*viewport;
	vec2 axis = length(delta) > .001 ? normalize(delta) : vec2(0.,1.);
	vec4 p = mix(a,b,corner.x);
	p.xy += (vec2(-axis.y,axis.x)*corner.y*2.4 + axis*(corner.x*2.-1.)*1.6)*p.w/viewport;
	gl_Position = p;
}
GLSL, <<<'GLSL'
#version 300 es
precision mediump float;
out vec4 color;
void main() { color = vec4(1.,.79,.36,1.); }
GLSL);
		glGenVertexArrays(1,$this->vao); glBindVertexArray($this->vao[0]);
		glGenBuffers(1,$this->buffers); glBindBuffer(GL_ARRAY_BUFFER,$this->buffers[0]);
		foreach([[3,0],[3,12],[2,24]] as $i=>[$size,$offset])
		{
			glEnableVertexAttribArray($i); glVertexAttribPointer($i,$size,GL_FLOAT,false,32,$offset);
		}
		glBindVertexArray(0);
	}

	public function draw(array $streaks, Camera $camera, int $w, int $h): void
	{
		$bytes = '';
		foreach($streaks as [$tip,$direction])
		{
			$head = $tail = [];
			foreach($tip as $i=>$value)
			{
				$head[] = $v = $value-$camera->position[$i];
				$tail[] = $v-13*$direction[$i];
			}
			$ends = pack('g*',...array_merge($tail,$head));
			foreach([[0,-1],[0,1],[1,1],[0,-1],[1,1],[1,-1]] as [$t,$side])
				$bytes .= $ends.pack('gg',$t,$side);
		}
		$this->program->use();
		glUniformMatrix4fv($this->program->location('vp'),1,false,$camera->viewProjection($w,$h));
		glUniform2f($this->program->location('viewport'),$w,$h);
		glBindVertexArray($this->vao[0]); glBindBuffer(GL_ARRAY_BUFFER,$this->buffers[0]);
		glBufferData(GL_ARRAY_BUFFER,strlen($bytes),$bytes,GL_DYNAMIC_DRAW);
		glDrawArrays(GL_TRIANGLES,0,6*count($streaks));
		glBindVertexArray(0);
	}

	public function close(): void
	{
		glDeleteBuffers(1,$this->buffers); glDeleteVertexArrays(1,$this->vao);
		$this->program->close();
	}
}
