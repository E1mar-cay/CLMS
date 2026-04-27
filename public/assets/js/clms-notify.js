/* ============================================================
   ClmsNotify — Thin SweetAlert2 wrapper used across the app.
   ------------------------------------------------------------
   Why this exists:
   - Every page was duplicating the same `Swal.mixin({ toast:true,
     position:'center' })` block, which produced the cramped,
     awkwardly-wrapping toasts users complained about.
   - Button colours were hard-coded to Sneat's old purple
     (#696cff); we switched the brand to navy (#0f204b).
   - Centralising the style here means future tweaks (e.g. swap
     the toast corner, change brand colour) happen in one place.

   Usage:
     ClmsNotify.success('Saved.');
     ClmsNotify.error('Something went wrong.');
     ClmsNotify.fromFlash(successMsg, errorMsg);   // common case
     ClmsNotify.confirm({
       title: 'Delete "Foo"?',
       text:  'This cannot be undone.',
       danger: true,
     }).then(r => r.isConfirmed && form.submit());

   This helper lazily reads `window.Swal`, so pages that don't
   load SweetAlert2 can still include this file safely — calls
   simply no-op and log a single warning.
   ============================================================ */
(function (root) {
  'use strict';

  var BRAND_PRIMARY = '#0f204b';
  var BRAND_DANGER  = '#dc3545';

  function getSwal() {
    return typeof root.Swal !== 'undefined' ? root.Swal : null;
  }

  var warnedMissing = false;
  function warnMissing() {
    if (warnedMissing) return;
    warnedMissing = true;
    if (typeof console !== 'undefined' && console.warn) {
      console.warn('[ClmsNotify] SweetAlert2 is not loaded on this page; notifications were suppressed.');
    }
  }

  /* Success / info / warning share this centered auto-dismiss dialog
     so they match the visual weight of the confirm dialogs (same big
     icon on top, centered, good typography) — consistent UX across
     the app. Errors keep their own method because they need a manual
     dismiss button to demand the user's attention. */
  function flash(icon, msg) {
    var Swal = getSwal();
    if (!Swal) { warnMissing(); return; }
    if (!msg) return;
    Swal.fire({
      icon: icon,
      title: msg,
      showConfirmButton: false,
      timer: 2200,
      timerProgressBar: true,
      customClass: { popup: 'clms-flash-dialog' },
    });
  }

  root.ClmsNotify = {
    PRIMARY: BRAND_PRIMARY,
    DANGER:  BRAND_DANGER,

    success: function (msg) { flash('success', msg); },
    info:    function (msg) { flash('info',    msg); },
    warning: function (msg) { flash('warning', msg); },

    /* Shown as a full dialog with a manual dismiss button because
       errors deserve focus — users shouldn't miss them while looking
       elsewhere. */
    error: function (msg, title) {
      var Swal = getSwal();
      if (!Swal) { warnMissing(); return; }
      if (!msg) return;
      Swal.fire({
        icon: 'error',
        title: title || 'Something went wrong',
        text: msg,
        confirmButtonColor: BRAND_PRIMARY,
      });
    },

    /* Convenience: pass in the two flash vars from PHP and let
       this helper decide which (if any) to render. */
    fromFlash: function (successMsg, errorMsg) {
      if (successMsg) this.success(successMsg);
      if (errorMsg)   this.error(errorMsg);
    },

    /* Returns a Promise that resolves with the SweetAlert2 result
       object, so callers can `.then(r => r.isConfirmed && ...)`. */
    confirm: function (opts) {
      var Swal = getSwal();
      if (!Swal) { warnMissing(); return Promise.resolve({ isConfirmed: false }); }
      opts = opts || {};
      return Swal.fire({
        icon:                opts.icon                || 'question',
        title:               opts.title               || 'Are you sure?',
        text:                opts.text                || undefined,
        html:                opts.html                || undefined,
        showCancelButton:    true,
        confirmButtonText:   opts.confirmButtonText   || 'Yes',
        cancelButtonText:    opts.cancelButtonText    || 'Cancel',
        confirmButtonColor:  opts.danger ? BRAND_DANGER : BRAND_PRIMARY,
        reverseButtons:      true,
      });
    },
  };
})(window);
