<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Camera;
use TerrainPhonics\Landscape;

/**
 * Arcade flight after AirForce Delta on the Dreamcast, with a world-up chase camera.
 * Novice banks into turns and levels itself when the stick is released. Expert rolls, pitches and yaws about the
 * jet's own axes and holds its attitude. In both, the speed controls work like the game's triggers: the jet speeds
 * up or slows down while one is held and returns to cruise when it is released.
 * Coordinates are meters, angles are radians.
 */
final class Jet
{
	private const BOOM = 24.0;
	/** Speeds in meters a second, and how fast the jet changes speed, in meters a second squared. */
	public const CRUISE = 140.0, FASTEST = 260.0, SLOWEST = 55.0, ACCELERATION = 55.0;
	public const CRUISE_POWER = self::CRUISE / self::FASTEST;
	/** Time constants in seconds for the engine and the airspeed that follows it. */
	private const SPOOL_UP = .36, SPOOL_DOWN = .27, SPEED_LAG = .18;
	/** Seconds for the direction of travel to close about two thirds of a small change in nose direction. */
	private const FLIGHT_PATH_LAG = .36;
	/** Expert body rates at full deflection in radians a second, and the time constant with which they follow it. */
	public const ROLL_RATE = 2.8, PITCH_RATE = 1.05, YAW_RATE = .35, RATE_LAG = .12;
	/** Time constant in seconds with which the Expert chase camera swings in behind the nose. */
	private const CHASE_LAG = .22;
	/** Fastest the Expert chase camera turns, in radians a second, so it swings round when the nose passes vertical. */
	private const CHASE_TURN = 4.0;
	/** Steeper than about 35 degrees, the wreck pushes against the hillside instead of riding up it. */
	private const LANDING_GRADE = .7;
	/** Underside contact points in model space: belly, tail, nose and both wingtips. */
	private const SKIDS = [[0.,-.83,-2.30],[0.,-.49,-6.27],[0.,-.20,8.],[-4.17,-.48,-2.16],[4.17,-.48,-2.16]];
	/** Smallest and largest square blocks, in lattice cells, whose height bound can replace an exact sample. */
	private const FINEST = 2, COARSEST = 256;
	/** Steps to fly on after a block rises too close before asking about a larger one. */
	private const PATIENCE = 8;
	/** Full travel of the control surfaces in radians: 20 degrees of aileron, 15 of stabilator and 10 of rudder. */
	public const AILERON = .3490658504, STABILATOR = .2617993878, RUDDER = .1745329252;
	/** Actuators follow their command with this time constant in seconds, and at 60 degrees a second at most. */
	public const SURFACE_LAG = .08, SURFACE_RATE = 1.0471975512;
	private array $pose = [], $previous = [];
	/** Whether the jet flies Expert's three axes rather than Novice's banked turns. */
	private bool $expert = false;
	/** Expert roll, pitch and yaw rates in radians a second: right roll, nose up and nose right are positive. */
	private array $rates = [0.0, 0.0, 0.0];
	/** Free-flight direction before terrain or the ceiling constrains a step. */
	private array $flightDirection = [0.0, 0.0, 1.0];
	/** Unit direction the Expert chase camera looks along, lagging the nose. */
	private array $chase = [0.0, 0.0, 1.0];
	/** Surface and revision that the cached limits and blocks below describe. */
	private ?Landscape $surveyed = null;
	private int $revision = 0;
	private float $half = 0.0, $roof = 0.0;
	/** Last terrain block consulted for the fuselage and for the chase eye. */
	private array $body = [], $eye = [];

	public function board(Camera $camera, Landscape $land, float $water, float $clearance = 75.): void
	{
		$this->survey($land);
		[$x,$y,$z] = $camera->position;
		$half = $this->half;
		$x = max(-$half,min($half,$x));
		$z = max(-$half,min($half,$z));
		$yaw = M_PI - $camera->yaw;
		$this->flightDirection = $this->chase = self::axes($yaw, 0.0, 0.0)[2];
		$this->pose = ['x'=>$x, 'y'=>max($y, max($water,$land->height($x,$z)) + $clearance), 'z'=>$z,
			'yaw'=>$yaw, 'pitch'=>0.0, 'roll'=>0.0, 'speed'=>self::CRUISE, 'power'=>self::CRUISE_POWER,
			'vx'=>self::CRUISE*$this->chase[0], 'vy'=>0.0, 'vz'=>self::CRUISE*$this->chase[2],
			'aileron'=>0.0, 'stabilator'=>0.0, 'rudder'=>0.0];
		$this->previous = [];
		$this->rates = [0.0, 0.0, 0.0];
		$this->follow(0.0, $camera, $land, $water);
	}

	/**
	 * Choose Novice or Expert handling, as AirForce Delta's Controller option does. The jet keeps its attitude.
	 * Novice cannot hold the loops and inverted attitudes Expert can reach, so it takes the same orientation with
	 * the nose within 90 degrees of level and then levels the wings and nose as usual.
	 * @param bool $expert True for Expert, false for Novice.
	 * @return void Updates the handling and restarts interpolation, so the next frame draws the new pose as it is.
	 */
	public function setExpert(bool $expert): void
	{
		if($expert === $this->expert) return;
		$this->expert = $expert;
		$this->rates = [0.0, 0.0, 0.0];
		$this->previous = [];
		if(!$this->pose) return;
		$p = &$this->pose;
		[$x,$y,$z] = self::axes($p['yaw'], $p['pitch'], $p['roll']);
		if($expert) $this->chase = $z;
		else [$p['yaw'],$p['pitch'],$p['roll']] = self::angles($x, $z, $p['yaw'], 0.0, 0.0, true);
	}

	public function expert(): bool { return $this->expert; }

	/**
	 * Fly one fixed step. Every control runs from -1 to 1; the keyboard gives whole values and a gamepad any between.
	 * @param float $dt Fixed step in seconds.
	 * @param float $throttle Positive speeds up toward FASTEST, negative slows toward SLOWEST, zero returns to CRUISE.
	 * @param float $bank Positive turns or rolls right.
	 * @param float $climb Positive raises the nose.
	 * @param Camera $camera Chase camera, placed behind the jet after the step.
	 * @param Landscape $land Surface the jet keeps clear of.
	 * @param float $water Water level the jet keeps clear of.
	 * @param float $rudder Expert only: positive yaws the nose right.
	 * @return bool False on reaching the map edge, where the session hands back the free camera.
	 */
	public function step(float $dt, float $throttle, float $bank, float $climb, Camera $camera, Landscape $land,
		float $water, float $rudder = 0.0): bool
	{
		if(!$this->fly($dt,$throttle,$bank,$climb,$land,$water,$rudder)) return false;
		$this->follow($dt,$camera,$land,$water);
		return true;
	}

	/** Advance the airframe and control surfaces one tick, independent of who is piloting or watching it. */
	public function fly(float $dt, float $throttle, float $bank, float $climb, Landscape $land,
		float $water, float $rudder = 0., float $idleSpeed = self::SLOWEST): bool
	{
		$this->survey($land);
		$this->previous = $this->pose;
		$p = &$this->pose;
		$command = self::CRUISE_POWER + $throttle * ($throttle < 0 ? self::CRUISE_POWER : 1 - self::CRUISE_POWER);
		$powerGap = $command - $p['power'];
		$lag = $powerGap > 0 ? self::SPOOL_UP : self::SPOOL_DOWN;
		$p['power'] += $powerGap * (1 - exp(-$dt / $lag));
		if(abs($command - $p['power']) < .0005) $p['power'] = $command;

		$target = $p['power'] < self::CRUISE_POWER
			? $idleSpeed + (self::CRUISE - $idleSpeed) * ($p['power'] / self::CRUISE_POWER)
			: self::CRUISE + (self::FASTEST - self::CRUISE) * (($p['power'] - self::CRUISE_POWER) / (1 - self::CRUISE_POWER));
		$gap = $target - $p['speed'];
		$change = min(self::ACCELERATION * $dt, abs($gap) * (1 - exp(-$dt / self::SPEED_LAG)));
		$p['speed'] = abs($gap) < .04 ? $target : $p['speed'] + ($gap <=> 0) * $change;
		$travel = $p['speed'] * $dt;
		if($this->expert)
			[, , $nose] = $this->rotate($dt, $bank, $climb, $rudder);
		else
		{
			// Roll and pitch close on their targets at a rate in proportion to the angle still to go.
			$rollDemand = -$bank * 1.10 - $p['roll'];
			$pitchDemand = $climb * .78 - $p['pitch'];
			$p['roll'] += $rollDemand * (1 - exp(-3.6 * $dt));
			$p['pitch'] += $pitchDemand * (1 - exp(-2.1 * $dt));
			$turn = sin($p['roll']) * .68;
			$p['yaw'] += $turn * $dt;
			$this->deflect($dt, $rollDemand, $pitchDemand, $turn);
			$nose = [sin($p['yaw'])*cos($p['pitch']), sin($p['pitch']), cos($p['yaw'])*cos($p['pitch'])];
		}
		// Turning the body redirects travel over time. Ground clearance can lift a single step without
		// imparting a permanent upward velocity when the terrain is edited below a flying jet.
		$follow = 1 - exp(-$dt / self::FLIGHT_PATH_LAG);
		[$dx,$dy,$dz] = $this->flightDirection;
		$dx += ($nose[0] - $dx)*$follow;
		$dy += ($nose[1] - $dy)*$follow;
		$dz += ($nose[2] - $dz)*$follow;
		$length = sqrt($dx*$dx + $dy*$dy + $dz*$dz);
		$this->flightDirection = [$dx/$length,$dy/$length,$dz/$length];
		$x = $p['x'] + $this->flightDirection[0]*$travel;
		$rise = $this->flightDirection[1]*$travel;
		$z = $p['z'] + $this->flightDirection[2]*$travel;
		$half = $this->half;
		if(abs($x) > $half || abs($z) > $half) return false;
		$p['x'] = $x; $p['z'] = $z;
		$p['y'] = min(15000.0,$p['y'] + $rise);
		if($p['y'] < $this->roof + 8) $p['y'] = $this->lift($this->body, $land, $x, $z, $p['y'], 8.0);
		$p['y'] = max($p['y'], $water + 8);
		// Measure translation after clearance and ceiling corrections. The canopy follows a different path in a roll.
		if($dt > 0)
		{
			$p['vx'] = ($x - $this->previous['x']) / $dt;
			$p['vy'] = ($p['y'] - $this->previous['y']) / $dt;
			$p['vz'] = ($z - $this->previous['z']) / $dt;
		}
		return true;
	}

	public function cutEngine(): void { $this->pose['power']=0.; }

	/** Carry the hit aircraft's velocity through a wings-level glide, flare and gear-up rollout. */
	public function landUnpowered(float $dt,Landscape $land,float $water,bool $sliding): bool
	{
		if($sliding && $this->pose['speed']===0.) return true;
		$this->previous=$this->pose;
		$recipe=$land->recipe();
		// A fast slide must meet the face before a contact point can cross it onto the plateau.
		$run=min(self::FASTEST,hypot($this->pose['vx'],$this->pose['vz']));
		$steps=max(1,(int)ceil($dt*$run/(.5*$recipe['spacing'])));
		for($i=0;$i<$steps;$i++)
			$sliding=$this->landingStep($dt/$steps,$land,$water,$recipe['worldSize']/2.,$sliding);
		return $sliding;
	}

	private function landingStep(float $dt,Landscape $land,float $water,float $half,bool $sliding): bool
	{
		$p=&$this->pose;
		$oldY=$p['y'];
		$run=hypot($p['vx'],$p['vz']);
		$speed=$sliding ? max(0.,$run-(9.+.18*$run)*$dt) : $run*exp(-.045*$dt);
		$drag=$run>0. ? $speed/$run : 0.;
		foreach(['x','z'] as $axis) {
			$v='v'.$axis; $p[$v]*=$drag;
			$next=$p[$axis]+$p[$v]*$dt;
			$p[$axis]=max(-$half,min($half,$next));
			if($p[$axis]!==$next) $p[$v]=0.;
		}
		$ground=$land->sample($p['x'],$p['z']);
		$surface=max($water,$ground['height']);
		$dx=$surface>$ground['height'] ? 0. : $ground['dx'];
		$dz=$surface>$ground['height'] ? 0. : $ground['dz'];
		if($speed>1.) {
			$yaw=atan2($p['vx'],$p['vz'])-$p['yaw'];
			$p['yaw']+=atan2(sin($yaw),cos($yaw))*(1.-exp(-$dt/.50));
		}
		if(hypot($dx,$dz)>self::LANDING_GRADE) $dx=$dz=0.;
		$along=$dx*sin($p['yaw'])+$dz*cos($p['yaw']);
		$across=$dx*cos($p['yaw'])-$dz*sin($p['yaw']);
		$clearance=$p['y']-$surface-.83;
		$flare=max(0.,min(1.,1.-$clearance/10.));
		$sink=max(3.,.11*$speed);
		$desiredVy=-$sink+(max(-$sink,min(-1.2,$speed*$along-1.6))+$sink)*$flare;
		$p['vy']+=($desiredVy-$p['vy'])*(1.-exp(-$dt/.38));
		$pitch=$sliding ? atan($along) : atan2($p['vy'],max(1.,$speed))*(1.-$flare)+.085*$flare;
		$roll=$sliding ? -atan($across*cos($pitch)) : 0.;
		$blend=$sliding && $speed===0. ? 1. : 1.-exp(-$dt/.30);
		$this->deflect($dt,$roll-$p['roll'],$pitch-$p['pitch'],0.);
		$p['roll']+=($roll-$p['roll'])*$blend;
		$p['pitch']+=($pitch-$p['pitch'])*$blend;
		$height=$oldY+$p['vy']*$dt;
		// Follow a descending runway through small changes of grade. Over a drop the wreck keeps falling.
		$reach=$sliding ? .05+$speed*$dt*self::LANDING_GRADE : 0.;
		$highestFloor=max($oldY,$height)+.05+$speed*$dt*self::LANDING_GRADE;
		$floor=$this->landingContacts($p,$height,$reach,$highestFloor,$land,$water,$half);
		$sliding=$floor>=$height-$reach;
		$p['y']=$sliding ? $floor : $height;
		if($sliding && $dt>0.) $p['vy']=min(hypot($p['vx'],$p['vz'])*self::LANDING_GRADE,($p['y']-$oldY)/$dt);
		if($sliding && hypot($p['vx'],$p['vz'])<.001) $p['vx']=$p['vy']=$p['vz']=0.;
		$p['speed']=hypot(hypot($p['vx'],$p['vz']),$p['vy']);
		if($p['speed']>.001) $this->flightDirection=[$p['vx']/$p['speed'],$p['vy']/$p['speed'],$p['vz']/$p['speed']];
		return $sliding;
	}

	/** Ground or water supports the belly vertically. A steep face removes horizontal motion into the wall. */
	private function landingContacts(array &$p,float $height,float $reach,float $highestFloor,Landscape $land,float $water,float $half): float
	{
		$axes=VehicleModel::basis($p);
		// Recheck all skids after a sideways correction, including at the corner of two walls.
		for($pass=0;$pass<4;$pass++) {
			$floor=-INF; $deepest=0.;
			foreach(self::SKIDS as [$x,$y,$z]) {
				$px=$p['x']+$axes[0]*$x+$axes[3]*$y+$axes[6]*$z;
				$py=$axes[1]*$x+$axes[4]*$y+$axes[7]*$z;
				$pz=$p['z']+$axes[2]*$x+$axes[5]*$y+$axes[8]*$z;
				$px=max(-$half,min($half,$px)); $pz=max(-$half,min($half,$pz));
				$floor=max($floor,$water-$py);
				$ground=$land->height($px,$pz);
				if($ground<=$water || $ground-$py<$height-$reach) continue;
				$g=$land->sample($px,$pz);
				$grade=hypot($g['dx'],$g['dz']);
				$support=$ground-$py;
				if($grade<=self::LANDING_GRADE && $support<=$highestFloor) {
					$floor=max($floor,$support);
					continue;
				}
				$depth=$support-$height;
				if($depth<=$deepest) continue;
				if($grade>self::LANDING_GRADE) {
					$dx=$g['dx']; $dz=$g['dz'];
				} else {
					// The nose or a wing can overlap the flat top of a shelf too high to land on from here.
					$dx=$px-$p['x']; $dz=$pz-$p['z'];
				}
				$length=hypot($dx,$dz);
				if($length<.0001) continue;
				$deepest=$depth; $nx=$dx/$length; $nz=$dz/$length;
				$backoff=min(2.,$depth/($grade>self::LANDING_GRADE ? $grade : 1.));
			}
			if($deepest<=.001) break;
			// Correct penetration along the horizontal gradient. A glancing impact keeps its sideways travel.
			$p['x']=max(-$half,min($half,$p['x']-$nx*$backoff));
			$p['z']=max(-$half,min($half,$p['z']-$nz*$backoff));
			$into=max(0.,$p['vx']*$nx+$p['vz']*$nz);
			$p['vx']-=$into*$nx; $p['vz']-=$into*$nz;
		}
		return $floor;
	}

	/**
	 * Turn the jet about its own axes for one Expert step, then move the control surfaces with the stick.
	 * @param float $dt Fixed step in seconds.
	 * @param float $roll Positive rolls right.
	 * @param float $pitch Positive raises the nose.
	 * @param float $yaw Positive yaws the nose right.
	 * @return array The jet's left, up and forward unit vectors after the step.
	 */
	private function rotate(float $dt, float $roll, float $pitch, float $yaw): array
	{
		$p = &$this->pose;
		$r = &$this->rates;
		$follow = 1 - exp(-$dt / self::RATE_LAG);
		$r[0] += ($roll * self::ROLL_RATE - $r[0]) * $follow;
		$r[1] += ($pitch * self::PITCH_RATE - $r[1]) * $follow;
		$r[2] += ($yaw * self::YAW_RATE - $r[2]) * $follow;
		[$left,$up,$nose] = self::axes($p['yaw'], $p['pitch'], $p['roll']);
		// The wings hold up only the part of the weight along their own up axis. The rest slews the nose toward the
		// lower wing, so a banked jet turns gently without a pull and a knife-edge one slides off.
		$slew = 9.81 * $left[1] / max(1.0, $p['speed']);
		self::turn($nose, $up, $r[1] * $dt);
		self::turn($left, $up, $r[0] * $dt);
		self::turn($nose, $left, -($r[2] + $slew) * $dt);
		// Remove the rounding the turns leave so the axes stay square and of unit length.
		$length = sqrt($nose[0]*$nose[0] + $nose[1]*$nose[1] + $nose[2]*$nose[2]);
		foreach($nose as $i => $value) $nose[$i] = $value / $length;
		$along = $left[0]*$nose[0] + $left[1]*$nose[1] + $left[2]*$nose[2];
		foreach($left as $i => $value) $left[$i] = $value - $along * $nose[$i];
		$length = sqrt($left[0]*$left[0] + $left[1]*$left[1] + $left[2]*$left[2]);
		foreach($left as $i => $value) $left[$i] = $value / $length;
		[$p['yaw'],$p['pitch'],$p['roll']] = self::angles($left, $nose, $p['yaw'], $p['pitch'], $p['roll']);
		// A positive aileron lowers the right wing's trailing edge, which rolls left, so right stick asks for a
		// negative one. A positive rudder yaws the nose left.
		$p['aileron'] = self::actuate($p['aileron'], -self::AILERON * $roll, self::AILERON, $dt);
		$p['stabilator'] = self::actuate($p['stabilator'], self::STABILATOR * $pitch, self::STABILATOR, $dt);
		$p['rudder'] = self::actuate($p['rudder'], -self::RUDDER * $yaw, self::RUDDER, $dt);
		return [$left, [$nose[1]*$left[2] - $nose[2]*$left[1], $nose[2]*$left[0] - $nose[0]*$left[2],
			$nose[0]*$left[1] - $nose[1]*$left[0]], $nose];
	}

	/**
	 * Rotate one axis toward another in their shared plane.
	 * @param array $a Axis to rotate toward $b, updated in place.
	 * @param array $b Axis $a turns toward, updated in place to stay square to it.
	 * @param float $angle Rotation in radians.
	 * @return void
	 */
	private static function turn(array &$a, array &$b, float $angle): void
	{
		$c = cos($angle); $s = sin($angle);
		foreach($a as $i => $value)
		{
			$a[$i] = $value * $c + $b[$i] * $s;
			$b[$i] = $b[$i] * $c - $value * $s;
		}
	}

	/**
	 * The jet's axes for a heading, pitch and roll, as VehicleModel draws them.
	 * @param float $yaw Heading; zero flies toward +Z.
	 * @param float $pitch Positive raises the nose.
	 * @param float $roll Positive lowers the left (+X) wing.
	 * @return array Left (+X), up and forward unit vectors in world space.
	 */
	private static function axes(float $yaw, float $pitch, float $roll): array
	{
		$cy = cos($yaw); $sy = sin($yaw); $cp = cos($pitch); $sp = sin($pitch); $cr = cos($roll); $sr = sin($roll);
		return [[$cy*$cr + $sy*$sp*$sr, -$cp*$sr, -$sy*$cr + $cy*$sp*$sr],
			[$cy*$sr - $sy*$sp*$cr, $cp*$cr, -$sy*$sr - $cy*$sp*$cr], [$sy*$cp, $sp, $cy*$cp]];
	}

	/**
	 * Recover heading, pitch and roll from the jet's axes, continuing smoothly from the previous angles.
	 * Every orientation has two such sets, one with the nose within 90 degrees of level and one past it with the
	 * heading and roll turned half round. Taking whichever lies nearer the previous step keeps the angles continuous
	 * through loops and rolls, so a pose interpolates between steps without spinning.
	 * @param array $left Left (+X) unit vector.
	 * @param array $nose Forward unit vector.
	 * @param float $yaw Previous heading in radians.
	 * @param float $pitch Previous pitch in radians.
	 * @param float $roll Previous roll in radians.
	 * @param bool $upright Take only the set with the nose within 90 degrees of level, as Novice needs.
	 * @return array Heading, pitch and roll in radians, each within half a turn of its previous value.
	 */
	private static function angles(array $left, array $nose, float $yaw, float $pitch, float $roll,
		bool $upright = false): array
	{
		$level = sqrt($nose[0]*$nose[0] + $nose[2]*$nose[2]);
		$best = null;
		foreach($upright ? [1.0] : [1.0, -1.0] as $side)
		{
			// Straight up or down the heading is free; keep the previous one and let roll take up the rest.
			$y = self::near($level > 1e-12 ? atan2($side * $nose[0], $side * $nose[2]) : $yaw, $yaw);
			$p = self::near(atan2($nose[1], $side * $level), $pitch);
			$cy = cos($y); $sy = sin($y); $cp = cos($p); $sp = sin($p);
			// The left axis is cos(roll) along (cy, 0, -sy) plus sin(roll) along (sy*sp, -cp, cy*sp).
			$r = self::near(atan2($left[0]*$sy*$sp - $left[1]*$cp + $left[2]*$cy*$sp, $left[0]*$cy - $left[2]*$sy), $roll);
			$distance = abs($y - $yaw) + abs($p - $pitch) + abs($r - $roll);
			if($best === null || $distance < $best[3]) $best = [$y, $p, $r, $distance];
		}
		return [$best[0], $best[1], $best[2]];
	}

	/** Add whole turns to an angle to bring it within half a turn of a reference. */
	private static function near(float $angle, float $reference): float
	{
		return $angle + 2 * M_PI * round(($reference - $angle) / (2 * M_PI));
	}

	/**
	 * Move the control surfaces for the attitude change asked of this step. They animate the model and nothing else.
	 * A positive aileron raises the +X trailing edge and lowers the other, a positive stabilator raises both of its
	 * trailing edges and a positive rudder swings toward +X.
	 * @param float $dt Fixed step in seconds.
	 * @param float $rollDemand Roll still to go to the commanded bank, in radians. Positive lowers the +X wing.
	 * @param float $pitchDemand Pitch still to go to the commanded climb, in radians. Positive raises the nose.
	 * @param float $turn Yaw rate of the coordinated turn in radians a second. Positive turns the nose toward +X.
	 * @return void Updates the aileron, stabilator and rudder angles in the pose, in radians.
	 */
	private function deflect(float $dt, float $rollDemand, float $pitchDemand, float $turn): void
	{
		$p = &$this->pose;
		// Full aileron until the roll still to go falls to a quarter of the full bank, and full stabilator likewise
		// for the climb. The rudder follows the turn rate and reaches its stop in the steepest bank.
		$p['aileron'] = self::actuate($p['aileron'], self::AILERON * $rollDemand / (.25 * 1.10), self::AILERON, $dt);
		$p['stabilator'] = self::actuate($p['stabilator'], self::STABILATOR * $pitchDemand / (.25 * .78),
			self::STABILATOR, $dt);
		$p['rudder'] = self::actuate($p['rudder'], self::RUDDER * $turn / (.68 * sin(1.10)), self::RUDDER, $dt);
	}

	/**
	 * Advance one control surface toward its command as an actuator would: a short lag, then a rate limit.
	 * @param float $angle Current deflection in radians.
	 * @param float $command Requested deflection in radians, which may lie beyond the surface's travel.
	 * @param float $travel Largest deflection either way, in radians.
	 * @param float $dt Fixed step in seconds.
	 * @return float The deflection after this step, in radians.
	 */
	private static function actuate(float $angle, float $command, float $travel, float $dt): float
	{
		$move = (max(-$travel, min($travel, $command)) - $angle) * (1 - exp(-$dt / self::SURFACE_LAG));
		$rate = self::SURFACE_RATE * $dt;
		return $angle + max(-$rate, min($rate, $move));
	}

	private function follow(float $dt, Camera $camera, Landscape $land, float $water): void
	{
		if($this->expert)
		{
			$this->chaseExpert($dt, $camera, $land, $water);
			return;
		}
		$p = &$this->pose;
		// Raise the camera behind a descending jet and keep the horizon level through banked turns.
		$angle = .38 - .75 * $p['pitch'];
		$p['boom'] = self::BOOM * cos($angle);
		$p['eyeY'] = max($water + 3, $p['y'] + self::BOOM * sin($angle));
		if($p['eyeY'] < $this->roof + 3)
		{
			$half = $this->half;
			$x = max(-$half,min($half,$p['x'] - $p['boom'] * sin($p['yaw'])));
			$z = max(-$half,min($half,$p['z'] - $p['boom'] * cos($p['yaw'])));
			$p['eyeY'] = $this->lift($this->eye, $land, $x, $z, $p['eyeY'], 3.0);
		}
		$this->placeCamera($camera,$p);
		$p['eyeX'] = $camera->position[0]; $p['eyeZ'] = $camera->position[2];
		$p['lookYaw'] = $camera->yaw; $p['lookPitch'] = $camera->pitch;
	}

	/**
	 * Place the Expert chase camera. It swings in behind the nose with a short lag and keeps the horizon level, so
	 * it follows loops and dives that the Novice camera, which only looks along the heading, cannot.
	 * @param float $dt Fixed step in seconds; zero places the camera directly behind the nose.
	 * @param Camera $camera Camera to place.
	 * @param Landscape $land Surface the eye keeps clear of.
	 * @param float $water Water level the eye keeps clear of.
	 * @return void Records the eye and its view direction in the pose and places the camera there.
	 */
	private function chaseExpert(float $dt, Camera $camera, Landscape $land, float $water): void
	{
		$p = &$this->pose;
		[, , $nose] = self::axes($p['yaw'], $p['pitch'], $p['roll']);
		$c = &$this->chase;
		$follow = $dt > 0 ? 1 - exp(-$dt / self::CHASE_LAG) : 1.0;
		foreach($c as $i => $value) $c[$i] = $value + ($nose[$i] - $value) * $follow;
		$length = sqrt($c[0]*$c[0] + $c[1]*$c[1] + $c[2]*$c[2]);
		if($length < 1e-9) $c = $nose;
		else foreach($c as $i => $value) $c[$i] = $value / $length;
		$half = $this->half;
		$x = max(-$half, min($half, $p['x'] - self::BOOM * $c[0]));
		$z = max(-$half, min($half, $p['z'] - self::BOOM * $c[2]));
		$y = max($water + 3, $p['y'] - self::BOOM * $c[1] + 5);
		if($y < $this->roof + 3) $y = $this->lift($this->eye, $land, $x, $z, $y, 3.0);
		// Aim a little ahead of the jet.
		$ax = $p['x'] + 8 * $nose[0] - $x; $ay = $p['y'] + 1.5 + 8 * $nose[1] - $y; $az = $p['z'] + 8 * $nose[2] - $z;
		$pitch = max(-1.45, min(1.45, atan2($ay, sqrt($ax*$ax + $az*$az))));
		$yaw = atan2($ax, -$az);
		if(isset($p['lookYaw']) && $dt > 0)
		{
			$yaw = self::near($yaw, $p['lookYaw']);
			$turn = self::CHASE_TURN * $dt;
			$yaw = $p['lookYaw'] + max(-$turn, min($turn, $yaw - $p['lookYaw']));
		}
		$p['eyeX'] = $x; $p['eyeY'] = $y; $p['eyeZ'] = $z; $p['lookYaw'] = $yaw; $p['lookPitch'] = $pitch;
		$p['boom'] = self::BOOM;
		$this->placeCamera($camera, $p);
	}

	/**
	 * Refresh the cached limits and forget the remembered blocks when the surface is replaced or edited.
	 * @param Landscape $land Surface the jet flies over on this step.
	 * @return void Leaves the cache describing this surface at its current revision.
	 */
	private function survey(Landscape $land): void
	{
		if($land === $this->surveyed && $land->revision() === $this->revision) return;
		$this->surveyed = $land;
		$this->revision = $land->revision();
		$this->half = $land->recipe()['worldSize'] / 2;
		$this->roof = $land->bounds()['maxY'];
		$this->body = $this->eye = ['cells' => 0, 'wait' => 0];
	}

	/**
	 * Return a point's height once the ground has pushed it up to its margin, sampling only as finely as needed.
	 * The landscape bounds a whole block of cells without generating a node. A point at or above that bound plus
	 * the margin cannot be moved by the exact sample, so the answer always equals max($y, height + $margin).
	 * Higher points clear larger blocks and keep them for more steps. A point too low for the finest block
	 * samples the surface exactly.
	 * @param array $block Block remembered for this point, updated in place.
	 * @param Landscape $land Surface under the point.
	 * @param float $x World X of the point in meters.
	 * @param float $z World Z of the point in meters.
	 * @param float $y Height of the point before the ground acts on it.
	 * @param float $margin Clearance the point keeps above the ground, in meters.
	 * @return float The given height, or the exact surface plus the margin when that is higher.
	 */
	private function lift(array &$block, Landscape $land, float $x, float $z, float $y, float $margin): float
	{
		$cells = $block['cells'];
		if($block['wait'] > 0) $block['wait']--;
		elseif($cells < self::COARSEST)
		{
			// A block twice as wide lasts twice as many steps. Move up to it whenever the point clears it.
			$wider = self::block($land, $x, $z, $cells ? $cells * 2 : self::FINEST, $margin, 0);
			if($y >= $wider['clear']) { $block = $wider; return $y; }
			$block['wait'] = self::PATIENCE;
		}
		if($cells)
		{
			if($x < $block['minX'] || $x >= $block['maxX'] || $z < $block['minZ'] || $z >= $block['maxZ'])
				$block = self::block($land, $x, $z, $cells, $margin, $block['wait']);
			if($y >= $block['clear']) return $y;
			// The ground has come too close for this size. Halve the block until one clears.
			while(($cells >>= 1) >= self::FINEST)
			{
				$block = self::block($land, $x, $z, $cells, $margin, self::PATIENCE);
				if($y >= $block['clear']) return $y;
			}
			$block['cells'] = 0;
			$block['wait'] = self::PATIENCE;
		}
		$floor = $land->height($x, $z) + $margin;
		if($floor <= $y) return $y;
		// No bound can clear a point resting on its floor, so a skimming jet never asks for one.
		$block['wait'] = self::PATIENCE;
		return $floor;
	}

	/**
	 * Ask the landscape to bound the block of one size around a point.
	 * @param Landscape $land Surface to bound.
	 * @param float $x World X of the point in meters.
	 * @param float $z World Z of the point in meters.
	 * @param int $cells Lattice cells along each edge of the block.
	 * @param float $margin Clearance the point keeps above the ground, in meters.
	 * @param int $wait Steps to fly on before asking about a larger block.
	 * @return array Block rectangle and size, the lowest height that clears it, and the wait.
	 */
	private static function block(Landscape $land, float $x, float $z, int $cells, float $margin, int $wait): array
	{
		$block = $land->localBounds($x, $z, $cells);
		$block['clear'] = $block['maxY'] + $margin;
		$block['cells'] = $cells;
		$block['wait'] = $wait;
		return $block;
	}

	/** Delivered thrust relative to full dry power, shared by the cockpit and the exhaust material. */
	public static function thrustLevel(float $power): float
	{
		$power = max(0.0, min(1.0, $power));
		if($power <= self::CRUISE_POWER) return .30 + .45 * $power / self::CRUISE_POWER;

		return .75 + .65 * ($power - self::CRUISE_POWER) / (1 - self::CRUISE_POWER);
	}

	public static function thrustPercent(float $power): int
	{
		return (int)round(100 * self::thrustLevel($power));
	}

	/** Pilot eye in the forward canopy, relative to the aircraft's balance point. */
	public static function cockpit(array $p): Camera
	{
		$eye = new Camera;
		$eye->yaw = M_PI - $p['yaw'];
		$eye->pitch = $p['pitch'];
		$eye->roll = $p['roll'];
		[$nose,,$up] = $eye->basis();
		foreach(['x','y','z'] as $i=>$axis) $eye->position[$i] = $p[$axis]+4.6*$nose[$i]+.85*$up[$i];
		return $eye;
	}

	private function placeCamera(Camera $camera, array $p): void
	{
		if($this->expert)
		{
			$camera->position = [$p['eyeX'], $p['eyeY'], $p['eyeZ']];
			$camera->yaw = $p['lookYaw'];
			$camera->pitch = $p['lookPitch'];
			return;
		}
		$camera->position = [$p['x'] - $p['boom'] * sin($p['yaw']), $p['eyeY'], $p['z'] - $p['boom'] * cos($p['yaw'])];
		$camera->yaw = M_PI - $p['yaw'];
		$camera->pitch = atan2($p['y'] + 1.5 + 6 * sin($p['pitch']) - $p['eyeY'], $p['boom'] + 6);
	}

	/** Pose of a carried game projectile under the wing skin of the baked mesh. Slot zero is the pilot's left. */
	public static function projectileMount(array $p, int $slot): array
	{
		[$left,$up,$nose] = self::axes($p['yaw'],$p['pitch'],$p['roll']);
		$span = $slot === 0 ? 2.65 : -2.65;
		foreach(['x','y','z'] as $i => $axis)
			$p[$axis] += $span*$left[$i] - .62*$up[$i] - .45*$nose[$i];
		return $p;
	}

	public function motion(): array { return [(int)$this->expert,...$this->rates,...$this->flightDirection,...$this->chase]; }

	public function resume(array $p,array $motion,Camera $camera,Landscape $land,float $water): void
	{
		$this->survey($land);
		$this->resumeFlight($p,$motion);
		$this->follow(0.,$camera,$land,$water);
	}

	/** Restore the airframe without moving a camera. */
	public function resumeFlight(array $p, array $motion = []): void
	{
		$this->previous=[];
		$this->pose=array_intersect_key($p,array_flip(['x','y','z','yaw','pitch','roll','speed','power','vx','vy','vz','aileron','stabilator','rudder']));
		$this->expert=(bool)($motion[0] ?? $this->expert);
		$this->rates=$motion ? array_slice($motion,1,3) : [0.,0.,0.];
		$speed=max(.001,sqrt($p['vx']**2+$p['vy']**2+$p['vz']**2));
		$this->flightDirection=$motion ? array_slice($motion,4,3) : [$p['vx']/$speed,$p['vy']/$speed,$p['vz']/$speed];
		$this->chase=$motion ? array_slice($motion,7,3) : $this->flightDirection;
	}

	public function state(): array { return $this->pose; }

	/** Interpolate jet and camera together without sampling terrain between simulation ticks. */
	public function view(Camera $camera, float $alpha): array
	{
		$p = $this->pose;
		if(!$this->previous || $alpha >= 1) return [$camera,$p];
		foreach($this->previous as $key => $value) $p[$key] = $value + $alpha * ($p[$key] - $value);
		if($this->expert)
		{
			// Near vertical, heading and roll trade large amounts between steps while the jet hardly turns. Blend
			// its axes instead, which follow the true rotation, and keep the blended angles only as a reference.
			$q = $this->previous;
			[$left0, , $nose0] = self::axes($q['yaw'], $q['pitch'], $q['roll']);
			[$left1, , $nose1] = self::axes($this->pose['yaw'], $this->pose['pitch'], $this->pose['roll']);
			$nose = $left = [];
			foreach([0, 1, 2] as $i)
			{
				$nose[$i] = $nose0[$i] + $alpha * ($nose1[$i] - $nose0[$i]);
				$left[$i] = $left0[$i] + $alpha * ($left1[$i] - $left0[$i]);
			}
			$length = sqrt($nose[0]*$nose[0] + $nose[1]*$nose[1] + $nose[2]*$nose[2]);
			foreach($nose as $i => $value) $nose[$i] = $value / $length;
			$along = $left[0]*$nose[0] + $left[1]*$nose[1] + $left[2]*$nose[2];
			foreach($left as $i => $value) $left[$i] = $value - $along * $nose[$i];
			$length = sqrt($left[0]*$left[0] + $left[1]*$left[1] + $left[2]*$left[2]);
			foreach($left as $i => $value) $left[$i] = $value / $length;
			[$p['yaw'],$p['pitch'],$p['roll']] = self::angles($left, $nose, $p['yaw'], $p['pitch'], $p['roll']);
		}
		$eye = clone $camera;
		$this->placeCamera($eye,$p);
		return [$eye,$p];
	}
}
