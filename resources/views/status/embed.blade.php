/* Status widget. Add <script src="{{ url('/embed.js') }}" async></script> to a page.
   It shows a small floating pill, or fills any element with data-statuspage-widget. */
(function () {
    var statusUrl = @json($statusUrl, JSON_UNESCAPED_SLASHES), apiUrl = @json($apiUrl, JSON_UNESCAPED_SLASHES);
    var colors = @json(\App\Support\Badge::COLORS);
    var labels = @json($labels);

    function pill(data) {
        var a = document.createElement('a');
        a.href = statusUrl;
        a.target = '_blank';
        a.rel = 'noopener';
        a.style.cssText = 'display:inline-flex;align-items:center;gap:8px;padding:6px 12px;border-radius:999px;' +
            'background:#fff;color:#1d2330;border:1px solid #e3e6eb;box-shadow:0 1px 3px rgba(0,0,0,.12);' +
            'font:13px/1.3 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;text-decoration:none;';
        var dot = document.createElement('span');
        dot.style.cssText = 'width:10px;height:10px;border-radius:50%;flex:none;background:' + (colors[data.status] || '#6b7280');
        var text = document.createElement('span');
        text.textContent = labels[data.status] || data.status_label;
        a.appendChild(dot);
        a.appendChild(text);
        if (data.active_incidents && data.active_incidents.length) {
            var inc = document.createElement('strong');
            inc.textContent = '· ' + data.active_incidents[0].title;
            inc.style.cssText = 'font-weight:600;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;';
            a.appendChild(inc);
        }
        return a;
    }

    function render(data) {
        var targets = document.querySelectorAll('[data-statuspage-widget]');
        if (targets.length) {
            targets.forEach(function (el) { el.textContent = ''; el.appendChild(pill(data)); });
            return;
        }
        var box = document.getElementById('statuspage-widget') || document.createElement('div');
        box.id = 'statuspage-widget';
        box.style.cssText = 'position:fixed;left:16px;bottom:16px;z-index:2147483000;';
        box.textContent = '';
        box.appendChild(pill(data));
        if (!box.parentNode) document.body.appendChild(box);
    }

    function load() {
        fetch(apiUrl, { headers: { Accept: 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
            .then(render)
            .catch(function () {});
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load); else load();
    setInterval(load, 120000);
})();
