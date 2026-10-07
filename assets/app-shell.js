(function () {
    var body = document.body;
    if (!body) return;
    body.classList.add('oldora-shared-shell');
    var sidebar = document.getElementById('oldoraSharedMenu');
    var toggles = Array.from(document.querySelectorAll('[data-oldora-menu-open]'));
    var previousFocus, previousOverflow = '', open = false;
    function mobile() { return window.innerWidth < 992; }
    function syncMenu() {
        if (!sidebar) return;
        sidebar.inert = mobile() && !open;
        sidebar.setAttribute('aria-hidden', String(mobile() && !open));
        toggles.forEach(function (button) { button.setAttribute('aria-expanded', String(open)); });
    }
    function openMenu() {
        if (!mobile() || !sidebar) return;
        previousFocus = document.activeElement;
        previousOverflow = body.style.overflow;
        open = true;
        body.classList.add('oldora-menu-open');
        body.style.overflow = 'hidden';
        syncMenu();
        var close = sidebar.querySelector('[data-oldora-menu-close]');
        if (close) close.focus();
    }
    function closeMenu() {
        if (open) body.style.overflow = previousOverflow;
        open = false;
        body.classList.remove('oldora-menu-open');
        syncMenu();
        if (mobile() && previousFocus && previousFocus.isConnected) previousFocus.focus();
    }
    toggles.forEach(function (button) { button.addEventListener('click', openMenu); });
    document.querySelectorAll('[data-oldora-menu-close]').forEach(function (button) { button.addEventListener('click', closeMenu); });
    document.addEventListener('keydown', function (event) {
        if (!open) return;
        if (event.key === 'Escape') { event.preventDefault(); closeMenu(); return; }
        if (event.key !== 'Tab' || !sidebar) return;
        var controls = Array.from(sidebar.querySelectorAll('a[href],button:not([disabled]),[tabindex="0"]')).filter(function (el) { return el.getClientRects().length; });
        var first = controls[0], last = controls[controls.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });
    window.addEventListener('resize', function () { if (!mobile()) closeMenu(); else syncMenu(); });
    syncMenu();

    var lastRefresh = 0, inFlight = false, authorized = true;
    function showBalance(value) {
        if (!Number.isSafeInteger(value) || value < 0) return;
        document.querySelectorAll('[data-oldora-balance]').forEach(function (el) {
            el.dataset.oldoraBalanceValue = String(value);
            el.textContent = value.toLocaleString();
        });
    }
    window.addEventListener('oldora:balance', function (event) { if (event.detail) showBalance(event.detail.credits); });
    function refreshBalance() {
        if (!authorized || inFlight || document.hidden || Date.now() - lastRefresh < 3000) return;
        inFlight = true;
        lastRefresh = Date.now();
        var controller = new AbortController();
        var timer = setTimeout(function () { controller.abort(); }, 8000);
        fetch('account-balance.php', { credentials: 'same-origin', cache: 'no-store', signal: controller.signal })
            .then(function (response) { if (response.status === 401) authorized = false; if (!response.ok) throw new Error('Balance unavailable'); return response.json(); })
            .then(function (data) { if (data.ok) window.dispatchEvent(new CustomEvent('oldora:balance', {detail: {credits: data.credits}})); })
            .catch(function () {})
            .finally(function () { clearTimeout(timer); inFlight = false; });
    }
    window.addEventListener('focus', refreshBalance);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) refreshBalance(); });
    setInterval(refreshBalance, 30000);
    refreshBalance();
})();
