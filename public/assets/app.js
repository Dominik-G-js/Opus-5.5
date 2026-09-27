// AI Model Studio — drobná vylepšení bez závislostí (CSP: pouze 'self').
(function () {
  'use strict';

  // Potvrzení nevratných akcí: <form data-confirm="Opravdu smazat?">
  document.addEventListener('submit', function (event) {
    var form = event.target;
    var message = form.getAttribute && form.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
      event.preventDefault();
    }
  });

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
    place(tip, x, y);
  }

  function hide() {
    if (active) {
      active.hidden = true;
      active = null;
    }
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
      var rect = target.getBoundingClientRect();
      show(target, rect.left + rect.width / 2, rect.top + rect.height / 2);
    }
  });
  document.addEventListener('focusout', hide);
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      hide();
    }
  });
  window.addEventListener('scroll', hide, { passive: true });
})();
