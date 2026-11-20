<?php
declare(strict_types=1);
namespace TerrainSandbox;

use Phonics\Render\Program;
use TerrainPhonics\Camera;

/** One transparent draw for the F5's smoke, condensation and impact bursts. */
final class TrailRenderer
{
	private const PUFFS = 12;
	private Program $program;
	private array $vao = [], $buffers = [];
	private ?FlightTrails $source = null;
	private int $revision = -1, $count = 0;

	public function __construct()
	{
		$this->program = new Program(<<<'GLSL'
#version 300 es
layout(location=0) in vec3 sampleCorner;
layout(location=1) in vec4 startPoint;
layout(location=2) in vec4 endPoint;
layout(location=3) in vec3 style; // vapor, smoke or burst; lifetime; density
uniform mat4 viewProjection;
uniform vec3 eye;
uniform vec2 projectionScale;
uniform float clock;
out vec2 disk;
out float opacity;
out float pattern;
out float burstLife;
void main() {
	vec4 point = mix(startPoint, endPoint, sampleCorner.z);
	float age = clock - point.w;
	if (age < 0.0 || age >= style.y) { gl_Position = vec4(2.,2.,2.,1.); return; }
	float life = age / style.y;
	disk = sampleCorner.xy;
	burstLife = -1.;
	if (style.x > 1.5) {
		// The same twelve quads form a central flash and an expanding shell of smoke puffs.
		float part = floor(sampleCorner.z * 12.);
		float y = 1.-2.*sampleCorner.z, ring = sqrt(1.-y*y);
		float angle = part*2.39996 + fract(dot(point.xz,vec2(.037,.013)))*6.283;
		vec3 spread = vec3(ring*cos(angle),y,ring*sin(angle)) * (1.-exp(-5.*life))*11.;
		if (part < .5) spread = vec3(0.);
		spread.y += 5.*life;
		vec4 p = viewProjection * vec4((point.xyz-eye)+spread,1.);
		p.xy += disk * projectionScale * (3.+7.*smoothstep(0.,.65,life));
		gl_Position = p;
		pattern = angle;
		burstLife = life;
		opacity = (1.-smoothstep(.35,1.,life))*smoothstep(.25,3.,p.w);
		return;
	}
	float smoke = style.x;
	// A stable offset for each wisp. It drifts and rises only as it ages.
	vec3 seed = fract(point.xyz * vec3(.1031,.1030,.0973));
	seed += dot(seed,seed.yzx + 33.33);
	vec2 jitter = fract((seed.xx + seed.yz) * seed.zy);
	pattern = jitter.x * 6.283;
	vec3 offset = vec3(jitter.x-.5, .65 + .5*jitter.y, jitter.y-.5) * age * age * mix(.7,1.8,smoke);
	vec4 p = viewProjection * vec4((point.xyz-eye)+offset,1.);
	float radius = mix(.20 + .75*age, .78 + 2.5*age, smoke) * mix(.88,1.12,jitter.y);
	p.xy += sampleCorner.xy * projectionScale * radius;
	gl_Position = p;
	opacity = style.z * mix(.36,.43,smoke) * (1.-smoothstep(.04,1.,life)) * smoothstep(2.,8.,p.w);
}
GLSL, <<<'GLSL'
#version 300 es
precision mediump float;
in vec2 disk;
in float opacity;
in float pattern;
in float burstLife;
uniform vec3 tint;
out vec4 color;
void main() {
	float d = length(disk);
	float r = d + .065*sin(5.*disk.x+pattern)*sin(4.*disk.y-pattern);
	vec3 ink = tint;
	if (burstLife >= 0.) {
		float heat = burstLife + .21*d;
		ink = mix(vec3(1.,.98,.75),vec3(1.,.40,.055),smoothstep(.04,.28,heat));
		ink = mix(ink,tint*vec3(.32,.28,.26),smoothstep(.27,.71,heat));
	}
	color = vec4(ink, opacity * (1.-smoothstep(.12,1.,r)));
}
GLSL);
		glGenVertexArrays(1,$this->vao);
		glGenBuffers(2,$this->buffers);
		glBindVertexArray($this->vao[0]);

		// Each instance covers one travelled segment. Stagger soft billboards along it so it
		// keeps its volume when seen straight down its length from the cockpit.
		$shape = '';
		for($i = 0; $i < self::PUFFS; $i++)
		{
			foreach([[-1,-1],[1,-1],[1,1],[-1,-1],[1,1],[-1,1]] as [$x,$y])
				$shape .= pack('g*',$x,$y,($i+.5)/self::PUFFS);
		}
		glBindBuffer(GL_ARRAY_BUFFER,$this->buffers[0]);
		glBufferData(GL_ARRAY_BUFFER,strlen($shape),$shape,GL_STATIC_DRAW);
		glEnableVertexAttribArray(0);
		glVertexAttribPointer(0,3,GL_FLOAT,false,12,0);

		glBindBuffer(GL_ARRAY_BUFFER,$this->buffers[1]);
		foreach([[4,0],[4,16],[3,32]] as $i => [$size,$offset])
		{
			glEnableVertexAttribArray($i+1);
			glVertexAttribPointer($i+1,$size,GL_FLOAT,false,FlightTrails::STRIDE,$offset);
			glVertexAttribDivisor($i+1,1);
		}
		glBindVertexArray(0);
	}

	public function draw(FlightTrails $trails, float $alpha, Camera $camera, int $width, int $height, float $night): void
	{
		glBindVertexArray($this->vao[0]);
		if($trails !== $this->source || $this->revision !== $trails->revision)
		{
			$bytes = $trails->instances();
			$this->count = intdiv(strlen($bytes),FlightTrails::STRIDE);
			glBindBuffer(GL_ARRAY_BUFFER,$this->buffers[1]);
			glBufferData(GL_ARRAY_BUFFER,strlen($bytes),$bytes,GL_DYNAMIC_DRAW);
			$this->source = $trails;
			$this->revision = $trails->revision;
		}
		$p = $this->program;
		$p->use();
		$focal = 1.0/tan($camera->fov/2);
		glUniform2f($p->location('projectionScale'),$focal*$height/$width,$focal);
		glUniformMatrix4fv($p->location('viewProjection'),1,false,$camera->viewProjection($width,$height));
		glUniform3f($p->location('eye'),...$camera->position);
		glUniform1f($p->location('clock'),$trails->timeAt($alpha));
		glUniform3f($p->location('tint'),.92-.64*$night,.94-.59*$night,.98-.48*$night);

		// Terrain, vehicles and water have already populated the depth buffer.
		glDepthMask(false);
		glEnable(GL_BLEND);
		glBlendFuncSeparate(GL_SRC_ALPHA,GL_ONE_MINUS_SRC_ALPHA,GL_ZERO,GL_ONE);
		glDrawArraysInstanced(GL_TRIANGLES,0,6*self::PUFFS,$this->count);
		glDisable(GL_BLEND);
		glDepthMask(true);
		glBindVertexArray(0);
	}

	public function close(): void
	{
		glDeleteBuffers(2,$this->buffers);
		glDeleteVertexArrays(1,$this->vao);
		$this->program->close();
	}
}
