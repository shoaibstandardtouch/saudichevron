<?php
/**
 * WP Admin Interface and Employee Management Class
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Load WP_List_Table if not loaded yet (standard for WP backend list tables)
if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class SBM_Admin {

    /**
     * DB Handler.
     * @var SBM_DB
     */
    private $db;

    /**
     * Constructor.
     */
    public function __construct( $db ) {
        $this->db = $db;
    }

    /**
     * Initialize admin hooks.
     */
    public function init() {
        add_action( 'admin_menu', array( $this, 'register_admin_menus' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        
        // Handle admin actions (e.g. manual status updates)
        add_action( 'admin_post_sbm_update_status', array( $this, 'handle_manual_status_update' ) );

        // Restrict admin dashboard access for non-admin users (subscribers/employees)
        add_action( 'admin_init', array( $this, 'restrict_admin_access' ) );

        // Handle custom CSV exports for employees list and reports
        add_action( 'admin_init', array( $this, 'handle_csv_exports' ) );

        // Hide WordPress admin bar for subscribers/employees on frontend
        add_filter( 'show_admin_bar', array( $this, 'hide_admin_bar_for_subscribers' ) );
    }

    /**
     * Register safety management menu and submenu pages.
     */
    public function register_admin_menus() {
        add_menu_page(
            esc_html__( 'Safety Training', 'safety-badges-manager' ),
            esc_html__( 'Safety Training', 'safety-badges-manager' ),
            'manage_options',
            'safety-training',
            array( $this, 'render_dashboard_page' ),
            'dashicons-shield-alt',
            25
        );

        add_submenu_page(
            'safety-training',
            esc_html__( 'Dashboard', 'safety-badges-manager' ),
            esc_html__( 'Dashboard', 'safety-badges-manager' ),
            'manage_options',
            'safety-training',
            array( $this, 'render_dashboard_page' )
        );

        add_submenu_page(
            'safety-training',
            esc_html__( 'Employees', 'safety-badges-manager' ),
            esc_html__( 'Employees', 'safety-badges-manager' ),
            'manage_options',
            'safety-employees',
            array( $this, 'render_employees_page' )
        );

        add_submenu_page(
            'safety-training',
            esc_html__( 'Reports', 'safety-badges-manager' ),
            esc_html__( 'Reports', 'safety-badges-manager' ),
            'manage_options',
            'safety-reports',
            array( $this, 'render_reports_page' )
        );
    }

    /**
     * Enqueue styles and scripts for WP Admin pages.
     */
    public function enqueue_admin_assets( $hook ) {
        // Enqueue only on our plugin pages
        if ( strpos( $hook, 'safety-training' ) === false && strpos( $hook, 'safety-employees' ) === false && strpos( $hook, 'safety-reports' ) === false ) {
            return;
        }

        // Register and Enqueue Chart.js from CDN
        wp_enqueue_script( 'chartjs', 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js', array(), '3.9.1', true );

        // Enqueue admin styles
        wp_enqueue_style( 'sbm-admin-css', SBM_URL . 'assets/admin-style.css', array(), SBM_VERSION );
        
        // Enqueue admin JS
        wp_enqueue_script( 'sbm-admin-js', SBM_URL . 'assets/admin-script.js', array( 'jquery', 'chartjs' ), SBM_VERSION, true );
    }

    /**
     * Render the visual report dashboard (Chart.js analytics).
     */
    public function render_dashboard_page() {
        $stats = $this->db->get_dashboard_stats();
        ?>
        <div class="wrap sbm-dashboard-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Safety Compliance Dashboard', 'safety-badges-manager' ); ?></h1>
            <hr class="wp-header-end">

            <!-- Stats Cards Grid -->
            <div class="sbm-grid sbm-stats-cards">
                <div class="sbm-card card-active">
                    <h3><?php esc_html_e( 'Active Badges', 'safety-badges-manager' ); ?></h3>
                    <p class="stat-number"><?php echo esc_html( $stats['compliance']['active'] ); ?></p>
                </div>
                <div class="sbm-card card-expired">
                    <h3><?php esc_html_e( 'Expired Badges', 'safety-badges-manager' ); ?></h3>
                    <p class="stat-number"><?php echo esc_html( $stats['compliance']['expired'] ); ?></p>
                </div>
                <div class="sbm-card card-pending">
                    <h3><?php esc_html_e( 'Untrained Employees', 'safety-badges-manager' ); ?></h3>
                    <p class="stat-number"><?php echo esc_html( $stats['compliance']['none'] ); ?></p>
                </div>
            </div>

            <!-- Charts Grid -->
            <div class="sbm-grid sbm-charts-grid">
                <!-- Doughnut Chart for Compliance -->
                <div class="sbm-card chart-card">
                    <h3><?php esc_html_e( 'Compliance Distribution', 'safety-badges-manager' ); ?></h3>
                    <div class="chart-container">
                        <canvas id="sbmComplianceChart"></canvas>
                    </div>
                </div>

                <!-- Stacked Bar Chart for Pass/Fail Trends -->
                <div class="sbm-card chart-card">
                    <h3><?php esc_html_e( 'Quiz Attempts Trend (6 Months)', 'safety-badges-manager' ); ?></h3>
                    <div class="chart-container">
                        <canvas id="sbmTrendsChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="sbm-grid sbm-charts-grid-full">
                <!-- Line Chart for Forecast Expiries -->
                <div class="sbm-card chart-card">
                    <h3><?php esc_html_e( 'Badge Expiries Forecast (Next 6 Months)', 'safety-badges-manager' ); ?></h3>
                    <div class="chart-container forecast-chart">
                        <canvas id="sbmForecastChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Embed Data securely for Chart.js -->
            <script type="text/javascript">
                var sbmChartData = <?php echo wp_json_encode( $stats ); ?>;
            </script>
        </div>
        <?php
    }

    /**
     * Render the employee lists and detailed single employee profiles.
     */
    public function render_employees_page() {
        // Handle single employee profile view
        if ( isset( $_GET['action'] ) && 'view' === $_GET['action'] && ! empty( $_GET['user_id'] ) ) {
            $this->render_single_employee_view( intval( $_GET['user_id'] ) );
            return;
        }

        // Fetch filter inputs
        $search         = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
        $company_filter = isset( $_GET['company_filter'] ) ? sanitize_text_field( $_GET['company_filter'] ) : '';
        $quiz_filter    = isset( $_GET['quiz_filter'] ) ? intval( $_GET['quiz_filter'] ) : 0;
        $start_date     = isset( $_GET['start_date'] ) ? sanitize_text_field( $_GET['start_date'] ) : '';
        $end_date       = isset( $_GET['end_date'] ) ? sanitize_text_field( $_GET['end_date'] ) : '';
        $status_filter  = isset( $_GET['status_filter'] ) ? sanitize_text_field( $_GET['status_filter'] ) : '';

        // Fetch unique companies for dropdown list
        global $wpdb;
        $companies = $wpdb->get_col( "
            SELECT DISTINCT meta_value 
            FROM {$wpdb->usermeta} 
            WHERE meta_key = 'sbm_company' AND meta_value != ''
        " );
        if ( ! in_array( 'S-Chem', $companies ) ) {
            $companies[] = 'S-Chem';
        }
        sort( $companies );

        // Fetch safety quiz forms
        $safety_forms = array();
        if ( class_exists( 'GFAPI' ) ) {
            $forms = GFAPI::get_forms();
            foreach ( $forms as $f ) {
                if ( rgar( $f, 'sbm_enabled' ) ) {
                    $safety_forms[] = $f;
                }
            }
        }

        // Standard employee records list
        $list_table = new SBM_Employee_List_Table( $this->db );
        $list_table->prepare_items();
        ?>
        <div class="wrap sbm-dashboard-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Employee Records', 'safety-badges-manager' ); ?></h1>
            <hr class="wp-header-end">

            <!-- Filter Card -->
            <div class="sbm-card sbm-filter-card" style="margin-bottom: 20px; margin-top: 20px;">
                <form method="get" action="" class="sbm-reports-filter-form">
                    <input type="hidden" name="page" value="safety-employees" />
                    <?php if ( ! empty( $status_filter ) ) : ?>
                        <input type="hidden" name="status_filter" value="<?php echo esc_attr( $status_filter ); ?>" />
                    <?php endif; ?>
                    <?php if ( ! empty( $search ) ) : ?>
                        <input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>" />
                    <?php endif; ?>
                    
                    <div class="sbm-filter-grid">
                        <div class="filter-col">
                            <label for="company_filter"><span class="dashicons dashicons-store"></span> <?php esc_html_e( 'Contracting Company', 'safety-badges-manager' ); ?></label>
                            <select name="company_filter" id="company_filter">
                                <option value=""><?php esc_html_e( 'All Companies', 'safety-badges-manager' ); ?></option>
                                <?php foreach ( $companies as $comp ) : ?>
                                    <option value="<?php echo esc_attr( $comp ); ?>" <?php selected( $company_filter, $comp ); ?>><?php echo esc_html( $comp ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-col">
                            <label for="quiz_filter"><span class="dashicons dashicons-welcome-learn-more"></span> <?php esc_html_e( 'Safety Quiz', 'safety-badges-manager' ); ?></label>
                            <select name="quiz_filter" id="quiz_filter">
                                <option value=""><?php esc_html_e( 'All Quizzes', 'safety-badges-manager' ); ?></option>
                                <?php foreach ( $safety_forms as $f ) : ?>
                                    <option value="<?php echo esc_attr( $f['id'] ); ?>" <?php selected( $quiz_filter, $f['id'] ); ?>><?php echo esc_html( $f['title'] ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-col">
                            <label for="start_date"><span class="dashicons dashicons-calendar-alt"></span> <?php esc_html_e( 'Certified Start Date', 'safety-badges-manager' ); ?></label>
                            <input type="date" name="start_date" id="start_date" value="<?php echo esc_attr( $start_date ); ?>" />
                        </div>
                        <div class="filter-col">
                            <label for="end_date"><span class="dashicons dashicons-calendar-alt"></span> <?php esc_html_e( 'Certified End Date', 'safety-badges-manager' ); ?></label>
                            <input type="date" name="end_date" id="end_date" value="<?php echo esc_attr( $end_date ); ?>" />
                        </div>
                    </div>
                    <div class="sbm-filter-actions">
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Apply Filters', 'safety-badges-manager' ); ?></button>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=safety-employees' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Reset Filters', 'safety-badges-manager' ); ?></a>
                        <?php
                        $export_url = add_query_arg( array(
                            'action'         => 'sbm_export_employees',
                            's'              => $search,
                            'status_filter'  => $status_filter,
                            'company_filter' => $company_filter,
                            'quiz_filter'    => $quiz_filter,
                            'start_date'     => $start_date,
                            'end_date'       => $end_date,
                        ), admin_url( 'admin.php' ) );
                        ?>
                        <a href="<?php echo esc_url( $export_url ); ?>" class="button button-secondary"><span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Export CSV', 'safety-badges-manager' ); ?></a>
                    </div>
                </form>
            </div>

            <form id="sbm-employee-filter" method="get">
                <input type="hidden" name="page" value="safety-employees" />
                <input type="hidden" name="company_filter" value="<?php echo esc_attr( $company_filter ); ?>" />
                <input type="hidden" name="quiz_filter" value="<?php echo esc_attr( $quiz_filter ); ?>" />
                <input type="hidden" name="start_date" value="<?php echo esc_attr( $start_date ); ?>" />
                <input type="hidden" name="end_date" value="<?php echo esc_attr( $end_date ); ?>" />
                <input type="hidden" name="status_filter" value="<?php echo esc_attr( $status_filter ); ?>" />
                <?php
                // Display search box and filters
                $list_table->search_box( esc_html__( 'Search Employees', 'safety-badges-manager' ), 'sbm-search' );
                ?>
                <div class="sbm-table-responsive">
                    <?php $list_table->display(); ?>
                </div>
            </form>
        </div>
        <?php
    }


    /**
     * Render Safety Training Reports Page with advanced filters and interactive charts.
     */
    public function render_reports_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Get filter inputs
        $company    = isset( $_GET['company'] ) ? sanitize_text_field( $_GET['company'] ) : '';
        $form_id    = isset( $_GET['form_id'] ) ? intval( $_GET['form_id'] ) : 0;
        $start_date = isset( $_GET['start_date'] ) ? sanitize_text_field( $_GET['start_date'] ) : '';
        $end_date   = isset( $_GET['end_date'] ) ? sanitize_text_field( $_GET['end_date'] ) : '';

        // Fetch reports data
        $entries = $this->db->get_reports_data( array(
            'company'    => $company,
            'form_id'    => $form_id,
            'start_date' => $start_date,
            'end_date'   => $end_date
        ) );

        // Fetch unique companies for dropdown list
        global $wpdb;
        $companies = $wpdb->get_col( "
            SELECT DISTINCT meta_value 
            FROM {$wpdb->usermeta} 
            WHERE meta_key = 'sbm_company' AND meta_value != ''
        " );
        if ( ! in_array( 'S-Chem', $companies ) ) {
            $companies[] = 'S-Chem';
        }
        sort( $companies );

        // Fetch safety quiz forms
        $safety_forms = array();
        if ( class_exists( 'GFAPI' ) ) {
            $forms = GFAPI::get_forms();
            foreach ( $forms as $f ) {
                if ( rgar( $f, 'sbm_enabled' ) ) {
                    $safety_forms[] = $f;
                }
            }
        }

        // Calculate KPI Metrics
        $total_attempts = count( $entries );
        $unique_users   = array();
        $passed_users   = array();
        $passed_attempts = 0;
        $total_score    = 0;
        $score_count    = 0;

        foreach ( $entries as $entry ) {
            $u_id = $entry->user_id;
            if ( $u_id ) {
                $unique_users[ $u_id ] = true;
                if ( $entry->is_pass == '1' ) {
                    $passed_users[ $u_id ] = true;
                }
            }
            if ( $entry->is_pass == '1' ) {
                $passed_attempts++;
            }
            if ( $entry->score_percent !== null ) {
                $total_score += floatval( $entry->score_percent );
                $score_count++;
            }
        }

        $candidates_appeared = count( $unique_users );
        $candidates_passed   = count( $passed_users );
        $candidates_failed   = max( 0, $candidates_appeared - $candidates_passed );
        $failed_attempts     = max( 0, $total_attempts - $passed_attempts );

        $pass_rate = $candidates_appeared > 0 ? round( ( $candidates_passed / $candidates_appeared ) * 100, 1 ) : 0;
        $average_score = $score_count > 0 ? round( $total_score / $score_count, 1 ) : 0;

        // Group Monthly Trends
        $monthly_data = array();
        foreach ( $entries as $entry ) {
            $month_key = date( 'Y-m', strtotime( $entry->date_created ) );
            if ( ! isset( $monthly_data[ $month_key ] ) ) {
                $monthly_data[ $month_key ] = array(
                    'label'    => date( 'F Y', strtotime( $entry->date_created ) ),
                    'appeared' => 0,
                    'passed'   => 0
                );
            }
            $monthly_data[ $month_key ]['appeared']++;
            if ( $entry->is_pass == '1' ) {
                $monthly_data[ $month_key ]['passed']++;
            }
        }
        ksort( $monthly_data );
        $trend_labels   = array();
        $trend_appeared = array();
        $trend_passed   = array();
        foreach ( $monthly_data as $m_data ) {
            $trend_labels[]   = $m_data['label'];
            $trend_appeared[] = $m_data['appeared'];
            $trend_passed[]   = $m_data['passed'];
        }

        // Group quiz average scores
        $quiz_scores = array();
        foreach ( $entries as $entry ) {
            $f_id = $entry->form_id;
            if ( ! isset( $quiz_scores[ $f_id ] ) ) {
                $form_title = 'Form #' . $f_id;
                if ( class_exists( 'GFAPI' ) ) {
                    $form_info = GFAPI::get_form( $f_id );
                    if ( $form_info ) {
                        $form_title = $form_info['title'];
                    }
                }
                $quiz_scores[ $f_id ] = array(
                    'title'       => $form_title,
                    'total_score' => 0,
                    'count'       => 0
                );
            }
            if ( $entry->score_percent !== null ) {
                $quiz_scores[ $f_id ]['total_score'] += floatval( $entry->score_percent );
                $quiz_scores[ $f_id ]['count']++;
            }
        }

        $quiz_labels   = array();
        $quiz_averages = array();
        foreach ( $quiz_scores as $q_score ) {
            if ( $q_score['count'] > 0 ) {
                $quiz_labels[]   = $q_score['title'];
                $quiz_averages[] = round( $q_score['total_score'] / $q_score['count'], 1 );
            }
        }

        // Get company compliance stats
        $company_compliance = $this->db->get_company_compliance_stats();
        ?>
        <div class="wrap sbm-dashboard-wrap sbm-reports-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'HSE Safety Training Reports', 'safety-badges-manager' ); ?></h1>
            <hr class="wp-header-end">

            <!-- Filter Card -->
            <div class="sbm-card sbm-filter-card">
                <form method="get" action="" class="sbm-reports-filter-form">
                    <input type="hidden" name="page" value="safety-reports" />
                    <div class="sbm-filter-grid">
                        <div class="filter-col">
                            <label for="company"><span class="dashicons dashicons-store"></span> <?php esc_html_e( 'Contracting Company', 'safety-badges-manager' ); ?></label>
                            <select name="company" id="company">
                                <option value=""><?php esc_html_e( 'All Companies', 'safety-badges-manager' ); ?></option>
                                <?php foreach ( $companies as $comp ) : ?>
                                    <option value="<?php echo esc_attr( $comp ); ?>" <?php selected( $company, $comp ); ?>><?php echo esc_html( $comp ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-col">
                            <label for="form_id"><span class="dashicons dashicons-welcome-learn-more"></span> <?php esc_html_e( 'Safety Quiz', 'safety-badges-manager' ); ?></label>
                            <select name="form_id" id="form_id">
                                <option value=""><?php esc_html_e( 'All Quizzes', 'safety-badges-manager' ); ?></option>
                                <?php foreach ( $safety_forms as $f ) : ?>
                                    <option value="<?php echo esc_attr( $f['id'] ); ?>" <?php selected( $form_id, $f['id'] ); ?>><?php echo esc_html( $f['title'] ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-col">
                            <label for="start_date"><span class="dashicons dashicons-calendar-alt"></span> <?php esc_html_e( 'Start Date', 'safety-badges-manager' ); ?></label>
                            <input type="date" name="start_date" id="start_date" value="<?php echo esc_attr( $start_date ); ?>" />
                        </div>
                        <div class="filter-col">
                            <label for="end_date"><span class="dashicons dashicons-calendar-alt"></span> <?php esc_html_e( 'End Date', 'safety-badges-manager' ); ?></label>
                            <input type="date" name="end_date" id="end_date" value="<?php echo esc_attr( $end_date ); ?>" />
                        </div>
                    </div>
                    <div class="sbm-filter-actions">
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Apply Filters', 'safety-badges-manager' ); ?></button>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=safety-reports' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Reset Filters', 'safety-badges-manager' ); ?></a>
                        <?php
                        $export_reports_url = add_query_arg( array(
                            'action'     => 'sbm_export_reports',
                            'company'    => $company,
                            'form_id'    => $form_id,
                            'start_date' => $start_date,
                            'end_date'   => $end_date,
                        ), admin_url( 'admin.php' ) );
                        ?>
                        <a href="<?php echo esc_url( $export_reports_url ); ?>" class="button button-secondary"><span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Export CSV', 'safety-badges-manager' ); ?></a>
                    </div>
                </form>
            </div>

            <!-- Stats KPI Cards -->
            <div class="sbm-grid sbm-stats-cards sbm-reports-kpi" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); margin-top: 25px;">
                <div class="sbm-card card-active">
                    <h3><?php esc_html_e( 'Exams Taken', 'safety-badges-manager' ); ?></h3>
                    <p class="stat-number"><?php echo esc_html( $total_attempts ); ?></p>
                    <span class="kpi-subtext"><?php esc_html_e( 'Total quiz attempts', 'safety-badges-manager' ); ?></span>
                </div>
                <div class="sbm-card card-pending" style="border-left-color: #2563eb;">
                    <h3><?php esc_html_e( 'Students Appeared', 'safety-badges-manager' ); ?></h3>
                    <p class="stat-number"><?php echo esc_html( $candidates_appeared ); ?></p>
                    <span class="kpi-subtext"><?php esc_html_e( 'Unique candidates', 'safety-badges-manager' ); ?></span>
                </div>
                <div class="sbm-card card-active" style="border-left-color: #059669;">
                    <h3><?php esc_html_e( 'Students Passed', 'safety-badges-manager' ); ?></h3>
                    <p class="stat-number"><?php echo esc_html( $candidates_passed ); ?></p>
                    <span class="kpi-subtext"><?php esc_html_e( 'Unique certified', 'safety-badges-manager' ); ?></span>
                </div>
                <div class="sbm-card card-active" style="border-left-color: #8b5cf6;">
                    <h3><?php esc_html_e( 'Passing Rate', 'safety-badges-manager' ); ?></h3>
                    <p class="stat-number"><?php echo esc_html( $pass_rate ); ?>%</p>
                    <span class="kpi-subtext"><?php esc_html_e( 'Certified vs appeared', 'safety-badges-manager' ); ?></span>
                </div>
                <div class="sbm-card card-expired" style="border-left-color: #f59e0b;">
                    <h3><?php esc_html_e( 'Average Score', 'safety-badges-manager' ); ?></h3>
                    <p class="stat-number"><?php echo esc_html( $average_score ); ?>%</p>
                    <span class="kpi-subtext"><?php esc_html_e( 'All graded attempts', 'safety-badges-manager' ); ?></span>
                </div>
            </div>

            <!-- Charts Container -->
            <div class="sbm-grid sbm-charts-grid" style="margin-top: 25px;">
                <div class="sbm-card chart-card">
                    <h3><?php esc_html_e( 'Quiz Attempts Pass/Fail Ratio', 'safety-badges-manager' ); ?></h3>
                    <div class="chart-container">
                        <canvas id="sbmReportsDoughnutChart"></canvas>
                    </div>
                </div>
                <div class="sbm-card chart-card">
                    <h3><?php esc_html_e( 'Attempts & Pass Trends', 'safety-badges-manager' ); ?></h3>
                    <div class="chart-container">
                        <canvas id="sbmReportsTrendsChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="sbm-grid sbm-charts-grid">
                <div class="sbm-card chart-card">
                    <h3><?php esc_html_e( 'Average Score by Quiz', 'safety-badges-manager' ); ?></h3>
                    <div class="chart-container">
                        <canvas id="sbmReportsScoresChart"></canvas>
                    </div>
                </div>
                <div class="sbm-card chart-card">
                    <h3><?php esc_html_e( 'Company Compliance Breakdown', 'safety-badges-manager' ); ?></h3>
                    <div class="chart-container">
                        <canvas id="sbmReportsCompanyChart"></canvas>
                    </div>
                </div>
            </div>

            <script type="text/javascript">
                var sbmReportsChartData = {
                    doughnut: {
                        passed: <?php echo intval( $passed_attempts ); ?>,
                        failed: <?php echo intval( $failed_attempts ); ?>
                    },
                    trends: {
                        labels: <?php echo wp_json_encode( $trend_labels ); ?>,
                        appeared: <?php echo wp_json_encode( $trend_appeared ); ?>,
                        passed: <?php echo wp_json_encode( $trend_passed ); ?>
                    },
                    quizScores: {
                        labels: <?php echo wp_json_encode( $quiz_labels ); ?>,
                        averages: <?php echo wp_json_encode( $quiz_averages ); ?>
                    },
                    companyCompliance: <?php echo wp_json_encode( $company_compliance ); ?>
                };
            </script>
        </div>
        <?php
    }


    /**
     * Render full test history and details for a single employee.
     */
    private function render_single_employee_view( $user_id ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'User not found.', 'safety-badges-manager' ) . '</p></div>';
            return;
        }

        $badges = $this->db->get_badges_by_user( $user_id );
        $active_badge = $this->db->get_active_badge_by_user( $user_id );

        // Fetch meta values
        $iqama   = get_user_meta( $user_id, 'sbm_iqama', true );
        if ( empty( $iqama ) ) {
            $iqama = $user->user_login;
        }
        $company = get_user_meta( $user_id, 'sbm_company', true );

        // Fetch test submissions using Gravity Forms API
        $attempts = array();
        if ( class_exists( 'GFAPI' ) ) {
            $attempts = GFAPI::get_entries(
                0,
                array( 'created_by' => $user_id ),
                array( 'key' => 'date_created', 'direction' => 'DESC' )
            );
        }
        ?>
        <div class="wrap sbm-employee-profile">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=safety-employees' ) ); ?>" class="back-link">&larr; <?php esc_html_e( 'Back to Employee Records', 'safety-badges-manager' ); ?></a>
            
            <div class="profile-header-card sbm-card">
                <div class="profile-avatar">
                    <?php echo get_avatar( $user_id, 96 ); ?>
                </div>
                <div class="profile-details">
                    <h2><?php echo esc_html( $user->display_name ); ?></h2>
                    <p class="profile-email"><strong><?php esc_html_e( 'Email:', 'safety-badges-manager' ); ?></strong> <a href="mailto:<?php echo esc_attr( $user->user_email ); ?>"><?php echo esc_html( $user->user_email ); ?></a></p>
                    <p class="profile-registered"><strong><?php esc_html_e( 'Registered:', 'safety-badges-manager' ); ?></strong> <?php echo date_i18n( get_option( 'date_format' ), strtotime( $user->user_registered ) ); ?></p>
                    
                    <?php if ( ! empty( $iqama ) ) : ?>
                        <p class="profile-iqama"><strong><?php esc_html_e( 'Iqama Number:', 'safety-badges-manager' ); ?></strong> <?php echo esc_html( $iqama ); ?></p>
                    <?php endif; ?>
                    <?php if ( ! empty( $company ) ) : ?>
                        <p class="profile-company"><strong><?php esc_html_e( 'Company:', 'safety-badges-manager' ); ?></strong> <?php echo esc_html( $company ); ?></p>
                    <?php endif; ?>
                    
                    <div class="profile-badge-status-container">
                        <strong><?php esc_html_e( 'Badge Status:', 'safety-badges-manager' ); ?></strong>
                        <?php if ( $active_badge ) : ?>
                            <span class="sbm-badge-tag status-active"><?php esc_html_e( 'Active', 'safety-badges-manager' ); ?></span>
                            <span class="badge-exp-info"><?php printf( esc_html__( 'Expires on %s', 'safety-badges-manager' ), date_i18n( get_option( 'date_format' ), strtotime( $active_badge->expiry_date ) ) ); ?></span>
                        <?php elseif ( ! empty( $badges ) ) : ?>
                            <span class="sbm-badge-tag status-expired"><?php esc_html_e( 'Expired', 'safety-badges-manager' ); ?></span>
                        <?php else : ?>
                            <span class="sbm-badge-tag status-none"><?php esc_html_e( 'Never Certified', 'safety-badges-manager' ); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="profile-sections-grid">
                <!-- Badges History Timeline -->
                <div class="sbm-card timeline-card">
                    <h3><?php esc_html_e( 'Badge History', 'safety-badges-manager' ); ?></h3>
                    <?php if ( empty( $badges ) ) : ?>
                        <p class="empty-message"><?php esc_html_e( 'No safety badges have been generated for this employee yet.', 'safety-badges-manager' ); ?></p>
                    <?php else : ?>
                        <ul class="sbm-timeline">
                            <?php foreach ( $badges as $badge ) : ?>
                                <li class="timeline-item">
                                    <div class="timeline-status-icon status-<?php echo esc_attr( $badge->status ); ?>"></div>
                                    <div class="timeline-content">
                                        <div class="timeline-header">
                                            <span class="badge-num"><?php echo esc_html( $badge->badge_number ); ?></span>
                                            <span class="sbm-badge-tag status-<?php echo esc_attr( $badge->status ); ?>"><?php echo esc_html( ucfirst( $badge->status ) ); ?></span>
                                        </div>
                                        <p class="timeline-dates">
                                            <strong><?php esc_html_e( 'Passed:', 'safety-badges-manager' ); ?></strong> <?php echo date_i18n( get_option( 'date_format' ), strtotime( $badge->pass_date ) ); ?> &nbsp;|&nbsp; 
                                            <strong><?php esc_html_e( 'Expires:', 'safety-badges-manager' ); ?></strong> <?php echo date_i18n( get_option( 'date_format' ), strtotime( $badge->expiry_date ) ); ?>
                                        </p>
                                        
                                        <!-- Actions -->
                                        <div class="timeline-actions">
                                            <a href="<?php echo esc_url( admin_url( 'admin-post.php?action=sbm_print_badges&badges[]=' . $badge->id ) ); ?>" class="button button-small" target="_blank"><?php esc_html_e( 'Print PDF', 'safety-badges-manager' ); ?></a>
                                            
                                            <?php if ( 'active' === $badge->status ) : ?>
                                                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sbm_update_status&badge_id=' . $badge->id . '&status=revoked' ), 'sbm_revoke_' . $badge->id ) ); ?>" class="button button-small button-link-delete" onclick="return confirm('Are you sure you want to revoke this badge?');"><?php esc_html_e( 'Revoke Badge', 'safety-badges-manager' ); ?></a>
                                            <?php elseif ( 'revoked' === $badge->status || 'expired' === $badge->status ) : ?>
                                                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sbm_update_status&badge_id=' . $badge->id . '&status=active' ), 'sbm_activate_' . $badge->id ) ); ?>" class="button button-small"><?php esc_html_e( 'Re-activate', 'safety-badges-manager' ); ?></a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <!-- Test Submissions (Gravity Forms) -->
                <div class="sbm-card attempts-card">
                    <h3><?php esc_html_e( 'Quiz Attempt History', 'safety-badges-manager' ); ?></h3>
                    <?php if ( empty( $attempts ) ) : ?>
                        <p class="empty-message"><?php esc_html_e( 'No test submission data found.', 'safety-badges-manager' ); ?></p>
                    <?php else : ?>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Date', 'safety-badges-manager' ); ?></th>
                                    <th><?php esc_html_e( 'Form / Test', 'safety-badges-manager' ); ?></th>
                                    <th><?php esc_html_e( 'Score', 'safety-badges-manager' ); ?></th>
                                    <th><?php esc_html_e( 'Result', 'safety-badges-manager' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $attempts as $entry ) : 
                                    $form = GFAPI::get_form( $entry['form_id'] );
                                    $gquiz_percent = gform_get_meta( $entry['id'], 'gquiz_percent' );
                                    $gquiz_pass = gform_get_meta( $entry['id'], 'gquiz_is_pass' );
                                    
                                    // Handle formatting
                                    $score_text = $gquiz_percent !== '' ? floatval( $gquiz_percent ) . '%' : '-';
                                    $status_text = $gquiz_pass == '1' ? 'Passed' : 'Failed';
                                    $status_class = $gquiz_pass == '1' ? 'status-passed' : 'status-failed';
                                    ?>
                                    <tr>
                                        <td><?php echo date_i18n( get_option( 'date_format' ) . ' H:i', strtotime( $entry['date_created'] ) ); ?></td>
                                        <td><?php echo esc_html( $form['title'] ); ?></td>
                                        <td><?php echo esc_html( $score_text ); ?></td>
                                        <td><span class="attempt-result-tag <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_text ); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Handle manual badge status overrides (revoke / activate).
     */
    public function handle_manual_status_update() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized user.', 'safety-badges-manager' ) );
        }

        $badge_id = isset( $_GET['badge_id'] ) ? intval( $_GET['badge_id'] ) : 0;
        $status   = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : '';

        if ( ! $badge_id || ! in_array( $status, array( 'active', 'revoked' ) ) ) {
            wp_die( esc_html__( 'Invalid request parameters.', 'safety-badges-manager' ) );
        }

        // Nonce validation
        $nonce_action = 'revoked' === $status ? 'sbm_revoke_' . $badge_id : 'sbm_activate_' . $badge_id;
        check_admin_referer( $nonce_action );

        // Update badge status
        $this->db->update_badge_status( $badge_id, $status );

        // Redirect back to profile page
        $badge = $this->db->get_badge( $badge_id );
        wp_safe_redirect( admin_url( 'admin.php?page=safety-employees&action=view&user_id=' . $badge->user_id ) );
        exit;
    }

    /**
     * Handle custom CSV exports for Employees and Reports.
     */
    public function handle_csv_exports() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( isset( $_GET['action'] ) ) {
            if ( 'sbm_export_employees' === $_GET['action'] ) {
                $search         = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
                $status_filter  = isset( $_GET['status_filter'] ) ? sanitize_text_field( $_GET['status_filter'] ) : '';
                $company_filter = isset( $_GET['company_filter'] ) ? sanitize_text_field( $_GET['company_filter'] ) : '';
                $quiz_filter    = isset( $_GET['quiz_filter'] ) ? intval( $_GET['quiz_filter'] ) : 0;
                $start_date     = isset( $_GET['start_date'] ) ? sanitize_text_field( $_GET['start_date'] ) : '';
                $end_date       = isset( $_GET['end_date'] ) ? sanitize_text_field( $_GET['end_date'] ) : '';

                // Query all matching employees (offset 0, high limit)
                $items = $this->db->get_employee_records( array(
                    'search'         => $search,
                    'status_filter'  => $status_filter,
                    'company_filter' => $company_filter,
                    'quiz_filter'    => $quiz_filter,
                    'start_date'     => $start_date,
                    'end_date'       => $end_date,
                    'number'         => 10000,
                    'offset'         => 0
                ) );

                $filename = 'employees_export_' . date('Y-m-d') . '.csv';

                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename=' . $filename);
                
                $output = fopen('php://output', 'w');
                
                fputcsv($output, array(
                    'Employee Name',
                    'Email Address',
                    'Iqaama No.',
                    'Company',
                    'Compliance Status',
                    'Active Badge #',
                    'Certified On',
                    'Expires On'
                ));

                foreach ( $items as $item ) {
                    $status = $item->badge_status;
                    $status_label = 'Never Certified';
                    if ( 'active' === $status ) {
                        $status_label = 'Active / Compliant';
                    } elseif ( 'expired' === $status ) {
                        $status_label = 'Expired';
                    } elseif ( 'revoked' === $status ) {
                        $status_label = 'Revoked';
                    }

                    $iqama   = get_user_meta( $item->user_id, 'sbm_iqama', true );
                    if ( empty( $iqama ) ) {
                        $user_obj = get_userdata( $item->user_id );
                        $iqama = $user_obj ? $user_obj->user_login : '';
                    }
                    $company = get_user_meta( $item->user_id, 'sbm_company', true );
                    if ( empty( $company ) ) {
                        $company = 'S-Chem';
                    }

                    fputcsv($output, array(
                        $item->display_name,
                        $item->user_email,
                        $iqama,
                        $company,
                        $status_label,
                        ! empty( $item->badge_number ) ? $item->badge_number : '-',
                        ( ! empty( $item->pass_date ) && $item->pass_date !== '0000-00-00 00:00:00' ) ? date('Y-m-d', strtotime( $item->pass_date )) : '-',
                        ( ! empty( $item->expiry_date ) && $item->expiry_date !== '0000-00-00 00:00:00' ) ? date('Y-m-d', strtotime( $item->expiry_date )) : '-'
                    ));
                }

                fclose($output);
                exit;
            } elseif ( 'sbm_export_reports' === $_GET['action'] ) {
                $company    = isset( $_GET['company'] ) ? sanitize_text_field( $_GET['company'] ) : '';
                $form_id    = isset( $_GET['form_id'] ) ? intval( $_GET['form_id'] ) : 0;
                $start_date = isset( $_GET['start_date'] ) ? sanitize_text_field( $_GET['start_date'] ) : '';
                $end_date   = isset( $_GET['end_date'] ) ? sanitize_text_field( $_GET['end_date'] ) : '';

                $entries = $this->db->get_reports_data( array(
                    'company'    => $company,
                    'form_id'    => $form_id,
                    'start_date' => $start_date,
                    'end_date'   => $end_date
                ) );

                $filename = 'safety_reports_export_' . date('Y-m-d') . '.csv';

                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename=' . $filename);
                
                $output = fopen('php://output', 'w');
                
                fputcsv($output, array(
                    'Entry ID',
                    'Employee Name',
                    'Iqaama No.',
                    'Company',
                    'Form ID',
                    'Quiz Title',
                    'Submission Date',
                    'Score (%)',
                    'Result'
                ));

                foreach ( $entries as $entry ) {
                    $user = get_userdata( $entry->user_id );
                    $user_name = $user ? $user->display_name : 'Guest';
                    $iqama = get_user_meta( $entry->user_id, 'sbm_iqama', true );
                    if ( empty( $iqama ) ) {
                        $iqama = $user ? $user->user_login : '';
                    }
                    
                    $form_title = 'Form #' . $entry->form_id;
                    if ( class_exists( 'GFAPI' ) ) {
                        $form_info = GFAPI::get_form( $entry->form_id );
                        if ( $form_info ) {
                            $form_title = $form_info['title'];
                        }
                    }

                    fputcsv($output, array(
                        $entry->entry_id,
                        $user_name,
                        $iqama,
                        $entry->company,
                        $entry->form_id,
                        $form_title,
                        date('Y-m-d H:i:s', strtotime( $entry->date_created )),
                        $entry->score_percent !== null ? floatval( $entry->score_percent ) . '%' : '-',
                        $entry->is_pass == '1' ? 'Passed' : 'Failed'
                    ));
                }

                fclose($output);
                exit;
            }
        }
    }

    /**
     * Restrict wp-admin access for subscribers and redirect them to the homepage.
     */
    public function restrict_admin_access() {
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            return;
        }

        // Check if user is logged in and is not an administrator
        if ( is_user_logged_in() && ! current_user_can( 'manage_options' ) ) {
            // Bypass redirect if this is the print badges request on admin-post.php
            if ( isset( $_GET['action'] ) && 'sbm_print_badges' === $_GET['action'] && strpos( $_SERVER['SCRIPT_NAME'], 'admin-post.php' ) !== false ) {
                return;
            }
            wp_safe_redirect( home_url() );
            exit;
        }
    }

    /**
     * Disable admin bar for non-admins (subscribers/employees).
     */
    public function hide_admin_bar_for_subscribers( $show ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }
        return $show;
    }
}

/**
 * Custom Employee List Table Class (subclasses WP_List_Table)
 */
class SBM_Employee_List_Table extends WP_List_Table {

    private $db;

    public function __construct( $db ) {
        parent::__construct( array(
            'singular' => 'employee',
            'plural'   => 'employees',
            'ajax'     => false
        ) );
        $this->db = $db;
    }

    public function get_columns() {
        return array(
            'cb'           => '<input type="checkbox" />',
            'display_name' => esc_html__( 'Employee Name', 'safety-badges-manager' ),
            'user_email'   => esc_html__( 'Email Address', 'safety-badges-manager' ),
            'badge_status' => esc_html__( 'Compliance Status', 'safety-badges-manager' ),
            'badge_number' => esc_html__( 'Active Badge #', 'safety-badges-manager' ),
            'pass_date'    => esc_html__( 'Certified On', 'safety-badges-manager' ),
            'expiry_date'  => esc_html__( 'Expires On', 'safety-badges-manager' ),
        );
    }

    public function get_sortable_columns() {
        return array(
            'display_name' => array( 'display_name', true ),
            'user_email'   => array( 'user_email', false ),
            'badge_status' => array( 'badge_status', false ),
            'pass_date'    => array( 'pass_date', false ),
            'expiry_date'  => array( 'expiry_date', false ),
        );
    }

    /**
     * Column checkbox for bulk selections.
     */
    public function column_cb( $item ) {
        // We only allow selection if the user actually has a valid printable badge (active/expired/revoked)
        if ( ! empty( $item->badge_id ) ) {
            return sprintf( '<input type="checkbox" name="badges[]" value="%d" />', $item->badge_id );
        }
        return ''; // Return empty checkbox column for users without any generated badge
    }

    /**
     * Column Name with profile actions.
     */
    public function column_display_name( $item ) {
        $view_url = admin_url( 'admin.php?page=safety-employees&action=view&user_id=' . $item->user_id );
        $actions = array(
            'view' => sprintf( '<a href="%s">%s</a>', esc_url( $view_url ), esc_html__( 'View Profile & History', 'safety-badges-manager' ) )
        );

        if ( ! empty( $item->badge_id ) ) {
            $print_url = admin_url( 'admin-post.php?action=sbm_print_badges&badges[]=' . $item->badge_id );
            $actions['print'] = sprintf( '<a href="%s" target="_blank">%s</a>', esc_url( $print_url ), esc_html__( 'Print Badge PDF', 'safety-badges-manager' ) );
        }

        return sprintf(
            '<strong><a class="row-title" href="%s">%s</a></strong> %s',
            esc_url( $view_url ),
            esc_html( $item->display_name ),
            $this->row_actions( $actions )
        );
    }

    /**
     * Display styled status tags.
     */
    public function column_badge_status( $item ) {
        $status = $item->badge_status;
        $label  = esc_html__( 'Never Tested', 'safety-badges-manager' );
        $class  = 'status-none';

        if ( 'active' === $status ) {
            $label = esc_html__( 'Active / Compliant', 'safety-badges-manager' );
            $class = 'status-active';
        } elseif ( 'expired' === $status ) {
            $label = esc_html__( 'Expired', 'safety-badges-manager' );
            $class = 'status-expired';
        } elseif ( 'revoked' === $status ) {
            $label = esc_html__( 'Revoked', 'safety-badges-manager' );
            $class = 'status-revoked';
        }

        return sprintf( '<span class="sbm-badge-tag %s">%s</span>', esc_attr( $class ), esc_html( $label ) );
    }

    /**
     * Default fallback renderer for columns.
     */
    public function column_default( $item, $column_name ) {
        switch ( $column_name ) {
            case 'user_email':
                return esc_html( $item->user_email );
            case 'badge_number':
                return ! empty( $item->badge_number ) ? esc_html( $item->badge_number ) : '-';
            case 'pass_date':
                return ! empty( $item->pass_date ) && $item->pass_date !== '0000-00-00 00:00:00'
                    ? date_i18n( get_option( 'date_format' ), strtotime( $item->pass_date ) )
                    : '-';
            case 'expiry_date':
                return ! empty( $item->expiry_date ) && $item->expiry_date !== '0000-00-00 00:00:00'
                    ? date_i18n( get_option( 'date_format' ), strtotime( $item->expiry_date ) )
                    : '-';
            default:
                return print_r( $item, true );
        }
    }

    /**
     * Set up bulk actions.
     */
    public function get_bulk_actions() {
        return array(
            'print_bulk' => esc_html__( 'Print Selected Badges (PDF)', 'safety-badges-manager' )
        );
    }

    /**
     * Process bulk print action.
     */
    public function process_bulk_action() {
        if ( 'print_bulk' === $this->current_action() ) {
            $badge_ids = isset( $_GET['badges'] ) ? array_map( 'intval', $_GET['badges'] ) : array();
            
            if ( ! empty( $badge_ids ) ) {
                $print_url = add_query_arg(
                    array(
                        'action' => 'sbm_print_badges',
                        'badges' => $badge_ids
                    ),
                    admin_url( 'admin-post.php' )
                );
                
                // Redirect directly to the print generator
                wp_redirect( esc_url_raw( $print_url ) );
                exit;
            }
        }
    }

    /**
     * Set up views filters (e.g. All, Active, Expired, Untrained).
     */
    protected function get_views() {
        $current        = isset( $_GET['status_filter'] ) ? sanitize_text_field( $_GET['status_filter'] ) : '';
        $company_filter = isset( $_GET['company_filter'] ) ? sanitize_text_field( $_GET['company_filter'] ) : '';
        $quiz_filter    = isset( $_GET['quiz_filter'] ) ? intval( $_GET['quiz_filter'] ) : 0;
        $start_date     = isset( $_GET['start_date'] ) ? sanitize_text_field( $_GET['start_date'] ) : '';
        $end_date       = isset( $_GET['end_date'] ) ? sanitize_text_field( $_GET['end_date'] ) : '';
        
        $filter_args = array();
        if ( ! empty( $company_filter ) ) {
            $filter_args['company_filter'] = $company_filter;
        }
        if ( ! empty( $quiz_filter ) ) {
            $filter_args['quiz_filter'] = $quiz_filter;
        }
        if ( ! empty( $start_date ) ) {
            $filter_args['start_date'] = $start_date;
        }
        if ( ! empty( $end_date ) ) {
            $filter_args['end_date'] = $end_date;
        }

        // Count totals for badges
        $count_args = array(
            'company_filter' => $company_filter,
            'quiz_filter'    => $quiz_filter,
            'start_date'     => $start_date,
            'end_date'       => $end_date,
        );

        $active_count  = $this->db->get_employee_records_count( array_merge( $count_args, array( 'status_filter' => 'active' ) ) );
        $expired_count = $this->db->get_employee_records_count( array_merge( $count_args, array( 'status_filter' => 'expired' ) ) );
        $none_count    = $this->db->get_employee_records_count( array_merge( $count_args, array( 'status_filter' => 'none' ) ) );
        $all_count     = $this->db->get_employee_records_count( $count_args );

        $views = array(
            'all' => sprintf(
                '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
                esc_url( add_query_arg( array_merge( $filter_args, array( 'status_filter' => '' ) ), admin_url( 'admin.php?page=safety-employees' ) ) ),
                empty( $current ) ? 'current' : '',
                esc_html__( 'All Employees', 'safety-badges-manager' ),
                $all_count
            ),
            'active' => sprintf(
                '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
                esc_url( add_query_arg( array_merge( $filter_args, array( 'status_filter' => 'active' ) ), admin_url( 'admin.php?page=safety-employees' ) ) ),
                'active' === $current ? 'current' : '',
                esc_html__( 'Active / Compliant', 'safety-badges-manager' ),
                $active_count
            ),
            'expired' => sprintf(
                '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
                esc_url( add_query_arg( array_merge( $filter_args, array( 'status_filter' => 'expired' ) ), admin_url( 'admin.php?page=safety-employees' ) ) ),
                'expired' === $current ? 'current' : '',
                esc_html__( 'Expired', 'safety-badges-manager' ),
                $expired_count
            ),
            'none' => sprintf(
                '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
                esc_url( add_query_arg( array_merge( $filter_args, array( 'status_filter' => 'none' ) ), admin_url( 'admin.php?page=safety-employees' ) ) ),
                'none' === $current ? 'current' : '',
                esc_html__( 'Untrained', 'safety-badges-manager' ),
                $none_count
            ),
        );

        return $views;
    }

    /**
     * Render Company filter dropdown next to bulk actions.
     */


    public function prepare_items() {
        // Execute bulk actions if requested
        $this->process_bulk_action();

        $columns  = $this->get_columns();
        $hidden   = array();
        $sortable = $this->get_sortable_columns();
        
        // Define column headers required by WP_List_Table
        $this->_column_headers = array( $columns, $hidden, $sortable );

        $per_page     = 20;
        $current_page = $this->get_pagenum();
        $offset       = ( $current_page - 1 ) * $per_page;

        $search         = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
        $status_filter  = isset( $_GET['status_filter'] ) ? sanitize_text_field( $_GET['status_filter'] ) : '';
        $company_filter = isset( $_GET['company_filter'] ) ? sanitize_text_field( $_GET['company_filter'] ) : '';
        $quiz_filter    = isset( $_GET['quiz_filter'] ) ? intval( $_GET['quiz_filter'] ) : 0;
        $start_date     = isset( $_GET['start_date'] ) ? sanitize_text_field( $_GET['start_date'] ) : '';
        $end_date       = isset( $_GET['end_date'] ) ? sanitize_text_field( $_GET['end_date'] ) : '';
        $orderby        = isset( $_GET['orderby'] ) ? sanitize_text_field( $_GET['orderby'] ) : 'display_name';
        $order          = isset( $_GET['order'] ) ? sanitize_text_field( $_GET['order'] ) : 'ASC';

        $total_items = $this->db->get_employee_records_count( array(
            'search'         => $search,
            'status_filter'  => $status_filter,
            'company_filter' => $company_filter,
            'quiz_filter'    => $quiz_filter,
            'start_date'     => $start_date,
            'end_date'       => $end_date
        ) );

        $items = $this->db->get_employee_records( array(
            'search'         => $search,
            'status_filter'  => $status_filter,
            'company_filter' => $company_filter,
            'quiz_filter'    => $quiz_filter,
            'start_date'     => $start_date,
            'end_date'       => $end_date,
            'orderby'        => $orderby,
            'order'          => $order,
            'number'         => $per_page,
            'offset'         => $offset
        ) );

        $this->items = $items;

        $this->set_pagination_args( array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total_items / $per_page )
        ) );
    }
}
