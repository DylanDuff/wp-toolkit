(function () {
    'use strict';

    // ── Tab switching ─────────────────────────────────────────────────
    var tabs         = document.querySelectorAll('.ddwpt-tab');
    var panels       = document.querySelectorAll('.ddwpt-panel');
    var nestedGroups = document.querySelectorAll('.ddwpt-subtabs-nested');
    var nestedLinks  = document.querySelectorAll('.ddwpt-subtab-nested');
    var initialized  = {};

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

    // Sub-panel switching within a single top-level tab. Falls back to the
    // first sub-panel when no specific one is requested (e.g. switching
    // tabs via the top-level nav rather than a nested sub-tab link).
    function activateSubtab(tabId, subtabId) {
        var panel     = document.querySelector('.ddwpt-panel[data-tab="' + tabId + '"]');
        var subpanels = panel ? panel.querySelectorAll('.ddwpt-subpanel') : [];
        if (!subpanels.length) return;

        var target = subtabId || subpanels[0].dataset.subtab;
        subpanels.forEach(function (p) {
            p.style.display = p.dataset.subtab === target ? '' : 'none';
        });
        nestedLinks.forEach(function (link) {
            if (link.dataset.tab === tabId) {
                link.classList.toggle('is-active', link.dataset.subtab === target);
            }
        });
    }

    function activateTab(tabId, subtabId) {
        tabs.forEach(function (t) {
            t.classList.toggle('is-active', t.dataset.tab === tabId);
        });
        panels.forEach(function (p) {
            var active = p.dataset.tab === tabId;
            p.style.display = active ? '' : 'none';
            if (active) initEditors(p);
        });
        nestedGroups.forEach(function (wrap) {
            wrap.classList.toggle('is-open', wrap.dataset.tab === tabId);
        });
        activateSubtab(tabId, subtabId);
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function (e) {
            e.preventDefault();
            var id = this.dataset.tab;
            history.replaceState(null, '', '#' + id);
            activateTab(id);
        });
    });

    nestedLinks.forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            var tabId = this.dataset.tab;
            history.replaceState(null, '', '#' + tabId);
            activateTab(tabId, this.dataset.subtab);
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

    // ── Checkboxes — sync to hidden input as JSON ─────────────────────
    document.querySelectorAll('.ddwpt-checkboxes-wrap').forEach(function (wrap) {
        var input = document.getElementById(wrap.dataset.input);
        if (!input) return;

        function sync() {
            var values = Array.from(wrap.querySelectorAll('input[type="checkbox"]'))
                .filter(function (cb) { return cb.checked; })
                .map(function (cb) { return cb.value; });
            input.value = JSON.stringify(values);
        }

        wrap.querySelectorAll('input[type="checkbox"]').forEach(function (cb) {
            cb.addEventListener('change', sync);
        });
    });

    // ── Export settings ───���────────────────────────────��──────────────
    var exportBtn = document.querySelector('.ddwpt-export-btn');
    if (exportBtn) {
        exportBtn.addEventListener('click', function () {
            exportBtn.textContent = 'Exporting…';
            exportBtn.disabled = true;

            fetch(ddwptSettings.ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'ddwpt_export_settings',
                    nonce: ddwptSettings.exportNonce
                })
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) throw new Error('Export failed');
                var blob = new Blob([JSON.stringify(res.data, null, 2)], { type: 'application/json' });
                var url  = URL.createObjectURL(blob);
                var a    = document.createElement('a');
                a.href     = url;
                a.download = 'wp-toolkit-settings.json';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            })
            .catch(function () {
                alert('Export failed. Please try again.');
            })
            .finally(function () {
                exportBtn.textContent = 'Export';
                exportBtn.disabled = false;
            });
        });
    }

    // ── Import settings ───────────────────────────────────────────────
    var importBtn  = document.querySelector('.ddwpt-import-btn');
    var importFile = document.getElementById('ddwpt-import-file');
    if (importBtn && importFile) {
        importBtn.addEventListener('click', function () {
            importFile.value = '';
            importFile.click();
        });

        importFile.addEventListener('change', function () {
            var file = this.files[0];
            if (!file) return;

            var reader = new FileReader();
            reader.onload = function (e) {
                var data;
                try {
                    data = JSON.parse(e.target.result);
                } catch (err) {
                    alert('Invalid JSON file.');
                    return;
                }

                importBtn.textContent = 'Importing…';
                importBtn.disabled = true;

                fetch(ddwptSettings.ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'ddwpt_import_settings',
                        nonce: ddwptSettings.importNonce,
                        settings: JSON.stringify(data)
                    })
                })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res.success) throw new Error('Import failed');
                    location.reload();
                })
                .catch(function () {
                    alert('Import failed. Please check the file and try again.');
                    importBtn.textContent = 'Import';
                    importBtn.disabled = false;
                });
            };
            reader.readAsText(file);
        });
    }

    // ── ACF preset export ─────────────────────────────────────────────
    document.querySelectorAll('.ddwpt-acf-export-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var wrap   = btn.closest('.ddwpt-acf-export-wrap');
            var result = wrap ? wrap.querySelector('.ddwpt-acf-export-result') : null;

            btn.textContent = 'Exporting…';
            btn.disabled    = true;
            if (result) result.textContent = '';

            fetch(ddwptSettings.ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'ddwpt_acf_export',
                    nonce:  ddwptSettings.acfExportNonce
                })
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) throw new Error(res.data || 'Export failed.');
                var blob = new Blob([JSON.stringify(res.data, null, 2)], { type: 'application/json' });
                var url  = URL.createObjectURL(blob);
                var a    = document.createElement('a');
                a.href     = url;
                a.download = 'acf-presets.json';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
                if (result) { result.textContent = 'Downloaded.'; result.style.color = 'green'; }
            })
            .catch(function (err) {
                if (result) { result.textContent = err.message || 'Export failed.'; result.style.color = 'red'; }
            })
            .finally(function () {
                btn.textContent = 'Export ACF Presets';
                btn.disabled    = false;
            });
        });
    });

    // ── JSON importers ────────────────────────────────────────────────
    var jsonEditorSettings = (typeof ddwptJsonEditorSettings !== 'undefined') ? ddwptJsonEditorSettings : null;

    function jsonEditorGetValue(wrap) {
        if (wrap._jsonEditor && wrap._jsonEditor.codemirror) {
            return wrap._jsonEditor.codemirror.getValue();
        }
        var ta = wrap.querySelector('.ddwpt-json-import-editor');
        return ta ? ta.value : '';
    }

    function jsonEditorSetValue(wrap, value) {
        if (wrap._jsonEditor && wrap._jsonEditor.codemirror) {
            wrap._jsonEditor.codemirror.setValue(value);
        } else {
            var ta = wrap.querySelector('.ddwpt-json-import-editor');
            if (ta) ta.value = value;
        }
    }

    document.querySelectorAll('.ddwpt-json-import').forEach(function (wrap) {
        var textarea = wrap.querySelector('.ddwpt-json-import-editor');
        if (!textarea) return;

        function initOrRefreshEditor() {
            if (!wrap._jsonEditor && jsonEditorSettings && typeof wp !== 'undefined' && wp.codeEditor) {
                wrap._jsonEditor = wp.codeEditor.initialize(textarea, jsonEditorSettings);
            } else if (wrap._jsonEditor && wrap._jsonEditor.codemirror) {
                wrap._jsonEditor.codemirror.refresh();
            }
        }

        var details = wrap.closest('details.ddwpt-field-accordion');
        if (details) {
            details.addEventListener('toggle', function () {
                if (details.open) initOrRefreshEditor();
            });
        } else {
            initOrRefreshEditor();
        }
    });

    document.querySelectorAll('.ddwpt-json-copy-schema').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var example = btn.closest('.ddwpt-json-import').querySelector('.ddwpt-json-schema-example');
            if (!example) return;
            navigator.clipboard.writeText(example.textContent).then(function () {
                var orig = btn.textContent;
                btn.textContent = 'Copied!';
                setTimeout(function () { btn.textContent = orig; }, 2000);
            });
        });
    });

    document.querySelectorAll('.ddwpt-json-import-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var wrap     = btn.closest('.ddwpt-json-import');
            var result   = wrap.querySelector('.ddwpt-json-import-result');
            var action   = wrap.dataset.action;
            var nonceKey = wrap.dataset.nonceKey;

            var items;
            try {
                items = JSON.parse(jsonEditorGetValue(wrap));
            } catch (e) {
                result.textContent = 'Invalid JSON.';
                result.style.color = 'red';
                return;
            }

            if (!Array.isArray(items) || items.length === 0) {
                result.textContent = 'Expected a non-empty JSON array.';
                result.style.color = 'red';
                return;
            }

            btn.textContent    = 'Importing…';
            btn.disabled       = true;
            result.textContent = '';

            fetch(ddwptSettings.ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: action,
                    nonce:  ddwptSettings[nonceKey],
                    items:  JSON.stringify(items)
                })
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) throw new Error(res.data || 'Import failed.');
                result.textContent = res.data.message;
                result.style.color = 'green';
                jsonEditorSetValue(wrap, '');
            })
            .catch(function (err) {
                result.textContent = err.message || 'Import failed.';
                result.style.color = 'red';
            })
            .finally(function () {
                btn.textContent = 'Run Import';
                btn.disabled    = false;
            });
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

    // ── Reset-to-default buttons ──────────────────────────────────
    document.querySelectorAll('.ddwpt-reset-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = document.getElementById(btn.dataset.input);
            if (!input) return;
            if (!confirm('Reset this field to the plugin\'s default value? Your current text will be replaced (not saved until you click Save Changes).')) return;

            input.value = btn.dataset.default;
            btn.textContent = 'Reset!';
            btn.classList.add('is-reset');
            setTimeout(function () {
                btn.textContent = 'Reset to Default';
                btn.classList.remove('is-reset');
            }, 2000);
        });
    });

    // ── Copy-to-clipboard buttons ─────────────────────────────────
    document.querySelectorAll('.ddwpt-copy-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var text = this.dataset.copy;
            if (!text) return;
            navigator.clipboard.writeText(text).then(function () {
                btn.textContent = 'Copied!';
                btn.classList.add('is-copied');
                setTimeout(function () {
                    btn.textContent = 'Copy';
                    btn.classList.remove('is-copied');
                }, 2000);
            });
        });
    });
})();
