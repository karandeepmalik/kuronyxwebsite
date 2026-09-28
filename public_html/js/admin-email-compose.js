// Swaps the subject/body fields of the admin email composer (gs-requests and
// veterinary-applications) when staff pick a different predefined template. Templates
// themselves are rendered server-side into #email-template's data-templates attribute
// so the copy always reflects the record's current state — this script only moves
// that text into the editable fields; staff still edit and send manually.
document.addEventListener('DOMContentLoaded', function () {
    var select = document.getElementById('email-template');
    if (!select) return;
    var templates = JSON.parse(select.getAttribute('data-templates') || '{}');
    var subjectInput = document.getElementById('email-subject');
    var bodyInput = document.getElementById('email-body');
    select.addEventListener('change', function () {
        var t = templates[select.value];
        if (!t) return;
        subjectInput.value = t.subject;
        bodyInput.value = t.body;
    });
});
