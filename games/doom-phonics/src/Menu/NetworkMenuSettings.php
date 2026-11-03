<?php
declare(strict_types=1);
namespace DoomPhonics\Menu;

/** Stages and persists browser-owned lobby endpoints for the native Doom menu. */
final class NetworkMenuSettings
{
	private \Closure $reader;
	private \Closure $writer;
	private string $homeserver;
	private string $lobbyChannel;
	private string $savedHomeserver;
	private string $savedLobbyChannel;
	private ?string $message = null;
	private bool $error = false;

	/**
	 * @param callable():string $reader Returns the active provider configuration as JSON.
	 * @param callable(string):string $writer Accepts JSON and returns a configuration or error envelope.
	 */
	public function __construct(
		callable $reader
		, callable $writer
		, string $homeserver = 'https://matrix.org'
		, string $lobbyChannel = '#doom-phonics:matrix.org'
	){
		$this->reader = \Closure::fromCallable($reader);
		$this->writer = \Closure::fromCallable($writer);
		$this->homeserver = $this->savedHomeserver = $homeserver;
		$this->lobbyChannel = $this->savedLobbyChannel = $lobbyChannel;
		$this->reload();
	}

	public function homeserver(): string { return $this->homeserver; }

	public function lobbyChannel(): string { return $this->lobbyChannel; }

	public function message(): ?string { return $this->message; }

	public function failed(): bool { return $this->error; }

	public function dirty(): bool
	{
		return $this->homeserver !== $this->savedHomeserver
			|| $this->lobbyChannel !== $this->savedLobbyChannel;
	}

	public function stageHomeserver(string $value): void
	{
		$this->homeserver = $value;
		$this->message = null;
		$this->error = false;
	}

	public function stageLobbyChannel(string $value): void
	{
		$this->lobbyChannel = $value;
		$this->message = null;
		$this->error = false;
	}

	public function revertHomeserver(): string
	{
		$this->homeserver = $this->savedHomeserver;
		$this->message = null;
		$this->error = false;

		return $this->homeserver;
	}

	public function revertLobbyChannel(): string
	{
		$this->lobbyChannel = $this->savedLobbyChannel;
		$this->message = null;
		$this->error = false;

		return $this->lobbyChannel;
	}

	/** Validate both staged values and commit them through the active lobby provider. */
	public function apply(): bool
	{
		$homeserver = trim($this->homeserver);
		$lobbyChannel = trim($this->lobbyChannel);
		$url = parse_url($homeserver);

		if(!is_array($url) || !in_array(strtolower((string)($url['scheme'] ?? '')), ['http','https'], true)
			|| !isset($url['host']) || isset($url['user']) || isset($url['pass'])
		) return $this->reject('Homeserver must be an HTTP(S) URL without credentials');

		if($lobbyChannel === '') return $this->reject('Lobby channel is required');

		try
		{
			$response = ($this->writer)(json_encode([
				'homeserver' => $homeserver
				, 'room' => $lobbyChannel
			], JSON_THROW_ON_ERROR
			));

			$record = $this->decode($response);

			if(isset($record['error'])) return $this->reject((string)$record['error']);

			$configuration = $record['configuration'] ?? $record;

			if(!is_array($configuration)) return $this->reject('Lobby provider returned an invalid configuration');

			$this->accept($configuration);
			$this->message = ($configuration['pending'] ?? false)
				? 'Saved for the next lobby connection'
				: 'Networking settings saved';

			$this->error = false;

			return true;
		}
		catch(\Throwable $error)
		{
			return $this->reject($error->getMessage());
		}
	}

	private function reload(): void
	{
		try
		{
			$this->accept($this->decode(($this->reader)()));
		}
		catch(\Throwable)
		{
			// World defaults remain usable when the browser provider is unavailable in native consumers.
		}
	}

	/** @return array<string,mixed> */
	private function decode(string $value): array
	{
		$decoded = json_decode($value, true, 16, JSON_THROW_ON_ERROR);

		if(!is_array($decoded)) throw new \UnexpectedValueException('Lobby provider returned invalid JSON');

		return $decoded;
	}

	/** @param array<string,mixed> $configuration */
	private function accept(array $configuration): void
	{
		$homeserver = trim((string)($configuration['homeserver'] ?? $this->homeserver));
		$lobbyChannel = trim((string)($configuration['room'] ?? $this->lobbyChannel));

		if($homeserver !== '') $this->homeserver = $this->savedHomeserver = $homeserver;

		if($lobbyChannel !== '') $this->lobbyChannel = $this->savedLobbyChannel = $lobbyChannel;
	}

	private function reject(string $message): bool
	{
		$this->message = $message !== '' ? $message : 'Unable to save networking settings';
		$this->error = true;

		return false;
	}
}
