<?php

posts([1 => ['acf' => [
    'raw'      => '20261123',
    'display'  => '23/11/2026',
    'datetime' => '23/11/2026 7:30 pm',
    'stored'   => '2026-11-23 19:30:00',
    'start'    => '7:30 pm',
    'stored_time' => '19:30:00',
    'june'     => '20260615',
    'bad'      => '31/02/2026',
    'iso'      => '2026-11-23T08:30:00Z',
]]]);

check('date: Ymd stays a date, no UTC midnight shift', '2026-11-23', token('{acf_raw:date}'));
check('date: ACF d/m/Y return format', '2026-11-23', token('{acf_display:date}'));
check('date: d/m/Y with time', '2026-11-23T19:30:00+11:00', token('{acf_datetime:date}'));
check('date: Y-m-d H:i:s in site timezone', '2026-11-23T19:30:00+11:00', token('{acf_stored:date}'));
check('date: combines a time field', '2026-11-23T19:30:00+11:00', token('{acf_raw:date|acf_start}'));
check('date: combines a stored H:i:s time', '2026-11-23T19:30:00+11:00', token('{acf_display:date|acf_stored_time}'));
check('date: literal time argument', '2026-11-23T09:00:00+11:00', token("{acf_raw:date|'9:00'}"));
check('date: offset follows daylight saving', '2026-06-15T19:30:00+10:00', token('{acf_june:date|acf_start}'));
check('date: empty time field leaves a date', '2026-11-23', token('{acf_raw:date|acf_missing}'));
check('date: impossible date dropped', null, token('{acf_bad:date}'));
check('date: UTC instant converted to site time', '2026-11-23T19:30:00+11:00', token('{acf_iso:date}'));
