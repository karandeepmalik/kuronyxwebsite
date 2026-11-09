// Swaps the subject/body fields of the admin email composer (gs-requests and
// veterinary-applications) when staff pick a different predefined template. Templates
// themselves are rendered server-side into #email-template's data-templates attribute
// so the copy always reflects the record's current state - this script only moves
// that text into the editable fields; staff still edit and send manually.
//
// Picking another template used to REPLACE what staff had typed, silently and with no way back (a Karandeep review
// comment: write a careful custom message, open the dropdown to look at an option or pick the wrong one, and it is gone;
// "Blank" cleared the message completely). Now the script remembers the text of the last template it applied. If the boxes
// still match it, nothing was edited and the swap is immediate, as before. If they do not (staff edited them, or the page
// came back with a kept draft), the dropdown goes back to where it was and an inline bar asks "Replace ...?" with
// Replace / Keep mine. After a replace an Undo button puts the previous text and template back, until the next keystroke
// (so Undo can never wipe something typed after it). Inline on purpose, not a browser confirm dialog: a browser can switch those
// dialogs off ("prevent this page from creating additional dialogs"), which would leave the dropdown dead.
(function (root) {
    function norm(s) { return String(s == null ? '' : s).replace(/\r\n/g, '\n'); }

    function init(doc) {
        var select = doc.getElementById('email-template');
        if (!select) return null;
        var templates = JSON.parse(select.getAttribute('data-templates') || '{}');
        var subjectInput = doc.getElementById('email-subject');
        var bodyInput = doc.getElementById('email-body');
        if (!subjectInput || !bodyInput) return null;

        var start = templates[select.value] || { subject: '', body: '' };
        var applied = { subject: start.subject, body: start.body }; // what untouched boxes would hold
        var lastKey = select.value;                                  // the template the boxes were last filled from
        var pendingKey = null;                                       // a template waiting for "Replace"
        var undoState = null;                                        // what "Undo" puts back

        function edited() {
            return norm(subjectInput.value) !== norm(applied.subject) || norm(bodyInput.value) !== norm(applied.body);
        }

        // The bar: one element, text and buttons swapped as needed.
        var bar = doc.createElement('div');
        bar.className = 'alert alert-warn';
        bar.setAttribute('role', 'status');
        bar.setAttribute('aria-live', 'polite');
        bar.setAttribute('id', 'email-template-notice');
        bar.hidden = true;
        var text = doc.createElement('span');
        var primary = doc.createElement('button');
        var secondary = doc.createElement('button');
        [primary, secondary].forEach(function (b) {
            b.setAttribute('type', 'button'); // inside the send form: must never submit it
            b.className = 'btn-secondary btn-sm';
            b.style.marginLeft = '0.75rem';
        });
        bar.appendChild(text);
        bar.appendChild(primary);
        bar.appendChild(secondary);
        var row = select.closest ? select.closest('.field-row') : null;
        var anchor = row || select.parentNode;
        anchor.parentNode.insertBefore(bar, anchor.nextSibling);

        function hideBar() { bar.hidden = true; pendingKey = null; undoState = null; }
        function showBar(message, primaryLabel, onPrimary, secondaryLabel, onSecondary) {
            text.textContent = message;
            primary.textContent = primaryLabel;
            primary.onclick = onPrimary;
            secondary.hidden = !secondaryLabel;
            secondary.textContent = secondaryLabel || '';
            secondary.onclick = onSecondary || null;
            bar.hidden = false;
        }

        function apply(key) {
            var t = templates[key];
            subjectInput.value = t.subject;
            bodyInput.value = t.body;
            applied = { subject: t.subject, body: t.body };
            lastKey = key;
            select.value = key;
        }

        function replaceWith(key) {
            undoState = { subject: subjectInput.value, body: bodyInput.value, key: lastKey, applied: applied };
            pendingKey = null;
            apply(key);
            showBar('Template applied.', 'Undo', function () {
                var u = undoState;
                if (!u) return;
                subjectInput.value = u.subject;
                bodyInput.value = u.body;
                applied = u.applied;
                lastKey = u.key;
                select.value = u.key;
                hideBar();
            }, null, null);
        }

        select.addEventListener('change', function () {
            var key = select.value;
            if (!templates[key]) return;
            if (!edited()) { hideBar(); apply(key); return; }
            // Edited: keep the dropdown where it was and ask first.
            select.value = lastKey;
            pendingKey = key;
            showBar(
                'You have edited this message. Replace it with the "' + (templates[key].label || key) + '" template? Your edits will be lost.',
                'Replace', function () { replaceWith(pendingKey); },
                'Keep mine', function () { hideBar(); }
            );
        });

        // Typing after a replace ends the Undo offer: it must never overwrite what was typed since.
        function onType() { if (undoState) hideBar(); }
        subjectInput.addEventListener('input', onType);
        bodyInput.addEventListener('input', onType);

        return { edited: edited, bar: bar };
    }

    if (root.document && root.document.addEventListener) {
        root.document.addEventListener('DOMContentLoaded', function () { init(root.document); });
    }
    root.KuronyxEmailCompose = { init: init };
})(typeof window !== 'undefined' ? window : globalThis);
