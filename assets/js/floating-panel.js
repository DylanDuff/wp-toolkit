(function () {
    'use strict';

    const TRIGGER_BOTTOM = 24;
    const TRIGGER_H = 40;
    const GAP = 8;

    const OPEN_ON_HOVER = window.ddwptPanelOpenOnHover !== false;

    let activeTab = 'overview';
    let hoverCloseTimer = null;
    let historyFilter = 'all';

    const trigger = document.getElementById('ddwpt-trigger');
    const triggerRow = document.getElementById('ddwpt-trigger-row');
    const panel = document.getElementById('ddwpt-panel');
    const tabBar = document.getElementById('ddwpt-tab-bar');

    if (!trigger || !panel || !tabBar || !triggerRow) return;

    function getPaneHeight(name) {
        const pane = document.getElementById('ddwpt-pane-' + name);
        const prev = pane.style.cssText;
        pane.style.cssText = 'position:absolute;visibility:hidden;height:auto;overflow:visible;';
        const h = pane.scrollHeight;
        pane.style.cssText = prev;
        return h;
    }

    function getHeaderHeight() {
        const header = panel.querySelector('.ddwpt-panel-header');
        return header ? header.offsetHeight : 0;
    }

    function getLeftAlign() {
        const vw = window.innerWidth;
        const panelW = Math.min(480, vw - 32);
        return Math.max(16, (vw - panelW) / 2);
    }

    function applyLayout(animate) {
        if (!animate) {
            panel.style.transition = 'none';
            tabBar.style.transition = 'none';
        }

        const paneH = getPaneHeight(activeTab);
        const headerH = getHeaderHeight();
        const totalPanelH = headerH + paneH;
        const panelBottom = TRIGGER_BOTTOM + TRIGGER_H + GAP;
        const tabBottom = panelBottom + totalPanelH + GAP;

        panel.style.bottom = panelBottom + 'px';
        panel.style.height = totalPanelH + 'px';
        tabBar.style.bottom = tabBottom + 'px';
        tabBar.style.left = getLeftAlign() + 'px';

        if (!animate) {
            panel.offsetHeight; // force reflow
            panel.style.transition = '';
            tabBar.style.transition = '';
        }
    }

    function showPane(name) {
        panel.querySelectorAll('.ddwpt-pane').forEach(function (p) {
            p.style.height = '0';
            p.style.overflow = 'hidden';
        });
        const pane = document.getElementById('ddwpt-pane-' + name);
        if (pane) {
            pane.style.height = 'auto';
            pane.style.overflow = 'visible';
        }
    }

    function openPanel() {
        if (panel.classList.contains('visible')) return;
        applyLayout(false);
        showPane(activeTab);
        requestAnimationFrame(function () {
            panel.classList.add('visible');
            tabBar.classList.add('visible');
            trigger.classList.add('open');
        });
    }

    function closePanel() {
        panel.classList.remove('visible');
        tabBar.classList.remove('visible');
        trigger.classList.remove('open');
    }

    function togglePanel() {
        panel.classList.contains('visible') ? closePanel() : openPanel();
    }

    function closeAllPopovers() {
        panel.querySelectorAll('.ddwpt-pchip.open').forEach(function (el) {
            el.classList.remove('open');
        });
    }

    // Tab switching
    tabBar.addEventListener('click', function (e) {
        const btn = e.target.closest('.ddwpt-tab-btn');
        if (!btn) return;

        const name = btn.dataset.tab;
        if (name === activeTab) return;

        tabBar.querySelectorAll('.ddwpt-tab-btn').forEach(function (b) {
            b.classList.remove('active');
        });
        btn.classList.add('active');
        activeTab = name;

        const paneH = getPaneHeight(name);
        const headerH = getHeaderHeight();
        const totalPanelH = headerH + paneH;
        const panelBottom = TRIGGER_BOTTOM + TRIGGER_H + GAP;
        const tabBottom = panelBottom + totalPanelH + GAP;

        showPane(name);

        if (name === 'history') {
            loadHistory();
        }

        requestAnimationFrame(function () {
            panel.style.height = totalPanelH + 'px';
            tabBar.style.bottom = tabBottom + 'px';
            tabBar.style.left = getLeftAlign() + 'px';
        });
    });

    // Global click: popovers + outside-click close
    document.addEventListener('click', function (e) {
        const filterBtn = e.target.closest('.ddwpt-hist-filter-btn');
        if (filterBtn) {
            filterBtn.closest('.ddwpt-hist-filter')
                .querySelectorAll('.ddwpt-hist-filter-btn')
                .forEach(function (b) { b.classList.remove('active'); });
            filterBtn.classList.add('active');
            historyFilter = filterBtn.dataset.filter || 'all';
            loadHistory();
            return;
        }

        const chip = e.target.closest('.ddwpt-pchip');
        if (chip && panel.contains(chip)) {
            const isOpen = chip.classList.contains('open');
            closeAllPopovers();
            if (!isOpen) chip.classList.add('open');
            e.stopPropagation();
            return;
        }

        closeAllPopovers();

        if (!OPEN_ON_HOVER) {
            if (!panel.contains(e.target) && !tabBar.contains(e.target) && !triggerRow.contains(e.target)) {
                closePanel();
            }
        }
    });

    // Hover / click setup
    if (OPEN_ON_HOVER) {
        triggerRow.addEventListener('mouseenter', function () {
            clearTimeout(hoverCloseTimer);
            openPanel();
        });
        triggerRow.addEventListener('mouseleave', function () {
            hoverCloseTimer = setTimeout(closePanel, 150);
        });
        [panel, tabBar].forEach(function (el) {
            el.addEventListener('mouseenter', function () { clearTimeout(hoverCloseTimer); });
            el.addEventListener('mouseleave', function () {
                hoverCloseTimer = setTimeout(closePanel, 150);
            });
        });
        trigger.addEventListener('click', togglePanel);
    } else {
        trigger.addEventListener('click', togglePanel);
    }

    window.addEventListener('resize', function () {
        if (panel.classList.contains('visible')) {
            applyLayout(false);
        }
    });

    var histReload = document.getElementById('ddwpt-hist-reload');
    if (histReload) {
        histReload.addEventListener('click', loadHistory);
    }

    // ===== History =====

    function escHtml(str) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(String(str)));
        return d.innerHTML;
    }

    function relativeTime(dateStr) {
        var diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000);
        if (diff < 60) return 'just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        var h = Math.floor(diff / 3600);
        if (diff < 86400) return h + ' hour' + (h > 1 ? 's' : '') + ' ago';
        var d = Math.floor(diff / 86400);
        return d + ' day' + (d > 1 ? 's' : '') + ' ago';
    }

    function renderHistoryItems(events) {
        return events.map(function (ev) {
            var isWp = ev.initiator === 'wp' || ev.initiator === 'wp_cron';
            var actor = isWp
                ? 'WordPress'
                : (ev.initiator_data && ev.initiator_data.user_login ? ev.initiator_data.user_login : 'System');
            var subsequent = (ev.subsequent_occasions_count || 0) - 1;
            return '<div class="ddwpt-hist-item">' +
                '<div class="ddwpt-hist-timeline">' +
                    '<div class="ddwpt-hist-dot' + (isWp ? ' ddwpt-hist-dot-wp' : '') + '"></div>' +
                    '<div class="ddwpt-hist-line"></div>' +
                '</div>' +
                '<div class="ddwpt-hist-body">' +
                    '<div class="ddwpt-hist-meta">' +
                        '<span class="ddwpt-hist-actor">' + escHtml(actor) + '</span>' +
                        '<span class="ddwpt-hist-sep">·</span>' +
                        '<span class="ddwpt-hist-time">' + escHtml(relativeTime(ev.date_local || ev.date_gmt)) + '</span>' +
                    '</div>' +
                    '<div class="ddwpt-hist-desc">' + (ev.message_html || escHtml(ev.message || '')) + '</div>' +
                    (subsequent > 0 ? '<span class="ddwpt-hist-similar">+' + subsequent + ' similar event' + (subsequent > 1 ? 's' : '') + '</span>' : '') +
                '</div>' +
            '</div>';
        }).join('');
    }

    function loadHistory() {
        var cfg = window.ddwptHistoryConfig;
        var listEl = document.getElementById('ddwpt-hist-list');
        if (!cfg || !listEl) return;

        listEl.innerHTML = '<div class="ddwpt-hist-loading">' +
            '<svg class="ddwpt-spin" viewBox="0 0 24 24" fill="none">' +
                '<circle cx="12" cy="12" r="9" stroke="rgba(255,255,255,0.12)" stroke-width="2"/>' +
                '<path d="M12 3a9 9 0 0 1 9 9" stroke="rgba(255,255,255,0.45)" stroke-width="2" stroke-linecap="round"/>' +
            '</svg></div>';

        var url = cfg.apiUrl + '?per_page=15&skip_count_query=true';
        if (historyFilter === 'page' && cfg.postId) {
            url += '&context_filters%5Bpost_id%5D=' + encodeURIComponent(cfg.postId);
        }

        fetch(url, { headers: { 'X-WP-Nonce': cfg.nonce } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!Array.isArray(data) || !data.length) {
                    listEl.innerHTML = '<div class="ddwpt-hist-empty">No events found.</div>';
                    return;
                }
                listEl.innerHTML = renderHistoryItems(data);
            })
            .catch(function () {
                listEl.innerHTML = '<div class="ddwpt-hist-empty">Failed to load activity.</div>';
            });
    }
})();
