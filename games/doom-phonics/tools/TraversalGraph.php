<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

/**
 * Search directed traversal regions together with finite environmental state.
 *
 * Switches are local actions, so an unreachable control cannot magically enable
 * a route. Searches retain witness transitions and reverse reachability to a
 * refuge, allowing callers to distinguish a reachable goal from a trapping route.
 */
final class TraversalGraph
{

	/** Named convex regions and their display/geometry metadata. */
	public array $regions = [];
	/** Directed edges with optional requirements, effects and physical witnesses. */
	public array $edges = [];
	/** Finite values permitted for each environmental variable. */
	private array $domains;

	/** Set the finite state domains before adding regions and transitions. */
	public function __construct(array $domains = [])
	{
		foreach($domains as $name => $values)
		if(!$values || count(array_unique($values, SORT_REGULAR)) !== count($values))
		throw new \InvalidArgumentException("Invalid state domain: $name");
		$this->domains = $domains;
	}

	/** Register a uniquely named traversal region. */
	public function region(string $id, array $metadata = []): void
	{
		if(isset($this->regions[$id])) throw new \InvalidArgumentException("Duplicate region: $id");
		$this->regions[$id] = $metadata;
	}

	/** Add a directed transition; requirements are allowed-value lists and effects are assignments. */
	public function connect(string $from, string $to, string $mode, array $requires = [], array $effects = [], array $witness = []): void
	{
		if(!isset($this->regions[$from], $this->regions[$to]))
		throw new \InvalidArgumentException("Unknown traversal endpoint: $from -> $to");
		foreach($requires as $name => $values)
		foreach($values as $value) $this->validateValue($name, $value);
		foreach($effects as $name => $value) $this->validateValue($name, $value);
		$this->edges[] = compact('from','to','mode','requires','effects','witness');
	}

	/** Exhaust reachable states, preserving shortest transition witnesses and return-to-refuge diagnostics. */
	public function search(string $start, array $state, bool $actions = true, ?string $refuge = null, int $limit = 10000): array
	{
		if(!isset($this->regions[$start]) || ($refuge !== null && !isset($this->regions[$refuge])))
		throw new \InvalidArgumentException('Unknown search start/refuge');
		if(count($state) !== count($this->domains)) throw new \InvalidArgumentException('Incomplete initial world state');
		foreach($state as $name => $value) $this->validateValue($name, $value);
		ksort($state);
		$key = static fn(string $region, array $values): string => json_encode([$region,$values], JSON_THROW_ON_ERROR);
		$first = $key($start,$state);
		$states = [$first => ['region' => $start,'state' => $state,'parent' => null,'edge' => null]];
		$queue = [$first];
		$adjacency = $reverse = $reached = [];
		foreach($this->edges as $id => $edge) $adjacency[$edge['from']][] = $id;
		for($head = 0; $head < count($queue); $head++)
		{
			$currentKey = $queue[$head];
			$current = $states[$currentKey];
			$reached[$current['region']] ??= $currentKey;
			foreach($adjacency[$current['region']] ?? [] as $edgeId)
			{
				$edge = $this->edges[$edgeId];
				if(!$actions && $edge['effects']) continue;
				foreach($edge['requires'] as $name => $values)
				if(!in_array($current['state'][$name], $values, true)) continue 2;
				$nextState = array_replace($current['state'], $edge['effects']);
				$nextKey = $key($edge['to'],$nextState);
				$reverse[$nextKey][] = $currentKey;
				if(isset($states[$nextKey])) continue;
				if(count($states) >= $limit) throw new \RuntimeException('Navigation state budget exceeded; no connectivity verdict');
				$states[$nextKey] = ['region' => $edge['to'],'state' => $nextState,'parent' => $currentKey,'edge' => $edgeId];
				$queue[] = $nextKey;
			}
		}
		$routes = [];
		foreach($reached as $region => $last)
		{
			$route = [];
			for($cursor = $last; $states[$cursor]['parent'] !== null; $cursor = $states[$cursor]['parent'])
			$route[] = $this->edges[$states[$cursor]['edge']] + ['state' => $states[$cursor]['state']];
			$routes[$region] = array_reverse($route);
		}
		$canReturn = $returnQueue = [];
		foreach($states as $id => $item)
		if($item['region'] === ($refuge ?? $start)) { $canReturn[$id] = true; $returnQueue[] = $id; }
		for($head = 0; $head < count($returnQueue); $head++)
		foreach($reverse[$returnQueue[$head]] ?? [] as $previous)
		if(!isset($canReturn[$previous])) { $canReturn[$previous] = true; $returnQueue[] = $previous; }
		return ['reachable' => array_keys($reached),'routes' => $routes,'states' => count($states),
			'traps' => array_values(array_filter($states, static fn($id) => !isset($canReturn[$id]), ARRAY_FILTER_USE_KEY))];
	}

	/** Reject misspelled state variables and values outside the declared finite domain. */
	private function validateValue(string $name, mixed $value): void
	{
		if(!isset($this->domains[$name]) || !in_array($value, $this->domains[$name], true))
		throw new \InvalidArgumentException("Unknown state value: $name");
	}
}
