<?php
/* TestMate learner sidebar component (for pages that explicitly include it). */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$current_page = basename($_SERVER['PHP_SELF']);
$sidebar_items = [
    ['title'=>'Dashboard','url'=>'/testmate/dashboard.php','page'=>'dashboard.php','icon'=>'fa-house'],
    ['title'=>'Study','url'=>'/testmate/study-materials.php','page'=>'study-materials.php','icon'=>'fa-book-open'],
    ['title'=>'Quizzes','url'=>'/testmate/quiz.php','page'=>'quiz.php','icon'=>'fa-list-check'],
    ['title'=>'Mock Test','url'=>'/testmate/mock-test.php','page'=>'mock-test.php','icon'=>'fa-file-circle-check'],
    ['title'=>'Simulations','url'=>'/testmate/simulations.php','page'=>'simulations.php','icon'=>'fa-car-side'],
    ['title'=>'Videos','url'=>'/testmate/video-demonstrations.php','page'=>'video-demonstrations.php','icon'=>'fa-circle-play'],
    ['title'=>'History','url'=>'/testmate/quiz-history.php','page'=>'quiz-history.php','icon'=>'fa-clock-rotate-left'],
    ['title'=>'Progress','url'=>'/testmate/progress.php','page'=>'progress.php','icon'=>'fa-chart-line'],
    ['title'=>'Profile','url'=>'/testmate/profile.php','page'=>'profile.php','icon'=>'fa-user'],
];
?>
<aside class="tm-learner-sidebar">
    <div class="tm-sidebar-brand">
        <a href="/testmate/dashboard.php">
            <span class="tm-sidebar-logo">T</span>
            <span class="tm-sidebar-brand-text">
                <strong>Test<span>Mate</span></strong>
                <small>Namibia Learner's Licence</small>
            </span>
        </a>
    </div>
    <nav class="tm-sidebar-navigation">
        <?php foreach ($sidebar_items as $item): ?>
            <a href="<?= htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') ?>" class="tm-sidebar-link <?= $current_page === $item['page'] ? 'active' : '' ?>">
                <span class="tm-sidebar-link-icon"><i class="fa-solid <?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?>"></i></span>
                <span class="tm-sidebar-link-text"><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="tm-sidebar-bottom">
        <div class="tm-sidebar-motivation">
            <span class="tm-motivation-icon"><i class="fa-solid fa-arrow-trend-up"></i></span>
            <div><strong>Keep Going!</strong><p>Every minute counts towards your goals.</p></div>
            <div class="tm-motivation-progress"><span></span></div>
        </div>
    </div>
</aside>
