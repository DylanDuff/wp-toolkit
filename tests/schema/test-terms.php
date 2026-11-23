<?php

function schema_test_term(string $name, string $slug): WP_Term
{
    $term = new WP_Term();
    [$term->name, $term->slug, $term->taxonomy] = [$name, $slug, 'department'];
    return $term;
}

posts([1 => [
    'terms' => ['department' => [schema_test_term('Nursing', 'nursing'), schema_test_term('Allied Health', 'allied-health')]],
    'acf'   => ['areas' => [schema_test_term('Physio', 'physio')], 'ids' => ['10'], 'checks' => ['a', 'b']],
]]);

$node = render(['@type' => 'Thing', 'about' => ['@each' => 'post_terms_department', '@type' => 'DefinedTerm', 'name' => '{item_name}', 'termCode' => '{item_slug}']]);
check('@each: post_terms_ rows (regression 1.5.19)', [
    ['@type' => 'DefinedTerm', 'name' => 'Nursing', 'termCode' => 'nursing'],
    ['@type' => 'DefinedTerm', 'name' => 'Allied Health', 'termCode' => 'allied-health'],
], $node['about'] ?? null);

$node = render(['@type' => 'Thing', 'about' => ['@each' => 'acf_areas', '@value' => '{item:name}']]);
check('@each: ACF taxonomy field terms', ['Physio'], $node['about'] ?? null);

$node = render(['@type' => 'Thing', 'keywords' => ['@each' => 'acf_checks', '@value' => '{item}']]);
check('@each: plain string rows untouched', ['a', 'b'], $node['keywords'] ?? null);

$node = render(['@type' => 'Thing', 'name' => '{post_terms_department:name:join}']);
check('post_terms_ name join', 'Nursing, Allied Health', $node['name'] ?? null);
