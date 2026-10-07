<?php

if (!function_exists('oldora_menu_page')) {
    function oldora_menu_page($file)
    {
        $map = [
            'home.php' => 'dashboard',
            'studio.php' => 'studio',
            'create-insta.php' => 'studio',
            'automation.php' => 'automation',
            'analytics.php' => 'analytics',
            'connect-platforms.php' => 'accounts',
            'connect.php' => 'accounts',
            'planing.php' => 'plans',
            'referral.php' => 'referrals',
            'support.php' => 'support',
            'profile.php' => 'settings'
        ];
        return isset($map[$file]) ? $map[$file] : '';
    }
}

if (!function_exists('oldora_menu_link')) {
    function oldora_menu_link($active, $key, $href, $icon, $label, $badge = '')
    {
        $class = $active === $key ? ' oldora-menu-active' : '';
        $badgeHtml = $badge !== '' ? '<span class="oldora-menu-badge">' . htmlspecialchars($badge) . '</span>' : '';
        return '<a class="oldora-menu-link' . $class . '" href="' . htmlspecialchars($href) . '"><i class="' . htmlspecialchars($icon) . '"></i><span>' . htmlspecialchars($label) . '</span>' . $badgeHtml . '</a>';
    }
}

if (!function_exists('oldora_menu_markup')) {
    function oldora_menu_markup($con, $file)
    {
        $active = oldora_menu_page($file);
        $name = 'Creator';
        $credits = 0;
        $plan = 'free';

        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['email']) && $con) {
            $stmt = $con->prepare('SELECT full_name, credits, plan_type FROM users WHERE email = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('s', $_SESSION['email']);
                if ($stmt->execute()) {
                    $row = $stmt->get_result()->fetch_assoc();
                    if ($row) {
                        $name = trim((string) ($row['full_name'] ?? 'Creator')) ?: 'Creator';
                        $credits = (int) ($row['credits'] ?? 0);
                        $plan = strtolower((string) ($row['plan_type'] ?? 'free'));
                    }
                }
                $stmt->close();
            }
        }

        $firstName = explode(' ', $name)[0];
        $initial = function_exists('mb_substr') ? mb_substr($firstName, 0, 1, 'UTF-8') : substr($firstName, 0, 1);

        $links = '';
        $links .= '<div class="oldora-menu-label">Workspace</div>';
        $links .= oldora_menu_link($active, 'dashboard', 'home.php', 'fa-solid fa-table-columns', 'Dashboard');
        $links .= oldora_menu_link($active, 'studio', 'studio.php', 'fa-solid fa-wand-magic-sparkles', 'Content Studio', 'AI');
        $links .= oldora_menu_link($active, 'channel-watch', 'home.php#youtube-watcher', 'fa-brands fa-youtube', 'Channel Watch', 'Auto');
        $links .= oldora_menu_link($active, 'automation', 'automation.php', 'fa-solid fa-calendar-check', 'Automation');
        $links .= oldora_menu_link($active, 'analytics', 'analytics.php', 'fa-solid fa-chart-line', 'Analytics');
        $links .= oldora_menu_link($active, 'accounts', 'connect-platforms.php', 'fa-solid fa-link', 'Connected accounts');
        $links .= '<div class="oldora-menu-label">Account</div>';
        $links .= oldora_menu_link($active, 'plans', 'planing.php', 'fa-solid fa-crown', 'Plans & credits');
        $links .= oldora_menu_link($active, 'referrals', 'referral.php', 'fa-solid fa-gift', 'Referrals');
        $links .= oldora_menu_link($active, 'support', 'support.php', 'fa-solid fa-headset', 'Support');
        $links .= oldora_menu_link($active, 'settings', 'profile.php', 'fa-solid fa-gear', 'Settings');

        return '<div class="oldora-menu-overlay" data-oldora-menu-close></div>
        <header class="oldora-mobile-header">
            <button type="button" class="oldora-menu-icon" data-oldora-menu-open aria-label="Open menu" aria-controls="oldoraSharedMenu" aria-expanded="false"><i class="fa-solid fa-bars"></i></button>
            <a class="oldora-mobile-brand" href="home.php"><img class="oldora-brand-logo" src="assets/oldora-mark.svg" alt="OLDORA"><strong>OLDORA</strong></a>
            <a class="oldora-credit-pill" href="planing.php"><i class="fa-solid fa-bolt"></i><span data-oldora-balance>' . $credits . '</span></a>
        </header>
        <aside class="oldora-menu-sidebar" id="oldoraSharedMenu" aria-label="Main navigation">
            <div class="oldora-menu-brand">
                <a href="home.php"><img class="oldora-brand-logo" src="assets/oldora-mark.svg" alt="OLDORA"><span><strong>OLDORA</strong><small>Creator operating system</small></span></a>
                <button type="button" class="oldora-menu-icon oldora-menu-close" data-oldora-menu-close aria-label="Close menu"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <a class="oldora-balance-card" href="planing.php"><span><small>Available credits</small><strong data-oldora-balance>' . $credits . '</strong></span><i class="fa-solid fa-arrow-up-right-from-square"></i></a>
            <nav class="oldora-menu-links">' . $links . '</nav>
            <div class="oldora-menu-user">
                <span class="oldora-menu-avatar">' . htmlspecialchars(strtoupper($initial)) . '</span>
                <span><strong>' . htmlspecialchars($firstName) . '</strong><small>' . htmlspecialchars(ucfirst($plan)) . ' plan</small></span>
                <a href="logout-user.php" aria-label="Logout"><i class="fa-solid fa-right-from-bracket"></i></a>
            </div>
        </aside>';
    }
}

if (!function_exists('oldora_inject_app_menu')) {
    function oldora_inject_app_menu($html, $con, $file)
    {
        if ($html === '' || stripos($html, '<html') === false || stripos($html, '<body') === false) {
            return $html;
        }

        $head = '<link rel="stylesheet" href="assets/app-shell.css?v=20261006-1">';
        $head .= '<link rel="stylesheet" href="assets/icons/css/all.min.css">';
        $html = preg_replace('/<\/head>/i', $head . '</head>', $html, 1);
        $html = preg_replace_callback('/<body\b([^>]*)>/i', function ($match) {
            $attributes = $match[1];
            if (preg_match('/\bclass\s*=\s*(["\'])(.*?)\1/i', $attributes, $classMatch)) {
                $replacement = 'class=' . $classMatch[1] . trim($classMatch[2] . ' oldora-shared-shell') . $classMatch[1];
                $attributes = preg_replace('/\bclass\s*=\s*(["\'])(.*?)\1/i', $replacement, $attributes, 1);
            } else {
                $attributes .= ' class="oldora-shared-shell"';
            }
            return '<body' . $attributes . '>';
        }, $html, 1);
        $markup = oldora_menu_markup($con, $file);
        $html = preg_replace('/(<body\b[^>]*>)/i', '$1' . $markup, $html, 1);
        $html = preg_replace('/<\/body>/i', '<script src="assets/app-shell.js?v=20261006-1"></script></body>', $html, 1);
        return $html;
    }
}

if (!function_exists('oldora_register_shared_menu')) {
    function oldora_register_shared_menu($con)
    {
        $file = basename(parse_url($_SERVER['SCRIPT_NAME'] ?? '', PHP_URL_PATH));
        $pages = [
            'home.php', 'studio.php', 'create-insta.php', 'automation.php', 'analytics.php',
            'connect-platforms.php', 'connect.php', 'planing.php',
            'referral.php', 'support.php', 'profile.php'
        ];
        if (!in_array($file, $pages, true)) {
            return;
        }

        ob_start(function ($html) use ($con, $file) {
            return oldora_inject_app_menu($html, $con, $file);
        });
    }
}
