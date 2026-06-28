# Grade Entry field for Moodle Database activity

A custom Database activity field type that allows teachers to collect numeric grade values from students, with configurable minimum/maximum bounds, decimal precision, and optional percentage display.

**Compatibility:** Moodle 5.0 – 5.2 · PHP 8.2 – 8.4 · maturity: beta.

## Requirements

- Moodle 5.0 or later
- PHP 8.2 or later

## Installing via uploaded ZIP file

1. Log in to your Moodle site as an admin and go to *Site administration > Plugins > Install plugins*.
2. Upload the ZIP file with the plugin code.
3. Check the plugin validation report and finish the installation.

## Installing manually

The plugin can be also installed by putting the contents of this directory to

    {your/moodle/dirroot}/mod/data/field/gradeentry

Afterwards, log in to your Moodle site as an admin and go to *Site administration > Notifications* to trigger the installation.

## Configuration

After adding the field to a database activity, you can configure:

- **Minimum grade** — lowest acceptable value (leave blank for no minimum)
- **Maximum grade** — highest acceptable value (leave blank for no maximum)
- **Decimal places** — number of decimal places shown in browse view (default 2)
- **Show as percentage** — display the value with its percentage of the maximum grade

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
