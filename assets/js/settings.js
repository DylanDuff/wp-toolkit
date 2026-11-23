(function () {
    'use strict';

    var wrap = document.querySelector('.ddwpt-wrap');
    if (!wrap) return;

    // ── Navigation ────────────────────────────────────────────────────
    // Routes live in the hash: #area, #area/pane (a section or nested page)
    // or #ddwpt-card-<tweak> to jump straight to a card. Everything is one
    // form, so switching is client-side and Save submits every field.
    var areas       = wrap.querySelectorAll('.ddwpt-area');
    var tabs        = wrap.querySelectorAll('.ddwpt-tab');
    var labsInput   = document.getElementById('ddwpt_experiments_enabled');
    var initialized = {};
    var current     = { area: 'overview', pane: '' };

    // Hashes from releases before the areas layout.
    var legacyAreas = { general: 'site', acf: 'content', 'wp-admin': 'admin', bricks: 'builder', animations: 'builder', experimental: 'overview' };

    function areaEl(id) {
        return wrap.querySelector('.ddwpt-area[data-area="' + id + '"]');
    }

    function initEditors(area) {
        if (initialized[area.dataset.area]) return;
        initialized[area.dataset.area] = true;

        area.querySelectorAll('.wp-editor-wrap').forEach(function (editor) {
            var id = editor.id.replace(/^wp-/, '').replace(/-wrap$/, '');
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

    // A card is shown unless it is a Labs tweak with Experiments off, or
    // needs a missing plugin and its area has not asked to show those.
    // Hidden cards keep their inputs, so saving never drops their values.
    function isLabsOn() {
        return labsInput ? labsInput.checked : wrap.classList.contains('is-labs-on');
    }

    function cardVisible(card) {
        if (card.classList.contains('is-labs') && !isLabsOn()) return false;
        if (card.classList.contains('is-unavailable')) {
            var area = card.closest('.ddwpt-area');
            return !!(area && area.classList.contains('is-showing-unavailable'));
        }
        return true;
    }

    function tweakCards(scope) {
        return Array.prototype.slice.call(scope.querySelectorAll('[data-tweak]'));
    }

    function refreshVisibility() {
        wrap.classList.toggle('is-labs-on', isLabsOn());

        areas.forEach(function (area) {
            if (area.dataset.area === 'overview') return;
            var cards = tweakCards(area);
            cards.forEach(function (card) { card.hidden = !cardVisible(card); });

            // Section buttons for sections with nothing visible are hidden,
            // as are pages whose tweak is hidden. With no section left, the
            // switcher goes too.
            var switcher = area.querySelector('.ddwpt-segmented');
            if (switcher) {
                var sectionsShown = 0;
                switcher.querySelectorAll('[data-pane]').forEach(function (button) {
                    var pane = area.querySelector('.ddwpt-pane[data-pane="' + button.dataset.pane + '"]');
                    if (pane && pane.hasAttribute('data-page')) {
                        var owner = button.dataset.pageOwner ? document.getElementById(button.dataset.pageOwner) : null;
                        button.hidden = !!(owner && owner.hidden);
                    } else {
                        button.hidden = !pane || !pane.querySelector('[data-tweak]:not([hidden])');
                        if (!button.hidden) sectionsShown++;
                    }
                });
                switcher.hidden = sectionsShown === 0;
                var divider = switcher.querySelector('.ddwpt-segmented-divider');
                if (divider) divider.hidden = !switcher.querySelector('.is-page:not([hidden])');
            }

            var missing = cards.filter(function (card) {
                return card.classList.contains('is-unavailable') && !(card.classList.contains('is-labs') && !isLabsOn());
            });
            var note = area.querySelector('[data-unavailable-note]');
            if (note) {
                var plugins = {};
                missing.forEach(function (card) {
                    card.dataset.missing.split(', ').forEach(function (name) { plugins[name] = true; });
                });
                var names = Object.keys(plugins).join(', ');
                note.hidden = !missing.length || area.classList.contains('is-showing-unavailable');
                note.querySelector('span').textContent = missing.length + ' tweak' + (missing.length === 1 ? '' : 's') +
                    ' hidden because ' + names + (Object.keys(plugins).length === 1 ? ' is' : ' are') + ' not active.';
            }
            var empty = area.querySelector('[data-area-empty]');
            if (empty) empty.hidden = cards.length === 0 || cards.some(function (card) { return !card.hidden; });
        });

        refreshCounts();
    }

    // "X of Y on" for each area, from header switches of visible tweaks.
    function refreshCounts() {
        var totalOn = 0;
        var total = 0;

        areas.forEach(function (area) {
            var id = area.dataset.area;
            if (id === 'overview') return;
            var switches = tweakCards(area)
                .filter(function (card) { return !(card.classList.contains('is-labs') && !isLabsOn()); })
                .map(function (card) { return card.querySelector('.ddwpt-card-header .ddwpt-toggle input'); })
                .filter(Boolean);
            var on = switches.filter(function (input) { return input.checked; }).length;
            totalOn += on;
            total += switches.length;

            wrap.querySelectorAll('[data-area-count-text="' + id + '"]').forEach(function (el) { el.textContent = on + ' of ' + switches.length + ' on'; });
            wrap.querySelectorAll('[data-area-meter="' + id + '"]').forEach(function (el) {
                el.style.width = (switches.length ? Math.round(on / switches.length * 100) : 0) + '%';
            });
        });

        var activeCount = wrap.querySelector('[data-active-count]');
        if (activeCount) activeCount.textContent = totalOn + ' / ' + total + ' tweaks enabled';
    }

    function firstVisiblePane(area) {
        var buttons = area.querySelectorAll('.ddwpt-segmented [data-pane]');
        for (var i = 0; i < buttons.length; i++) {
            if (!buttons[i].hidden) return buttons[i].dataset.pane;
        }
        var pane = area.querySelector('.ddwpt-pane:not([data-page])');
        return pane ? pane.dataset.pane : '';
    }

    function show(areaId, paneId) {
        var area = areaEl(areaId) || areaEl('overview');
        areaId = area.dataset.area;

        var panes = area.querySelectorAll('.ddwpt-pane');
        if (panes.length) {
            if (!paneId || !area.querySelector('.ddwpt-pane[data-pane="' + paneId + '"]')) paneId = firstVisiblePane(area);
            var active = null;
            panes.forEach(function (pane) {
                var isActive = pane.dataset.pane === paneId;
                pane.hidden = !isActive;
                if (isActive) active = pane;
            });
            area.querySelectorAll('.ddwpt-segmented [data-pane]').forEach(function (button) {
                button.classList.toggle('is-active', button.dataset.pane === paneId);
                button.setAttribute('aria-selected', button.dataset.pane === paneId ? 'true' : 'false');
            });
            // Nested pages extend the breadcrumb: Overview / Area / Page.
            var crumb = area.querySelector('[data-crumb-page]');
            if (crumb) crumb.textContent = active && active.hasAttribute('data-page') ? '/ ' + active.dataset.paneLabel : '';
        }

        areas.forEach(function (el) { el.hidden = el !== area; });
        tabs.forEach(function (tab) { tab.classList.toggle('is-active', tab.dataset.area === areaId); });
        initEditors(area);

        current = { area: areaId, pane: paneId || '' };
    }

    function route(hash, scroll) {
        hash = (hash || '').replace(/^#/, '');

        if (hash.indexOf('ddwpt-card-') === 0) {
            goToCard(hash);
            return;
        }

        var parts = hash.split('/');
        var areaId = legacyAreas[parts[0]] || parts[0] || 'overview';
        show(areaId, parts[1] || '');
        if (scroll !== false) window.scrollTo(0, 0);
    }

    function goToCard(cardId) {
        var card = document.getElementById(cardId);
        if (!card) return;
        var area = card.closest('.ddwpt-area');
        var pane = card.closest('.ddwpt-pane');
        if (card.classList.contains('is-unavailable')) area.classList.add('is-showing-unavailable');
        if (card.classList.contains('is-labs') && !isLabsOn()) return;
        refreshVisibility();
        show(area.dataset.area, pane ? pane.dataset.pane : '');
        history.replaceState(null, '', '#' + area.dataset.area + (pane ? '/' + pane.dataset.pane : ''));
        card.scrollIntoView({ block: 'center' });
        card.classList.remove('is-flashing');
        void card.offsetWidth;
        card.classList.add('is-flashing');
    }

    window.addEventListener('hashchange', function () { route(location.hash); });

    wrap.addEventListener('click', function (e) {
        var goto = e.target.closest('[data-goto-card]');
        if (goto) {
            e.preventDefault();
            goToCard(goto.dataset.gotoCard);
            return;
        }
        var showMissing = e.target.closest('[data-show-unavailable]');
        if (showMissing) {
            showMissing.closest('.ddwpt-area').classList.add('is-showing-unavailable');
            refreshVisibility();
        }
    });

    // Keep the current view through the save round trip.
    var form = wrap.closest('form');
    if (form) {
        form.addEventListener('submit', function () {
            var referer = form.querySelector('input[name="_wp_http_referer"]');
            if (referer) {
                referer.value = referer.value.replace(/#.*$/, '') + '#' + current.area + (current.pane ? '/' + current.pane : '');
            }
            sessionStorage.setItem('ddwpt_saved', '1');
        });
    }

    // ── Card switches ─────────────────────────────────────────────────
    // Header switches fade their card; ability opt-in switches fade their row.
    wrap.addEventListener('change', function (e) {
        var input = e.target;
        if (!input.closest || !input.closest('.ddwpt-toggle')) return;

        if (input.closest('.ddwpt-card-header')) {
            input.closest('.ddwpt-card').classList.toggle('is-disabled', !input.checked);
        } else if (input.closest('.ddwpt-ability-row')) {
            input.closest('.ddwpt-ability-row').classList.toggle('is-off', !input.checked);
        }
        if (input === labsInput) {
            refreshVisibility();
        } else {
            refreshCounts();
        }
    });

    // ── Search palette ────────────────────────────────────────────────
    // Indexes every tweak card and nested page already on the page.
    var palette   = wrap.querySelector('[data-palette]');
    var input     = palette ? palette.querySelector('.ddwpt-palette-input') : null;
    var list      = palette ? palette.querySelector('.ddwpt-palette-results') : null;
    var results   = [];
    var selected  = 0;

    function areaLabel(area) {
        var tab = wrap.querySelector('.ddwpt-tab[data-area="' + area.dataset.area + '"]');
        return tab ? tab.textContent.trim() : '';
    }

    function paletteIndex() {
        var entries = [];
        wrap.querySelectorAll('.ddwpt-pane[data-page]').forEach(function (pane) {
            var area = pane.closest('.ddwpt-area');
            entries.push({
                label: pane.dataset.paneLabel,
                where: areaLabel(area),
                text: (pane.dataset.paneLabel + ' ' + areaLabel(area)).toLowerCase(),
                page: true,
                open: function () { location.hash = area.dataset.area + '/' + pane.dataset.pane; }
            });
        });
        wrap.querySelectorAll('.ddwpt-area [data-tweak]').forEach(function (card) {
            if (card.classList.contains('is-labs') && !isLabsOn()) return;
            var area = card.closest('.ddwpt-area');
            var pane = card.closest('.ddwpt-pane');
            var section = pane && !pane.hasAttribute('data-page') && area.querySelector('.ddwpt-segmented') ? ' › ' + pane.dataset.paneLabel : '';
            entries.push({
                label: card.dataset.label,
                where: areaLabel(area) + section,
                text: (card.dataset.search + ' ' + (pane ? pane.dataset.paneLabel : '')).toLowerCase(),
                open: function () { goToCard(card.id); }
            });
        });
        return entries;
    }

    function renderResults() {
        var query = input.value.trim().toLowerCase();
        var terms = query.split(/\s+/).filter(Boolean);
        results = paletteIndex().filter(function (entry) {
            return terms.every(function (term) { return entry.text.indexOf(term) !== -1; });
        }).slice(0, 12);
        selected = Math.min(selected, Math.max(results.length - 1, 0));

        list.innerHTML = '';
        if (!results.length) {
            var empty = document.createElement('li');
            empty.className = 'is-empty';
            empty.textContent = 'No matches';
            list.appendChild(empty);
            return;
        }
        results.forEach(function (entry, index) {
            var item = document.createElement('li');
            item.setAttribute('role', 'option');
            item.className = index === selected ? 'is-current' : '';
            var label = document.createElement('span');
            label.textContent = entry.label;
            item.appendChild(label);
            if (entry.page) {
                var badge = document.createElement('span');
                badge.className = 'ddwpt-badge';
                badge.textContent = 'Page';
                item.appendChild(badge);
            }
            var where = document.createElement('span');
            where.className = 'ddwpt-palette-where';
            where.textContent = entry.where;
            item.appendChild(where);
            item.addEventListener('click', function () { choose(index); });
            list.appendChild(item);
        });
    }

    function openPalette() {
        if (!palette) return;
        palette.hidden = false;
        input.value = '';
        selected = 0;
        renderResults();
        input.focus();
    }

    function closePalette() {
        if (palette) palette.hidden = true;
    }

    function choose(index) {
        var entry = results[index];
        if (!entry) return;
        closePalette();
        entry.open();
    }

    if (palette) {
        wrap.querySelectorAll('[data-palette-open]').forEach(function (button) {
            button.addEventListener('click', openPalette);
        });
        palette.addEventListener('click', function (e) {
            if (e.target === palette) closePalette();
        });
        input.addEventListener('input', function () {
            selected = 0;
            renderResults();
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                selected = Math.max(0, Math.min(results.length - 1, selected + (e.key === 'ArrowDown' ? 1 : -1)));
                renderResults();
                var currentItem = list.querySelector('.is-current');
                if (currentItem) currentItem.scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'Enter') {
                e.preventDefault();
                choose(selected);
            }
        });
        document.addEventListener('keydown', function (e) {
            var typing = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName) || document.activeElement.isContentEditable;
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                openPalette();
            } else if (e.key === '/' && !typing && palette.hidden) {
                e.preventDefault();
                openPalette();
            } else if (e.key === 'Escape' && !palette.hidden) {
                closePalette();
            }
        });
    }

    // ── Registered abilities filter ───────────────────────────────────
    wrap.querySelectorAll('.ddwpt-registry').forEach(function (registry) {
        var search = registry.querySelector('.ddwpt-registry-search');
        var namespace = '';

        function filter() {
            var query = search ? search.value.trim().toLowerCase() : '';
            registry.querySelectorAll('tbody tr').forEach(function (row) {
                row.hidden = (namespace && row.dataset.namespace !== namespace) ||
                    (query && row.textContent.toLowerCase().indexOf(query) === -1);
            });
        }

        registry.querySelectorAll('[data-registry-namespace]').forEach(function (chip) {
            chip.addEventListener('click', function () {
                namespace = chip.dataset.registryNamespace;
                registry.querySelectorAll('[data-registry-namespace]').forEach(function (other) {
                    other.classList.toggle('is-active', other === chip);
                });
                filter();
            });
        });
        if (search) {
            search.addEventListener('input', filter);
            // Enter in the filter must not submit the settings form.
            search.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
        }
    });

    refreshVisibility();
    route(location.hash, false);

    // ── Conditional fields (show_if) ──────────────────────────────────
    // Hidden fields keep their saved values; only their rows are hidden.
    document.querySelectorAll('[data-show-if]').forEach(function (row) {
        var conditions = JSON.parse(row.dataset.showIf);
        var form = row.closest('form') || document;

        function fieldValue(name) {
            var inputs = form.querySelectorAll('[name="' + name + '"]');
            var value = '';
            inputs.forEach(function (input) {
                if ((input.type === 'checkbox' || input.type === 'radio') && !input.checked) return;
                value = input.value;
            });
            return value;
        }

        function sync() {
            row.hidden = !Object.keys(conditions).every(function (name) {
                return conditions[name].map(String).indexOf(fieldValue(name)) !== -1;
            });
        }

        Object.keys(conditions).forEach(function (name) {
            form.querySelectorAll('[name="' + name + '"]').forEach(function (input) {
                input.addEventListener('change', sync);
            });
        });
        sync();
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
                    btn.textContent = btn.dataset.copyLabel || 'Copy';
                    btn.classList.remove('is-copied');
                }, 2000);
            });
        });
    });
})();
