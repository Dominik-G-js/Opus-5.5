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
