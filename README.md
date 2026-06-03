# Grade Entry field for Moodle Database activity

A custom Database activity field type that allows teachers to collect numeric grade values from students, with configurable minimum/maximum bounds, decimal precision, and optional percentage display.

## Requirements

- Moodle 5.0 or later
- PHP 8.2 or later
- The companion [`local_datagrading`](https://github.com/verzog/moodle-local_datagrading)
  plugin, declared as a hard dependency in `version.php`. It supplies the
  `local/datagrading:grade` capability, teacher grading UI strings, the AJAX
  save endpoint, and the gradebook push. Moodle will refuse to install this
  plugin until `local_datagrading` is present.

## Installing via uploaded ZIP file

1. Install [`local_datagrading`](https://github.com/verzog/moodle-local_datagrading) first.
2. Log in to your Moodle site as an admin and go to *Site administration > Plugins > Install plugins*.
3. Upload the ZIP file with the plugin code.
4. Check the plugin validation report and finish the installation.

## Installing manually

1. Install `local_datagrading` by putting its contents into
   `{your/moodle/dirroot}/local/datagrading`.
2. Put the contents of this directory into
   `{your/moodle/dirroot}/mod/data/field/gradeentry`.
3. Log in as an admin and visit *Site administration > Notifications* to trigger
   the installation of both plugins.

## Configuration

After adding the field to a database activity, you can configure:

- **Minimum grade** — lowest acceptable value (leave blank for no minimum)
- **Maximum grade** — highest acceptable value (leave blank for no maximum)
- **Decimal places** — number of decimal places shown in browse view (default 2)
- **Show as percentage** — display the value with its percentage of the maximum grade

## Third-party libraries

No third-party libraries are bundled with this plugin.

## License

2025 onwards, Australian developers

This program is free software: you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation, either version 3 of the License, or (at your option) any later
version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY
WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with
this program. If not, see <https://www.gnu.org/licenses/>.
