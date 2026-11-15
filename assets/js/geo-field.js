// "Use my current location" on place fields (pastoral care: sick visit location, house address, funeral venue).
// The browser finds where the person is, OpenStreetMap turns that into a street address, and the exact
// latitude,longitude goes in a hidden field so the priest can open the spot in Google Maps.
// If the person has already allowed location for this site, an empty field is filled in automatically.
(function () {
    var boxes = document.querySelectorAll('.sx-geo[data-geo]');
    if (!boxes.length) return;

    function mapUrl(geo) { return 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(geo); }

    boxes.forEach(function (box) {
        var key = box.dataset.geo;
        var input = document.getElementById(key);
        var hidden = document.querySelector('input[data-geo-for="' + key + '"]');
        var btn = box.querySelector('.sx-geo-btn');
        var label = btn.querySelector('span');
        var msg = box.querySelector('.sx-geo-msg');
        var link = box.querySelector('.sx-geo-map');
        if (!input || !hidden) return;

        if (!('geolocation' in navigator) || !window.isSecureContext) {
            // Location only works on https (or localhost); without it the person simply types the address.
            btn.hidden = true;
            return;
        }

        function say(text, tone) { msg.textContent = text; msg.className = 'sx-geo-msg' + (tone ? ' is-' + tone : ''); }
        function busy(on) { btn.disabled = on; label.textContent = on ? 'Finding your location…' : 'Use my current location'; }

        function detect(quiet) {
            busy(true);
            if (!quiet) say('');
            navigator.geolocation.getCurrentPosition(function (pos) {
                var lat = pos.coords.latitude.toFixed(6), lng = pos.coords.longitude.toFixed(6), geo = lat + ',' + lng;
                var acc = Math.round(pos.coords.accuracy || 0);
                hidden.value = geo;
                link.href = mapUrl(geo);
                link.hidden = false;
                // Street address for the spot (OpenStreetMap). If that fails, the coordinates are used instead.
                fetch('https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=18&addressdetails=1&lat=' + lat + '&lon=' + lng, {
                    headers: { 'Accept-Language': document.documentElement.lang || 'en' }
                }).then(function (r) { return r.ok ? r.json() : null; }).catch(function () { return null; }).then(function (data) {
                    var addr = data && data.display_name ? data.display_name : geo;
                    input.value = addr.slice(0, parseInt(input.getAttribute('maxlength') || '255', 10));
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    busy(false);
                    say('Location found' + (acc ? ' (accurate to about ' + (acc >= 1000 ? (acc / 1000).toFixed(1) + ' km' : acc + ' m') + ')' : '') +
                        '. Please check it and add details like a room, ward or house number.', 'ok');
                    input.focus();
                });
            }, function (err) {
                busy(false);
                if (quiet) return;
                say(err.code === 1 ? 'Location is blocked. Allow location for this site in your browser, or type the address.'
                                   : 'Your location could not be found. Please type the address instead.', 'err');
            }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 });
        }

        btn.addEventListener('click', function () { detect(false); });

        // Adding a ward or house number keeps the spot; clearing the field drops it.
        input.addEventListener('input', function () {
            if (!input.value.trim() && hidden.value) { hidden.value = ''; link.hidden = true; say(''); }
        });

        // Already allowed before? Fill an empty field straight away (no permission prompt is shown).
        if (!input.value && navigator.permissions && navigator.permissions.query) {
            navigator.permissions.query({ name: 'geolocation' }).then(function (p) {
                if (p.state === 'granted' && !input.value) detect(true);
            }).catch(function () {});
        }
    });
})();
