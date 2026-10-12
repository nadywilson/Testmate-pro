
<?php
$current_page = basename($_SERVER['PHP_SELF']);

$admin_menu = [
    'MAIN' => [
        ['Dashboard', 'index.php', 'fa-gauge-high'],
        ['Review Scores', 'review-scores.php', 'fa-clipboard-check']
    ],
    'USERS' => [
        ['Users', 'users.php', 'fa-users'],
        ['Add User', 'add-user.php', 'fa-user-plus']
    ],
    'STATISTICS' => [
        ['Statistics', 'stats.php', 'fa-chart-line']
    ],
    'QUESTIONS' => [
        ['All Questions', 'questions.php', 'fa-circle-question'],
        ['Add Question', 'add-question.php', 'fa-circle-plus'],
        ['Assign Quiz', 'assign-quiz.php', 'fa-list-check'],
        ['Drag & Drop', 'dragdrop_pairs.php', 'fa-puzzle-piece']
    ],
    'CONTENT' => [
        ['Simulations', 'simulations.php', 'fa-film'],
        ['Activities', 'activities.php', 'fa-clipboard-list'],
        ['Materials', 'materials.php', 'fa-book-open'],
        ['Add Material', 'add-material.php', 'fa-file-circle-plus']
    ]
];
?>

<style>
/* ==========================================
   TESTMATE ADMIN SIDEBAR
   Moodle-inspired light theme
========================================== */

.sidebar.tm-admin-sidebar {
    width: 270px;
    min-width: 270px;
    background: #f5f6f8 !important;
    color: #344054 !important;
    border-right: 1px solid #dfe3e8;
    border-radius: 0;
    box-shadow: none;
    padding: 0 10px 24px;
    margin: 0;
    box-sizing: border-box;
    overflow-y: auto;
    overflow-x: hidden;
    align-self: stretch;
}

/* Top heading */
.tm-admin-sidebar-top {
    padding: 24px 12px 18px;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: 1px;
    color: #667085;
    border-bottom: 1px solid #e0e4e9;
    margin-bottom: 12px;
}

/* Sections */
.tm-admin-menu-section {
    margin-bottom: 14px;
}

.sidebar.tm-admin-sidebar .tm-admin-menu-section h3 {
    margin: 16px 12px 8px;
    padding: 0;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 1px;
    color: #667085;
    background: transparent;
}

/* Navigation */
.sidebar.tm-admin-sidebar .tm-admin-menu-section a {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    margin: 3px 0;
    border-radius: 8px;
    color: #344054 !important;
    background: transparent;
    font-size: 14px;
    font-weight: 500;
    text-decoration: none;
    border: none;
    transition: background .2s ease, color .2s ease;
}

.sidebar.tm-admin-sidebar .tm-admin-menu-section a i {
    width: 19px;
    flex-shrink: 0;
    text-align: center;
    color: #667085;
}

.sidebar.tm-admin-sidebar .tm-admin-menu-section a:hover {
    background: #e8ebef;
    color: #172b4d !important;
}

/* Active item */
.sidebar.tm-admin-sidebar .tm-admin-menu-section a.active {
    background: #d9231b !important;
    color: #ffffff !important;
    font-weight: 700;
}

.sidebar.tm-admin-sidebar .tm-admin-menu-section a.active i {
    color: #ffffff !important;
}

/* Sidebar scrollbar */
.sidebar.tm-admin-sidebar::-webkit-scrollbar {
    width: 6px;
}

.sidebar.tm-admin-sidebar::-webkit-scrollbar-thumb {
    background: #c6ccd5;
    border-radius: 8px;
}

/* Smaller screens */
@media (max-width: 900px) {
    .sidebar.tm-admin-sidebar {
        width: 240px;
        min-width: 240px;
    }
}
</style>

<aside class="sidebar tm-admin-sidebar">

    <div class="tm-admin-sidebar-top">
        ADMIN NAVIGATION
    </div>

    <?php foreach ($admin_menu as $section => $items): ?>

        <div class="tm-admin-menu-section">

            <h3>
                <?= htmlspecialchars($section, ENT_QUOTES, 'UTF-8') ?>
            </h3>

            <?php foreach ($items as [$label, $page, $icon]): ?>

                <a
                    href="/testmate/admin/<?= htmlspecialchars($page, ENT_QUOTES, 'UTF-8') ?>"
                    class="<?= $current_page === $page ? 'active' : '' ?>"
                >
                    <i class="fa-solid <?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?>"></i>

                    <span>
                        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </a>

            <?php endforeach; ?>

        </div>

    <?php endforeach; ?>

</aside>
