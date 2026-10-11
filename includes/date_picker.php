<script>
// Calendars that grey out what is already taken: a wedding date (day), or a visit / session (day + time pills).
// The plain input is kept (hidden) and carries the value, so the form works the same either way.
(function () {
    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    var DOW = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function iso(y, m, d) { return y + '-' + pad(m + 1) + '-' + pad(d); }
    function label(h) { var n = parseInt(h, 10); return (n % 12 || 12) + ':00 ' + (n < 12 ? 'am' : 'pm'); }

    document.querySelectorAll('.dp').forEach(function (dp) {
        var input = document.getElementById(dp.dataset.target);
        if (!input) return;
        var slots = dp.dataset.mode === 'datetime';
        var taken = JSON.parse(dp.dataset.taken || (slots ? '{}' : '[]'));
        var hours = slots ? dp.dataset.hours.split(',') : [];
        var allowed = dp.dataset.dow ? dp.dataset.dow.split(',').map(Number) : null;   // days of the week that can be picked
        var min = dp.dataset.min, max = dp.dataset.max;
        var urgency = slots ? document.getElementById('urgency') : null;
        input.type = 'hidden';

        var sel = { date: '', hour: '' };
        var m = /^(\d{4}-\d{2}-\d{2})(?:T(\d{2}))?/.exec(input.value || '');
        if (m) { sel.date = m[1]; sel.hour = m[2] || ''; }
        var view = sel.date ? new Date(+sel.date.slice(0, 4), +sel.date.slice(5, 7) - 1, 1) : new Date(+min.slice(0, 4), +min.slice(5, 7) - 1, 1);

        function hoursTaken(date) {   // an Emergency request is never blocked
            return (urgency && urgency.value === 'emergency') ? [] : (taken[date] || []);
        }
        function dayState(date) {
            if (date < min || date > max) return 'off';
            if (allowed && allowed.indexOf(new Date(date + 'T12:00:00').getDay()) === -1) return 'off';
            if (slots) return hoursTaken(date).length >= hours.length ? 'taken' : '';
            return taken.indexOf(date) > -1 ? 'taken' : '';
        }
        function commit() {
            input.value = slots ? (sel.date && sel.hour ? sel.date + 'T' + sel.hour + ':00' : '') : sel.date;
        }

        function render() {
            var y = view.getFullYear(), mo = view.getMonth();
            var first = new Date(y, mo, 1).getDay(), days = new Date(y, mo + 1, 0).getDate();
            var minView = new Date(+min.slice(0, 4), +min.slice(5, 7) - 1, 1), maxView = new Date(+max.slice(0, 4), +max.slice(5, 7) - 1, 1);
            var html = '<div class="cf-month"><button type="button" class="cf-arrow" data-nav="-1" aria-label="Previous month"' + (view <= minView ? ' disabled' : '') + '>&#8249;</button>'
                + '<h3>' + MONTHS[mo] + ', ' + y + '</h3>'
                + '<button type="button" class="cf-arrow" data-nav="1" aria-label="Next month"' + (view >= maxView ? ' disabled' : '') + '>&#8250;</button></div><div class="cf-cal">';
            DOW.forEach(function (d, i) { html += '<span class="cf-dow' + (i === 0 ? ' is-sun' : '') + '">' + d + '</span>'; });
            for (var i = 0; i < first; i++) html += '<span></span>';
            for (var d = 1; d <= days; d++) {
                var date = iso(y, mo, d), st = dayState(date), sun = (first + d - 1) % 7 === 0;
                var cls = 'cf-day' + (sun ? ' is-sun' : '') + (date === sel.date ? ' is-selected' : '') + (st ? ' is-' + st : '');
                html += '<button type="button" class="' + cls + '" data-date="' + date + '"' + (st ? ' disabled' : '')
                    + (st === 'taken' ? ' title="Already booked"' : '') + '>' + d + '</button>';
            }
            html += '</div>';
            if (slots) {
                var busy = sel.date ? hoursTaken(sel.date) : [];
                var now = new Date(), today = iso(now.getFullYear(), now.getMonth(), now.getDate());
                html += '<h4 class="dp-times-title">Available time</h4><div class="dp-times">';
                if (!sel.date) html += '<span class="dp-hint">Choose a day first.</span>';
                else hours.forEach(function (h) {
                    var past = sel.date === today && parseInt(h, 10) <= now.getHours();
                    var isBusy = busy.indexOf(h) > -1;
                    html += '<button type="button" class="dp-time' + (h === sel.hour ? ' is-selected' : '') + (isBusy ? ' is-taken' : '') + '" data-hour="' + h + '"'
                        + (isBusy || past ? ' disabled' : '') + (isBusy ? ' title="Already booked"' : '') + '>' + label(h) + '</button>';
                });
                html += '</div>';
            }
            html += '<p class="dp-key"><span class="dp-swatch"></span> Greyed-out days and times are not available.</p>';
            dp.innerHTML = html;
        }

        dp.addEventListener('click', function (ev) {
            var t = ev.target.closest('button');
            if (!t || t.disabled) return;
            if (t.dataset.nav) { view = new Date(view.getFullYear(), view.getMonth() + (+t.dataset.nav), 1); render(); return; }
            if (t.dataset.date) {
                sel.date = t.dataset.date;
                if (slots && hoursTaken(sel.date).indexOf(sel.hour) > -1) sel.hour = '';
                commit(); render();
            } else if (t.dataset.hour) {
                sel.hour = t.dataset.hour;
                commit(); render();
            }
        });
        if (urgency) urgency.addEventListener('change', function () { if (sel.hour && hoursTaken(sel.date).indexOf(sel.hour) > -1) { sel.hour = ''; commit(); } render(); });
        render();
    });
})();
</script>
