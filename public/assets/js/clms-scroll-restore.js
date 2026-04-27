/* ============================================================
   CLMS Scroll Restoration
   ------------------------------------------------------------
   Preserves the window's vertical scroll position across:
     - Page reloads (F5 / Ctrl+R).
     - Form submits that redirect back to the same page (common
       pattern in this app — e.g. "Enroll" button, "Save module",
       "Delete" confirm, admin CRUD forms).
     - Normal same-origin link navigations.
     - Browser back/forward (including bfcache restores).

   Why we don't just rely on the browser:
     - Sneat renders its layout inside fixed-height containers,
       and native `history.scrollRestoration = 'auto'` occasionally
       fires BEFORE the content wrapper has laid out, which makes
       the restore land short or at the top. Taking over with
       `manual` + our own rAF-driven restore is reliable.
     - Native restoration only fires on back/forward; it does
       NOT restore after a form POST → redirect cycle, which is
       the #1 UX pain point in this app.

   Storage:
     - `sessionStorage` (per-tab), keyed by pathname+search so
       `?page=2` and `?page=3` remember their own scroll.
     - Entries expire after 30 min or when the store exceeds 25
       URLs (LRU-trimmed).
   ============================================================ */

(function () {
  'use strict';

  if (!('sessionStorage' in window)) return;

  var STORAGE_KEY  = 'clms-scroll-positions';
  var MAX_AGE_MS   = 30 * 60 * 1000;
  var MAX_ENTRIES  = 25;
  var RESTORE_WINDOW_MS = 500; /* How long after load we keep nudging the scroll (in case late images push content) */

  function currentKey() {
    return location.pathname + location.search;
  }

  function readStore() {
    try {
      var raw = sessionStorage.getItem(STORAGE_KEY);
      return raw ? JSON.parse(raw) : {};
    } catch (e) {
      return {};
    }
  }

  function writeStore(store) {
    try {
      sessionStorage.setItem(STORAGE_KEY, JSON.stringify(store));
    } catch (e) {
      /* Quota full: drop half the entries and retry once. */
      try {
        var entries = Object.entries(store).sort(function (a, b) { return b[1].t - a[1].t; });
        var trimmed = {};
        entries.slice(0, Math.floor(MAX_ENTRIES / 2)).forEach(function (e) { trimmed[e[0]] = e[1]; });
        sessionStorage.setItem(STORAGE_KEY, JSON.stringify(trimmed));
      } catch (inner) { /* give up silently */ }
    }
  }

  function savePosition() {
    var y = window.scrollY || window.pageYOffset || 0;
    /* Don't save obviously-zero positions — saves storage and means a
       fresh navigation that never scrolled doesn't "pin" a URL at 0. */
    if (y < 2) {
      var existing = readStore();
      if (existing[currentKey()]) {
        delete existing[currentKey()];
        writeStore(existing);
      }
      return;
    }

    var store = readStore();
    store[currentKey()] = { y: y, t: Date.now() };

    var keys = Object.keys(store);
    if (keys.length > MAX_ENTRIES) {
      var sorted = keys
        .map(function (k) { return { k: k, t: store[k].t }; })
        .sort(function (a, b) { return b.t - a.t; })
        .slice(0, MAX_ENTRIES);
      var trimmed = {};
      sorted.forEach(function (e) { trimmed[e.k] = store[e.k]; });
      store = trimmed;
    }

    writeStore(store);
  }

  var restoreTargetY = null;

  function applyRestore() {
    if (restoreTargetY == null) return;
    /* Only nudge if the browser has drifted away from our target. This
       makes the final rAF tick cheap on pages that loaded synchronously. */
    var current = window.scrollY || window.pageYOffset || 0;
    if (Math.abs(current - restoreTargetY) > 1) {
      window.scrollTo(0, restoreTargetY);
    }
  }

  function scheduleRestore(y) {
    restoreTargetY = y;

    /* Restore immediately. */
    applyRestore();

    /* Keep re-applying for a short window while late-loading images /
       fonts shift the layout. After that, lock in whatever the user
       has scrolled to themselves. */
    var start = performance.now();
    function tick() {
      applyRestore();
      if (performance.now() - start < RESTORE_WINDOW_MS) {
        requestAnimationFrame(tick);
      } else {
        restoreTargetY = null;
      }
    }
    requestAnimationFrame(tick);
  }

  function restorePosition(opts) {
    opts = opts || {};

    /* Hash anchors: user asked for a specific section, don't fight
       the browser. */
    if (!opts.ignoreHash && location.hash) return;

    var store = readStore();
    var entry = store[currentKey()];
    if (!entry) return;
    if (Date.now() - entry.t > MAX_AGE_MS) {
      delete store[currentKey()];
      writeStore(store);
      return;
    }

    scheduleRestore(entry.y);
  }

  /* --- Setup ------------------------------------------------ */

  /* Disable native restoration so ours is authoritative. Without this
     the browser fights us on back/forward. */
  if ('scrollRestoration' in history) {
    try { history.scrollRestoration = 'manual'; } catch (e) { /* ignore */ }
  }

  /* Save on *hide* (covers reload, navigation away, tab close). Using
     both pagehide + beforeunload because Safari fires one but not the
     other in some cases. */
  window.addEventListener('pagehide', savePosition);
  window.addEventListener('beforeunload', savePosition);

  /* Also save when the user explicitly acts — form submit or same-
     origin link click — so we've already stored the position before
     the browser even starts unloading. Catches edge cases where
     pagehide doesn't fire (some mobile Safari scenarios). */
  document.addEventListener('submit', savePosition, true);
  document.addEventListener('click', function (event) {
    var link = event.target && event.target.closest && event.target.closest('a[href]');
    if (!link) return;
    if (link.target && link.target !== '_self') return;
    if (link.hasAttribute('download')) return;
    /* Skip pure hash links on the current page — the browser will
       just scroll to the anchor, no navigation. */
    var href = link.getAttribute('href');
    if (!href || href.charAt(0) === '#') return;
    savePosition();
  }, true);

  /* Restore on fresh load. */
  if (document.readyState === 'complete' || document.readyState === 'interactive') {
    restorePosition();
  } else {
    document.addEventListener('DOMContentLoaded', function () { restorePosition(); });
  }

  /* Also restore after all resources load — images pushing content
     down is the single biggest reason a restored scroll looks "short". */
  window.addEventListener('load', function () { restorePosition({ ignoreHash: !!restoreTargetY }); });

  /* bfcache back/forward restore. */
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) restorePosition();
  });

  /* Expose a small API so specific flows (e.g. AJAX pagination) can
     opt-out or force behavior. Example:
        window.ClmsScroll.clear();   // forget this page's scroll
        window.ClmsScroll.save();    // save now
        window.ClmsScroll.restore(); // try to restore now              */
  window.ClmsScroll = {
    save: savePosition,
    restore: function () { restorePosition({ ignoreHash: true }); },
    clear: function () {
      var store = readStore();
      if (store[currentKey()]) {
        delete store[currentKey()];
        writeStore(store);
      }
    },
    clearAll: function () {
      try { sessionStorage.removeItem(STORAGE_KEY); } catch (e) { /* ignore */ }
    },
  };
})();
