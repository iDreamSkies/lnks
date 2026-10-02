/* lnks — tiny progressive enhancement. No dependencies. */
(function () {
    'use strict';

    var msg = document.body.dataset;   // translated labels come from <body data-copied data-copyfail>

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


    /* ── QR codes ───────────────────────────────────────────────────
     * Uses public/vendor/qrcode.js (MIT, Kazuhiko Arase), loaded only on pages with QR buttons.
     * Everything is generated in the browser; nothing is sent anywhere.
     */
    var QR_MARGIN = 4;   // quiet zone, in modules (minimum required by the spec)
    var qrUi = null;

    function qrMake(text) {
        window.qrcode.stringToBytes = window.qrcode.stringToBytesFuncs['UTF-8'];
        var levels = ['M', 'L'];   // fall back to lower error correction for very long URLs
        for (var i = 0; i < levels.length; i++) {
            try {
                var qr = window.qrcode(0, levels[i]);   // 0 = smallest version that fits
                qr.addData(text);
                qr.make();
                return qr;
            } catch (e) { /* data too long for this level, try the next one */ }
        }
        return null;
    }

    function qrSvg(qr) {
        var n = qr.getModuleCount(), size = n + QR_MARGIN * 2, d = '';
        for (var r = 0; r < n; r++) {
            for (var c = 0; c < n; c++) {
                if (qr.isDark(r, c)) d += 'M' + (c + QR_MARGIN) + ' ' + (r + QR_MARGIN) + 'h1v1h-1z';
            }
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + size + ' ' + size + '" shape-rendering="crispEdges">'
            + '<rect width="' + size + '" height="' + size + '" fill="#fff"/><path fill="#000" d="' + d + '"/></svg>';
    }

    function qrPng(qr, scale) {
        var n = qr.getModuleCount(), size = (n + QR_MARGIN * 2) * scale;
        var canvas = document.createElement('canvas');
        canvas.width = canvas.height = size;
        var ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, size, size);
        ctx.fillStyle = '#000';
        for (var r = 0; r < n; r++) {
            for (var c = 0; c < n; c++) {
                if (qr.isDark(r, c)) ctx.fillRect((c + QR_MARGIN) * scale, (r + QR_MARGIN) * scale, scale, scale);
            }
        }
        return canvas.toDataURL('image/png');
    }

    function el(tag, cls, text) {
        var x = document.createElement(tag);
        if (cls) x.className = cls;
        if (text) x.textContent = text;
        return x;
    }

    function qrBuildDialog() {
        var d = el('dialog', 'qr-dialog');
        d.setAttribute('aria-labelledby', 'qr-title');
        var title = el('h2', '', msg.qrTitle || 'QR code');
        title.id = 'qr-title';
        var ui = {
            dialog: d,
            preview: el('div', 'qr-preview'),
            caption: el('p', 'muted small break qr-url'),
            png: el('a', 'btn', msg.qrPng || 'PNG'),
            svg: el('a', 'btn ghost', msg.qrSvg || 'SVG'),
            close: el('button', 'btn ghost', msg.qrClose || 'Close')
        };
        ui.close.type = 'button';
        ui.close.addEventListener('click', function () { d.close(); });
        d.addEventListener('click', function (ev) { if (ev.target === d) d.close(); });   // backdrop click
        var actions = el('div', 'qr-actions');
        actions.appendChild(ui.png);
        actions.appendChild(ui.svg);
        actions.appendChild(ui.close);
        d.appendChild(title);
        d.appendChild(ui.preview);
        d.appendChild(ui.caption);
        d.appendChild(actions);
        document.body.appendChild(d);
        return ui;
    }

    function openQr(url, name) {
        if (!window.qrcode) return;
        var qr = qrMake(url);
        if (!qr) return;
        qrUi = qrUi || qrBuildDialog();
        var svg = qrSvg(qr);   // markup built from numbers only — safe to inject
        var file = 'qr-' + String(name || 'link').replace(/[^A-Za-z0-9_-]/g, '');
        qrUi.preview.innerHTML = svg;
        qrUi.caption.textContent = url;
        qrUi.png.href = qrPng(qr, 12);
        qrUi.png.setAttribute('download', file + '.png');
        qrUi.svg.href = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
        qrUi.svg.setAttribute('download', file + '.svg');
        if (typeof qrUi.dialog.showModal === 'function') qrUi.dialog.showModal();
        else qrUi.dialog.setAttribute('open', '');
        qrUi.png.focus();
    }

    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-copy]');
        if (btn) {
            // Icon buttons keep their icon: feedback goes to colour + tooltip instead of the text
            var iconOnly = btn.classList.contains('ibtn');
            var label = iconOnly ? btn.getAttribute('title') : btn.textContent;
            var show = function (text, cls) {
                if (iconOnly) { btn.setAttribute('title', text); btn.classList.add(cls); }
                else btn.textContent = text;
            };
            copyText(btn.getAttribute('data-copy')).then(function () {
                show(msg.copied || 'Copied!', 'ok');
            }, function () {
                show(msg.copyfail || 'Press Ctrl+C', 'fail');
            }).then(function () {
                setTimeout(function () {
                    if (iconOnly) { btn.setAttribute('title', label); btn.classList.remove('ok', 'fail'); }
                    else btn.textContent = label;
                }, 1500);
            });
            return;
        }
        var qrBtn = ev.target.closest('[data-qr]');
        if (qrBtn) {
            openQr(qrBtn.getAttribute('data-qr'), qrBtn.getAttribute('data-qr-name'));
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

    // UTM template picker fills the tag inputs of its form (the server also applies the template without JS)
    document.addEventListener('change', function (ev) {
        var sel = ev.target.closest('select[data-utm-select]');
        if (!sel) return;
        var opt = sel.options[sel.selectedIndex];
        var data = {};
        try { data = JSON.parse(opt.getAttribute('data-utm') || '{}'); } catch (e) { data = {}; }
        ['source', 'medium', 'campaign'].forEach(function (f) {
            var input = sel.form.querySelector('[name="utm_' + f + '"]');
            if (input) input.value = data[f] || '';
        });
    });

    // Select the freshly created short URL right away
    var result = document.querySelector('.result input[data-select]');
    if (result) result.select();
})();
