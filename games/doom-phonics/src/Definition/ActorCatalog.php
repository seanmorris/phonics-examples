<?php
declare(strict_types=1);
namespace DoomPhonics\Definition;

/** Layered DECORATE actor catalog with inheritance and editor-number lookup. */
final class ActorCatalog
{
	/** @var array<string,ActorDefinition> */
	private array $actors = [];
	/** @var array<string,ActorDefinition> */
	private array $resolved = [];
	/** @var array<int,string> */
	private array $numbers = [];

	public function add(ActorDefinition $actor): void
	{
		$key = strtolower($actor->name);
		$this->actors[$key] = $actor;
		$this->resolved = [];

		if($actor->editorNumber !== null) $this->numbers[$actor->editorNumber] = $key;
	}

	/** @return list<ActorDefinition> Raw definitions in registration order. */
	public function all(): array { return array_values($this->actors); }

	/** @return list<ActorDefinition> Effective definitions after inheritance and later-document replacement. */
	public function resolved(): array
	{
		$result = [];

		foreach(array_keys($this->actors) as $key) $result[] = $this->resolve($key, []);

		return $result;
	}

	public function named(string $name): ?ActorDefinition
	{
		$key = strtolower($name);

		return isset($this->actors[$key]) ? $this->resolve($key, []) : null;
	}

	public function numbered(int $editorNumber): ?ActorDefinition
	{
		return isset($this->numbers[$editorNumber]) ? $this->named($this->numbers[$editorNumber]) : null;
	}

	/** Test a definition's declared ancestry without coupling systems to concrete actor names. */
	public function isA(string|ActorDefinition $actor, string $ancestor): bool
	{
		$name = $actor instanceof ActorDefinition ? $actor->name : $actor;
		$target = strtolower($ancestor);
		$seen = [];

		while(isset($this->actors[strtolower($name)]))
		{
			$key = strtolower($name);

			if($key === $target) return true;

			if(isset($seen[$key])) return false;

			$seen[$key] = true;
			$name = $this->actors[$key]->parent ?? '';
		}

		return strtolower($name) === $target;
	}

	/** @param array<string,bool> $chain Recursion guard. */
	private function resolve(string $key, array $chain): ActorDefinition
	{
		if(isset($this->resolved[$key])) return $this->resolved[$key];

		if(isset($chain[$key]))
		throw new \UnexpectedValueException('DECORATE inheritance cycle at ' . $this->actors[$key]->name);

		$raw = $this->actors[$key];
		$chain[$key] = true;
		$actor = new ActorDefinition($raw->name, $raw->parent, $raw->editorNumber, $raw->native, $raw->source);

		if($raw->parent !== null && isset($this->actors[strtolower($raw->parent)]))
		{
			$parent = $this->resolve(strtolower($raw->parent), $chain);
			$actor->properties = $parent->properties;
			$actor->flags = $parent->flags;
			$actor->states = $parent->states;
		}

		foreach($raw->properties as $name => $occurrences)
		{
			// Player declarations extend inherited lists. A class's first DropItem declaration
			// replaces its parent's list, which lets `DropItem None` suppress corpse loot.
			if(in_array($name, ['player.startitem','player.weaponslot','player.colorset'], true))
			$actor->properties[$name] = [...($actor->properties[$name] ?? []), ...$occurrences];
			else $actor->properties[$name] = $occurrences;
		}

		$actor->flags = array_replace($actor->flags, $raw->flags);
		$actor->states = array_replace($actor->states, $raw->states);

		return $this->resolved[$key] = $actor;
	}
}
