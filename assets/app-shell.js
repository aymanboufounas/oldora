(function () {
    var body = document.body;
    if (!body) return;
    body.classList.add('oldora-shared-shell');

    function openMenu() {
        body.classList.add('oldora-menu-open');
        body.style.overflow = 'hidden';
    }

    function closeMenu() {
        body.classList.remove('oldora-menu-open');
        body.style.overflow = '';
    }

    document.querySelectorAll('[data-oldora-menu-open]').forEach(function (button) {
        button.addEventListener('click', openMenu);
    });

    document.querySelectorAll('[data-oldora-menu-close]').forEach(function (button) {
        button.addEventListener('click', closeMenu);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeMenu();
    });

    window.addEventListener('resize', function () {
        if (window.innerWidth >= 992) closeMenu();
    });
})();
