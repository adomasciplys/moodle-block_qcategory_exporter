# Question Category Exporter

A Moodle block (`block_qcategory_exporter`) that migrates the questions a course uses from a
shared question bank into the course's own question bank.

> **This plugin is not maintained.**
> I no longer maintain this plugin. It is available only in its current version, v1.2.
> There will be no bug fixes, no new features and no updates for newer Moodle versions.
> Issues and pull requests are not answered. Test it on a copy of your site before you use it.
> You are free to fork it under the GPL.

## Purpose

Before Moodle 5.0, questions could be stored at system level or course category level, where
every course could use them. Moodle 5.0 removed those levels: a question bank is now an activity
inside a course. The upgrade to Moodle 5.0 moves the old system-level questions into a shared
question bank, and every quiz keeps taking its questions from there.

This plugin finishes that migration, one course at a time. It copies the question categories
that the course's quizzes use into the course's own question bank, and creates a copy of each
quiz that takes its questions from there. Once every course is migrated, no quiz depends on the
shared question bank and the shared question bank can be deleted.

Use it on a site that was upgraded from a version before Moodle 5.0 to Moodle 5.0 or later.

## Requirements

- Moodle 5.0.3 or later.
- A site administrator account. The block shows only to users with `moodle/site:config`.

## Installation

1. Copy this folder to `blocks/qcategory_exporter` in the Moodle root. On Moodle 5.1 or later
   the path is `public/blocks/qcategory_exporter`.
2. Log in as a site administrator and open *Site administration → Notifications* to finish the
   install.

## How to use

Do this once per course.

1. **Back up the course.** The plugin creates question categories, quizzes, grades and
   completion records. It has no undo.
2. Open the course as a site administrator and turn on **Edit mode**.
3. Choose **Add a block → Question Category Exporter Block**.
4. Read the list in the block. It shows every question category that a visible quiz in the
   course uses and that is not in the course's own question bank. An empty list means the
   course has nothing to migrate.
5. Press **Import question categories into course** once. The whole run happens in one page
   load, so a course with many quizzes or students takes a while.
6. Moodle returns to the course page with one of these messages:
   - `Imported categories and duplicated N quiz(zes).` The run succeeded.
   - A warning that some quizzes failed. Those quizzes were not copied. The message names the
     first failure.
   - A warning that some questions could not be copied. Those copies hold fewer questions than
     the originals. The message names the first skipped question and the reason.
7. Check the copies. Each migrated quiz now exists twice in the same section: the original, and
   a copy named `<original name> (copy)`.
8. Delete the original quizzes by hand. The plugin never deletes them.
9. Remove the block from the course.

When every course is done, check that no quiz still takes questions from the shared question
bank, then delete the shared question bank. Moodle keeps any question that still has quiz
attempts.

### What to know before you run it

- **Run it once per course.** A second run does not copy the question categories again, but it
  does create another copy of every original quiz that is still in the course.
- **Hidden quizzes are skipped.** So are quizzes that only use the course's own question bank.
- **Quiz attempts are not copied.** Only each student's grade and completion are carried over
  to the copy.
- **Only active students are carried over.** That means users with a student role on an active
  enrolment. Suspended students and teachers are left out.
- **Keep the plugin installed** for as long as students with a carried-over grade can attempt
  the copies. The plugin watches those attempts to keep the higher grade. See
  [Keeping a carried-over grade](#keeping-a-carried-over-grade).
- **Showing the block creates the course's own question bank** if the course has none.
- **The copied categories are renamed.** See step 2 below.

## How it works

Pressing the button runs three steps.

### 1. Collect (`classes/exporter.php`)

The plugin goes through every visible quiz in the course and every slot in each quiz, and finds
the question category the slot takes its question from:

- **Normal slot**: points at one specific question.
- **Random slot**: points at a category to pick a random question from.

A category is listed only if it holds at least one question and is not in the course's own
question bank. This is the list the block shows.

### 2. Import (`classes/importer.php`)

Each listed category is exported to Moodle question XML and imported into the course's own
question bank. The copy is flat: the parent categories of the source category are dropped, so
the copy sits directly under the top category of the course's own question bank.

The copy is named after the course. Only the part of the source name after the last colon is
kept, and the course's full name is put in front:

```
Old Course: Category Name   ->   New Course: Category Name
```

If two source categories end up with the same name, the second copy is numbered:
`New Course: Category Name (2)`, up to `(10)`.

Running the import again does not create a second copy. A category that already exists in the
course under that name is reused if it holds the same questions as the source.

### 3. Duplicate the quizzes (`classes/duplicator.php`)

Each quiz that uses an imported category is recreated as a new quiz named
`<original name> (copy)`. Its settings, review options, visibility, completion and availability
are copied. Then each slot of the original is copied to the new quiz, pointed at the imported
category.

Last, each active student's grade and completion are carried over from the original to the
copy. The grade is saved in the quiz and in the gradebook.

### Keeping a carried-over grade

A carried-over grade has no attempt behind it. A quiz rebuilds a student's grade from the rows
in `quiz_attempts` alone, so the first attempt a student submits at the copy would erase the
carried-over grade.

A **passing** carried-over grade is therefore pinned in the gradebook with an override. A
**failing** carried-over grade is left unpinned. Pinning it would freeze the failure, and the
student could never pass.

After that, the carried-over grade competes with the student's own attempts, and the higher
grade wins. `classes/observer.php` runs each time `mod_quiz` recalculates a grade
(`attempt_graded`, `question_manually_graded`, `attempt_regraded`) and compares the two:

- The attempt is higher: the override is removed and the quiz manages its own grade from then
  on.
- The attempt is lower: the override stays, and the carried-over grade is written back to the
  quiz's `quiz_grades` row so the quiz reports match the gradebook.

The table `block_qcexp_carried` stores each carried-over grade. It is how the plugin knows
which overrides are its own, so an override set by a teacher is never touched. A student's row
is deleted once one of their attempts beats the carried-over grade.

## Permissions

- **Seeing the block**: `moodle/site:config` at system level.
- **Running the import**: `moodle/question:managecategory` and `moodle/question:useall` in the
  course.
- **Duplicating the quizzes**: `moodle/course:manageactivities` in the course.
- **Carrying completion over**: `moodle/course:overridecompletion`. Asked for only when the
  course tracks completion.

## File layout

| Path | Role |
| --- | --- |
| `block_qcategory_exporter.php` | Block definition. Builds the category list shown in the block |
| `templates/content.mustache` | The block's markup: category list and Import button |
| `copy_to_course.php` | The page the button posts to. Runs the importer, then the duplicator |
| `classes/exporter.php` | Step 1: find the categories the course's quizzes use |
| `classes/importer.php` | Step 2: import those categories into the course |
| `classes/importer/category_namer.php` | Names the copies and recognises a copy made earlier |
| `classes/importer/xml_flattener.php` | Drops the parent categories from the exported XML |
| `classes/duplicator.php` | Step 3: runs the quiz duplication |
| `classes/duplicator/quiz_builder.php` | Creates the empty copy of a quiz |
| `classes/duplicator/slot_copier.php` | Copies the slots and points them at the imported categories |
| `classes/duplicator/question_resolver.php` | Matches each source question to its imported copy |
| `classes/duplicator/grade_copier.php` | Copies each active student's grade to the copy |
| `classes/duplicator/completion_copier.php` | Marks the copy complete for each active student who completed the original |
| `classes/duplicator/active_students.php` | Works out which students are carried over |
| `classes/duplicator/override_register.php` | Records which overrides the plugin created |
| `classes/observer.php` | Removes an override once the student beats the carried-over grade |
| `classes/privacy/provider.php` | Privacy API: export and deletion of the stored rows |
| `db/events.php` | Registers the observer |
| `db/install.xml`, `db/upgrade.php` | The `block_qcexp_carried` table |
| `tests/` | PHPUnit tests for the export, import and duplicate steps |

## Tests

With PHPUnit set up for the Moodle site, run from the Moodle root:

```
vendor/bin/phpunit --testsuite block_qcategory_exporter_testsuite
```

## Licence

GNU GPL v3 or later. Copyright Innowell. Written by Adomas Ciplys.
