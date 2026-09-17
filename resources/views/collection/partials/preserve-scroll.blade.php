<script data-collection-scroll-position>
    document.addEventListener('DOMContentLoaded', () => {
        const scrollKey = `collection.scrollY:${window.location.pathname}${window.location.search}`;

        const saveScrollPosition = () => {
            try {
                window.sessionStorage.setItem(scrollKey, String(window.scrollY));
            } catch (error) {
                // The page should keep working when browser storage is unavailable.
            }
        };

        try {
            const savedScrollY = window.sessionStorage.getItem(scrollKey);

            if (savedScrollY !== null) {
                window.sessionStorage.removeItem(scrollKey);

                window.requestAnimationFrame(() => {
                    window.scrollTo(0, Number(savedScrollY) || 0);
                });
            }
        } catch (error) {
            // The browser's normal scroll restoration remains as a fallback.
        }

        document.querySelectorAll('form').forEach((form) => {
            form.addEventListener('submit', saveScrollPosition);
        });

        window.addEventListener('pagehide', saveScrollPosition);
    });
</script>
