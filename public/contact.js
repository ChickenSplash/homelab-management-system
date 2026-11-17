// Delegated listeners also work after a partial page navigation.
(() => {
    document.addEventListener('click', async (event) => {
        const button = event.target.closest('button[data-copy]');
        if (!button) return;
        const status = button.closest('.contact-section').querySelector('.contact-copy-status');
        try {
            await navigator.clipboard.writeText(button.dataset.copy);
            button.classList.add('is-copied');
            status.textContent = button.getAttribute('aria-label') === 'Copy email address'
                ? 'Email address copied.' : 'Phone number copied.';
            clearTimeout(button.copyTimer);
            button.copyTimer = setTimeout(() => button.classList.remove('is-copied'), 1800);
        } catch {
            status.textContent = 'Could not copy. Select and copy the contact details above.';
        }
    });

    document.addEventListener('submit', async (event) => {
        const form = event.target.closest('.contact-form');
        if (!form) return;
        event.preventDefault();
        const button = form.querySelector('button[type="submit"]');
        if (button.disabled) return;
        const status = form.querySelector('.contact-form-status');
        const say = (message, error = false) => {
            status.textContent = message;
            status.classList.toggle('error', error);
            status.classList.toggle('muted', !error);
        };
        form.querySelectorAll('[aria-invalid]').forEach(field => field.removeAttribute('aria-invalid'));
        button.disabled = true;
        button.textContent = 'Sending…';
        say('');

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                body: new FormData(form),
            });
            if (response.status === 201) {
                form.reset();
                ['email', 'subject', 'message', 'website'].forEach(name => { form.elements.namedItem(name).value = ''; });
                say('Thanks, your message has been received.');
            } else if (response.status === 422) {
                const { errors = {} } = await response.json();
                Object.keys(errors).forEach(name => form.elements.namedItem(name)?.setAttribute('aria-invalid', 'true'));
                form.querySelector('[aria-invalid]')?.focus();
                say(Object.values(errors)[0]?.[0] || 'Please check the form.', true);
            } else if (response.status === 429) {
                say('Too many messages. Wait a minute and try again.', true);
            } else if (response.status === 419) {
                say('This form has expired. Reload the page and try again.', true);
            } else {
                throw new Error('Message could not be sent.');
            }
        } catch {
            say('Could not confirm delivery. Your message may have been received. You can email me directly instead.', true);
        } finally {
            button.disabled = false;
            button.textContent = 'Send message';
        }
    });
})();
