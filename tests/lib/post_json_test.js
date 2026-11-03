// Runs public_html/js/post-json.js (KuronyxPost.send) against pretend server answers and prints one JSON array of
// { name, ok, detail } for tests/cases/85b_post_json_helper.php to assert on. No browser, no network: `fetch` is a stub.
// Usage: node post_json_test.js
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '..', '..', 'public_html', 'js', 'post-json.js'), 'utf8');

function load(fetchImpl) {
  const sandbox = { window: {}, fetch: fetchImpl };
  vm.createContext(sandbox);
  vm.runInContext(source, sandbox);
  return sandbox.window.KuronyxPost;
}
const answer = (status, body) => () => Promise.resolve({ ok: status >= 200 && status < 300, status, text: () => Promise.resolve(body) });

const HTML_500 = '<!doctype html><html><head><title>Something went wrong</title></head><body><h1>Something went wrong.</h1></body></html>';
const results = [];
async function check(name, fetchImpl, expect) {
  let detail = '';
  let ok = false;
  try {
    const post = load(fetchImpl);
    const r = await post.send('/php/send-enquiry.php', { a: 1 });
    detail = JSON.stringify(r);
    ok = expect(r, post) === true;
  } catch (err) {
    detail = 'THREW: ' + (err && err.message);
    ok = false;
  }
  results.push({ name, ok, detail });
}
const raw = /Unexpected|JSON|<|Failed to fetch|TypeError|SyntaxError/;

(async () => {
  await check('an HTML 500 page is not shown as a parser error', answer(500, HTML_500),
    (r, p) => r.ok === false && r.message === p.SERVER_ERROR && !raw.test(r.message));
  await check('a host-level 413 HTML page', answer(413, '<html><body>413 Request Entity Too Large</body></html>'),
    (r, p) => r.ok === false && r.message === p.SERVER_ERROR);
  await check('a 502 with an empty body', answer(502, ''),
    (r, p) => r.ok === false && r.message === p.SERVER_ERROR);
  await check('plain text from the endpoint (its 405 answer)', answer(405, 'Method not allowed'),
    (r, p) => r.ok === false && r.message === p.SERVER_ERROR);
  await check('a 200 that is HTML (a captive portal) is not a success', answer(200, '<html>Please log in to the wifi</html>'),
    (r, p) => r.ok === false && r.message === p.SERVER_ERROR);
  await check('a 200 JSON without success:true is not a success', answer(200, '{"success":false}'),
    (r, p) => r.ok === false && r.message === p.SERVER_ERROR);
  await check('JSON that is not an object (null / array / number)', answer(500, 'null'),
    (r, p) => r.ok === false && r.message === p.SERVER_ERROR);
  await check('JSON array body', answer(500, '[1,2]'),
    (r, p) => r.ok === false && r.message === p.SERVER_ERROR);
  await check('a message that is not a string is ignored', answer(500, '{"success":false,"message":{"x":1}}'),
    (r, p) => r.ok === false && r.message === p.SERVER_ERROR);

  await check('the endpoint\'s own 429 message is kept as it is', answer(429, '{"success":false,"message":"Too many requests. Please try again later."}'),
    (r) => r.ok === false && r.message === 'Too many requests. Please try again later.');
  await check('the endpoint\'s own 400 validation message is kept as it is', answer(400, '{"success":false,"message":"Please fill in all fields with valid information."}'),
    (r) => r.ok === false && r.message === 'Please fill in all fields with valid information.');
  await check('a 500 with a JSON message keeps it and adds what to do', answer(500, '{"success":false,"message":"Enquiry could not be transmitted."}'),
    (r) => r.ok === false && r.message === 'Enquiry could not be transmitted. Please try again, or email hello@kuronyx.in.');
  await check('a 500 message that already names the address is not given it twice', answer(500, '{"success":false,"message":"Please email hello@kuronyx.in."}'),
    (r) => r.ok === false && r.message === 'Please email hello@kuronyx.in.');

  await check('a dropped connection is not shown as "Failed to fetch"', () => Promise.reject(new TypeError('Failed to fetch')),
    (r, p) => r.ok === false && r.message === p.NETWORK_ERROR && !raw.test(r.message));
  await check('a connection that drops while the answer is being read', () => Promise.resolve({ ok: true, status: 200, text: () => Promise.reject(new Error('network error')) }),
    (r, p) => r.ok === false && r.message === p.NETWORK_ERROR);
  await check('fetch itself throwing synchronously still does not throw out of send()', () => { throw new Error('boom'); },
    (r, p) => r.ok === false && r.message === p.NETWORK_ERROR);

  await check('a normal success', answer(200, '{"success":true}'),
    (r) => r.ok === true && r.message === '' && r.data && r.data.success === true);

  // What the request itself looks like.
  let seen = null;
  await check('it POSTs the payload as JSON', (url, init) => { seen = { url, init }; return answer(200, '{"success":true}')(); },
    (r) => r.ok === true && seen.url === '/php/send-enquiry.php' && seen.init.method === 'POST'
      && seen.init.headers['Content-Type'] === 'application/json' && seen.init.body === '{"a":1}');

  // The fallback wording: a real inbox, the same one the pages already show.
  const p = load(answer(200, '{"success":true}'));
  results.push({ name: 'both fallback messages say to email hello@kuronyx.in, and only that address',
    ok: [p.SERVER_ERROR, p.NETWORK_ERROR].every(m => m.indexOf('hello@kuronyx.in') !== -1 && !/orders@|ops@/.test(m)), detail: p.SERVER_ERROR + ' | ' + p.NETWORK_ERROR });

  process.stdout.write(JSON.stringify(results));
  process.exit(results.every(r => r.ok) ? 0 : 1);
})();
