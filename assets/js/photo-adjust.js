// Adjust a photo before it is uploaded: move it, zoom it, or fit the whole picture into the frame.
//
// Works on any <input type="file" data-adjust="16:10"> (the frame's width:height; add data-adjust-shape="round"
// for a profile picture). When a photo is chosen, this opens the adjuster; "Use photo" swaps the chosen file for the
// adjusted one and then lets the page carry on exactly as before (its own preview or save still runs). The server
// receives an ordinary JPG, so nothing changes there. Loaded on every page by includes/footer.php.
(function () {
    'use strict';
    if (!window.DataTransfer || !HTMLCanvasElement.prototype.toBlob) return;   // very old browser: upload the photo as it is

    var MAX_WIDTH = 1600;   // the saved photo is never wider than this
    var overlay, frame, imgEl, zoomEl, hintEl;
    var st = null;          // the photo being adjusted

    function build() {
        overlay = document.createElement('div');
        overlay.className = 'pa-overlay';
        overlay.hidden = true;
        overlay.innerHTML =
            '<div class="pa-box" role="dialog" aria-modal="true" aria-labelledby="pa-title">' +
                '<h2 class="pa-title" id="pa-title">Adjust photo</h2>' +
                '<p class="pa-hint" id="pa-hint"></p>' +
                '<div class="pa-stage"><div class="pa-frame" tabindex="0" aria-label="Photo. Drag, or use the arrow keys, to move it."><img alt="" draggable="false"></div></div>' +
                '<div class="pa-zoom"><button type="button" class="pa-step" data-step="-1" aria-label="Zoom out">&minus;</button>' +
                    '<input type="range" min="0" max="100" value="0" step="1" aria-label="Zoom">' +
                    '<button type="button" class="pa-step" data-step="1" aria-label="Zoom in">+</button></div>' +
                '<div class="pa-modes"><button type="button" data-mode="fill">Fill the frame</button><button type="button" data-mode="fit">Fit whole photo</button></div>' +
                '<div class="pa-actions"><button type="button" class="pa-cancel">Cancel</button><button type="button" class="pa-ok">Use photo</button></div>' +
            '</div>';
        document.body.appendChild(overlay);
        frame = overlay.querySelector('.pa-frame');
        imgEl = frame.querySelector('img');
        zoomEl = overlay.querySelector('input[type=range]');
        hintEl = overlay.querySelector('.pa-hint');

        // Drag to move (mouse, touch and pen).
        var drag = null;
        frame.addEventListener('pointerdown', function (ev) {
            if (!st) return;
            drag = { x: ev.clientX, y: ev.clientY, ox: st.x, oy: st.y };
            frame.setPointerCapture(ev.pointerId);
            frame.classList.add('is-dragging');
        });
        frame.addEventListener('pointermove', function (ev) {
            if (!drag) return;
            st.x = drag.ox + ev.clientX - drag.x;
            st.y = drag.oy + ev.clientY - drag.y;
            draw();
        });
        ['pointerup', 'pointercancel'].forEach(function (t) {
            frame.addEventListener(t, function () { drag = null; frame.classList.remove('is-dragging'); });
        });
        frame.addEventListener('wheel', function (ev) { ev.preventDefault(); zoomTo(Number(zoomEl.value) + (ev.deltaY < 0 ? 5 : -5)); }, { passive: false });
        frame.addEventListener('keydown', function (ev) {
            var d = { ArrowLeft: [-12, 0], ArrowRight: [12, 0], ArrowUp: [0, -12], ArrowDown: [0, 12] }[ev.key];
            if (!d || !st) return;
            ev.preventDefault();
            st.x += d[0]; st.y += d[1];
            draw();
        });
        zoomEl.addEventListener('input', function () { zoomTo(Number(zoomEl.value)); });
        overlay.querySelectorAll('.pa-step').forEach(function (b) {
            b.addEventListener('click', function () { zoomTo(Number(zoomEl.value) + Number(b.dataset.step) * 8); });
        });
        overlay.querySelector('[data-mode=fill]').addEventListener('click', function () { zoomTo(st.fillAt, true); });
        overlay.querySelector('[data-mode=fit]').addEventListener('click', function () { zoomTo(0, true); });
        overlay.querySelector('.pa-cancel').addEventListener('click', cancel);
        overlay.querySelector('.pa-ok').addEventListener('click', apply);
        overlay.addEventListener('click', function (ev) { if (ev.target === overlay) cancel(); });
        document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && st) cancel(); });
        window.addEventListener('resize', function () { if (st) layout(true); });
    }

    // Slider 0..100 -> scale, from "whole photo visible" up to 3x "frame filled".
    function scaleAt(v) { return st.min * Math.pow(st.max / st.min, v / 100); }

    function zoomTo(v, recentre) {
        v = Math.max(0, Math.min(100, v));
        var before = st.scale, cx = st.fw / 2, cy = st.fh / 2;
        st.scale = scaleAt(v);
        zoomEl.value = v;
        if (recentre) {
            st.x = (st.fw - st.nw * st.scale) / 2;
            st.y = (st.fh - st.nh * st.scale) / 2;
        } else {   // keep the middle of the frame where it is
            st.x = cx - (cx - st.x) * st.scale / before;
            st.y = cy - (cy - st.y) * st.scale / before;
        }
        draw();
    }

    // Keep the photo inside the frame: no empty edge where it is larger than the frame, centred where it is smaller.
    function clamp() {
        var w = st.nw * st.scale, h = st.nh * st.scale;
        st.x = w <= st.fw ? (st.fw - w) / 2 : Math.min(0, Math.max(st.fw - w, st.x));
        st.y = h <= st.fh ? (st.fh - h) / 2 : Math.min(0, Math.max(st.fh - h, st.y));
    }

    function draw() {
        clamp();
        imgEl.style.width = (st.nw * st.scale) + 'px';
        imgEl.style.height = (st.nh * st.scale) + 'px';
        imgEl.style.transform = 'translate(' + st.x + 'px,' + st.y + 'px)';
    }

    // Size the frame to the window, then place the photo (filling the frame the first time).
    function layout(keep) {
        var maxW = Math.min(460, window.innerWidth - 72), maxH = Math.max(160, window.innerHeight - 330);
        var fw = maxW, fh = fw * st.rh / st.rw;
        if (fh > maxH) { fh = maxH; fw = fh * st.rw / st.rh; }
        var ratio = keep && st.fw ? fw / st.fw : 1;
        st.fw = fw; st.fh = fh;
        frame.style.width = fw + 'px';
        frame.style.height = fh + 'px';
        var contain = Math.min(fw / st.nw, fh / st.nh), cover = Math.max(fw / st.nw, fh / st.nh);
        st.min = contain;
        st.max = cover * 3;
        st.fillAt = 100 * Math.log(cover / st.min) / Math.log(st.max / st.min);
        if (keep) {
            st.scale *= ratio; st.x *= ratio; st.y *= ratio;
            draw();
        } else {
            st.scale = cover;
            zoomTo(st.fillAt, true);
        }
    }

    function open(input, file) {
        if (!overlay) build();
        var parts = (input.dataset.adjust || '1:1').split(':');
        var rw = Number(parts[0]) || 1, rh = Number(parts[1]) || 1;
        var url = URL.createObjectURL(file);
        var probe = new Image();
        probe.onload = function () {
            st = { input: input, file: file, url: url, rw: rw, rh: rh, nw: probe.naturalWidth, nh: probe.naturalHeight, x: 0, y: 0, scale: 1, fw: 0, fh: 0, last: document.activeElement };
            imgEl.src = url;
            frame.classList.toggle('is-round', input.dataset.adjustShape === 'round');
            hintEl.textContent = 'Drag the photo to move it and use the slider to zoom, so it sits the way you want in the frame.';
            overlay.hidden = false;
            document.body.classList.add('pa-open');
            layout(false);
            overlay.querySelector('.pa-ok').focus();
        };
        probe.onerror = function () { URL.revokeObjectURL(url); pass(input); };   // not a picture we can show: let the page and server deal with it
        probe.src = url;
    }

    function close() {
        overlay.hidden = true;
        document.body.classList.remove('pa-open');
        URL.revokeObjectURL(st.url);
        imgEl.removeAttribute('src');
        var last = st.last;
        st = null;
        if (last && last.focus) last.focus();
    }

    function cancel() {
        if (!st || st.busy) return;
        st.input.value = '';   // nothing chosen, so nothing is uploaded
        close();
    }

    // Hand the (adjusted) file back to the page: its own "change" handlers run now.
    function pass(input) {
        input._paDone = true;
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function apply() {
        var s = st;
        if (!s || s.busy) return;   // a second click while the photo is being prepared
        s.busy = true;
        var outW = Math.round(Math.max(320, Math.min(MAX_WIDTH, s.fw / s.scale)));
        var outH = Math.round(outW * s.fh / s.fw);
        var k = outW / s.fw;
        var canvas = document.createElement('canvas');
        canvas.width = outW; canvas.height = outH;
        var ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff';   // behind a photo that was fitted whole into the frame
        ctx.fillRect(0, 0, outW, outH);
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(imgEl, s.x * k, s.y * k, s.nw * s.scale * k, s.nh * s.scale * k);
        canvas.toBlob(function (blob) {
            if (blob) {
                var name = (s.file.name || 'photo').replace(/\.[^.]*$/, '') + '.jpg';
                var dt = new DataTransfer();
                dt.items.add(new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() }));
                s.input.files = dt.files;
            }
            close();
            pass(s.input);
        }, 'image/jpeg', 0.9);
    }

    // Before the page's own handlers see a newly chosen photo, adjust it.
    document.addEventListener('change', function (ev) {
        var input = ev.target;
        if (!input || input.type !== 'file' || !input.dataset || !input.dataset.adjust) return;
        if (input._paDone) { input._paDone = false; return; }
        var file = input.files && input.files[0];
        if (!file || !/^image\/(jpeg|png|webp)$/.test(file.type)) return;   // anything else is left for the page and server to refuse
        ev.stopImmediatePropagation();
        open(input, file);
    }, true);
})();
