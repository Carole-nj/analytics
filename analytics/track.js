/*!
 * Visitor tracker - add to every page:
 *   <script src="/analytics/track.js" defer></script>
 * Optional: data-endpoint="https://yoursite.com/analytics/track.php"
 *
 * Tracks: unique visitors, sessions, page views, active time, link/button clicks
 * and form submissions. Form values and visible labels are never collected.
 */
(function () {
  'use strict';

  // Respect Do Not Track
  if (navigator.doNotTrack === '1') return;

  var script = document.currentScript;
  var endpoint = (script && script.getAttribute('data-endpoint')) ||
    (script && script.src ? script.src.replace(/track\.js(\?.*)?$/, 'track.php') : '/analytics/track.php');

  var SESSION_TIMEOUT = 30 * 60 * 1000; // 30 min inactivity = new session
  var PING_EVERY = 15;                  // seconds between heartbeats

  function uuid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var r = Math.random() * 16 | 0;
      return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
    });
  }

  function store(key, value) {
    try {
      if (value === undefined) return JSON.parse(localStorage.getItem(key));
      localStorage.setItem(key, JSON.stringify(value));
    } catch (e) { return null; }
  }

  // Visitor ID (persists across visits)
  var visitorId = store('va_visitor');
  if (!visitorId) { visitorId = uuid(); store('va_visitor', visitorId); }

  // Session ID (renews after 30 min of inactivity; shared across tabs)
  var now = Date.now();
  var sess = store('va_session');
  if (!sess || !sess.id || now - sess.last > SESSION_TIMEOUT) {
    sess = { id: uuid(), last: now };
  }
  sess.last = now;
  store('va_session', sess);

  var pageviewId = uuid();
  var activeSeconds = 0;
  var lastSent = -1;

  function safeTarget(value) {
    try {
      var target = new URL(value, location.href);
      return (target.origin === location.origin ? '' : target.host) + target.pathname;
    } catch (e) {
      return '';
    }
  }

  function safeReferrer() {
    try {
      return document.referrer ? new URL(document.referrer).origin : '';
    } catch (e) {
      return '';
    }
  }

  function send(type, useBeacon, eventTarget) {
    var payload = JSON.stringify({
      type: type,
      event_id: uuid(),
      visitor_id: visitorId,
      session_id: sess.id,
      pageview_id: pageviewId,
      url: location.pathname,
      title: document.title,
      referrer: safeReferrer(),
      time_spent: activeSeconds,
      event_target: eventTarget || ''
    });
    try {
      if (useBeacon && navigator.sendBeacon) {
        navigator.sendBeacon(endpoint, new Blob([payload], { type: 'text/plain' }));
      } else {
        fetch(endpoint, {
          method: 'POST',
          body: payload,
          headers: { 'Content-Type': 'text/plain' },
          keepalive: true,
          credentials: 'omit'
        }).catch(function () {});
      }
    } catch (e) {}
  }

  // Count active time once per second, only while the tab is visible
  setInterval(function () {
    if (document.visibilityState === 'visible') {
      activeSeconds++;
      sess.last = Date.now();
      if (activeSeconds % 5 === 0) store('va_session', sess);
      if (activeSeconds % PING_EVERY === 0) { lastSent = activeSeconds; send('ping'); }
    }
  }, 1000);

  function flush() {
    if (activeSeconds !== lastSent) { lastSent = activeSeconds; send('end', true); }
  }
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') flush();
  });
  window.addEventListener('pagehide', flush);

  document.addEventListener('click', function (event) {
    if (!(event.target instanceof Element)) return;
    var element = event.target.closest('a,button,input[type="button"],input[type="submit"],input[type="image"],[role="button"]');
    if (!element) return;
    var target = element.tagName.toLowerCase() === 'a'
      ? safeTarget(element.href)
      : element.tagName.toLowerCase();
    send('click', true, target);
  }, true);

  document.addEventListener('submit', function (event) {
    if (!(event.target instanceof HTMLFormElement)) return;
    var method = (event.target.method || 'get').toUpperCase();
    send('form_submit', true, method + ' ' + safeTarget(event.target.action));
  }, true);

  // Record the page view
  send('view');
})();
