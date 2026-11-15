// Installable app: registers the service worker (sw.js) and offers "Install app".
//  - Android / Chrome / Edge: the browser's own install prompt, opened from our banner or any [data-pwa-install] button.
//  - iPhone / iPad (Safari has no prompt): a short "Share > Add to Home Screen" hint.
//  - Already running as the app, or dismissed: nothing is shown. "Not now" hides it for 14 days.
(function () {
    var me = document.currentScript;
    var base = (me && me.dataset.base) || '';

    if ('serviceWorker' in navigator && window.isSecureContext) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register(base + 'sw.js', { scope: base || './' }).catch(function () {});
        });
    }

    var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    if (standalone) { document.documentElement.classList.add('is-app'); return; }

    var KEY = 'pwa-dismissed-until';
    function snoozed() { try { return Date.now() < +(localStorage.getItem(KEY) || 0); } catch (e) { return false; } }
    function snooze() { try { localStorage.setItem(KEY, String(Date.now() + 14 * 864e5)); } catch (e) {} }

    var deferred = null, banner = null;
    var isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    var icon = base + 'assets/images/app/icon-192.png';
    var shareSvg = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><path d="m16 6-4-4-4 4"/><path d="M12 2v13"/></svg>';

    function hide(remember) {
        if (remember) snooze();
        if (banner) { banner.classList.remove('is-in'); setTimeout(function () { banner && banner.remove(); banner = null; }, 250); }
        document.body.classList.remove('pwa-banner-on');
    }

    function show(ios) {
        if (banner || snoozed()) return;
        banner = document.createElement('div');
        banner.className = 'pwa-banner';
        banner.setAttribute('role', 'dialog');
        banner.setAttribute('aria-label', 'Install the app');
        banner.innerHTML =
            '<img src="' + icon + '" alt="" width="44" height="44">' +
            '<div class="pwa-t"><b>Install the ArchKK app</b>' +
            (ios ? '<small>Tap ' + shareSvg + ' <b>Share</b>, then <b>Add to Home Screen</b>.</small>'
                 : '<small>Mass times, events and your passes, one tap away.</small>') + '</div>' +
            (ios ? '' : '<button type="button" class="pwa-go">Install</button>') +
            '<button type="button" class="pwa-x" aria-label="Not now">&times;</button>';
        document.body.appendChild(banner);
        document.body.classList.add('pwa-banner-on');
        requestAnimationFrame(function () { banner.classList.add('is-in'); });
        banner.querySelector('.pwa-x').addEventListener('click', function () { hide(true); });
        var go = banner.querySelector('.pwa-go');
        if (go) go.addEventListener('click', install);
    }

    function install() {
        if (!deferred) return;
        deferred.prompt();
        deferred.userChoice.then(function (c) { if (c.outcome !== 'accepted') snooze(); hide(false); deferred = null; });
    }

    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();               // keep the prompt for our own button
        deferred = e;
        document.querySelectorAll('[data-pwa-install]').forEach(function (b) { b.hidden = false; });
        setTimeout(function () { show(false); }, 2500);
    });
    window.addEventListener('appinstalled', function () { hide(false); deferred = null; });

    document.addEventListener('click', function (e) {
        var b = e.target.closest && e.target.closest('[data-pwa-install]');
        if (!b) return;
        e.preventDefault();
        if (deferred) install(); else show(isIOS);
    });

    // Safari on iPhone/iPad never fires beforeinstallprompt: show the hint after a moment.
    if (isIOS && /safari/i.test(navigator.userAgent) && !/crios|fxios|edgios/i.test(navigator.userAgent)) {
        window.addEventListener('load', function () { setTimeout(function () { show(true); }, 3000); });
        document.querySelectorAll('[data-pwa-install]').forEach(function (b) { b.hidden = false; });
    }
})();
