// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Metrics dashboard - AJAX loading, Chart.js rendering, sub-view switching.
 *
 * @module     mod_cmi5/metrics
 * @copyright  2026 David Ropte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import ChartJS from 'core/chartjs';

let cmid = 0;
let isTeacher = false;
let charts = {};
let loaded = {overview: false, learners: false, auanalytics: false};
let activeDays = 30;
let activeUserid = 0;
let activeGroupby = 'verb';
let cachedLearners = null;

/** Verb color palette. */
const VERB_COLORS = [
    '#0d6efd', '#198754', '#ffc107', '#dc3545', '#6f42c1',
    '#0dcaf0', '#fd7e14', '#20c997', '#d63384', '#6c757d',
];

/**
 * Format a Unix timestamp as a localized date string.
 *
 * @param {number} ts Unix timestamp.
 * @returns {string} Formatted date string.
 */
const formatDate = (ts) => {
    if (!ts) {
        return '-';
    }
    return new Date(ts * 1000).toLocaleDateString();
};

/**
 * Format a Unix timestamp as an ISO date string (YYYY-MM-DD).
 *
 * @param {number} ts Unix timestamp.
 * @returns {string} ISO date string or empty string.
 */
const formatDateISO = (ts) => {
    if (!ts) {
        return '';
    }
    return new Date(ts * 1000).toISOString().substring(0, 10);
};

/**
 * Escape a value for CSV (RFC 4180).
 *
 * @param {*} val The value to escape.
 * @returns {string} CSV-safe string.
 */
const csvEscape = (val) => {
    const str = String(val ?? '');
    if (str.includes('"') || str.includes(',') || str.includes('\n')) {
        return '"' + str.replace(/"/g, '""') + '"';
    }
    return str;
};

/**
 * Download a CSV file with given headers, rows, and filename.
 *
 * @param {Array} headers Column header strings.
 * @param {Array} rows Array of row arrays.
 * @param {string} filename Download filename.
 */
const downloadCsv = (headers, rows, filename) => {
    const csv = [headers, ...rows].map(row => row.map(csvEscape).join(',')).join('\r\n');
    const blob = new Blob([csv], {type: 'text/csv;charset=utf-8;'});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
};

/**
 * Generate and download a CSV file from learner data.
 *
 * @param {Array} learners Learner data array from the web service.
 */
const exportLearnersCsv = (learners) => {
    const headers = ['Learner', 'Email', 'Registered', 'Completed AUs', 'Passed AUs',
        'Avg Score', 'Last Active', 'Satisfied'];
    const rows = learners.map(l => [
        l.fullname,
        l.email,
        formatDateISO(l.registrationdate),
        l.completedcount,
        l.passedcount,
        l.avgscore !== null && l.avgscore !== undefined ? (l.avgscore * 100).toFixed(1) + '%' : '',
        formatDateISO(l.lastactive),
        l.coursesatisfied ? 'Yes' : 'No',
    ]);
    downloadCsv(headers, rows, 'learners-export.csv');
};

/**
 * Export timeline data as CSV (date, verb, count).
 *
 * @param {Array} timeline Timeline data from the API.
 * @param {Array} timelineverbs Distinct verb labels.
 * @param {number} days Active date range.
 */
const exportTimelineCsv = (timeline, timelineverbs, days) => {
    const dateLabels = buildDateLabels(timeline, days);
    // Columns: Date, then one column per verb.
    const headers = ['Date', ...timelineverbs];
    // Index data by date+verb for fast lookup.
    const lookup = {};
    timeline.forEach(t => {
        lookup[t.date + '|' + t.verb] = t.count;
    });
    const rows = dateLabels.map(d =>
        [d, ...timelineverbs.map(v => lookup[d + '|' + v] || 0)]
    );
    downloadCsv(headers, rows, 'statements-timeline.csv');
};

/**
 * Export verb distribution data as CSV.
 *
 * @param {Array} verbdistribution Verb distribution from the API.
 */
const exportVerbsCsv = (verbdistribution) => {
    const headers = ['Verb', 'Count'];
    const rows = verbdistribution.map(v => [v.verb, v.count]);
    downloadCsv(headers, rows, 'verb-distribution.csv');
};

/**
 * Format seconds as a human-readable duration string.
 *
 * @param {number} seconds Duration in seconds.
 * @returns {string} Formatted duration (e.g. "2h 15m" or "45s").
 */
const formatDuration = (seconds) => {
    if (!seconds) {
        return '-';
    }
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    if (h > 0) {
        return `${h}h ${m}m`;
    }
    if (m > 0) {
        return `${m}m ${s}s`;
    }
    return `${s}s`;
};

/**
 * Destroy a chart instance by key if it exists.
 *
 * @param {string} key Chart key name.
 */
const destroyChart = (key) => {
    if (charts[key]) {
        charts[key].destroy();
        delete charts[key];
    }
};

/**
 * Show a spinner in a container element.
 *
 * @param {HTMLElement} container The container to show spinner in.
 */
const showSpinner = (container) => {
    container.innerHTML = '<div class="d-flex justify-content-center p-4">' +
        '<div class="spinner-border text-primary" role="status">' +
        '<span class="visually-hidden">Loading...</span></div></div>';
};

/**
 * Show an empty state message.
 *
 * @param {HTMLElement} container The container to show message in.
 * @param {string} message The message text.
 */
const showEmpty = (container, message) => {
    container.innerHTML = `<div class="alert alert-info">${message}</div>`;
};

/**
 * Build the controls bar (date range buttons + learner filter).
 *
 * @returns {string} HTML for the controls bar.
 */
const buildControlsHtml = () => {
    const dayOptions = [
        {value: 7, label: '7d'},
        {value: 14, label: '14d'},
        {value: 30, label: '30d'},
        {value: 0, label: 'All'},
    ];

    let html = '<div class="d-flex flex-wrap align-items-center gap-2 mb-3">';

    // Date range buttons.
    html += '<div class="btn-group btn-group-sm">';
    dayOptions.forEach(opt => {
        const active = opt.value === activeDays ? ' active' : '';
        html += `<button class="btn btn-outline-secondary${active}" data-days="${opt.value}">${opt.label}</button>`;
    });
    html += '</div>';

    // Timeline groupby toggle (teacher only).
    if (isTeacher) {
        const verbActive = activeGroupby === 'verb' ? ' active' : '';
        const userActive = activeGroupby === 'user' ? ' active' : '';
        html += '<div class="btn-group btn-group-sm">';
        html += `<button class="btn btn-outline-primary${verbActive}" data-groupby="verb">By Verb</button>`;
        html += `<button class="btn btn-outline-primary${userActive}" data-groupby="user">By User</button>`;
        html += '</div>';
    }

    // Learner filter (teacher only).
    if (isTeacher) {
        html += '<select class="form-select form-select-sm" id="cmi5-metrics-learner-filter" ' +
            'style="max-width:250px">';
        html += '<option value="0">All learners</option>';
        if (cachedLearners) {
            cachedLearners.forEach(l => {
                const selected = l.userid === activeUserid ? ' selected' : '';
                html += `<option value="${l.userid}"${selected}>${l.fullname}</option>`;
            });
        }
        html += '</select>';
    }

    html += '</div>';
    return html;
};

/**
 * Build unique sorted date labels for the timeline.
 *
 * @param {Array} timeline Timeline data from API.
 * @param {number} days Number of days in range.
 * @returns {Array} Sorted array of date strings.
 */
const buildDateLabels = (timeline, days) => {
    if (days > 0) {
        const labels = [];
        for (let i = days - 1; i >= 0; i--) {
            const d = new Date(Date.now() - (i * 86400000));
            labels.push(d.toISOString().substring(0, 10));
        }
        return labels;
    }
    // All time: extract unique dates from data.
    const set = new Set(timeline.map(t => t.date));
    return [...set].sort();
};

/**
 * Load and render the Overview sub-view.
 */
const loadOverview = async() => {
    const container = document.getElementById('cmi5-metrics-overview');
    showSpinner(container);

    try {
        const data = await Ajax.call([{
            methodname: 'mod_cmi5_get_metrics_overview',
            args: {cmid, days: activeDays, userid: activeUserid, groupby: activeGroupby},
        }])[0];

        let html = buildControlsHtml();

        // KPI cards.
        html += '<div class="row g-3 mb-4">';
        const kpis = [
            {label: 'Registrations', value: data.totalregistrations, cls: 'primary'},
            {label: 'Active Sessions', value: data.activesessions, cls: 'info'},
            {label: 'Completion Rate', value: data.completionrate + '%', cls: 'success'},
            {label: 'Passed', value: data.passcount, cls: 'success'},
            {label: 'Failed', value: data.failcount, cls: 'danger'},
        ];
        kpis.forEach(kpi => {
            html += `<div class="col-sm-6 col-md-4 col-lg">
                <div class="card text-center border-${kpi.cls}">
                    <div class="card-body py-3">
                        <div class="h3 mb-1 text-${kpi.cls}">${kpi.value}</div>
                        <div class="text-muted small">${kpi.label}</div>
                    </div>
                </div>
            </div>`;
        });
        html += '</div>';

        // Charts row.
        html += '<div class="row g-3">';
        html += '<div class="col-lg-8"><div class="card"><div class="card-body">' +
            '<div class="d-flex justify-content-between align-items-center mb-2">' +
            '<h5 class="card-title mb-0">Statements Over Time</h5>' +
            '<button class="btn btn-outline-secondary btn-sm" id="cmi5-export-timeline">' +
            '<i class="fa fa-download me-1"></i>CSV</button></div>' +
            '<div style="position:relative;height:250px"><canvas id="cmi5-chart-timeline"></canvas></div>' +
            '</div></div></div>';
        html += '<div class="col-lg-4"><div class="card"><div class="card-body">' +
            '<div class="d-flex justify-content-between align-items-center mb-2">' +
            '<h5 class="card-title mb-0">Verb Distribution</h5>' +
            '<button class="btn btn-outline-secondary btn-sm" id="cmi5-export-verbs">' +
            '<i class="fa fa-download me-1"></i>CSV</button></div>' +
            '<div style="position:relative;height:250px"><canvas id="cmi5-chart-verbs"></canvas></div>' +
            '</div></div></div>';
        html += '</div>';

        container.innerHTML = html;

        // Bind date range buttons.
        container.querySelectorAll('[data-days]').forEach(btn => {
            btn.addEventListener('click', () => {
                activeDays = parseInt(btn.dataset.days, 10);
                loaded.overview = false;
                loadOverview();
            });
        });

        // Bind groupby toggle.
        container.querySelectorAll('[data-groupby]').forEach(btn => {
            btn.addEventListener('click', () => {
                activeGroupby = btn.dataset.groupby;
                loaded.overview = false;
                loadOverview();
            });
        });

        // Bind learner filter.
        const learnerSelect = document.getElementById('cmi5-metrics-learner-filter');
        if (learnerSelect) {
            learnerSelect.addEventListener('change', () => {
                activeUserid = parseInt(learnerSelect.value, 10);
                loaded.overview = false;
                loadOverview();
            });
        }

        // Bind CSV export buttons.
        document.getElementById('cmi5-export-timeline')?.addEventListener('click', () => {
            exportTimelineCsv(data.timeline, data.timelineverbs, activeDays);
        });
        document.getElementById('cmi5-export-verbs')?.addEventListener('click', () => {
            exportVerbsCsv(data.verbdistribution);
        });

        // Timeline chart — multi-series stacked area.
        destroyChart('timeline');
        const timelineCtx = document.getElementById('cmi5-chart-timeline');
        if (timelineCtx && data.timeline.length > 0) {
            const dateLabels = buildDateLabels(data.timeline, activeDays);
            const displayLabels = dateLabels.map(d => d.substring(5));

            // Build per-verb data indexed by date.
            const verbData = {};
            data.timelineverbs.forEach(v => {
                verbData[v] = {};
            });
            data.timeline.forEach(t => {
                if (verbData[t.verb]) {
                    verbData[t.verb][t.date] = t.count;
                }
            });

            const datasets = data.timelineverbs.map((verb, idx) => ({
                label: verb,
                data: dateLabels.map(d => verbData[verb][d] || 0),
                borderColor: VERB_COLORS[idx % VERB_COLORS.length],
                backgroundColor: VERB_COLORS[idx % VERB_COLORS.length] + '40',
                fill: true,
                tension: 0.3,
            }));

            charts.timeline = new ChartJS(timelineCtx, {
                type: 'line',
                data: {labels: displayLabels, datasets},
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {beginAtZero: true, stacked: true, ticks: {precision: 0}},
                        x: {stacked: true},
                    },
                    plugins: {legend: {display: true, position: 'top'}},
                },
            });
        }

        // Verb distribution chart.
        destroyChart('verbs');
        const verbsCtx = document.getElementById('cmi5-chart-verbs');
        if (verbsCtx && data.verbdistribution.length > 0) {
            charts.verbs = new ChartJS(verbsCtx, {
                type: 'doughnut',
                data: {
                    labels: data.verbdistribution.map(v => v.verb),
                    datasets: [{
                        data: data.verbdistribution.map(v => v.count),
                        backgroundColor: VERB_COLORS.slice(0, data.verbdistribution.length),
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {legend: {position: 'right'}},
                },
            });
        }

        loaded.overview = true;
    } catch (err) {
        container.innerHTML = `<div class="alert alert-danger">${err.message || 'Error loading metrics'}</div>`;
    }
};

/**
 * Load and render the Learner Progress sub-view.
 *
 * @param {number} userid Specific user for drill-down, or 0 for summary.
 */
const loadLearnerProgress = async(userid = 0) => {
    const container = document.getElementById('cmi5-metrics-learners');
    showSpinner(container);

    try {
        const data = await Ajax.call([{
            methodname: 'mod_cmi5_get_learner_progress',
            args: {cmid, userid},
        }])[0];

        if (data.mode === 'summary') {
            // Cache learners for the overview filter dropdown.
            if (data.learners.length) {
                cachedLearners = data.learners;
            }

            if (!data.learners.length) {
                showEmpty(container, 'No learner data available yet.');
                loaded.learners = true;
                return;
            }

            let html = '<div class="d-flex justify-content-end mb-2">' +
                '<button class="btn btn-outline-secondary btn-sm mod-cmi5-export-csv">' +
                '<i class="fa fa-download me-1"></i>Export CSV</button></div>';
            html += '<div class="table-responsive"><table class="table table-striped table-hover">';
            html += '<thead><tr>' +
                '<th>Learner</th><th>Registered</th><th>Completed</th>' +
                '<th>Passed</th><th>Avg Score</th><th>Last Active</th><th>Satisfied</th>' +
                (isTeacher ? '<th>Actions</th>' : '') +
                '</tr></thead><tbody>';

            data.learners.forEach(l => {
                const score = l.avgscore !== null && l.avgscore !== undefined
                    ? (l.avgscore * 100).toFixed(1) + '%' : '-';
                const satisfied = l.coursesatisfied
                    ? '<span class="badge bg-success">Yes</span>'
                    : '<span class="badge bg-secondary">No</span>';
                html += `<tr class="mod-cmi5-learner-row" data-userid="${l.userid}"
                    data-fullname="${l.fullname.replace(/"/g, '&quot;')}" style="cursor:pointer">
                    <td><strong>${l.fullname}</strong><br><small class="text-muted">${l.email}</small></td>
                    <td>${formatDate(l.registrationdate)}</td>
                    <td>${l.completedcount}</td>
                    <td>${l.passedcount}</td>
                    <td>${score}</td>
                    <td>${formatDate(l.lastactive)}</td>
                    <td>${satisfied}</td>`;
                if (isTeacher) {
                    html += `<td class="text-nowrap">
                        <button class="btn btn-outline-warning btn-sm mod-cmi5-reset-reg me-1"
                            data-userid="${l.userid}" data-fullname="${l.fullname.replace(/"/g, '&quot;')}"
                            title="Reset sessions and state (keeps registration)">
                            Reset</button>
                        <button class="btn btn-outline-danger btn-sm mod-cmi5-delete-reg"
                            data-userid="${l.userid}" data-fullname="${l.fullname.replace(/"/g, '&quot;')}"
                            title="Delete registration and all data">
                            Delete</button></td>`;
                }
                html += '</tr>';
            });

            html += '</tbody></table></div>';
            container.innerHTML = html;

            // Bind drill-down clicks (on the row, but not on action buttons).
            container.querySelectorAll('.mod-cmi5-learner-row').forEach(row => {
                row.addEventListener('click', (e) => {
                    if (e.target.closest('.mod-cmi5-delete-reg') || e.target.closest('.mod-cmi5-reset-reg')) {
                        return;
                    }
                    loadLearnerProgress(parseInt(row.dataset.userid, 10));
                });
            });

            // Bind delete buttons.
            container.querySelectorAll('.mod-cmi5-delete-reg').forEach(btn => {
                btn.addEventListener('click', async(e) => {
                    e.stopPropagation();
                    const uid = parseInt(btn.dataset.userid, 10);
                    const name = btn.dataset.fullname;
                    if (!confirm(`Delete all progress for ${name}? This cannot be undone.`)) {
                        return;
                    }
                    // Optimistic UI: disable button and fade row.
                    btn.disabled = true;
                    btn.textContent = '...';
                    const row = btn.closest('tr');
                    if (row) {
                        row.style.opacity = '0.4';
                    }
                    try {
                        await Ajax.call([{
                            methodname: 'mod_cmi5_delete_registration',
                            args: {cmid, userid: uid},
                        }])[0];
                        // Remove row from DOM immediately.
                        if (row) {
                            row.remove();
                        }
                        loaded.overview = false;
                        // Update cached learners.
                        if (cachedLearners) {
                            cachedLearners = cachedLearners.filter(l => l.userid !== uid);
                        }
                    } catch (err) {
                        btn.disabled = false;
                        btn.textContent = 'Delete';
                        if (row) {
                            row.style.opacity = '';
                        }
                        window.alert(err.message || 'Error deleting registration');
                    }
                });
            });

            // Bind reset buttons.
            container.querySelectorAll('.mod-cmi5-reset-reg').forEach(btn => {
                btn.addEventListener('click', async(e) => {
                    e.stopPropagation();
                    const uid = parseInt(btn.dataset.userid, 10);
                    const name = btn.dataset.fullname;
                    if (!confirm(`Reset all sessions and state for ${name}? Registration will be kept.`)) {
                        return;
                    }
                    btn.disabled = true;
                    btn.textContent = '...';
                    try {
                        await Ajax.call([{
                            methodname: 'mod_cmi5_reset_registration_state',
                            args: {cmid, userid: uid},
                        }])[0];
                        btn.textContent = 'Done';
                        btn.classList.remove('btn-outline-warning');
                        btn.classList.add('btn-success');
                        loaded.overview = false;
                        // Re-enable after a moment.
                        setTimeout(() => {
                            btn.disabled = false;
                            btn.textContent = 'Reset';
                            btn.classList.remove('btn-success');
                            btn.classList.add('btn-outline-warning');
                        }, 2000);
                    } catch (err) {
                        btn.disabled = false;
                        btn.textContent = 'Reset';
                        window.alert(err.message || 'Error resetting registration state');
                    }
                });
            });

            // Bind CSV export.
            container.querySelector('.mod-cmi5-export-csv')?.addEventListener('click', () => {
                exportLearnersCsv(data.learners);
            });

            loaded.learners = true;
        } else {
            // Drilldown mode.
            let html = `<button class="btn btn-outline-secondary btn-sm mb-3 mod-cmi5-back-btn">
                &larr; Back to all learners</button>`;
            html += `<h5>${data.learnername}</h5>`;

            // AU statuses.
            if (data.austatuses.length) {
                html += '<h6 class="mt-3">AU Status</h6>';
                html += '<div class="table-responsive"><table class="table table-sm table-striped">';
                html += '<thead><tr><th>AU</th><th>Status</th><th>Score</th><th>Updated</th></tr></thead><tbody>';
                data.austatuses.forEach(au => {
                    let status = 'Not started';
                    let cls = 'secondary';
                    if (au.satisfied) {
                        status = 'Satisfied';
                        cls = 'success';
                    } else if (au.failed) {
                        status = 'Failed';
                        cls = 'danger';
                    } else if (au.passed) {
                        status = 'Passed';
                        cls = 'success';
                    } else if (au.completed) {
                        status = 'Completed';
                        cls = 'primary';
                    }
                    const score = au.score !== null && au.score !== undefined
                        ? (au.score * 100).toFixed(1) + '%' : '-';
                    html += `<tr><td>${au.title}</td>
                        <td><span class="badge bg-${cls}">${status}</span></td>
                        <td>${score}</td>
                        <td>${formatDate(au.timemodified)}</td></tr>`;
                });
                html += '</tbody></table></div>';
            }

            // Sessions.
            if (data.sessions.length) {
                html += '<h6 class="mt-3">Session History</h6>';
                html += '<div class="table-responsive"><table class="table table-sm table-striped">';
                html += '<thead><tr><th>AU</th><th>State</th><th>Duration</th><th>Started</th></tr></thead><tbody>';
                data.sessions.forEach(s => {
                    let state = 'Active';
                    if (s.terminated) {
                        state = 'Terminated';
                    } else if (s.abandoned) {
                        state = 'Abandoned';
                    } else if (s.initialized) {
                        state = 'Initialized';
                    }
                    html += `<tr><td>${s.autitle}</td><td>${state}</td>
                        <td>${formatDuration(s.duration)}</td>
                        <td>${formatDate(s.timecreated)}</td></tr>`;
                });
                html += '</tbody></table></div>';
            }

            container.innerHTML = html;

            // Back button.
            container.querySelector('.mod-cmi5-back-btn')?.addEventListener('click', () => {
                loaded.learners = false;
                loadLearnerProgress(0);
            });
        }
    } catch (err) {
        container.innerHTML = `<div class="alert alert-danger">${err.message || 'Error loading learner data'}</div>`;
    }
};

/**
 * Load and render the AU Analytics sub-view.
 */
const loadAuAnalytics = async() => {
    const container = document.getElementById('cmi5-metrics-auanalytics');
    if (loaded.auanalytics) {
        return;
    }
    showSpinner(container);

    try {
        const data = await Ajax.call([{
            methodname: 'mod_cmi5_get_au_analytics',
            args: {cmid},
        }])[0];

        if (!data.length) {
            showEmpty(container, 'No AU analytics data available yet.');
            loaded.auanalytics = true;
            return;
        }

        let html = '';

        // Chart.
        if (data.length > 1) {
            html += '<div class="card mb-3"><div class="card-body">' +
                '<h5 class="card-title">Completion Rates by AU</h5>' +
                '<div style="position:relative;height:' + Math.max(150, data.length * 30) + 'px"><canvas id="cmi5-chart-au"></canvas></div>' +
                '</div></div>';
        }

        // Table.
        html += '<div class="table-responsive"><table class="table table-striped">';
        html += '<thead><tr>' +
            '<th>AU</th><th>Learners</th><th>Completed</th><th>Passed</th>' +
            '<th>Failed</th><th>Completion %</th><th>Avg Score</th>' +
            '<th>Sessions</th><th>Avg Duration</th>' +
            '</tr></thead><tbody>';

        data.forEach(au => {
            const score = au.avgscore !== null && au.avgscore !== undefined
                ? (au.avgscore * 100).toFixed(1) + '%' : '-';
            html += `<tr>
                <td><strong>${au.title}</strong></td>
                <td>${au.learnercount}</td>
                <td>${au.completedcount}</td>
                <td>${au.passedcount}</td>
                <td>${au.failedcount}</td>
                <td>${au.completionrate}%</td>
                <td>${score}</td>
                <td>${au.sessioncount}</td>
                <td>${formatDuration(au.avgduration)}</td>
            </tr>`;
        });

        html += '</tbody></table></div>';
        container.innerHTML = html;

        // Bar chart.
        destroyChart('au');
        const auCtx = document.getElementById('cmi5-chart-au');
        if (auCtx) {
            charts.au = new ChartJS(auCtx, {
                type: 'bar',
                data: {
                    labels: data.map(a => a.title),
                    datasets: [{
                        label: 'Completion %',
                        data: data.map(a => a.completionrate),
                        backgroundColor: 'rgba(13, 110, 253, 0.7)',
                    }],
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {x: {beginAtZero: true, max: 100, ticks: {callback: v => v + '%'}}},
                    plugins: {legend: {display: false}},
                },
            });
        }

        loaded.auanalytics = true;
    } catch (err) {
        container.innerHTML = `<div class="alert alert-danger">${err.message || 'Error loading AU analytics'}</div>`;
    }
};

/**
 * Initialize the metrics module.
 *
 * @param {number} courseModuleId The course module ID.
 * @param {boolean} teacher Whether the current user is a teacher.
 * @param {boolean} activeOnLoad Whether the metrics tab is active on page load.
 */
export const init = (courseModuleId, teacher, activeOnLoad) => {
    cmid = courseModuleId;
    isTeacher = teacher;

    // Load immediately if metrics tab is active on load.
    if (activeOnLoad) {
        loadOverview();
    }

    // Lazy-load when metrics tab is first shown.
    const metricsTab = document.getElementById('cmi5-tab-metrics');
    if (metricsTab) {
        metricsTab.addEventListener('shown.bs.tab', () => {
            if (!loaded.overview) {
                loadOverview();
            }
        });
    }

    // Sub-view pill switching.
    const overviewPill = document.getElementById('cmi5-pill-overview');
    const learnersPill = document.getElementById('cmi5-pill-learners');
    const auPill = document.getElementById('cmi5-pill-auanalytics');

    if (overviewPill) {
        overviewPill.addEventListener('shown.bs.tab', () => {
            loadOverview();
        });
    }
    if (learnersPill) {
        learnersPill.addEventListener('shown.bs.tab', () => {
            if (!loaded.learners) {
                loadLearnerProgress(0);
            }
        });
    }
    if (auPill) {
        auPill.addEventListener('shown.bs.tab', () => {
            loadAuAnalytics();
        });
    }
};
