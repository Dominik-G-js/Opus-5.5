// AI Model Studio — drobná vylepšení bez závislostí (CSP: pouze 'self').
(function () {
  'use strict';

  // Potvrzení nevratných akcí: <form data-confirm="Opravdu smazat?"> otevře modální <dialog>
  // (Esc nebo klik mimo = zrušit). Bez podpory <dialog> zůstává window.confirm.
  var confirmDialog = null;

  function buildConfirmDialog() {
    var dialog = document.createElement('dialog');
    dialog.className = 'confirm-dialog';
    dialog.setAttribute('closedby', 'any');
    dialog.setAttribute('aria-labelledby', 'confirm-dialog-title');
    dialog.innerHTML = '<h2 id="confirm-dialog-title"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
      + ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/>'
      + '<path d="M12 9v4"/><path d="M12 17h.01"/></svg><span></span></h2>'
      + '<div class="confirm-actions"><button type="button" class="btn" value="cancel" autofocus>Zrušit</button>'
      + '<button type="button" class="btn btn-danger-solid" value="confirm"></button></div>';
    dialog.addEventListener('click', function (event) {
      var button = event.target.closest('button[value]');
      if (button) {
        dialog.close(button.value);
        return;
      }
      // Klik na pozadí zavře dialog i v prohlížečích bez atributu closedby (Safari).
      if (event.target === dialog && !('closedBy' in HTMLDialogElement.prototype)) {
        var rect = dialog.getBoundingClientRect();
        var inside = rect.top <= event.clientY && event.clientY <= rect.bottom && rect.left <= event.clientX && event.clientX <= rect.right;
        if (!inside) {
          dialog.close('cancel');
        }
      }
    });
    document.body.appendChild(dialog);

    return dialog;
  }

  function submitConfirmed(form, submitter) {
    form.dataset.confirmed = '1';
    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
    } else {
      form.submit();
    }
    delete form.dataset.confirmed;
  }

  function confirmLabel(submitter) {
    var text = submitter ? submitter.textContent.trim() : '';
    return text === '' ? 'Potvrdit' : text.charAt(0).toUpperCase() + text.slice(1);
  }

  document.addEventListener('submit', function (event) {
    var form = event.target;
    var message = form.getAttribute && form.getAttribute('data-confirm');
    if (!message || form.dataset.confirmed === '1') {
      return;
    }
    event.preventDefault();
    var submitter = event.submitter || null;
    if (typeof HTMLDialogElement !== 'function' || typeof HTMLDialogElement.prototype.showModal !== 'function') {
      if (window.confirm(message)) {
        submitConfirmed(form, submitter);
      }
      return;
    }
    confirmDialog = confirmDialog || buildConfirmDialog();
    confirmDialog.querySelector('h2 span').textContent = message;
    confirmDialog.querySelector('button[value="confirm"]').textContent = confirmLabel(submitter);
    confirmDialog.returnValue = '';
    confirmDialog.addEventListener('close', function onClose() {
      confirmDialog.removeEventListener('close', onClose);
      if (confirmDialog.returnValue === 'confirm') {
        submitConfirmed(form, submitter);
      }
    });
    confirmDialog.showModal();
  });

  // Přístupnost chyb: aria-invalid odpovídá vizuálnímu :user-invalid (chyba až po interakci)
  // a chybová hláška ze serveru se přiřadí ke svému poli (aria-describedby).
  function syncInvalid(field) {
    if (!field.matches || !field.matches('input, select, textarea')) {
      return;
    }
    var invalid = false;
    try {
      invalid = field.matches(':user-invalid');
    } catch (error) {
      invalid = false; // prohlížeč bez :user-invalid — spoléhá na nativní hlášky
    }
    if (invalid) {
      field.setAttribute('aria-invalid', 'true');
    } else if (!field.hasAttribute('data-server-error')) {
      field.removeAttribute('aria-invalid');
    }
  }
  document.addEventListener('blur', function (event) { syncInvalid(event.target); }, true);
  document.addEventListener('input', function (event) {
    var field = event.target;
    if (field.removeAttribute && field.hasAttribute('data-server-error')) {
      field.removeAttribute('data-server-error'); // uživatel pole opravuje, serverová chyba už neplatí
    }
    if (field.getAttribute && field.getAttribute('aria-invalid') === 'true') {
      syncInvalid(field);
    }
  });
  document.addEventListener('submit', function (event) {
    Array.prototype.forEach.call(event.target.elements || [], syncInvalid);
  }, true);
  Array.prototype.forEach.call(document.querySelectorAll('.field > .field-error'), function (error, index) {
    var field = error.parentNode.querySelector('input, select, textarea');
    if (!field) {
      return;
    }
    error.id = error.id || 'field-error-' + index;
    field.setAttribute('aria-invalid', 'true');
    field.setAttribute('data-server-error', '');
    var describedBy = field.getAttribute('aria-describedby');
    field.setAttribute('aria-describedby', describedBy ? describedBy + ' ' + error.id : error.id);
  });

  // Mobilní menu: po návratu zpět (bfcache) ani po roztažení okna nezůstane otevřené.
  var drawer = document.getElementById('nav-drawer');
  if (drawer && typeof drawer.hidePopover === 'function') {
    var closeDrawer = function () {
      if (drawer.matches(':popover-open')) {
        drawer.hidePopover();
      }
    };
    window.addEventListener('pageshow', function (event) {
      if (event.persisted) {
        closeDrawer();
      }
    });
    window.matchMedia('(min-width: 861px)').addEventListener('change', function (event) {
      if (event.matches) {
        closeDrawer();
      }
    });
  }

  // Kopírování promptu: <button data-copy="#id">
  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-copy]');
    if (!button) {
      return;
    }
    var source = document.querySelector(button.getAttribute('data-copy'));
    if (!source || !navigator.clipboard) {
      return;
    }
    navigator.clipboard.writeText(source.textContent).then(function () {
      var original = button.textContent;
      button.textContent = 'Zkopírováno';
      window.setTimeout(function () { button.textContent = original; }, 1500);
    });
  });

  // Tisk character bible: <button data-print>
  document.addEventListener('click', function (event) {
    if (event.target.closest('[data-print]')) {
      window.print();
    }
  });
})();

// Tooltip grafů: prvek s data-tip="ID" ukáže skrytý <div id="ID" class="viz-tip"> u kurzoru (nebo u prvku při focusu).
// Bez JS zůstává nativní <title>. Pozice přes CSSOM (povoleno CSP), žádné inline styly v HTML.
(function () {
  'use strict';

  var active = null;
  var activeTarget = null;
  var tips = document.querySelectorAll('.viz-tip');
  if (tips.length === 0) {
    return;
  }
  // Karta má backdrop-filter → position: fixed by se vztahoval ke kartě; tooltipy proto patří do <body>.
  Array.prototype.forEach.call(tips, function (tip) { document.body.appendChild(tip); });

  function place(tip, x, y) {
    var margin = 16;
    var rect = tip.getBoundingClientRect();
    var left = x + 18;
    if (left + rect.width > window.innerWidth - margin) {
      left = x - rect.width - 18;
    }
    var top = y - rect.height / 2;
    left = Math.max(margin, Math.min(left, window.innerWidth - rect.width - margin));
    top = Math.max(margin, Math.min(top, window.innerHeight - rect.height - margin));
    tip.style.left = left + 'px';
    tip.style.top = top + 'px';
  }

  function show(target, x, y) {
    var tip = document.getElementById(target.getAttribute('data-tip'));
    if (!tip) {
      return;
    }
    if (active && active !== tip) {
      active.hidden = true;
    }
    tip.hidden = false;
    active = tip;
    activeTarget = target;
    place(tip, x, y);
  }

  function hide() {
    if (active) {
      active.hidden = true;
      active = null;
      activeTarget = null;
    }
  }

  function showAtTarget(target) {
    var rect = target.getBoundingClientRect();
    show(target, rect.left + rect.width / 2, rect.top + rect.height / 2);
  }

  function onPointer(event) {
    var target = event.target.closest && event.target.closest('[data-tip]');
    if (target) {
      show(target, event.clientX, event.clientY);
    } else {
      hide();
    }
  }
  document.addEventListener('pointermove', onPointer);
  document.addEventListener('pointerdown', onPointer);
  document.addEventListener('focusin', function (event) {
    var target = event.target.closest && event.target.closest('[data-tip]');
    if (target) {
      showAtTarget(target);
    }
  });
  document.addEventListener('focusout', hide);
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      hide();
    }
  });
  // Posun stránky: tooltip u myši zmizí; u prvku s fokusem (klávesnice — fokus sám stránku posune)
  // zůstane a jde s prvkem, dokud je prvek vidět.
  window.addEventListener('scroll', function () {
    if (!active || activeTarget !== document.activeElement) {
      hide();
      return;
    }
    var rect = activeTarget.getBoundingClientRect();
    if (rect.bottom < 0 || rect.top > window.innerHeight) {
      hide();
    } else {
      showAtTarget(activeTarget);
    }
  }, { passive: true });
})();
