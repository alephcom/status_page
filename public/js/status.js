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
