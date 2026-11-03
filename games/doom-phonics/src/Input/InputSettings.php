<?php
declare(strict_types=1);
namespace DoomPhonics\Input;

use DoomPhonics\Game\GameSettings;
use Phonics\Input\Bindings;
use Phonics\Input\Pad;
use Phonics\Input\Source;

/** Versioned gameplay bindings and look preferences, with optional browser persistence. */
final class InputSettings
{
	public readonly Bindings $bindings;
	public bool $sessionOnly = false;
	private ?\Closure $write;

	public function __construct(public readonly GameSettings $settings, ?callable $read = null, ?callable $write = null)
	{
		$this->bindings = DoomActions::defaults();
		$this->write = $write === null ? null : \Closure::fromCallable($write);

		try
		{
			$json = $read ? $read() : '';
			$data = is_string($json) && strlen($json) <= 1048576 ? json_decode($json, true) : null;

			if(!is_array($data) || !in_array($data['version'] ?? null, [1,2], true)) return;

			if($data['version'] === 1) $data = $this->migrateV1($data);

			if(is_array($data['bindings'] ?? null))
			$this->bindings->restore($data['bindings'], [DoomActions::class, 'allowed']);

			foreach(is_array($data['look'] ?? null) ? $data['look'] : [] as $name => $value)
			if(is_string($name)) $this->preference($name, $value, false);
		} catch(\Throwable)
		{
			$this->sessionOnly = true;
		}
	}

	/** Reject malformed saved values independently, without discarding valid neighbors. */
	public function preference(string $name, mixed $value, bool $save = true): bool
	{
		$range = match($name)
		{
			'mouseSensitivity' => [.5, 2], 'controllerHorizontal', 'controllerVertical' => [.25, 3],
			'controllerDeadZone' => [0, .3], default => null,
		};

		if($range)
		{
			if((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value < $range[0]
				|| $value > $range[1]
			)
			return false;

			$value = (float)$value;
		} elseif(!in_array($name, ['mouseInvertY', 'controllerInvertY'], true) || !is_bool($value)) return false;

		if($name === 'mouseSensitivity') $this->settings->setLookSensitivity($value);
		elseif($name === 'mouseInvertY') $this->settings->setInvertVerticalLook($value);
		else $this->settings->$name = $value;

		if($save) $this->save();

		return true;
	}

	public function save(): void
	{
		try
		{
			$this->sessionOnly = !$this->write || ($this->write)(json_encode([
				'version' => 2
				, 'bindings' => $this->bindings
				, 'look' => [
					'mouseSensitivity' => $this->settings->lookSensitivity()
					, 'mouseInvertY' => $this->settings->invertVerticalLook()
					, 'controllerHorizontal' => $this->settings->controllerHorizontal
					, 'controllerVertical' => $this->settings->controllerVertical
					, 'controllerDeadZone' => $this->settings->controllerDeadZone
					, 'controllerInvertY' => $this->settings->controllerInvertY
				]
			], JSON_THROW_ON_ERROR
			)) === false;
		} catch(\Throwable)
		{
			$this->sessionOnly = true;
		}
	}

	/** Preserve edited v1 profiles while moving untouched old defaults to the new fire layout. */
	private function migrateV1(array $data): array
	{
		$bindings = is_array($data['bindings'] ?? null) ? $data['bindings'] : [];
		$fire = $bindings[DoomActions::FIRE][1][Source::CONTROLLER] ?? null;

		if(is_array($fire) && count($fire) === 1
			&& ($fire[0]['kind'] ?? null) === Source::PAD_BUTTON
			&& ($fire[0]['code'] ?? null) === Pad::RB
		){
			$bindings[DoomActions::FIRE][1][Source::CONTROLLER] = [
				[
					'kind' => Source::PAD_AXIS
					, 'code' => Pad::RT
					, 'sign' => 1
				]
			];
		}

		$use = $bindings[DoomActions::USE][1][Source::KEYBOARD] ?? null;

		if(is_array($use))
		$bindings[DoomActions::USE][1][Source::KEYBOARD] = array_values(
			array_filter(
				$use
				, static fn(mixed $source): bool => !is_array($source) || ($source['kind']
					?? null
				) !== Source::MOUSE_BUTTON || ($source['code'] ?? null) !== Source::SECONDARY
			)
		);

		$data['bindings'] = $bindings;
		$data['version'] = 2;

		return $data;
	}

	public function reset(string $device): void
	{
		$this->bindings->reset($device);

		if($device === Source::CONTROLLER)
		{
			$this->settings->controllerHorizontal = $this->settings->controllerVertical = 1;
			$this->settings->controllerDeadZone = .12;
			$this->settings->controllerInvertY = false;
		} else
		{
			$this->settings->setLookSensitivity(1);
			$this->settings->setInvertVerticalLook(false);
		}

		$this->save();
	}
}
