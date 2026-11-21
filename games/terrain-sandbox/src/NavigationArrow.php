<?php
declare(strict_types=1);
namespace TerrainSandbox;

use Phonics\Render\Program;
use TerrainPhonics\Camera;

/** An overhead, extruded direction arrow. Its virtual camera looks up at the underside. */
final class NavigationArrow
{
	private Program $program;
	private array $vao = [], $buffers = [];
	private int $vertices;

	public function __construct()
	{
		$this->program = new Program(<<<'GLSL'
#version 300 es
layout(location=0) in vec3 vertex;
layout(location=1) in vec3 normal;
uniform vec2 bearing, scale;
uniform float anchor;
out float shade;
vec3 rotateHeading(vec3 p){
	return vec3(bearing.y*p.x+bearing.x*p.z,p.y,-bearing.x*p.x+bearing.y*p.z);
}
void main(){
	vec3 p=rotateHeading(vertex);
	float depth=5.+.86*p.z+.51*p.y;
	// An oblique HUD projection keeps forward screen-up while showing the glyph from below.
	vec2 screen=vec2(p.x,-.86*p.y+.58*p.z)*5./depth;
	gl_Position=vec4(screen*scale+vec2(0.,anchor),(depth-5.)*.35,1.);
	vec3 n=rotateHeading(normal);
	shade=.33+.67*max(0.,dot(n,vec3(-.54,-.76,-.36)));
}
GLSL, <<<'GLSL'
#version 300 es
precision mediump float;
uniform vec3 tint;
in float shade;
out vec4 color;
void main(){ color=vec4(tint*shade,1.); }
GLSL);
		$outline = [[0,1.22],[.81,.04],[.30,.04],[.30,-1.03],[-.30,-1.03],[-.30,.04],[-.81,.04]];
		$mesh = '';
		$triangle = static function(array $a, array $b, array $c) use (&$mesh): void {
			$u = [$b[0]-$a[0],$b[1]-$a[1],$b[2]-$a[2]];
			$v = [$c[0]-$a[0],$c[1]-$a[1],$c[2]-$a[2]];
			$n = [$u[1]*$v[2]-$u[2]*$v[1],$u[2]*$v[0]-$u[0]*$v[2],$u[0]*$v[1]-$u[1]*$v[0]];
			$length = sqrt($n[0]**2+$n[1]**2+$n[2]**2);
			foreach([$a,$b,$c] as $p) $mesh .= pack('g*',...$p).pack('g*',$n[0]/$length,$n[1]/$length,$n[2]/$length);
		};
		foreach($outline as [$x,$z])
		{
			$top[] = [$x,.20,$z];
			$rim[] = [$x,-.07,$z];
			$bottom[] = [$x*.88,-.18,$z*.92];
		}
		foreach([[0,1,6],[2,3,4],[2,4,5]] as [$a,$b,$c])
		{
			$triangle($top[$a],$top[$b],$top[$c]);
		}
		// Two sloping facets meet along the underside, readable even against smoke of the same colour.
		$neck = [0,-.32,$bottom[2][2]]; $tail = [0,-.32,$bottom[3][2]];
		foreach([[$bottom[0],$neck,$bottom[2]],[$bottom[0],$bottom[2],$bottom[1]],
			[$bottom[0],$bottom[6],$bottom[5]],[$bottom[0],$bottom[5],$neck],
			[$neck,$bottom[3],$bottom[2]],[$neck,$tail,$bottom[3]],
			[$neck,$bottom[5],$bottom[4]],[$neck,$bottom[4],$tail],
			[$tail,$bottom[4],$bottom[3]]] as [$a,$b,$c]) $triangle($a,$b,$c);
		foreach([[$top,$rim],[$rim,$bottom]] as [$upper,$lower])
			foreach($outline as $i=>$_)
			{
				$j = ($i+1)%count($outline);
				$triangle($upper[$i],$lower[$i],$upper[$j]);
				$triangle($lower[$i],$lower[$j],$upper[$j]);
			}
		$this->vertices = intdiv(strlen($mesh),24);
		glGenVertexArrays(1,$this->vao);
		glBindVertexArray($this->vao[0]);
		glGenBuffers(1,$this->buffers);
		glBindBuffer(GL_ARRAY_BUFFER,$this->buffers[0]);
		glBufferData(GL_ARRAY_BUFFER,strlen($mesh),$mesh,GL_STATIC_DRAW);
		foreach([0,1] as $i)
		{
			glEnableVertexAttribArray($i);
			glVertexAttribPointer($i,3,GL_FLOAT,false,24,12*$i);
		}
		glBindVertexArray(0);
	}

	public static function rgb(string $hex): array
	{
		return array_map(static fn($byte) => hexdec($byte)/255, str_split(substr($hex,1),2));
	}

	public function draw(array $target, array $vehicle, Camera $camera, int $w, int $h): void
	{
		$angle = atan2($target['x']-$vehicle['x'],$vehicle['z']-$target['z']) - $camera->yaw;
		if(cos($camera->pitch) < 0) $angle += M_PI;
		$size = min(78.,$h*.087,$w*.13);
		$top = (int)ceil(10+2*$size);
		// Clear depth only under the HUD glyph, after all world geometry and effects are complete.
		glEnable(GL_SCISSOR_TEST);
		glScissor(max(0,(int)($w/2-2*$size)),max(0,$h-$top),(int)ceil(4*$size),$top);
		glDepthMask(true);
		glClear(GL_DEPTH_BUFFER_BIT);
		glDisable(GL_SCISSOR_TEST);
		glEnable(GL_DEPTH_TEST);
		glDisable(GL_CULL_FACE);
		glDisable(GL_BLEND);
		$p = $this->program;
		$p->use();
		glUniform1f($p->location('anchor'),1.-2*(8+$size*.95)/$h);
		glUniform2f($p->location('scale'),2*$size/$w,2*$size/$h);
		glUniform2f($p->location('bearing'),sin($angle),cos($angle));
		glUniform3f($p->location('tint'),...self::rgb($target['color']));
		glBindVertexArray($this->vao[0]);
		glDrawArrays(GL_TRIANGLES,0,$this->vertices);
		glBindVertexArray(0);
	}

	public function close(): void
	{
		glDeleteVertexArrays(1,$this->vao);
		glDeleteBuffers(1,$this->buffers);
		$this->program->close();
	}
}
