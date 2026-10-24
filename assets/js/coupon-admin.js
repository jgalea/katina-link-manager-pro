(function () {
    'use strict';

    var search = document.getElementById('katina-coupon-link-search');
    if (!search || !window.wp || !wp.apiFetch) {
        return;
    }

    var hidden = document.getElementById('katina-coupon-link-id');
    var current = document.getElementById('katina-coupon-link-current');
    var currentTitle = document.getElementById('katina-coupon-link-title');
    var clear = document.getElementById('katina-coupon-link-clear');
    var results = document.getElementById('katina-coupon-link-results');
    var strings = window.katinaProCoupon || {};
    var timer = null;

    function choose(id, title) {
        hidden.value = id;
        currentTitle.textContent = title;
        current.hidden = false;
        results.textContent = '';
        search.value = '';
    }

    function showMessage(text) {
        results.textContent = '';
        var li = document.createElement('li');
        li.textContent = text;
        results.appendChild(li);
    }

    function run(term) {
        wp.apiFetch({
            path: '/wp/v2/katina-links?per_page=10&_fields=id,title&search=' + encodeURIComponent(term)
        }).then(function (items) {
            if (!items.length) {
                showMessage(strings.noResults || '');
                return;
            }
            results.textContent = '';
            items.forEach(function (item) {
                var li = document.createElement('li');
                var btn = document.createElement('button');
                var title = item.title && item.title.rendered ? item.title.rendered : '#' + item.id;
                var tmp = document.createElement('textarea');
                tmp.innerHTML = title;
                btn.type = 'button';
                btn.className = 'button-link';
                btn.textContent = tmp.value;
                btn.addEventListener('click', function () {
                    choose(String(item.id), tmp.value);
                });
                li.appendChild(btn);
                results.appendChild(li);
            });
        }).catch(function () {
            results.textContent = '';
        });
    }

    search.addEventListener('input', function () {
        window.clearTimeout(timer);
        var term = search.value.trim();
        if (term.length < 2) {
            results.textContent = '';
            return;
        }
        timer = window.setTimeout(function () { run(term); }, 250);
    });

    search.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
        }
    });

    clear.addEventListener('click', function () {
        hidden.value = '';
        currentTitle.textContent = '';
        current.hidden = true;
    });
})();
