# TestMate NATIS Quiz Bank

Created from the uploaded NATIS manual as the source.

Topics: 9
Questions per topic: 30
Quiz sets per topic: 3
Questions per set: 10
Total questions: 270

## Quiz behaviour
When a learner opens a topic in normal mode, `quiz.php` chooses one complete `quiz_set` (1, 2 or 3) at random and loads exactly 10 questions from that set.

## Installation
1. Back up the TestMate database.
2. Put the contents of `assets/quiz/` into `C:/xampp/htdocs/testmate/assets/quiz/`.
3. Import `database/natis_quiz_bank.sql` in phpMyAdmin.
4. Replace your current `quiz.php` with the supplied `quiz.php`.
5. If your `questions` table already has a `quiz_set` column, remove the ALTER TABLE line from the SQL before importing.

## Source note
The manual says learner testing covers vehicle controls, road traffic signs, rules of the road and other matters relating to road traffic legislation. It also describes separate road-sign categories.
