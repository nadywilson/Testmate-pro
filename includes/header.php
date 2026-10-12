<?php
/**
 * TestMate - Namibia Learner's Licence
 * Shared Header and Sidebar
 * Standalone PHP 8.5 / SQLite
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$is_logged_in = !empty($_SESSION['user_id']);

$current_page = basename(
    (string)($_SERVER['SCRIPT_NAME'] ?? 'index.php')
);

$user_name = trim(
    (string)($_SESSION['name'] ?? 'Learner')
);

$user_name = $user_name !== ''
    ? $user_name
    : 'Learner';

$is_admin = $is_logged_in &&
    strtolower((string)($_SESSION['role'] ?? '')) === 'admin';

$show_sidebar = $is_logged_in &&
    $current_page !== 'index.php';

$tmEsc = static fn($value): string =>
    htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

// Locate the stylesheet for the standalone PHP server.

$tmCssFile = dirname(__DIR__) . '/css/style.css';
$tmCssHref = '/css/style.css';

if (
    !is_file($tmCssFile) &&
    is_file(dirname(__DIR__) . '/assets/css/style.css')
) {
    $tmCssFile = dirname(__DIR__) . '/assets/css/style.css';
    $tmCssHref = '/assets/css/style.css';
}

if (is_file($tmCssFile)) {
    $tmCssHref .= '?v=' . filemtime($tmCssFile);
}

// Navigation links

$tmNav = [
    ['Dashboard', '/dashboard.php', 'dashboard.php', 'dashboard'],
    ['Study Materials', '/study-materials.php', 'study-materials.php', 'book'],
    ['Video Demonstrations', '/video-demonstrations.php', 'video-demonstrations.php', 'video'],
    ['Simulations', '/simulations.php', 'simulations.php', 'car'],
    ['Quizzes', '/quiz.php', 'quiz.php', 'quiz'],
    ['History', '/quiz-history.php', 'quiz-history.php', 'history'],
    ['Mock Test', '/mock-test.php', 'mock-test.php', 'mock'],
    ['My Progress', '/progress.php', 'progress.php', 'progress'],
    ['Rankings', '/rankings.php', 'rankings.php', 'trophy'],
    ['My Profile', '/profile.php', 'profile.php', 'user']
];

// Built-in SVG icons (work without Font Awesome)

$tmSvgPaths = [
    'dashboard' =>
        '<rect x="3" y="3" width="7" height="7" rx="1"/>
         <rect x="14" y="3" width="7" height="7" rx="1"/>
         <rect x="3" y="14" width="7" height="7" rx="1"/>
         <rect x="14" y="14" width="7" height="7" rx="1"/>',

    'book' =>
        '<path d="M12 6c-2.5-1.8-5.4-2.1-9-1v14c3.6-1.1 6.5-.8 9 1 2.5-1.8 5.4-2.1 9-1V5c-3.6-1.1-6.5-.8-9 1Z"/>
         <path d="M12 6v14"/>',

    'video' =>
        '<rect x="3" y="4" width="18" height="16" rx="3"/>
         <path d="m10 8 6 4-6 4V8Z"/>',

    'car' =>
        '<path d="M5 17H3v-5l2.2-6h13.6l2.2 6v5h-2"/>
         <path d="M5 17h14M5 12h14"/>
         <circle cx="7" cy="17" r="1"/>
         <circle cx="17" cy="17" r="1"/>',

    'quiz' =>
        '<rect x="5" y="3" width="14" height="18" rx="2"/>
         <path d="M9 3.5h6M8 10l2 2 4-4M8 16h8"/>',

    'history' =>
        '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/>
         <path d="M3 3v5h5M12 7v5l3 2"/>',

    'mock' =>
        '<path d="M8 3h8l4 4v14H4V3h4Z"/>
         <path d="M16 3v5h4M8 12h8M8 16h6"/>',

    'progress' =>
        '<path d="M3 20h18M6 17v-4M12 17V9M18 17V5"/>',

    'trophy' =>
        '<path d="M7 4h10v6a5 5 0 0 1-10 0V4Z"/>
         <path d="M7 6H4v3c0 2 1.4 3 3.4 3M17 6h3v3c0 2-1.4 3-3.4 3M12 15v5M8 21h8"/>',

    'user' =>
        '<circle cx="12" cy="8" r="4"/>
         <path d="M4 21a8 8 0 0 1 16 0"/>'
];

$tmIcon = static function (string $key) use ($tmSvgPaths): string {
    $paths = $tmSvgPaths[$key] ?? $tmSvgPaths['dashboard'];

    return '<svg viewBox="0 0 24 24"
                 width="19"
                 height="19"
                 fill="none"
                 stroke="currentColor"
                 stroke-width="1.8"
                 stroke-linecap="round"
                 stroke-linejoin="round"
                 aria-hidden="true">'
                 . $paths .
           '</svg>';
};

?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>TestMate - Namibia Learner's Licence</title>

<link
    rel="stylesheet"
    href="<?= $tmEsc($tmCssHref) ?>"
>

<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
>

<style>

/* =====================================================
   TESTMATE - SHARED LAYOUT REPAIR
   ===================================================== */

html body.tm-modern {
    margin: 0 !important;
    background: #f4f8fd !important;
    color: #173452 !important;
    font-family: "Segoe UI", Arial, sans-serif !important;
    font-size: 14px !important;
    line-height: 1.5 !important;
    padding-top: 74px !important;
}

html body.tm-modern,
html body.tm-modern * {
    box-sizing: border-box;
}

@media (min-width: 901px) {
    html body.tm-modern.has-sidebar {
        padding-left: 270px !important;
    }
}

/* =====================================================
   NAVBAR
   ===================================================== */

html body.tm-modern > nav.navbar {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;

    width: 100% !important;
    max-width: none !important;
    height: 74px !important;
    min-height: 74px !important;

    padding: 0 26px !important;

    background: linear-gradient(
        110deg,
        #102744 0%,
        #193554 60%,
        #102744 100%
    ) !important;

    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;

    gap: 16px !important;

    border: none !important;

    box-shadow: 0 3px 13px rgba(15,39,66,.12) !important;

    z-index: 1200 !important;
}

html body.tm-modern > nav.navbar a {
    text-decoration: none !important;
}

/* BRAND */

html body.tm-modern > nav.navbar .brand {
    display: inline-flex !important;
    flex-shrink: 0;
    align-items: center !important;

    gap: 11px !important;

    color: #fff !important;

    font-family: "Segoe UI", Arial, sans-serif !important;
    font-size: 23px !important;
    font-weight: 800 !important;

    white-space: nowrap;
}

html body.tm-modern > nav.navbar .brand-icon {
    width: 42px !important;
    height: 42px !important;

    display: grid !important;
    place-items: center;

    border-radius: 11px !important;

    background: linear-gradient(
        135deg,
        #19adff,
        #147df5
    ) !important;

    color: #fff !important;

    font: 800 23px "Segoe UI", Arial, sans-serif !important;
}

html body.tm-modern > nav.navbar .brand-name {
    color: #fff !important;
}

/* NAVIGATION LINKS */

html body.tm-modern > nav.navbar .nav-links {
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    flex-wrap: nowrap !important;

    gap: 18px !important;

    margin-left: auto !important;
}

html body.tm-modern > nav.navbar
.nav-links a:not(.btn-logout) {
    color: #e5f0fb !important;
    background: transparent !important;

    font: 500 14px "Segoe UI", Arial, sans-serif !important;

    white-space: nowrap;
}

html body.tm-modern > nav.navbar
.nav-links a:not(.btn-logout):hover {
    color: #fff !important;
}

/* LOGOUT BUTTON */

html body.tm-modern > nav.navbar
.nav-links .btn-logout {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;

    min-height: 42px;

    padding: 10px 18px !important;

    border: none !important;
    border-radius: 9px !important;

    background: #ee493d !important;
    color: #fff !important;

    font: 700 14px "Segoe UI", Arial, sans-serif !important;

    white-space: nowrap;
}

html body.tm-modern > nav.navbar
.nav-links .btn-logout:hover {
    background: #d9362b !important;
}

/* =====================================================
   SIDEBAR
   ===================================================== */

html body.tm-modern > .tm-sidebar {
    position: fixed !important;

    top: 74px !important;
    bottom: 0 !important;
    left: 0 !important;
    right: auto !important;

    width: 270px !important;
    height: calc(100vh - 74px) !important;

    padding: 22px 14px 18px !important;

    display: flex !important;
    flex-direction: column !important;

    overflow-y: auto !important;

    background: linear-gradient(
        180deg,
        #0a3637,
        #082c2e
    ) !important;

    color: #fff !important;

    border: none !important;
    box-shadow: none !important;

    z-index: 1080 !important;
}

/* SIDEBAR MENU */

html body.tm-modern > .tm-sidebar
.tm-sidebar-menu {
    display: flex !important;
    flex-direction: column !important;

    gap: 4px !important;

    flex: 1 0 auto;
    width: 100%;
}

html body.tm-modern > .tm-sidebar
.tm-sidebar-menu a.tm-nav-link {
    display: flex !important;
    align-items: center !important;

    gap: 12px !important;

    min-height: 46px !important;
    width: 100% !important;

    padding: 11px 13px !important;
    margin: 0 !important;

    background: transparent !important;
    color: #d8e8e9 !important;

    border: none !important;
    border-radius: 10px !important;

    font: 500 14px "Segoe UI", Arial, sans-serif !important;

    text-decoration: none !important;
    text-align: left !important;

    transition:
        background .15s ease,
        color .15s ease;
}

html body.tm-modern > .tm-sidebar
.tm-sidebar-menu a.tm-nav-link:hover {
    background: rgba(255,255,255,.09) !important;
    color: #fff !important;
}

html body.tm-modern > .tm-sidebar
.tm-sidebar-menu a.tm-nav-link.active {
    background: #125a5c !important;
    color: #fff !important;
    font-weight: 700 !important;
}

/* SIDEBAR ICONS */

html body.tm-modern > .tm-sidebar
.tm-nav-icon {
    width: 22px;
    height: 22px;

    flex-shrink: 0;

    display: inline-flex;
    align-items: center;
    justify-content: center;
}

/* SIDEBAR MOTIVATION */

html body.tm-modern > .tm-sidebar
.tm-sidebar-motivation {
    margin: auto 0 0 !important;
    padding: 18px 10px 3px !important;

    background: transparent !important;

    border: none !important;
    border-top: 1px solid rgba(255,255,255,.13) !important;

    border-radius: 0 !important;

    color: #fff !important;
}

html body.tm-modern > .tm-sidebar
.tm-sidebar-motivation strong {
    display: block;

    color: #fff !important;
    font-size: 13px;

    margin-bottom: 6px;
}

html body.tm-modern > .tm-sidebar
.tm-sidebar-motivation p {
    margin: 0;

    color: #b5cbcc !important;

    font-size: 12px !important;
    line-height: 1.6;
}

/* =====================================================
   MOBILE MENU
   ===================================================== */

html body.tm-modern .tm-mobile-menu {
    display: none !important;

    width: 40px;
    height: 40px;

    align-items: center;
    justify-content: center;

    padding: 0;

    border: none;
    border-radius: 9px;

    background: transparent;
    color: #fff;

    cursor: pointer;
}

html body.tm-modern .tm-mobile-menu svg {
    width: 23px;
    height: 23px;
}

html body.tm-modern > .tm-mobile-overlay {
    display: none !important;
}

/* =====================================================
   RESPONSIVE
   ===================================================== */

@media (max-width: 900px) {

    html body.tm-modern.has-sidebar {
        padding-left: 0 !important;
    }

    html body.tm-modern > nav.navbar {
        padding: 0 16px !important;
        gap: 10px !important;
    }

    html body.tm-modern .tm-mobile-menu {
        display: inline-flex !important;
    }

    html body.tm-modern > .tm-sidebar {
        top: 74px !important;

        width: min(270px,86vw) !important;
        height: calc(100vh - 74px) !important;

        transform: translateX(-110%) !important;

        transition: transform .22s ease;

        z-index: 1150 !important;
    }

    html body.tm-modern.tm-menu-open > .tm-sidebar {
        transform: translateX(0) !important;
    }

    html body.tm-modern.tm-menu-open > .tm-mobile-overlay {
        display: block !important;

        position: fixed !important;

        inset: 74px 0 0 !important;

        background: rgba(6,18,35,.55) !important;

        z-index: 1120 !important;
    }
}

@media (max-width: 560px) {

    html body.tm-modern > nav.navbar .brand {
        font-size: 19px !important;
    }

    html body.tm-modern > nav.navbar .brand-icon {
        width: 37px !important;
        height: 37px !important;
    }

    html body.tm-modern > nav.navbar .nav-links {
        gap: 8px !important;
    }

    html body.tm-modern > nav.navbar .tm-home-link,
    html body.tm-modern > nav.navbar .tm-user-link {
        display: none !important;
    }

    html body.tm-modern > nav.navbar
    .nav-links .btn-logout {
        padding: 8px 11px !important;
    }
}

</style>

</head>

<body class="tm-modern<?= $show_sidebar ? ' has-sidebar' : '' ?>">

<!-- ==========================================
     TOP NAVIGATION
     ========================================== -->

<nav class="navbar" aria-label="Top navigation">

    <?php if ($show_sidebar): ?>

        <button
            type="button"
            class="tm-mobile-menu"
            aria-label="Open menu"
            aria-controls="tm-learner-sidebar"
            aria-expanded="false"
        >
            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                aria-hidden="true"
            >
                <path d="M4 6h16M4 12h16M4 18h16"/>
            </svg>
        </button>

    <?php endif; ?>

    <a class="brand" href="/index.php">

        <span class="brand-icon">
            T
        </span>

        <span class="brand-name">
            TestMate
        </span>

    </a>

    <div class="nav-links">

        <?php if ($is_logged_in): ?>

            <a class="tm-home-link" href="/index.php">
                Home
            </a>

            <a class="tm-user-link" href="/profile.php">
                <?= $tmEsc($user_name) ?>
            </a>

            <?php if ($is_admin): ?>

                <a href="/admin/index.php">
                    Admin Panel
                </a>

            <?php endif; ?>

            <a
                class="btn-logout"
                href="/logout.php"
            >
                Logout
            </a>

        <?php else: ?>

            <a href="/login.php">
                Login
            </a>

            <a href="/register.php">
                Register
            </a>

        <?php endif; ?>

    </div>

</nav>

<!-- ==========================================
     LEARNER SIDEBAR
     ========================================== -->

<?php if ($show_sidebar): ?>

<div
    class="tm-mobile-overlay"
    aria-hidden="true"
></div>

<aside
    class="tm-sidebar"
    id="tm-learner-sidebar"
    aria-label="Learner navigation"
>

    <div class="tm-sidebar-menu">

        <?php foreach ($tmNav as [$label, $url, $page, $icon]): ?>

            <?php
            $active = $current_page === $page;
            ?>

            <a
                class="tm-nav-link<?= $active ? ' active' : '' ?>"
                href="<?= $tmEsc($url) ?>"
                <?= $active ? 'aria-current="page"' : '' ?>
            >

                <span class="tm-nav-icon">
                    <?= $tmIcon($icon) ?>
                </span>

                <span>
                    <?= $tmEsc($label) ?>
                </span>

            </a>

        <?php endforeach; ?>

    </div>

    <div class="tm-sidebar-motivation">

        <strong>
            Keep Learning!
        </strong>

        <p>
            Practise regularly and improve your
            knowledge of Namibia's road rules.
        </p>

    </div>

</aside>

<script>
(() => {

    const button = document.querySelector(
        '.tm-mobile-menu'
    );

    const overlay = document.querySelector(
        '.tm-mobile-overlay'
    );

    const links = document.querySelectorAll(
        '.tm-sidebar-menu a'
    );

    if (!button || !overlay) {
        return;
    }

    const setOpen = open => {

        document.body.classList.toggle(
            'tm-menu-open',
            open
        );

        button.setAttribute(
            'aria-expanded',
            String(open)
        );

        button.setAttribute(
            'aria-label',
            open ? 'Close menu' : 'Open menu'
        );
    };

    button.addEventListener('click', () => {

        setOpen(
            !document.body.classList.contains(
                'tm-menu-open'
            )
        );
    });

    overlay.addEventListener('click', () => {
        setOpen(false);
    });

    links.forEach(link => {

        link.addEventListener('click', () => {
            setOpen(false);
        });

    });

    document.addEventListener('keydown', event => {

        if (event.key === 'Escape') {
            setOpen(false);
        }

    });

    window.addEventListener('resize', () => {

        if (window.innerWidth > 900) {
            setOpen(false);
        }

    });

})();
</script>

<?php endif; ?>