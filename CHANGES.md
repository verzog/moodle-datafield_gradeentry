# Changelog

All notable changes to the Grade Entry field plugin (`datafield_gradeentry`)
are documented in this file.

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
