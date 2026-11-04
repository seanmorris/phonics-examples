<?php
declare(strict_types=1);
namespace PhpBilliards\Physics;

/**
 * Deterministic slate-rim, facing, and pocket-liner constraints.
 *
 * Constraints apply after an accepted free-flight interval or contact microstep, preserving the order of
 * rattle, rejection, and sink events. The browser arithmetic backend mirrors these reference operations.
 */
final class PocketModel
{
	public const DRAFT = .24007875908011603; // tan(13.5 degrees)
	public const RESTITUTION = .52;
	public const SINK_DEPTH = .115;

	private static function length(float $x, float $y): float { return sqrt($x * $x + $y * $y); }

	/** Static geometry supplied once to the browser backend, with no duplicated table dimensions. */
	public static function geometry(): array
	{
		$pockets = [];
		foreach(Table::pockets() as $index => [$x,$y])
		$pockets[] = [$x,$y,...Table::pocketOutward($index),...Table::pocketMouthCenter($index)];
		return ['pockets' => $pockets,'facings' => Table::facings(),'radius' => Ball::RADIUS,
			'swept' => Simulation::sweptGeometry(),'jaws' => Table::jaws(),'cushions' => Table::cushionLimits(),
			'halfX' => Table::WIDTH / 2 - Ball::RADIUS,'halfY' => Table::HEIGHT / 2 - Ball::RADIUS,
			'throat' => Table::THROAT_RADIUS,'liner' => Table::LINER_RADIUS,'draft' => self::DRAFT,
			'restitution' => self::RESTITUTION,'sinkDepth' => self::SINK_DEPTH,'maxSpeed' => Simulation::MAX_SPEED];
	}

	/**
	 * Gravity, the rim of the slate cut, and the conical liner decide whether a ball rattles out or sinks.
	 *
	 * @param Ball $ball Active ball assigned to a pocket.
	 * @param float $seconds Accepted interval for gravity integration.
	 * @param bool $gravity Whether to integrate gravity, or only constrain an already integrated contact step.
	 * @return list<array> Ordered pocket events.
	 */
	public static function advance(Ball $ball, float $seconds, bool $gravity = true): array
	{
		$events = [];
		$index = $ball->pocket;
		[$pocketX,$pocketY] = Table::pockets()[$index];
		if($gravity)
		{
			$ball->drop += $ball->vDrop * $seconds + .5 * ContactModel::GRAVITY * $seconds ** 2;
			$ball->vDrop += ContactModel::GRAVITY * $seconds;
		}
		$relativeX = $ball->x - $pocketX;
		$relativeY = $ball->y - $pocketY;
		$radial = self::length($relativeX, $relativeY);
		[$radialX,$radialY] = $radial < 1e-9 ? [1.0,0.0] : [$relativeX / $radial,$relativeY / $radial];
		$normal = null;
		$onRim = $ball->drop < Ball::RADIUS;
		[$outwardX,$outwardY] = Table::pocketOutward($index);

		if($onRim && $relativeX * $outwardX + $relativeY * $outwardY > 0 && $radial > Table::LINER_RADIUS)
		{
			// Behind the pocket centre the cut is closed by the rail and the back of the liner: a wall that returns
			// the ball rather than a rim it could tip over or a cloth it could roll back onto. The leather hangs at the
			// lip, inside the cut, so a ball pressed against it is already past the rim edge and tips in rather than
			// balancing on top of it.
			$radial = Table::LINER_RADIUS;
			$normal = [$radialX,$radialY,0.0];
			$onRim = false;
		}
		elseif($onRim)
		{
			// Rolling over the rim of the cut: the centre keeps one radius from the rim edge, so pushing back along
			// the line to that edge lets the ball tip inward and down rather than fall straight through the cloth.
			$inward = Table::THROAT_RADIUS - $radial;
			$above = Ball::RADIUS - $ball->drop;
			$distance = self::length(max(0, $inward), $above);

			if($inward <= 0 && $ball->drop > 0)
			{
				$ball->drop = 0;
				$normal = [0.0,0.0,1.0];
			}
			elseif($inward > 0 && $distance < Ball::RADIUS)
			{
				$radial = Table::THROAT_RADIUS - $inward * Ball::RADIUS / $distance;
				$ball->drop = Ball::RADIUS - $above * Ball::RADIUS / $distance;
				$normal = [$radialX * $inward / $distance,$radialY * $inward / $distance,$above / $distance];
			}
		}
		else
		{
			// Below the rim the liner cone narrows at the draft angle from where the rim left off.
			$allowed = max(.001, Table::THROAT_RADIUS - Ball::RADIUS - self::DRAFT * ($ball->drop - Ball::RADIUS));

			if($radial > $allowed)
			{
				$radial = $allowed;
				$normalLength = self::length(1, self::DRAFT);
				$normal = [$radialX / $normalLength,$radialY / $normalLength,self::DRAFT / $normalLength];
			}
		}

		if($normal !== null)
		{
			[$nx,$ny,$nz] = $normal;
			$ball->x = $pocketX + $radialX * $radial;
			$ball->y = $pocketY + $radialY * $radial;
			$normalSpeed = $ball->vx * $nx + $ball->vy * $ny + $ball->vDrop * $nz;

			if($normalSpeed > 0)
			{
				// The rim supports slow roll; the compliant lining still returns soft pocket knocks.
				$restitution = $onRim && $normalSpeed < .3 ? 0.0 : self::RESTITUTION;
				$impulse = (1 + $restitution) * $normalSpeed;
				$ball->vx -= $impulse * $nx;
				$ball->vy -= $impulse * $ny;
				$ball->vDrop -= $impulse * $nz;
				// A supported ball can touch on every microstep. Apply the strike losses only to a rebound,
				// not repeatedly to resting contact as gravity or another ball presses it into the lining.
				if($normalSpeed >= .3)
				{
					$ball->vx *= .985;
					$ball->vy *= .985;
					$ball->spinY *= .92;
				}
				// Depth, in ball radii below resting on the cloth, lets the sound tell a ball rolling around the edge
				// from one dropping out of sight down the liner.
				if($restitution > 0) $events[] = ['type' => 'pocket-rattle','ball' => $ball->number,'pocket' => $index
					,'strength' => (float)min(1, $normalSpeed / Simulation::MAX_SPEED),'depth' => $ball->drop / Ball::RADIUS];
			}
		}

		// Facings already support the horizontal sphere cross-section while its centre is above the cloth.
		// Waiting for drop=0 lets a nearly level ball cross the wall and then snap sideways into another ball.
		if($ball->drop < Ball::RADIUS) self::clearPocketFacingOverlap($ball, $index);
		[$mouthX,$mouthY] = Table::pocketMouthCenter($index);
		$crossedMouth = ($ball->x - $mouthX) * $outwardX + ($ball->y - $mouthY) * $outwardY <= 0;
		$leaving = $ball->vx * $outwardX + $ball->vy * $outwardY < -1e-6;
		// Once its centre is past the cut on the table side, the ball rests on the shelf cloth whatever its speed. Only
		// cloth friction turns its leftover spin into roll or stops it; kept here, a resting ball would never end the shot.
		$relativeX = $ball->x - $pocketX;
		$relativeY = $ball->y - $pocketY;
		$onShelf = $onRim && $ball->drop <= 1e-8 && self::length($relativeX, $relativeY) > Table::THROAT_RADIUS
			&& $relativeX * $outwardX + $relativeY * $outwardY <= 0;
		if(!$onShelf && (!$crossedMouth || !$leaving) && $ball->drop < 0)
		{
			$ball->drop = 0;
			$ball->vDrop = max(0, $ball->vDrop);
		}
		elseif($onShelf || ($crossedMouth && $leaving && $ball->drop < 0))
		{
			// The facing correction can push the centre back over the cut; it stays in the pocket until it clears both.
			if(!self::clearPocketFacingOverlap($ball, $index)
				|| self::length($ball->x - $pocketX, $ball->y - $pocketY) <= Table::THROAT_RADIUS)
			{
				$ball->drop = 0;
				$ball->vDrop = max(0, $ball->vDrop);
				return $events;
			}
			$ball->drop = $ball->previousDrop = 0;
			$ball->vDrop = 0;
			$ball->pocket = null;
			$events[] = ['type' => 'pocket-reject','ball' => $ball->number,'pocket' => $index];

			return $events;
		}

		if($ball->drop >= self::SINK_DEPTH)
		{
			$ball->drop = $ball->previousDrop = self::SINK_DEPTH;
			$ball->active = false;
			$ball->vx = $ball->vy = $ball->vDrop = $ball->spinX = $ball->spinY = $ball->spinZ = 0;
			$events[] = ['type' => 'pocket','ball' => $ball->number,'pocket' => $index];

		}
		return $events;
	}

	/**
	 * Keep a rattling ball on the open side of both tangent pocket facings before returning it to cloth physics.
	 *
	 * Pocket integration owns the ball while it is above the cut, so the ordinary facing sweep cannot protect it.
	 * Projecting a penetration to the ball-centre offset and reflecting the inward velocity supplies that missing
	 * contact. The ball may leave pocket physics only once it clears both solid facings by one radius.
	 */
	private static function clearPocketFacingOverlap(Ball $ball, int $index): bool
	{
		[$pocketX,$pocketY] = Table::pockets()[$index];
		$faces = array_slice(Table::facings(), $index * 2, 2);

		for($iteration = 0; $iteration < 3; $iteration++)
		{
			$corrected = false;
			foreach($faces as [$startX,$startY,$endX,$endY])
			{
				$segmentX = $endX - $startX;
				$segmentY = $endY - $startY;
				$length = self::length($segmentX, $segmentY);
				$nx = -$segmentY / $length;
				$ny = $segmentX / $length;
				if(($pocketX - $startX) * $nx + ($pocketY - $startY) * $ny < 0)
				[$nx,$ny] = [-$nx,-$ny];
				$distance = ($ball->x - $startX) * $nx + ($ball->y - $startY) * $ny;
				if($distance >= Ball::RADIUS) continue;

				$correction = Ball::RADIUS - $distance + 1e-7;
				$ball->x += $nx * $correction;
				$ball->y += $ny * $correction;
				$normalSpeed = $ball->vx * $nx + $ball->vy * $ny;
				if($normalSpeed < 0)
				{
					$impulse = (1 + self::RESTITUTION) * $normalSpeed;
					$ball->vx -= $impulse * $nx;
					$ball->vy -= $impulse * $ny;
				}
				$corrected = true;
			}
			if(!$corrected) return true;
		}

		foreach($faces as [$startX,$startY,$endX,$endY])
		{
			$segmentX = $endX - $startX;
			$segmentY = $endY - $startY;
			$length = self::length($segmentX, $segmentY);
			$nx = -$segmentY / $length;
			$ny = $segmentX / $length;
			if(($pocketX - $startX) * $nx + ($pocketY - $startY) * $ny < 0) [$nx,$ny] = [-$nx,-$ny];
			if(($ball->x - $startX) * $nx + ($ball->y - $startY) * $ny < Ball::RADIUS - 1e-8) return false;
		}
		return true;
	}

}
