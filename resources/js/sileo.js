import { createElement } from 'react';
import { createRoot } from 'react-dom/client';
import { Toaster, sileo } from 'sileo';

function mountSileo() {
    const host = document.getElementById('sileo-root');

    if (! host || host.dataset.mounted === 'true') {
        return;
    }

    host.dataset.mounted = 'true';

    createRoot(host).render(
        createElement(Toaster, {
            position: 'top-right',
            theme: 'system',
            offset: 20,
        }),
    );

    window.sileo = sileo;

    window.notify = (options = {}) => {
        const title = options.heading ?? options.title ?? 'Monitor BCV';
        const description = options.text ?? options.description ?? '';
        const payload = { title, description };
        const variant = options.variant ?? options.type ?? 'info';

        if (variant === 'success') {
            return sileo.success(payload);
        }

        if (variant === 'danger' || variant === 'error') {
            return sileo.error(payload);
        }

        if (variant === 'warning') {
            return sileo.warning(payload);
        }

        return sileo.info(payload);
    };
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mountSileo, { once: true });
} else {
    mountSileo();
}

document.addEventListener('livewire:navigated', mountSileo);
