// Church picker: turns <select data-church-picker> into a searchable list that shows each church with its
// town ("St Michael Church" / Penampang). The real <select> stays in the page (hidden) and still sends the form,
// so without JavaScript the plain dropdown works as before. Each <option> carries its town in data-loc.
(function () {
    var pin = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>';
    var tick = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>';
    var chev = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>';
    var search = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>';
    var uid = 0;

    function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

    function build(select) {
        var id = 'cp' + (++uid);
        var opts = Array.prototype.map.call(select.options, function (o) {
            return { value: o.value, name: o.dataset.name || o.textContent.trim(), loc: o.dataset.loc || '', photo: o.dataset.photo || '' };
        });
        var label = select.id ? document.querySelector('label[for="' + select.id + '"]') : null;

        var wrap = document.createElement('div');
        wrap.className = 'cp';
        wrap.innerHTML =
            '<button type="button" class="cpk-btn" aria-haspopup="listbox" aria-expanded="false" aria-controls="' + id + '-list">' +
                '<span class="cpk-btn-t"><b></b><small></small></span>' + chev +
            '</button>' +
            '<div class="cpk-pop" hidden>' +
                '<div class="cpk-search">' + search + '<input type="text" placeholder="Search church or town" aria-label="Search church or town" autocomplete="off" aria-controls="' + id + '-list"></div>' +
                '<ul class="cpk-list" role="listbox" id="' + id + '-list"></ul>' +
                '<p class="cpk-none" hidden>No church matches your search.</p>' +
            '</div>';
        select.parentNode.insertBefore(wrap, select.nextSibling);
        select.classList.add('cpk-native');
        select.tabIndex = -1;
        select.setAttribute('aria-hidden', 'true');

        var btn = wrap.querySelector('.cpk-btn');
        var pop = wrap.querySelector('.cpk-pop');
        var input = wrap.querySelector('.cpk-search input');
        var list = wrap.querySelector('.cpk-list');
        var none = wrap.querySelector('.cpk-none');
        if (label) { label.removeAttribute('for'); label.id = label.id || id + '-label'; btn.setAttribute('aria-labelledby', label.id + ' ' + id + '-val'); }
        btn.querySelector('.cpk-btn-t').id = id + '-val';

        list.innerHTML = opts.map(function (o, i) {
            return '<li role="option" id="' + id + '-o' + i + '" data-i="' + i + '" aria-selected="false">' +
                // the church's photo, or its initial when it has none (or the photo fails to load)
                '<span class="cpk-av" data-initial="' + esc(o.name.replace(/^(St|Church of|Holy|Blessed|Sacred|Good)\s+/i, '').charAt(0).toUpperCase()) + '">' +
                    (o.photo ? '<img src="' + esc(o.photo) + '" alt="" loading="lazy" onerror="this.parentNode.textContent=this.parentNode.dataset.initial">'
                             : esc(o.name.replace(/^(St|Church of|Holy|Blessed|Sacred|Good)\s+/i, '').charAt(0).toUpperCase())) +
                '</span>' +
                '<span class="cpk-t"><b>' + esc(o.name) + '</b>' + (o.loc ? '<small>' + pin + ' ' + esc(o.loc) + '</small>' : '') + '</span>' +
                '<span class="cpk-tick">' + tick + '</span></li>';
        }).join('');
        var items = Array.prototype.slice.call(list.children);
        var active = -1;

        function paintButton() {
            var o = opts[select.selectedIndex] || opts[0];
            btn.querySelector('b').textContent = o ? o.name : '';
            btn.querySelector('small').textContent = o && o.loc ? o.loc : '';
            items.forEach(function (li, i) { li.setAttribute('aria-selected', i === select.selectedIndex ? 'true' : 'false'); });
        }
        function visible() { return items.filter(function (li) { return !li.hidden; }); }
        function setActive(li) {
            items.forEach(function (x) { x.classList.remove('is-active'); });
            if (!li) { active = -1; input.removeAttribute('aria-activedescendant'); return; }
            li.classList.add('is-active');
            active = +li.dataset.i;
            input.setAttribute('aria-activedescendant', li.id);
            li.scrollIntoView({ block: 'nearest' });
        }
        function open() {
            pop.hidden = false;
            wrap.classList.add('is-open');
            btn.setAttribute('aria-expanded', 'true');
            input.value = '';
            filter();
            setActive(items[select.selectedIndex]);
            input.focus();
        }
        function close(focusBtn) {
            pop.hidden = true;
            wrap.classList.remove('is-open');
            btn.setAttribute('aria-expanded', 'false');
            if (focusBtn) btn.focus();
        }
        function choose(i) {
            close(true);
            if (i === select.selectedIndex) return;
            select.selectedIndex = i;
            paintButton();
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }
        function filter() {
            var q = input.value.trim().toLowerCase();
            var shown = 0;
            items.forEach(function (li, i) {
                var o = opts[i];
                var hit = !q || (o.name + ' ' + o.loc).toLowerCase().indexOf(q) !== -1;
                li.hidden = !hit;
                if (hit) shown++;
            });
            none.hidden = shown > 0;
            var v = visible();
            if (active === -1 || items[active].hidden) setActive(v[0] || null);
        }

        btn.addEventListener('click', function () { pop.hidden ? open() : close(false); });
        btn.addEventListener('keydown', function (ev) {
            if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') { ev.preventDefault(); open(); }
        });
        input.addEventListener('input', filter);
        input.addEventListener('keydown', function (ev) {
            var v = visible();
            var pos = v.indexOf(items[active]);
            if (ev.key === 'ArrowDown') { ev.preventDefault(); setActive(v[Math.min(v.length - 1, pos + 1)] || null); }
            else if (ev.key === 'ArrowUp') { ev.preventDefault(); setActive(v[Math.max(0, pos - 1)] || null); }
            else if (ev.key === 'Enter') { ev.preventDefault(); if (active !== -1 && !items[active].hidden) choose(active); }
            else if (ev.key === 'Escape') { ev.preventDefault(); close(true); }
            else if (ev.key === 'Tab') { close(false); }
        });
        list.addEventListener('click', function (ev) {
            var li = ev.target.closest('li');
            if (li) choose(+li.dataset.i);
        });
        list.addEventListener('mousemove', function (ev) {
            var li = ev.target.closest('li');
            if (li && +li.dataset.i !== active) setActive(li);
        });
        document.addEventListener('mousedown', function (ev) { if (!pop.hidden && !wrap.contains(ev.target)) close(false); });

        paintButton();
    }

    function init() { document.querySelectorAll('select[data-church-picker]').forEach(build); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
