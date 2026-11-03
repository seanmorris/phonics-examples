<?php
declare(strict_types=1);
namespace PhpBuster\Input;

use Phonics\Input\Bindings;

/** Versioned Buster bindings with failure-tolerant browser persistence. */
final class InputSettings
{
	public readonly Bindings $bindings;
	public bool $sessionOnly = false;
	private ?\Closure $write;

	/**
	 * Restore a versioned profile while keeping safe defaults on failure.
	 *
	 * @param null|callable():mixed $read Browser persistence reader.
	 * @param null|callable(string):mixed $write Browser persistence writer.
	 * @return void The mutable binding profile is exposed through $bindings.
	 */
	public function __construct(?callable $read = null, ?callable $write = null)
	{
		$this->bindings = BusterActions::defaults();
		$this->write = $write === null ? null : \Closure::fromCallable($write);

		try
		{
			$json = $read ? $read() : '';
			$data = is_string($json) && strlen($json) <= 1048576 ? json_decode($json, true) : null;

			if(is_array($data) && ($data['version'] ?? null) === 1 && is_array($data['bindings'] ?? null))
			$this->bindings->restore($data['bindings'], [BusterActions::class, 'allowed']);
		} catch(\Throwable)
		{
			$this->sessionOnly = true;
		}
	}

	/**
	 * Persist the current binding profile when storage is available.
	 *
	 * @return void Failure switches the profile to session-only mode.
	 */
	public function save(): void
	{
		try
		{
			$this->sessionOnly = !$this->write || ($this->write)(json_encode([
				'version' => 1
				, 'bindings' => $this->bindings
			], JSON_THROW_ON_ERROR
			)) === false;
		} catch(\Throwable)
		{
			$this->sessionOnly = true;
		}
	}

	/**
	 * Restore one device to defaults and persist the result.
	 *
	 * @param string $device Physical device family to reset.
	 * @return void The existing binding object is updated in place.
	 */
	public function reset(string $device): void
	{
		$this->bindings->reset($device);
		$this->save();
	}
}
