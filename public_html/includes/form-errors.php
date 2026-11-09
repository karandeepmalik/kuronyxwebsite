<?php
// Not a page - refuse direct requests.
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden.');
}

// Per-field error display for the forms that tell the user WHAT is wrong (the staff, password-reset,
// activation and setup forms, and the dispatch editor), from the usual `$errors['field'] = 'message'` array.
//
// Setting $errors['password'] = 'Use at least 12 characters' is only half the job: nothing shows it unless the
// page prints it. Several forms set the messages and only coloured the label (`has-error`), so the user saw a
// red box and had to guess. These three helpers keep the label colour, the message and the screen-reader
// wiring (aria-invalid + aria-describedby) in step, so a field can't get one without the others:
//
//   <label class="[field_class($errors, 'email')]">
//     <span class="lbl">Email</span>
//     <input type="email" name="email" ...[field_error_attrs($errors, 'email')]>
//     [field_error_html($errors, 'email')]
//   </label>
//
// where each [...] is echoed. (Never write the PHP echo tags into these comments: a closing tag ends PHP mode
// even inside a // comment, and the rest of the comment is printed into the page.)
//
// The public intake forms (cat owners, vet application, vet portal request) use these too: a worried cat owner is the one
// person who most needs to be told WHAT is wrong, not just shown a red box. They also print form_error_focus_script().

// The label's class: "field" (or another base class for a radio group / checkbox row), plus "has-error" when this field
// has an error.
function field_class(array $errors, string $name, string $base = 'field'): string {
    return isset($errors[$name]) ? $base . ' has-error' : $base;
}

// Attributes for the <input>/<select>/<textarea>, empty when the field is fine.
function field_error_attrs(array $errors, string $name): string {
    return isset($errors[$name]) ? ' aria-invalid="true" aria-describedby="err-' . $name . '"' : '';
}

// The message itself, empty when the field is fine. The text is escaped here, so pass it raw.
function field_error_html(array $errors, string $name): string {
    if (!isset($errors[$name])) return '';
    return '<span class="field-error" id="err-' . $name . '">' . htmlspecialchars((string) $errors[$name], ENT_QUOTES) . '</span>';
}

// The value a field is refilled with after a failed submit: what the person typed (or the saved value), escaped for an
// HTML attribute / text. Empty when there is none. The forms used to carry their own copies (ov() twice, old_val() once).
function old_value(array $old, string $key): string {
    return htmlspecialchars((string) ($old[$key] ?? ''), ENT_QUOTES);
}

// "Too long - 150 characters at most": the message for a field over its column size (limit from field_length_errors()).
function too_long_message(int $max): string {
    return 'Too long - ' . number_format($max) . ' characters at most';
}

// For a form that re-renders after a failed submit: moves the cursor to the FIRST field with a message and scrolls it into
// view, so the person lands on what to fix instead of at the top of a long page. Empty when no field has a message
// (the '_form' banner is not a field). Echo it once, after the form.
function form_error_focus_script(array $errors): string {
    $fieldErrors = array_diff_key($errors, ['_form' => true]);
    if (!$fieldErrors) return '';
    return '<script>(function () { var el = document.querySelector(\'[aria-invalid="true"]\'); if (!el) return;'
         . ' try { el.focus({ preventScroll: true }); } catch (e) { el.focus(); }'
         . ' el.scrollIntoView({ block: "center" }); })();</script>' . "\n";
}

// Browsers cannot refill a file input, so after ANY failed submit the chosen files are gone and nothing said so: the
// person fixed the typo, pressed Submit and was told the prescription was missing. Echo this just above the file field.
// It shows when the form came back with some error and the file field itself is not the one at fault (that message
// already says to attach it). $what names what they may have attached, e.g. "your prescription or any other documents".
function reattach_files_note(array $errors, string $fileKey, string $what): string {
    if (!$errors || isset($errors[$fileKey])) return '';
    return '<p class="reattach-note"><strong>Please attach your files again.</strong> Your browser clears chosen files when a form reloads,'
         . ' so if you had already attached ' . htmlspecialchars($what, ENT_QUOTES) . ', choose them again before submitting.</p>' . "\n";
}
