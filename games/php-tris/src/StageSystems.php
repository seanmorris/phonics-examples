<?php
declare(strict_types=1);
namespace PhpTris;

use Phonics\World\ScopeContext;
use Phonics\World\SystemSet;
use PhpTris\Component\
{
	Board, Active, Queue, Input, Run
};

/** A selected board owns its systems and components for the lifetime of one run. */
final class StageSystems implements SystemSet
{

	/**
	 * Validate the authored board before attaching its ordered gameplay systems.
	 * @param ScopeContext $scope Prepared map with its components already constructed.
	 * @param array $options Reserved system-set options.
	 * @return void Registers systems only for a complete board.
	 */
	public function activate(ScopeContext $scope, array $options = []): void
	{
		$boards = [];

		foreach($scope->entities() as $id)
		if($scope->registry->has($id, Board::class)) $boards[] = $id;

		$complete = count($boards) === 1;

		foreach([Active::class, Queue::class, Input::class, Run::class] as $class)
		$complete = $complete && $scope->registry->has($boards[0], $class);

		if(!$complete)
		throw new \UnexpectedValueException('A PHPTris stage requires exactly one complete board entity');

		Module::systems($scope);
	}
}
