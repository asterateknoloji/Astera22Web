(() => {
    const root = document.documentElement;
    const rail = document.getElementById('appRail');
    const backdrop = document.querySelector('.rail-backdrop');
    const toggle = document.querySelector('[data-rail-toggle]');
    if (!rail || !toggle) return;

    const drawerQuery = window.matchMedia('(max-width: 1023.98px)');
    const storageKey = 'astera-rail-collapsed';

    const setDrawer = (open) => {
        root.classList.toggle('rail-open', open);
        if (backdrop) backdrop.hidden = !open;
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) rail.querySelector('a, summary, button')?.focus({ preventScroll: true });
    };

    const setCollapsed = (collapsed) => {
        root.classList.toggle('rail-collapsed', collapsed);
        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        try { localStorage.setItem(storageKey, collapsed ? '1' : '0'); } catch (e) {}
    };

    const syncMode = () => {
        if (drawerQuery.matches) {
            setDrawer(false);
        } else {
            root.classList.remove('rail-open');
            if (backdrop) backdrop.hidden = true;
            toggle.setAttribute('aria-expanded', root.classList.contains('rail-collapsed') ? 'false' : 'true');
        }
    };

    toggle.addEventListener('click', () => {
        if (drawerQuery.matches) {
            setDrawer(!root.classList.contains('rail-open'));
        } else {
            setCollapsed(!root.classList.contains('rail-collapsed'));
        }
    });

    document.querySelectorAll('[data-rail-close]').forEach((element) => {
        element.addEventListener('click', () => setDrawer(false));
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && root.classList.contains('rail-open')) {
            setDrawer(false);
            toggle.focus();
        }
    });

    rail.querySelectorAll('.nav-group > summary').forEach((summary) => {
        summary.addEventListener('click', (event) => {
            if (!drawerQuery.matches && root.classList.contains('rail-collapsed')) {
                event.preventDefault();
                setCollapsed(false);
                summary.parentElement.open = true;
            }
        });
    });

    rail.querySelector('nav a.active, nav a[aria-current="page"]')?.scrollIntoView({ block: 'nearest' });

    drawerQuery.addEventListener('change', syncMode);
    syncMode();
})();
