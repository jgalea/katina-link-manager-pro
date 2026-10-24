(function () {
    'use strict';

    function fallbackCopy(text) {
        var area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        var ok = false;
        try {
            ok = document.execCommand('copy');
        } catch (e) {
            ok = false;
        }
        document.body.removeChild(area);
        return ok;
    }

    function copy(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text).then(function () { return true; }, function () { return fallbackCopy(text); });
        }
        return Promise.resolve(fallbackCopy(text));
    }

    function announce(wrap, message) {
        var status = wrap.querySelector('.katina-coupons__status');
        if (status) {
            status.textContent = message;
        }
    }

    function track(item, name) {
        var wrap = item.closest('.katina-coupons');
        var url = wrap ? wrap.getAttribute('data-stats-url') : '';
        var id = item.getAttribute('data-coupon-id');
        if (!url || !id || !navigator.sendBeacon) {
            return;
        }
        navigator.sendBeacon(url, new URLSearchParams({ coupon_id: id, event: name }));
    }

    function trackCopy(item) {
        if (item.hasAttribute('data-copy-tracked')) {
            return;
        }
        item.setAttribute('data-copy-tracked', '');
        track(item, 'copy');
    }

    function observeViews() {
        if (!('IntersectionObserver' in window)) {
            return;
        }
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    observer.unobserve(entry.target);
                    track(entry.target, 'view');
                }
            });
        }, { threshold: 0.5 });
        document.querySelectorAll('.katina-coupons[data-stats-url] .katina-coupon[data-coupon-id]:not(.is-expired)').forEach(function (item) {
            observer.observe(item);
        });
    }

    function reveal(button) {
        var item = button.closest('.katina-coupon');
        var wrap = button.closest('.katina-coupons');
        var copyButton = item.querySelector('.katina-coupon__copy');
        var href = button.getAttribute('data-href');

        item.classList.remove('is-concealed');
        trackCopy(item);
        if (copyButton) {
            copyButton.focus();
            announce(wrap, wrap.getAttribute('data-revealed-label') + ' ' + copyButton.getAttribute('data-code'));
        }
        if (href) {
            track(item, 'click');
            if ('yes' === button.getAttribute('data-new-window')) {
                window.open(href, '_blank', 'noopener');
            } else {
                window.location.href = href;
            }
        }
    }

    document.querySelectorAll('.katina-coupon.has-reveal').forEach(function (item) {
        item.classList.add('is-concealed');
    });

    observeViews();

    document.addEventListener('click', function (event) {
        if (!event.target.closest) {
            return;
        }
        var revealButton = event.target.closest('.katina-coupon__reveal');
        if (revealButton) {
            reveal(revealButton);
            return;
        }
        var cta = event.target.closest('.katina-coupon__cta');
        if (cta) {
            track(cta.closest('.katina-coupon'), 'click');
            return;
        }
        var button = event.target.closest('.katina-coupon__copy');
        if (!button) {
            return;
        }
        trackCopy(button.closest('.katina-coupon'));
        var wrap = button.closest('.katina-coupons');
        var original = wrap.getAttribute('data-copy-label');
        var copied = wrap.getAttribute('data-copied-label');
        var failed = wrap.getAttribute('data-failed-label');
        var status = wrap.querySelector('.katina-coupons__status');

        copy(button.getAttribute('data-code')).then(function (ok) {
            var message = ok ? copied : failed;
            button.textContent = message;
            button.classList.toggle('is-copied', ok);
            if (status) {
                status.textContent = message;
            }
            window.setTimeout(function () {
                button.textContent = original;
                button.classList.remove('is-copied');
                if (status) {
                    status.textContent = '';
                }
            }, 2000);
        });
    });
})();
