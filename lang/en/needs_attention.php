<?php

/**
 * "Needs attention" (shipping-domain-design.md §7.2.20 §6) — every string the page draws that does
 * not come from a row itself.
 *
 * THE TWO FACT SENTENCES NAME WHAT THEY ARE ABOUT in words ("by cash", the three figures), so the
 * screen adds no words of its own to a fact: the row is the whole sentence. age_days is the one
 * plural on the page (:count is filled in by trans_choice), and today is the floor — a row that began
 * today, or one a merchant dated tomorrow by mistake, both read as a wait of nothing.
 *
 * section_empty is what a section says when it has nothing while other sections do; empty is what the
 * WHOLE page says when there is nothing anywhere (§6's own promise).
 *
 * THE INTRO NAMES WHAT IS WAITING AND NOTHING ELSE: one sentence for the two sources, then the ONE thing
 * this page may say about a wait (how long it has been), so nobody reading it expects a judgement of
 * urgency the page never makes (§7.2.7: no severity, no threshold, no "overdue").
 */
return [
    'title' => 'Needs attention',
    'navigation_label' => 'Needs attention',

    'intro' => 'Money that is waiting on someone: refunds that were recorded and not paid out yet, and bank transfers that do not add up. The days only say how long it has waited, and the oldest is first.',
    'empty' => 'Nothing is waiting.',
    'section_empty' => 'Nothing here.',
    'today' => 'today',
    'age_days' => ':count day|:count days',

    'columns' => [
        'order' => 'Order',
        'fact' => 'What happened',
        'amount' => 'Amount',
        'waiting' => 'Waiting',
    ],

    'pagination' => [
        'previous' => 'Previous',
        'next' => 'Next',
        'status' => 'Page :page of :last',
    ],

    /** How the refund's payout channel reads inside the fact sentence below. */
    'channels' => [
        'cash' => 'cash',
        'bank' => 'bank',
        'unknown' => 'an unrecorded channel',
    ],

    'sources' => [
        'owed_refund' => [
            'label' => 'Refunds owed',
            'fact' => 'Refund by :channel recorded, not paid out yet',
        ],
        'receipt_mismatch' => [
            'label' => 'Bank transfers that do not add up',
            'fact_short' => 'Short by :difference (received :received of :expected)',
            'fact_over' => 'Over by :difference (received :received of :expected)',
        ],
    ],
];
