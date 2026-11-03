<?php
declare(strict_types=1);
namespace DoomPhonics\Definition;

use Phonics\Ecs\Registry;

/** Maps DECORATE action names onto engine behavior primitives. */
final class ActionDispatcher
{
	/** @var array<string,callable(int,StateFrame,Registry):void> */
	private array $actions = [];
	/** @var array<string,int> Unhandled action names and invocation counts. */
	public array $unhandled = [];

	/** @param callable(int,StateFrame,Registry):void $action */
	public function register(string $name, callable $action): void { $this->actions[strtolower($name)] = $action; }

	public function handles(string $name): bool { return isset($this->actions[strtolower($name)]); }

	/** @return list<string> Case-folded action names currently bound to behavior. */
	public function registered(): array { return array_keys($this->actions); }

	public function dispatch(int $entity, StateFrame $frame, Registry $registry): void
	{
		if($frame->action === null) return;

		$key = strtolower($frame->action);

		if(isset($this->actions[$key])) ($this->actions[$key])($entity, $frame, $registry);
		else $this->unhandled[$frame->action] = ($this->unhandled[$frame->action] ?? 0) + 1;
	}
}
