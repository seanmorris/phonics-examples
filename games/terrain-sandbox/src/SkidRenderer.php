<?php
declare(strict_types=1);
namespace TerrainSandbox;

use Phonics\Render\Program;
use TerrainPhonics\{Camera,Scene};

/** Multiply rubber into the existing ground before vehicles, water and transparent effects. */
final class SkidRenderer
{
	private const FRAGMENT = <<<'GLSL'
		#version 300 es
		precision highp float;
		in vec3 vWorld, vPaint1;
		in vec4 vRoadMark,vRoadArms;
		in float vSplinePaint;
		uniform vec3 uTile; // X, Z, inverse width
		uniform sampler2D uRubber;
		uniform float uBorder;
		out vec4 color;
		void main() {
			vec2 uv=(vWorld.xz-uTile.xy)*uTile.z;
			float outline=dot(abs(vRoadArms),vec4(1.))/max(vRoadMark.w,.0001);
			float aa=max(fwidth(outline),.0001);
			float outside=smoothstep(1.-aa,1.+aa,outline);
			float pavement=clamp(vPaint1.y-vSplinePaint*outside,0.,1.);
			float rubber=texture(uRubber,uv*(1.-2.*uBorder)+uBorder).r*pavement;
			if(any(lessThan(uv,vec2(0.))) || any(greaterThanEqual(uv,vec2(1.)))) discard;
			color=vec4(vec3(1.-.40*rubber),1.);
		}
		GLSL;

	private ?Program $program=null;
	private ?Scene $scene=null;
	private array $textures=[];

	public function draw(SkidMarks $marks,Scene $scene,Camera $camera,int $width,int $height): void
	{
		if($this->scene!==$scene) { $this->close(); $this->scene=$scene; }
		foreach($this->textures as $key=>[$tile,$id]) {
			if(($marks->tiles[$key] ?? null)===$tile) continue;
			glDeleteTextures(1,[$id]); unset($this->textures[$key]);
		}
		if(!$marks->tiles) return;
		$planes=$camera->clipPlanes($width,$height);
		[$dx,$dy,$dz]=$marks->offset;
		$bound=false;
		foreach($marks->tiles as $key=>$tile) {
			$x=$tile->x+$dx; $z=$tile->z+$dz; $size=SkidTile::METERS;
			if(!Camera::inView($planes,$camera->position,['x'=>$x,'z'=>$z,'size'=>$size],$tile->low+$dy-2.,$tile->high+$dy+2.)) continue;
			if(!$bound) {
				$p=$this->program ??= $scene->groundOverlayProgram(self::FRAGMENT);
				$p->use();
				glUniformMatrix4fv($p->location('uViewProjection'),1,false,$camera->viewProjection($width,$height));
				glUniform3f($p->location('uEye'),...$camera->position);
				glUniform1i($p->location('uRubber'),0);
				glUniform1f($p->location('uBorder'),SkidTile::BORDER/SkidTile::SIZE);
				glDepthMask(false); glDepthFunc(GL_LEQUAL);
				glEnable(GL_BLEND); glBlendFuncSeparate(GL_ZERO,GL_SRC_COLOR,GL_ZERO,GL_ONE);
				$bound=true;
			}
			glActiveTexture(GL_TEXTURE0);
			$this->bind($key,$tile);
			glUniform3f($p->location('uTile'),$x,$z,1./$size);
			$scene->drawGroundRegion($p,$camera,[$x,$z,$x+$size,$z+$size]);
		}
		if($bound) {
			glActiveTexture(GL_TEXTURE0); glBindVertexArray(0);
			glDepthMask(true); glDepthFunc(GL_LESS); glDisable(GL_BLEND);
		}
	}

	private function bind(string $key,SkidTile $tile): void
	{
		$n=SkidTile::SIZE;
		if(!isset($this->textures[$key])) {
			$ids=[]; glGenTextures(1,$ids);
			$this->textures[$key]=[$tile,$ids[0],$tile->revision];
			glBindTexture(GL_TEXTURE_2D,$ids[0]);
			glTexImage2D(GL_TEXTURE_2D,0,GL_R8,$n,$n,0,GL_RED,GL_UNSIGNED_BYTE,$tile->pixels([0,0,$n,$n]));
			glTexParameteri(GL_TEXTURE_2D,GL_TEXTURE_WRAP_S,GL_CLAMP_TO_EDGE);
			glTexParameteri(GL_TEXTURE_2D,GL_TEXTURE_WRAP_T,GL_CLAMP_TO_EDGE);
			glTexParameteri(GL_TEXTURE_2D,GL_TEXTURE_MIN_FILTER,GL_LINEAR_MIPMAP_LINEAR);
			glTexParameteri(GL_TEXTURE_2D,GL_TEXTURE_MAG_FILTER,GL_LINEAR);
		} else {
			[, $id,$revision]=$this->textures[$key];
			glBindTexture(GL_TEXTURE_2D,$id);
			if($revision===$tile->revision) return;
			[$l,$t,$r,$b]=$tile->dirtyRect() ?? [0,0,$n,$n];
			// R8 rows use GLES's default four-byte unpack alignment.
			$l &= ~3; $r=($r+3)&~3;
			glTexSubImage2D(GL_TEXTURE_2D,0,$l,$t,$r-$l,$b-$t,GL_RED,GL_UNSIGNED_BYTE,$tile->pixels([$l,$t,$r,$b]));
			$this->textures[$key][2]=$tile->revision;
		}
		glGenerateMipmap(GL_TEXTURE_2D);
		$tile->uploaded();
	}

	public function close(): void
	{
		if($this->textures) {
			$ids=array_column($this->textures,1);
			glDeleteTextures(count($ids),$ids);
			$this->textures=[];
		}
		$this->program?->close(); $this->program=null; $this->scene=null;
	}
}
