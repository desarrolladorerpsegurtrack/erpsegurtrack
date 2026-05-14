import './bootstrap.js';

import '../tailwise/js/vendors/dom.js';
import '../tailwise/js/vendors/tailwind-merge.js';
import '../tailwise/js/vendors/lucide.js';
import '../tailwise/js/vendors/dayjs.js';
import '../tailwise/js/vendors/tom-select.js';
import '../tailwise/js/vendors/tiny-slider.js';
import '../tailwise/js/vendors/popper.js';
import '../tailwise/js/vendors/dropdown.js';
import '../tailwise/js/vendors/tippy.js';
import '../tailwise/js/vendors/simplebar.js';
import '../tailwise/js/vendors/transition.js';
import '../tailwise/js/vendors/chartjs.js';
import '../tailwise/js/vendors/modal.js';

import '../tailwise/js/components/base/theme-color.js';
import '../tailwise/js/components/base/lucide.js';
import '../tailwise/js/components/base/litepicker.js';
import '../tailwise/js/components/base/tom-select.js';
import '../tailwise/js/components/base/tiny-slider.js';
import '../tailwise/js/components/base/tippy.js';

import '../tailwise/js/utils/colors.js';
import '../tailwise/js/utils/helper.js';

import '../tailwise/js/components/report-line-chart.js';
import '../tailwise/js/components/report-donut-chart-3.js';
import '../tailwise/js/components/quick-search.js';

import '../tailwise/js/themes/dagger.js';

const FULLSCREEN_STORAGE_KEY = 'erp_fullscreen_enabled';

const requestFullscreen = async () => {
    const element = document.documentElement;
    if (element.requestFullscreen) return element.requestFullscreen();
    if (element.webkitRequestFullscreen) return element.webkitRequestFullscreen();
};

const exitFullscreen = async () => {
    if (document.exitFullscreen) return document.exitFullscreen();
    if (document.webkitExitFullscreen) return document.webkitExitFullscreen();
};

const isFullscreenActive = () => {
    return Boolean(document.fullscreenElement || document.webkitFullscreenElement);
};

const updateFullscreenButton = (button) => {
    if (!button) return;

    const icon = button.querySelector('[data-lucide]');
    const active = isFullscreenActive();

    button.setAttribute('aria-label', active ? 'Salir de pantalla completa' : 'Pantalla completa');
    button.setAttribute('title', active ? 'Salir de pantalla completa' : 'Pantalla completa');

    if (icon) {
        icon.setAttribute('data-lucide', active ? 'shrink' : 'expand');
        if (typeof window.createIcons === 'function' && window.icons) {
            window.createIcons({ icons: window.icons, nameAttr: 'data-lucide' });
        }
    }
};

const bindFullscreenToggle = () => {
    const button = document.querySelector('.request-full-screen');
    if (!button) return;

    updateFullscreenButton(button);

    button.addEventListener('click', async (event) => {
        event.preventDefault();
        try {
            if (isFullscreenActive()) {
                await exitFullscreen();
                localStorage.setItem(FULLSCREEN_STORAGE_KEY, '0');
            } else {
                await requestFullscreen();
                localStorage.setItem(FULLSCREEN_STORAGE_KEY, '1');
            }
        } catch {
            // Some browsers may block fullscreen without explicit user gesture context.
        } finally {
            updateFullscreenButton(button);
        }
    });

    document.addEventListener('fullscreenchange', () => updateFullscreenButton(button));
    document.addEventListener('webkitfullscreenchange', () => updateFullscreenButton(button));

    if (localStorage.getItem(FULLSCREEN_STORAGE_KEY) === '1' && !isFullscreenActive()) {
        // Best effort: browsers may block this on page load.
        setTimeout(async () => {
            try {
                await requestFullscreen();
            } catch {
                // Ignore if blocked.
            } finally {
                updateFullscreenButton(button);
            }
        }, 0);
    }
};

document.addEventListener('DOMContentLoaded', bindFullscreenToggle);
