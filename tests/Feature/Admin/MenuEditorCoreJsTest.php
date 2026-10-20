<?php

namespace Tests\Feature\Admin;

use PHPUnit\Framework\TestCase;

/** MENU-2: the rules of a move in the menu editor (resources/js/menu-editor-core.js), run under node without a browser. Skipped when node is not installed. */
class MenuEditorCoreJsTest extends TestCase
{
    public function test_the_rules_of_a_move_hold(): void
    {
        $node = trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node'));

        if ($node === '') {
            $this->markTestSkipped('node is not installed');
        }

        $output = [];
        exec('node '.escapeshellarg(__DIR__.'/Js/menu-editor-core.mjs').' 2>&1', $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertMatchesRegularExpression('/^OK \d+$/', trim(implode("\n", $output)));
    }
}
