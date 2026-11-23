<?php

posts([1 => [
    'post_title' => 'Nick&#8217;s guide &amp; tips',
    'acf'        => [
        'answer' => '<p>Book&nbsp;online &#8211; it&#8217;s&nbsp;&nbsp;quick.</p>',
        'list'   => ['A&amp;B', '<b>C&#8217;s</b>'],
    ],
]]);

check('clean: decodes numeric entities', 'Nick’s guide & tips', token('{post_title:clean}'));
check('clean: nbsp becomes a collapsed space', 'Book online – it’s quick.', token('{acf_answer:clean}'));
check('clean: lists', ['A&B', 'C’s'], token('{acf_list:clean}'));
check('summary: decodes entities too', 'Book online – it’s quick.', token('{acf_answer:summary}'));

check('post_title: decoded without clean', 'Nick’s guide & tips', token('{post_title}'));
check('post transform: decoded names', 'Nick’s guide & tips', token('{post_id:post|Thing}')['name']);
