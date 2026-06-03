// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Inline grader: wires up grade inputs, feedback textareas, and release
 * controls rendered by datafield_gradeentry for teacher views of the
 * Database activity.
 *
 * @module     datafield_gradeentry/inline_grader
 * @copyright  2025 onwards, Australian developers
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import {get_strings as getStrings} from 'core/str';

/** @type {number} Debounce delay (ms) before autosaving after the last keystroke. */
const DEBOUNCE_MS = 800;

/** Map of recordid → pending debounce timer for grade inputs. */
const debounceTimers = new Map();

/**
 * Initialise the inline grader on a Database activity browse page.
 *
 * Called by datafield_gradeentry\hook_callbacks::before_footer_html_generation()
 * via $PAGE->requires->js_call_amd.
 *
 * @param {number} cmid       Course-module ID.
 * @param {number} contextid  Module context ID (unused client-side; kept for future use).
 */
export const init = (cmid, contextid) => { // eslint-disable-line no-unused-vars
    getStrings([
        {key: 'savinggrade',      component: 'datafield_gradeentry'},
        {key: 'graded',           component: 'datafield_gradeentry'},
        {key: 'errorsavegrade',   component: 'datafield_gradeentry'},
        {key: 'gradingprogress',  component: 'datafield_gradeentry'},
    ]).then(([savingStr, gradedStr, errorStr, progressStr]) => {
        wireGradeInputs(cmid, savingStr, gradedStr, errorStr);
        wireFeedbackAreas(cmid, savingStr, gradedStr, errorStr);
        wireReleaseControls(cmid);
        wireReleaseAllButton(cmid);
        return;
    }).catch(Notification.exception);
};

/**
 * Wire debounced autosave to all grade inputs rendered by the datafield.
 */
const wireGradeInputs = (cmid, savingStr, gradedStr, errorStr) => {
    document.querySelectorAll('[data-gradeentry-field]').forEach(input => {
        input.addEventListener('input', () => {
            const recordid = parseInt(input.dataset.recordid, 10);
            clearTimeout(debounceTimers.get(recordid));
            debounceTimers.set(recordid, setTimeout(() => {
                const feedback = getFeedbackFor(recordid);
                triggerSave(cmid, input, input.dataset.fieldid, input.value, feedback, savingStr, gradedStr, errorStr);
            }, DEBOUNCE_MS));
        });

        // Immediate save on blur in case the user tabs away before the debounce fires.
        input.addEventListener('blur', () => {
            const recordid = parseInt(input.dataset.recordid, 10);
            clearTimeout(debounceTimers.get(recordid));
            const feedback = getFeedbackFor(recordid);
            triggerSave(cmid, input, input.dataset.fieldid, input.value, feedback, savingStr, gradedStr, errorStr);
        });
    });
};

/**
 * Wire blur-autosave to all feedback textareas.
 */
const wireFeedbackAreas = (cmid, savingStr, gradedStr, errorStr) => {
    document.querySelectorAll('[data-gradeentry-feedback]').forEach(textarea => {
        textarea.addEventListener('blur', () => {
            const gradeInput = getGradeInputFor(textarea.dataset.recordid);
            if (!gradeInput || gradeInput.value === '') {
                return; // Don't save feedback without a grade.
            }
            triggerSave(
                cmid, gradeInput, gradeInput.dataset.fieldid,
                gradeInput.value, textarea.value,
                savingStr, gradedStr, errorStr
            );
        });
    });
};

/**
 * Wire release checkboxes (per-entry release toggle).
 */
const wireReleaseControls = (cmid) => {
    document.querySelectorAll('[data-gradeentry-release]').forEach(checkbox => {
        checkbox.addEventListener('change', () => {
            const recordid = parseInt(checkbox.dataset.recordid, 10);
            const desired = checkbox.checked;
            Ajax.call([{
                methodname: 'datafield_gradeentry_release_grades',
                args: {cmid, recordids: [recordid], released: desired},
                done: () => {
                    updateReleasedLabel(checkbox, desired);
                },
                fail: (ex) => {
                    // Roll the checkbox back so the UI matches server state.
                    checkbox.checked = !desired;
                    Notification.exception(ex);
                },
            }]);
        });
    });
};

/**
 * Wire the "Release all grades" button rendered by before_footer.
 */
const wireReleaseAllButton = (cmid) => {
    const btn = document.getElementById('datafield-gradeentry-release-all');
    if (!btn) {
        return;
    }
    btn.addEventListener('click', () => {
        btn.disabled = true;
        Ajax.call([{
            methodname: 'datafield_gradeentry_release_grades',
            args: {cmid, recordids: [], released: true},
            done: (result) => {
                btn.disabled = false;
                document.querySelectorAll('[data-gradeentry-release]').forEach(cb => {
                    cb.checked = true;
                    updateReleasedLabel(cb, true);
                });
                // Briefly flash a confirmation on the progress bar.
                const progressText = document.getElementById('datafield-gradeentry-progress-text');
                if (progressText) {
                    const original = progressText.textContent;
                    progressText.textContent = result.released + ' grades released';
                    setTimeout(() => {
                        progressText.textContent = original;
                    }, 3000);
                }
            },
            fail: (ex) => {
                btn.disabled = false;
                Notification.exception(ex);
            },
        }]);
    });
};

/**
 * Call the save_grade web service and update the status indicator.
 */
const triggerSave = (cmid, gradeInput, fieldid, gradeValue, feedback, savingStr, gradedStr, errorStr) => {
    const recordid = parseInt(gradeInput.dataset.recordid, 10);
    const status   = getStatusFor(recordid);

    setStatus(status, 'saving', savingStr);

    Ajax.call([{
        methodname: 'datafield_gradeentry_save_grade',
        args: {
            cmid,
            recordid,
            fieldid: parseInt(fieldid, 10),
            grade: parseFloat(gradeValue),
            feedback: feedback || '',
        },
        done: (result) => {
            setStatus(status, 'saved', gradedStr);
            updateProgressBar(result.graded, result.total);
        },
        fail: (ex) => {
            setStatus(status, 'error', errorStr);
            Notification.exception(ex);
        },
    }]);
};

// ---------------------------------------------------------------------------
// DOM helpers
// ---------------------------------------------------------------------------

const getGradeInputFor = (recordid) =>
    document.querySelector(`[data-gradeentry-field][data-recordid="${recordid}"]`);

const getFeedbackFor = (recordid) => {
    const el = document.querySelector(`[data-gradeentry-feedback][data-recordid="${recordid}"]`);
    return el ? el.value : '';
};

const getStatusFor = (recordid) =>
    document.querySelector(`[data-gradeentry-status][data-recordid="${recordid}"]`);

const setStatus = (el, state, text) => {
    if (!el) {
        return;
    }
    el.textContent = text;
    el.className = 'gradeentry-status gradeentry-status--' + state;
};

const updateReleasedLabel = (checkbox, isReleased) => {
    const label = checkbox.closest('.gradeentry-release-group')?.querySelector('.gradeentry-release-label');
    if (!label) {
        return;
    }
    label.textContent = isReleased
        ? label.dataset.releasedText
        : label.dataset.unreleasedText;
};

const updateProgressBar = (graded, total) => {
    const bar = document.getElementById('datafield-gradeentry-progress');
    if (!bar) {
        return;
    }
    bar.dataset.graded = graded;
    bar.dataset.total  = total;

    const text = document.getElementById('datafield-gradeentry-progress-text');
    if (text) {
        // Replace the placeholder values in the existing string pattern.
        text.textContent = text.textContent.replace(/\d+\s*\/\s*\d+/, graded + ' / ' + total);
    }
};
