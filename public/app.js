/* lnks — tiny progressive enhancement. No dependencies. */
(function () {
    'use strict';

    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (resolve, reject) {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy') ? resolve() : reject(); } catch (e) { reject(e); }
            document.body.removeChild(ta);
        });
    }

    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-copy]');
        if (btn) {
            var label = btn.textContent;
            copyText(btn.getAttribute('data-copy')).then(function () {
                btn.textContent = 'Copied!';
            }, function () {
                btn.textContent = 'Press Ctrl+C';
            }).then(function () {
                setTimeout(function () { btn.textContent = label; }, 1500);
            });
            return;
        }
        var sel = ev.target.closest('[data-select]');
        if (sel) sel.select();
    });

    // <button data-confirm="…"> asks before submitting its form
    document.addEventListener('submit', function (ev) {
        var b = ev.submitter;
        if (b && b.hasAttribute('data-confirm') && !window.confirm(b.getAttribute('data-confirm'))) {
            ev.preventDefault();
        }
    });

    // Select the freshly created short URL right away
    var result = document.querySelector('.result input[data-select]');
    if (result) result.select();
})();
