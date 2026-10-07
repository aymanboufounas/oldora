(function () {
    var busy = false, freshness = document.getElementById('adminFreshness');
    function cell(row, value) { var td = document.createElement('td'); td.textContent = value; row.appendChild(td); return td; }
    function money(value) { return Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function refresh() {
        if (busy || document.hidden) return;
        busy = true;
        var controller = new AbortController(), timer = setTimeout(function () { controller.abort(); }, 10000);
        fetch('admin-metrics.php', { credentials: 'same-origin', cache: 'no-store', signal: controller.signal })
            .then(function (response) { if (!response.ok) throw new Error('Unavailable'); return response.json(); })
            .then(function (data) {
                if (!data.ok) throw new Error('Unavailable');
                document.querySelectorAll('[data-admin-metric]').forEach(function (el) { var key = el.dataset.adminMetric; el.textContent = key === 'revenue_usd' ? money(data.summary[key]) : Number(data.summary[key]).toLocaleString(); });
                var rows = document.getElementById('adminPaymentRows');
                rows.replaceChildren();
                if (!data.recent_payments.length) { var empty = document.createElement('tr'), td = cell(empty, 'No invoices yet. Confirmed payments will appear here.'); td.colSpan = 6; td.className = 'admin-empty'; rows.appendChild(empty); }
                data.recent_payments.forEach(function (invoice) {
                    var row = document.createElement('tr'), account = cell(row, ''), order = document.createElement('strong'), email = document.createElement('small');
                    order.textContent = invoice.order_id; email.textContent = invoice.user_email; account.append(order, email);
                    cell(row, invoice.plan_name); cell(row, '$' + money(invoice.amount_usd)); cell(row, Number(invoice.credits).toLocaleString());
                    var status = document.createElement('span'); status.className = 'admin-status'; status.dataset.state = invoice.status; status.textContent = invoice.status.replaceAll('_', ' '); cell(row, '').appendChild(status);
                    var detail = cell(row, invoice.provider_status || 'Awaiting provider'); if (invoice.last_error) { var error = document.createElement('small'); error.className = 'admin-error'; error.textContent = invoice.last_error; detail.appendChild(error); }
                    rows.appendChild(row);
                });
                if (data.worker) document.getElementById('adminWorkerState').textContent = 'Automation worker: ' + data.worker.state.replaceAll('_', ' ');
                freshness.textContent = 'Updated ' + new Date().toLocaleTimeString();
            })
            .catch(function () { freshness.textContent = 'Connection interrupted · showing last confirmed data'; })
            .finally(function () { clearTimeout(timer); busy = false; });
    }
    setInterval(refresh, 30000);
    window.addEventListener('focus', refresh);
})();
