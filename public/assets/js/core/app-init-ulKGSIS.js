import '../app.js';

import './csrf.js';
import './dom.js';
import './http-client.js';
import './modal.js';
import './notifications.js';
import './theme.js';

import { initBaseLayout } from '../layout/base-layout.js';
import { initFooter } from '../layout/footer.js';
import { initHeader } from '../layout/header.js';
import { initSidebar } from '../layout/sidebar.js';

let initialized = false;

export function initApp(document = globalThis.document) {
    if (initialized || !document) {
        return;
    }

    const init = () => {
        if (initialized) {
            return;
        }

        initialized = true;

        initBaseLayout(document);
        initHeader(document);
        initSidebar(document);
        initFooter(document);

        document.documentElement.classList.add('app-ready');

        document.dispatchEvent(
            new CustomEvent('wazebr:ready')
        );
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
}
