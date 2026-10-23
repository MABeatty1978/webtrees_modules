/**
 * Local history: the settings page.
 *
 * Adds three more empty events to the form each time the button is clicked.
 */
(function () {
    'use strict';

    const button   = document.getElementById('lh-add');
    const events   = document.getElementById('lh-events');
    const template = document.getElementById('lh-template');

    if (!button || !events || !template) {
        return;
    }

    let next = parseInt(button.getAttribute('data-next'), 10) || events.children.length;

    button.addEventListener('click', function () {
        let first = null;

        for (let i = 0; i < 3; i++) {
            const holder = document.createElement('div');

            // The template's field names and ids contain "__INDEX__", which must be different for every event.
            holder.innerHTML = template.innerHTML.split('__INDEX__').join(String(next));
            next++;

            const event = holder.firstElementChild;

            events.appendChild(event);
            first = first || event;
        }

        first.querySelector('input').focus();
    });
})();
