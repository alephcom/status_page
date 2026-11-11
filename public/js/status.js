// Show times in the visitor's locale and timezone, and refresh the status
// section in place (no full reload, no flicker) on pages that ask for it.
(function () {
    function localiseTimes(root) {
        root.querySelectorAll('time[datetime]').forEach(function (el) {
            var d = new Date(el.getAttribute('datetime'));
            if (isNaN(d)) return;
            el.textContent = el.dataset.format === 'time'
                ? d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
                : d.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
            el.title = d.toString();
        });
    }

    localiseTimes(document);

    var root = document.getElementById('status-root');
    if (!root) return;
    var seconds = parseInt(root.dataset.refresh || '0', 10);
    if (!seconds) return;

    setInterval(function () {
        if (document.hidden) return;
        fetch(window.location.href, { headers: { 'X-Requested-With': 'fetch' } })
            .then(function (r) { return r.ok ? r.text() : Promise.reject(r.status); })
            .then(function (html) {
                var next = new DOMParser().parseFromString(html, 'text/html').getElementById('status-root');
                if (!next) return;
                // Keep groups the visitor opened or closed as they were.
                var open = {};
                root.querySelectorAll('details').forEach(function (d, i) { open[i] = d.open; });
                root.innerHTML = next.innerHTML;
                root.querySelectorAll('details').forEach(function (d, i) { if (i in open) d.open = open[i]; });
                localiseTimes(root);
            })
            .catch(function () { /* keep showing what we have */ });
    }, seconds * 1000);
})();

// Theme toggle: automatic → light → dark, remembered in this browser.
(function () {
    var button = document.querySelector('[data-theme-toggle]');
    if (!button) return;
    var root = document.documentElement;
    var order = ['auto', 'light', 'dark'];
    function label() { button.textContent = button.dataset['label' + root.dataset.theme.charAt(0).toUpperCase() + root.dataset.theme.slice(1)] || button.textContent; }
    label();
    button.addEventListener('click', function () {
        root.dataset.theme = order[(order.indexOf(root.dataset.theme) + 1) % order.length];
        try { localStorage.setItem('statuspage-theme', root.dataset.theme); } catch (e) {}
        label();
    });
})();

// Metric charts: a crosshair that snaps to the nearest sample, with its value and time.
(function () {
    function bind(plot) {
        if (plot.dataset.bound) return;
        plot.dataset.bound = '1';
        var points;
        try { points = JSON.parse(plot.dataset.chart || '[]'); } catch (e) { return; }
        if (points.length < 2) return;
        var times = points.map(function (p) { return new Date(p.t).getTime(); });
        var first = times[0], span = Math.max(1, times[times.length - 1] - first);
        var cursor = plot.querySelector('.metric-cursor'), tip = plot.querySelector('.metric-tip');

        function show(clientX) {
            var box = plot.getBoundingClientRect();
            var at = first + Math.min(1, Math.max(0, (clientX - box.left) / box.width)) * span;
            var i = 0, best = Infinity;
            times.forEach(function (t, j) { var d = Math.abs(t - at); if (d < best) { best = d; i = j; } });
            var x = (times[i] - first) / span * box.width;
            cursor.style.left = x + 'px';
            tip.style.left = Math.min(box.width - 60, Math.max(60, x)) + 'px';
            tip.textContent = '';
            var value = document.createElement('strong');
            value.textContent = points[i].v;
            tip.appendChild(value);
            tip.appendChild(document.createTextNode(' · ' + new Date(times[i]).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })));
            cursor.hidden = tip.hidden = false;
        }

        function hide() { cursor.hidden = tip.hidden = true; }

        plot.addEventListener('pointermove', function (e) { show(e.clientX); });
        plot.addEventListener('pointerleave', hide);
    }

    function bindAll(root) { root.querySelectorAll('.metric-plot').forEach(bind); }
    bindAll(document);
    // The status section is refreshed in place; rebind after each refresh.
    var root = document.getElementById('status-root');
    if (root && window.MutationObserver) new MutationObserver(function () { bindAll(root); }).observe(root, { childList: true });
})();
