/**
 * clms-exam-sequencing.js
 *
 * Drag-and-drop UI for "sequencing" exam questions. Replaces the legacy
 * "Select order 1..N" dropdowns with a reorderable list while preserving
 * the existing form payload shape (responses[<qid>][sequence][<answer_id>])
 * so grade_exam.php, autosave_exam.php, grade_mock_exam.php, and
 * autosave_mock_exam.php keep working without changes.
 *
 * Each list looks like:
 *   <ol class="clms-seq-list" data-clms-sequence-list data-clms-qid="42">
 *     <li class="clms-seq-item" data-answer-id="7" draggable="true">
 *       …handle, position badge, label, up/down buttons,
 *       <input type="hidden" name="responses[42][sequence][7]" value="1">
 *     </li>
 *     …
 *   </ol>
 *
 * The script:
 *   - Renumbers position badges + hidden inputs after every reorder.
 *   - Dispatches a bubbling "input" event on the form so the existing
 *     autosave + nav-status code reacts to drag/keyboard moves.
 *   - Provides Up/Down buttons for keyboard + touch users (HTML5
 *     drag-and-drop is not reliable on mobile).
 */
(function () {
  'use strict';

  function dispatchSyntheticInput(list) {
    const evt = new Event('input', { bubbles: true });
    list.dispatchEvent(evt);
  }

  function renumber(list) {
    const items = Array.from(list.querySelectorAll(':scope > .clms-seq-item'));
    items.forEach(function (item, idx) {
      const pos = idx + 1;
      const badge = item.querySelector('.clms-seq-pos');
      if (badge) badge.textContent = String(pos);
      const hidden = item.querySelector('input[type="hidden"][data-clms-seq-input]');
      if (hidden) hidden.value = String(pos);
      item.setAttribute('aria-posinset', String(pos));
      item.setAttribute('aria-setsize', String(items.length));
    });
  }

  function moveItem(list, item, delta) {
    const items = Array.from(list.querySelectorAll(':scope > .clms-seq-item'));
    const idx = items.indexOf(item);
    if (idx < 0) return;
    const next = idx + delta;
    if (next < 0 || next >= items.length) return;
    if (delta < 0) {
      list.insertBefore(item, items[next]);
    } else {
      list.insertBefore(item, items[next].nextSibling);
    }
    renumber(list);
    dispatchSyntheticInput(list);
    item.focus();
  }

  function bindButtons(list, item) {
    const up = item.querySelector('[data-clms-seq-up]');
    const down = item.querySelector('[data-clms-seq-down]');
    if (up) {
      up.addEventListener('click', function (e) {
        e.preventDefault();
        moveItem(list, item, -1);
      });
    }
    if (down) {
      down.addEventListener('click', function (e) {
        e.preventDefault();
        moveItem(list, item, +1);
      });
    }
  }

  function bindKeyboard(list, item) {
    item.addEventListener('keydown', function (e) {
      // Only react when focus is on the row itself or the handle, not
      // on the Up/Down buttons (they have their own click handlers and
      // accept Space/Enter natively).
      const tag = (e.target && e.target.tagName) || '';
      if (tag === 'BUTTON' || tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;

      if (e.key === 'ArrowUp' || (e.key === 'k' && !e.ctrlKey && !e.metaKey)) {
        e.preventDefault();
        moveItem(list, item, -1);
      } else if (e.key === 'ArrowDown' || (e.key === 'j' && !e.ctrlKey && !e.metaKey)) {
        e.preventDefault();
        moveItem(list, item, +1);
      }
    });
  }

  function clearDropTargets(list) {
    list.querySelectorAll('.clms-seq-item.is-drop-target').forEach(function (n) {
      n.classList.remove('is-drop-target');
    });
  }

  function bindDrag(list, item) {
    item.addEventListener('dragstart', function (e) {
      item.classList.add('is-dragging');
      if (e.dataTransfer) {
        e.dataTransfer.effectAllowed = 'move';
        try {
          // Firefox needs *some* payload or it won't initiate the drag.
          e.dataTransfer.setData('text/plain', item.getAttribute('data-answer-id') || '');
        } catch (_) {}
      }
    });

    item.addEventListener('dragend', function () {
      item.classList.remove('is-dragging');
      clearDropTargets(list);
    });

    item.addEventListener('dragover', function (e) {
      const dragging = list.querySelector('.clms-seq-item.is-dragging');
      if (!dragging || dragging === item) return;
      e.preventDefault();
      if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';
      clearDropTargets(list);
      item.classList.add('is-drop-target');
    });

    item.addEventListener('dragleave', function () {
      item.classList.remove('is-drop-target');
    });

    item.addEventListener('drop', function (e) {
      const dragging = list.querySelector('.clms-seq-item.is-dragging');
      if (!dragging || dragging === item) return;
      e.preventDefault();
      clearDropTargets(list);

      const rect = item.getBoundingClientRect();
      const dropAfter = e.clientY - rect.top > rect.height / 2;
      if (dropAfter) {
        list.insertBefore(dragging, item.nextSibling);
      } else {
        list.insertBefore(dragging, item);
      }
      renumber(list);
      dispatchSyntheticInput(list);
    });
  }

  function initList(list) {
    if (list.dataset.clmsSeqInit === '1') return;
    list.dataset.clmsSeqInit = '1';

    const items = Array.from(list.querySelectorAll(':scope > .clms-seq-item'));
    items.forEach(function (item) {
      bindButtons(list, item);
      bindKeyboard(list, item);
      bindDrag(list, item);
    });

    renumber(list);
  }

  function initAll(root) {
    const scope = root || document;
    const lists = scope.querySelectorAll('[data-clms-sequence-list]');
    lists.forEach(initList);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      initAll(document);
    });
  } else {
    initAll(document);
  }

  // Expose for manually-rendered content.
  window.clmsInitExamSequencing = initAll;
})();
