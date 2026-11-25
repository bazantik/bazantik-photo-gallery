// Potvrzení nevratného smazání (nativní okno) a výběr textu v poli; delegováno, aby fungovalo i po průběžné výměně řádků tabulky
document.addEventListener('submit', function (e) {
    const msg = e.target && e.target.getAttribute ? e.target.getAttribute('data-bazantik-confirm') : null;
    if (msg && !window.confirm(msg)) e.preventDefault();
});
document.addEventListener('click', function (e) {
    if (e.target && e.target.hasAttribute && e.target.hasAttribute('data-bazantik-select')) e.target.select();
});
document.addEventListener('DOMContentLoaded', function () {
    if (!window.wp || !wp.apiFetch) return;

    let lastRows = null;

    function schedule(delay) {
        setTimeout(tick, delay);
    }

    function tick() {
        if (document.visibilityState !== 'visible') {
            schedule(10000);
            return;
        }

        wp.apiFetch({ path: '/bazantik-photo-gallery/v1/queue', method: 'POST', data: {} }).then(function (res) {
            const counts = res.counts || {};
            Object.keys(counts).forEach(function (key) {
                const cell = document.querySelector('[data-bazantik-count="' + key + '"]');
                if (cell) cell.textContent = counts[key];
            });

            const delAll = document.getElementById('bazantik-delete-all-failed');
            if (delAll) delAll.hidden = !((counts.failed || 0) > 0);

            const th = document.querySelector('.bazantik-col-alba');
            const tbody = th ? th.closest('table').querySelector('tbody') : null;
            if (tbody) {
                if (res.rows !== lastRows) {
                    tbody.innerHTML = res.rows;
                    lastRows = res.rows;
                }
            } else if (res.rows) {
                window.location.reload(); // tabulka nedokončených fotek na stránce ještě nebyla
                return;
            }

            const active = (counts.pending || 0) + (counts.processing || 0);
            schedule(active > 0 ? 3000 : ((counts.failed || 0) > 0 ? 10000 : 30000));
        }).catch(function () {
            schedule(30000);
        });
    }

    schedule(6000);
});
