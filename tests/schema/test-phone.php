<?php

posts([1 => ['acf' => [
    'paren_trunk' => '+61 (02) 9809 4464',
    'zero_trunk'  => '+61 (0)2 9809 4464',
    'bare_trunk'  => '+61 02 9809 4464',
    'national'    => '(02) 9809 4464',
    'dashed'      => '02-9809-4464',
    'mobile'      => '0412 345 678',
    'local_rate'  => '1300 123 674',
    'uk'          => '+44 (0) 20 7946 0958',
    'link'        => ['title' => '(02) 9809 4464', 'url' => 'tel:0298094464'],
    'tel'         => 'tel:+61298094464',
]]]);

check('phone: (02) after country code', '+61 2 9809 4464', token('{acf_paren_trunk:phone|+61}'));
check('phone: (02) without a prefix arg', '+61 2 9809 4464', token('{acf_paren_trunk:phone}'));
check('phone: (0)2', '+61 2 9809 4464', token('{acf_zero_trunk:phone|+61}'));
check('phone: bare trunk 0 after matching prefix', '+61 2 9809 4464', token('{acf_bare_trunk:phone|+61}'));
check('phone: national with brackets', '+61 2 9809 4464', token('{acf_national:phone|+61}'));
check('phone: dashes', '+61 2 9809 4464', token('{acf_dashed:phone|+61}'));
check('phone: mobile', '+61 412 345 678', token('{acf_mobile:phone|+61}'));
check('phone: 1300 keeps its digits', '+61 1300 123 674', token('{acf_local_rate:phone|+61}'));
check('phone: other country (0)', '+44 20 7946 0958', token('{acf_uk:phone}'));
check('phone: ACF link title', '+61 2 9809 4464', token('{acf_link:phone|+61}'));
check('phone: tel: prefix stripped', '+61298094464', token('{acf_tel:phone|+61}'));
