<?php

namespace EasyCo\Order\Tests;

use EasyCo\Order\Enums\OrderStatus;
use PHPUnit\Framework\TestCase;

/**
 * The transition matrix of EasyCo\Order\Enums\OrderStatus, pinned three ways:
 * the value list, all 36 (from, to) pairs, and which statuses are terminal.
 *
 * IT ALSO GUARDS THE PROSE. order-lifecycle-design.md §2.1's table is what a
 * human reads; the enum's map is what the code obeys (design doc §5.1/§11 item
 * 18 — the document promises exactly this test, so the two can never drift
 * apart silently). The document is read as a fixture: a reworded §2.1 fails
 * here loudly with the parse count, rather than passing vacuously.
 */
final class OrderStatusTest extends TestCase
{
    /**
     * §1's list, in §1's order: the case names and their string values.
     */
    private const EXPECTED_VALUES = [
        'placed',
        'confirmed',
        'shipped',
        'delivered',
        'cancelled',
        'refunded',
    ];

    /**
     * The seven legal moves, written out here by hand so this test is a second,
     * independent statement of §2.1's table rather than a loop over the
     * implementation it is testing.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const LEGAL_MOVES = [
        ['placed', 'confirmed'],
        ['placed', 'cancelled'],
        ['confirmed', 'shipped'],
        ['confirmed', 'cancelled'],
        ['shipped', 'delivered'],
        ['shipped', 'cancelled'],
        ['delivered', 'refunded'],
    ];

    private const TERMINAL_VALUES = ['cancelled', 'refunded'];

    private const DESIGN_DOCUMENT = __DIR__.'/../../documents/order-lifecycle-design.md';

    public function test_the_six_values_are_exactly_the_expected_list_in_this_order(): void
    {
        $this->assertSame(
            self::EXPECTED_VALUES,
            array_map(static fn (OrderStatus $status): string => $status->value, OrderStatus::cases()),
        );
    }

    public function test_every_legal_move_is_allowed(): void
    {
        foreach (self::LEGAL_MOVES as [$from, $to]) {
            $this->assertTrue(
                OrderStatus::from($from)->canTransitionTo(OrderStatus::from($to)),
                "{$from} -> {$to} is one of the seven legal moves and must be allowed.",
            );
        }
    }

    /**
     * The whole 6x6 in one assertion: 36 (from, to) pairs, of which exactly the
     * seven above are true. This is what pins "no skipping a step, no backwards
     * move, nothing out of a terminal status" — a hand-written list of the
     * pairs to refuse would miss whichever one somebody forgets to add.
     */
    public function test_the_matrix_over_all_thirty_six_pairs_is_exactly_the_seven_legal_moves(): void
    {
        $expected = [];
        $actual = [];

        foreach (OrderStatus::cases() as $from) {
            foreach (OrderStatus::cases() as $to) {
                $pair = $from->value.' -> '.$to->value;
                $expected[$pair] = in_array([$from->value, $to->value], self::LEGAL_MOVES, true);
                $actual[$pair] = $from->canTransitionTo($to);
            }
        }

        $this->assertSame($expected, $actual);
        $this->assertCount(36, $actual);
        $this->assertCount(7, array_keys($actual, true, true));
    }

    /** No status transitions to itself, including the two that have no exit at all. */
    public function test_no_status_transitions_to_itself(): void
    {
        foreach (OrderStatus::cases() as $status) {
            $this->assertFalse(
                $status->canTransitionTo($status),
                "{$status->value} -> {$status->value} is not a transition.",
            );
        }
    }

    public function test_only_cancelled_and_refunded_are_terminal(): void
    {
        foreach (OrderStatus::cases() as $status) {
            $this->assertSame(
                in_array($status->value, self::TERMINAL_VALUES, true),
                $status->isTerminal(),
                "{$status->value}'s terminality disagrees with §1's table.",
            );
        }
    }

    /** "Terminal" is not a second list: it is exactly "no legal move leaves here". */
    public function test_a_terminal_status_is_exactly_one_that_no_legal_move_leaves(): void
    {
        foreach (OrderStatus::cases() as $status) {
            $hasAnExit = false;

            foreach (OrderStatus::cases() as $to) {
                $hasAnExit = $hasAnExit || $status->canTransitionTo($to);
            }

            $this->assertSame(! $hasAnExit, $status->isTerminal());
        }
    }

    /**
     * The drift test: §2.1's table, parsed out of the document, is the same
     * seven moves. See the class docblock for why this reads a Markdown file.
     */
    public function test_the_design_documents_transition_table_lists_exactly_these_seven_moves(): void
    {
        $rows = $this->transitionTableRows();

        $this->assertCount(
            7,
            $rows,
            'Expected the seven rows of order-lifecycle-design.md §2.1. Zero here means the parse broke — the document was reworded — not that the matrix is empty.',
        );

        $expected = self::LEGAL_MOVES;
        sort($expected);
        sort($rows);

        $this->assertSame($expected, $rows, 'The document\'s transition table and the enum\'s map disagree.');
    }

    /**
     * The seven "| `from` | `to` | ..." rows between §2.1's heading and the
     * sentence that closes the table ("`cancelled` and `refunded` are
     * terminal..."). Both markers are asserted to exist, so a reworded document
     * fails here instead of silently parsing nothing — or parsing on past the
     * table into whatever follows it.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function transitionTableRows(): array
    {
        $this->assertFileExists(self::DESIGN_DOCUMENT);

        $lines = file(self::DESIGN_DOCUMENT, FILE_IGNORE_NEW_LINES);

        $heading = array_search('### 2.1 The transition table', $lines, true);

        $this->assertNotFalse(
            $heading,
            '§2.1\'s heading is gone: this test cannot tell which table is the transition table.',
        );

        $rows = [];
        $closed = false;

        foreach (array_slice($lines, $heading + 1) as $line) {
            if (str_starts_with(rtrim($line, "\r"), '`cancelled` and `refunded` are terminal')) {
                $closed = true;

                break;
            }

            if (preg_match('/^\| `(?<from>[a-z_]+)` \| `(?<to>[a-z_]+)` \|/', $line, $matches) === 1) {
                $rows[] = [$matches['from'], $matches['to']];
            }
        }

        $this->assertTrue(
            $closed,
            'The sentence that closes §2.1\'s table is gone: this test no longer knows where the table ends.',
        );

        return $rows;
    }
}
