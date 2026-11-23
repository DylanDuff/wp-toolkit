/*
 * Bricks component property groups as Gutenberg sidebar panels
 * (inc/tweaks/bricks-component-groups.php). See docs/bricks-builder-tweaks.md.
 *
 * Bricks (2.1+) exposes components as blocks but renders every property in one flat
 * "Properties" panel. This keeps ungrouped properties there and renders each property
 * group as its own panel. It wraps window.bricksCreatePropertyControl, an undocumented
 * Bricks global, so re-check after Bricks updates.
 */
(function () {
	if (typeof window.bricksCreatePropertyControl !== 'function' || !window.wp || !window.wp.hooks) {
		return;
	}

	const { createElement, Fragment } = window.wp.element;
	const { PanelBody } = window.wp.components;
	const { InspectorControls } = window.wp.blockEditor || window.wp.editor;
	const createControl = window.bricksCreatePropertyControl;

	function getGroups(component) {
		return component && Array.isArray(component.propertyGroups) ? component.propertyGroups : [];
	}

	function isGrouped(property, component) {
		return !!property.group && getGroups(component).some((group) => group.id === property.group);
	}

	function isConnected(property, component) {
		const direct = property.connections && typeof property.connections === 'object' && Object.keys(property.connections).length > 0;
		const nested = typeof window.isConnectedToNestedInstance === 'function' && window.isConnectedToNestedInstance(component, property.id);
		return direct || nested;
	}

	function getComponentId(blockName, attributes) {
		if (attributes && attributes.componentId) {
			return attributes.componentId;
		}
		if (blockName.indexOf('bricks-components/') === 0) {
			return blockName.slice('bricks-components/'.length);
		}
		const hex = blockName.indexOf('bricks-component-ids/id-') === 0 ? blockName.slice('bricks-component-ids/id-'.length) : '';
		return hex ? hex.replace(/../g, (pair) => String.fromCharCode(parseInt(pair, 16))) : '';
	}

	function findComponent(blockName, attributes) {
		if (blockName.indexOf('bricks-component') !== 0) {
			return null;
		}
		const components = (window.bricksGutenbergData && window.bricksGutenbergData.components) || [];
		const componentId = getComponentId(blockName, attributes);
		return components.find((component) => component.id === componentId) || null;
	}

	// Hide grouped properties from Bricks' own flat "Properties" panel
	window.bricksCreatePropertyControl = function (property, props, component) {
		return isGrouped(property, component) ? null : createControl.apply(this, arguments);
	};

	function GroupedPanels({ props, component }) {
		const groups = getGroups(component);
		const properties = (component.properties || []).filter((property) => property.id && property.label && isConnected(property, component));
		const hasUngrouped = properties.some((property) => !isGrouped(property, component));
		const panels = [];

		groups.forEach((group) => {
			const controls = properties
				.filter((property) => property.group === group.id)
				.map((property) => createControl(property, props, component))
				.filter(Boolean);

			if (!controls.length) {
				return;
			}

			panels.push(createElement(PanelBody, {
				key: group.id,
				title: group.name,
				initialOpen: !hasUngrouped && panels.length === 0,
			}, controls));
		});

		return panels.length ? createElement(InspectorControls, null, panels) : null;
	}

	window.wp.hooks.addFilter('editor.BlockEdit', 'bricks/property-groups', (BlockEdit) => (props) => {
		const component = findComponent(props.name || '', props.attributes);

		if (!component || !getGroups(component).length || props.attributes._preview) {
			return createElement(BlockEdit, props);
		}

		return createElement(Fragment, null,
			createElement(BlockEdit, props),
			createElement(GroupedPanels, { props, component })
		);
	});
})();
