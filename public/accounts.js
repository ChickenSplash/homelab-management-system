// Progressive enhancement: only opted-in links swap the content area. Forms remain native.
(() => {
    let pending;
    const authPaths = ['/login', '/register', '/guest'];
    const preferenceKey = 'hms.auth-tab';

    function preferredTab() {
        try {
            const saved = localStorage.getItem(preferenceKey);
            return authPaths.includes(saved) ? saved : '/guest';
        } catch {
            return '/guest';
        }
    }

    function rememberTab() {
        const active = document.querySelector('main .tabs a[aria-current="page"]');
        if (!active) return;
        const path = new URL(active.href).pathname;
        if (!authPaths.includes(path)) return;
        try {
            localStorage.setItem(preferenceKey, path);
        } catch {
            // Navigation still works when browser storage is unavailable.
        }
    }

    async function navigate(url, fromHistory = false, replace = false) {
        pending?.abort();
        const controller = new AbortController();
        pending = controller;
        const current = document.querySelector('#page-content');
        current.setAttribute('aria-busy', 'true');

        try {
            const options = {
                signal: controller.signal,
                credentials: 'same-origin',
                headers: { Accept: 'text/html' },
            };
            let response = await fetch(url, options);
            // /profile redirects signed-out visitors to /login. Resolve their preference
            // before displaying it, without interfering with explicit sign-in tab clicks.
            const destination = new URL(response.url);
            if (destination.origin === location.origin && destination.pathname === '/login'
                && !authPaths.includes(new URL(url, location.href).pathname)) {
                destination.pathname = preferredTab();
                response = await fetch(destination.href, options);
            }
            if (!response.ok || new URL(response.url).origin !== location.origin) {
                throw new Error('Navigation requires a full page load.');
            }
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const content = page.querySelector('#page-content');
            if (!content || controller.signal.aborted) {
                if (controller.signal.aborted) return;
                throw new Error('Missing page content.');
            }

            current.replaceWith(content);
            document.title = page.title;
            document.querySelectorAll('.site-nav a').forEach((link) => {
                const next = page.querySelector(`[data-section="${link.dataset.section}"]`);
                if (next) link.querySelector('span').textContent = next.querySelector('span').textContent;
                if (next?.hasAttribute('aria-current')) link.setAttribute('aria-current', 'page');
                else link.removeAttribute('aria-current');
            });
            if (replace || fromHistory) history.replaceState(null, '', response.url);
            else history.pushState(null, '', response.url);
            rememberTab();
            window.scrollTo(0, 0);
            (content.querySelector('[autofocus]') || content).focus({ preventScroll: true });
        } catch (error) {
            if (error.name !== 'AbortError') location.assign(url);
        } finally {
            if (pending === controller) {
                pending = null;
                document.querySelector('#page-content').removeAttribute('aria-busy');
            }
        }
    }

    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[data-navigate]');
        if (!link || event.defaultPrevented || event.button !== 0
            || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey
            || link.hasAttribute('download') || (link.target && link.target !== '_self')) return;
        const url = new URL(link.href);
        if (url.origin !== location.origin || url.hash) return;
        event.preventDefault();
        navigate(url.href);
    });

    window.addEventListener('popstate', () => navigate(location.href, true));

    // /login is also the entry point used by the apps and after logout. Keep its
    // return query intact, and replace history so Back never hits a redirect loop.
    if (location.pathname === '/login' && document.querySelector('main .tabs')) {
        const destination = new URL(location.href);
        destination.pathname = preferredTab();
        if (destination.pathname !== location.pathname) navigate(destination.href, false, true);
        else rememberTab();
    } else {
        rememberTab();
    }
})();
