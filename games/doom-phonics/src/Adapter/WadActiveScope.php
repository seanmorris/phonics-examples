<?php
declare(strict_types=1);
namespace DoomPhonics\Adapter;

use Phonics\World\ActiveScope;
use Phonics\World\ScopeDescriptor;

/** Independently releasable map activation returned through the neutral world API. */
final class WadActiveScope implements ActiveScope
{
	private bool $closed = false;

	public function __construct(private ScopeDescriptor $scope, private WadLevel $level) {}

	public function descriptor(): ScopeDescriptor { return $this->scope; }

	public function content(): WadLevel { return $this->level; }

	public function snapshot(): array { return ['map' => $this->scope->id]; }

	public function close(): void { $this->closed = true; }

	public function closed(): bool { return $this->closed; }
}
