/**
 * One question at a time; question-number grid lives in a Bootstrap modal (priority on the question).
 * Review modal before manual submit.
 */
(function () {
  'use strict';

  function paneIsAnswered(form, pane) {
    if (!pane || !form.contains(pane)) return false;

    if (pane.querySelector('input[type="radio"]')) {
      return !!pane.querySelector('input[type="radio"]:checked');
    }

    const checks = pane.querySelectorAll('input[type="checkbox"]');
    for (let c = 0; c < checks.length; c++) {
      if (checks[c].checked) return true;
    }

    const texts = pane.querySelectorAll('input[type="text"], textarea');
    for (let t = 0; t < texts.length; t++) {
      if (texts[t].value && String(texts[t].value).trim() !== '') return true;
    }

    const selects = pane.querySelectorAll('select');
    if (selects.length > 0) {
      for (let s = 0; s < selects.length; s++) {
        if (!selects[s].value || String(selects[s].value).trim() === '') return false;
      }
      return true;
    }

    return false;
  }

  /**
   * @param {object} o
   * @param {HTMLElement} o.container
   * @param {HTMLFormElement} o.form
   * @param {HTMLElement[]} o.panes
   * @param {(idx: number) => void} o.onSelect
   */
  function buildNavGrid(o) {
    o.container.innerHTML = '';
    const buttons = [];
    for (let i = 0; i < o.panes.length; i++) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'clms-exam-nav-btn clms-exam-nav-btn--unanswered';
      btn.textContent = String(i + 1);
      btn.setAttribute('aria-label', 'Question ' + (i + 1));
      (function (idx) {
        btn.addEventListener('click', function () {
          o.onSelect(idx);
        });
      })(i);
      o.container.appendChild(btn);
      buttons.push(btn);
    }

    function sync() {
      let answered = 0;
      for (let i = 0; i < o.panes.length; i++) {
        const ok = paneIsAnswered(o.form, o.panes[i]);
        if (ok) answered++;
        const b = buttons[i];
        b.classList.toggle('clms-exam-nav-btn--answered', ok);
        b.classList.toggle('clms-exam-nav-btn--unanswered', !ok);
      }
      return answered;
    }

    return { buttons, sync };
  }

  function hideBootstrapModal(modalEl) {
    if (!modalEl || !window.bootstrap || typeof bootstrap.Modal !== 'function') return;
    const inst = bootstrap.Modal.getInstance(modalEl);
    if (inst) inst.hide();
  }

  /**
   * @param {object} opts
   * @param {string} opts.formSelector
   * @param {string} [opts.navMapModalId] — modal that hosts [data-clms-exam-nav-map]; if omitted, uses .clms-exam-stepper__nav inside .clms-exam-stepper
   * @param {string} [opts.reviewModalId]
   * @param {string} [opts.submitReviewBtnSelector]
   * @param {string} [opts.reviewSummarySelector]
   * @param {string} [opts.reviewNavSelector]
   * @param {string} [opts.progressStorageKey] — sessionStorage key for last viewed question (also read from form data-clms-exam-progress-key)
   */
  window.clmsInitExamStepper = function clmsInitExamStepper(opts) {
    const form = document.querySelector(opts.formSelector);
    if (!form || !(form instanceof HTMLFormElement)) return;

    const panes = Array.from(form.querySelectorAll('.clms-exam-q-pane'));
    if (panes.length === 0) return;

    const progressStorageKey =
      (opts.progressStorageKey && String(opts.progressStorageKey)) ||
      form.getAttribute('data-clms-exam-progress-key') ||
      '';

    const stepperRoot = form.querySelector('.clms-exam-stepper');
    const navMapModalId = opts.navMapModalId || '';
    const navMapModal = navMapModalId ? document.getElementById(navMapModalId) : null;
    const navHostFromModal = navMapModal ? navMapModal.querySelector('[data-clms-exam-nav-map]') : null;
    const navHostInline = stepperRoot ? stepperRoot.querySelector('.clms-exam-stepper__nav') : null;
    const navHost = navHostFromModal || navHostInline;

    const btnPrev = form.querySelector('.clms-exam-stepper__prev');
    const btnNext = form.querySelector('.clms-exam-stepper__next');
    const btnNextDefaultHtml = btnNext ? btnNext.innerHTML : '';
    const summaryEl = stepperRoot ? stepperRoot.querySelector('.clms-exam-stepper__summary') : null;

    const reviewModalId = opts.reviewModalId || 'clmsExamReviewModal';
    const reviewModal = document.getElementById(reviewModalId);
    const submitReviewBtn = opts.submitReviewBtnSelector
      ? document.querySelector(opts.submitReviewBtnSelector)
      : reviewModal
        ? reviewModal.querySelector('[data-clms-exam-review-submit]')
        : null;
    const reviewSummary = opts.reviewSummarySelector
      ? document.querySelector(opts.reviewSummarySelector)
      : reviewModal
        ? reviewModal.querySelector('[data-clms-exam-review-summary]')
        : null;
    const reviewNav = opts.reviewNavSelector
      ? document.querySelector(opts.reviewNavSelector)
      : reviewModal
        ? reviewModal.querySelector('[data-clms-exam-review-nav]')
        : null;

    let currentIdx = 0;
    /** @type {{ buttons: HTMLButtonElement[], sync: () => number } | null} */
    let mainNav = null;

    function refreshSummary(answered) {
      if (!summaryEl) return;
      summaryEl.textContent =
        'Question ' + (currentIdx + 1) + ' of ' + panes.length + ' · Answered ' + answered + '/' + panes.length;
    }

    /** When every pane is answered, Next becomes “Review answers” and opens the question-map modal. */
    function syncPagerButtons(answered) {
      const total = panes.length;
      const allAnswered = total > 0 && answered >= total;
      if (btnPrev) btnPrev.disabled = currentIdx <= 0;
      if (!btnNext) return;
      if (allAnswered) {
        btnNext.disabled = false;
        btnNext.textContent = 'Review answers';
        btnNext.setAttribute('aria-label', 'Open question map to submit');
      } else {
        btnNext.innerHTML = btnNextDefaultHtml;
        btnNext.disabled = currentIdx >= total - 1;
        btnNext.setAttribute('aria-label', 'Next question');
      }
    }

    function refreshAll() {
      const answered = mainNav ? mainNav.sync() : panes.reduce((n, p) => n + (paneIsAnswered(form, p) ? 1 : 0), 0);
      refreshSummary(answered);
      syncPagerButtons(answered);
      return answered;
    }

    function persistActivePaneIndex() {
      if (!progressStorageKey || typeof window.sessionStorage === 'undefined') return;
      try {
        window.sessionStorage.setItem(progressStorageKey, String(currentIdx));
      } catch (_e) {
        /* quota / private mode */
      }
    }

    /** Last viewed index from session, else first unanswered, else last question. */
    function readInitialPaneIndex() {
      const total = panes.length;
      if (progressStorageKey && typeof window.sessionStorage !== 'undefined') {
        try {
          const raw = window.sessionStorage.getItem(progressStorageKey);
          if (raw !== null && raw !== '') {
            const n = parseInt(raw, 10);
            if (Number.isFinite(n) && n >= 0 && n < total) return n;
          }
        } catch (_e) {}
      }
      for (let i = 0; i < total; i++) {
        if (!paneIsAnswered(form, panes[i])) return i;
      }
      return total > 0 ? total - 1 : 0;
    }

    function setActivePane(idx) {
      const clamped = Math.max(0, Math.min(panes.length - 1, idx));
      currentIdx = clamped;
      for (let i = 0; i < panes.length; i++) {
        panes[i].classList.toggle('clms-exam-q-pane--active', i === clamped);
      }
      if (mainNav) {
        for (let i = 0; i < mainNav.buttons.length; i++) {
          mainNav.buttons[i].classList.toggle('clms-exam-nav-btn--current', i === clamped);
          mainNav.buttons[i].setAttribute('aria-current', i === clamped ? 'true' : 'false');
        }
      }
      refreshAll();
      persistActivePaneIndex();
    }

    if (navHost) {
      mainNav = buildNavGrid({
        container: navHost,
        form: form,
        panes: panes,
        onSelect: function (i) {
          setActivePane(i);
          panes[i].scrollIntoView({ behavior: 'smooth', block: 'start' });
          hideBootstrapModal(navMapModal);
        },
      });
    }

    if (navMapModal && window.bootstrap && typeof bootstrap.Modal !== 'undefined') {
      navMapModal.addEventListener('show.bs.modal', function () {
        refreshAll();
      });
    }

    setActivePane(readInitialPaneIndex());
    if (panes[currentIdx]) {
      try {
        panes[currentIdx].scrollIntoView({ block: 'start', behavior: 'auto' });
      } catch (_e) {}
    }

    form.addEventListener(
      'input',
      function () {
        refreshAll();
      },
      true
    );
    form.addEventListener(
      'change',
      function () {
        refreshAll();
      },
      true
    );

    function openReviewModal() {
      hideBootstrapModal(navMapModal);
      const answered = refreshAll();
      const total = panes.length;
      const unanswered = total - answered;

      if (reviewSummary) {
        reviewSummary.innerHTML =
          '<p class="mb-2">You have answered <strong>' +
          answered +
          '</strong> of <strong>' +
          total +
          '</strong> question(s). ' +
          (unanswered > 0
            ? '<span class="text-warning">' + unanswered + ' still unanswered.</span>'
            : '<span class="text-success">All questions have a response.</span>') +
          '</p>';
      }

      if (reviewNav) {
        reviewNav.innerHTML = '';
        const mini = buildNavGrid({
          container: reviewNav,
          form: form,
          panes: panes,
          onSelect: function (i) {
            hideBootstrapModal(reviewModal);
            setActivePane(i);
            panes[i].scrollIntoView({ behavior: 'smooth', block: 'start' });
          },
        });
        mini.sync();
        mini.buttons.forEach(function (b, i) {
          b.classList.toggle('clms-exam-nav-btn--current', i === currentIdx);
        });
      }

      if (reviewModal && window.bootstrap && typeof bootstrap.Modal === 'function') {
        let modal = bootstrap.Modal.getInstance(reviewModal);
        if (!modal) modal = new bootstrap.Modal(reviewModal);
        modal.show();
      } else if (reviewModal) {
        reviewModal.classList.add('show');
        reviewModal.style.display = 'block';
      }
    }

    function showNavMapModal() {
      refreshAll();
      if (!navMapModal) {
        openReviewModal();
        return;
      }
      if (window.bootstrap && typeof bootstrap.Modal === 'function') {
        let inst = bootstrap.Modal.getInstance(navMapModal);
        if (!inst) inst = new bootstrap.Modal(navMapModal);
        inst.show();
      } else {
        openReviewModal();
      }
    }

    if (btnPrev) btnPrev.addEventListener('click', () => setActivePane(currentIdx - 1));
    if (btnNext) {
      btnNext.addEventListener('click', function () {
        const answered = refreshAll();
        const total = panes.length;
        if (total > 0 && answered >= total) {
          showNavMapModal();
          return;
        }
        setActivePane(currentIdx + 1);
      });
    }

    form.addEventListener(
      'submit',
      function (e) {
        if (form.dataset.clmsExamConfirmSubmit === '1') {
          delete form.dataset.clmsExamConfirmSubmit;
          return;
        }
        e.preventDefault();
        e.stopPropagation();
        openReviewModal();
      },
      true
    );

    if (submitReviewBtn) {
      submitReviewBtn.addEventListener('click', function () {
        hideBootstrapModal(reviewModal);
        form.dataset.clmsExamConfirmSubmit = '1';
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit();
        } else {
          form.submit();
        }
      });
    }
  };
})();
