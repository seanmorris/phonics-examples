<?php
declare(strict_types=1);
namespace TerrainSandbox;

use Phonics\Render\Program;
use TerrainPhonics\{Atmosphere,Camera,Landscape,SpotLights};

/** A shared mesh and texture atlas for the few journey signs within sight of the driver. */
final class JourneySignModel
{
	private const ASSETS=__DIR__.'/../assets/roads/';
	private ?Landscape $land=null;
	private int $revision=-1;
	private bool $stage=false;
	private ?\Generator $build=null;
	private array $sites=[], $art=[], $mileMarkers=[];
	private ?Program $program=null;
	private ?BakedMesh $mesh=null;
	private SpotLights $lamps;
	public int $count=0, $drawn=0;

	public function __construct() { $this->lamps=new SpotLights; }

	public function update(Landscape $land,bool $journeyStage=false): bool
	{
		$changed=false;
		if($land!==$this->land || $land->revision()!==$this->revision || $journeyStage!==$this->stage) {
			$changed=$this->count>0;
			$this->sites=$this->mileMarkers=[]; $this->count=$this->drawn=0;
			$this->land=$land; $this->revision=$land->revision(); $this->stage=$journeyStage;
			$this->build=JourneySigns::layout($land,$journeyStage);
		}
		if($this->build===null) return $changed;
		$until=hrtime(true)+800_000;
		while($this->build->valid()) {
			$this->build->next();
			if(hrtime(true)>=$until) return $changed;
		}
		$this->sites=$this->build->getReturn(); $this->build=null;
		$this->count=count($this->sites);
		foreach($this->sites as $site) if($site['kind']==='mile') {
			[$x,,$z]=$site['position'];
			$this->mileMarkers[]=[$x,$z];
		}
		return $changed || $this->count>0;
	}

	/** World XZ positions of the mileage gantries in the current visible road layout. */
	public function mileMarkers(): array { return $this->mileMarkers; }

	private function load(): void
	{
		$fragment=str_replace('// RETROREFLECTION',file_get_contents(self::ASSETS.'retroreflection.glsl'),file_get_contents(self::ASSETS.'journey-sign.frag'));
		$this->program=new Program(file_get_contents(self::ASSETS.'journey-sign.vert'),$fragment);
		$this->mesh=new BakedMesh(self::ASSETS.'journey-signs.mesh.bin.gz');
	}

	/** Fit Highway Gothic's actual glyph widths into the text area of the supplied mileage artwork. */
	private function lettering(float $meters): array
	{
		[$value,$unit]=JourneySigns::legend($meters);
		$tokens=[...str_split($value),$unit]; $slots=[]; $pen=0.;
		foreach($tokens as $token) {
			if($token===$unit) $pen+=8.;
			[$x,$w]=$this->art['glyphs'][$token];
			$slots[]=[$pen,$w,($x+.5)/$this->art['atlasWidth'],($w-1)/$this->art['atlasWidth']];
			$pen+=$w+2.;
		}
		$advance=$pen-2.; $scale=min(.0238,5.8/$advance);
		$data=[];
		foreach($slots as [$left,$width,$u,$du])
			array_push($data,1.735+($left-.5*$advance)*$scale,$width*$scale,$u,$du);
		return array_pad($data,32,0.);
	}

	public function draw(Camera $camera,Atmosphere $sky,float $water,int $w,int $h,array $lights): void
	{
		$this->drawn=0;
		if(!$this->sites) return;
		if(!$this->art) $this->art=json_decode(file_get_contents(self::ASSETS.'journey-signs.json'),true,flags:JSON_THROW_ON_ERROR);
		$eye=$camera->position; $planes=null;
		foreach($this->sites as $i=>$site) {
			[$x,$y,$z]=$site['position']; $kind=$site['kind'];
			if(($x-$eye[0])**2+($y-$eye[1])**2+($z-$eye[2])**2>1800.**2) continue;
			$mount=$this->art['mounts'][$kind] ?? null;
			$span=($site['width'] ?? 0.)+2.; $scale=1.; $lift=0.; $r=2.; $top=4.25;
			if($mount) {
				$scale=min(1.,$span/$mount['fitWidth']);
				$span=max($span,$mount['span']*$scale);
				$lift=$mount['lift']*$scale;
				$r=$span*.5+2.; $top=max(8.5+$lift,7.15+($mount['top']-7.15)*$scale);
			}
			if($y+$top<$water || !Camera::inView($planes ??= $camera->clipPlanes($w,$h),$eye,
				['x'=>$x-$r,'z'=>$z-$r,'size'=>2.*$r],$y-10.,$y+$top)) continue;
			if(!$this->drawn) {
				if(!$this->mesh) $this->load();
				$p=$this->program; $p->use(); $this->mesh->bind();
				$env=$sky->uniforms(); $sun=$env['sunDirection'];
				glUniformMatrix4fv($p->location('vp'),1,false,$camera->viewProjection($w,$h));
				glUniform3f($p->location('eye'),...$eye); glUniform3f($p->location('sun'),...$sun);
				glUniform1f($p->location('day'),max(0.,min(1.,($sun[1]+.10)/.22)));
				glUniform1f($p->location('waterLevel'),$water); glUniform1i($p->location('artwork'),0);
				glUniform4f($p->location('textV'),$this->art['glyphV'],$this->art['glyphHeight'],$this->art['greenScale'],$this->art['panelBottom']);
				$this->lamps->set(...$lights); $this->lamps->bind($p,$env['nightVisibility']);
				glEnable(GL_DEPTH_TEST); glDepthMask(true); glDisable(GL_CULL_FACE);
			}
			[$tx,$tz]=$site['heading'];
			if(!isset($site['feet'])) {
				$ground=$mount===null ? $y : $this->land->height($x,$z);
				$offset=$span*.5+.4;
				$this->sites[$i]['ground']=$ground;
				$this->sites[$i]['feet']=$mount===null ? [0.,0.] : [
					$this->land->height($x+$tz*$offset,$z-$tx*$offset)-$ground,
					$this->land->height($x-$tz*$offset,$z+$tx*$offset)-$ground];
			}
			glUniform3f($p->location('foot'),$x,$this->sites[$i]['ground'],$z);
			glUniform2f($p->location('tangent'),$tx,$tz);
			glUniform2f($p->location('feet'),...$this->sites[$i]['feet']);
			glUniform1f($p->location('widthDelta'),.5*$span-6.662);
			glUniform2f($p->location('panelFit'),$scale,$lift);
			if($kind==='mile') {
				$letters=$this->sites[$i]['letters'] ??= $this->lettering($site['remaining']);
				glUniform4fv($p->location('textSlots[0]'),8,$letters);
			}
			$this->mesh->drawRange(...$this->art['ranges'][$site['caption'] ?? $kind]);
			$this->drawn++;
		}
		if($this->drawn) glBindVertexArray(0);
	}

	public function close(): void
	{
		$this->mesh?->close(); $this->mesh=null;
		$this->program?->close(); $this->program=null;
		$this->lamps->forget();
		$this->land=$this->build=null; $this->sites=$this->art=$this->mileMarkers=[]; $this->count=$this->drawn=0;
	}
}
