<?php
/**
 * Which native Bricks filter element each Zag element impersonates.
 *
 * Its own file, and deliberately free of WordPress calls, so the test harness
 * can require the real thing rather than keep a copy. A duplicated map goes
 * stale silently: the tests still pass, because a missing entry makes the bridge
 * skip the element exactly as it would for a native one.
 */

namespace Zag;

if ( ! function_exists( __NAMESPACE__ . '\element_map' ) ) {
	/**
	 * Element names this plugin registers, mapped to the native Bricks filter
	 * element each one impersonates for indexing and query building.
	 *
	 * Bricks gates its filter pipeline on hardcoded element-name lists in
	 * includes/query-filters.php (filter_controls_elements, indexable_elements,
	 * dynamic_update_elements). Custom names are invisible to all three, so every
	 * integration point needs to know which native element to stand in for.
	 *
	 * zag-smart-search and zag-active-filters are deliberately absent: they hold
	 * no filter value, so they have nothing to index, no query vars to build and
	 * no counts to refresh. Every hook in Query_Filters_Bridge keys off this map,
	 * which is exactly what keeps them out of all of them.
	 *
	 * @see Query_Filters_Bridge
	 */
	function element_map() {
		return [
			'zag-filter-select'     => 'filter-select',
			'zag-filter-datepicker' => 'filter-datepicker',

			/**
			 * Sorting is gated on the same name lists as filtering.
			 *
			 * Present so Query_Filters_Bridge::build_query_vars() picks the
			 * element up — Bricks' own sort path bails on anything that isn't
			 * filter-select or filter-radio (query-filters.php:2106), and because
			 * it `continue`s, its dedicated sort hook is unreachable for us.
			 */
			'zag-sort'              => 'filter-select',
		];
	}
}

if ( ! function_exists( __NAMESPACE__ . '\default_sort_options' ) ) {
	/**
	 * What a zag-sort with an empty Sort options repeater offers.
	 *
	 * Bricks' repeater starts empty and the element used to render an empty
	 * menu, which reads as a sort that does nothing. Rows use Bricks' own
	 * sortOptions shape so setup_sort_options() and get_selected_sort_option()
	 * consume them unchanged. Labels stay plain strings so this file keeps no
	 * WordPress dependency (see the note at the top).
	 */
	function default_sort_options() {
		return [
			[ 'optionLabel' => 'Newest', 'optionSource' => 'date', 'optionOrder' => 'DESC' ],
			[ 'optionLabel' => 'Oldest', 'optionSource' => 'date', 'optionOrder' => 'ASC' ],
			[ 'optionLabel' => 'Title A-Z', 'optionSource' => 'title', 'optionOrder' => 'ASC' ],
			[ 'optionLabel' => 'Title Z-A', 'optionSource' => 'title', 'optionOrder' => 'DESC' ],
		];
	}
}

if ( ! function_exists( __NAMESPACE__ . '\element_settings' ) ) {
	/**
	 * Settings as an element actually behaves, not as they were saved.
	 *
	 * zag-sort has no Action control, so its saved settings never carry
	 * filterAction. Forcing it only in render() was not enough: the filter
	 * request builds query vars from the raw page data, where the element looked
	 * like a filter with no source and was skipped, so selecting an option never
	 * changed the order. Every reader (render, query vars, index rows) goes
	 * through here instead.
	 *
	 * @param string $name     Element name from page data.
	 * @param array  $settings Saved element settings.
	 */
	function element_settings( $name, $settings ) {
		$settings = is_array( $settings ) ? $settings : [];

		if ( $name !== 'zag-sort' ) {
			return $settings;
		}

		$settings['filterAction'] = 'sort';

		if ( empty( $settings['sortOptions'] ) ) {
			$settings['sortOptions'] = default_sort_options();
		}

		return $settings;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\sort_option_items' ) ) {
	/**
	 * Sort options as menu items, with values in Bricks' `<key>_<ORDER>` form.
	 *
	 * Built here rather than taken from Filter_Element::setup_sort_options(),
	 * which leaves get_populated_options() empty for our element on Bricks 2.4,
	 * so the menu rendered with nothing in it. The key mirrors the matching in
	 * Query_Filters::get_selected_sort_option(): the source without its term|
	 * or user| prefix, or the meta key for a custom field source, so every value
	 * rendered here is one the query side can resolve.
	 *
	 * @param array $sort_options Rows from the sortOptions repeater.
	 * @return array[] Each `[ 'value' => string, 'text' => string ]`.
	 */
	function sort_option_items( $sort_options ) {
		$items = [];

		foreach ( (array) $sort_options as $option ) {
			$source = str_replace( [ 'term|', 'user|' ], '', (string) ( $option['optionSource'] ?? '' ) );

			if ( $source === '' ) {
				continue;
			}

			$key = in_array( $source, [ 'meta_value', 'meta_value_num' ], true ) && ! empty( $option['optionMetaKey'] )
				? (string) $option['optionMetaKey']
				: $source;

			$value = $key . '_' . ( $option['optionOrder'] ?? 'ASC' );
			$text  = trim( (string) ( $option['optionLabel'] ?? '' ) );

			$items[] = [
				'value' => $value,
				'text'  => $text !== '' ? $text : $value,
			];
		}

		return $items;
	}
}
