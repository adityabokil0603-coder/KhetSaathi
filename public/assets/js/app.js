// Shared, dependency-free interactions for KhetSaathi.
document.addEventListener('DOMContentLoaded', () => {
    // Native confirm dialogs support keyboard and screen-reader interaction.
    // Disable the clicked button after confirmation to reduce double submits.
    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirm || 'Are you sure you want to continue?')) {
                event.preventDefault();
                return;
            }

            const button = form.querySelector('button[type="submit"]');
            if (button) {
                button.disabled = true;
                button.textContent = 'Working…';
            }
        });
    });

    const sidebar = document.querySelector('.sidebar');
    const menuButton = document.querySelector('.nav-toggle');

    if (sidebar && menuButton) {
        const closeMenu = () => {
            sidebar.classList.remove('sidebar--nav-open');
            menuButton.setAttribute('aria-expanded', 'false');
            menuButton.setAttribute('aria-label', 'Open navigation menu');
        };

        menuButton.setAttribute('aria-label', 'Open navigation menu');
        document.body.classList.add('nav-enhanced');
        menuButton.addEventListener('click', () => {
            const isOpen = sidebar.classList.toggle('sidebar--nav-open');
            menuButton.setAttribute('aria-expanded', String(isOpen));
            menuButton.setAttribute('aria-label', isOpen ? 'Close navigation menu' : 'Open navigation menu');
        });

        sidebar.querySelectorAll('.nav-link').forEach((link) => link.addEventListener('click', closeMenu));
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && sidebar.classList.contains('sidebar--nav-open')) {
                closeMenu();
                menuButton.focus();
            }
        });
    }

    // Filter only rows/cards already rendered from the server; this does not alter database data.
    document.querySelectorAll('[data-list-filter]').forEach((input) => {
        const targetSelector = input.dataset.listFilter;
        const entries = targetSelector ? document.querySelectorAll(targetSelector) : [];
        const count = input.closest('.list-filter')?.querySelector('[data-filter-count]');

        const updateList = () => {
            const query = input.value.trim().toLocaleLowerCase();
            let visibleCount = 0;

            entries.forEach((entry) => {
                const matches = entry.textContent.toLocaleLowerCase().includes(query);
                entry.hidden = !matches;
                if (matches) visibleCount++;
            });

            if (count) {
                count.textContent = query
                    ? (visibleCount === 0 ? 'No matches' : `${visibleCount} shown`)
                    : `${visibleCount} records`;
            }
        };

        input.addEventListener('input', updateList);
        updateList();
    });
});
