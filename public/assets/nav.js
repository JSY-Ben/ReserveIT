(function () {
    // Turn the existing app nav into a collapsible menu on small screens.
    const mobileQuery = window.matchMedia('(max-width: 768px)');

    function closeMenu(wrapper, toggle, labelEl) {
        wrapper.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
        labelEl.textContent = 'Menu';
    }

    function enhanceNav(nav) {
        if (!nav || nav.dataset.enhanced === '1') {
            return;
        }
        nav.dataset.enhanced = '1';

        const wrapper = document.createElement('div');
        wrapper.className = 'app-nav-shell has-toggle';

        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'app-nav-toggle';
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-controls', 'app-nav-' + Math.random().toString(36).slice(2));

        const icon = document.createElement('span');
        icon.className = 'app-nav-toggle-icon';
        icon.setAttribute('aria-hidden', 'true');

        const label = document.createElement('span');
        label.className = 'app-nav-toggle-label';
        label.textContent = 'Menu';

        toggle.append(icon, label);

        // Move nav into wrapper and apply ID for aria-controls.
        nav.id = nav.id || 'app-nav-' + Math.random().toString(36).slice(2);
        toggle.setAttribute('aria-controls', nav.id);

        nav.parentNode.insertBefore(wrapper, nav);
        wrapper.appendChild(toggle);
        wrapper.appendChild(nav);

        toggle.addEventListener('click', () => {
            const isOpen = wrapper.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            label.textContent = isOpen ? 'Close menu' : 'Menu';
        });

        // Close the drawer after selecting a link on mobile.
        nav.querySelectorAll('a').forEach((link) => {
            link.addEventListener('click', () => {
                if (mobileQuery.matches) {
                    closeMenu(wrapper, toggle, label);
                }
            });
        });

        // Reset state when resizing back to desktop.
        const handleViewportChange = () => {
            if (!mobileQuery.matches) {
                closeMenu(wrapper, toggle, label);
            }
        };

        if (mobileQuery.addEventListener) {
            mobileQuery.addEventListener('change', handleViewportChange);
        } else if (mobileQuery.addListener) {
            mobileQuery.addListener(handleViewportChange);
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.app-nav').forEach(enhanceNav);
    });
})();
