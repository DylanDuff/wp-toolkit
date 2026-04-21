(function () {
    'use strict';

    // ── Tab switching ─────────────────────────────────────────────────
    var tabs   = document.querySelectorAll('.ddwpt-tab');
    var panels = document.querySelectorAll('.ddwpt-panel');
    var initialized = {};

    function initEditors(panel) {
        if (initialized[panel.dataset.tab]) return;
        initialized[panel.dataset.tab] = true;

        panel.querySelectorAll('.wp-editor-wrap').forEach(function (wrap) {
            var id = wrap.id.replace(/^wp-/, '').replace(/-wrap$/, '');
            if (typeof tinymce !== 'undefined' && tinymce.get(id)) return;
            if (typeof tinyMCEPreInit !== 'undefined' && tinyMCEPreInit.mceInit && tinyMCEPreInit.mceInit[id] && typeof tinymce !== 'undefined') {
                tinymce.init(tinyMCEPreInit.mceInit[id]);
            }
            if (typeof tinyMCEPreInit !== 'undefined' && tinyMCEPreInit.qtInit && tinyMCEPreInit.qtInit[id] && typeof quicktags !== 'undefined') {
                quicktags(tinyMCEPreInit.qtInit[id]);
                QTags._buttonsInit();
            }
        });
    }

    function activateTab(tabId) {
        tabs.forEach(function (t) {
            t.classList.toggle('is-active', t.dataset.tab === tabId);
        });
        panels.forEach(function (p) {
            var active = p.dataset.tab === tabId;
            p.style.display = active ? '' : 'none';
            if (active) initEditors(p);
        });
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function (e) {
            e.preventDefault();
            var id = this.dataset.tab;
            history.replaceState(null, '', '#' + id);
            activateTab(id);
        });
    });

    // Preserve active tab + flag save through form submission
    var form = document.querySelector('form[action="options.php"]');
    if (form) {
        form.addEventListener('submit', function () {
            var referer = form.querySelector('input[name="_wp_http_referer"]');
            if (referer) {
                referer.value = referer.value.replace(/#.*$/, '') + location.hash;
            }
            sessionStorage.setItem('ddwpt_saved', '1');
        });
    }

    // Activate from URL hash or first tab
    var hash = location.hash.replace('#', '');
    var firstTab = tabs[0] ? tabs[0].dataset.tab : '';
    activateTab(hash && document.querySelector('.ddwpt-panel[data-tab="' + hash + '"]') ? hash : firstTab);

    // ── Card toggle — disabled state ──────────────────────────────────
    document.querySelectorAll('.ddwpt-card .ddwpt-toggle input[type="checkbox"]').forEach(function (toggle) {
        var card = toggle.closest('.ddwpt-card');
        if (!card) return;

        function sync() {
            card.classList.toggle('is-disabled', !toggle.checked);
        }

        toggle.addEventListener('change', sync);
    });

    // ── Saved button state ────────────────────────────────────────────
    if (sessionStorage.getItem('ddwpt_saved')) {
        sessionStorage.removeItem('ddwpt_saved');
        var saveBtn = document.querySelector('.ddwpt-save-btn');
        if (saveBtn) {
            saveBtn.textContent = '✓ Saved';
            saveBtn.classList.add('is-saved');
            setTimeout(function () {
                saveBtn.textContent = 'Save Changes';
                saveBtn.classList.remove('is-saved');
            }, 3000);
        }
    }

    // ── Media picker fields ───────────────────────────────────────────
    document.querySelectorAll('.ddwpt-media-field').forEach(function (wrap) {
        var input   = wrap.querySelector('input[type="hidden"]');
        var preview = wrap.querySelector('.ddwpt-media-preview');
        var removeBtn = wrap.querySelector('.ddwpt-media-remove');

        wrap.querySelector('.ddwpt-media-select').addEventListener('click', function () {
            var frame = wp.media({
                title: 'Select Image',
                multiple: false,
                library: { type: 'image' }
            });
            frame.on('select', function () {
                var url = frame.state().get('selection').first().toJSON().url;
                input.value = url;
                var img = document.createElement('img');
                img.src = url;
                img.style.cssText = 'max-width:200px;max-height:60px;display:block;margin-bottom:8px;';
                preview.innerHTML = '';
                preview.appendChild(img);
                removeBtn.style.display = '';
            });
            frame.open();
        });

        removeBtn.addEventListener('click', function () {
            input.value = '';
            preview.innerHTML = '';
            this.style.display = 'none';
        });
    });

    // ── Multiselect — sync to hidden input as JSON ────────────────────
    document.querySelectorAll('.ddwpt-multiselect').forEach(function (sel) {
        var input = document.getElementById(sel.dataset.input);
        if (!input) return;

        sel.addEventListener('change', function () {
            var values = Array.from(sel.options)
                .filter(function (o) { return o.selected; })
                .map(function (o) { return o.value; });
            input.value = JSON.stringify(values);
        });
    });

    // ── Sortable fields ───────────────────────────────────────────────
    if (typeof jQuery !== 'undefined') {
        jQuery(function ($) {
            $('.ddwpt-sortable-wrap').each(function () {
                var $wrap    = $(this);
                var inputId  = $wrap.data('input');
                var $visible = $wrap.find('.ddwpt-sortable-visible');
                var $hidden  = $wrap.find('.ddwpt-sortable-hidden');

                function sync() {
                    var order  = $visible.children('li').map(function () { return $(this).data('key'); }).get();
                    var hidden = $hidden.children('li').map(function () { return $(this).data('key'); }).get();
                    $('#' + inputId).val(JSON.stringify({ order: order, hidden: hidden }));
                }

                $visible.sortable({
                    connectWith: $hidden,
                    placeholder: 'ui-sortable-placeholder',
                    cursor: 'grabbing',
                    update: sync,
                    receive: sync
                });

                $hidden.sortable({
                    connectWith: $visible,
                    placeholder: 'ui-sortable-placeholder',
                    cursor: 'grabbing',
                    update: sync,
                    receive: sync
                });
            });
        });
    }
})();
