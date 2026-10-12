
<?php
/**
 * TestMate – Namibia Learner's Licence
 * Redesigned homepage
 * Standalone PHP 8.5 / SQLite compatible
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$isLoggedIn = !empty($_SESSION['user_id']);

$isAdmin = $isLoggedIn &&
    strtolower((string)($_SESSION['role'] ?? '')) === 'admin';

$userName = trim(
    (string)($_SESSION['name'] ?? 'Learner')
) ?: 'Learner';

$dashboardUrl = $isAdmin
    ? '/admin/index.php'
    : '/dashboard.php';

$protectedUrl = static fn(string $url): string =>
    $isLoggedIn ? $url : '/login.php';

$esc = static fn($value): string =>
    htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

// Keep existing slideshow images.
// Optional hero-namibia.jpg takes priority if added.

$heroSlides = [];

foreach (
    [
        'hero-namibia.jpg',
        'slide1.jpg',
        'slide2.jpg',
        'slide3.jpg',
        'slide4.jpg'
    ] as $imageFile
) {
    if (is_file(__DIR__ . '/images/' . $imageFile)) {
        $heroSlides[] = '/images/' . rawurlencode($imageFile);
    }
}

// Homepage feature cards

$features = [
    [
        'title' => 'Study Materials',
        'description' => 'Learn Namibian road signs, traffic rules, speed limits and vehicle controls.',
        'link' => $protectedUrl('/study-materials.php'),
        'action' => 'Start Studying',
        'icon' => 'book',
        'color' => 'green',
    ],
    [
        'title' => 'Video Demonstrations',
        'description' => 'Watch driving demonstrations and understand road situations visually.',
        'link' => $protectedUrl('/video-demonstrations.php'),
        'action' => 'Watch Videos',
        'icon' => 'video',
        'color' => 'blue',
    ],
    [
        'title' => 'Practice Tests',
        'description' => 'Build exam confidence with topic quizzes and timed mock tests.',
        'link' => $protectedUrl('/mock-test.php'),
        'action' => 'Take a Test',
        'icon' => 'car',
        'color' => 'orange',
    ],
    [
        'title' => 'Track Progress',
        'description' => 'Follow your scores, see improvement and revisit topics that need work.',
        'link' => $protectedUrl('/progress.php'),
        'action' => 'View Progress',
        'icon' => 'chart',
        'color' => 'purple',
    ],
];

// SVG icons - no icon library required.

function homeIcon(string $name): string
{
    $paths = [
        'book' =>
            '<path d="M12 6c-2.8-1.8-5.8-2.1-9-1v14c3.2-1 6.2-.8 9 1 2.8-1.8 5.8-2 9-1V5c-3.2-1.1-6.2-.8-9 1Z"/>
             <path d="M12 6v14"/>',

        'video' =>
            '<rect x="3" y="4" width="18" height="16" rx="3"/>
             <path d="m10 8 6 4-6 4V8Z"/>',

        'car' =>
            '<path d="M5 16H3v-5l2-5h14l2 5v5h-2M5 16h14M4 11h16"/>
             <circle cx="7" cy="16" r="1.7"/>
             <circle cx="17" cy="16" r="1.7"/>',

        'chart' =>
            '<path d="M3 19h18M6 16l5-5 4 3 5-7"/>
             <path d="M16 7h4v4"/>',

        'arrow' =>
            '<path d="M5 12h14M13 6l6 6-6 6"/>',

        'menu' =>
            '<path d="M4 6h16M4 12h16M4 18h16"/>',

        'left' =>
            '<path d="M15 18 9 12l6-6"/>',

        'right' =>
            '<path d="m9 18 6-6-6-6"/>',
    ];

    return '<svg
        viewBox="0 0 24 24"
        width="24"
        height="24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.9"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true">'
        . ($paths[$name] ?? $paths['arrow']) .
        '</svg>';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<meta
    name="theme-color"
    content="#102744"
>

<meta
    name="description"
    content="Prepare for your Namibia learner's licence with TestMate study materials, videos, quizzes and practice tests."
>

<title>TestMate | Namibia Learner's Licence</title>

<style>

/* ==============================================
   TESTMATE HOMEPAGE - MODERN DESIGN
   ============================================== */

:root {
    font-family: "Segoe UI", Inter, Arial, sans-serif;
    color: #102744;
    background: #f5f8fc;
    font-synthesis: none;
}

*,
*::before,
*::after {
    box-sizing: border-box;
}

html {
    scroll-behavior: smooth;
}

body.tm-home {
    margin: 0;
    min-width: 320px;
    background: #f5f8fc;
    color: #102744;
    font-family: "Segoe UI", Inter, Arial, sans-serif;
    line-height: 1.55;
}

.tm-home a {
    color: inherit;
    text-decoration: none;
}

.tm-home button {
    font: inherit;
    cursor: pointer;
}

.tm-home button:focus-visible,
.tm-home a:focus-visible {
    outline: 3px solid #f8c52a;
    outline-offset: 4px;
}

.tm-home svg {
    display: block;
}

.tm-home .tm-skip {
    position: absolute;
    left: 12px;
    top: -80px;
    padding: 10px 16px;
    z-index: 2000;
    background: white;
    border-radius: 8px;
}

.tm-home .tm-skip:focus {
    top: 12px;
}

/* ==============================================
   NAVIGATION BAR
   ============================================== */

.tm-home .tm-topbar {
    position: relative;
    z-index: 200;
    background: #102744;
    color: #fff;
    box-shadow: 0 3px 18px rgba(8, 28, 49, .16);
}

.tm-home .tm-nav-shell {
    width: min(1340px, calc(100% - 64px));
    min-height: 76px;
    margin: 0 auto;
    display: flex;
    align-items: center;
    gap: 32px;
}

.tm-home .tm-logo {
    display: inline-flex;
    align-items: center;
    gap: 11px;
    font-size: 23px;
    font-weight: 800;
    letter-spacing: -.6px;
    flex-shrink: 0;
}

.tm-home .tm-logo-mark {
    display: grid;
    place-items: center;
    width: 42px;
    height: 42px;
    border-radius: 12px;
    background: linear-gradient(135deg, #1cb3fc, #147df5);
    color: #fff;
    font-size: 25px;
    font-weight: 850;
    box-shadow: inset 0 1px rgba(255,255,255,.2);
}

.tm-home .tm-primary-nav {
    margin-left: auto;
    display: flex;
    gap: 25px;
    align-items: center;
}

.tm-home .tm-primary-nav a {
    color: #e0eafa;
    font-size: 14px;
    font-weight: 600;
    transition: color .2s;
}

.tm-home .tm-primary-nav a:hover {
    color: #fff;
}

.tm-home .tm-account-nav {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-left: 8px;
}

.tm-home .tm-nav-plain {
    padding: 10px 12px;
    color: #fff;
    font-size: 14px;
    font-weight: 650;
}

.tm-home .tm-nav-plain:hover {
    color: #95d2ff;
}

.tm-home .tm-nav-outline,
.tm-home .tm-nav-blue,
.tm-home .tm-nav-danger {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 41px;
    padding: 8px 17px;
    border-radius: 9px;
    font-size: 13px;
    font-weight: 750;
    white-space: nowrap;
    transition: background .2s, transform .2s;
}

.tm-home .tm-nav-outline {
    border: 1px solid rgba(255,255,255,.3);
}

.tm-home .tm-nav-outline:hover {
    background: rgba(255,255,255,.1);
}

.tm-home .tm-nav-blue {
    background: #147df5;
    color: #fff;
}

.tm-home .tm-nav-blue:hover {
    background: #0067dd;
}

.tm-home .tm-nav-danger {
    background: #ef493e;
    color: #fff;
}

.tm-home .tm-nav-danger:hover {
    background: #d7362a;
}

.tm-home .tm-menu-toggle {
    display: none;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    width: 42px;
    height: 42px;
    border: 1px solid rgba(255,255,255,.26);
    background: transparent;
    color: #fff;
    border-radius: 9px;
}

/* ==============================================
   HERO SLIDESHOW
   ============================================== */

.tm-home .tm-hero {
    position: relative;
    isolation: isolate;
    min-height: 510px;
    overflow: hidden;
    color: #fff;
    background: linear-gradient(
        135deg,
        #174875,
        #0c2642
    );
}

.tm-home .tm-slide-stack,
.tm-home .tm-hero-slide,
.tm-home .tm-hero-shade {
    position: absolute;
    inset: 0;
}

.tm-home .tm-hero-slide {
    opacity: 0;
    transition: opacity 1.2s ease;
}

.tm-home .tm-hero-slide.is-active {
    opacity: 1;
}

.tm-home .tm-hero-slide img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: center 48%;
}

.tm-home .tm-hero-shade {
    background:
        linear-gradient(
            90deg,
            rgba(4,21,39,.88) 0%,
            rgba(9,34,57,.69) 40%,
            rgba(9,29,47,.17) 84%
        ),
        linear-gradient(
            0deg,
            rgba(3,22,38,.28),
            transparent 50%
        );
}

.tm-home .tm-hero-inner {
    position: relative;
    width: min(1340px, calc(100% - 64px));
    margin: 0 auto;
    min-height: 510px;
    padding: 68px 0 110px;
    display: flex;
    align-items: center;
}

.tm-home .tm-hero-content {
    max-width: 730px;
}

.tm-home .tm-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    color: #e2f1ff;
    font-size: 12px;
    letter-spacing: 1.7px;
    font-weight: 800;
    text-transform: uppercase;
    margin: 0 0 17px;
}

.tm-home .tm-eyebrow::before {
    content: '';
    width: 27px;
    height: 3px;
    border-radius: 3px;
    background: #f5c518;
}

.tm-home .tm-hero h1 {
    font-size: clamp(38px, 4.2vw, 62px);
    max-width: 780px;
    line-height: 1.1;
    font-weight: 850;
    letter-spacing: -1.8px;
    margin: 0 0 21px;
    text-wrap: balance;
}

.tm-home .tm-highlight {
    color: #f8ce37;
}

.tm-home .tm-hero-copy {
    max-width: 615px;
    font-size: clamp(15px, 1.15vw, 18px);
    line-height: 1.75;
    color: #eef5fb;
    margin: 0 0 29px;
}

.tm-home .tm-hero-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 13px;
    align-items: center;
}

.tm-home .tm-cta {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    border: 0;
    min-height: 51px;
    padding: 12px 25px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 800;
    transition:
        background .2s,
        transform .2s,
        box-shadow .2s;
}

.tm-home .tm-cta:hover {
    transform: translateY(-2px);
    box-shadow: 0 9px 25px rgba(0,0,0,.2);
}

.tm-home .tm-cta svg {
    width: 18px;
    height: 18px;
}

.tm-home .tm-cta-primary {
    background: #147df5;
    color: #fff;
}

.tm-home .tm-cta-primary:hover {
    background: #006add;
}

.tm-home .tm-cta-secondary {
    background: #fff;
    color: #173452;
}

.tm-home .tm-cta-secondary:hover {
    background: #ecf4ff;
}

/* Slideshow indicators */

.tm-home .tm-hero-controls {
    position: absolute;
    left: 0;
    right: 0;
    bottom: 74px;
    width: min(1340px, calc(100% - 64px));
    margin: 0 auto;
    z-index: 3;
    display: flex;
    align-items: center;
    gap: 16px;
}

.tm-home .tm-slider-dots {
    display: flex;
    gap: 9px;
}

.tm-home .tm-slider-dots button {
    width: 10px;
    height: 10px;
    padding: 0;
    border: 0;
    border-radius: 50%;
    background: rgba(255,255,255,.42);
    transition: width .2s, background .2s;
}

.tm-home .tm-slider-dots button[aria-current="true"] {
    width: 28px;
    border-radius: 20px;
    background: #fff;
}

.tm-home .tm-slider-arrows {
    display: flex;
    gap: 7px;
}

.tm-home .tm-slider-arrows button {
    width: 33px;
    height: 33px;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: rgba(255,255,255,.15);
    border: 1px solid rgba(255,255,255,.35);
    color: #fff;
}

.tm-home .tm-slider-arrows button:hover {
    background: rgba(255,255,255,.3);
}

.tm-home .tm-slider-arrows svg {
    width: 17px;
    height: 17px;
}

/* ==============================================
   FOUR FEATURE CARDS
   ============================================== */

.tm-home .tm-features {
    position: relative;
    z-index: 10;
    width: min(1340px, calc(100% - 64px));
    margin: -57px auto 0;
}

.tm-home .tm-feature-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 18px;
}

.tm-home .tm-feature-card {
    min-width: 0;
    padding: 26px 24px 23px;
    background: #fff;
    border: 1px solid #e3eaf2;
    border-radius: 15px;
    box-shadow: 0 12px 32px rgba(20,48,76,.11);
    transition: transform .2s, box-shadow .2s;

    display: flex;
    flex-direction: column;
}

.tm-home .tm-feature-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 36px rgba(20,48,76,.16);
}

.tm-home .tm-feature-icon {
    display: grid;
    place-items: center;
    width: 54px;
    height: 54px;
    border-radius: 13px;
    margin-bottom: 17px;
}

.tm-home .tm-feature-icon svg {
    width: 28px;
    height: 28px;
}

.tm-home .tm-feature-card.is-green .tm-feature-icon {
    background: #e2f8ec;
    color: #08a471;
}

.tm-home .tm-feature-card.is-blue .tm-feature-icon {
    background: #e6f1ff;
    color: #147df5;
}

.tm-home .tm-feature-card.is-orange .tm-feature-icon {
    background: #fff0df;
    color: #eb850d;
}

.tm-home .tm-feature-card.is-purple .tm-feature-icon {
    background: #f0eaff;
    color: #814bda;
}

.tm-home .tm-feature-card h2 {
    margin: 0 0 8px;
    color: #102744;
    font-size: 18px;
    line-height: 1.3;
    font-weight: 800;
}

.tm-home .tm-feature-card p {
    margin: 0 0 23px;
    color: #6c8095;
    font-size: 14px;
    line-height: 1.65;
    flex: 1;
}

.tm-home .tm-feature-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 800;
    color: #166cbd;
    align-self: flex-start;
}

.tm-home .tm-feature-link:hover {
    color: #0a3f77;
}

.tm-home .tm-feature-link svg {
    width: 17px;
    height: 17px;
    transition: transform .2s;
}

.tm-home .tm-feature-link:hover svg {
    transform: translateX(4px);
}

/* ==============================================
   HOW TESTMATE WORKS
   ============================================== */

.tm-home .tm-section {
    width: min(1340px, calc(100% - 64px));
    margin: 0 auto;
    padding: 78px 0 82px;
}

.tm-home .tm-section-kicker {
    margin: 0 0 8px;
    font-size: 12px;
    letter-spacing: 1.5px;
    color: #147df5;
    font-weight: 850;
    text-transform: uppercase;
}

.tm-home .tm-section-heading {
    margin: 0 0 13px;
    font-size: clamp(27px, 2.4vw, 36px);
    line-height: 1.2;
    letter-spacing: -.7px;
    font-weight: 850;
}

.tm-home .tm-section-lead {
    margin: 0;
    max-width: 690px;
    color: #687e93;
    font-size: 15px;
    line-height: 1.7;
}

.tm-home .tm-step-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 17px;
    margin-top: 29px;
}

.tm-home .tm-step {
    padding: 24px 26px;
    border: 1px solid #e3ebf4;
    border-radius: 14px;
    background: #fff;
}

.tm-home .tm-step-number {
    display: inline-grid;
    place-items: center;
    width: 34px;
    height: 34px;
    margin-bottom: 14px;
    background: #eaf3ff;
    border-radius: 9px;
    font-weight: 800;
    color: #147df5;
}

.tm-home .tm-step h3 {
    margin: 0 0 6px;
    font-size: 17px;
    color: #102744;
}

.tm-home .tm-step p {
    margin: 0;
    font-size: 14px;
    color: #71859a;
    line-height: 1.65;
}

/* ==============================================
   BOTTOM CALL TO ACTION
   ============================================== */

.tm-home .tm-banner {
    background: linear-gradient(
        110deg,
        #102744,
        #185681
    );
    color: #fff;
}

.tm-home .tm-banner-inner {
    width: min(1340px, calc(100% - 64px));
    margin: auto;
    padding: 30px 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 24px;
}

.tm-home .tm-banner h2 {
    font-size: clamp(22px, 2vw, 28px);
    margin: 0 0 5px;
}

.tm-home .tm-banner p {
    margin: 0;
    color: #cddfec;
    font-size: 14px;
}

/* ==============================================
   FOOTER
   ============================================== */

.tm-home .tm-footer {
    background: #0d2035;
    color: #a9bfd3;
}

.tm-home .tm-footer-inner {
    width: min(1340px, calc(100% - 64px));
    margin: auto;
    padding: 30px 0;
    display: flex;
    justify-content: space-between;
    gap: 20px;
    align-items: center;
    font-size: 13px;
}

.tm-home .tm-footer p {
    margin: 0;
}

.tm-home .tm-footer a {
    color: #e1ebf6;
}

/* ==============================================
   TABLET
   ============================================== */

@media (max-width: 1100px) {

    .tm-home .tm-nav-shell,
    .tm-home .tm-hero-inner,
    .tm-home .tm-hero-controls,
    .tm-home .tm-features,
    .tm-home .tm-section,
    .tm-home .tm-banner-inner,
    .tm-home .tm-footer-inner {
        width: min(100% - 42px, 1340px);
    }

    .tm-home .tm-primary-nav {
        gap: 15px;
    }

    .tm-home .tm-feature-card {
        padding: 24px 18px;
    }

    .tm-home .tm-feature-grid {
        gap: 13px;
    }
}

/* ==============================================
   MOBILE NAVIGATION
   ============================================== */

@media (max-width: 820px) {

    .tm-home .tm-nav-shell {
        min-height: 68px;
        gap: 14px;
    }

    .tm-home .tm-logo {
        font-size: 21px;
    }

    .tm-home .tm-menu-toggle {
        display: inline-flex;
    }

    .tm-home .tm-primary-nav {
        display: none;
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        padding: 12px 22px 20px;
        background: #102744;
        border-top: 1px solid rgba(255,255,255,.12);
        flex-direction: column;
        align-items: stretch;
        gap: 0;
        box-shadow: 0 10px 18px rgba(5,20,39,.25);
    }

    .tm-home .tm-primary-nav.is-open {
        display: flex;
    }

    .tm-home .tm-primary-nav a {
        padding: 12px 8px;
    }

    .tm-home .tm-account-nav {
        margin-left: auto;
    }

    .tm-home .tm-feature-grid {
        grid-template-columns: repeat(2, minmax(0,1fr));
    }

    .tm-home .tm-features {
        margin-top: -44px;
    }

    .tm-home .tm-hero-inner,
    .tm-home .tm-hero {
        min-height: 490px;
    }

    .tm-home .tm-step-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 540px) {

    .tm-home .tm-nav-shell,
    .tm-home .tm-hero-inner,
    .tm-home .tm-hero-controls,
    .tm-home .tm-features,
    .tm-home .tm-section,
    .tm-home .tm-banner-inner,
    .tm-home .tm-footer-inner {
        width: calc(100% - 32px);
    }

    .tm-home .tm-nav-shell {
        gap: 10px;
    }

    .tm-home .tm-logo-mark {
        width: 38px;
        height: 38px;
        font-size: 22px;
    }

    .tm-home .tm-logo {
        font-size: 19px;
    }

    .tm-home .tm-account-nav {
        gap: 6px;
    }

    .tm-home .tm-account-nav .tm-small-hide {
        display: none;
    }

    .tm-home .tm-nav-blue,
    .tm-home .tm-nav-danger {
        min-height: 38px;
        padding: 8px 11px;
        font-size: 12px;
    }

    .tm-home .tm-hero,
    .tm-home .tm-hero-inner {
        min-height: 520px;
    }

    .tm-home .tm-hero-inner {
        padding: 62px 0 100px;
    }

    .tm-home .tm-hero h1 {
        font-size: clamp(34px, 9vw, 45px);
        letter-spacing: -1.1px;
    }

    .tm-home .tm-hero-copy {
        font-size: 15px;
    }

    .tm-home .tm-hero-shade {
        background:
            linear-gradient(
                90deg,
                rgba(4,21,39,.88),
                rgba(4,21,39,.48)
            ),
            linear-gradient(
                0deg,
                rgba(4,21,39,.45),
                transparent
            );
    }

    .tm-home .tm-hero-controls {
        bottom: 70px;
    }

    .tm-home .tm-cta {
        min-height: 46px;
        padding: 10px 17px;
        font-size: 13px;
    }

    .tm-home .tm-feature-grid {
        gap: 12px;
    }

    .tm-home .tm-feature-card {
        padding: 18px 15px;
    }

    .tm-home .tm-feature-icon {
        width: 46px;
        height: 46px;
        margin-bottom: 12px;
    }

    .tm-home .tm-feature-icon svg {
        width: 25px;
        height: 25px;
    }

    .tm-home .tm-feature-card h2 {
        font-size: 15px;
    }

    .tm-home .tm-feature-card p {
        font-size: 12px;
        margin-bottom: 16px;
    }

    .tm-home .tm-feature-link {
        font-size: 12px;
    }

    .tm-home .tm-section {
        padding: 65px 0;
    }

    .tm-home .tm-banner-inner,
    .tm-home .tm-footer-inner {
        flex-direction: column;
        align-items: flex-start;
    }
}

@media (max-width: 370px) {

    .tm-home .tm-feature-grid {
        grid-template-columns: 1fr;
    }
}

@media (prefers-reduced-motion: reduce) {

    html {
        scroll-behavior: auto;
    }

    .tm-home .tm-hero-slide,
    .tm-home .tm-feature-card,
    .tm-home .tm-feature-link svg,
    .tm-home .tm-cta {
        transition: none;
    }
}

</style>
</head>

<body class="tm-home" id="top">

<a class="tm-skip" href="#main">
    Skip to content
</a>

<!-- ==========================================
     TOP NAVIGATION
     ========================================== -->

<header class="tm-topbar">

    <div class="tm-nav-shell">

        <a
            class="tm-logo"
            href="/index.php"
            aria-label="TestMate home"
        >

            <span class="tm-logo-mark">T</span>

            <span>TestMate</span>

        </a>

        <!-- DESKTOP NAVIGATION -->

        <nav
            class="tm-primary-nav"
            id="tmPrimaryNav"
            aria-label="Main navigation"
        >

            <a href="#top">Home</a>

            <a href="#about">About</a>

            <a href="#features">Features</a>

            <a href="#contact">Contact</a>

        </nav>

        <!-- ACCOUNT NAVIGATION -->

        <div class="tm-account-nav">

            <?php if ($isLoggedIn): ?>

                <a
                    class="tm-nav-plain tm-small-hide"
                    href="<?= $esc($dashboardUrl) ?>"
                >
                    Dashboard
                </a>

                <?php if ($isAdmin): ?>

                    <a
                        class="tm-nav-outline tm-small-hide"
                        href="/admin/index.php"
                    >
                        Admin Panel
                    </a>

                <?php endif; ?>

                <a
                    class="tm-nav-danger"
                    href="/logout.php"
                >
                    Logout
                </a>

            <?php else: ?>

                <a
                    class="tm-nav-outline tm-small-hide"
                    href="/login.php"
                >
                    Login
                </a>

                <a
                    class="tm-nav-blue"
                    href="/register.php"
                >
                    Register
                </a>

            <?php endif; ?>

        </div>

        <!-- MOBILE MENU BUTTON -->

        <button
            type="button"
            class="tm-menu-toggle"
            id="tmMenuToggle"
            aria-controls="tmPrimaryNav"
            aria-expanded="false"
            aria-label="Toggle navigation"
        >
            <?= homeIcon('menu') ?>
        </button>

    </div>

</header>

<main id="main">

    <!-- ======================================
         HOMEPAGE HERO
         ====================================== -->

    <section
        class="tm-hero"
        aria-labelledby="tmHeroTitle"
        id="home"
    >

        <!-- IMAGE SLIDESHOW -->

        <div
            class="tm-slide-stack"
            aria-hidden="true"
        >

            <?php foreach ($heroSlides as $index => $slide): ?>

                <div
                    class="tm-hero-slide<?= $index === 0 ? ' is-active' : '' ?>"
                >

                    <img
                        src="<?= $esc($slide) ?>"
                        alt=""
                        <?= $index === 0 ? 'fetchpriority="high"' : 'loading="lazy"' ?>
                    >

                </div>

            <?php endforeach; ?>

        </div>

        <!-- IMAGE OVERLAY -->

        <div
            class="tm-hero-shade"
            aria-hidden="true"
        ></div>

        <!-- HERO TEXT -->

        <div class="tm-hero-inner">

            <div class="tm-hero-content">

                <p class="tm-eyebrow">
                    Namibia Learner's Licence Preparation
                </p>

                <h1 id="tmHeroTitle">
                    Pass Your Learner's Licence
                    <span class="tm-highlight">
                        with Confidence.
                    </span>
                </h1>

                <p class="tm-hero-copy">
                    Learn road signs, understand traffic rules,
                    watch driving demonstrations and practise
                    with quizzes — all in one place.
                </p>

                <div class="tm-hero-actions">

                    <a
                        class="tm-cta tm-cta-primary"
                        href="<?= $esc(
                            $isLoggedIn
                                ? $dashboardUrl
                                : '/register.php'
                        ) ?>"
                    >

                        <?= $isLoggedIn
                            ? 'Go to Dashboard'
                            : 'Get Started Free'
                        ?>

                        <?= homeIcon('arrow') ?>

                    </a>

                    <a
                        class="tm-cta tm-cta-secondary"
                        href="#features"
                    >
                        Explore Learning Tools
                    </a>

                </div>

            </div>

        </div>

        <!-- SLIDESHOW CONTROLS -->

        <?php if (count($heroSlides) > 1): ?>

            <div
                class="tm-hero-controls"
                aria-label="Homepage image slideshow"
            >

                <div class="tm-slider-dots">

                    <?php foreach ($heroSlides as $index => $slide): ?>

                        <button
                            type="button"
                            data-slide="<?= $index ?>"
                            aria-label="Show image <?= $index + 1 ?>"
                            aria-current="<?= $index === 0 ? 'true' : 'false' ?>"
                        ></button>

                    <?php endforeach; ?>

                </div>

                <div class="tm-slider-arrows">

                    <button
                        id="tmPrevSlide"
                        type="button"
                        aria-label="Previous image"
                    >
                        <?= homeIcon('left') ?>
                    </button>

                    <button
                        id="tmNextSlide"
                        type="button"
                        aria-label="Next image"
                    >
                        <?= homeIcon('right') ?>
                    </button>

                </div>

            </div>

        <?php endif; ?>

    </section>

    <!-- ======================================
         LEARNING FEATURE CARDS
         ====================================== -->

    <section
        class="tm-features"
        id="features"
        aria-label="TestMate learning tools"
    >

        <div class="tm-feature-grid">

            <?php foreach ($features as $feature): ?>

                <article
                    class="tm-feature-card is-<?= $esc($feature['color']) ?>"
                >

                    <div class="tm-feature-icon">
                        <?= homeIcon($feature['icon']) ?>
                    </div>

                    <h2>
                        <?= $esc($feature['title']) ?>
                    </h2>

                    <p>
                        <?= $esc($feature['description']) ?>
                    </p>

                    <a
                        class="tm-feature-link"
                        href="<?= $esc($feature['link']) ?>"
                    >

                        <?= $esc($feature['action']) ?>

                        <?= homeIcon('arrow') ?>

                    </a>

                </article>

            <?php endforeach; ?>

        </div>

    </section>

    <!-- ======================================
         HOW TESTMATE WORKS
         ====================================== -->

    <section
        class="tm-section"
        id="about"
        aria-labelledby="tmAboutTitle"
    >

        <p class="tm-section-kicker">
            How TestMate Works
        </p>

        <h2
            class="tm-section-heading"
            id="tmAboutTitle"
        >
            A simpler way to prepare for the road.
        </h2>

        <p class="tm-section-lead">
            Build your knowledge step by step. Study the
            material, test yourself and use your results
            to decide what to practise next.
        </p>

        <div class="tm-step-grid">

            <article class="tm-step">

                <span class="tm-step-number">01</span>

                <h3>Learn the rules</h3>

                <p>
                    Read clear topic-by-topic materials and
                    watch helpful driving demonstrations.
                </p>

            </article>

            <article class="tm-step">

                <span class="tm-step-number">02</span>

                <h3>Practise regularly</h3>

                <p>
                    Answer topic questions and try a timed
                    mock test when you're ready.
                </p>

            </article>

            <article class="tm-step">

                <span class="tm-step-number">03</span>

                <h3>Improve your results</h3>

                <p>
                    Review your scores and revisit the areas
                    where you need more practice.
                </p>

            </article>

        </div>

    </section>

    <!-- ======================================
         CALL TO ACTION
         ====================================== -->

    <section
        class="tm-banner"
        id="contact"
        aria-label="Get started"
    >

        <div class="tm-banner-inner">

            <div>

                <h2>
                    Ready to prepare for your learner's licence?
                </h2>

                <p>
                    Get started with TestMate and keep learning
                    at your own pace.
                </p>

            </div>

            <a
                class="tm-cta tm-cta-secondary"
                href="<?= $esc(
                    $isLoggedIn
                        ? $dashboardUrl
                        : '/register.php'
                ) ?>"
            >

                <?= $isLoggedIn
                    ? 'Continue Learning'
                    : 'Create Free Account'
                ?>

                <?= homeIcon('arrow') ?>

            </a>

        </div>

    </section>

</main>

<!-- ==========================================
     FOOTER
     ========================================== -->

<footer class="tm-footer">

    <div class="tm-footer-inner">

        <p>
            © <?= date('Y') ?> TestMate Namibia.
            Learner's licence preparation.
        </p>

        <p>
            Need assistance? Please contact your
            TestMate administrator.

            <a href="#top">
                Back to top ↑
            </a>
        </p>

    </div>

</footer>

<!-- ==========================================
     JAVASCRIPT
     ========================================== -->

<script>

(() => {

    // --------------------------------------
    // Mobile navigation
    // --------------------------------------

    const menuButton = document.getElementById(
        'tmMenuToggle'
    );

    const menu = document.getElementById(
        'tmPrimaryNav'
    );

    if (menuButton && menu) {

        const closeMenu = () => {

            menu.classList.remove('is-open');

            menuButton.setAttribute(
                'aria-expanded',
                'false'
            );

        };

        menuButton.addEventListener('click', () => {

            const opened = menu.classList.toggle(
                'is-open'
            );

            menuButton.setAttribute(
                'aria-expanded',
                String(opened)
            );

        });

        menu.querySelectorAll('a').forEach(link => {

            link.addEventListener('click', closeMenu);

        });

        document.addEventListener('keydown', e => {

            if (e.key === 'Escape') {
                closeMenu();
            }

        });

    }

    // --------------------------------------
    // Homepage slideshow
    // --------------------------------------

    const slides = [
        ...document.querySelectorAll('.tm-hero-slide')
    ];

    const dots = [
        ...document.querySelectorAll(
            '.tm-slider-dots button'
        )
    ];

    if (slides.length < 2) {
        return;
    }

    let current = 0;
    let timer;

    const reducedMotion = window.matchMedia(
        '(prefers-reduced-motion: reduce)'
    ).matches;

    function showSlide(index) {

        current = (
            index + slides.length
        ) % slides.length;

        slides.forEach((slide, i) => {

            slide.classList.toggle(
                'is-active',
                i === current
            );

        });

        dots.forEach((dot, i) => {

            dot.setAttribute(
                'aria-current',
                String(i === current)
            );

        });

    }

    function stopAutoplay() {

        if (timer) {
            window.clearInterval(timer);
        }

    }

    function startAutoplay() {

        stopAutoplay();

        if (!reducedMotion && !document.hidden) {

            timer = window.setInterval(() => {

                showSlide(current + 1);

            }, 6000);

        }

    }

    dots.forEach((dot, i) => {

        dot.addEventListener('click', () => {

            showSlide(i);
            startAutoplay();

        });

    });

    document.getElementById('tmPrevSlide')
        ?.addEventListener('click', () => {

            showSlide(current - 1);
            startAutoplay();

        });

    document.getElementById('tmNextSlide')
        ?.addEventListener('click', () => {

            showSlide(current + 1);
            startAutoplay();

        });

    document.addEventListener(
        'visibilitychange',
        startAutoplay
    );

    startAutoplay();

})();

</script>

</body>
</html>
