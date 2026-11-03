// Posts JSON to one of our own PHP endpoints (/php/send-enquiry.php, /php/send-welcome.php) and ALWAYS answers
// with something safe to show a visitor. The forms used to call res.json() directly, which throws when the server
// answers with anything that is not JSON - a PHP error page, a 500, a host-level 413 or rate-limit page - and the
// catch then printed the browser's own parser message (Unexpected token '<', "<!doctype "... is not valid JSON)
// as the form status. A dropped connection printed "Failed to fetch" the same way.
//
//   const result = await KuronyxPost.send('/php/send-enquiry.php', payload);
//   if (result.ok) { ... } else { show(result.message); }
//
// send() never throws. result = { ok, data, message }: `message` is the endpoint's own friendly message when it
// answered JSON with one (e.g. "Too many requests. Please try again later."), else a plain fallback that says
// what to do. A 5xx with a JSON message also gets the fallback's "try again, or email ..." appended.
(function () {
  const CONTACT = 'hello@kuronyx.in';
  const SERVER_ERROR = 'Something went wrong on our side. Please try again, or email ' + CONTACT + '.';
  const NETWORK_ERROR = "We couldn't reach the server. Check your connection and try again, or email " + CONTACT + '.';

  async function send(url, payload) {
    let res;
    let text;
    try {
      res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      text = await res.text();
    } catch (err) {
      return { ok: false, data: null, message: NETWORK_ERROR }; // offline, DNS, the connection dropped mid-answer
    }

    let data = null;
    try {
      data = JSON.parse(text);
    } catch (err) {
      data = null; // not JSON at all: an HTML error page, an empty body, plain text
    }
    if (data === null || typeof data !== 'object' || Array.isArray(data)) data = null;

    if (res.ok && data && data.success === true) return { ok: true, data: data, message: '' };

    let message = data && typeof data.message === 'string' && data.message.trim() ? data.message.trim() : SERVER_ERROR;
    if (message !== SERVER_ERROR && res.status >= 500 && message.indexOf(CONTACT) === -1) {
      message += ' Please try again, or email ' + CONTACT + '.';
    }
    return { ok: false, data: data, message: message };
  }

  window.KuronyxPost = { send: send, SERVER_ERROR: SERVER_ERROR, NETWORK_ERROR: NETWORK_ERROR };
})();
