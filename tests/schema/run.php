<?php

/**
 * Schema renderer tests. Run with: php tests/schema/run.php
 * Exits non-zero when any check fails.
 */

require __DIR__ . '/bootstrap.php';

use DDWPTweaks\SEO\Schema_Renderer;

$GLOBALS['schema_test_failures'] = 0;
$GLOBALS['schema_test_count']    = 0;

function check(string $name, $expected, $actual): void
{
    $GLOBALS['schema_test_count']++;
    if ($expected === $actual) return;

    $GLOBALS['schema_test_failures']++;
    echo "FAIL $name\n  expected: " . json_encode($expected, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        . "\n  actual:   " . json_encode($actual, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}

/** Renders one template node against post 1 and returns the first graph node. */
function render(array $template, int $post_id = 1): ?array
{
    $nodes = (new Schema_Renderer($post_id))->render($template);
    return $nodes[0] ?? null;
}

/** Renders "{tag:filters}" as the value of a single property. */
function token(string $expression, int $post_id = 1)
{
    return render(['@type' => 'Thing', 'value' => $expression], $post_id)['value'] ?? null;
}

function posts(array $posts): void
{
    $GLOBALS['schema_test_posts'] = [];
    foreach ($posts as $id => $post) {
        $GLOBALS['schema_test_posts'][$id] = array_merge(['ID' => $id, 'post_status' => 'publish'], $post);
    }
}

foreach (glob(__DIR__ . '/test-*.php') as $file) {
    require $file;
}

echo $GLOBALS['schema_test_count'] - $GLOBALS['schema_test_failures'] . ' of ' . $GLOBALS['schema_test_count'] . " checks passed\n";
exit($GLOBALS['schema_test_failures'] ? 1 : 0);
