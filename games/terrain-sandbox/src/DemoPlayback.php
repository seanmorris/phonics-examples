<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Scene;
use TerrainPhonics\Landscape;

/** A playback owns its world and graphics cache. The parked live session keeps its physics and edit history. */
final class DemoPlayback
{
	public readonly Session $world;
	public readonly Scene $scene;
	public float $shown = -1.;
	public bool $ready = false;
	public Landscape $terrain;
	public array $hiddenGates = [];
	private int $chapter = -1;
	private array $sun = [];
	private ?array $previous = null;

	public function __construct(DemoTape $tape)
	{
		$this->world = new Session;
		$this->loadWorld($tape->world(0));
		$this->scene = new Scene($this->terrain,$this->world->water,$this->world->atmosphere,useClipmap:true,retainJourneyBinding:true);
		$this->scene->weather=$this->world->weather;
		$this->chapter = 0;
		$this->appearance();
	}

	public static function restoreWorld(Session $s,string $document): void
	{
		$s->load($document);
		$preview = json_decode($document,true,64,JSON_THROW_ON_ERROR)['demoPreview'] ?? [];
		$actions = [];
		foreach($preview['layers'] ?? [] as $id) $actions[] = ['type'=>'surface-visibility','id'=>$id,'visible'=>false];
		if($preview['roads'] ?? []) {
			$editor = ['type'=>'road-editor','generation'=>$s->generation,'revision'=>$s->landscape->revision()];
			$actions[] = $editor + ['op'=>'open'];
			foreach($preview['roads'] as $id) $actions[] = $editor + ['op'=>'visibility','id'=>$id,'visible'=>false];
			$actions[] = $editor + ['op'=>'close'];
		}
		if($actions) $s->input(['actions'=>$actions],0.,1.);
	}

	private function loadWorld(string $document): void
	{
		$s=$this->world;
		self::restoreWorld($s,$document);
		$this->terrain = $s->roadEditor->displayedLandscape($s->landscape);
		$this->hiddenGates = $s->roadEditor->hiddenGates($s->landscape,$s->course,$s->usingFloodedCircuit());
	}

	private function appearance(): void
	{
		$this->scene->quality = $this->world->settings['quality'];
		$this->scene->appearance = $this->world->terrainAppearance;
		$this->scene->layers = $this->world->displayedLayers();
		$this->scene->paint = $this->world->paint;
	}

	public function apply(DemoTape $tape, array $frame): void
	{
		$s = $this->world;
		if($frame['world'] !== $this->chapter) {
			$this->loadWorld($tape->world($frame['world']));
			$this->scene->setLandscape($this->terrain);
			$this->appearance();
			$this->chapter = $frame['world'];
			$this->ready = false;
			$this->sun = [];
		}
		$before = $this->previous;
		$dt = $frame['time']-($before['time'] ?? $frame['time']);
		if(!$before || $before['mode']!==$frame['mode'] || $before['world']!==$frame['world'] || $dt<0 || $dt>.25) {
			$s->tireDust = new TireDust;
			$s->flightTrails = $frame['mode']==='jet' ? new FlightTrails : null;
			$tape->restorePatrolSmoke($s->airPatrol,$tape->playhead ?? 0.,$frame);
		} elseif($dt>0) {
			if($frame['mode']==='car') {
				$s->tireDust->step($dt,$frame['events']['contacts'] ?? [],$frame['vehicle']);
				$s->airPatrol->stepSmoke($dt,$before['events']['airPatrol']['seeker'] ?? null,$frame['events']['airPatrol']['seeker'] ?? null,$frame['vehicle'],$before['vehicle'],
					$before['events']['airPatrol']['pose'] ?? null,$frame['events']['airPatrol']['pose'] ?? null,
					($frame['events']['airPatrol']['phase'] ?? '')==='glide' ? $frame['events']['airPatrol']['flight'][0] : null);
			}
			$s->flightTrails?->step($dt,$before['vehicle'],$frame['vehicle']);
		}
		$this->previous = $frame;
		$s->time = $frame['time'];
		$weather=$frame['events']['weather'] ?? null;
		if($weather !== null && ($weather !== ($before['events']['weather'] ?? null) || $before['world'] !== $frame['world']))
			$s->weather->restore($weather);
		$stars=$frame['events']['stars'] ?? null;
		if($stars !== null && ($stars !== ($before['events']['stars'] ?? null) || $before['world'] !== $frame['world']))
			$s->setStars(new \TerrainPhonics\Starfield(...$stars));
		$s->mode = $frame['mode'];
		$s->camera = $frame['camera'];
		$s->jetView = ($frame['flags'] & 1) ? 'cockpit' : 'chase';
		$s->jetMap = $s->carMap = (bool)($frame['flags'] & 2);
		$s->jetWideMap = $s->carWideMap = (bool)($frame['flags'] & 4);
		$s->water->update($s->time);
		if($s->course->floats()) $s->course->float($s->water,$s->time);
		$sun = [$frame['azimuth'],$frame['elevation']];
		if($sun !== $this->sun) {
			[$a,$e] = array_map('deg2rad',$sun);
			$s->atmosphere->setSun([sin($a)*cos($e),sin($e),cos($a)*cos($e)]);
			$this->sun = $sun;
		}
	}

	public function close(): void { $this->scene->close(); $this->world->close(); }
}
