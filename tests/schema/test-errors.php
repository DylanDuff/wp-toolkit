<?php

use DDWPTweaks\SEO\Schema_Renderer;

posts([
    1 => ['post_title' => 'Fine', 'acf' => ['related' => [2]]],
    2 => ['post_title' => 'Broken', 'throws' => true],
]);

$renderer = new Schema_Renderer(1);
$nodes    = @$renderer->render([
    ['@type' => 'Thing', '@from' => 'acf_related', 'name' => '{acf_anything}'],
    ['@type' => 'WebPage', 'name' => '{post_title}'],
]);
check('render: a throwing node is dropped, the rest render against the right post', [['@type' => 'WebPage', 'name' => 'Fine']], $nodes);
check('render: the failure is reported as a warning', true, (bool) preg_grep('/^Thing failed to render/', $renderer->warnings()));
