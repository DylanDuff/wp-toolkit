<?php

posts([
    1  => ['acf' => ['faqs' => [10, 11, 12, 13], 'drafts' => [11, 12], 'gallery' => [20]]],
    10 => ['post_title' => 'Published question', 'acf' => ['answer' => 'Yes']],
    11 => ['post_title' => 'Draft question', 'post_status' => 'draft', 'acf' => ['answer' => 'No']],
    12 => ['post_title' => 'Trashed question', 'post_status' => 'trash', 'acf' => ['answer' => 'No']],
    13 => ['post_title' => 'Second published', 'acf' => ['answer' => 'Also yes']],
    20 => ['post_type' => 'attachment', 'post_status' => 'inherit'],
]);

$question = ['@type' => 'Question', 'name' => '{post_title}', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => '{acf_answer}']];

$faq = render(['@type' => 'FAQPage', 'mainEntity' => array_merge(['@from' => 'acf_faqs'], $question)]);
check('@from: skips draft and trashed posts', ['Published question', 'Second published'], array_column($faq['mainEntity'], 'name'));

$faq = render(['@type' => 'FAQPage', 'mainEntity' => array_merge(['@from' => 'acf_drafts', '@else' => 'none'], $question)]);
check('@from: only unpublished posts falls back to @else', 'none', $faq['mainEntity']);

$list = render(['@type' => 'ItemList', 'itemListElement' => ['@each' => 'acf_faqs', '@type' => 'ListItem', 'position' => '{query_loop_index}', 'name' => '{item:name}']]);
check('@each: skips unpublished rows', ['Published question', 'Second published'], array_column($list['itemListElement'], 'name'));
check('@each: positions count published rows only', [1, 2], array_column($list['itemListElement'], 'position'));

$images = render(['@type' => 'Thing', 'image' => ['@each' => 'acf_gallery', '@value' => '{item:image}']]);
check('@each: attachments still count', ['https://example.com/image-20.jpg'], $images['image']);

check('post transform: skips unpublished', ['Published question', 'Second published'], array_column(token('{acf_faqs:post|Question}'), 'name'));
