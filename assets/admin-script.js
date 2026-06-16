/**
 * Safety Badges Manager Chart.js Initializations
 */
jQuery(document).ready(function($) {
    if (typeof sbmChartData === 'undefined' && typeof sbmReportsChartData === 'undefined') {
        return;
    }

    // 1. Dashboard Charts (Only if sbmChartData is defined)
    if (typeof sbmChartData !== 'undefined') {
        // 1. Compliance Doughnut Chart
        var ctxCompliance = document.getElementById('sbmComplianceChart');
        if (ctxCompliance) {
            new Chart(ctxCompliance, {
                type: 'doughnut',
                data: {
                    labels: ['Active/Compliant', 'Expired', 'Untrained'],
                    datasets: [{
                        data: [
                            sbmChartData.compliance.active,
                            sbmChartData.compliance.expired,
                            sbmChartData.compliance.none
                        ],
                        backgroundColor: ['#10b981', '#ef4444', '#94a3b8'],
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

            sbmChartData.trends.forEach(function(item) {
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

            sbmChartData.expiry_forecast.forEach(function(item) {
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
