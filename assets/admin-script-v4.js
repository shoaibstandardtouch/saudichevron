/**
 * Safety Badges Manager Chart.js Initializations
 */
jQuery(document).ready(function($) {
    // Helper to escape HTML tags
    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }



    // 1. Dashboard Charts (Only if sbmChartData is defined)
    if (typeof sbmChartData !== 'undefined') {
        // 1. Compliance Doughnut Chart
        var ctxCompliance = document.getElementById('sbmDashboardPassFailChart');
        if (ctxCompliance) {
            var totalPasses = typeof sbmDashboardAllTimeStats !== 'undefined' ? sbmDashboardAllTimeStats.passes : 0;
            var totalFails  = typeof sbmDashboardAllTimeStats !== 'undefined' ? sbmDashboardAllTimeStats.fails : 0;
            
            new Chart(ctxCompliance, {
                type: 'doughnut',
                data: {
                    labels: ['Passed', 'Failed'],
                    datasets: [{
                        data: [
                            totalPasses,
                            totalFails
                        ],
                        backgroundColor: ['#10b981', '#ef4444'],
                        borderColor: '#ffffff',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                font: {
                                    family: 'Segoe UI, Arial, sans-serif',
                                    size: 12
                                },
                                padding: 15
                            }
                        }
                    }
                }
            });
        }

        // 2. Quiz Pass/Fail Stacked Bar Chart
        var ctxTrends = document.getElementById('sbmTrendsChart');
        if (ctxTrends) {
            var months = [];
            var passes = [];
            var fails = [];

            $.each(sbmChartData.trends, function(index, item) {
                months.push(item.month);
                passes.push(item.passes);
                fails.push(item.fails);
            });

            new Chart(ctxTrends, {
                type: 'bar',
                data: {
                    labels: months,
                    datasets: [
                        {
                            label: 'Passed (Badge Created)',
                            data: passes,
                            backgroundColor: '#10b981',
                            borderRadius: 4
                        },
                        {
                            label: 'Failed / Retake Required',
                            data: fails,
                            backgroundColor: '#fca5a5',
                            borderRadius: 4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            stacked: true,
                            grid: {
                                display: false
                            }
                        },
                        y: {
                            stacked: true,
                            grid: {
                                color: '#f1f5f9'
                            },
                            ticks: {
                                precision: 0
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                font: {
                                    size: 12
                                },
                                padding: 15
                            }
                        }
                    }
                }
            });
        }

        // 3. Expiry Forecast Line Chart
        var ctxForecast = document.getElementById('sbmForecastChart');
        if (ctxForecast) {
            var forecastMonths = [];
            var forecastCounts = [];

            $.each(sbmChartData.expiry_forecast, function(index, item) {
                forecastMonths.push(item.month);
                forecastCounts.push(item.count);
            });

            new Chart(ctxForecast, {
                type: 'line',
                data: {
                    labels: forecastMonths,
                    datasets: [{
                        label: 'Badges Expiring',
                        data: forecastCounts,
                        borderColor: '#ef4444',
                        backgroundColor: 'rgba(239, 68, 68, 0.05)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.35,
                        pointBackgroundColor: '#ef4444',
                        pointRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            grid: {
                                display: false
                            }
                        },
                        y: {
                            min: 0,
                            grid: {
                                color: '#f1f5f9'
                            },
                            ticks: {
                                precision: 0
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 15
                            }
                        }
                    }
                }
            });
        }
    }
    // 4. Reports Charts (Only if sbmReportsChartData is defined)
    if (typeof sbmReportsChartData !== 'undefined') {
        // A. Doughnut Chart for Pass/Fail attempts
        var ctxReportsDoughnut = document.getElementById('sbmReportsDoughnutChart');
        if (ctxReportsDoughnut) {
            new Chart(ctxReportsDoughnut, {
                type: 'doughnut',
                data: {
                    labels: ['Passed Attempts', 'Failed Attempts'],
                    datasets: [{
                        data: [
                            sbmReportsChartData.doughnut.passed,
                            sbmReportsChartData.doughnut.failed
                        ],
                        backgroundColor: ['#10b981', '#ef4444'],
                        borderColor: '#ffffff',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 15
                            }
                        }
                    }
                }
            });
        }

        // B. Grouped Bar Chart for attempts trends
        var ctxReportsTrends = document.getElementById('sbmReportsTrendsChart');
        if (ctxReportsTrends) {
            new Chart(ctxReportsTrends, {
                type: 'bar',
                data: {
                    labels: sbmReportsChartData.trends.labels,
                    datasets: [
                        {
                            label: 'Total Quiz Attempts',
                            data: sbmReportsChartData.trends.appeared,
                            backgroundColor: '#2563eb', // Blue
                            borderRadius: 4
                        },
                        {
                            label: 'Passed Exams',
                            data: sbmReportsChartData.trends.passed,
                            backgroundColor: '#10b981', // Green
                            borderRadius: 4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            grid: { display: false }
                        },
                        y: {
                            grid: { color: '#f1f5f9' },
                            ticks: { precision: 0 },
                            min: 0
                        }
                    },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { padding: 15 }
                        }
                    }
                }
            });
        }

        // C. Horizontal Bar Chart for average scores by quiz
        var ctxReportsScores = document.getElementById('sbmReportsScoresChart');
        if (ctxReportsScores) {
            new Chart(ctxReportsScores, {
                type: 'bar',
                data: {
                    labels: sbmReportsChartData.quizScores.labels,
                    datasets: [{
                        label: 'Average Score (%)',
                        data: sbmReportsChartData.quizScores.averages,
                        backgroundColor: '#8b5cf6', // Violet
                        borderRadius: 4
                    }]
                },
                options: {
                    indexAxis: 'y', // Makes it horizontal!
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            max: 100,
                            min: 0,
                            grid: { color: '#f1f5f9' }
                        },
                        y: {
                            grid: { display: false }
                        }
                    },
                    plugins: {
                        legend: { display: false }
                    }
                }
            });
        }

        // D. Stacked Bar Chart for Company Compliance
        var ctxReportsCompany = document.getElementById('sbmReportsCompanyChart');
        if (ctxReportsCompany) {
            var companiesList = Object.keys(sbmReportsChartData.companyCompliance);
            var activeCounts = [];
            var expiredCounts = [];
            var noneCounts = [];

            companiesList.forEach(function(comp) {
                activeCounts.push(sbmReportsChartData.companyCompliance[comp].active || 0);
                expiredCounts.push(sbmReportsChartData.companyCompliance[comp].expired || 0);
                noneCounts.push(sbmReportsChartData.companyCompliance[comp].none || 0);
            });

            new Chart(ctxReportsCompany, {
                type: 'bar',
                data: {
                    labels: companiesList,
                    datasets: [
                        {
                            label: 'Active/Compliant',
                            data: activeCounts,
                            backgroundColor: '#10b981', // Green
                            borderRadius: 4
                        },
                        {
                            label: 'Expired / Non-Compliant',
                            data: expiredCounts,
                            backgroundColor: '#ef4444', // Red
                            borderRadius: 4
                        },
                        {
                            label: 'Untrained',
                            data: noneCounts,
                            backgroundColor: '#94a3b8', // Slate/Grey
                            borderRadius: 4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            stacked: true,
                            grid: { display: false }
                        },
                        y: {
                            stacked: true,
                            grid: { color: '#f1f5f9' },
                            ticks: { precision: 0 },
                            min: 0
                        }
                    },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { padding: 15 }
                        }
                    }
                }
            });
        }
    }
});

/**
 * Global Search AJAX Handler
 */
jQuery(document).ready(function($) {
    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>"'`=\/]/g, function (s) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;', '/': '&#x2F;', '`': '&#x60;', '=': '&#x3D;' }[s];
        });
    }

    var searchInput = $('#sbm-global-search');
    var resultsDropdown = $('#sbm-search-results');
    var spinner = $('#sbm-search-spinner');
    var searchTimeout = null;

    if (searchInput.length === 0) {
        return;
    }

    searchInput.on('input keyup', function() {
        var query = $(this).val().trim();

        if (searchTimeout) {
            clearTimeout(searchTimeout);
        }

        if (query.length < 3) {
            resultsDropdown.removeClass('active').html('');
            spinner.removeClass('is-active').hide();
            return;
        }

        spinner.addClass('is-active').show();

        searchTimeout = setTimeout(function() {
            try {
                if (typeof sbmAjax === 'undefined') {
                    throw new Error('sbmAjax is undefined');
                }
                
                $.ajax({
                    url: sbmAjax.ajaxUrl,
                    type: 'GET',
                    dataType: 'json',
                    data: {
                        action: 'sbm_global_search',
                        nonce: sbmAjax.searchNonce,
                        q: query
                    },
                    success: function(response) {
                        spinner.removeClass('is-active').hide();
                        if (response.success) {
                            renderResults(response.data, query);
                        } else {
                            resultsDropdown.addClass('active').html('<div class="sbm-search-no-results">Error performing search</div>');
                        }
                    },
                    error: function(xhr, status, error) {
                        spinner.removeClass('is-active').hide();
                        resultsDropdown.addClass('active').html('<div class="sbm-search-no-results">Error performing search: ' + error + '</div>');
                    }
                });
            } catch (e) {
                spinner.removeClass('is-active').hide();
                resultsDropdown.addClass('active').html('<div class="sbm-search-no-results">JS Error: ' + e.message + '</div>');
            }
        }, 400);
    });

    // Close dropdown when clicking outside
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.sbm-search-wrapper').length) {
            resultsDropdown.removeClass('active');
        }
    });

    // Re-open if input is clicked and has query
    searchInput.on('click', function() {
        if ($(this).val().trim().length >= 3 && resultsDropdown.children().length > 0) {
            resultsDropdown.addClass('active');
        }
    });

    function renderResults(data, query) {
        var html = '';
        var hasResults = false;

        // 1. Employees
        if (data.employees) {
            data.employees = $.isArray(data.employees) ? data.employees : $.map(data.employees, function(v) { return v; });
            if (data.employees.length > 0) {
                hasResults = true;
                html += '<div class="sbm-search-group">';
                html += '<div class="sbm-search-group-title">Employees</div>';
                $.each(data.employees, function(index, item) {
                    var secondary = item.email;
                    if (item.iqama) {
                        secondary += ' &middot; IQAMA: ' + item.iqama;
                    }
                    if (item.company) {
                        secondary += ' &middot; ' + item.company;
                    }
                    html += '<a href="' + escapeHtml(item.url) + '" class="sbm-search-result-item">';
                    html += '<span class="dashicons dashicons-admin-users"></span>';
                    html += '<div class="sbm-search-result-text">';
                    html += '<span class="sbm-search-primary">' + escapeHtml(item.name) + '</span>';
                    html += '<span class="sbm-search-secondary">' + secondary + '</span>';
                    html += '</div></a>';
                });
                html += '</div>';
            }
        }

        // 2. Badges
        if (data.badges) {
            data.badges = $.isArray(data.badges) ? data.badges : $.map(data.badges, function(v) { return v; });
            if (data.badges.length > 0) {
                hasResults = true;
                html += '<div class="sbm-search-group">';
                html += '<div class="sbm-search-group-title">Badges</div>';
                $.each(data.badges, function(index, item) {
                    var secondary = 'Status: ' + item.status.toUpperCase() + ' &middot; Certified: ' + item.pass_date + ' &middot; Expires: ' + item.expiry_date;
                    if (item.user_name) {
                        secondary = escapeHtml(item.user_name) + ' &middot; ' + secondary;
                    }
                    html += '<a href="' + escapeHtml(item.url) + '" class="sbm-search-result-item">';
                    html += '<span class="dashicons dashicons-shield"></span>';
                    html += '<div class="sbm-search-result-text">';
                    html += '<span class="sbm-search-primary">Badge #' + escapeHtml(item.badge_number) + '</span>';
                    html += '<span class="sbm-search-secondary">' + secondary + '</span>';
                    html += '</div></a>';
                });
                html += '</div>';
            }
        }

        // 3. Entries
        if (data.entries) {
            data.entries = $.isArray(data.entries) ? data.entries : $.map(data.entries, function(v) { return v; });
            if (data.entries.length > 0) {
                hasResults = true;
                html += '<div class="sbm-search-group">';
                html += '<div class="sbm-search-group-title">Entries</div>';
                $.each(data.entries, function(index, item) {
                    var secondary = escapeHtml(item.user_name) + ' &middot; ' + item.date + ' &middot; Score: ' + item.score + ' &middot; ' + item.result;
                    html += '<a href="' + escapeHtml(item.url) + '" class="sbm-search-result-item">';
                    html += '<span class="dashicons dashicons-media-text"></span>';
                    html += '<div class="sbm-search-result-text">';
                    html += '<span class="sbm-search-primary">' + escapeHtml(item.form_title) + '</span>';
                    html += '<span class="sbm-search-secondary">' + secondary + '</span>';
                    html += '</div></a>';
                });
                html += '</div>';
            }
        }

        // 4. Forms
        if (data.forms) {
            data.forms = $.isArray(data.forms) ? data.forms : $.map(data.forms, function(v) { return v; });
            if (data.forms.length > 0) {
                hasResults = true;
                html += '<div class="sbm-search-group">';
                html += '<div class="sbm-search-group-title">Forms</div>';
                $.each(data.forms, function(index, item) {
                    var secondary = 'Pass Threshold: ' + item.pass_percent + '% &middot; Validity: ' + item.validity_days + ' days';
                    html += '<a href="' + escapeHtml(item.url) + '" class="sbm-search-result-item">';
                    html += '<span class="dashicons dashicons-welcome-learn-more"></span>';
                    html += '<div class="sbm-search-result-text">';
                    html += '<span class="sbm-search-primary">' + escapeHtml(item.title) + '</span>';
                    html += '<span class="sbm-search-secondary">' + secondary + '</span>';
                    html += '</div></a>';
                });
                html += '</div>';
            }
        }

        if (!hasResults) {
            html = '<div class="sbm-search-no-results">No results found for \'' + escapeHtml(query) + '\'</div>';
        }

        resultsDropdown.html(html).addClass('active');
    }
});
// Individual Training Record Lookup (Dashboard Page)
jQuery(document).ready(function($) {
    // Helper to escape HTML tags
    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>"'`=\/]/g, function (s) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;',
                '/': '&#x2F;',
                '`': '&#x60;',
                '=': '&#x3D;'
            }[s];
        });
    }

    function performTrainingLookup(userId, iqama) {
        var $spinner = $('#sbm_training_lookup_spinner');
        var $resultsDiv = $('#sbm_training_lookup_results');
        var $tbody = $('#sbm_training_lookup_body');
        
        if (!userId && !iqama) {
            $resultsDiv.hide();
            return;
        }

        $spinner.addClass('is-active').css('display', 'inline-block');
        $resultsDiv.hide();

        $.ajax({
            url: sbmAjax.ajaxUrl,
            type: 'GET',
            data: {
                action: 'sbm_employee_training_lookup',
                user_id: userId,
                iqama: iqama,
                nonce: sbmAjax.searchNonce
            },
            success: function(response) {
                $spinner.removeClass('is-active').hide();
                if (response && response.success && response.data) {
                    var data = response.data;
                    var trainings = data.trainings || data;
                    
                    if (data.employee_name) {
                        $('#sbm_lookup_emp_name').text(data.employee_name);
                        $('#sbm_lookup_emp_iqama').text(data.employee_iqama);
                        $('#sbm_training_lookup_info').show();
                    } else {
                        $('#sbm_training_lookup_info').hide();
                    }

                    $tbody.empty();
                    if (trainings.length === 0) {
                        $tbody.append('<tr><td colspan="4" style="text-align: center; padding: 15px;">No training records found for this employee.</td></tr>');
                    } else {
                        trainings = $.isArray(trainings) ? trainings : $.map(trainings, function(v) { return v; });
                        $.each(trainings, function(index, item) {
                            $tbody.append(
                                '<tr>' +
                                '<td>' + escapeHtml(item.date) + '</td>' +
                                '<td>' + escapeHtml(item.title) + '</td>' +
                                '<td>' + escapeHtml(item.score) + '</td>' +
                                '<td>' + item.result + '</td>' +
                                '</tr>'
                            );
                        });
                    }
                    $resultsDiv.show();
                } else {
                    alert('Failed to retrieve training records. ' + (response.data || 'Unknown error.'));
                }
            },
            error: function(xhr, status, error) {
                $spinner.removeClass('is-active').hide();
                alert('Server error: ' + error + '. Please check the console or server logs.');
                console.error(xhr.responseText);
            }
        });
    }

    $('#sbm_training_lookup_select').on('change', function() {
        var userId = $(this).val();
        $('#sbm_training_lookup_iqama').val(''); // Clear Iqama field
        performTrainingLookup(userId, '');
    });

    $('#sbm_training_lookup_iqama_btn').on('click', function() {
        var iqama = $('#sbm_training_lookup_iqama').val().trim();
        $('#sbm_training_lookup_select').val(''); // Clear dropdown
        performTrainingLookup('', iqama);
    });

    $('#sbm_training_lookup_iqama').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            $('#sbm_training_lookup_iqama_btn').click();
        }
    });
});
