<?php
declare(strict_types=1);
namespace DoomPhonics\Definition;

/** Declarative actor, weapon, pickup, or projectile definition loaded from DECORATE. */
final class ActorDefinition
{
	/** @var array<string,list<list<string|int|float>>> Case-folded property name to occurrences. */
	public array $properties = [];
	/** @var array<string,bool> Case-folded effective flag changes. */
	public array $flags = [];
	/** @var array<string,StateSequence> Case-folded state label to sequence. */
	public array $states = [];

	public function __construct(
		public string $name
		, public ?string $parent = null
		, public ?int $editorNumber = null
		, public bool $native = false
		, public ?string $source = null
	){
	}

	/** @param string $name Property name. @param list<string|int|float> $values One occurrence. */
	public function addProperty(string $name, array $values): void
	{
		$this->properties[strtolower($name)][] = $values;
	}

	/** @return list<list<string|int|float>> Every inherited occurrence of a property. */
	public function values(string $name): array { return $this->properties[strtolower($name)] ?? []; }

	/** @return string|int|float|bool|null Last scalar value, or the supplied default. */
	public function value(string $name, string|int|float|bool|null $default = null): string|int|float|bool|null
	{
		$values = $this->values($name);

		return $values ? ($values[array_key_last($values)][0] ?? true) : $default;
	}

	public function hasFlag(string $name): bool { return $this->flags[strtolower($name)] ?? false; }

	public function state(string $label): ?StateSequence { return $this->states[strtolower($label)] ?? null; }
}
