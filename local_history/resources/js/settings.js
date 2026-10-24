/**
 * Local history: the settings page.
 *
 * There are two repeatable sections — groups and events — each with its own "Add" button, its
 * own <template>, and its own row container, marked up with matching data-* attributes:
 *
 *   <div id="X">...existing rows...</div>
 *   <template id="X-template">...one blank row, with __INDEX__ in every name/id...</template>
 *   <button data-add="X" data-template="X-template" data-count="1" data-next="N">Add…</button>
 *
 * A [data-trigger="some-id"] button just clicks another button for you, e.g. an "Add a group"
 * shortcut placed inside a warning banner, elsewhere on the page.
 */
(function () {
    'use strict';

    // "Copy" buttons next to a read-only textarea (e.g. the AI prompt).
    document.querySelectorAll('[data-copy]').forEach(function (button) {
        const field = document.getElementById(button.getAttribute('data-copy'));

        if (!field) {
            return;
        }

        const label = button.textContent;

        button.addEventListener('click', function () {
            field.select();

            // navigator.clipboard needs a secure context; select() above is the fallback
            // either way, so a visitor can always press Ctrl+C themselves if this fails silently.
            const done = function () {
                button.textContent = 'Copied';
                setTimeout(function () {
                    button.textContent = label;
                }, 1500);
            };

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(field.value).then(done, function () {
                    /* left selected, for Ctrl+C */
                });
            } else {
                try {
                    if (document.execCommand('copy')) {
                        done();
                    }
                } catch (error) {
                    /* left selected, for Ctrl+C */
                }
            }
        });
    });

    document.querySelectorAll('[data-add]').forEach(function (button) {
        const container = document.getElementById(button.getAttribute('data-add'));
        const template   = document.getElementById(button.getAttribute('data-template'));

        if (!container || !template) {
            return;
        }

        const count = parseInt(button.getAttribute('data-count'), 10) || 1;
        let next     = parseInt(button.getAttribute('data-next'), 10) || container.children.length;

        button.addEventListener('click', function () {
            let first = null;
            const added = [];

            for (let i = 0; i < count; i++) {
                const holder = document.createElement('div');

                // The template's field names and ids contain "__INDEX__", which must be
                // different for every row.
                holder.innerHTML = template.innerHTML.split('__INDEX__').join(String(next));
                next++;

                const row = holder.firstElementChild;

                // A brief highlight, so clicking "Add" is obviously doing something, even
                // though the new row can land below the fold.
                row.classList.add('lh-row-new');
                added.push(row);

                container.appendChild(row);
                first = first || row;
            }

            const field = first.querySelector('input:not([type=hidden]), select, textarea');

            if (field) {
                field.focus();
                field.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }

            setTimeout(function () {
                added.forEach(function (row) {
                    row.classList.remove('lh-row-new');
                });
            }, 2000);
        });
    });

    // A button standing in for another one — e.g. the "Add a group" shortcut in the
    // "nobody will see any events yet" warning, which just triggers the real Add button.
    document.querySelectorAll('[data-trigger]').forEach(function (button) {
        const target = document.getElementById(button.getAttribute('data-trigger'));

        if (!target) {
            return;
        }

        button.addEventListener('click', function () {
            target.click();
        });
    });
})();
