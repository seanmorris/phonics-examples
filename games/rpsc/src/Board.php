<?php
declare(strict_types=1);
namespace Rpsc;

/**
 * Board geometry and the rock-paper-scissors order for RPSC.
 *
 * Squares are numbered 0 to 63 from a1 along each rank, so `square = y * 8 + x`, and a1 is a black square as on a
 * tournament checkers board. Each side plays only on the squares of the other side's color: Black on the red
 * squares and Red on the black squares. Opposing pieces therefore never share a diagonal. They cannot block or jump
 * each other, and they meet only edge to edge, which is where a challenge happens.
 */
final class Board
{
	/** Squares along each edge of the board. */
	public const SIZE = 8;
	/** Squares on the whole board. */
	public const SQUARES = 64;
	/** The side that moves first. It starts on ranks 1 to 3 and plays up the board on the red squares. */
	public const BLACK = 0;
	/** The side that moves second. It starts on ranks 6 to 8 and plays down the board on the black squares. */
	public const RED = 1;
	/** Rock: beats scissors and loses to paper. */
	public const ROCK = 0;
	/** Paper: beats rock and loses to scissors. */
	public const PAPER = 1;
	/** Scissors: beats paper and loses to rock. */
	public const SCISSORS = 2;
	/** Checkers each side starts with. */
	public const PIECES = 12;
	/** Checkers of each kind in one side's starting twelve. */
	public const PER_KIND = 4;
	/** Squares each side has to fill on the far rank to win by occupation. */
	public const GOALS = 4;
	/** Side names in side order, for labels and notation. */
	public const SIDES = ['Black', 'Red'];
	/** Kind names in kind order, for labels and notation. */
	public const KINDS = ['Rock', 'Paper', 'Scissors'];

	/**
	 * Find the square at a file and rank.
	 *
	 * @param int $x File from 0 (a) to 7 (h).
	 * @param int $y Rank from 0 (rank 1) to 7 (rank 8).
	 * @return int Square number, or -1 when the position is off the board.
	 */
	public static function square(int $x, int $y): int
	{
		return $x < 0 || $y < 0 || $x >= self::SIZE || $y >= self::SIZE ? -1 : $y * self::SIZE + $x;
	}

	/**
	 * Read a square's file.
	 *
	 * @param int $square Square number from 0 to 63.
	 * @return int File from 0 (a) to 7 (h).
	 */
	public static function x(int $square): int
	{
		return $square % self::SIZE;
	}

	/**
	 * Read a square's rank.
	 *
	 * @param int $square Square number from 0 to 63.
	 * @return int Rank from 0 (rank 1) to 7 (rank 8).
	 */
	public static function y(int $square): int
	{
		return intdiv($square, self::SIZE);
	}

	/**
	 * Name the side that plays on a square.
	 *
	 * @param int $square Square number from 0 to 63.
	 * @return int Board::BLACK for a red square, Board::RED for a black square.
	 */
	public static function owner(int $square): int
	{
		return (self::x($square) + self::y($square)) % 2 === 1 ? self::BLACK : self::RED;
	}

	/**
	 * Name the opposing side.
	 *
	 * @param int $side Board::BLACK or Board::RED.
	 * @return int The other side.
	 */
	public static function other(int $side): int
	{
		return 1 - $side;
	}

	/**
	 * Direction a side's men travel along the ranks.
	 *
	 * @param int $side Board::BLACK or Board::RED.
	 * @return int 1 for Black, which moves up the board, and -1 for Red.
	 */
	public static function forward(int $side): int
	{
		return $side === self::BLACK ? 1 : -1;
	}

	/**
	 * Rank a square stands on, counted from its side's own back rank.
	 *
	 * @param int $side Board::BLACK or Board::RED.
	 * @param int $square Square number from 0 to 63.
	 * @return int 0 on the side's own back rank through 7 on the far rank.
	 */
	public static function advance(int $side, int $square): int
	{
		return $side === self::BLACK ? self::y($square) : self::SIZE - 1 - self::y($square);
	}

	/**
	 * List a side's twelve starting squares in placement order.
	 *
	 * The order runs from the side's own back rank forward, and from file a to file h within a rank. A placement names
	 * one kind for each of these squares in this order.
	 *
	 * @param int $side Board::BLACK or Board::RED.
	 * @return list<int> Twelve square numbers.
	 */
	public static function home(int $side): array
	{
		$squares = [];

		for($row = 0; $row < 3; $row++)
		{
			$y = $side === self::BLACK ? $row : self::SIZE - 1 - $row;

			for($x = 0; $x < self::SIZE; $x++)
			{
				$square = self::square($x, $y);

				if(self::owner($square) === $side) $squares[] = $square;
			}
		}

		return $squares;
	}

	/**
	 * List the four squares a side must fill on the far rank.
	 *
	 * They are the squares of that side's own playing color on the opponent's back rank, between the opponent's four
	 * home squares.
	 *
	 * @param int $side Board::BLACK or Board::RED.
	 * @return list<int> Four square numbers from file a to file h.
	 */
	public static function goal(int $side): array
	{
		$squares = [];
		$y = $side === self::BLACK ? self::SIZE - 1 : 0;

		for($x = 0; $x < self::SIZE; $x++)
		{
			$square = self::square($x, $y);

			if(self::owner($square) === $side) $squares[] = $square;
		}

		return $squares;
	}

	/**
	 * List the squares a piece could step to on an empty board.
	 *
	 * @param int $square Square the piece stands on.
	 * @param int $side Side the piece belongs to.
	 * @param bool $king Whether the piece is crowned and may also step backward.
	 * @return list<int> Diagonal neighbors on the board, forward steps first.
	 */
	public static function steps(int $square, int $side, bool $king): array
	{
		$x = self::x($square);
		$y = self::y($square);
		$forward = self::forward($side);
		$targets = [];

		foreach($king ? [$forward, -$forward] : [$forward] as $dy) foreach([-1, 1] as $dx)
		{
			$target = self::square($x + $dx, $y + $dy);

			if($target >= 0) $targets[] = $target;
		}

		return $targets;
	}

	/**
	 * List the squares that share an edge with a square.
	 *
	 * These always belong to the other side, so they are the only squares an opposing piece can be challenged on.
	 *
	 * @param int $square Square number from 0 to 63.
	 * @return list<int> Up to four orthogonal neighbors on the board.
	 */
	public static function neighbors(int $square): array
	{
		$x = self::x($square);
		$y = self::y($square);
		$targets = [];

		foreach([[0, 1], [1, 0], [0, -1], [-1, 0]] as [$dx, $dy])
		{
			$target = self::square($x + $dx, $y + $dy);

			if($target >= 0) $targets[] = $target;
		}

		return $targets;
	}

	/**
	 * Decide whether one kind beats another.
	 *
	 * @param int $kind Kind of the piece asking.
	 * @param int $against Kind of the piece it faces.
	 * @return bool True when rock meets scissors, scissors meets paper, or paper meets rock.
	 */
	public static function beats(int $kind, int $against): bool
	{
		return ($kind - $against + 3) % 3 === 1;
	}

	/**
	 * Write a square in board notation.
	 *
	 * @param int $square Square number from 0 to 63.
	 * @return string File letter and rank digit, such as "c3".
	 */
	public static function name(int $square): string
	{
		return chr(97 + self::x($square)) . (self::y($square) + 1);
	}
}
