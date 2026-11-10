<?php
declare(strict_types=1);
namespace PhpPoker;

/**
 * The games this table can deal, as data: forced bets, seat limit, and one entry per street.
 *
 * A street deals cards (face down, face up, or to the shared board), names which of the profile's two limit tiers
 * its betting round uses, and says who acts first. The same table is published as `variants.json` for the online
 * reducer in JavaScript; the rules tests fail if the two copies differ.
 */
final class Variant
{
	/** Game shown when a save or a start request names none. */
	public const DEFAULT = 'draw';
	/** @var array<string,array> Every game by id; mirrored in variants.json. */
	public const GAMES = [
		'draw' => [
			'label' => 'Five-Card Draw',
			'seats' => 8,
			'forced' => 'dealer-ante',
			'evaluate' => 'exact5',
			'streets' => [
				[
					'name' => 'opening',
					'deal' => [
						'down' => 5,
						'up' => 0,
						'board' => 0,
					],
					'limits' => 'early',
					'first' => 'left-of-dealer',
				],
				[
					'name' => 'final',
					'deal' => [
						'down' => 0,
						'up' => 0,
						'board' => 0,
					],
					'limits' => 'late',
					'first' => 'opener',
					'draw' => true,
				],
			],
		],
		'holdem' => [
			'label' => 'Texas Hold\'em',
			'seats' => 8,
			'forced' => 'blinds',
			'evaluate' => 'best5',
			'streets' => [
				[
					'name' => 'preflop',
					'deal' => [
						'down' => 2,
						'up' => 0,
						'board' => 0,
					],
					'limits' => 'early',
					'first' => 'left-of-blinds',
				],
				[
					'name' => 'flop',
					'deal' => [
						'down' => 0,
						'up' => 0,
						'board' => 3,
					],
					'limits' => 'early',
					'first' => 'left-of-dealer',
				],
				[
					'name' => 'turn',
					'deal' => [
						'down' => 0,
						'up' => 0,
						'board' => 1,
					],
					'limits' => 'late',
					'first' => 'left-of-dealer',
				],
				[
					'name' => 'river',
					'deal' => [
						'down' => 0,
						'up' => 0,
						'board' => 1,
					],
					'limits' => 'late',
					'first' => 'left-of-dealer',
				],
			],
		],
		'stud5' => [
			'label' => 'Five-Card Stud',
			'seats' => 8,
			'forced' => 'ante-bring-in',
			'evaluate' => 'exact5',
			'streets' => [
				[
					'name' => 'second',
					'deal' => [
						'down' => 1,
						'up' => 1,
						'board' => 0,
					],
					'limits' => 'early',
					'first' => 'low-up',
				],
				[
					'name' => 'third',
					'deal' => [
						'down' => 0,
						'up' => 1,
						'board' => 0,
					],
					'limits' => 'early',
					'first' => 'high-showing',
				],
				[
					'name' => 'fourth',
					'deal' => [
						'down' => 0,
						'up' => 1,
						'board' => 0,
					],
					'limits' => 'late',
					'first' => 'high-showing',
				],
				[
					'name' => 'fifth',
					'deal' => [
						'down' => 0,
						'up' => 1,
						'board' => 0,
					],
					'limits' => 'late',
					'first' => 'high-showing',
				],
			],
		],
		'stud7' => [
			'label' => 'Seven-Card Stud',
			'seats' => 7,
			'forced' => 'ante-bring-in',
			'evaluate' => 'best5',
			'streets' => [
				[
					'name' => 'third',
					'deal' => [
						'down' => 2,
						'up' => 1,
						'board' => 0,
					],
					'limits' => 'early',
					'first' => 'low-up',
				],
				[
					'name' => 'fourth',
					'deal' => [
						'down' => 0,
						'up' => 1,
						'board' => 0,
					],
					'limits' => 'early',
					'first' => 'high-showing',
				],
				[
					'name' => 'fifth',
					'deal' => [
						'down' => 0,
						'up' => 1,
						'board' => 0,
					],
					'limits' => 'late',
					'first' => 'high-showing',
				],
				[
					'name' => 'sixth',
					'deal' => [
						'down' => 0,
						'up' => 1,
						'board' => 0,
					],
					'limits' => 'late',
					'first' => 'high-showing',
				],
				[
					'name' => 'seventh',
					'deal' => [
						'down' => 1,
						'up' => 0,
						'board' => 0,
					],
					'limits' => 'late',
					'first' => 'high-showing',
				],
			],
		],
	];

	/** @param array $definition One entry of GAMES. */
	private function __construct(public readonly string $id, private array $definition) {}

	/**
	 * Look a game up by id.
	 * @param string $id One of the keys of GAMES.
	 * @return self The game's definition.
	 */
	public static function of(string $id): self
	{
		if(!isset(self::GAMES[$id])) throw new \InvalidArgumentException('Unknown poker game');
		return new self($id, self::GAMES[$id]);
	}

	/** @return list<string> Game ids in menu order. */
	public static function ids(): array { return array_keys(self::GAMES); }
	/** @return string Name for menus and the table header. */
	public function label(): string { return $this->definition['label']; }
	/** @return int Most seats the deck can serve. */
	public function seats(): int { return $this->definition['seats']; }
	/** @return string `dealer-ante`, `blinds` or `ante-bring-in`. */
	public function forced(): string { return $this->definition['forced']; }
	/** @return bool Whether a showdown hand is the best five of more than five cards. */
	public function bestOfMany(): bool { return $this->definition['evaluate'] === 'best5'; }
	/** @return list<array> Streets in dealing order. */
	public function streets(): array { return $this->definition['streets']; }

	/**
	 * One street by position.
	 * @param int $index Zero-based street number.
	 * @return array Name, deal counts, limit tier, first-actor rule and the optional draw flag.
	 */
	public function street(int $index): array
	{
		return $this->definition['streets'][$index] ?? throw new \InvalidArgumentException('Unknown street');
	}

	/**
	 * Count the cards a seat and the board hold once the given street has been dealt.
	 * @param int $through Zero-based index of the last street dealt.
	 * @return array{down:int,up:int,board:int} Cumulative counts.
	 */
	public function dealt(int $through): array
	{
		$total = ['down'=>0,'up'=>0,'board'=>0];
		foreach(array_slice($this->definition['streets'], 0, $through + 1) as $street)
			foreach($total as $kind => $count) $total[$kind] = $count + $street['deal'][$kind];
		return $total;
	}

	/** @return int Cards in a seat's own hand at showdown. */
	public function handSize(): int
	{
		$total = $this->dealt(count($this->definition['streets']) - 1);
		return $total['down'] + $total['up'];
	}

	/** @return int Shared board cards at showdown. */
	public function boardSize(): int { return $this->dealt(count($this->definition['streets']) - 1)['board']; }
}
