/**
 * Structured Data editor: one editor per post type with Visual and JSON
 * modes over the same template, plus presets and a live preview.
 *
 * The saved value is a JSON object in a hidden input:
 *   { "<post_type>": { "enabled": true, "template": [ <node>, ... ] } }
 * See docs/seo-schema.md for the template format.
 */
(function () {
    'use strict';

    if (typeof ddwptSchema === 'undefined') return;

    const config     = ddwptSchema;
    const DIRECTIVES = {
        '@each':   'Repeat for each',
        '@if':     'Only if',
        '@unless': 'Unless',
        '@from':   'Use related post',
        '@value':  'Item value',
        '@merge':  'Merge object',
        '@else':   'Fallback when empty',
        '@maps':   'Lookup tables',
    };
    const SOURCE_DIRECTIVES = ['@each', '@if', '@unless', '@from'];
    const SINGLE_TOKEN      = /^\{\{([^{}]*)\}\}$/;

    /* ---------- DOM helpers ---------- */

    function h(tag, attributes, ...children) {
        const element = document.createElement(tag);
        Object.entries(attributes || {}).forEach(([key, value]) => {
            if (value === null || value === undefined || value === false) return;
            if (key.startsWith('on')) element.addEventListener(key.slice(2).toLowerCase(), value);
            else if (key === 'className') element.className = value;
            else if (key === 'value') element.value = value;
            else element.setAttribute(key, value === true ? '' : value);
        });
        children.flat().forEach((child) => {
            if (child === null || child === undefined || child === false) return;
            element.append(child instanceof Node ? child : document.createTextNode(String(child)));
        });
        return element;
    }

    function button(label, onClick, className) {
        return h('button', { type: 'button', className: 'ddwpt-btn ' + (className || ''), onClick }, label);
    }

    function select(options, value, onChange, attributes) {
        const element = h('select', Object.assign({ onChange: (event) => onChange(event.target.value) }, attributes));
        options.forEach((option) => {
            if (option.group) {
                const group = h('optgroup', { label: option.group });
                option.options.forEach((child) => group.append(h('option', { value: child.value }, child.label)));
                element.append(group);
            } else {
                element.append(h('option', { value: option.value }, option.label));
            }
        });
        element.value = value;
        return element;
    }

    function clone(value) {
        return JSON.parse(JSON.stringify(value));
    }

    /** Replace a key while keeping property order. */
    function renameKey(object, from, to) {
        const entries = Object.entries(object);
        Object.keys(object).forEach((key) => delete object[key]);
        entries.forEach(([key, value]) => { object[key === from ? to : key] = value; });
    }

    /* ---------- Tokens ---------- */

    /** "{{acf:x|minutes|duration}}" → { source: "acf:x", transforms: [{name, arg}] } */
    function parseToken(text) {
        const match = SINGLE_TOKEN.exec(text);
        if (!match) return null;
        const [source, ...parts] = match[1].split('|');
        return {
            source: source.trim(),
            transforms: parts.filter((part) => part.trim() !== '').map((part) => {
                const colon = part.indexOf(':');
                return colon === -1
                    ? { name: part.trim(), arg: '' }
                    : { name: part.slice(0, colon).trim(), arg: part.slice(colon + 1) };
            }),
        };
    }

    function formatToken(token) {
        const transforms = token.transforms.map((transform) => transform.name + (transform.arg !== '' ? ':' + transform.arg : ''));
        return '{{' + [token.source].concat(transforms).join('|') + '}}';
    }

    /** "@each" may carry transforms ("acf:x|term:tax") or be {rows, if, else}. */
    function eachSource(each) {
        const rows = each && typeof each === 'object' ? each.rows : each;
        return String(rows || '').split('|')[0].trim();
    }

    /* ---------- Sources ---------- */

    class SourceCatalogue {
        constructor(postType) {
            this.groups = (config.postTypes[postType] || {}).sources || [];
        }

        find(value) {
            for (const group of this.groups) {
                const found = group.sources.find((source) => source.value === value);
                if (found) return found;
            }
            return null;
        }

        /** Row fields offered as item.* inside a loop over `source`. */
        itemSources(source) {
            const found  = this.find(source);
            const fields = found && found.children ? found.children : [
                { value: 'item', label: 'Whole row' },
                { value: 'item.@index', label: 'Row number' },
                { value: 'item.name', label: 'Name' },
            ];
            return fields;
        }

        options(items, onlyLists) {
            const options = [{ value: '', label: '— Choose a source —' }];
            if (items && items.length) {
                options.push({ group: 'Loop item', options: items.map((item) => ({ value: item.value, label: item.label + ' (' + item.value + ')' })) });
            }
            this.groups.forEach((group) => {
                const sources = group.sources.filter((source) => !onlyLists || source.list);
                if (!sources.length) return;
                options.push({
                    group: group.label,
                    options: sources.map((source) => ({ value: source.value, label: source.label + (source.list ? ' [list]' : '') })),
                });
            });
            options.push({ value: '__custom', label: 'Custom source…' });
            return options;
        }
    }

    /**
     * Source picker: a grouped select, falling back to a text input for
     * sources the catalogue doesn't list (meta:, other post types, etc.).
     */
    function SourcePicker(value, context, onChange, onlyLists) {
        const options = context.sources.options(context.items, onlyLists);
        const known   = value === '' || options.some((option) =>
            option.value === value || (option.options || []).some((child) => child.value === value));

        const wrap  = h('span', { className: 'ddwpt-schema-source' });
        const input = h('input', {
            type: 'text', className: 'ddwpt-schema-source-custom', value, placeholder: 'e.g. meta:_price',
            onInput: (event) => onChange(event.target.value, false),
        });
        const picker = select(options, known ? value : '__custom', (picked) => {
            if (picked === '__custom') {
                input.hidden = false;
                input.focus();
                return;
            }
            input.hidden = true;
            input.value  = picked;
            onChange(picked, true);
        });

        input.hidden = known;
        wrap.append(picker, input);
        return wrap;
    }

    /* ---------- Transforms ---------- */

    function TransformChips(transforms, onChange) {
        const wrap = h('span', { className: 'ddwpt-schema-transforms' });

        transforms.forEach((transform, index) => {
            const hint = (config.transforms[transform.name] || {}).arg;
            wrap.append(h('span', { className: 'ddwpt-schema-chip', title: (config.transforms[transform.name] || {}).label || '' },
                h('span', { className: 'ddwpt-schema-chip-name' }, transform.name),
                hint || transform.arg !== '' ? h('input', {
                    type: 'text', value: transform.arg, placeholder: hint || '', size: Math.max(4, (transform.arg || hint || '').length),
                    onInput: (event) => { transform.arg = event.target.value; onChange(transforms, false); },
                }) : null,
                h('button', { type: 'button', className: 'ddwpt-schema-chip-remove', 'aria-label': 'Remove ' + transform.name,
                    onClick: () => { transforms.splice(index, 1); onChange(transforms, true); } }, '×'),
            ));
        });

        const options = [{ value: '', label: '+ transform' }].concat(
            Object.entries(config.transforms).map(([name, info]) => ({ value: name, label: name + ' – ' + info.label })));
        wrap.append(select(options, '', (name) => {
            if (!name) return;
            transforms.push({ name, arg: '' });
            onChange(transforms, true);
        }, { className: 'ddwpt-schema-add-transform' }));

        return wrap;
    }

    /* ---------- Values ---------- */

    function valueMode(value) {
        if (typeof value === 'string') return SINGLE_TOKEN.test(value) ? 'field' : 'text';
        if (value && typeof value === 'object' && !Array.isArray(value)) return 'object';
        return 'json';
    }

    function convertValue(value, mode) {
        const current = valueMode(value);
        if (current === mode) return value;
        if (mode === 'field') return typeof value === 'string' && value.includes('{{') ? value : '{{}}';
        if (mode === 'text') return typeof value === 'string' ? value : '';
        if (mode === 'object') return { '@type': '' };
        return value === undefined ? null : value;
    }

    /**
     * Edits any template value. `set(value, structural)` writes it back;
     * structural changes re-render the panel, keystrokes don't.
     */
    function ValueEditor(value, context, set) {
        const mode = valueMode(value);
        const wrap = h('div', { className: 'ddwpt-schema-value is-' + mode });

        const modes = select([
            { value: 'field', label: 'Field' },
            { value: 'text', label: 'Text' },
            { value: 'object', label: 'Object' },
            { value: 'json', label: 'JSON' },
        ], mode, (next) => set(convertValue(value, next), true), { className: 'ddwpt-schema-mode', 'aria-label': 'Value type' });
        wrap.append(modes);

        if (mode === 'field') {
            const token = parseToken(value);
            const write = (structural) => set(formatToken(token), structural);
            wrap.append(
                SourcePicker(token.source, context, (source, structural) => { token.source = source; write(structural); }),
                TransformChips(token.transforms, (transforms, structural) => { token.transforms = transforms; write(structural); }),
            );
        } else if (mode === 'text') {
            const input = h('input', { type: 'text', className: 'ddwpt-schema-text', value,
                onInput: (event) => set(event.target.value, false) });
            const insert = select(context.sources.options(context.items).filter((option) => option.value !== '__custom')
                .map((option) => option.value === '' ? { value: '', label: '+ field' } : option), '', (source) => {
                if (!source) return;
                set(input.value + '{{' + source + '}}', true);
            }, { className: 'ddwpt-schema-insert', 'aria-label': 'Insert field' });
            wrap.append(input, insert);
        } else if (mode === 'object') {
            wrap.append(NodeEditor(value, context, () => set(value, true), (keystroke) => set(value, keystroke)));
        } else {
            wrap.append(JsonInput(value, (parsed) => set(parsed, false)));
        }

        return wrap;
    }

    function JsonInput(value, onValid) {
        const error    = h('span', { className: 'ddwpt-schema-error' });
        const textarea = h('textarea', { className: 'ddwpt-schema-json-input', rows: 3,
            value: JSON.stringify(value, null, 2),
            onInput: (event) => {
                try {
                    onValid(JSON.parse(event.target.value));
                    error.textContent = '';
                } catch (exception) {
                    error.textContent = 'Invalid JSON';
                }
            },
        });
        return h('span', { className: 'ddwpt-schema-json-wrap' }, textarea, error);
    }

    /* ---------- Nodes ---------- */

    function typeProperties(type) {
        const definition = config.types[type];
        if (!definition) return { required: [], recommended: [], other: [] };
        return definition;
    }

    /**
     * Edits an object node in place: @type, directives, then property rows.
     * `rerender()` redraws after structural edits; `changed()` saves keystrokes.
     */
    function NodeEditor(node, parentContext, rerender, changed) {
        const save = (structural) => (structural ? rerender() : changed(false));
        const each = node['@each'];
        const context = Object.assign({}, parentContext, each !== undefined
            ? { items: parentContext.sources.itemSources(eachSource(each)) }
            : {});

        const wrap = h('div', { className: 'ddwpt-schema-node' });

        // Type
        const typeInput = h('input', {
            type: 'text', className: 'ddwpt-schema-type', value: node['@type'] || '', list: 'ddwpt-schema-types', placeholder: 'schema.org type',
            onChange: (event) => { node['@type'] = event.target.value; save(true); },
        });
        // A node that yields a plain value per row (@value) has no type.
        if (node['@value'] === undefined || node['@type']) {
            wrap.append(h('div', { className: 'ddwpt-schema-row is-type' },
                h('label', { className: 'ddwpt-schema-key' }, '@type'), typeInput));
        }

        // Directives
        Object.keys(DIRECTIVES).forEach((directive) => {
            if (node[directive] === undefined) return;
            wrap.append(DirectiveRow(node, directive, directive === '@each' ? parentContext : context, save));
        });

        // Properties
        Object.keys(node).forEach((key) => {
            if (key === '@type' || DIRECTIVES[key]) return;
            wrap.append(PropertyRow(node, key, context, save));
        });

        wrap.append(AddRow(node, save));
        return wrap;
    }

    function DirectiveRow(node, directive, context, save) {
        const remove = button('×', () => { delete node[directive]; save(true); }, 'ddwpt-schema-remove');
        const label  = h('label', { className: 'ddwpt-schema-key is-directive', title: directive }, DIRECTIVES[directive]);
        let control;

        if (directive === '@maps') {
            control = MapsEditor(node['@maps'], save);
        } else if (SOURCE_DIRECTIVES.includes(directive)) {
            const value = node[directive];
            if (directive === '@each' && value && typeof value === 'object') {
                // {rows, if, else}: rows and the condition are editable here;
                // the fallback row stays JSON.
                control = h('span', { className: 'ddwpt-schema-each' },
                    SourcePicker(value.rows || '', context, (source, structural) => { value.rows = source; save(structural); }, true),
                    h('span', { className: 'ddwpt-schema-muted' }, ' only if '),
                    SourcePicker(value.if || '', context, (source, structural) => { value.if = source; save(structural); }),
                    value.else ? h('span', { className: 'ddwpt-schema-muted', title: JSON.stringify(value.else) }, ' + fallback row (JSON mode)') : null,
                );
            } else {
                control = SourcePicker(value || '', context, (source, structural) => { node[directive] = source; save(structural); }, directive === '@each');
            }
        } else {
            control = ValueEditor(node[directive], context, (value, structural) => { node[directive] = value; save(structural); });
        }

        return h('div', { className: 'ddwpt-schema-row is-directive' }, label, control, remove);
    }

    function PropertyRow(node, key, context, save) {
        const required = typeProperties(node['@type']).required.includes(key);
        const name     = h('input', {
            type: 'text', className: 'ddwpt-schema-key', value: key, list: 'ddwpt-schema-props-' + (node['@type'] || 'any'),
            onChange: (event) => {
                const next = event.target.value.trim();
                if (!next || next === key || node[next] !== undefined) { event.target.value = key; return; }
                renameKey(node, key, next);
                save(true);
            },
        });

        return h('div', { className: 'ddwpt-schema-row' + (required ? ' is-required' : '') },
            name,
            ValueEditor(node[key], context, (value, structural) => { node[key] = value; save(structural); }),
            button('×', () => { delete node[key]; save(true); }, 'ddwpt-schema-remove'),
        );
    }

    function AddRow(node, save) {
        const properties = typeProperties(node['@type']);
        const missing    = properties.required.filter((property) => node[property] === undefined);
        const options    = [{ value: '', label: '+ Add property' }];

        ['required', 'recommended', 'other'].forEach((group) => {
            const unused = properties[group].filter((property) => node[property] === undefined);
            if (unused.length) options.push({ group: group[0].toUpperCase() + group.slice(1), options: unused.map((property) => ({ value: property, label: property })) });
        });
        options.push({ group: 'Options', options: Object.entries(DIRECTIVES)
            .filter(([directive]) => node[directive] === undefined)
            .map(([directive, label]) => ({ value: directive, label })) });
        options.push({ value: '__custom', label: 'Other property…' });

        const add = (key) => {
            if (!key) return;
            if (key === '__custom') key = (window.prompt('Property name') || '').trim();
            if (!key || node[key] !== undefined) return;
            node[key] = key === '@maps' ? {} : (key === '@else' || key === '@merge' ? '{{}}' : (DIRECTIVES[key] && key !== '@value' ? '' : '{{}}'));
            save(true);
        };

        return h('div', { className: 'ddwpt-schema-add' },
            select(options, '', add, { 'aria-label': 'Add property' }),
            missing.length ? h('span', { className: 'ddwpt-schema-missing' }, 'Missing required: ',
                missing.map((property) => h('button', { type: 'button', className: 'ddwpt-schema-pill', onClick: () => add(property) }, property))) : null,
        );
    }

    /** @maps: { tableName: { from: to } } as editable key/value rows. */
    function MapsEditor(maps, save) {
        const wrap = h('div', { className: 'ddwpt-schema-maps' });

        Object.keys(maps).forEach((table) => {
            const rows = maps[table];
            const list = h('div', { className: 'ddwpt-schema-map' },
                h('div', { className: 'ddwpt-schema-map-head' },
                    h('code', {}, 'map:' + table),
                    button('Remove table', () => { delete maps[table]; save(true); }, 'is-link')));

            Object.keys(rows).forEach((from) => {
                list.append(h('div', { className: 'ddwpt-schema-map-row' },
                    h('input', { type: 'text', value: from, onChange: (event) => { renameKey(rows, from, event.target.value); save(true); } }),
                    h('span', {}, '→'),
                    h('input', { type: 'text', value: rows[from], onInput: (event) => { rows[from] = event.target.value; save(false); } }),
                    button('×', () => { delete rows[from]; save(true); }, 'ddwpt-schema-remove')));
            });

            list.append(button('+ Row', () => { rows[''] = ''; save(true); }, 'is-link'));
            wrap.append(list);
        });

        wrap.append(button('+ Lookup table', () => {
            const name = (window.prompt('Table name, e.g. diets') || '').trim();
            if (name && !maps[name]) { maps[name] = {}; save(true); }
        }, 'is-link'));

        return wrap;
    }

    /* ---------- Post type panel ---------- */

    class PostTypePanel {
        constructor(slug, info, entry, commit) {
            this.slug    = slug;
            this.info    = info;
            this.entry   = entry;
            this.commit  = commit;
            this.mode    = 'visual';
            this.sources = new SourceCatalogue(slug);
            this.element = h('details', { className: 'ddwpt-schema-panel' });
            this.render();
        }

        nodes() {
            const template = this.entry.template;
            if (!Array.isArray(template)) this.entry.template = template && typeof template === 'object' ? [template] : [];
            return this.entry.template;
        }

        summary() {
            const types = this.nodes().map((node) => node['@type']).filter(Boolean);
            return types.length ? types.join(', ') : 'No schema';
        }

        render() {
            const open = this.element.open;
            this.element.innerHTML = '';
            this.element.open = open;

            this.element.append(h('summary', {},
                h('strong', {}, this.info.label), ' ', h('code', {}, this.slug),
                h('span', { className: 'ddwpt-schema-summary' }, this.summary()),
                h('span', { className: 'ddwpt-schema-status' + (this.entry.enabled ? ' is-on' : '') }, this.entry.enabled ? 'On' : 'Off')));

            const body = h('div', { className: 'ddwpt-schema-body' });
            body.append(h('label', { className: 'ddwpt-schema-enable' },
                h('input', { type: 'checkbox', checked: this.entry.enabled, onChange: (event) => {
                    this.entry.enabled = event.target.checked;
                    this.changed(true);
                } }),
                ' Output this schema on single ' + this.info.label.toLowerCase()));
            body.append(this.toolbar());

            if (this.mode === 'json') {
                body.append(this.jsonEditor());
            } else {
                this.nodes().forEach((node, index) => body.append(this.nodeCard(node, index)));
                if (!this.nodes().length) {
                    body.append(h('p', { className: 'description' }, 'No schema yet. Start from a preset above, or add an empty node.'));
                }
                body.append(button('+ Empty node', () => { this.nodes().push({ '@type': '' }); this.changed(true); }, 'is-link'));
            }

            body.append(this.preview());
            this.element.append(body);
        }

        changed(structural) {
            this.commit();
            if (structural) this.render();
        }

        toolbar() {
            const presets = [{ value: '', label: '+ Add from preset…' }].concat(
                Object.entries(config.presets).map(([type, preset]) => ({ value: type, label: preset.label + ' (' + type + ')' })));

            const modeButton = (mode, label) => h('button', {
                type: 'button', className: 'ddwpt-schema-mode-btn' + (this.mode === mode ? ' is-active' : ''), 'aria-pressed': this.mode === mode,
                onClick: () => {
                    if (this.mode === mode) return;
                    if (this.jsonError) { window.alert('Fix the JSON first: ' + this.jsonError); return; }
                    this.mode = mode;
                    this.render();
                },
            }, label);

            return h('div', { className: 'ddwpt-schema-toolbar' },
                h('span', { className: 'ddwpt-schema-modes', role: 'group', 'aria-label': 'Editor mode' }, modeButton('visual', 'Visual'), modeButton('json', 'JSON')),
                select(presets, '', (type) => {
                    if (!type) return;
                    this.nodes().push(clone(config.presets[type].template));
                    this.changed(true);
                }, { 'aria-label': 'Add from preset' }));
        }

        nodeCard(node, index) {
            const nodes  = this.nodes();
            const context = { sources: this.sources, items: null };
            const move   = (offset) => {
                const target = index + offset;
                if (target < 0 || target >= nodes.length) return;
                [nodes[index], nodes[target]] = [nodes[target], nodes[index]];
                this.changed(true);
            };

            return h('div', { className: 'ddwpt-schema-card' },
                h('div', { className: 'ddwpt-schema-card-head' },
                    h('strong', {}, node['@type'] || 'Untitled node'),
                    h('span', { className: 'ddwpt-schema-card-actions' },
                        button('↑', () => move(-1), 'is-link'),
                        button('↓', () => move(1), 'is-link'),
                        button('Remove', () => {
                            if (!window.confirm('Remove this ' + (node['@type'] || '') + ' node?')) return;
                            nodes.splice(index, 1);
                            this.changed(true);
                        }, 'is-link is-danger'))),
                NodeEditor(node, context, () => this.changed(true), () => this.changed(false)));
        }

        jsonEditor() {
            const error    = h('p', { className: 'ddwpt-schema-error' });
            const textarea = h('textarea', { className: 'ddwpt-schema-json', rows: 20, value: JSON.stringify(this.nodes(), null, 2) });
            const apply    = (text) => {
                try {
                    const parsed = JSON.parse(text);
                    this.entry.template = Array.isArray(parsed) ? parsed : [parsed];
                    this.jsonError = null;
                    error.textContent = '';
                    this.commit();
                } catch (exception) {
                    this.jsonError = exception.message;
                    error.textContent = 'Invalid JSON: ' + exception.message;
                }
            };

            textarea.addEventListener('input', () => apply(textarea.value));
            const wrap = h('div', { className: 'ddwpt-schema-json-mode' }, textarea, error);

            // CodeMirror once attached; falls back to the plain textarea.
            if (config.codeEditor && window.wp && wp.codeEditor) {
                requestAnimationFrame(() => {
                    const instance = wp.codeEditor.initialize(textarea, config.codeEditor);
                    instance.codemirror.on('change', (cm) => apply(cm.getValue()));
                });
            }
            return wrap;
        }

        preview() {
            const posts   = h('select', { 'aria-label': 'Preview post' }, h('option', { value: '' }, 'Loading posts…'));
            const output  = h('div', { className: 'ddwpt-schema-preview-output' });
            const run     = button('Preview', () => {
                if (!posts.value) return;
                output.textContent = 'Rendering…';
                request('ddwpt_schema_preview', { post_id: posts.value, template: JSON.stringify(this.nodes()) }).then((result) => {
                    output.innerHTML = '';
                    if (!result.success) { output.textContent = result.data; return; }
                    const { json, warnings, testUrl } = result.data;
                    if (warnings.length) {
                        output.append(h('ul', { className: 'ddwpt-schema-warnings' }, warnings.map((warning) => h('li', {}, warning))));
                    }
                    output.append(
                        h('pre', { className: 'ddwpt-schema-preview-json' }, json),
                        h('a', { href: testUrl, target: '_blank', rel: 'noopener' }, 'Test the live page in Google\'s Rich Results Test ↗'));
                });
            });

            const details = h('details', { className: 'ddwpt-schema-preview' },
                h('summary', {}, 'Preview'),
                h('div', { className: 'ddwpt-schema-preview-controls' }, posts, run,
                    h('span', { className: 'description' }, 'Renders the template as it is now, before saving.')),
                output);

            details.addEventListener('toggle', () => {
                if (!details.open || posts.dataset.loaded) return;
                posts.dataset.loaded = '1';
                request('ddwpt_schema_posts', { post_type: this.slug }).then((result) => {
                    posts.innerHTML = '';
                    const items = result.success ? result.data : [];
                    if (!items.length) posts.append(h('option', { value: '' }, 'No published posts'));
                    items.forEach((post) => posts.append(h('option', { value: post.id }, post.title)));
                });
            });

            return details;
        }
    }

    function request(action, data) {
        const body = new URLSearchParams(Object.assign({ action, nonce: config.nonce }, data));
        return fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body })
            .then((response) => response.json())
            .catch(() => ({ success: false, data: 'Request failed' }));
    }

    /* ---------- Datalists shared by every editor ---------- */

    function datalists() {
        const lists = [h('datalist', { id: 'ddwpt-schema-types' },
            Object.entries(config.types).map(([type, info]) => h('option', { value: type }, info.label)))];

        const all = new Set();
        Object.entries(config.types).forEach(([type, info]) => {
            const properties = info.required.concat(info.recommended, info.other);
            properties.forEach((property) => all.add(property));
            lists.push(h('datalist', { id: 'ddwpt-schema-props-' + type }, properties.map((property) => h('option', { value: property }))));
        });
        lists.push(h('datalist', { id: 'ddwpt-schema-props-any' }, Array.from(all).map((property) => h('option', { value: property }))));
        return lists;
    }

    /* ---------- Boot ---------- */

    document.querySelectorAll('.ddwpt-schema-editor').forEach((root) => {
        const input = root.querySelector('input[type="hidden"]');
        let state;
        try { state = JSON.parse(input.value || '{}') || {}; } catch (exception) { state = {}; }
        if (Array.isArray(state)) state = {};

        const commit = () => {
            const saved = {};
            Object.entries(state).forEach(([slug, entry]) => {
                if (entry.enabled || (entry.template && entry.template.length)) saved[slug] = entry;
            });
            input.value = JSON.stringify(saved);
        };

        root.querySelector('.description').remove();
        root.append(...datalists());

        Object.entries(config.postTypes).forEach(([slug, info]) => {
            state[slug] = state[slug] || { enabled: false, template: [] };
            root.append(new PostTypePanel(slug, info, state[slug], commit).element);
        });
    });
})();
