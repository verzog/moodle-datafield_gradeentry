# Changelog

All notable changes to the Grade Entry field plugin (`datafield_gradeentry`)
are documented in this file.

## 1.0.0-beta.4 — 2026-10-16

### Security and privacy
- Students no longer see other students' grades and feedback. The browse view
  showed a released grade, feedback and submission status to anyone who could
  view the entry; it is now shown only to the entry's author (with the
  `datafield/gradeentry:viewgrades` capability) and to graders.
- Unreleased grades no longer appear in the gradebook. Grades were pushed to
  the gradebook as soon as a teacher saved them, so students could see them in
  their grade report before release. Grades now reach the gradebook only when
  released, and unreleasing removes them. When a student has several entries,
  their gradebook grade is that of their most recent released entry.
- A student can no longer grade their own entry by posting a number for this
  field when saving the entry. Numeric values through the entry form (CSV
  import) are accepted only from users who can grade.
- Rubric totals are computed on the server from the selected level scores
  instead of trusting the total sent by the browser.

### Fixed
- Scale-graded activities kept turning back into points (value) grade items:
  a duplicate gradebook sync in the `entry_graded` event observer did not pass
  the scale. The observer is removed; the event is still logged.
- The gradebook maximum was 0 when a numeric field had no maximum set; it now
  defaults to 100. Rubric fields use the sum of each criterion's highest level
  score as the maximum.
- Course-level custom scales were missing from the field settings form.
- "Criterion N" fallback names and the rubric JSON validator messages are now
  translatable.

### Changed
- Supported Moodle versions are now 5.1 to 5.3 LTS (`requires` 5.1). Moodle
  5.0 is no longer supported.
- CI runs a branch-aware matrix for Moodle 5.1, 5.2 and 5.3 (plus Moodle
  `main`, allowed to fail) on PostgreSQL 17 and MariaDB 11.4. A grunt check
  of the AMD build is added but non-blocking until the hand-written
  `amd/build` file is rebuilt with grunt.
- The release workflow runs when a GitHub release is published and refuses to
  build if the tag does not match `$plugin->release`.
- Line endings are forced to LF, and `.github/` and `tests/` are left out of
  release ZIPs.

### Upgrade
- The upgrade removes grades that were saved but never released from the
  gradebook, so existing sites stop showing them to students straight away.
  Released grades are left in place.

## 1.0.0-beta.3 — 2026-07-31

### Fixed
- Privacy API provider is now discovered by Moodle. The provider class was
  located at `privacy/classes/provider.php`, which the class autoloader never
  loads, so the privacy subsystem saw no provider and reported the plugin as
  missing a Privacy API implementation. The class has been moved to its correct
  autoloader path, `classes/privacy/provider.php` (namespace unchanged), so
  privacy metadata, export and deletion now work.

### Added
- PHPUnit coverage for the privacy provider: metadata description, export of
  grading data, and the deletion no-op.

## 1.0.0-beta.2 — 2026-07-01

### Added
- Backup, restore and course copy support. Grades and grading metadata
  (feedback, release state, submission status, rubric scores) are stored in the
  Database activity's content record (`data_content`), so they are included in
  standard mod_data backup, restore and copy.

### Changed
- Grade metadata moved from the plugin's own `datafield_gradeentry_grades` table
  into `data_content.content1` (JSON). The custom table is removed and existing
  rows are migrated automatically on upgrade.
- The grader's identity is no longer stored in field content (a boolean "graded"
  flag is used instead), so it cannot be left pointing at the wrong user after a
  restore to another site. The grader remains recorded on the gradebook item and
  the `entry_graded` event.
- Grading progress is counted in SQL, so teacher browse pages no longer scale
  with the number of entries.

### Fixed
- Privacy export now writes the grade and grading metadata for the field (the
  previous provider never called the export writer, so nothing was exported).

### Compatibility
- Moodle 5.0 or later.
- PHP 8.2 or later.

## 1.0.0-beta — 2026-06-28

Initial public release.

### Features
- Database activity field type that records a grade against each entry.
- Three grading methods: numeric (with min/max bounds, decimal precision and
  optional percentage display), Moodle scale, and rubric (criteria with
  scored levels).
- Inline AJAX grading panel for teachers in the browse view, with autosave,
  feedback, and per-entry grade release.
- Grading progress indicator and a "Release all grades" control.
- Student submission workflow: save as draft, submit for grading, and a
  teacher "require resubmission" action.
- Automatic synchronisation of released grades to the Moodle gradebook.
- Privacy API provider covering the grade value and grade metadata.

### Compatibility
- Moodle 5.0 or later.
- PHP 8.2 or later.
