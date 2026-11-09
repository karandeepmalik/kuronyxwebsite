// Tells a person AT ONCE that a chosen file is too big, instead of after a slow upload.
//
// Our host lets a request be up to 1 GB, so the 10 MB-per-file limit is only enforced by our own code AFTER the whole
// file has arrived: someone who picks a 300 MB scan on a phone connection waits through the upload, then gets "too big"
// and has to start again (a browser also clears every chosen file when the form reloads). Every file input that carries
// data-max-bytes (the server's MAX_UPLOAD_BYTES, printed by the page) is checked the moment a file is chosen: if it is
// over the limit the choice is cleared and the reason is printed under the field in the same style as the server's own
// messages. The server still checks everything again - this only saves the wait.
(function () {
  function sizeText(bytes) {
    var mb = Math.round((bytes / 1048576) * 10) / 10;
    return (mb % 1 === 0 ? mb.toFixed(0) : mb.toFixed(1)) + ' MB';
  }

  // '' when the file is fine, else the sentence to show.
  function tooBigMessage(file, maxBytes) {
    if (!file || !(maxBytes > 0) || file.size <= maxBytes) return '';
    return 'That file is ' + sizeText(file.size) + ' - the limit is ' + sizeText(maxBytes) + ' per file. ' +
      'Please choose a smaller file, or a compressed copy.';
  }

  function wire(input) {
    var max = parseInt(input.getAttribute('data-max-bytes'), 10);
    if (!(max > 0)) return;
    var errId = 'err-js-' + (input.name || 'file');
    input.addEventListener('change', function () {
      var old = document.getElementById(errId);
      if (old) old.remove();
      var label = input.closest('label') || input.parentNode;
      var msg = tooBigMessage(input.files && input.files[0], max);
      if (!msg) {
        if (input.getAttribute('data-js-invalid')) {
          input.removeAttribute('data-js-invalid');
          input.removeAttribute('aria-invalid');
          input.removeAttribute('aria-describedby');
          label.classList.remove('has-error');
        }
        return;
      }
      input.value = ''; // the file is not sent; the field is empty again
      var span = document.createElement('span');
      span.className = 'field-error';
      span.id = errId;
      span.setAttribute('role', 'alert');
      span.textContent = msg;
      label.appendChild(span);
      input.setAttribute('data-js-invalid', '1');
      input.setAttribute('aria-invalid', 'true');
      input.setAttribute('aria-describedby', errId);
      label.classList.add('has-error');
    });
  }

  window.KuronyxUploadLimit = { sizeText: sizeText, tooBigMessage: tooBigMessage };
  if (typeof document !== 'undefined') {
    Array.prototype.forEach.call(document.querySelectorAll('input[type="file"][data-max-bytes]'), wire);
  }
})();
