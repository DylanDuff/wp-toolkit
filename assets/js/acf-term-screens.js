/*
 * Tidier term edit screens (inc/tweaks/acf-term-screens.php). Printed in the footer of
 * term.php at admin_footer priority 1, before ACF initialises its tabs.
 */

/*
 * Runs before ACF initialises tabs (ACF waits for document ready). Left-placed tab
 * buttons inside a form-table are switched to "top" so ACF skips its left-tab
 * onRefresh, which assumes the <ul> is a direct child of the tab wrap and throws
 * "Cannot read properties of undefined (reading 'top')" when the wrap is a <tr>.
 * The real placement is kept in data-ddwpt-placement and the table is flagged for the CSS.
 */
document.querySelectorAll('table.form-table .acf-tab-button[data-placement="left"]').forEach(function (button) {
    var table = button.closest('table.form-table');
    var heading = table.previousElementSibling;

    button.dataset.placement = 'top';
    button.dataset.ddwptPlacement = 'left';
    table.classList.add('ddwpt-acf-left-tabs');

    // ACF prints each field group on a term screen as <h2>Group title</h2><table>.
    if (heading && heading.tagName === 'H2') {
        heading.classList.add('ddwpt-acf-left-tabs-heading');
    }
});
