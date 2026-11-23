<?php
declare(strict_types=1);
namespace TerrainSandbox;

/** Four unilateral springs and dampers support the chassis in heave, pitch and roll. */
final class CarSuspension
{
	// Static sag and extra bump travel in meters. The mesh is authored at its loaded ride height.
	public const SAG = .13;
	private const BUMP = .14;
	// Effective suspension roll axis above the tire soles (m), and mass / roll inertia (m⁻²).
	private const ROLL_CENTER_HEIGHT = .22, INVERSE_ROLL_INERTIA = 2.7;
	/** The boost can pull a drooping wheel toward ground within this many meters of full extension. */
	public const BOOST_REACH = .65;
	/** Smallest gap from a fully extended wheel to the road. */
	public float $clearance = 0.;
	// Extra rebound damping fades during the first stroke after all four tires leave the ground.
	private const LANDING_SECONDS = .35;
	private float $landingTimeLeft = 0;
	private const ALONG = [Car::WHEELBASE/2,Car::WHEELBASE/2,-Car::WHEELBASE/2,-Car::WHEELBASE/2];
	private const ACROSS = [-Car::FRONT_TRACK/2,Car::FRONT_TRACK/2,-Car::REAR_TRACK/2,Car::REAR_TRACK/2];
	public float $y = 0, $pitch = 0, $roll = 0, $verticalSpeed = 0, $pitchRate = 0, $rollRate = 0;
	public int $contacts = 4, $frontContacts = 2;
	public array $travel = [0.0,0.0,0.0,0.0];
	public bool $resting = true;
	private array $ground = [];

	public function reset(array $support, float $verticalSpeed = 0): void
	{
		[$this->y,$this->pitch,$this->roll] = $support;
		$this->verticalSpeed = $verticalSpeed;
		$this->landingTimeLeft = 0;
		$this->pitchRate = $this->rollRate = 0;
		$this->ground = $support[4];
		$this->measure($this->ground);
		$this->resting = $verticalSpeed === 0.0 && max(array_map('abs',$this->travel)) < .0001;
	}

	public function changeGround(array $heights): void
	{
		$this->ground = $heights;
		$this->resting = false;
	}

	public function step(float $dt, array $heights, bool $moving, float $downforce = 0., float $cornerLoad = 0.): void
	{
		if(!$moving && $downforce === 0. && $cornerLoad === 0. && $this->resting && $this->ground === $heights) return;
		// Interpolate these same four terrain samples across both substeps, including their road velocities.
		$steps = max(1, (int)ceil($dt*120));
		$h = $dt/$steps;
		if($this->contacts === 0) $this->landingTimeLeft = self::LANDING_SECONDS;
		$roadSpeed = [];
		foreach($heights as $i => $height) $roadSpeed[$i] = ($height-$this->ground[$i])/$dt;
		// Lateral tire force acts below the mass center. Each supporting corner supplies its share of the moment.
		$rollMoment = .25*$cornerLoad*(Car::CENTER_OF_MASS_HEIGHT - self::ROLL_CENTER_HEIGHT);

		for($step = 0; $step < $steps; $step++)
		{
			$reboundDamping = 10.0 + 3.0*$this->landingTimeLeft/self::LANDING_SECONDS;
			$this->landingTimeLeft = max(0.0, $this->landingTimeLeft - $h);
			[$rise,$bank,$normalUp] = $this->plane();
			$lift = -Session::DRIVING_GRAVITY; $pitchAccel = $rollAccel = 0.0;
			foreach(self::ALONG as $i => $along)
			{
				$side = self::ACROSS[$i];
				$road = $this->ground[$i] + $roadSpeed[$i]*$step*$h;
				$compression = ($road+.02 - ($this->y+$along*$rise+$side*$bank))*$normalUp;
				if($downforce > 0.)
				{
					// Load each corner independently. A briefly unloaded wheel still feels the pull,
					// which falls continuously to zero as it moves beyond suspension reach.
					$gap = max(0., -$compression-self::SAG);
					$pull = .25*$downforce*max(0., 1.-$gap/self::BOOST_REACH);
					$lift -= $pull;
					$pitchAccel -= $pull*$along*.64;
					$rollAccel += $pull*$side*self::INVERSE_ROLL_INERTIA;
				}
				if($compression <= -self::SAG) continue;
				$bodySpeed = $this->verticalSpeed + ($along*(1+$rise*$rise)+$side*$bank*$rise)*$this->pitchRate
					- $side*(1/($normalUp*max(.18,cos($this->roll))))*$this->rollRate;
				$rate = ($roadSpeed[$i]-$bodySpeed)*$normalUp;
				$stroke = min($compression,self::BUMP);
				$spring = Session::DRIVING_GRAVITY*(1+$stroke/self::SAG);
				// Progressive bump stops and stronger rebound damping. A tire can only push upward.
				$bumpStop = 90*max(0.0, $stroke-.085);
				$force = .25*max(0.0, $spring+$bumpStop+($rate > 0 ? 5.0 : $reboundDamping)*$rate);
				$lift += $force;
				$pitchAccel += $force*$along*.64;
				$rollAccel += ($rollMoment - $force*$side)*self::INVERSE_ROLL_INERTIA;
			}
			$this->y += $h*($this->verticalSpeed+.5*$lift*$h);
			$this->pitch += $h*($this->pitchRate+.5*$pitchAccel*$h);
			$this->roll += $h*($this->rollRate+.5*$rollAccel*$h);
			$this->verticalSpeed += $h*$lift;
			$this->pitchRate += $h*$pitchAccel;
			$this->rollRate += $h*$rollAccel;
			$this->bottomOut($roadSpeed,($step+1)*$h);
		}
		$this->ground = $heights;
		$this->measure($heights);
		$this->resting = !$moving && $downforce === 0. && $this->contacts >= 2 && abs($this->verticalSpeed) < .0002
			&& abs($this->pitchRate)+abs($this->rollRate) < .0002 && abs($lift)+abs($pitchAccel)+abs($rollAccel) < .003;
		if($this->resting) $this->verticalSpeed = $this->pitchRate = $this->rollRate = 0.0;
	}

	/** At the end of the spring stroke the wheel transmits an inelastic contact, with no stored extra spring energy. */
	private function bottomOut(array $roadSpeed, float $elapsed): void
	{
		// Combine simultaneous strikes before applying an impulse. A centered bump must not kick one side first.
		for($pass = 0; $pass < 3; $pass++)
		{
			[$rise,$bank,$normalUp] = $this->plane();
			$weight = $depth = $pitchArm = $rollArm = $groundSpeed = 0.0;
			foreach(self::ALONG as $i => $along)
			{
				$side = self::ACROSS[$i];
				$road = $this->ground[$i]+$roadSpeed[$i]*$elapsed;
				$overrun = $road+.02 - ($this->y+$along*$rise+$side*$bank+self::BUMP/$normalUp);
				if($overrun < .0001) continue;
				$weight += $overrun;
				$depth += $overrun*$overrun;
				$pitchArm += $overrun*($along*(1+$rise*$rise)+$side*$bank*$rise);
				$rollArm -= $overrun*$side/($normalUp*max(.18,cos($this->roll)));
				$groundSpeed += $overrun*$roadSpeed[$i];
			}
			if($weight === 0.0) break;
			$pitchArm /= $weight; $rollArm /= $weight;
			$effectiveMass = 1/(1+.64*$pitchArm**2+self::INVERSE_ROLL_INERTIA*$rollArm**2);
			$correction = $depth/$weight*$effectiveMass;
			$closing = $groundSpeed/$weight-($this->verticalSpeed+$this->pitchRate*$pitchArm+$this->rollRate*$rollArm);
			$impulse = max(0.0,$closing)*$effectiveMass;
			$this->y += $correction; $this->verticalSpeed += $impulse;
			$this->pitch += .64*$pitchArm*$correction; $this->pitchRate += .64*$pitchArm*$impulse;
			$this->roll += self::INVERSE_ROLL_INERTIA*$rollArm*$correction;
			$this->rollRate += self::INVERSE_ROLL_INERTIA*$rollArm*$impulse;
		}
	}

	/** Vertical height of the chassis plane at the horizontal tire probes, and its upward normal component. */
	private function plane(): array
	{
		$cp = max(.18,cos($this->pitch));
		return [sin($this->pitch)/$cp,-sin($this->roll)/(max(.18,cos($this->roll))*$cp),max(.12,$cp*cos($this->roll))];
	}

	private function measure(array $heights): void
	{
		[$rise,$bank,$normalUp] = $this->plane();
		$this->contacts = $this->frontContacts = 0;
		$this->clearance = INF;
		foreach($heights as $i => $height)
		{
			$compression = ($height+.02 - ($this->y+self::ALONG[$i]*$rise+self::ACROSS[$i]*$bank))*$normalUp;
			$this->clearance = min($this->clearance, max(0., -$compression-self::SAG));
			if($compression > -self::SAG)
			{
				$this->contacts++;
				if($i < 2) $this->frontContacts++;
			}
			$this->travel[$i] = max(-self::SAG,min(self::BUMP,$compression));
		}
	}
}
