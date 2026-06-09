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
 * Inline grader: wires up grade inputs, feedback textareas, release controls,
 * submission status buttons, require-resubmission checkboxes, and rubric panels.
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

/** @type {Object.<string, string>} Submission-status badge labels, populated from lang on init. */
let statusLabels = {};

/** @type {string} Template for the "N grades released" message, populated from lang on init. */
let releasedCountStr = '{$a} grades released';

/**
 * Initialise the inline grader on a Database activity browse page.
 *
 * @param {number} cmid       Course-module ID.
 * @param {number} contextid  Module context ID.
 */
export const init = (cmid, contextid) => { // eslint-disable-line no-unused-vars
    getStrings([
        {key: 'savinggrade',    component: 'datafield_gradeentry'},
        {key: 'graded',         component: 'datafield_gradeentry'},
        {key: 'errorsavegrade', component: 'datafield_gradeentry'},
        {key: 'gradingprogress', component: 'datafield_gradeentry'},
        {key: 'savingstatus',   component: 'datafield_gradeentry'},
        {key: 'errorsavestatus', component: 'datafield_gradeentry'},
        {key: 'submissionnotsubmitted', component: 'datafield_gradeentry'},
        {key: 'submissiondraft', component: 'datafield_gradeentry'},
        {key: 'submissionsubmitted', component: 'datafield_gradeentry'},
        {key: 'submissionresubmit', component: 'datafield_gradeentry'},
        {key: 'releasedcount',  component: 'datafield_gradeentry'},
    ]).then((strings) => {
        const [savingStr, gradedStr, errorStr, , savingStatusStr, errorStatusStr] = strings;
        statusLabels = {
            notsubmitted: strings[6],
            draft:        strings[7],
            submitted:    strings[8],
            resubmit:     strings[9],
        };
        releasedCountStr = strings[10];

        wireGradeInputs(cmid, savingStr, gradedStr, errorStr);
        wireFeedbackAreas(cmid, savingStr, gradedStr, errorStr);
        wireReleaseControls(cmid);
        wireReleaseAllButton(cmid);
        wireSubmissionStatusButtons(cmid, savingStatusStr, errorStatusStr);
        wireResubmissionControls(cmid);
        wireRubricPanels(cmid, savingStr, gradedStr, errorStr);
        return;
    }).catch(Notification.exception);
};

// ---------------------------------------------------------------------------
// Grade inputs (numeric and scale select)
// ---------------------------------------------------------------------------

const wireGradeInputs = (cmid, savingStr, gradedStr, errorStr) => {
    document.querySelectorAll('[data-gradeentry-field]:not([type="hidden"])').forEach(input => {
        const method = input.dataset.gradingMethod || 'numeric';
        if (method === 'rubric') {
            return; // Rubric inputs are handled separately.
        }

        const saveHandler = () => {
            const feedback = getFeedbackFor(input.dataset.recordid);
            const value    = input.value;
            if (value === '') {
                return;
            }
            triggerSave(cmid, input, input.dataset.fieldid, parseFloat(value), feedback, '', savingStr, gradedStr, errorStr);
        };

        if (input.tagName === 'SELECT') {
            input.addEventListener('change', saveHandler);
        } else {
            input.addEventListener('input', () => {
                const recordid = parseInt(input.dataset.recordid, 10);
                clearTimeout(debounceTimers.get(recordid));
                debounceTimers.set(recordid, setTimeout(saveHandler, DEBOUNCE_MS));
            });
            input.addEventListener('blur', () => {
                const recordid = parseInt(input.dataset.recordid, 10);
                clearTimeout(debounceTimers.get(recordid));
                saveHandler();
            });
        }
    });
};

const wireFeedbackAreas = (cmid, savingStr, gradedStr, errorStr) => {
    document.querySelectorAll('[data-gradeentry-feedback]').forEach(textarea => {
        textarea.addEventListener('blur', () => {
            const gradeInput = getGradeInputFor(textarea.dataset.recordid);
            if (!gradeInput || gradeInput.value === '') {
                return;
            }
            triggerSave(
                cmid, gradeInput, gradeInput.dataset.fieldid,
                parseFloat(gradeInput.value), textarea.value,
                '', savingStr, gradedStr, errorStr
            );
        });
    });
};

// ---------------------------------------------------------------------------
// Release controls
// ---------------------------------------------------------------------------

const wireReleaseControls = (cmid) => {
    document.querySelectorAll('[data-gradeentry-release]').forEach(checkbox => {
        checkbox.addEventListener('change', () => {
            const recordid = parseInt(checkbox.dataset.recordid, 10);
            const desired  = checkbox.checked;
            Ajax.call([{
                methodname: 'datafield_gradeentry_release_grades',
                args: {cmid, recordids: [recordid], released: desired},
                done: () => {
                    updateReleasedLabel(checkbox, desired);
                },
                fail: (ex) => {
                    checkbox.checked = !desired;
                    Notification.exception(ex);
                },
            }]);
        });
    });
};

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
                const progressText = document.getElementById('datafield-gradeentry-progress-text');
                if (progressText) {
                    const original = progressText.textContent;
                    progressText.textContent = releasedCountStr.replace('{$a}', result.released);
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

// ---------------------------------------------------------------------------
// Submission status buttons (student draft / submit)
// ---------------------------------------------------------------------------

const wireSubmissionStatusButtons = (cmid, savingStatusStr, errorStatusStr) => {
    document.querySelectorAll('[data-gradeentry-submit-status]').forEach(btn => {
        btn.addEventListener('click', () => {
            const recordid = parseInt(btn.dataset.recordid, 10);
            const status   = btn.dataset.status;

            // Visually indicate saving.
            const badge = getSubmissionBadge(recordid);
            if (badge) {
                badge.textContent = savingStatusStr;
            }

            Ajax.call([{
                methodname: 'datafield_gradeentry_save_submission_status',
                args: {cmid, recordid, status},
                done: (result) => {
                    updateSubmissionBadge(recordid, result.status);
                },
                fail: (ex) => {
                    if (badge) {
                        badge.textContent = errorStatusStr;
                    }
                    Notification.exception(ex);
                },
            }]);
        });
    });
};

// ---------------------------------------------------------------------------
// Require resubmission controls (teacher)
// ---------------------------------------------------------------------------

const wireResubmissionControls = (cmid) => {
    document.querySelectorAll('[data-gradeentry-resubmit]').forEach(checkbox => {
        checkbox.addEventListener('change', () => {
            const recordid = parseInt(checkbox.dataset.recordid, 10);
            const desired  = checkbox.checked;

            Ajax.call([{
                methodname: 'datafield_gradeentry_require_resubmission',
                args: {cmid, recordid, require: desired},
                done: () => {
                    // Reflect the new status in the submission badge.
                    const newStatus = desired ? 'resubmit' : 'submitted';
                    updateSubmissionBadge(recordid, newStatus);
                },
                fail: (ex) => {
                    checkbox.checked = !desired;
                    Notification.exception(ex);
                },
            }]);
        });
    });
};

// ---------------------------------------------------------------------------
// Rubric panels
// ---------------------------------------------------------------------------

const wireRubricPanels = (cmid, savingStr, gradedStr, errorStr) => {
    document.querySelectorAll('[data-gradeentry-rubric]').forEach(panel => {
        const recordid = parseInt(panel.dataset.recordid, 10);
        const fieldid  = parseInt(panel.dataset.fieldid, 10);
        const hidden   = panel.querySelector('[data-gradeentry-field][type="hidden"]');

        panel.querySelectorAll('.gradeentry-criterion').forEach(criterion => {
            criterion.querySelectorAll('.gradeentry-rubric-level').forEach(btn => {
                btn.addEventListener('click', () => {
                    // Deselect siblings in this criterion.
                    criterion.querySelectorAll('.gradeentry-rubric-level').forEach(b => {
                        b.classList.remove('btn-primary', 'active');
                        b.classList.add('btn-outline-secondary');
                    });
                    // Select clicked level.
                    btn.classList.remove('btn-outline-secondary');
                    btn.classList.add('btn-primary', 'active');

                    // Recalculate total and update hidden input.
                    const {total, scores} = calcRubricTotal(panel);
                    if (hidden) {
                        hidden.value = total;
                    }

                    // Update displayed total.
                    const totalEl = panel.querySelector('.gradeentry-rubric-total');
                    if (totalEl) {
                        totalEl.textContent = totalEl.textContent.replace(/[\d.]+/, total);
                    }

                    // Save via the standard triggerSave path.
                    const feedback = getFeedbackFor(recordid);
                    triggerSave(
                        cmid, hidden, fieldid, total, feedback,
                        JSON.stringify(scores),
                        savingStr, gradedStr, errorStr
                    );
                });
            });
        });
    });
};

/**
 * Compute the total rubric score from selected level buttons.
 *
 * @param {Element} panel  The rubric panel element.
 * @returns {{total: number, scores: number[]}}
 */
const calcRubricTotal = (panel) => {
    let total = 0;
    const scores = [];
    panel.querySelectorAll('.gradeentry-criterion').forEach(criterion => {
        const selected = criterion.querySelector('.gradeentry-rubric-level.active');
        const score    = selected ? parseFloat(selected.dataset.score) : 0;
        total += score;
        scores.push(score);
    });
    return {total, scores};
};

// ---------------------------------------------------------------------------
// Core save call
// ---------------------------------------------------------------------------

const triggerSave = (cmid, gradeInput, fieldid, gradeValue, feedback, rubricscores, savingStr, gradedStr, errorStr) => {
    const recordid = parseInt(gradeInput.dataset.recordid, 10);
    const status   = getStatusFor(recordid);

    setStatus(status, 'saving', savingStr);

    Ajax.call([{
        methodname: 'datafield_gradeentry_save_grade',
        args: {
            cmid,
            recordid,
            fieldid: parseInt(fieldid, 10),
            grade: gradeValue,
            feedback: feedback || '',
            rubricscores: rubricscores || '',
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
    document.querySelector(`[data-gradeentry-field][data-recordid="${recordid}"]:not([type="hidden"])`);

const getFeedbackFor = (recordid) => {
    const el = document.querySelector(`[data-gradeentry-feedback][data-recordid="${recordid}"]`);
    return el ? el.value : '';
};

const getStatusFor = (recordid) =>
    document.querySelector(`[data-gradeentry-status][data-recordid="${recordid}"]`);

const getSubmissionBadge = (recordid) =>
    document.querySelector(`[data-gradeentry-submission-badge][data-recordid="${recordid}"]`);

const setStatus = (el, state, text) => {
    if (!el) {
        return;
    }
    el.textContent = text;
    el.className   = 'gradeentry-status gradeentry-status--' + state;
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
        text.textContent = text.textContent.replace(/\d+\s*\/\s*\d+/, graded + ' / ' + total);
    }
};

const updateSubmissionBadge = (recordid, status) => {
    const badge = getSubmissionBadge(recordid);
    if (!badge) {
        return;
    }
    badge.textContent = statusLabels[status] || status;

    // Swap badge colour class.
    badge.classList.remove('text-bg-secondary', 'text-bg-warning', 'text-bg-success', 'text-bg-danger');
    const colourMap = {
        notsubmitted: 'text-bg-secondary',
        draft:        'text-bg-warning',
        submitted:    'text-bg-success',
        resubmit:     'text-bg-danger',
    };
    badge.classList.add(colourMap[status] || 'text-bg-secondary');
};
