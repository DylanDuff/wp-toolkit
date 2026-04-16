<?php

namespace DDWPTweaks\Tweaks;

function toast_styles()
{
    ?>
    <style>
        /* Toast container */
        .ddwpt-toast-container {
            position: fixed;
            bottom: 1rem;
            right: 1rem;
            z-index: 99999;
            display: flex;
            flex-direction: column-reverse;
            align-items: flex-end;
            pointer-events: none;
            max-height: 90vh;
            overflow-y: visible;
            animation: ddwpt-toast-in 0.3s ease;
        }

        .ddwpt-toast-container:hover {
            pointer-events: auto;
            overflow-y: auto;
        }

        /* Individual toast */
        .ddwpt-toast {
            pointer-events: auto;
            width: 380px;
            max-width: 90vw;
            padding: 10px 16px;
            margin: 0;
            border-left-width: 4px;
            border-left-style: solid;
            background: #fff;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12), 0 1px 4px rgba(0, 0, 0, 0.08);
            border-radius: 4px;
            opacity: 1;
            transition: margin-bottom 0.25s ease, opacity 0.25s ease, transform 0.25s ease, z-index 0s;
        }

        .ddwpt-toast p {
            margin: 0.4em 0;
        }

        /* Preserve WP notice border colors */
        .ddwpt-toast.notice-success { border-left-color: #00a32a; }
        .ddwpt-toast.notice-error   { border-left-color: #d63638; }
        .ddwpt-toast.notice-warning { border-left-color: #dba617; }
        .ddwpt-toast.notice-info    { border-left-color: #72aee6; }

        /* Expanded state on hover */
        .ddwpt-toast-container:hover .ddwpt-toast {
            margin-bottom: 8px !important;
            opacity: 1 !important;
            transform: scale(1) !important;
            z-index: auto !important;
        }

        .ddwpt-toast-container:hover .ddwpt-toast:first-child {
            margin-bottom: 0 !important;
        }

        /* Close button */
        .ddwpt-toast-close {
            position: absolute;
            top: 6px;
            right: 8px;
            background: none;
            border: none;
            font-size: 16px;
            cursor: pointer;
            color: #888;
            line-height: 1;
            padding: 2px 4px;
        }

        .ddwpt-toast-close:hover {
            color: #333;
        }

        /* Badge counter */
        .ddwpt-toast-badge {
            position: absolute;
            bottom: 100%;
            right: 0;
            background: #2271b1;
            color: #fff;
            font-size: 11px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 10px;
            margin-bottom: 4px;
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.2s ease;
        }

        .ddwpt-toast-container:not(:hover) .ddwpt-toast-badge {
            opacity: 1;
        }

        /* Slide-in animation */
        @keyframes ddwpt-toast-in {
            from {
                opacity: 0;
                transform: translateX(40px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        /* Slide-out for dismissal */
        .ddwpt-toast.ddwpt-toast-removing {
            opacity: 0;
            transform: translateX(40px);
            pointer-events: none;
        }

        /* Notices are hidden by JS after being converted to toasts.
           This just removes the leftover whitespace gap. */
        .ddwpt-notice-hidden {
            display: none !important;
        }
    </style>
    <?php
}

function toast_script()
{
    ?>
    <script>
    (function () {
        // Build the toast container
        var container = document.createElement('div');
        container.className = 'ddwpt-toast-container';
        document.body.appendChild(container);

        // Badge for notification count
        var badge = document.createElement('div');
        badge.className = 'ddwpt-toast-badge';
        container.appendChild(badge);

        var MAX_VISIBLE = 8;

        function updateBadge() {
            var count = container.querySelectorAll('.ddwpt-toast').length;
            badge.textContent = count + ' notification' + (count !== 1 ? 's' : '');
            badge.style.display = count > 1 ? '' : 'none';
        }

        function applyStacking() {
            var toasts = container.querySelectorAll('.ddwpt-toast');
            toasts.forEach(function (toast, i) {
                if (i === 0) {
                    toast.style.marginBottom = '0';
                    toast.style.opacity = '1';
                    toast.style.transform = 'scale(1)';
                    toast.style.zIndex = '0';
                } else if (i < MAX_VISIBLE) {
                    var factor = i / MAX_VISIBLE;
                    toast.style.marginBottom = '-46px';
                    toast.style.opacity = String(1 - factor * 0.9);
                    toast.style.transform = 'scale(' + (1 - i * 0.01) + ')';
                    toast.style.zIndex = String(-i);
                } else {
                    toast.style.marginBottom = '-46px';
                    toast.style.opacity = '0';
                    toast.style.transform = 'scale(' + (1 - MAX_VISIBLE * 0.01) + ')';
                    toast.style.zIndex = String(-i);
                }
            });
        }

        function makeToast(notice) {
            var toast = document.createElement('div');
            toast.className = 'ddwpt-toast';

            // Carry over the notice type classes
            ['notice-success', 'notice-error', 'notice-warning', 'notice-info', 'updated', 'error'].forEach(function (cls) {
                if (notice.classList.contains(cls)) toast.classList.add(cls);
            });

            // Map legacy classes
            if (notice.classList.contains('updated') && !toast.classList.contains('notice-success')) {
                toast.classList.add('notice-success');
            }
            if (notice.classList.contains('error') && !toast.classList.contains('notice-error')) {
                toast.classList.add('notice-error');
            }

            toast.style.position = 'relative';
            toast.innerHTML = notice.innerHTML;

            // Remove any existing dismiss button from the original notice
            var existingDismiss = toast.querySelector('.notice-dismiss');
            if (existingDismiss) existingDismiss.remove();

            // Add close button
            var close = document.createElement('button');
            close.className = 'ddwpt-toast-close';
            close.innerHTML = '&times;';
            close.setAttribute('aria-label', 'Dismiss');
            close.addEventListener('click', function () {
                toast.classList.add('ddwpt-toast-removing');
                setTimeout(function () {
                    toast.remove();
                    updateBadge();
                    applyStacking();
                }, 250);
            });
            toast.appendChild(close);

            container.insertBefore(toast, badge.nextSibling);
        }

        // Collect all existing notices — broad selectors to catch all positions
        var notices = document.querySelectorAll('.notice, .updated, .error');
        notices.forEach(function (notice) {
            // Skip elements that aren't admin notices
            if (notice.closest('.ddwpt-toast-container')) return;
            if (notice.classList.contains('inline')) return;
            if (notice.classList.contains('hidden')) return;
            if (!notice.textContent.trim()) return;

            makeToast(notice);
            notice.classList.add('ddwpt-notice-hidden');
        });

        updateBadge();
        applyStacking();
    })();
    </script>
    <?php
}

return [
    'id'    => 'ddwpt_toast_notifications',
    'label' => 'Toast Notifications',
    'tab'   => 'notifications',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Convert admin notices into stacking toasts at the bottom right of the screen.',
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        add_action('admin_head', __NAMESPACE__ . '\\toast_styles');
        add_action('admin_footer', __NAMESPACE__ . '\\toast_script');
    },
];
