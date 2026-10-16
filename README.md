# Grade Entry field for Moodle Database activity

Inline grading for the Database activity: numeric, scale, or rubric grades with
feedback, grade release, and gradebook sync.

A Database activity (`mod_data`) field type that turns a database into a
lightweight, gradable activity. Teachers grade each student entry directly in
the browse view through an inline panel, and released grades flow automatically
to the Moodle gradebook.

**Compatibility:** Moodle 5.1 – 5.3 LTS · PHP 8.2 – 8.4 · maturity: beta.

## Features

- **Three grading methods:**
  - *Numeric* — score with configurable minimum/maximum bounds, decimal
    precision, and optional percentage display.
  - *Scale* — grade using any standard Moodle scale.
  - *Rubric* — define criteria and scored levels as JSON; the panel totals the
    score automatically and students see a per-criterion breakdown. The
    gradebook maximum is the sum of each criterion's highest level score.
- **Inline grading** in the browse view with autosave — no separate page.
- **Per-entry feedback** from the teacher. Grades and feedback are shown only to
  the entry's author and to graders, never to other students.
- **Grade release** — release grades to students individually or all at once, or
  hold them back until you are ready.
- **Grading progress** indicator (graded / total).
- **Submission workflow** — students save as draft or submit for grading;
  teachers can flag an entry as requiring resubmission.
- **Gradebook synchronisation** — only released grades are pushed to the
  gradebook, as the activity's grade item (value or scale). Unreleasing a grade
  removes it from the gradebook again.
- **Backup, restore and course copy** — grades and grading metadata are stored in
  the Database activity's own content record, so they are included in standard
  mod_data backup, restore and course-copy.
- **Privacy API** support (export and deletion) for both the grade value and the
  grade metadata (feedback, release state, submission status).

## Requirements

| Moodle | PHP |
|---|---|
| 5.1 | 8.2 – 8.4 |
| 5.2 | 8.3 – 8.4 |
| 5.3 LTS | 8.3 – 8.4 |

Moodle 5.0 and earlier are not supported.

## Installing via uploaded ZIP file

1. Download `datafield_gradeentry-<version>.zip` from the
   [GitHub releases](https://github.com/verzog/moodle-datafield_gradeentry/releases) page.
   Don't use GitHub's "Source code (zip)" download: its root folder is the
   repository name, which Moodle rejects. (If you do, enter `gradeentry` under
   *Install plugins > Show more... > Rename the root directory*.)
2. Log in to your Moodle site as an admin and go to *Site administration > Plugins > Install plugins*.
3. Upload the ZIP file with the plugin code.
4. Check the plugin validation report and finish the installation.

## Installing manually

The plugin can be also installed by putting the contents of this directory to

    {your/moodle/dirroot}/mod/data/field/gradeentry

On Moodle 5.1 and later `dirroot` is the `public/` folder of the Moodle
codebase, so the path is `public/mod/data/field/gradeentry`.

Afterwards, log in to your Moodle site as an admin and go to *Site administration > Notifications* to trigger the installation.

## Configuration

When adding the field to a Database activity, first choose a **grading method**
(numeric, scale, or rubric). The available options depend on the method:

**Numeric**

- **Minimum grade** — lowest acceptable value (leave blank for no minimum)
- **Maximum grade** — highest acceptable value (leave blank for no maximum)
- **Decimal places** — number of decimal places shown in browse view (default 2)
- **Show as percentage** — display the value with its percentage of the maximum grade

**Scale**

- **Scale** — the Moodle scale to grade against (its items appear as a dropdown
  for teachers)

**Rubric**

- **Rubric criteria** — a JSON array of criteria, each with a name and an array of
  levels (`score` + `desc`). The grading panel sums the selected level scores and
  records a per-criterion breakdown.

Grading itself, feedback, grade release, and the draft/submit/resubmission
workflow all happen inline in the browse view once entries exist — no extra
configuration required.

## Third-party libraries

No third-party libraries are bundled with this plugin.

## Limitations

This field type is designed for **one Grade entry field per Database activity**.
The activity's gradebook item and the maximum-grade lookup are keyed on the
parent Database activity (`mod/data`, instance id, item number 0), so adding a
second Grade entry field to the same Database activity would have both fields
share — and overwrite — the same single gradebook item. If you need more than
one graded value, use separate Database activities.

## License

2025 onwards, Vernon Spain/Educheckout

This program is free software: you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation, either version 3 of the License, or (at your option) any later
version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY
WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with
this program. If not, see <https://www.gnu.org/licenses/>.
