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
    // Shorter names for the add buttons, where the full name is a mouthful.
    const DIRECTIVE_BUTTONS = { '@else': 'Fallback', '@merge': 'Merge', '@maps': 'Lookup' };
    const SOURCE_DIRECTIVES = ['@each', '@if', '@unless', '@from'];
    // Where each directive's control sits in a node: how the node is produced
    // goes above the properties, what happens to the result goes below.
    const TOP_DIRECTIVES    = ['@each', '@value', '@from', '@if', '@unless'];
    const BOTTOM_DIRECTIVES = ['@else', '@merge', '@maps'];

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

    /** Two-step destructive button: the first click arms it, the second acts. */
    /** Inline name entry in place of window.prompt (blocked in some frames). */
    function NameEntry(placeholder, onSubmit) {
        const input = h('input', { type: 'text', className: 'ddwpt-schema-name-entry', placeholder,
            onKeydown: (event) => {
                if (event.key === 'Enter') { event.preventDefault(); submit(); }
                if (event.key === 'Escape') wrap.remove();
            } });
        const submit = () => { const name = input.value.trim(); if (name) onSubmit(name); };
        const wrap = h('span', { className: 'ddwpt-schema-name-wrap' }, input, button('Add', submit), button('Cancel', () => wrap.remove(), 'is-link'));
        requestAnimationFrame(() => input.focus());
        return wrap;
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

    /** Move one entry of a list to another index, in place. */
    function moveItem(list, from, to) {
        list.splice(to, 0, list.splice(from, 1)[0]);
    }

    /** Rewrite an object's key order: keys not in `order` stay first, in place. */
    function reorderKeys(object, order) {
        const entries = Object.entries(object);
        Object.keys(object).forEach((key) => delete object[key]);
        entries.filter(([key]) => !order.includes(key)).forEach(([key, value]) => { object[key] = value; });
        order.forEach((key) => { object[key] = entries.find(([entry]) => entry === key)[1]; });
    }

    /* ---------- Reordering ---------- */

    // Stable ids for template objects, so focus and collapse state survive re-renders.
    const objectIds = new WeakMap();
    const collapsed = new WeakMap();
    // Nested objects start as a summary; ones listed here are open.
    const expandedObjects = new WeakSet();
    let nextObjectId = 0;
    let pendingFocus = null;

    function objectId(object) {
        if (!objectIds.has(object)) objectIds.set(object, ++nextObjectId);
        return objectIds.get(object);
    }

    /** Focus the handle a keyboard move asked for, once the panel has re-rendered. */
    function restoreFocus(root) {
        if (focusOpenRow) {
            focusOpenRow = false;
            const control = root.querySelector('.ddwpt-schema-row.is-open select:not(.ddwpt-schema-mode), .ddwpt-schema-row.is-open input:not(.ddwpt-schema-key)');
            if (control) control.focus();
        }
        if (!pendingFocus) return;
        const handle = root.querySelector('[data-handle-id="' + pendingFocus + '"]');
        pendingFocus = null;
        if (handle) handle.focus();
    }

    /**
     * Grip for a [data-sortable] item: the item is draggable only while the
     * grip is held, so inputs inside it still select text. Arrow keys move it.
     */
    function DragHandle(id, label, move) {
        const arm = (draggable) => (event) => { event.currentTarget.closest('[data-sortable]').draggable = draggable; };
        return h('button', {
            type: 'button', className: 'ddwpt-schema-handle', 'data-handle-id': id, title: 'Drag to reorder',
            'aria-label': 'Move ' + label + ' (arrow keys)',
            onPointerdown: arm(true), onPointerup: arm(false),
            onKeydown: (event) => {
                const step = { ArrowUp: -1, ArrowDown: 1 }[event.key];
                if (!step) return;
                event.preventDefault();
                pendingFocus = id;
                move(step);
            },
        }, icon('grip-vertical'));
    }

    /**
     * Drag-and-drop between the direct [data-sortable] children of `list`.
     * `horizontal` drops before or after by x instead of y (inline chips).
     */
    function Sortable(list, onMove, horizontal) {
        let dragged = null;
        const items  = () => Array.from(list.children).filter((child) => child.hasAttribute('data-sortable'));
        const clear  = () => items().forEach((item) => item.classList.remove('is-drop-before', 'is-drop-after'));
        const itemAt = (target) => {
            while (target && target.parentNode !== list) target = target.parentNode;
            return target && target.hasAttribute && target.hasAttribute('data-sortable') ? target : null;
        };

        list.addEventListener('dragstart', (event) => {
            const item = itemAt(event.target);
            if (!item || item !== event.target) return;
            event.stopPropagation();
            dragged = item;
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', '');
            requestAnimationFrame(() => item.classList.add('is-dragging'));
        });
        list.addEventListener('dragover', (event) => {
            if (!dragged) return;
            const item = itemAt(event.target);
            if (!item) return;
            event.preventDefault();
            event.stopPropagation();
            clear();
            if (item === dragged) return;
            const box    = item.getBoundingClientRect();
            const before = horizontal ? event.clientX < box.left + box.width / 2 : event.clientY < box.top + box.height / 2;
            item.classList.add(before ? 'is-drop-before' : 'is-drop-after');
        });
        list.addEventListener('drop', (event) => {
            if (!dragged) return;
            event.preventDefault();
            event.stopPropagation();
            const all    = items();
            const target = all.find((item) => item.classList.contains('is-drop-before') || item.classList.contains('is-drop-after'));
            clear();
            if (!target) return;
            const from = all.indexOf(dragged);
            let to     = all.indexOf(target) + (target.classList.contains('is-drop-after') ? 1 : 0);
            if (to > from) to--;
            if (to !== from) onMove(from, to);
        });
        list.addEventListener('dragend', (event) => {
            event.stopPropagation();
            if (dragged) {
                dragged.draggable = false;
                dragged.classList.remove('is-dragging');
            }
            dragged = null;
            clear();
        });
        return list;
    }

    /** Collapse toggle for a node; flips a class rather than re-rendering. */
    function CollapseToggle(object, target, label) {
        const toggle = h('button', { type: 'button', className: 'ddwpt-schema-collapse', 'aria-expanded': !collapsed.get(object),
            'aria-label': 'Show or hide ' + label,
            onClick: () => {
                const next = !collapsed.get(object);
                collapsed.set(object, next);
                target.classList.toggle('is-collapsed', next);
                toggle.setAttribute('aria-expanded', !next);
            } }, icon('chevron-right'));
        target.classList.toggle('is-collapsed', !!collapsed.get(object));
        return toggle;
    }

    function removeButton(label, onClick) {
        return h('button', { type: 'button', className: 'ddwpt-schema-remove', 'aria-label': 'Remove ' + label, title: 'Remove', onClick }, icon('x'));
    }

    /** An X that arms on the first click and removes on the second. */
    function confirmRemoveButton(label, onConfirm) {
        const element = removeButton(label, () => {
            if (element.classList.contains('is-armed')) { onConfirm(); return; }
            element.classList.add('is-armed');
            element.title = 'Click again to remove';
            element.setAttribute('aria-label', 'Click again to remove ' + label);
            setTimeout(() => {
                element.classList.remove('is-armed');
                element.title = 'Remove';
                element.setAttribute('aria-label', 'Remove ' + label);
            }, 3000);
        });
        return element;
    }

    function propertyCount(node) {
        const count = Object.keys(node).filter((key) => key !== '@type' && !DIRECTIVES[key]).length;
        return count === 1 ? '1 property' : count + ' properties';
    }

    /* ---------- Tokens (inc/seo/class-schema-syntax.php) ---------- */

    /**
     * Tokens follow Bricks dynamic data: {tag:filter:filter|arg @fallback:'text'}.
     * The model is { source, transforms: [{name, arg}] }; a trailing "fallback"
     * transform is written as @fallback. Earlier {{source|transform:arg}}
     * tokens are read too, and migrateTemplate() rewrites them on load.
     */
    function tokens(text) {
        const found = [];
        let offset = 0;
        let start;
        while ((start = text.indexOf('{', offset)) !== -1) {
            if (text.startsWith('{{', start)) {
                const end = text.indexOf('}}', start + 2);
                if (end === -1) break;
                found.push({ start, length: end + 2 - start, body: text.slice(start + 2, end).replace(/^\s+/, ''), legacy: true });
                offset = end + 2;
                continue;
            }
            if (!/[A-Za-z_:]/.test(text.charAt(start + 1))) { offset = start + 1; continue; }

            let depth = 0, quoted = false, end = -1;
            for (let index = start; index < text.length; index++) {
                const char = text[index];
                if (char === '\\' && quoted) { index++; continue; }
                if (char === "'") { quoted = !quoted; continue; }
                if (quoted) continue;
                if (char === '{') depth++;
                if (char === '}' && --depth === 0) { end = index; break; }
            }
            if (end === -1) { offset = start + 1; continue; }
            found.push({ start, length: end + 1 - start, body: text.slice(start + 1, end), legacy: false });
            offset = end + 1;
        }
        return found;
    }

    /** The token when the whole string is one ("{}" is an empty field). */
    function singleToken(text) {
        const trimmed = String(text).trim();
        if (trimmed === '{}' || trimmed === '{{}}') return { body: '', legacy: false };
        const found = tokens(trimmed);
        return found.length === 1 && found[0].start === 0 && found[0].length === trimmed.length ? found[0] : null;
    }

    function isLegacy(body) {
        return /^\s*(post\.|site\.|acf:|meta:|tax:|option:|ref:|item\.|item\s*\|)/.test(body);
    }

    /** Splits on `separator` outside single quotes, at most `limit` parts. */
    function splitUnquoted(text, separator, limit) {
        const parts = [];
        let current = '', quoted = false;
        for (let index = 0; index < text.length; index++) {
            const char = text[index];
            if (char === '\\' && quoted && index + 1 < text.length) { current += char + text[++index]; continue; }
            if (char === "'") quoted = !quoted;
            if (!quoted && char === separator && (!limit || parts.length < limit - 1)) { parts.push(current); current = ''; continue; }
            current += char;
        }
        parts.push(current);
        return parts;
    }

    function unquote(value) {
        return value.length >= 2 && value[0] === "'" && value[value.length - 1] === "'" ? value.slice(1, -1).replace(/\\'/g, "'") : value;
    }

    /** Expression body (no braces) → { source, transforms, legacy }. */
    function parseExpression(body, legacy) {
        body = String(body || '');
        if (legacy === undefined) {
            const single = singleToken(body);
            if (single) return parseExpression(single.body, single.legacy);
            legacy = isLegacy(body);
        }

        if (legacy) {
            const [source, ...parts] = body.replace(/^\s+/, '').split('|');
            return {
                source: source.trim(),
                legacy: true,
                transforms: parts.filter((part) => part.trim() !== '').map((part) => {
                    const colon = part.indexOf(':');
                    return colon === -1 ? { name: part.trim(), arg: '' } : { name: part.slice(0, colon).trim(), arg: part.slice(colon + 1) };
                }),
            };
        }

        body = body.trim();
        let main = body, fallback = null;
        let quoted = false;
        for (let index = 0; index < body.length; index++) {
            const char = body[index];
            if (char === '\\' && quoted) { index++; continue; }
            if (char === "'") { quoted = !quoted; continue; }
            if (!quoted && char === '@' && index > 0 && /\s/.test(body[index - 1])) {
                main = body.slice(0, index).trimEnd();
                const match = /@fallback:('(?:[^'\\]|\\.)*'|\S*)/.exec(body.slice(index));
                if (match) fallback = unquote(match[1]);
                break;
            }
        }

        const [source, ...segments] = splitUnquoted(main, ':');
        const transforms = segments.map((segment) => {
            const [name, arg] = splitUnquoted(segment, '|', 2);
            return { name: name.trim(), arg: arg === undefined ? '' : unquote(arg) };
        }).filter((transform) => transform.name !== '');
        if (fallback !== null) transforms.push({ name: 'fallback', arg: fallback });

        return { source: source.trim(), transforms, legacy: false };
    }

    function quoteArg(arg) {
        return /[:'{}@|]|^\s|\s$/.test(arg) ? "'" + arg.replace(/'/g, "\\'") + "'" : arg;
    }

    /** Token model → "tag:filter|arg @fallback:'text'" (no braces). */
    function formatExpression(token) {
        const transforms = token.transforms.slice();
        const last = transforms[transforms.length - 1];
        const fallback = last && last.name === 'fallback' ? transforms.pop() : null;
        return token.source
            + transforms.map((transform) => ':' + transform.name + (transform.arg !== '' ? '|' + quoteArg(transform.arg) : '')).join('')
            + (fallback ? " @fallback:'" + fallback.arg.replace(/'/g, "\\'") + "'" : '');
    }

    function formatToken(token) {
        return '{' + formatExpression(token) + '}';
    }

    /** "@each" may carry filters ("acf_x:term|tax") or be {rows, if, else}. */
    function eachSource(each) {
        const rows = each && typeof each === 'object' ? each.rows : each;
        return parseExpression(rows || '').source;
    }

    /* ---------- Migration from {{source|transform:arg}} ---------- */

    const LEGACY_SOURCES = {
        'post.title': 'post_title', 'post.url': 'post_url', 'post.excerpt': 'post_excerpt', 'post.content': 'post_content',
        'post.date': 'post_date', 'post.modified': 'post_modified', 'post.thumbnail': 'featured_image', 'post.author': 'author_name',
        'post.id': 'post_id', 'post.type': 'post_type', 'site.name': 'site_title', 'site.url': 'site_url', 'site.logo': 'site_logo',
        'item.@index': 'query_loop_index', item: 'item',
    };

    /** Earlier source name → Bricks tag, or null when it has none (e.g. tax:a,b). */
    function legacySource(source) {
        if (source === '' || LEGACY_SOURCES[source]) return LEGACY_SOURCES[source] || '';
        const match = /^(acf:|option:|meta:|ref:|tax:|item\.)(.+)$/.exec(source);
        if (!match || (match[1] === 'tax:' && match[2].includes(','))) return null;
        const prefix = { 'acf:': 'acf_', 'option:': 'acf_option_', 'meta:': 'cf_', 'ref:': 'ref_', 'tax:': 'post_terms_', 'item.': 'item_' }[match[1]];
        return prefix + match[2].replace(/\./g, '_');
    }

    /** A legacy expression rewritten, or null to leave it as it is. */
    function migrateExpression(body, legacy) {
        const token = parseExpression(body, legacy);
        if (!token.legacy) return null;
        const source = legacySource(token.source);
        if (source === null) return null;
        const transforms = token.transforms.map((transform) => {
            const arg = isLegacy(transform.arg) ? legacySource(transform.arg.trim()) : null;
            return { name: transform.name === 'default' ? 'fallback' : transform.name, arg: arg !== null ? arg : transform.arg };
        });
        return formatExpression({ source, transforms });
    }

    function migrateString(text) {
        const found = tokens(text);
        if (!found.some((token) => token.legacy)) {
            return found.length ? text : (migrateExpression(text) ?? text);
        }
        let result = '', offset = 0;
        found.forEach((token) => {
            const migrated = token.legacy ? migrateExpression(token.body, true) : null;
            result += text.slice(offset, token.start) + (migrated !== null ? '{' + migrated + '}' : text.substr(token.start, token.length));
            offset = token.start + token.length;
        });
        return result + text.slice(offset);
    }

    /** Rewrites a template's legacy tokens in place; bare directive values too. */
    function migrateTemplate(value) {
        if (Array.isArray(value)) return value.map(migrateTemplate);
        if (typeof value === 'string') return tokens(value).some((token) => token.legacy) ? migrateString(value) : value;
        if (!value || typeof value !== 'object') return value;
        Object.keys(value).forEach((key) => {
            const entry = value[key];
            if (key === '@maps') return;
            if (['@each', '@from', '@if', '@unless'].includes(key)) {
                if (typeof entry === 'string') value[key] = migrateString(entry);
                else if (key === '@each' && entry && typeof entry === 'object') {
                    ['rows', 'if'].forEach((part) => { if (typeof entry[part] === 'string') entry[part] = migrateString(entry[part]); });
                    if (entry.else && typeof entry.else === 'object') {
                        Object.keys(entry.else).forEach((field) => {
                            if (typeof entry.else[field] === 'string') entry.else[field] = migrateString(entry.else[field]);
                        });
                    }
                } else value[key] = migrateTemplate(entry);
                return;
            }
            value[key] = migrateTemplate(entry);
        });
        return value;
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

        /** Human label for a source, falling back to the raw source text. */
        label(value, items) {
            const item  = (items || []).find((entry) => entry.value === value);
            const found = item || this.find(value);
            return found ? found.label : value;
        }

        /** Row fields offered as item.* inside a loop over `source`. */
        itemSources(source) {
            const found  = this.find(source);
            const fields = found && found.children ? found.children : [
                { value: 'item', label: 'Whole row' },
                { value: 'query_loop_index', label: 'Row number' },
                { value: 'item_name', label: 'Name' },
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
            type: 'text', className: 'ddwpt-schema-source-custom', value, placeholder: 'e.g. cf__price',
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

    // Menu groups for the transform picker; unlisted transforms land in "Other".
    const TRANSFORM_GROUPS = [
        ['Text', ['clean', 'summary', 'prefix', 'suffix', 'plural', 'join']],
        ['Time and dates', ['minutes', 'duration', 'sum', 'hours', 'time', 'date']],
        ['Places and contact', ['address', 'geo', 'phone']],
        ['Lists and lookups', ['map', 'pairs', 'nutrition', 'first', 'unique', 'term', 'post']],
        ['Pick a part', ['name', 'slug', 'url', 'title', 'image']],
        ['Numbers and fallbacks', ['int', 'float', 'fallback']],
    ];

    function transformGroups() {
        const listed = new Set(TRANSFORM_GROUPS.flatMap(([, names]) => names));
        return TRANSFORM_GROUPS
            .map(([label, names]) => [label, names.filter((name) => config.transforms[name])])
            .concat([['Other', Object.keys(config.transforms).filter((name) => !listed.has(name))]])
            .filter(([, names]) => names.length);
    }

    /**
     * Searchable menu anchored to `anchor`. `groups` is [[label, [{value,
     * title, description}]]]; picking calls onPick(value) and closes. Esc or a
     * click elsewhere closes it too.
     */
    function Menu(anchor, groups, onPick, placeholder) {
        let active = 0;
        const search  = h('input', { type: 'search', className: 'ddwpt-schema-menu-search', placeholder, 'aria-label': placeholder });
        const list    = h('div', { className: 'ddwpt-schema-menu-list', role: 'listbox' });
        const menu    = h('div', { className: 'ddwpt-schema-menu' }, search, list);
        const options = () => Array.from(list.querySelectorAll('[role=option]'));

        const close = () => {
            document.removeEventListener('pointerdown', outside, true);
            menu.remove();
            anchor.setAttribute('aria-expanded', 'false');
        };
        const outside = (event) => { if (!menu.contains(event.target) && !anchor.contains(event.target)) close(); };
        const pick    = (value) => { close(); onPick(value); };
        const mark    = () => options().forEach((option, index) => {
            option.classList.toggle('is-active', index === active);
            if (index === active) option.scrollIntoView({ block: 'nearest' });
        });

        const draw = () => {
            const query = search.value.trim().toLowerCase();
            list.innerHTML = '';
            groups.forEach(([group, items]) => {
                const matches = items.filter((item) => !query
                    || (item.title + ' ' + (item.description || '') + ' ' + item.value).toLowerCase().includes(query));
                if (!matches.length) return;
                if (group) list.append(h('div', { className: 'ddwpt-schema-menu-group' }, group));
                matches.forEach((item) => list.append(h('div', { role: 'option', className: 'ddwpt-schema-menu-item',
                    onPointerdown: (event) => event.preventDefault(), onClick: () => pick(item.value) },
                    item.icon ? h('span', { className: 'ddwpt-schema-menu-icon' }, icon(item.icon)) : null,
                    h('span', { className: 'ddwpt-schema-menu-text' },
                        h('strong', {}, item.title),
                        item.description ? h('span', {}, item.description) : null))));
            });
            if (!options().length) list.append(h('div', { className: 'ddwpt-schema-menu-empty' }, 'Nothing matches.'));
            active = 0;
            mark();
        };

        search.addEventListener('input', draw);
        search.addEventListener('keydown', (event) => {
            const count = options().length;
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                active = (active + (event.key === 'ArrowDown' ? 1 : -1) + count) % Math.max(count, 1);
                mark();
            } else if (event.key === 'Enter') {
                event.preventDefault();
                const option = options()[active];
                if (option) option.click();
            } else if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                close();
                anchor.focus();
            }
        });

        draw();
        anchor.setAttribute('aria-expanded', 'true');
        anchor.after(menu);
        document.addEventListener('pointerdown', outside, true);
        search.focus();
        return menu;
    }

    /** Small button that opens a Menu; `groups` is built on click so it is current. */
    function MenuButton(attributes, content, groups, onPick, placeholder) {
        const trigger = h('button', Object.assign({ type: 'button', 'aria-haspopup': 'listbox', 'aria-expanded': 'false',
            onClick: () => {
                if (trigger.getAttribute('aria-expanded') === 'true') return;
                Menu(trigger, groups(), onPick, placeholder);
            } }, attributes), content);
        return trigger;
    }

    function transformMenuGroups() {
        return transformGroups().map(([label, names]) => [label, names.map((name) => ({
            value: name, title: name, description: config.transforms[name].label,
        }))]);
    }

    /** Sources as menu groups, for inserting a field into text. */
    function sourceMenuGroups(context) {
        return context.sources.options(context.items)
            .filter((option) => option.group)
            .map((option) => [option.group, option.options.map((source) => ({ value: source.value, title: source.label, description: source.value }))]);
    }

    /** Transforms as inline steps after the source, plus a button that opens the menu. */
    function TransformChips(transforms, onChange) {
        // Steps run left to right, so dragging a chip by its name reorders them.
        const wrap = Sortable(h('span', { className: 'ddwpt-schema-transforms' }), (from, to) => {
            moveItem(transforms, from, to);
            onChange(transforms, true);
        }, true);
        const arm = (draggable) => (event) => { event.currentTarget.parentNode.draggable = draggable; };

        transforms.forEach((transform, index) => {
            const info = config.transforms[transform.name] || {};
            wrap.append(h('span', { className: 'ddwpt-schema-chip', title: info.label || '', 'data-sortable': true },
                h('span', { className: 'ddwpt-schema-chip-name', title: 'Drag to reorder', onPointerdown: arm(true), onPointerup: arm(false) }, transform.name),
                info.arg || transform.arg !== '' ? h('input', {
                    type: 'text', value: transform.arg, placeholder: info.arg || '', size: Math.max(4, (transform.arg || info.arg || '').length),
                    'aria-label': transform.name + ' ' + (info.arg || 'argument'),
                    onInput: (event) => { transform.arg = event.target.value; onChange(transforms, false); },
                }) : null,
                h('button', { type: 'button', className: 'ddwpt-schema-chip-remove', 'aria-label': 'Remove ' + transform.name,
                    onClick: () => { transforms.splice(index, 1); onChange(transforms, true); } }, icon('x')),
            ));
        });

        wrap.append(MenuButton({ className: 'ddwpt-schema-add-transform', title: 'Add a transform', 'aria-label': 'Add a transform' },
            icon('function'), transformMenuGroups,
            (name) => { transforms.push({ name, arg: '' }); onChange(transforms, true); }, 'Search transforms'));

        return wrap;
    }

    /* ---------- Values ---------- */

    function valueMode(value) {
        if (typeof value === 'string') return 'text';
        if (value && typeof value === 'object' && !Array.isArray(value)) return 'object';
        return 'json';
    }

    function convertValue(value, mode) {
        const current = valueMode(value);
        if (current === mode) return value;
        if (mode === 'text') return '';
        if (mode === 'object') return { '@type': '' };
        return value === undefined ? null : value;
    }

    /**
     * Text with its fields. The input shows each token as its bare tag
     * ("{post_title}"); the filters and fallback applied to it are edited in
     * one box per token below. A lone {tag} keeps the value's type when
     * rendered, so there is no separate "field" mode.
     */
    function TextValue(value, context, set) {
        const signature = (text) => tokens(text).map((token) => token.body).join('\n');
        const parsed = tokens(value).map((found) => parseExpression(found.body, found.legacy));
        let current = value;

        // Stored text → what the input shows: tokens reduced to their tag.
        const display = (text) => {
            let out = '', offset = 0;
            tokens(text).forEach((found) => {
                out += text.slice(offset, found.start) + '{' + parseExpression(found.body, found.legacy).source + '}';
                offset = found.start + found.length;
            });
            return out + text.slice(offset);
        };

        // Input text → stored text. Each tag takes the filters of the token
        // it was rendered from: the same tag first, else the same position
        // while the token count is unchanged, so retyping a tag keeps them.
        const store = (text) => {
            const shown = [...text.matchAll(/\{([A-Za-z_][^{}:|@\s]*)?\}/g)];
            const used  = new Set();
            const pick  = (source, index) => {
                let match = parsed.findIndex((token, at) => !used.has(at) && token.source === source);
                if (match < 0 && shown.length === parsed.length && !used.has(index)) match = index;
                if (match < 0) return [];
                used.add(match);
                return parsed[match].transforms;
            };
            let out = '', offset = 0;
            shown.forEach((found, index) => {
                const source = found[1] || '';
                out += text.slice(offset, found.index) + formatToken({ source, transforms: pick(source, index) });
                offset = found.index + found[0].length;
            });
            return out + text.slice(offset);
        };

        const write = (next, structural) => {
            current = next;
            input.value = display(next);
            set(next, structural);
        };
        const input = h('input', { type: 'text', className: 'ddwpt-schema-text', value: display(value),
            onInput: (event) => { current = store(event.target.value); set(current, false); },
            // Re-render the boxes only when the tokens themselves changed.
            onChange: () => { if (signature(current) !== signature(value)) set(current, true); } });
        const insert = MenuButton({ className: 'ddwpt-schema-add-transform', title: 'Insert a field', 'aria-label': 'Insert a field' },
            icon('braces'), () => sourceMenuGroups(context),
            (source) => write(current + '{' + source + '}', true), 'Search fields');

        // Rewrites the index-th token of the current text.
        const replace = (index, token, structural) => {
            const found = tokens(current)[index];
            if (!found) return;
            write(current.slice(0, found.start) + formatToken(token) + current.slice(found.start + found.length), structural);
        };

        // The input already names each tag, so a box is only labelled (with
        // the field's own name, not its group) when there are several.
        const name = (token) => token.source ? context.sources.label(token.source, context.items).split(' › ').pop() : 'No source';
        const boxes = parsed.map((token, index) => h('span', { className: 'ddwpt-schema-field is-filters' },
            parsed.length > 1 ? h('span', { className: 'ddwpt-schema-token-source' }, name(token)) : null,
            token.transforms.length ? null : h('span', { className: 'ddwpt-schema-empty' }, 'No filters'),
            TransformChips(token.transforms, (transforms, structural) => { token.transforms = transforms; replace(index, token, structural); })));

        // The input sits beside the type select; the filter boxes take a full
        // line beneath both, so long filter chains get the whole width.
        return [
            h('span', { className: 'ddwpt-schema-field is-text' }, input, insert),
            boxes.length ? h('div', { className: 'ddwpt-schema-text-filters' }, boxes) : null,
        ];
    }

    /**
     * Edits any template value. `set(value, structural)` writes it back;
     * structural changes re-render the panel, keystrokes don't.
     */
    function ValueEditor(value, context, set) {
        const mode = valueMode(value);
        const wrap = h('div', { className: 'ddwpt-schema-value is-' + mode });

        const modes = select([
            { value: 'text', label: 'Text' },
            { value: 'object', label: 'Object' },
            { value: 'json', label: 'JSON' },
        ], mode, (next) => {
            const converted = convertValue(value, next);
            if (next === 'object') expandedObjects.add(converted);
            set(converted, true);
        }, { className: 'ddwpt-schema-mode', 'aria-label': 'Value type' });
        if (mode !== 'object') wrap.append(modes);

        if (mode === 'text') {
            wrap.append(...TextValue(value, context, set).filter(Boolean));
        } else if (mode === 'object') {
            wrap.append(NodeEditor(value, context, () => set(value, true), (keystroke) => set(value, keystroke), false, modes));
        } else {
            wrap.append(JsonInput(value, (parsed) => set(parsed, false)));
        }

        return wrap;
    }

    /** Read-only view of one {{token}}: source label, then its transforms. */
    function TokenSummary(token, context) {
        return h('span', { className: 'ddwpt-schema-token' },
            token.source ? h('span', { className: 'ddwpt-schema-token-source' }, context.sources.label(token.source, context.items))
                : h('span', { className: 'ddwpt-schema-empty' }, 'No source'),
            token.transforms.map((transform) => h('span', { className: 'ddwpt-schema-chip is-static' },
                h('span', { className: 'ddwpt-schema-chip-name' }, transform.name),
                transform.arg !== '' ? h('span', { className: 'ddwpt-schema-chip-arg' }, transform.arg) : null)));
    }

    /** Read-only view of a field, text or JSON value. */
    function ValueSummary(value, context) {
        const mode = valueMode(value);
        if (mode === 'text') {
            if (value === '') return h('span', { className: 'ddwpt-schema-empty' }, 'Empty');
            const parts = [];
            let offset = 0;
            tokens(value).forEach((token) => {
                if (token.start > offset) parts.push(value.slice(offset, token.start));
                parts.push(TokenSummary(parseExpression(token.body, token.legacy), context));
                offset = token.start + token.length;
            });
            if (offset < value.length) parts.push(value.slice(offset));
            return h('span', { className: 'ddwpt-schema-text-summary' }, parts);
        }
        return h('code', { className: 'ddwpt-schema-json-summary' }, JSON.stringify(value));
    }

    /** One line for a nested object: type, how it is produced, then its properties. */
    function ObjectSummary(node, context) {
        const label = (source) => context.sources.label(source, context.items);
        const keys  = Object.keys(node).filter((key) => key !== '@type' && !DIRECTIVES[key]);
        const parts = [];
        const via   = (directive, source) => h('span', { className: 'ddwpt-schema-via' }, DirectiveIcon(directive),
            h('span', { className: 'ddwpt-schema-badge' }, label(source) || '…'));
        if (node['@each'] !== undefined) parts.push(via('@each', eachSource(node['@each'])));
        ['@from', '@if', '@unless'].forEach((directive) => {
            if (typeof node[directive] === 'string') parts.push(via(directive, parseExpression(node[directive]).source));
        });
        if (node['@value'] !== undefined) parts.push(h('span', { className: 'ddwpt-schema-via' }, DirectiveIcon('@value'), 'one value per row'));
        else parts.push(keys.length ? keys.slice(0, 4).join(', ') + (keys.length > 4 ? ' +' + (keys.length - 4) : '') : 'no properties');
        return h('span', { className: 'ddwpt-schema-object-summary' },
            h('strong', {}, node['@type'] || (node['@value'] !== undefined ? 'List' : 'Object')),
            parts.flatMap((part) => [h('span', { className: 'ddwpt-schema-muted' }, '·'), typeof part === 'string' ? h('span', {}, part) : part]));
    }

    /**
     * A nested object value: a summary line until opened, then the full
     * editor. `set(value, structural)` writes it back.
     */
    function ObjectValue(value, context, label, set) {
        if (expandedObjects.has(value)) return ValueEditor(value, context, set);
        return h('div', { className: 'ddwpt-schema-value is-object-summary' },
            h('button', { type: 'button', className: 'ddwpt-schema-summary-value', title: 'Open ' + label, 'aria-expanded': 'false',
                onClick: () => { expandedObjects.add(value); set(value, true); } },
                icon('chevron-right'), ObjectSummary(value, context)));
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

    function directiveDefault(directive) {
        if (directive === '@maps') return {};
        return ['@value', '@else', '@merge'].includes(directive) ? '{}' : '';
    }

    const DIRECTIVE_HELP = {
        '@each':   'Output this once per row of a list field',
        '@value':  'Output a plain value per row instead of an object',
        '@from':   'Read post fields from a related post',
        '@if':     'Only output when a field has a value',
        '@unless': 'Only output when a field is empty',
        '@else':   'Use this instead when the node comes out empty',
        '@merge':  'Spread another object\'s properties into this one',
        '@maps':   'Named lookup tables for the map transform',
    };

    const DIRECTIVE_ICONS = {
        '@each': 'repeat', '@value': 'list', '@from': 'link', '@if': 'eye', '@unless': 'eye-off',
        '@else': 'corner-down-right', '@merge': 'merge', '@maps': 'table',
    };

    /** A directive shown as its icon; the name and what it does are in the tooltip. */
    function DirectiveIcon(directive) {
        return h('span', { className: 'ddwpt-schema-directive-icon', role: 'img', 'aria-label': DIRECTIVES[directive],
            title: DIRECTIVES[directive] + ': ' + DIRECTIVE_HELP[directive].toLowerCase() }, icon(DIRECTIVE_ICONS[directive]));
    }

    /** One quiet icon-and-label add button per directive this place offers and the node lacks. */
    function DirectiveButtons(node, directives, save) {
        const missing = directives.filter((directive) => node[directive] === undefined);
        if (!missing.length) return null;
        return h('div', { className: 'ddwpt-schema-directive-buttons' }, missing.map((directive) =>
            h('button', { type: 'button', className: 'ddwpt-schema-ghost', title: DIRECTIVE_HELP[directive],
                onClick: () => { node[directive] = directiveDefault(directive); save(true); } },
                icon(DIRECTIVE_ICONS[directive]), DIRECTIVE_BUTTONS[directive] || DIRECTIVES[directive])));
    }

    function TypeInput(node, save) {
        return h('input', {
            type: 'text', className: 'ddwpt-schema-type', value: node['@type'] || '', list: 'ddwpt-schema-types',
            placeholder: 'schema.org type', 'aria-label': 'Schema type',
            size: fitSize(node['@type'] || 'schema.org type'),
            onInput: (event) => { event.target.size = fitSize(event.target.value || event.target.placeholder); },
            onChange: (event) => { node['@type'] = event.target.value; save(true); },
        });
    }

    /**
     * The plugin's switch (settings.css .ddwpt-toggle). It lives in a panel's
     * <summary>, so clicks are handled here and never open or close the panel.
     */
    function Toggle(checked, label, onToggle) {
        const input = h('input', { type: 'checkbox', checked, 'aria-label': label });
        return h('label', { className: 'ddwpt-toggle', title: label + (checked ? ': on' : ': off'),
            onClick: (event) => {
                event.preventDefault();
                event.stopPropagation();
                // input.checked is already flipped while a keyboard click dispatches.
                onToggle(!checked, document.activeElement === input);
            } },
            input, h('span', { className: 'ddwpt-toggle-track' }, h('span', { className: 'ddwpt-toggle-thumb' })));
    }

    /** Width for an input that grows with its text, where CSS field-sizing is unsupported. */
    function fitSize(text) {
        return Math.max(4, text.length + 1);
    }

    /**
     * Edits an object node in place: options (directives), then sortable
     * property rows. `rerender()` redraws after structural edits; `changed()`
     * saves keystrokes. Nested nodes get a collapsible head with their type;
     * a card shows the type in its own head (`inCard`).
     */
    /**
     * `headExtra` (a nested value's type select) sits at the end of the
     * node's head, so opening the object doesn't move it onto its own line.
     */
    function NodeEditor(node, parentContext, rerender, changed, inCard, headExtra) {
        const save = (structural) => (structural ? rerender() : changed(false));
        const each = node['@each'];
        const context = Object.assign({}, parentContext, each !== undefined
            ? { items: parentContext.sources.itemSources(eachSource(each)) }
            : {});

        const wrap = h('div', { className: 'ddwpt-schema-node' });
        const body = h('div', { className: 'ddwpt-schema-node-body' });

        if (!inCard) {
            // A node that yields a plain value per row (@value) has no type.
            const typed = node['@value'] === undefined || node['@type'];
            wrap.append(h('div', { className: 'ddwpt-schema-node-head' },
                h('button', { type: 'button', className: 'ddwpt-schema-collapse', 'aria-expanded': 'true', 'aria-label': 'Close this object',
                    onClick: () => { expandedObjects.delete(node); save(true); } }, icon('chevron-right')),
                typed ? TypeInput(node, save) : h('span', { className: 'ddwpt-schema-muted' }, 'Value per row'),
                h('span', { className: 'ddwpt-schema-node-count' }, propertyCount(node)),
                headExtra || null));
        }

        // Item value only means something inside a loop; lookup tables live on root nodes.
        const offered = (place) => place.filter((directive) =>
            (directive !== '@value' || node['@each'] !== undefined) && (directive !== '@maps' || inCard));
        const section = (place, className) => {
            const present = place.filter((directive) => node[directive] !== undefined);
            return present.length ? h('div', { className: 'ddwpt-schema-options ' + className },
                present.map((directive) => DirectiveRow(node, directive, directive === '@each' ? parentContext : context, save))) : null;
        };

        const top = section(TOP_DIRECTIVES, 'is-top');
        if (top) body.append(top);
        const topButtons = DirectiveButtons(node, offered(TOP_DIRECTIVES), save);
        if (topButtons) body.append(topButtons);

        const keys  = Object.keys(node).filter((key) => key !== '@type' && !DIRECTIVES[key]);
        const move  = (from, to) => { moveItem(keys, from, to); reorderKeys(node, keys); save(true); };
        const props = Sortable(h('div', { className: 'ddwpt-schema-props' },
            keys.map((key, index) => PropertyRow(node, key, context, save, (step) => {
                const target = index + step;
                if (target >= 0 && target < keys.length) move(index, target);
            }))), move);

        body.append(props);
        const bottom = section(BOTTOM_DIRECTIVES, 'is-bottom');
        if (bottom) body.append(bottom);
        body.append(AddRow(node, save, DirectiveButtons(node, offered(BOTTOM_DIRECTIVES), save)));
        wrap.append(body);
        return wrap;
    }

    // The one row open for editing: {node, key}. Others show a summary.
    let openRow = null;
    let focusOpenRow = false;

    function rowState(node, key, save, editable) {
        const open = editable && !!openRow && openRow.node === node && openRow.key === key;
        const setOpen = (next) => { openRow = next ? { node, key } : null; focusOpenRow = next; save(true); };
        return { open, setOpen };
    }

    function SummaryButton(label, summary, setOpen) {
        return h('button', { type: 'button', className: 'ddwpt-schema-summary-value', title: 'Edit ' + label, onClick: () => setOpen(true) },
            summary, icon('pencil'));
    }

    function DoneButton(label, setOpen) {
        return h('button', { type: 'button', className: 'ddwpt-schema-done', title: 'Done (Esc)', 'aria-label': 'Done editing ' + label,
            onClick: () => setOpen(false) }, icon('check'));
    }

    function closeOnEscape(open, setOpen) {
        return open ? (event) => { if (event.key === 'Escape') { event.preventDefault(); setOpen(false); } } : null;
    }

    /** Summary of a directive's value: its source(s), or the value itself. */
    function DirectiveSummary(node, directive, context) {
        const value  = node[directive];
        const source = (text) => text ? TokenSummary(parseExpression(text), context)
            : h('span', { className: 'ddwpt-schema-empty' }, 'No source');
        if (directive === '@maps') {
            const tables = Object.keys(value || {});
            return tables.length ? h('span', { className: 'ddwpt-schema-text-summary is-sentence' }, tables.flatMap((table, index) => [
                index ? ', ' : null,
                h('span', { className: 'ddwpt-schema-badge' }, table),
                ' ' + Object.keys(value[table]).length + ' rows']).filter(Boolean))
                : h('span', { className: 'ddwpt-schema-empty' }, 'No tables');
        }
        if (!SOURCE_DIRECTIVES.includes(directive)) return ValueSummary(value, context);
        if (directive === '@each' && value && typeof value === 'object') {
            // Sources and row fields as badges, joined by plain words.
            const badge  = (content) => h('span', { className: 'ddwpt-schema-badge' }, content);
            const fields = Object.keys(value.else || {});
            return h('span', { className: 'ddwpt-schema-text-summary is-sentence' }, badge(source(value.rows)),
                value.if ? [' only when ', badge(source(value.if))] : null,
                value.else ? [', otherwise one row of ', ...(fields.length ? fields.map((field) => badge(field)) : ['no fields'])] : null);
        }
        return source(value);
    }

    /**
     * "Repeat for each": the rows, an optional condition on them, and an
     * optional single row built from other fields when there are none. The
     * short form ("@each": "source") is kept until a condition or fallback
     * row is added, and restored when both are removed.
     */
    function EachEditor(node, context, save) {
        const each = node['@each'];
        const spec = each && typeof each === 'object' ? each : { rows: each || '' };
        const write = (structural) => {
            node['@each'] = spec.if === undefined && spec.else === undefined ? spec.rows : spec;
            save(structural);
        };
        const ghost = (label, onClick) => h('button', { type: 'button', className: 'ddwpt-schema-ghost', onClick }, icon('plus'), label);
        const line  = (label, help, ...control) => h('div', { className: 'ddwpt-schema-each-line' },
            h('span', { className: 'ddwpt-schema-each-label', title: help }, label),
            h('div', { className: 'ddwpt-schema-each-control' }, control));

        const rows = line('Rows from', 'A list field: the object is output once per row.',
            SourcePicker(spec.rows || '', context, (source, structural) => { spec.rows = source; write(structural); }, true));

        const condition = spec.if !== undefined
            ? line('Only when', 'The rows are used only while this field has a value; otherwise it is as if there were none.',
                SourcePicker(spec.if, context, (source, structural) => { spec.if = source; write(structural); }),
                removeButton('condition', () => { delete spec.if; write(true); }))
            : null;

        let fallback = null;
        if (spec.else !== undefined) {
            const fields = spec.else;
            const entries = Object.keys(fields).map((key) => h('div', { className: 'ddwpt-schema-each-field' },
                h('label', { className: 'ddwpt-schema-each-name' },
                    h('span', { className: 'ddwpt-schema-each-prefix' }, 'item_'),
                    h('input', { type: 'text', value: key, 'aria-label': 'Row field name',
                    onChange: (event) => {
                        const next = event.target.value.trim();
                        if (!next || next === key || fields[next] !== undefined) { event.target.value = key; return; }
                        renameKey(fields, key, next);
                        write(true);
                    } })),
                h('span', { className: 'ddwpt-schema-muted' }, 'from'),
                typeof fields[key] === 'string'
                    ? SourcePicker(fields[key], context, (source, structural) => { fields[key] = source; write(structural); })
                    : h('code', { className: 'ddwpt-schema-json-summary' }, JSON.stringify(fields[key])),
                removeButton('row field ' + key, () => { delete fields[key]; write(true); })));
            const addField = ghost('Add row field', () => {
                let name = 'field', count = 1;
                while (fields[name] !== undefined) name = 'field' + ++count;
                fields[name] = '';
                write(true);
            });
            fallback = line('If no rows', 'Output the object once, from a single row built from these fields. Inside it, item_<name> reads them and query_loop_index is empty.',
                h('div', { className: 'ddwpt-schema-each-fields' },
                    h('p', { className: 'ddwpt-schema-each-help' }, 'Output it once, with a row built from:'),
                    entries, addField),
                removeButton('fallback row', () => { delete spec.else; write(true); }));
        }

        const adds = [
            spec.if === undefined ? ghost('Only when…', () => { spec.if = ''; write(true); }) : null,
            spec.else === undefined ? ghost('If there are no rows…', () => { spec.else = {}; write(true); }) : null,
        ].filter(Boolean);

        return h('div', { className: 'ddwpt-schema-each-form' }, rows, condition, fallback,
            adds.length ? h('div', { className: 'ddwpt-schema-each-adds' }, adds) : null);
    }

    function DirectiveRow(node, directive, context, save) {
        const editable = directive === '@maps' || SOURCE_DIRECTIVES.includes(directive) || valueMode(node[directive]) !== 'object';
        const { open, setOpen } = rowState(node, directive, save, editable);
        let control;

        if (directive === '@maps') {
            control = MapsEditor(node['@maps'], save);
        } else if (SOURCE_DIRECTIVES.includes(directive)) {
            const value = node[directive];
            if (directive === '@each') {
                control = EachEditor(node, context, save);
            } else {
                control = SourcePicker(value || '', context, (source, structural) => { node[directive] = source; save(structural); }, directive === '@each');
            }
        } else {
            const write = (value, structural) => { node[directive] = value; save(structural); };
            control = valueMode(node[directive]) === 'object'
                ? ObjectValue(node[directive], context, DIRECTIVES[directive], write)
                : ValueEditor(node[directive], context, write);
        }

        return h('div', { className: 'ddwpt-schema-row is-directive' + (open ? ' is-open' : ''), onKeydown: closeOnEscape(open, setOpen) },
            DirectiveIcon(directive),
            open || !editable ? control : SummaryButton(DIRECTIVES[directive], DirectiveSummary(node, directive, context), setOpen),
            open ? DoneButton(DIRECTIVES[directive], setOpen) : removeButton(DIRECTIVES[directive], () => { delete node[directive]; save(true); }));
    }


    function PropertyRow(node, key, context, save, move) {
        const required = typeProperties(node['@type']).required.includes(key);
        const value    = node[key];
        const { open, setOpen } = rowState(node, key, save, valueMode(value) !== 'object');

        const name = h('input', {
            type: 'text', className: 'ddwpt-schema-key', value: key, list: 'ddwpt-schema-props-' + (node['@type'] || 'any'),
            'aria-label': 'Property name',
            onChange: (event) => {
                const next = event.target.value.trim();
                if (!next || next === key || node[next] !== undefined) { event.target.value = key; return; }
                renameKey(node, key, next);
                if (open) openRow.key = next;
                save(true);
            },
        });

        const write   = (next, structural) => { node[key] = next; save(structural); };
        const content = valueMode(value) === 'object' ? ObjectValue(value, context, key, write)
            : open ? ValueEditor(value, context, write)
            : SummaryButton(key, ValueSummary(value, context), setOpen);

        return h('div', { className: 'ddwpt-schema-row' + (required ? ' is-required' : '') + (open ? ' is-open' : ''), 'data-sortable': true,
            onKeydown: closeOnEscape(open, setOpen) },
            DragHandle(objectId(node) + '/' + key, key, move),
            h('span', { className: 'ddwpt-schema-key-wrap', title: required ? key + ' (required by Google)' : key }, name),
            content,
            open ? DoneButton(key, setOpen) : removeButton(key, () => { delete node[key]; save(true); }),
        );
    }

    function AddRow(node, save, extras) {
        const properties = typeProperties(node['@type']);
        const missing    = properties.required.filter((property) => node[property] === undefined);
        const options    = [{ value: '', label: '+ Add property' }];

        ['required', 'recommended', 'other'].forEach((group) => {
            const unused = properties[group].filter((property) => node[property] === undefined);
            if (unused.length) options.push({ group: group[0].toUpperCase() + group.slice(1), options: unused.map((property) => ({ value: property, label: property })) });
        });
        options.push({ value: '__custom', label: 'Other property…' });

        const add = (key) => {
            if (!key) return;
            if (key === '__custom') {
                picker.value = '';
                if (!row.querySelector('.ddwpt-schema-name-wrap')) {
                    picker.after(NameEntry('Property name', (name) => add(name)));
                }
                return;
            }
            if (node[key] !== undefined) return;
            node[key] = DIRECTIVES[key] ? directiveDefault(key) : '{}';
            save(true);
        };

        const picker = select(options, '', add, { 'aria-label': 'Add property' });
        const row    = h('div', { className: 'ddwpt-schema-add' },
            picker,
            missing.length ? h('span', { className: 'ddwpt-schema-missing' }, 'Missing required: ',
                missing.map((property) => h('button', { type: 'button', className: 'ddwpt-schema-pill', onClick: () => add(property) }, property))) : null,
            extras,
        );
        return row;
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
                    removeButton('row', () => { delete rows[from]; save(true); })));
            });

            list.append(button('+ Row', () => { rows[''] = ''; save(true); }, 'is-link'));
            wrap.append(list);
        });

        const addTable = button('+ Lookup table', () => {
            if (wrap.querySelector('.ddwpt-schema-name-wrap')) return;
            addTable.after(NameEntry('Table name, e.g. diets', (name) => {
                if (!maps[name]) maps[name] = {};
                save(true);
            }));
        }, 'is-link');
        wrap.append(addTable);

        return wrap;
    }

    /* ---------- Preset picker ---------- */

    // Lucide icons (ISC licence), inner SVG markup on a 24px stroke grid.
    const ICONS = {
        'file-text': '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
        'pen-line': '<path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"/>',
        newspaper: '<path d="M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-2 2Zm0 0a2 2 0 0 1-2-2v-9c0-1.1.9-2 2-2h2"/><path d="M18 14h-8"/><path d="M15 18h-5"/><path d="M10 6h8v4h-8V6Z"/>',
        globe: '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
        building: '<rect width="16" height="20" x="4" y="2" rx="2" ry="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M12 6h.01"/><path d="M12 10h.01"/><path d="M12 14h.01"/><path d="M16 10h.01"/><path d="M16 14h.01"/><path d="M8 10h.01"/><path d="M8 14h.01"/>',
        stethoscope: '<path d="M11 2v2"/><path d="M5 2v2"/><path d="M5 3H4a2 2 0 0 0-2 2v4a6 6 0 0 0 12 0V5a2 2 0 0 0-2-2h-1"/><path d="M8 15a6 6 0 0 0 12 0v-3"/><circle cx="20" cy="10" r="2"/>',
        store: '<path d="m2 7 4.41-4.41A2 2 0 0 1 7.83 2h8.34a2 2 0 0 1 1.42.59L22 7"/><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><path d="M15 22v-4a2 2 0 0 0-2-2h-2a2 2 0 0 0-2 2v4"/><path d="M2 7h20"/><path d="M22 7v3a2 2 0 0 1-2 2 2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 16 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 12 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 8 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 4 12a2 2 0 0 1-2-2V7"/>',
        'map-pin': '<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>',
        utensils: '<path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2"/><path d="M7 2v20"/><path d="M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/>',
        user: '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        wrench: '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
        calendar: '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
        'help-circle': '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>',
        package: '<path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
        briefcase: '<path d="M16 20V4a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/><rect width="20" height="14" x="2" y="6" rx="2"/>',
        'graduation-cap': '<path d="M21.42 10.922a1 1 0 0 0-.019-1.838L12.83 5.18a2 2 0 0 0-1.66 0L2.6 9.08a1 1 0 0 0 0 1.832l8.57 3.908a2 2 0 0 0 1.66 0z"/><path d="M22 10v6"/><path d="M6 12.5V16a6 3 0 0 0 12 0v-3.5"/>',
        braces: '<path d="M8 3H7a2 2 0 0 0-2 2v5a2 2 0 0 1-2 2 2 2 0 0 1 2 2v5c0 1.1.9 2 2 2h1"/><path d="M16 21h1a2 2 0 0 0 2-2v-5c0-1.1.9-2 2-2a2 2 0 0 1-2-2V5a2 2 0 0 0-2-2h-1"/>',
        plus: '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'grip-vertical': '<circle cx="9" cy="12" r="1"/><circle cx="9" cy="5" r="1"/><circle cx="9" cy="19" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="5" r="1"/><circle cx="15" cy="19" r="1"/>',
        'chevron-right': '<path d="m9 18 6-6-6-6"/>',
        pencil: '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/>',
        check: '<path d="M20 6 9 17l-5-5"/>',
        repeat: '<path d="m17 2 4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14"/><path d="m7 22-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/>',
        list: '<path d="M3 12h.01"/><path d="M3 18h.01"/><path d="M3 6h.01"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M8 6h13"/>',
        link: '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        eye: '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
        'eye-off': '<path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"/><path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"/><path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"/><path d="m2 2 20 20"/>',
        'corner-down-right': '<polyline points="15 10 20 15 15 20"/><path d="M4 4v7a4 4 0 0 0 4 4h12"/>',
        merge: '<path d="m8 6 4-4 4 4"/><path d="M12 2v10.3a4 4 0 0 1-1.172 2.872L4 22"/><path d="m20 22-5-5"/>',
        table: '<path d="M12 3v18"/><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M3 15h18"/>',
        function: '<rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><path d="M9 17c2 0 2.8-1 2.8-2.8V10c0-2 1-3.3 3.2-3"/><path d="M9 11.2h5.7"/>',
        x: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
    };

    const PRESET_ICONS = {
        Article: 'file-text', BlogPosting: 'pen-line', NewsArticle: 'newspaper', WebPage: 'globe',
        Organization: 'building', LegalService: 'building', MedicalOrganization: 'stethoscope',
        MedicalClinic: 'stethoscope', Dentist: 'stethoscope', Physician: 'stethoscope',
        LocalBusiness: 'store', Store: 'store', AutomotiveBusiness: 'map-pin', Restaurant: 'utensils',
        Recipe: 'utensils', Person: 'user', Service: 'wrench', ProfessionalService: 'briefcase',
        HomeAndConstructionBusiness: 'wrench', Event: 'calendar', FAQPage: 'help-circle',
        Product: 'package', JobPosting: 'briefcase', Course: 'graduation-cap',
    };

    // Types not listed fall into "Other", so filtered-in presets still show.
    const PRESET_GROUPS = [
        ['Content', ['Article', 'BlogPosting', 'NewsArticle', 'WebPage', 'FAQPage', 'Recipe', 'Course']],
        ['Organisations & places', ['Organization', 'MedicalOrganization', 'LocalBusiness', 'MedicalClinic', 'Dentist', 'Physician',
            'ProfessionalService', 'LegalService', 'HomeAndConstructionBusiness', 'AutomotiveBusiness', 'Restaurant', 'Store']],
        ['People & services', ['Person', 'Service', 'JobPosting']],
        ['Commerce & events', ['Product', 'Event']],
    ];

    function icon(name) {
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        ['viewBox:0 0 24 24', 'fill:none', 'stroke:currentColor', 'stroke-width:2', 'stroke-linecap:round', 'stroke-linejoin:round', 'aria-hidden:true']
            .forEach((pair) => { const [key, value] = pair.split(':'); svg.setAttribute(key, value); });
        svg.innerHTML = ICONS[name] || ICONS.braces;
        return svg;
    }

    function presetGroups() {
        const types  = Object.keys(config.presets);
        const listed = new Set(PRESET_GROUPS.flatMap(([, members]) => members));
        return PRESET_GROUPS
            .map(([label, members]) => [label, members.filter((type) => config.presets[type])])
            .concat([['Other', types.filter((type) => !listed.has(type))]])
            .filter(([, members]) => members.length);
    }

    /**
     * "Add from preset" button and the modal it opens: one tab per group,
     * each a grid of radio cards. `state` ({type, tab}) lives on the panel
     * so the choice survives re-renders; onAdd receives the chosen type.
     */
    function PresetPicker(slug, state, onAdd) {
        const name   = 'ddwpt-preset-' + slug;
        const groups = presetGroups();
        if (!groups.some(([group]) => group === state.tab)) state.tab = groups.length ? groups[0][0] : '';

        const add = h('button', { type: 'button', className: 'ddwpt-btn ddwpt-schema-preset-add', disabled: !state.type,
            onClick: () => {
                if (!state.type) return;
                const type = state.type;
                state.type = '';
                dialog.close();
                onAdd(type);
            } });
        const label = () => { add.textContent = state.type ? 'Add ' + config.presets[state.type].label : 'Choose a preset'; };
        label();

        const card = (type) => {
            const preset   = config.presets[type];
            const required = (config.types[type] && config.types[type].required || []).length;
            return h('label', { className: 'ddwpt-schema-preset-card' },
                h('input', { type: 'radio', name, value: type, form: 'ddwpt-schema-none', checked: state.type === type,
                    onChange: () => { state.type = type; add.disabled = false; label(); },
                    onDblclick: () => add.click() }),
                h('span', { className: 'ddwpt-schema-preset-icon' }, icon(PRESET_ICONS[type])),
                h('span', { className: 'ddwpt-schema-preset-text' },
                    h('strong', {}, preset.label),
                    h('code', {}, type),
                    required ? h('small', {}, required + ' required') : null));
        };

        const tabs   = [];
        const panels = [];
        const showTab = (group, focus) => {
            state.tab = group;
            groups.forEach(([other], index) => {
                const active = other === group;
                tabs[index].setAttribute('aria-selected', active);
                tabs[index].tabIndex = active ? 0 : -1;
                panels[index].hidden = !active;
                if (active && focus) tabs[index].focus();
            });
        };

        groups.forEach(([group, types], index) => {
            const id = name + '-' + index;
            tabs.push(h('button', { type: 'button', role: 'tab', id: id + '-tab', 'aria-controls': id, className: 'ddwpt-schema-preset-tab',
                onClick: () => showTab(group),
                onKeydown: (event) => {
                    const step = { ArrowRight: 1, ArrowLeft: -1 }[event.key];
                    if (!step) return;
                    event.preventDefault();
                    showTab(groups[(index + step + groups.length) % groups.length][0], true);
                } },
                group, h('span', { className: 'ddwpt-schema-preset-count' }, types.length)));
            panels.push(h('div', { role: 'tabpanel', id, 'aria-labelledby': id + '-tab', className: 'ddwpt-schema-preset-grid' }, types.map(card)));
        });

        const dialog = h('dialog', { className: 'ddwpt-schema-preset-modal', 'aria-labelledby': name + '-title',
            // A click on the backdrop lands on the dialog element itself.
            onClick: (event) => { if (event.target === dialog) dialog.close(); } },
            h('div', { className: 'ddwpt-schema-preset-modal-head' },
                h('h3', { id: name + '-title' }, 'Add from preset'),
                h('button', { type: 'button', className: 'ddwpt-schema-preset-close', 'aria-label': 'Close', onClick: () => dialog.close() }, icon('x'))),
            h('div', { className: 'ddwpt-schema-preset-tabs', role: 'tablist', 'aria-label': 'Preset groups' }, tabs),
            h('div', { className: 'ddwpt-schema-preset-panels' }, panels),
            h('div', { className: 'ddwpt-schema-preset-modal-foot' },
                h('span', { className: 'description' }, 'Adds a node with the type\'s required and recommended properties.'),
                h('span', { className: 'ddwpt-schema-preset-actions' }, button('Cancel', () => dialog.close(), 'is-link'), add)));
        showTab(state.tab);

        const open = h('button', { type: 'button', className: 'ddwpt-btn ddwpt-schema-preset-open', onClick: () => dialog.showModal() },
            icon('plus'), 'Add from preset');
        return [open, dialog];
    }

    /* ---------- Post type panel ---------- */

    class PostTypePanel {
        constructor(slug, info, entry, commit) {
            this.slug    = slug;
            this.info    = info;
            this.entry   = entry;
            this.commit  = commit;
            this.mode    = 'visual';
            this.presetState = { type: '', tab: '' };
            this.sources = new SourceCatalogue(slug);
            this.element = h('details', { className: 'ddwpt-schema-panel' });
            if (entry.template) entry.template = migrateTemplate(entry.template);
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
                h('strong', {}, this.info.label), h('code', { className: 'ddwpt-schema-slug' }, this.slug),
                h('span', { className: 'ddwpt-schema-summary' }, this.summary()),
                Toggle(this.entry.enabled, 'Output schema on single ' + this.info.label.toLowerCase(), (enabled, focused) => {
                    this.entry.enabled = enabled;
                    this.changed(true);
                    if (focused) this.element.querySelector('summary .ddwpt-toggle input').focus();
                })));

            const body = h('div', { className: 'ddwpt-schema-body' });
            body.append(this.toolbar());

            if (this.mode === 'json') {
                body.append(this.jsonEditor());
            } else if (this.mode === 'preview') {
                body.append(this.preview());
            } else {
                const nodes = this.nodes();
                const move  = (from, to) => { moveItem(nodes, from, to); this.changed(true); };
                body.append(Sortable(h('div', { className: 'ddwpt-schema-cards' },
                    nodes.map((node, index) => this.nodeCard(node, index, move))), move));
                if (!this.nodes().length) {
                    body.append(h('p', { className: 'description' }, 'No schema yet. Add one from a preset, or start with an empty node.'));
                }
                body.append(h('button', { type: 'button', className: 'ddwpt-schema-ghost ddwpt-schema-empty-node',
                    onClick: () => { this.nodes().push({ '@type': '' }); this.changed(true); } }, icon('plus'), 'Empty node'));
            }

            this.element.append(body);
            restoreFocus(this.element);
        }

        changed(structural) {
            this.commit();
            if (structural) this.render();
        }

        toolbar() {
            const modeButton = (mode, label) => h('button', {
                type: 'button', className: 'ddwpt-schema-mode-btn' + (this.mode === mode ? ' is-active' : ''), 'aria-pressed': this.mode === mode,
                onClick: () => {
                    if (this.mode === mode) return;
                    if (this.jsonError) {
                        // The JSON error is already shown under the editor; keep the user there.
                        this.element.querySelector('.ddwpt-schema-json-mode .ddwpt-schema-error').classList.add('is-flash');
                        return;
                    }
                    this.mode = mode;
                    this.render();
                },
            }, label);

            return h('div', { className: 'ddwpt-schema-toolbar' },
                h('span', { className: 'ddwpt-schema-modes', role: 'group', 'aria-label': 'Editor mode' }, modeButton('visual', 'Visual'), modeButton('json', 'JSON'), modeButton('preview', 'Preview')),
                this.mode === 'preview' ? null : PresetPicker(this.slug, this.presetState, (type) => {
                    this.nodes().push(clone(config.presets[type].template));
                    this.changed(true);
                }));
        }

        nodeCard(node, index, move) {
            const nodes   = this.nodes();
            const context = { sources: this.sources, items: null };
            const save    = (structural) => this.changed(structural);
            const card    = h('div', { className: 'ddwpt-schema-card', 'data-sortable': true });

            card.append(
                h('div', { className: 'ddwpt-schema-card-head' },
                    DragHandle('node-' + objectId(node), node['@type'] || 'node', (step) => {
                        const target = index + step;
                        if (target >= 0 && target < nodes.length) move(index, target);
                    }),
                    CollapseToggle(node, card, node['@type'] || 'node'),
                    h('span', { className: 'ddwpt-schema-card-icon' }, icon(PRESET_ICONS[node['@type']])),
                    TypeInput(node, save),
                    h('span', { className: 'ddwpt-schema-node-count' }, propertyCount(node)),
                    confirmRemoveButton(node['@type'] || 'node', () => {
                        nodes.splice(index, 1);
                        this.changed(true);
                    })),
                NodeEditor(node, context, () => this.changed(true), () => this.changed(false), true));
            return card;
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

        /** Preview mode: renders the unsaved template against a chosen post. */
        preview() {
            const posts  = h('select', { 'aria-label': 'Preview post', onChange: () => { this.previewPost = posts.value; render(); } },
                h('option', { value: '' }, 'Loading posts…'));
            const output = h('div', { className: 'ddwpt-schema-preview-output' });
            const test   = h('a', { className: 'ddwpt-schema-preview-test', target: '_blank', rel: 'noopener', hidden: true,
                title: 'Test this post\'s live page' }, 'Google\'s Rich Results Test ↗');
            const render = () => {
                if (!posts.value) return;
                output.textContent = 'Rendering…';
                request('ddwpt_schema_preview', { post_id: posts.value, template: JSON.stringify(this.nodes()) }).then((result) => {
                    output.innerHTML = '';
                    if (!result.success) { output.textContent = result.data; return; }
                    const { json, warnings, testUrl } = result.data;
                    if (warnings.length) {
                        output.append(h('ul', { className: 'ddwpt-schema-warnings' }, warnings.map((warning) => h('li', {}, warning))));
                    }
                    output.append(h('pre', { className: 'ddwpt-schema-preview-json' }, json));
                    test.href   = testUrl;
                    test.hidden = false;
                });
            };

            request('ddwpt_schema_posts', { post_type: this.slug }).then((result) => {
                posts.innerHTML = '';
                const items = result.success ? result.data : [];
                if (!items.length) posts.append(h('option', { value: '' }, 'No published posts'));
                items.forEach((post) => posts.append(h('option', { value: post.id }, post.title)));
                if (this.previewPost && items.some((post) => String(post.id) === String(this.previewPost))) posts.value = this.previewPost;
                render();
            });

            return h('div', { className: 'ddwpt-schema-preview' },
                h('div', { className: 'ddwpt-schema-preview-controls' }, posts, button('Refresh', render), test),
                output);
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
