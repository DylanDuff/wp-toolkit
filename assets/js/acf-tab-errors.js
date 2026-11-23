/*
 * Flag ACF tabs that contain validation errors (inc/tweaks/acf-tab-errors.php).
 * Adds .has-field-error to a tab's <li> while any field in that tab shows an error,
 * so an error on a hidden tab is visible without clicking through every tab.
 */
(function () {
  'use strict';

  var ERROR_CLASS = 'has-field-error';
  var TAB_SELECTOR = '.acf-field-tab';
  var ERROR_SELECTOR = '.acf-error';
  var TAB_WRAP_SELECTOR = '.acf-tab-wrap';
  var TAB_BUTTON_SELECTOR = '.acf-tab-button';

  // A tab's fields are the siblings after its tab row, up to the next tab row.
  // An error can sit on one of those fields or inside one (group, repeater).
  function tabHasError(tabRow) {
    var sibling = tabRow.nextElementSibling;

    while (sibling && !sibling.matches(TAB_SELECTOR)) {
      if (sibling.matches(ERROR_SELECTOR) || sibling.querySelector(ERROR_SELECTOR)) return true;
      sibling = sibling.nextElementSibling;
    }

    return false;
  }

  function findTabButton(wrap, key) {
    return wrap.querySelector(TAB_BUTTON_SELECTOR + '[data-key="' + key + '"]');
  }

  function syncTabButton(wrap, tabRow) {
    var key = tabRow.dataset.key;
    if (!key) return;

    var button = findTabButton(wrap, key);
    if (!button || !button.parentElement) return;

    var hasError = tabHasError(tabRow);
    button.parentElement.classList.toggle(ERROR_CLASS, hasError);
  }

  function syncAllTabs() {
    document.querySelectorAll(TAB_WRAP_SELECTOR).forEach(function (wrap) {
      var scope = wrap.closest('table, .acf-fields, form') || document;

      scope.querySelectorAll(TAB_SELECTOR).forEach(function (tabRow) {
        syncTabButton(wrap, tabRow);
      });
    });
  }

  function debounceToFrame(fn) {
    var pending = false;

    return function () {
      if (pending) return;
      pending = true;

      requestAnimationFrame(function () {
        pending = false;
        fn();
      });
    };
  }

  function watchDomForChanges(onChange) {
    new MutationObserver(onChange).observe(document.body, {
      subtree: true,
      childList: true,
      attributes: true,
      attributeFilter: ['class']
    });
  }

  function subscribeToAcfEvents(onChange) {
    if (!window.acf || typeof acf.addAction !== 'function') return;

    ['invalid_field', 'valid_field', 'append', 'remove'].forEach(function (event) {
      acf.addAction(event, onChange);
    });
  }

  function whenReady(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  whenReady(function () {
    var scheduleSync = debounceToFrame(syncAllTabs);

    syncAllTabs();
    watchDomForChanges(scheduleSync);
    subscribeToAcfEvents(scheduleSync);
  });
})();
