// View a profile picture full size.
//
// Works on any element with data-photo-view that holds an <img> (the round avatar on profile.php and account.php).
// While that image is showing a photo, the avatar can be clicked, or focused and opened with Enter / Space; with no
// photo (the default icon or initials) it is left alone. The image is watched, so a photo just picked with
// "Upload New Photo" can be viewed before it is saved. Loaded on every page by includes/footer.php.
(function () {
    'use strict';
    var overlay, bigImg, caption, closeBtn, opener = null;

    function build() {
        overlay = document.createElement('div');
        overlay.className = 'pv-overlay';
        overlay.hidden = true;
        overlay.innerHTML =
            '<figure class="pv-box" role="dialog" aria-modal="true" aria-labelledby="pv-caption">' +
                '<img alt="">' +
                '<figcaption id="pv-caption"></figcaption>' +
                '<button type="button" class="pv-close">Close</button>' +
            '</figure>';
        document.body.appendChild(overlay);
        bigImg = overlay.querySelector('img');
        caption = overlay.querySelector('figcaption');
        closeBtn = overlay.querySelector('.pv-close');
        closeBtn.addEventListener('click', close);
        overlay.addEventListener('click', function (ev) { if (ev.target === overlay) close(); });
        document.addEventListener('keydown', function (ev) {
            if (overlay.hidden) return;
            if (ev.key === 'Escape') close();
            if (ev.key === 'Tab') { ev.preventDefault(); closeBtn.focus(); }   // the only control inside, so focus stays here
        });
    }

    function photoOf(el) {
        var img = el.querySelector('img');
        return img && !img.hidden && img.getAttribute('src') ? img : null;
    }

    function open(el) {
        var img = photoOf(el);
        if (!img) return;
        if (!overlay) build();
        opener = el;
        bigImg.src = img.src;
        bigImg.alt = img.alt || 'Profile photo';
        caption.textContent = img.alt || 'Profile photo';
        overlay.hidden = false;
        document.body.classList.add('pv-open');
        closeBtn.focus();
    }

    function close() {
        overlay.hidden = true;
        document.body.classList.remove('pv-open');
        if (opener) opener.focus();
    }

    // Only an avatar that shows a photo is a button.
    function sync(el) {
        var on = !!photoOf(el);
        el.classList.toggle('is-viewable', on);
        if (on) {
            el.setAttribute('role', 'button');
            el.setAttribute('tabindex', '0');
            el.setAttribute('aria-label', 'View your profile photo full size');
            el.title = 'View photo';
        } else {
            ['role', 'tabindex', 'aria-label', 'title'].forEach(function (a) { el.removeAttribute(a); });
        }
    }

    document.querySelectorAll('[data-photo-view]').forEach(function (el) {
        sync(el);
        var img = el.querySelector('img');
        if (img && window.MutationObserver) {
            new MutationObserver(function () { sync(el); }).observe(img, { attributes: true, attributeFilter: ['src', 'hidden'] });
        }
        el.addEventListener('click', function () { open(el); });
        el.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); open(el); }
        });
    });
})();
