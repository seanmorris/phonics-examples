<?php
declare(strict_types=1);
namespace TerrainSandbox;

use Phonics\Render\Program;
use TerrainPhonics\Atmosphere;
use TerrainPhonics\Camera;
use TerrainPhonics\Landscape;
use TerrainPhonics\SpotLights;

/** Static instanced sign rows, grouped into spatial cells so distant bends cost no draw calls. */
final class CurveSignModel
{
	private const CELL = 256.;
	private const TIER_HEIGHT = 5.65; // 5.30 m diamond and a 0.35 m gap.
	private const SITE_BYTES = 28; // XZ, heading XZ, signed artwork, panel lift, pole flag.
	private const MAX_HEIGHT = 6.90; // Diamond bottom (1.60 m) plus its 5.30 m face.
	private const RANGE = 1000.;
	private const ASSETS = __DIR__.'/../assets/roads/';
	private ?Landscape $land = null;
	private int $revision = -1;
	private ?\Generator $build = null;
	private array $cells = [], $meshes = [], $fits = [], $texture = [], $shape = [];
	private ?Program $program = null;
	private SpotLights $lamps;
	public int $count = 0, $drawn = 0;
	private bool $fitting = false;

	public function __construct() { $this->lamps = new SpotLights; }

	/** Give a newly applied road a short slice each frame, including while the editor is paused. */
	public function update(Landscape $land): bool
	{
		$changed = false;
		if($land !== $this->land || $land->revision() !== $this->revision) {
			$changed = $this->count > 0;
			$this->clearCells();
			$this->land = $land; $this->revision = $land->revision();
			$this->build = $this->collect($land);
		}
		if($this->build === null) return $changed || $this->fitting;
		$until = hrtime(true)+1_000_000;
		while($this->build->valid()) {
			$this->build->next();
			if(hrtime(true) >= $until) return $changed;
		}
		$this->cells = $this->build->getReturn();
		$this->build = null;
		foreach($this->cells as $cell) $this->count += intdiv(strlen($cell['bytes']),self::SITE_BYTES);
		return $changed || $this->count > 0;
	}

	private function collect(Landscape $land): \Generator
	{
		$cells = [];
		$half=$land->recipe()['worldSize']/2;
		foreach([CurveSigns::sites($land), RoadWarnings::sites($land)] as $signs) foreach($signs as $site) {
			if($site !== null && max(abs($site[0]),abs($site[1]))<=$half) {
				[$x,$z,$tx,$tz] = $site;
				$col = (int)floor($x/self::CELL); $row = (int)floor($z/self::CELL);
				$key = "$col,$row";
				$cells[$key] ??= ['box'=>['x'=>$col*self::CELL-2.65,'z'=>$row*self::CELL-2.65,'size'=>self::CELL+5.30],'bytes'=>'','lift'=>0.];
				$last=array_key_last($site);
				for($face=4;$face<=$last;$face++) {
					// The first hazard is the top panel. That instance supplies the entire shared pole.
					$lift=($last-$face)*self::TIER_HEIGHT;
					$cells[$key]['bytes'] .= pack('g*',$x,$z,$tx,$tz,$site[$face],$lift,(float)($face===4));
				}
				$cells[$key]['lift']=max($cells[$key]['lift'],($last-4)*self::TIER_HEIGHT);
			}
			yield;
		}
		return $cells;
	}

	private function loadArtwork(): void
	{
		$fragment = str_replace('// RETROREFLECTION',file_get_contents(self::ASSETS.'retroreflection.glsl'),file_get_contents(self::ASSETS.'chevron.frag'));
		$this->program = new Program(file_get_contents(self::ASSETS.'chevron.vert'),$fragment);
		$data = gzdecode(file_get_contents(self::ASSETS.'warning-signs.rgba.gz'));
		[$w,$h] = array_values(unpack('Vwidth/Vheight',$data));
		glGenTextures(1,$this->texture);
		glActiveTexture(GL_TEXTURE0);
		glBindTexture(GL_TEXTURE_2D,$this->texture[0]);
		glTexImage2D(GL_TEXTURE_2D,0,GL_RGBA8,$w,$h,0,GL_RGBA,GL_UNSIGNED_BYTE,substr($data,8));
		glTexParameteri(GL_TEXTURE_2D,GL_TEXTURE_MIN_FILTER,GL_LINEAR_MIPMAP_LINEAR);
		glTexParameteri(GL_TEXTURE_2D,GL_TEXTURE_MAG_FILTER,GL_LINEAR);
		glTexParameteri(GL_TEXTURE_2D,GL_TEXTURE_WRAP_S,GL_CLAMP_TO_EDGE);
		glTexParameteri(GL_TEXTURE_2D,GL_TEXTURE_WRAP_T,GL_CLAMP_TO_EDGE);
		glGenerateMipmap(GL_TEXTURE_2D);
		$corners = str_repeat(pack('g*',0,0,1,0,1,1,0,0,1,1,0,1),3);
		glGenBuffers(1,$this->shape); glBindBuffer(GL_ARRAY_BUFFER,$this->shape[0]);
		glBufferData(GL_ARRAY_BUFFER,strlen($corners),$corners,GL_STATIC_DRAW);
	}

	private function mesh(string $bytes): array
	{
		$vao = $vbo = [];
		glGenVertexArrays(1,$vao); glBindVertexArray($vao[0]);
		glBindBuffer(GL_ARRAY_BUFFER,$this->shape[0]);
		glEnableVertexAttribArray(0); glVertexAttribPointer(0,2,GL_FLOAT,false,8,0);
		glGenBuffers(1,$vbo); glBindBuffer(GL_ARRAY_BUFFER,$vbo[0]);
		glBufferData(GL_ARRAY_BUFFER,strlen($bytes),$bytes,GL_STATIC_DRAW);
		foreach([[1,3,0],[2,3,12],[3,2,24]] as [$attribute,$size,$offset]) {
			glEnableVertexAttribArray($attribute);
			glVertexAttribPointer($attribute,$size,GL_FLOAT,false,32,$offset);
			glVertexAttribDivisor($attribute,1);
		}
		return [$vao,$vbo];
	}

	/** Only visible cells need exact post footings. Keep them through camera moves and headlight changes. */
	private function fit(string $bytes): \Generator
	{
		$fitted = ''; $low = INF; $high = -INF;
		for($i = 0, $end = strlen($bytes); $i < $end; $i += self::SITE_BYTES) {
			$site = unpack('gx/gz/x12/glift',$bytes,$i);
			$y = $this->land->height($site['x'],$site['z']);
			$fitted .= substr($bytes,$i,4).pack('g',$y).substr($bytes,$i+4,self::SITE_BYTES-4);
			$low = min($low,$y-.3); $high = max($high,$y+self::MAX_HEIGHT+$site['lift']);
			yield;
		}
		return [$fitted,$low,$high];
	}

	public function draw(Camera $camera, Atmosphere $sky, float $water, int $w, int $h, array $lights, ?array $bolt = null): void
	{
		$this->drawn = 0;
		$this->fitting = false;
		if(!$this->cells) return;
		$eye = $camera->position;
		$planes = $camera->clipPlanes($w,$h);
		$bounds = $this->land->bounds();
		$fitBudget = 1_000_000;
		foreach($this->cells as $key=>$cell) {
			$fitted = isset($this->meshes[$key]);
			$low = $fitted ? $cell['low'] : $bounds['minY']-.3;
			$high = $fitted ? $cell['high'] : $bounds['maxY']+self::MAX_HEIGHT+$cell['lift'];
			if($high < $water) continue;
			$box = $cell['box'];
			$dx = max(0.,$box['x']-$eye[0],$eye[0]-$box['x']-$box['size']);
			$dz = max(0.,$box['z']-$eye[2],$eye[2]-$box['z']-$box['size']);
			$dy = max(0.,$low-$eye[1],$eye[1]-$high);
			if($dx*$dx+$dz*$dz+$dy*$dy > self::RANGE**2 || !Camera::inView($planes,$eye,$box,$low,$high)) continue;
			if(!$fitted) {
				// Drawing resident cells must not consume the time reserved for fitting and uploading new ones.
				if($fitBudget <= 0) { $this->fitting = true; continue; }
				$until = hrtime(true)+$fitBudget;
				$fit = $this->fits[$key] ??= $this->fit($cell['bytes']);
				while($fit->valid() && hrtime(true) < $until) $fit->next();
				if($fit->valid()) { $fitBudget = 0; $this->fitting = true; continue; }
				[$bytes,$this->cells[$key]['low'],$this->cells[$key]['high']] = $fit->getReturn();
				unset($this->fits[$key]);
				if($this->program === null) $this->loadArtwork();
				$this->meshes[$key] = $this->mesh($bytes);
				$fitBudget = $until-hrtime(true);
			}
			if($this->drawn === 0) {
				$p = $this->program; $p->use();
				$env = $sky->uniforms(); $sun = $env['sunDirection'];
				glUniformMatrix4fv($p->location('vp'),1,false,$camera->viewProjection($w,$h));
				glUniform3f($p->location('eye'),...$eye);
				glUniform3f($p->location('sun'),...$sun);
				glUniform1f($p->location('day'),max(0.,min(1.,($sun[1]+.10)/.22)));
				glUniform1f($p->location('waterLevel'),$water);
				// A lit lightning strike flashes the sheeting. With none the strength is zero.
				glUniform4f($p->location('uBoltFlash'),...($bolt ?? [0.,0.,0.,0.]));
				$this->lamps->set(...$lights);
				$this->lamps->bind($p,$env['nightVisibility']);
				glActiveTexture(GL_TEXTURE0); glBindTexture(GL_TEXTURE_2D,$this->texture[0]);
				glUniform1i($p->location('artwork'),0);
				glEnable(GL_DEPTH_TEST); glDepthMask(true); glDisable(GL_CULL_FACE);
			}
			[$vao] = $this->meshes[$key];
			glBindVertexArray($vao[0]);
			$count = intdiv(strlen($cell['bytes']),self::SITE_BYTES);
			glDrawArraysInstanced(GL_TRIANGLES,0,18,$count);
			$this->drawn += $count;
		}
		if($this->drawn) glBindVertexArray(0);
	}

	private function clearCells(): void
	{
		foreach($this->meshes as [$vao,$vbo]) {
			glDeleteVertexArrays(1,$vao);
			glDeleteBuffers(1,$vbo);
		}
		$this->cells = $this->meshes = $this->fits = [];
		$this->fitting = false;
		$this->count = $this->drawn = 0;
	}

	public function close(): void
	{
		$this->clearCells();
		if($this->texture) glDeleteTextures(1,$this->texture);
		if($this->shape) glDeleteBuffers(1,$this->shape);
		$this->texture = $this->shape = []; $this->program?->close(); $this->program = null;
		$this->lamps->forget();
		$this->build = $this->land = null;
	}
}
