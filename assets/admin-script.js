/**
 * Safety Badges Manager Chart.js Initializations
 */
jQuery(document).ready(function($) {
    if (typeof sbmChartData === 'undefined') {
        return;
    }

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
});
