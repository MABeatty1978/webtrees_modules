/**
 * Family tree chatbot: the chat panel.
 *
 * The conversation is kept in sessionStorage, so it continues as the visitor
 * moves between pages, and ends when they close the browser tab.
 */
(function () {
    'use strict';

    const configElement = document.getElementById('wtcb-config');

    if (!configElement) {
        return;
    }

    const config      = JSON.parse(configElement.textContent);
    const launch      = document.getElementById('wtcb-launch');
    const panel       = document.getElementById('wtcb-panel');
    const messages    = document.getElementById('wtcb-messages');
    const suggestions = document.getElementById('wtcb-suggestions');
    const form        = document.getElementById('wtcb-form');
    const input       = document.getElementById('wtcb-input');
    const send        = document.getElementById('wtcb-send');

    // Earlier messages sent with each question, so that follow-up questions make sense.
    const HISTORY_LENGTH = 6;

    // Messages kept in storage, for redisplay on the next page.
    const STORED_LENGTH = 50;

    let state = load();
    let busy  = false;

    function load () {
        try {
            const saved = JSON.parse(sessionStorage.getItem(config.storage) || 'null');

            if (saved && Array.isArray(saved.messages)) {
                return saved;
            }
        } catch (error) {
            // Storage can be unavailable, e.g. in some private browsing modes.
        }

        return { messages: [], focus: '', open: false };
    }

    function save () {
        state.messages = state.messages.slice(-STORED_LENGTH);

        try {
            sessionStorage.setItem(config.storage, JSON.stringify(state));
        } catch (error) {
            // The conversation just won't survive a page change.
        }
    }

    function escapeHtml (text) {
        return text.replace(/[&<>"']/g, (character) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        })[character]);
    }

    // Only link to pages on this website.
    function safeHref (url) {
        try {
            const parsed = new URL(url, window.location.href);

            return parsed.origin === window.location.origin ? parsed.href : '';
        } catch (error) {
            return '';
        }
    }

    function emphasis (html) {
        return html
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            .replace(/(^|[^*\w])\*([^*\s][^*]*)\*(?!\w)/g, '$1<em>$2</em>');
    }

    // Markdown links [label](url) and bare URLs.  Everything else is escaped.
    function formatLine (text) {
        const pattern = /\[([^\]\n]+)\]\(([^)\s]+)\)|(https?:\/\/[^\s<>()]+[^\s<>().,;:!?'"])/g;
        let html = '';
        let last = 0;
        let match;

        while ((match = pattern.exec(text)) !== null) {
            html += emphasis(escapeHtml(text.slice(last, match.index)));

            const label = match[1] !== undefined ? match[1] : match[3];
            const href  = safeHref(match[1] !== undefined ? match[2] : match[3]);

            html += href === ''
                ? emphasis(escapeHtml(label))
                : '<a href="' + escapeHtml(href) + '">' + emphasis(escapeHtml(label)) + '</a>';

            last = pattern.lastIndex;
        }

        return html + emphasis(escapeHtml(text.slice(last)));
    }

    function formatMessage (text) {
        return text
            .trim()
            .split(/\n{2,}/)
            .map((paragraph) => '<p>' + paragraph.split('\n').map(formatLine).join('<br>') + '</p>')
            .join('');
    }

    /**
     * @param {string} role "user", "assistant" or "notice" (errors, which are not part of the conversation)
     */
    function showMessage (role, text) {
        const element = document.createElement('div');

        element.className = 'wtcb-message wtcb-message-' + role;

        if (role === 'user') {
            element.textContent = text;
        } else {
            element.innerHTML = formatMessage(text);
        }

        messages.appendChild(element);
        messages.scrollTop = messages.scrollHeight;
    }

    function addMessage (role, text) {
        showMessage(role, text);
        state.messages.push({ role: role, text: text });
        save();
        showSuggestions();
    }

    function showSuggestions () {
        suggestions.textContent = '';
        suggestions.hidden = state.messages.length > 0;

        if (suggestions.hidden) {
            return;
        }

        const questions = config.page
            ? ['Tell me about ' + config.page.name, 'Who were ' + config.page.name + '’s parents?']
            : ['What can you help me with?'];

        questions.forEach((question) => {
            const button = document.createElement('button');

            button.type = 'button';
            button.className = 'wtcb-suggestion';
            button.textContent = question;
            button.addEventListener('click', () => ask(question));
            suggestions.appendChild(button);
        });
    }

    function render () {
        messages.textContent = '';
        showMessage('assistant', config.greeting);
        state.messages.forEach((message) => showMessage(message.role, message.text));
        showSuggestions();
    }

    function showTyping () {
        const element = document.createElement('div');

        element.className = 'wtcb-message wtcb-message-assistant wtcb-typing';
        element.setAttribute('aria-label', 'Writing an answer');
        element.innerHTML = '<span class="wtcb-dot"></span><span class="wtcb-dot"></span><span class="wtcb-dot"></span>';
        messages.appendChild(element);
        messages.scrollTop = messages.scrollHeight;

        return element;
    }

    function setBusy (value) {
        busy = value;
        send.disabled = value;
        input.setAttribute('aria-busy', value ? 'true' : 'false');
    }

    function csrfToken () {
        const meta = document.querySelector('meta[name="csrf"]');

        return meta ? meta.getAttribute('content') : '';
    }

    async function ask (text) {
        const question = text.trim();

        if (question === '' || busy) {
            return;
        }

        setBusy(true);

        const history = state.messages.slice(-HISTORY_LENGTH);

        addMessage('user', question);

        const typing = showTyping();
        const body   = new URLSearchParams();

        body.set('question', question);
        body.set('history', JSON.stringify(history));
        body.set('focus', state.focus || '');
        body.set('page', config.page ? config.page.xref : '');

        try {
            const response = await fetch(config.url, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: { 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' }
            });

            // An expired session redirects to an HTML page instead of returning JSON.
            if ((response.headers.get('Content-Type') || '').indexOf('application/json') === -1) {
                throw new Error('expired');
            }

            const data = await response.json();

            typing.remove();

            if (response.ok && data.answer) {
                addMessage('assistant', data.answer);

                if (data.focus) {
                    state.focus = data.focus;
                    save();
                }
            } else {
                showMessage('notice', data.error || 'Sorry, something went wrong. Please try again.');
            }
        } catch (error) {
            typing.remove();
            showMessage('notice', error.message === 'expired'
                ? 'Your session has expired. Please reload the page and ask again.'
                : 'I couldn’t reach the website. Please check your connection and try again.');
        } finally {
            setBusy(false);
            input.focus();
        }
    }

    function open (focusInput) {
        panel.hidden = false;
        launch.setAttribute('aria-expanded', 'true');
        launch.classList.add('wtcb-launch-open');
        messages.scrollTop = messages.scrollHeight;
        state.open = true;
        save();

        if (focusInput) {
            input.focus();
        }
    }

    function close () {
        panel.hidden = true;
        launch.setAttribute('aria-expanded', 'false');
        launch.classList.remove('wtcb-launch-open');
        state.open = false;
        save();
        launch.focus();
    }

    function resize () {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 120) + 'px';
    }

    function submit () {
        const question = input.value;

        input.value = '';
        resize();
        ask(question);
    }

    launch.addEventListener('click', () => (panel.hidden ? open(true) : close()));
    document.getElementById('wtcb-close').addEventListener('click', close);

    document.getElementById('wtcb-reset').addEventListener('click', () => {
        state = { messages: [], focus: '', open: true };
        save();
        render();
        input.focus();
    });

    // Escape closes the panel, unless the visitor is busy elsewhere on the page (e.g. in a form or a dialog).
    document.addEventListener('keydown', (event) => {
        const active = document.activeElement;

        if (event.key === 'Escape' && !panel.hidden && (panel.contains(active) || active === launch || active === document.body)) {
            close();
        }
    });

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        submit();
    });

    // Enter sends; Shift+Enter starts a new line.
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            submit();
        }
    });

    input.addEventListener('input', resize);

    render();

    if (state.open) {
        open(false);
    }
})();
