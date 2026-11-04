export function initResponsiveNavigation(root = document) {
    const toggle = root.getElementById('mobileMenuToggle');
    const menu = root.getElementById('mobileNavigation');

    if (toggle && menu) {
        const desktop = window.matchMedia('(min-width: 1280px)');
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
        let closingAnimation = null;
        let restoreFocusAfterClose = true;

        const close = (restoreFocus = true, immediately = false) => {
            if (!menu.open) return;
            restoreFocusAfterClose = restoreFocus;
            toggle.setAttribute('aria-expanded', 'false');

            if (immediately || reducedMotion.matches || !menu.animate) {
                menu.close();
                return;
            }
            if (closingAnimation) return;

            const animation = menu.animate([
                { transform: window.getComputedStyle(menu).transform },
                { transform: 'translateX(100%)' },
            ], { duration: 200, easing: 'ease-in', fill: 'forwards' });
            closingAnimation = animation;
            animation.finished.then(() => {
                if (closingAnimation === animation && menu.open) menu.close();
            }, () => {});
        };

        const open = () => {
            if (desktop.matches) return;
            closingAnimation?.cancel();
            closingAnimation = null;
            restoreFocusAfterClose = true;
            if (!menu.open) menu.showModal();
            toggle.setAttribute('aria-expanded', 'true');
            root.body.classList.add('responsive-drawer-open');
        };

        toggle.addEventListener('click', () => {
            menu.open && !closingAnimation ? close() : open();
        });
        menu.addEventListener('click', (event) => {
            if (event.target.closest('[data-close-navigation]')) close();
            if (event.target.closest('a')) close(false, true);

            if (event.target === menu) {
                const bounds = menu.getBoundingClientRect();
                if (event.clientX < bounds.left || event.clientX > bounds.right ||
                    event.clientY < bounds.top || event.clientY > bounds.bottom) close();
            }
        });
        menu.addEventListener('cancel', (event) => {
            event.preventDefault();
            close();
        });
        menu.addEventListener('keydown', (event) => {
            if (event.key !== 'Tab') return;
            const controls = menu.querySelectorAll('a[href], button:not([disabled])');
            const first = controls[0];
            const last = controls[controls.length - 1];
            if (event.shiftKey && root.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && root.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        });
        menu.addEventListener('close', () => {
            if (menu.open) return;
            closingAnimation?.cancel();
            closingAnimation = null;
            root.body.classList.remove('responsive-drawer-open');
            toggle.setAttribute('aria-expanded', 'false');
            if (restoreFocusAfterClose && !desktop.matches) toggle.focus({ preventScroll: true });
        });
        desktop.addEventListener('change', () => {
            if (!desktop.matches) return;
            const focusWasInMenu = menu.contains(root.activeElement);
            close(false, true);
            if (focusWasInMenu) root.querySelector('.responsive-desktop-navigation a')?.focus();
        });
    }

    const filters = root.querySelector('[data-responsive-filters]');
    if (filters) {
        const wideLayout = window.matchMedia('(min-width: 1024px)');
        const update = () => { filters.open = wideLayout.matches; };
        update();
        wideLayout.addEventListener('change', update);
    }
}
