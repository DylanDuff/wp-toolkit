<?php

namespace DDWPTweaks\Tweaks;

return [
  "id" => "ddwpt_profile_overhaul",
  "label" => "Profile Page Overhaul",
  "tab" => "general",

  "settings" => [
    [
      "id" => "enabled",
      "type" => "checkbox",
      "label" => "Enable tweak",
      "description" =>
        "Apply a modern single-card accordion layout to the WordPress user profile and user-edit pages.",
    ],
  ],

  "callback" => function ($settings) {
    if (empty($settings["enabled"])) {
      return;
    }

    add_action("admin_head", function () {
      $screen = get_current_screen();
      if (!$screen || !in_array($screen->id, ["profile", "user-edit"], true)) {
        return;
      }?>
            <style id="ddwpt-profile-overhaul">
            /* ── Variables ──────────────────────────────────────────────── */
            body.profile-php,
            body.user-edit-php {
                --ddwpt-accent:       #2271b1;
                --ddwpt-accent-hover: #135e96;
                --ddwpt-accent-light: #f0f6fc;
                --ddwpt-bg:           #f0f0f4;
                --ddwpt-surface:      #ffffff;
                --ddwpt-surface-alt:  #f9fafb;
                --ddwpt-border:       #e5e7eb;
                --ddwpt-text:         #111827;
                --ddwpt-text-muted:   #6b7280;
                --ddwpt-radius:       10px;
            }

            /* ── Page chrome ─────────────────────────────────────────────── */
            body.profile-php #wpcontent,
            body.user-edit-php #wpcontent {
                background: var(--ddwpt-bg);
            }

            body.profile-php #wpbody-content,
            body.user-edit-php #wpbody-content {
                max-width: 1200px;
                margin-inline: auto;
                padding-top: 32px;
            }

            body.profile-php h1,
            body.user-edit-php h1,
            body.profile-php #screen-meta-links,
            body.user-edit-php #screen-meta-links{
                display: none!important;

            }

            /* ── Form ────────────────────────────────────────────────────── */
            #your-profile {
                display: flex;
                flex-direction: column;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
                -webkit-font-smoothing: antialiased;
            }

            /* ── Outer card ──────────────────────────────────────────────── */
            .ddwpt-profile-card {
                background: var(--ddwpt-surface);
                border: 1px solid var(--ddwpt-border);
                border-radius: var(--ddwpt-radius);
                overflow: hidden;
                margin-bottom: 10px;
            }

            /* ── Accordion section ───────────────────────────────────────── */
            .ddwpt-profile-section {
                border-bottom: 1px solid var(--ddwpt-border);
            }

            .ddwpt-profile-section:last-child {
                border-bottom: none;
            }

            /* ── Accordion trigger (card header style) ───────────────────── */
            .ddwpt-profile-trigger {
                display: flex;
                align-items: center;
                justify-content: space-between;
                width: 100%;
                padding: 14px 20px;
                background: none;
                border: none;
                cursor: pointer;
                text-align: left;
                font-family: inherit;
                font-size: 14px;
                font-weight: 600;
                color: var(--ddwpt-text);
                gap: 12px;
                line-height: 1.3;
            }

            .ddwpt-profile-trigger:hover {
                background: var(--ddwpt-surface-alt);
            }

            .ddwpt-profile-trigger svg {
                flex-shrink: 0;
                color: var(--ddwpt-text-muted);
                transition: transform 0.2s ease;
            }

            .ddwpt-profile-section.is-open > .ddwpt-profile-trigger svg {
                transform: rotate(180deg);
            }

            /* ── Accordion body (card body style) ────────────────────────── */
            .ddwpt-profile-body {
                border-top: 1px solid var(--ddwpt-border);
                background: var(--ddwpt-surface-alt);
                padding: 0 24px;
            }

            /* ── Form table reset ────────────────────────────────────────── */
            #your-profile .form-table {
                border-collapse: separate;
                border-spacing: 0;
                width: 100%;
                margin: 0;
            }

            #your-profile .form-table tr {
                display: grid;
                grid-template-columns: 190px 1fr;
                gap: 12px;
                padding: 14px 0;
                border-bottom: 1px solid var(--ddwpt-border);
                align-items: start;
            }

            #your-profile .form-table tr:last-child {
                border-bottom: none;
            }

            #your-profile .form-table th {
                padding: 6px 0 0;
                font-size: 13px;
                font-weight: 500;
                color: var(--ddwpt-text);
                text-align: left;
                width: auto;
            }

            #your-profile .form-table td {
                padding: 0;
                margin: 0;
            }

            /* ── Inputs ──────────────────────────────────────────────────── */
            #your-profile input[type="text"],
            #your-profile input[type="email"],
            #your-profile input[type="url"],
            #your-profile input[type="password"],
            #your-profile textarea,
            #your-profile select {
                border-color: var(--ddwpt-border) !important;
                border-radius: 6px !important;
                font-size: 13px !important;
                box-shadow: none !important;
                max-width: 520px;
                min-height: 36px;
                padding: 6px 10px !important;
                width: 100%;
            }

            #your-profile textarea {
                min-height: 120px;
                resize: vertical;
            }

            #your-profile input[type="text"]:focus,
            #your-profile input[type="email"]:focus,
            #your-profile input[type="url"]:focus,
            #your-profile input[type="password"]:focus,
            #your-profile textarea:focus,
            #your-profile select:focus {
                border-color: var(--ddwpt-accent) !important;
                box-shadow: 0 0 0 1px var(--ddwpt-accent) !important;
                outline: none !important;
            }

            /* ── Descriptions ────────────────────────────────────────────── */
            #your-profile .description {
                font-size: 12px;
                color: var(--ddwpt-text-muted);
                line-height: 1.5;
                margin-top: 6px;
                max-width: 520px;
            }

            /* ── Labels ──────────────────────────────────────────────────── */
            #your-profile label {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                font-size: 13px;
                color: var(--ddwpt-text);
            }

            /* ── Admin color scheme picker ───────────────────────────────── */
            #color-picker {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
                gap: 10px;
                margin-top: 4px;
            }

            #color-picker .color-option {
                border: 1px solid var(--ddwpt-border);
                border-radius: var(--ddwpt-radius);
                padding: 12px;
                background: var(--ddwpt-surface);
                cursor: pointer;
                transition: border-color 0.15s ease, box-shadow 0.15s ease;
                width: 100%;
            }

            #color-picker .color-option:hover {
                border-color: var(--ddwpt-text-muted);
            }

            #color-picker .color-option.selected {
                border-color: var(--ddwpt-accent);
                box-shadow: 0 0 0 1px var(--ddwpt-accent);
            }

            #color-picker .color-palette {
                margin-top: 10px;
                display: flex;
                gap: 3px;
            }

            /* ── Avatar ──────────────────────────────────────────────────── */
            #simple-local-avatar-photo img,
            #your-profile .avatar {
                border-radius: 50%;
                border: 3px solid var(--ddwpt-surface);
                box-shadow: 0 2px 8px rgba(0, 0, 0, .10);
            }

            /* ── Application passwords & passkeys ────────────────────────── */
            #your-profile .application-passwords,
            #your-profile .wp-passkeys-section {
                margin-block: 14px;
                background: var(--ddwpt-surface);
                border: 1px solid var(--ddwpt-border);
                border-radius: var(--ddwpt-radius);
                padding: 14px 20px;
            }

            /* ── Buttons ─────────────────────────────────────────────────── */
            #your-profile .button {
                border-radius: 6px !important;
                font-size: 13px !important;
                font-weight: 500 !important;
                min-height: 36px !important;
                box-shadow: none !important;
            }

            #your-profile .button-primary {
                background: var(--ddwpt-accent) !important;
                border-color: var(--ddwpt-accent) !important;
            }

            #your-profile .button-primary:hover {
                background: var(--ddwpt-accent-hover) !important;
                border-color: var(--ddwpt-accent-hover) !important;
            }

            /* ── Submit bar ──────────────────────────────────────────────── */
            #your-profile .submit {
                display: flex;
                justify-content: flex-end;
                padding: 0 0 16px;
            }

            /* ── Mobile ──────────────────────────────────────────────────── */
            @media (max-width: 782px) {
                #your-profile .form-table tr {
                    grid-template-columns: 1fr;
                    gap: 8px;
                }

                #your-profile input[type="text"],
                #your-profile input[type="email"],
                #your-profile input[type="url"],
                #your-profile input[type="password"],
                #your-profile textarea,
                #your-profile select {
                    max-width: 100%;
                }

                .ddwpt-profile-body {
                    padding-inline: 16px;
                }
            }
            </style>

            <script id="ddwpt-profile-overhaul-js">
            (function () {
                var CHEVRON = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>';

                document.addEventListener('DOMContentLoaded', function () {
                    var form = document.getElementById('your-profile');
                    if (!form) return;

                    var submit  = form.querySelector('.submit');
                    var children = Array.from(form.children);

                    // Group all children into sections, each starting with an h2/h3.
                    // Content before the first heading is dropped (WP boilerplate <p>).
                    var sections = [];
                    var current  = null;

                    children.forEach(function (el) {
                        if (el === submit) return;

                        if (el.matches('h2, h3')) {
                            current = { label: el.textContent.trim(), items: [] };
                            sections.push(current);
                            el.remove();
                            return;
                        }

                        if (current) {
                            current.items.push(el);
                        }
                        // Elements before the first heading are left in place and removed
                        // from the DOM when we move everything else into the card below.
                    });

                    if (!sections.length) return;

                    // Remove any stray pre-heading nodes (e.g. the WP <p> disclaimer).
                    children.forEach(function (el) {
                        if (!el.closest('.ddwpt-profile-card') && el !== submit && el.parentNode === form) {
                            el.remove();
                        }
                    });

                    // Build single card with one accordion section per heading.
                    var card = document.createElement('div');
                    card.className = 'ddwpt-profile-card';

                    sections.forEach(function (section, index) {
                        var wrap = document.createElement('div');
                        wrap.className = 'ddwpt-profile-section' + (index === 1 ? ' is-open' : '');

                        var trigger = document.createElement('button');
                        trigger.type = 'button';
                        trigger.className = 'ddwpt-profile-trigger';
                        trigger.innerHTML = '<span>' + section.label + '</span>' + CHEVRON;

                        var body = document.createElement('div');
                        body.className = 'ddwpt-profile-body';
                        if (index !== 1) body.style.display = 'none';

                        section.items.forEach(function (el) {
                            body.appendChild(el);
                        });

                        trigger.addEventListener('click', function () {
                            var isOpen = wrap.classList.toggle('is-open');
                            body.style.display = isOpen ? '' : 'none';
                        });

                        wrap.appendChild(trigger);
                        wrap.appendChild(body);
                        card.appendChild(wrap);
                    });

                    // Place submit above the card, then append the card.
                    if (submit) {
                        form.insertBefore(submit, form.firstChild);
                    }
                    form.appendChild(card);
                });
            }());
            </script>
            <?php
    });
  },
];
