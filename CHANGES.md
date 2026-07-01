# Changelog

All notable changes to the Grade Entry field plugin (`datafield_gradeentry`)
are documented in this file.

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
- Grades and grading metadata are stored in the Database activity's content
  record (`data_content`), so they are included in course backup, restore and
  copy without a datafield backup subplugin (which mod_data does not support).
- Privacy API provider covering both the grade value and the grade metadata
  (feedback, release state, submission status).

### Compatibility
- Moodle 5.0 or later.
- PHP 8.2 or later.
