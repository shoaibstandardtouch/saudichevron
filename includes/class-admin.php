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
        add_action( 'admin_post_sbm_update_employee_settings', array( $this, 'handle_employee_settings_update' ) );

        // Register Global Settings Search AJAX handler
        add_action( 'wp_ajax_sbm_global_search', array( $this, 'handle_global_search' ) );

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
            'manage_safety_training',
            'safety-training',
            array( $this, 'render_dashboard_page' ),
            'dashicons-shield-alt',
            25
        );

        add_submenu_page(
            'safety-training',
            esc_html__( 'Dashboard', 'safety-badges-manager' ),
            esc_html__( 'Dashboard', 'safety-badges-manager' ),
            'manage_safety_training',
            'safety-training',
            array( $this, 'render_dashboard_page' )
        );

        add_submenu_page(
            'safety-training',
            esc_html__( 'Get Started', 'safety-badges-manager' ),
            esc_html__( 'Get Started', 'safety-badges-manager' ),
            'manage_safety_training',
            'safety-get-started',
            array( $this, 'render_get_started_page' )
        );

        add_submenu_page(
            'safety-training',
            esc_html__( 'Employees', 'safety-badges-manager' ),
            esc_html__( 'Employees', 'safety-badges-manager' ),
            'manage_safety_training',
            'safety-employees',
            array( $this, 'render_employees_page' )
        );

        add_submenu_page(
            'safety-training',
            esc_html__( 'Reports', 'safety-badges-manager' ),
            esc_html__( 'Reports', 'safety-badges-manager' ),
            'manage_safety_training',
            'safety-reports',
            array( $this, 'render_reports_page' )
        );

        add_submenu_page(
            'safety-training',
            esc_html__( 'Settings', 'safety-badges-manager' ),
            esc_html__( 'Settings', 'safety-badges-manager' ),
            'manage_safety_training',
            'safety-settings',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Enqueue styles and scripts for WP Admin pages.
     */
    public function enqueue_admin_assets( $hook ) {
        // Enqueue only on our plugin pages
        if ( strpos( $hook, 'safety-training' ) === false 
            && strpos( $hook, 'safety-employees' ) === false 
            && strpos( $hook, 'safety-reports' ) === false 
            && strpos( $hook, 'safety-settings' ) === false
            && strpos( $hook, 'safety-get-started' ) === false ) {
            return;
        }

        // Register and Enqueue Chart.js from CDN
        wp_enqueue_script( 'chartjs', 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js', array(), '3.9.1', true );

        // Enqueue admin styles
        wp_enqueue_style( 'sbm-admin-css', SBM_URL . 'assets/admin-style.css', array(), SBM_VERSION );
        
        // Enqueue admin JS
        wp_enqueue_script( 'sbm-admin-js', SBM_URL . 'assets/admin-script.js', array( 'jquery', 'chartjs' ), SBM_VERSION, true );

        // Localize AJAX parameters for search
        wp_localize_script( 'sbm-admin-js', 'sbmAjax', array(
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'searchNonce' => wp_create_nonce( 'sbm_global_search_nonce' ),
        ) );
    }

    /**
     * Render the visual report dashboard (Chart.js analytics).
     */
    public function render_dashboard_page() {
        $stats = $this->db->get_dashboard_stats();
        
        $current_month_passes = 0;
        $current_month_fails  = 0;
        if ( ! empty( $stats['trends'] ) ) {
            $current_month_trend = end( $stats['trends'] );
            if ( $current_month_trend ) {
                $current_month_passes = $current_month_trend['passes'];
                $current_month_fails  = $current_month_trend['fails'];
            }
        }
        $recent_certifications = $this->db->get_recent_certifications( 5 );
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
                <div class="sbm-card card-active" style="border-left-color: #059669;">
                    <h3><?php esc_html_e( 'Passed (Current Month)', 'safety-badges-manager' ); ?></h3>
                    <p class="stat-number"><?php echo esc_html( $current_month_passes ); ?></p>
                </div>
                <div class="sbm-card card-expired" style="border-left-color: #dc2626;">
                    <h3><?php esc_html_e( 'Failed (Current Month)', 'safety-badges-manager' ); ?></h3>
                    <p class="stat-number"><?php echo esc_html( $current_month_fails ); ?></p>
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
                    <h3><?php esc_html_e( 'Exam Attempts Trend (6 Months)', 'safety-badges-manager' ); ?></h3>
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

            <!-- Recent Certified Employees -->
            <div class="sbm-card" style="margin-top: 25px; margin-bottom: 25px;">
                <h3 style="margin-top: 0; margin-bottom: 20px; font-size: 16px; font-weight: 600; color: #0f172a; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px;">
                    <?php esc_html_e( 'Recent Certified Employees', 'safety-badges-manager' ); ?>
                </h3>
                <?php if ( empty( $recent_certifications ) ) : ?>
                    <p class="empty-message"><?php esc_html_e( 'No certifications recorded yet.', 'safety-badges-manager' ); ?></p>
                <?php else : ?>
                    <div style="overflow-x: auto;">
                        <table class="wp-list-table widefat fixed striped" style="border: none; box-shadow: none;">
                            <thead>
                                <tr>
                                    <th style="font-weight: 600;"><?php esc_html_e( 'Employee Name', 'safety-badges-manager' ); ?></th>
                                    <th style="font-weight: 600;"><?php esc_html_e( 'Iqaama No.', 'safety-badges-manager' ); ?></th>
                                    <th style="font-weight: 600;"><?php esc_html_e( 'Company', 'safety-badges-manager' ); ?></th>
                                    <th style="font-weight: 600;"><?php esc_html_e( 'Badge Number', 'safety-badges-manager' ); ?></th>
                                    <th style="font-weight: 600;"><?php esc_html_e( 'Certified On', 'safety-badges-manager' ); ?></th>
                                    <th style="font-weight: 600;"><?php esc_html_e( 'Expires On', 'safety-badges-manager' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $recent_certifications as $cert ) : ?>
                                    <tr>
                                        <td><strong><a href="<?php echo esc_url( admin_url( 'admin.php?page=safety-employees&action=view&user_id=' . $cert->user_id ) ); ?>"><?php echo esc_html( SBM()->gravity_forms->heal_user_display_name( $cert->user_id ) ); ?></a></strong></td>
                                        <td><?php echo esc_html( $cert->iqama ); ?></td>
                                        <td><?php echo esc_html( $cert->company ); ?></td>
                                        <td><code><?php echo esc_html( $cert->badge_number ); ?></code></td>
                                        <td><?php echo date_i18n( get_option( 'date_format' ), strtotime( $cert->pass_date ) ); ?></td>
                                        <td><?php echo date_i18n( get_option( 'date_format' ), strtotime( $cert->expiry_date ) ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
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
        $iqama_filter   = isset( $_GET['iqama_filter'] ) ? sanitize_text_field( $_GET['iqama_filter'] ) : '';

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
                            <label for="quiz_filter"><span class="dashicons dashicons-welcome-learn-more"></span> <?php esc_html_e( 'Safety Exam', 'safety-badges-manager' ); ?></label>
                            <select name="quiz_filter" id="quiz_filter">
                                <option value=""><?php esc_html_e( 'All Exams', 'safety-badges-manager' ); ?></option>
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
                        <div class="filter-col">
                            <label for="iqama_filter"><span class="dashicons dashicons-id"></span> <?php esc_html_e( 'Iqaama Number', 'safety-badges-manager' ); ?></label>
                            <input type="text" name="iqama_filter" id="iqama_filter" value="<?php echo esc_attr( $iqama_filter ); ?>" placeholder="<?php esc_attr_e( 'Search by Iqaama...', 'safety-badges-manager' ); ?>" />
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
                            'iqama_filter'   => $iqama_filter,
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
                <input type="hidden" name="iqama_filter" value="<?php echo esc_attr( $iqama_filter ); ?>" />
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
        if ( ! current_user_can( 'manage_safety_training' ) ) {
            return;
        }

        // Get filter inputs
        $company      = isset( $_GET['company'] ) ? sanitize_text_field( $_GET['company'] ) : '';
        $form_id      = isset( $_GET['form_id'] ) ? intval( $_GET['form_id'] ) : 0;
        $start_date   = isset( $_GET['start_date'] ) ? sanitize_text_field( $_GET['start_date'] ) : '';
        $end_date     = isset( $_GET['end_date'] ) ? sanitize_text_field( $_GET['end_date'] ) : '';
        $iqama_filter = isset( $_GET['iqama_filter'] ) ? sanitize_text_field( $_GET['iqama_filter'] ) : '';

        // Fetch reports data
        $entries = $this->db->get_reports_data( array(
            'company'      => $company,
            'form_id'      => $form_id,
            'start_date'   => $start_date,
            'end_date'     => $end_date,
            'iqama_filter' => $iqama_filter,
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
                            <label for="form_id"><span class="dashicons dashicons-welcome-learn-more"></span> <?php esc_html_e( 'Safety Exam', 'safety-badges-manager' ); ?></label>
                            <select name="form_id" id="form_id">
                                <option value=""><?php esc_html_e( 'All Exams', 'safety-badges-manager' ); ?></option>
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
                        <div class="filter-col">
                            <label for="iqama_filter"><span class="dashicons dashicons-id"></span> <?php esc_html_e( 'Iqaama Number', 'safety-badges-manager' ); ?></label>
                            <input type="text" name="iqama_filter" id="iqama_filter" value="<?php echo esc_attr( $iqama_filter ); ?>" placeholder="<?php esc_attr_e( 'Search by Iqaama...', 'safety-badges-manager' ); ?>" />
                        </div>
                    </div>
                    <div class="sbm-filter-actions">
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Apply Filters', 'safety-badges-manager' ); ?></button>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=safety-reports' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Reset Filters', 'safety-badges-manager' ); ?></a>
                        <?php
                        $export_reports_url = add_query_arg( array(
                            'action'       => 'sbm_export_reports',
                            'company'      => $company,
                            'form_id'      => $form_id,
                            'start_date'   => $start_date,
                            'end_date'     => $end_date,
                            'iqama_filter' => $iqama_filter,
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
                    <span class="kpi-subtext"><?php esc_html_e( 'Total exam attempts', 'safety-badges-manager' ); ?></span>
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

            <!-- Detailed Individual Attempts Table -->
            <div class="sbm-card" style="margin-top: 25px;">
                <h3 style="margin-top: 0; margin-bottom: 20px; font-size: 16px; font-weight: 600; color: #0f172a; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px;">
                    <?php esc_html_e( 'Individual Candidate Exam Results', 'safety-badges-manager' ); ?>
                </h3>
                <?php if ( empty( $entries ) ) : ?>
                    <p class="empty-message"><?php esc_html_e( 'No candidate attempts found matching the filters.', 'safety-badges-manager' ); ?></p>
                <?php else : ?>
                    <div style="overflow-x: auto;">
                        <table class="wp-list-table widefat fixed striped" style="border: none; box-shadow: none;">
                            <thead>
                                <tr>
                                    <th style="width: 80px; font-weight: 600;"><?php esc_html_e( 'Entry ID', 'safety-badges-manager' ); ?></th>
                                    <th style="font-weight: 600;"><?php esc_html_e( 'Employee Name', 'safety-badges-manager' ); ?></th>
                                    <th style="font-weight: 600;"><?php esc_html_e( 'Iqaama No.', 'safety-badges-manager' ); ?></th>
                                    <th style="font-weight: 600;"><?php esc_html_e( 'Company', 'safety-badges-manager' ); ?></th>
                                    <th style="font-weight: 600;"><?php esc_html_e( 'Exam Title', 'safety-badges-manager' ); ?></th>
                                    <th style="font-weight: 600;"><?php esc_html_e( 'Submission Date', 'safety-badges-manager' ); ?></th>
                                    <th style="width: 100px; font-weight: 600;"><?php esc_html_e( 'Score', 'safety-badges-manager' ); ?></th>
                                    <th style="width: 100px; font-weight: 600;"><?php esc_html_e( 'Result', 'safety-badges-manager' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                // Paginate entries in PHP for reports list table
                                $per_page     = 20;
                                $total_items  = count( $entries );
                                $total_pages  = ceil( $total_items / $per_page );
                                $current_page = isset( $_GET['paged_rep'] ) ? max( 1, intval( $_GET['paged_rep'] ) ) : 1;
                                $offset       = ( $current_page - 1 ) * $per_page;
                                $paged_entries = array_slice( $entries, $offset, $per_page );

                                foreach ( $paged_entries as $entry ) :
                                    $user = get_userdata( $entry->user_id );
                                    $user_name = SBM()->gravity_forms->heal_user_display_name( $entry->user_id );
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
                                    $score_text = $entry->score_percent !== null ? floatval( $entry->score_percent ) . '%' : '-';
                                    $status_text = $entry->is_pass == '1' ? 'Passed' : 'Failed';
                                    $status_class = $entry->is_pass == '1' ? 'status-passed' : 'status-failed';
                                    ?>
                                    <tr>
                                        <td><?php echo esc_html( $entry->entry_id ); ?></td>
                                        <td><strong><a href="<?php echo esc_url( admin_url( 'admin.php?page=safety-employees&action=view&user_id=' . $entry->user_id ) ); ?>"><?php echo esc_html( $user_name ); ?></a></strong></td>
                                        <td><?php echo esc_html( $iqama ); ?></td>
                                        <td><?php echo esc_html( $entry->company ); ?></td>
                                        <td><?php echo esc_html( $form_title ); ?></td>
                                        <td><?php echo date_i18n( get_option( 'date_format' ) . ' H:i', strtotime( $entry->date_created ) ); ?></td>
                                        <td><?php echo esc_html( $score_text ); ?></td>
                                        <td>
                                            <span class="attempt-result-tag <?php echo esc_attr( $status_class ); ?>" style="display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; text-transform: uppercase;">
                                                <?php echo esc_html( $status_text ); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?php if ( $total_pages > 1 ) : ?>
                        <div class="tablenav" style="margin-top: 15px;">
                            <div class="tablenav-pages">
                                <span class="displaying-num"><?php printf( esc_html__( '%s attempts', 'safety-badges-manager' ), number_format_i18n( $total_items ) ); ?></span>
                                <span class="pagination-links">
                                    <?php
                                    echo paginate_links( array(
                                        'base'      => add_query_arg( 'paged_rep', '%#%' ),
                                        'format'    => '',
                                        'prev_text' => '&laquo;',
                                        'next_text' => '&raquo;',
                                        'total'     => $total_pages,
                                        'current'   => $current_page,
                                    ) );
                                    ?>
                                </span>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Charts Container -->
            <div class="sbm-grid sbm-charts-grid" style="margin-top: 25px;">
                <div class="sbm-card chart-card">
                    <h3><?php esc_html_e( 'Exam Attempts Pass/Fail Ratio', 'safety-badges-manager' ); ?></h3>
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
                    <h3><?php esc_html_e( 'Average Score by Exam', 'safety-badges-manager' ); ?></h3>
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
            $search_criteria = array(
                'status'        => 'active',
                'field_filters' => array(
                    array(
                        'key'   => 'created_by',
                        'value' => $user_id,
                    ),
                ),
            );
            $attempts = GFAPI::get_entries(
                0,
                $search_criteria,
                array( 'key' => 'date_created', 'direction' => 'DESC' )
            );
        }
        ?>
        <div class="wrap sbm-employee-profile">
            <?php if ( isset( $_GET['settings_updated'] ) ) : ?>
                <div class="notice notice-success is-dismissible" style="margin-left: 0; margin-right: 0;"><p><?php esc_html_e( 'Employee settings updated successfully.', 'safety-badges-manager' ); ?></p></div>
            <?php endif; ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=safety-employees' ) ); ?>" class="back-link">&larr; <?php esc_html_e( 'Back to Employee Records', 'safety-badges-manager' ); ?></a>
            
            <div class="profile-header-card sbm-card">
                <div class="profile-avatar">
                    <?php echo get_avatar( $user_id, 96 ); ?>
                </div>
                <div class="profile-details">
                    <?php
                    $display_name = SBM()->gravity_forms->heal_user_display_name( $user_id );
                    ?>
                    <h2><?php echo esc_html( $display_name ); ?></h2>
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

            <!-- Employee Settings Card -->
            <div class="sbm-card employee-settings-card" style="margin-bottom: 25px; margin-top: 25px;">
                <h3 style="margin-top: 0; margin-bottom: 15px; font-size: 16px; font-weight: 600; color: #0f172a; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px;">
                    <?php esc_html_e( 'Employee Settings & Permissions', 'safety-badges-manager' ); ?>
                </h3>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'sbm_save_employee_settings_' . $user_id, 'sbm_employee_settings_nonce' ); ?>
                    <input type="hidden" name="action" value="sbm_update_employee_settings" />
                    <input type="hidden" name="user_id" value="<?php echo esc_attr( $user_id ); ?>" />
                    
                    <div style="display: flex; flex-direction: column; gap: 20px;">
                        
                        <!-- Badge Printing -->
                        <div>
                            <label for="sbm_allow_badge_printing" style="font-weight: 600; display: block; margin-bottom: 5px;">
                                <?php esc_html_e( 'Badge Printing Permission', 'safety-badges-manager' ); ?>
                            </label>
                            <?php
                            $allow_printing = get_user_meta( $user_id, 'sbm_allow_badge_printing', true );
                            if ( $allow_printing === '' ) {
                                $allow_printing = 'yes';
                            }
                            ?>
                            <select id="sbm_allow_badge_printing" name="sbm_allow_badge_printing" style="min-width: 150px;">
                                <option value="yes" <?php selected( $allow_printing, 'yes' ); ?>><?php esc_html_e( 'Allowed', 'safety-badges-manager' ); ?></option>
                                <option value="no" <?php selected( $allow_printing, 'no' ); ?>><?php esc_html_e( 'Disallowed', 'safety-badges-manager' ); ?></option>
                            </select>
                            <span class="description" style="margin-left: 10px;">
                                <?php esc_html_e( 'Determine if this employee can print their badge from their portal.', 'safety-badges-manager' ); ?>
                            </span>
                        </div>
                        
                        <!-- Assigned Exams & Allowed Retakes -->
                        <?php
                        $safety_forms = array();
                        if ( class_exists( 'GFAPI' ) ) {
                            $forms = GFAPI::get_forms();
                            foreach ( $forms as $f ) {
                                if ( rgar( $f, 'sbm_enabled' ) ) {
                                    $safety_forms[] = $f;
                                }
                            }
                        }
                        
                        $assigned_exams = get_user_meta( $user_id, 'sbm_assigned_exams', true );
                        if ( ! is_array( $assigned_exams ) ) {
                            $assigned_exams = array();
                        }
                        
                        $allowed_retakes = get_user_meta( $user_id, 'sbm_allowed_retakes', true );
                        if ( ! is_array( $allowed_retakes ) ) {
                            $allowed_retakes = array();
                        }
                        ?>
                        <div style="display: flex; gap: 40px; flex-wrap: wrap;">
                            
                            <!-- Assigned Exams Checklist -->
                            <div style="flex: 1; min-width: 250px;">
                                <label style="font-weight: 600; display: block; margin-bottom: 8px;">
                                    <?php esc_html_e( 'Assigned Exams', 'safety-badges-manager' ); ?>
                                </label>
                                <span class="description" style="display: block; margin-bottom: 10px;">
                                    <?php esc_html_e( 'Check the exams that should appear on this employee\'s dashboard portal. (Leave empty to allow all exams)', 'safety-badges-manager' ); ?>
                                </span>
                                <?php if ( empty( $safety_forms ) ) : ?>
                                    <p style="font-style: italic; color: #64748b;"><?php esc_html_e( 'No safety exams configured yet.', 'safety-badges-manager' ); ?></p>
                                <?php else : ?>
                                    <div style="max-height: 200px; overflow-y: auto; border: 1px solid #cbd5e1; padding: 10px; border-radius: 6px; background: #f8fafc;">
                                        <?php foreach ( $safety_forms as $form ) : ?>
                                            <div style="margin-bottom: 6px;">
                                                <label>
                                                    <input type="checkbox" name="sbm_assigned_exams[]" value="<?php echo esc_attr( $form['id'] ); ?>" <?php checked( in_array( intval( $form['id'] ), $assigned_exams ), true ); ?> />
                                                    <?php echo esc_html( $form['title'] ); ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Allowed Retakes Checklist -->
                            <div style="flex: 1; min-width: 250px;">
                                <label style="font-weight: 600; display: block; margin-bottom: 8px;">
                                    <?php esc_html_e( 'Allow Retakes / Re-attempts', 'safety-badges-manager' ); ?>
                                </label>
                                <span class="description" style="display: block; margin-bottom: 10px;">
                                    <?php esc_html_e( 'Authorize a one-time retake for exams this candidate has already taken.', 'safety-badges-manager' ); ?>
                                </span>
                                <?php if ( empty( $safety_forms ) ) : ?>
                                    <p style="font-style: italic; color: #64748b;"><?php esc_html_e( 'No safety exams configured yet.', 'safety-badges-manager' ); ?></p>
                                <?php else : ?>
                                    <div style="max-height: 200px; overflow-y: auto; border: 1px solid #cbd5e1; padding: 10px; border-radius: 6px; background: #f8fafc;">
                                        <?php foreach ( $safety_forms as $form ) : ?>
                                            <div style="margin-bottom: 6px;">
                                                <label>
                                                    <input type="checkbox" name="sbm_allowed_retakes[]" value="<?php echo esc_attr( $form['id'] ); ?>" <?php checked( in_array( intval( $form['id'] ), $allowed_retakes ), true ); ?> />
                                                    <?php echo esc_html( $form['title'] ); ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                        </div>

                        <div style="margin-top: 10px;">
                            <input type="submit" name="save_employee_settings" class="button button-primary" style="background-color: #0f172a !important; border-color: #0f172a !important;" value="<?php esc_attr_e( 'Save Settings & Permissions', 'safety-badges-manager' ); ?>" />
                        </div>
                    </div>
                </form>
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
                    <h3><?php esc_html_e( 'Exam Attempt History', 'safety-badges-manager' ); ?></h3>
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
     * Render SBM Global Settings page.
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_safety_training' ) ) {
            wp_die( esc_html__( 'Unauthorized user.', 'safety-badges-manager' ) );
        }

        // Process saving of global settings
        if ( isset( $_POST['save_sbm_global_settings'] ) ) {
            check_admin_referer( 'sbm_save_global_settings' );

            $global_printing  = isset( $_POST['sbm_global_allow_printing'] ) ? 'yes' : 'no';
            $page_size        = isset( $_POST['sbm_pdf_page_size'] ) ? sanitize_text_field( $_POST['sbm_pdf_page_size'] ) : 'A4';
            $page_orientation = isset( $_POST['sbm_pdf_page_orientation'] ) ? sanitize_text_field( $_POST['sbm_pdf_page_orientation'] ) : 'portrait';

            update_option( 'sbm_global_allow_printing', $global_printing );
            update_option( 'sbm_pdf_page_size', $page_size );
            update_option( 'sbm_pdf_page_orientation', $page_orientation );

            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Global settings saved successfully.', 'safety-badges-manager' ) . '</p></div>';
        }

        $global_printing  = get_option( 'sbm_global_allow_printing', 'yes' );
        $page_size        = get_option( 'sbm_pdf_page_size', 'A4' );
        $page_orientation = get_option( 'sbm_pdf_page_orientation', 'portrait' );
        ?>
        <div class="wrap sbm-settings-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Safety Badges Settings', 'safety-badges-manager' ); ?></h1>
            <hr class="wp-header-end">

            <!-- Global Search Card -->
            <div class="sbm-card sbm-global-search-card" style="max-width: 800px; margin-top: 20px; margin-bottom: 25px;">
                <h3><?php esc_html_e( 'Global Search', 'safety-badges-manager' ); ?></h3>
                <p class="description"><?php esc_html_e( 'Search across employees, badges, exam entries, and forms. Type at least 3 characters.', 'safety-badges-manager' ); ?></p>
                <div class="sbm-search-wrapper" style="position: relative;">
                    <input type="text" id="sbm-global-search" class="regular-text" style="width: 100%; box-sizing: border-box;" placeholder="<?php esc_attr_e( 'Search by name, email, IQAMA, badge number, form title...', 'safety-badges-manager' ); ?>" autocomplete="off" />
                    <div id="sbm-search-spinner" class="spinner" style="float: none; position: absolute; right: 10px; top: 50%; transform: translateY(-50%); display: none;"></div>
                    <div id="sbm-search-results" class="sbm-search-dropdown"></div>
                </div>
            </div>

            <div class="sbm-card" style="max-width: 800px; margin-top: 20px;">
                <h3 style="border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 20px; font-size: 18px; color: #0f172a;">
                    <?php esc_html_e( 'Global Printing Configuration', 'safety-badges-manager' ); ?>
                </h3>
                
                <form method="post" action="">
                    <?php wp_nonce_field( 'sbm_save_global_settings' ); ?>
                    
                    <table class="form-table">
                        <!-- Global Print Toggle -->
                        <tr valign="top">
                            <th scope="row" style="width: 250px; font-weight: 600;">
                                <label for="sbm_global_allow_printing"><?php esc_html_e( 'Enable Badge Printing Globally', 'safety-badges-manager' ); ?></label>
                            </th>
                            <td>
                                <input type="checkbox" id="sbm_global_allow_printing" name="sbm_global_allow_printing" value="yes" <?php checked( $global_printing, 'yes' ); ?> />
                                <span class="description" style="margin-left: 10px;">
                                    <?php esc_html_e( 'Allow employees to download and print their badges from the employee portal dashboard.', 'safety-badges-manager' ); ?>
                                </span>
                            </td>
                        </tr>

                        <!-- Page Size -->
                        <tr valign="top">
                            <th scope="row" style="font-weight: 600;">
                                <label for="sbm_pdf_page_size"><?php esc_html_e( 'Badge PDF Page Size', 'safety-badges-manager' ); ?></label>
                            </th>
                            <td>
                                <select id="sbm_pdf_page_size" name="sbm_pdf_page_size">
                                    <option value="A4" <?php selected( $page_size, 'A4' ); ?>>A4</option>
                                    <option value="letter" <?php selected( $page_size, 'letter' ); ?>>Letter</option>
                                </select>
                                <p class="description" style="margin-top: 5px;">
                                    <?php esc_html_e( 'Set the default page size for the printed safety badges sheet.', 'safety-badges-manager' ); ?>
                                </p>
                            </td>
                        </tr>

                        <!-- Page Orientation -->
                        <tr valign="top">
                            <th scope="row" style="font-weight: 600;">
                                <label for="sbm_pdf_page_orientation"><?php esc_html_e( 'Badge PDF Page Orientation', 'safety-badges-manager' ); ?></label>
                            </th>
                            <td>
                                <select id="sbm_pdf_page_orientation" name="sbm_pdf_page_orientation">
                                    <option value="portrait" <?php selected( $page_orientation, 'portrait' ); ?>><?php esc_html_e( 'Portrait', 'safety-badges-manager' ); ?></option>
                                    <option value="landscape" <?php selected( $page_orientation, 'landscape' ); ?>><?php esc_html_e( 'Landscape', 'safety-badges-manager' ); ?></option>
                                </select>
                                <p class="description" style="margin-top: 5px;">
                                    <?php esc_html_e( 'Set the page orientation for the safety badges sheet.', 'safety-badges-manager' ); ?>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit" style="margin-top: 30px; padding-top: 15px; border-top: 1px solid #e2e8f0;">
                        <input type="submit" name="save_sbm_global_settings" class="button button-primary" style="background-color: #0f172a !important; border-color: #0f172a !important;" value="<?php esc_html_e( 'Save Settings', 'safety-badges-manager' ); ?>" />
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Handle manual badge status overrides (revoke / activate).
     */
    public function handle_manual_status_update() {
        if ( ! current_user_can( 'manage_safety_training' ) ) {
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
    }

    public function handle_employee_settings_update() {
        if ( ! current_user_can( 'manage_safety_training' ) ) {
            wp_die( esc_html__( 'Unauthorized user.', 'safety-badges-manager' ) );
        }

        $user_id = isset( $_POST['user_id'] ) ? intval( $_POST['user_id'] ) : 0;
        if ( ! $user_id ) {
            wp_die( esc_html__( 'Invalid employee user ID.', 'safety-badges-manager' ) );
        }

        // Nonce check
        check_admin_referer( 'sbm_save_employee_settings_' . $user_id, 'sbm_employee_settings_nonce' );

        $allow_printing = isset( $_POST['sbm_allow_badge_printing'] ) && $_POST['sbm_allow_badge_printing'] === 'no' ? 'no' : 'yes';
        update_user_meta( $user_id, 'sbm_allow_badge_printing', $allow_printing );

        // Save Assigned Exams
        $assigned_exams = isset( $_POST['sbm_assigned_exams'] ) ? array_map( 'intval', $_POST['sbm_assigned_exams'] ) : array();
        update_user_meta( $user_id, 'sbm_assigned_exams', $assigned_exams );

        // Save Allowed Retakes
        $allowed_retakes = isset( $_POST['sbm_allowed_retakes'] ) ? array_map( 'intval', $_POST['sbm_allowed_retakes'] ) : array();
        update_user_meta( $user_id, 'sbm_allowed_retakes', $allowed_retakes );

        // Redirect back with success message
        wp_safe_redirect( admin_url( 'admin.php?page=safety-employees&action=view&user_id=' . $user_id . '&settings_updated=1' ) );
        exit;
    }

    /**
     * Handle custom CSV exports for Employees and Reports.
     */
    public function handle_csv_exports() {
        if ( ! current_user_can( 'manage_safety_training' ) ) {
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
                $iqama_filter   = isset( $_GET['iqama_filter'] ) ? sanitize_text_field( $_GET['iqama_filter'] ) : '';

                // Query all matching employees (offset 0, high limit)
                $items = $this->db->get_employee_records( array(
                    'search'         => $search,
                    'status_filter'  => $status_filter,
                    'company_filter' => $company_filter,
                    'quiz_filter'    => $quiz_filter,
                    'start_date'     => $start_date,
                    'end_date'       => $end_date,
                    'iqama_filter'   => $iqama_filter,
                    'number'         => 10000,
                    'offset'         => 0
                ) );

                $filename = 'employees_export_' . date('Y-m-d') . '.csv';

                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename=' . $filename);
                
                $output = fopen('php://output', 'w');
                
                fputcsv($output, array(
                    'Employee Name',
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

                    $display_name = SBM()->gravity_forms->heal_user_display_name( $item->user_id );

                    fputcsv($output, array(
                        $display_name,
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
                $company      = isset( $_GET['company'] ) ? sanitize_text_field( $_GET['company'] ) : '';
                $form_id      = isset( $_GET['form_id'] ) ? intval( $_GET['form_id'] ) : 0;
                $start_date   = isset( $_GET['start_date'] ) ? sanitize_text_field( $_GET['start_date'] ) : '';
                $end_date     = isset( $_GET['end_date'] ) ? sanitize_text_field( $_GET['end_date'] ) : '';
                $iqama_filter = isset( $_GET['iqama_filter'] ) ? sanitize_text_field( $_GET['iqama_filter'] ) : '';

                $entries = $this->db->get_reports_data( array(
                    'company'      => $company,
                    'form_id'      => $form_id,
                    'start_date'   => $start_date,
                    'end_date'     => $end_date,
                    'iqama_filter' => $iqama_filter,
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
                    'Exam Title',
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
                    $user_name = SBM()->gravity_forms->heal_user_display_name( $entry->user_id );
                    
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

        // Check if user is logged in and does not have compliance manager or admin capabilities
        if ( is_user_logged_in() && ! current_user_can( 'manage_safety_training' ) ) {
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
        if ( ! current_user_can( 'manage_safety_training' ) ) {
            return false;
        }
        return $show;
    }

    /**
     * Render the onboarding/reference page.
     */
    public function render_get_started_page() {
        if ( ! current_user_can( 'manage_safety_training' ) ) {
            wp_die( esc_html__( 'Unauthorized user.', 'safety-badges-manager' ) );
        }

        // Check Gravity Forms active status
        $gf_active = class_exists( 'GFCommon' );
        $quiz_active = class_exists( 'GFQuiz' );

        // Get SBM enabled forms
        $safety_forms = array();
        if ( class_exists( 'GFAPI' ) ) {
            $forms = GFAPI::get_forms();
            foreach ( $forms as $f ) {
                if ( rgar( $f, 'sbm_enabled' ) ) {
                    $safety_forms[] = $f;
                }
            }
        }

        // Get logo data URI if it exists
        $logo_path_png  = SBM_PATH . 'assets/schem-logo.png';
        $logo_path_jpg  = SBM_PATH . 'assets/schem-logo.jpg';
        $logo_path_jpeg = SBM_PATH . 'assets/schem-logo.jpeg';
        $logo_img_src   = '';
        $logo_file = '';
        $logo_mime = 'image/png';
        if ( file_exists( $logo_path_png ) ) {
            $logo_file = $logo_path_png;
            $logo_mime = 'image/png';
        } elseif ( file_exists( $logo_path_jpg ) ) {
            $logo_file = $logo_path_jpg;
            $logo_mime = 'image/jpeg';
        } elseif ( file_exists( $logo_path_jpeg ) ) {
            $logo_file = $logo_path_jpeg;
            $logo_mime = 'image/jpeg';
        }
        if ( ! empty( $logo_file ) ) {
            $logo_data = file_get_contents( $logo_file );
            if ( $logo_data !== false ) {
                $logo_img_src = 'data:' . $logo_mime . ';base64,' . base64_encode( $logo_data );
            }
        }

        ?>
        <div class="wrap sbm-get-started-wrap" style="max-width: 1000px; margin: 20px auto;">
            
            <!-- Page Header -->
            <div class="sbm-card" style="display: flex; align-items: center; gap: 20px; margin-bottom: 25px;">
                <?php if ( ! empty( $logo_img_src ) ) : ?>
                    <img src="<?php echo esc_url( $logo_img_src ); ?>" alt="Branding Logo" style="max-height: 60px; width: auto;" />
                <?php endif; ?>
                <div>
                    <h1 style="margin: 0 0 5px 0; font-size: 24px; font-weight: 700; color: #0f172a;"><?php esc_html_e( 'Welcome to Safety Badges Manager', 'safety-badges-manager' ); ?></h1>
                    <p style="margin: 0; font-size: 15px; color: #64748b;"><?php esc_html_e( 'Follow the steps below to set up and manage your safety training compliance system.', 'safety-badges-manager' ); ?></p>
                </div>
            </div>

            <!-- Step Cards -->
            <div style="display: flex; flex-direction: column; gap: 20px;">
                
                <!-- Step 1 -->
                <div class="sbm-card sbm-step-card" style="display: flex; gap: 20px; align-items: flex-start;">
                    <div class="sbm-step-number" style="display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 50%; background: #0f172a; color: #ffffff; font-weight: 700; flex-shrink: 0; font-size: 16px;">1</div>
                    <div class="sbm-step-content" style="flex-grow: 1;">
                        <h3 class="sbm-step-title" style="margin: 0 0 8px 0; font-size: 18px; font-weight: 600; color: #0f172a;">
                            <?php esc_html_e( 'Install & Activate Gravity Forms', 'safety-badges-manager' ); ?>
                            <?php if ( $gf_active ) : ?>
                                <span class="sbm-step-status" style="color: #10b981; margin-left: 10px; font-size: 16px;">&#10004;</span>
                            <?php else : ?>
                                <span class="sbm-step-status" style="color: #f59e0b; margin-left: 10px; font-size: 16px;">&#9888;</span>
                            <?php endif; ?>
                        </h3>
                        <p class="sbm-step-desc" style="margin: 0 0 15px 0; font-size: 14px; color: #475569; line-height: 1.5;">
                            <?php esc_html_e( 'This plugin requires Gravity Forms with the Quiz add-on. Gravity Forms handles the exam/quiz creation while Safety Badges Manager extends it to issue certificates and track compliance.', 'safety-badges-manager' ); ?>
                        </p>
                        <a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Manage Plugins', 'safety-badges-manager' ); ?></a>
                    </div>
                </div>

                <!-- Step 2 -->
                <div class="sbm-card sbm-step-card" style="display: flex; gap: 20px; align-items: flex-start;">
                    <div class="sbm-step-number" style="display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 50%; background: #0f172a; color: #ffffff; font-weight: 700; flex-shrink: 0; font-size: 16px;">2</div>
                    <div class="sbm-step-content" style="flex-grow: 1;">
                        <h3 class="sbm-step-title" style="margin: 0 0 8px 0; font-size: 18px; font-weight: 600; color: #0f172a;"><?php esc_html_e( 'Create a Safety Exam (Gravity Form)', 'safety-badges-manager' ); ?></h3>
                        <p class="sbm-step-desc" style="margin: 0 0 15px 0; font-size: 14px; color: #475569; line-height: 1.5;">
                            <?php esc_html_e( 'Create a new Gravity Form and add Quiz fields for your safety questions. Each form represents one exam/training module. Use the Quiz add-on field types to build your question bank.', 'safety-badges-manager' ); ?>
                        </p>
                        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=gf_new_form' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Create New Form', 'safety-badges-manager' ); ?></a>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=gf_edit_forms' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'View All Forms', 'safety-badges-manager' ); ?></a>
                        </div>
                    </div>
                </div>

                <!-- Step 3 -->
                <div class="sbm-card sbm-step-card" style="display: flex; gap: 20px; align-items: flex-start;">
                    <div class="sbm-step-number" style="display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 50%; background: #0f172a; color: #ffffff; font-weight: 700; flex-shrink: 0; font-size: 16px;">3</div>
                    <div class="sbm-step-content" style="flex-grow: 1;">
                        <h3 class="sbm-step-title" style="margin: 0 0 8px 0; font-size: 18px; font-weight: 600; color: #0f172a;"><?php esc_html_e( 'Enable Safety Badges on the Form', 'safety-badges-manager' ); ?></h3>
                        <p class="sbm-step-desc" style="margin: 0 0 15px 0; font-size: 14px; color: #475569; line-height: 1.5;">
                            <?php esc_html_e( 'Open the form in the Gravity Forms editor, go to Settings → Safety Badges tab. Enable badge generation, set the pass percentage, validity period (e.g. 365 days), and optionally enable question randomization.', 'safety-badges-manager' ); ?>
                        </p>
                        <div style="margin-bottom: 15px;">
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=gf_edit_forms' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Go to Forms → Settings → Safety Badges', 'safety-badges-manager' ); ?></a>
                        </div>
                        <?php if ( ! empty( $safety_forms ) ) : ?>
                            <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 12px; margin-top: 10px;">
                                <h4 style="margin: 0 0 8px 0; font-size: 13px; font-weight: 600; color: #475569; text-transform: uppercase;"><?php esc_html_e( 'Currently Enabled Forms:', 'safety-badges-manager' ); ?></h4>
                                <ul style="margin: 0; padding-left: 20px; list-style-type: disc;">
                                    <?php foreach ( $safety_forms as $form ) : ?>
                                        <li style="margin-bottom: 5px;">
                                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=gf_edit_forms&view=settings&subview=safety_badges&id=' . $form['id'] ) ); ?>" style="font-weight: 600; text-decoration: none;">
                                                <?php echo esc_html( $form['title'] ); ?>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Step 4 -->
                <div class="sbm-card sbm-step-card" style="display: flex; gap: 20px; align-items: flex-start;">
                    <div class="sbm-step-number" style="display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 50%; background: #0f172a; color: #ffffff; font-weight: 700; flex-shrink: 0; font-size: 16px;">4</div>
                    <div class="sbm-step-content" style="flex-grow: 1;">
                        <h3 class="sbm-step-title" style="margin: 0 0 8px 0; font-size: 18px; font-weight: 600; color: #0f172a;"><?php esc_html_e( 'Configure Global Settings', 'safety-badges-manager' ); ?></h3>
                        <p class="sbm-step-desc" style="margin: 0 0 15px 0; font-size: 14px; color: #475569; line-height: 1.5;">
                            <?php esc_html_e( 'Set up global printing preferences including badge PDF page size (A4/Letter), orientation (Portrait/Landscape), and whether employees can download their own badges from the portal.', 'safety-badges-manager' ); ?>
                        </p>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=safety-settings' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Open Settings', 'safety-badges-manager' ); ?></a>
                    </div>
                </div>

                <!-- Step 5 -->
                <div class="sbm-card sbm-step-card" style="display: flex; gap: 20px; align-items: flex-start;">
                    <div class="sbm-step-number" style="display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 50%; background: #0f172a; color: #ffffff; font-weight: 700; flex-shrink: 0; font-size: 16px;">5</div>
                    <div class="sbm-step-content" style="flex-grow: 1;">
                        <h3 class="sbm-step-title" style="margin: 0 0 8px 0; font-size: 18px; font-weight: 600; color: #0f172a;"><?php esc_html_e( 'Customize Branding (White Label)', 'safety-badges-manager' ); ?></h3>
                        <p class="sbm-step-desc" style="margin: 0 0 15px 0; font-size: 14px; color: #475569; line-height: 1.5;">
                            <?php esc_html_e( 'Upload your company logo and customize the look of your safety badges, PDF certificates, and email notifications. This ensures all printed badges carry your organization\'s branding.', 'safety-badges-manager' ); ?>
                        </p>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=safety-settings' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Open White Label Settings', 'safety-badges-manager' ); ?></a>
                    </div>
                </div>

                <!-- Step 6 -->
                <div class="sbm-card sbm-step-card" style="display: flex; gap: 20px; align-items: flex-start;">
                    <div class="sbm-step-number" style="display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 50%; background: #0f172a; color: #ffffff; font-weight: 700; flex-shrink: 0; font-size: 16px;">6</div>
                    <div class="sbm-step-content" style="flex-grow: 1;">
                        <h3 class="sbm-step-title" style="margin: 0 0 8px 0; font-size: 18px; font-weight: 600; color: #0f172a;"><?php esc_html_e( 'Register Employees / User Accounts', 'safety-badges-manager' ); ?></h3>
                        <p class="sbm-step-desc" style="margin: 0 0 15px 0; font-size: 14px; color: #475569; line-height: 1.5;">
                            <?php esc_html_e( 'Employees need WordPress user accounts to take exams. You can register them via the built-in Gravity Forms User Registration add-on (auto-creates accounts on form submission), or manually create user accounts and assign them the Subscriber role.', 'safety-badges-manager' ); ?>
                        </p>
                        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                            <a href="<?php echo esc_url( admin_url( 'user-new.php' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Add New User', 'safety-badges-manager' ); ?></a>
                            <a href="<?php echo esc_url( admin_url( 'users.php' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'View All Users', 'safety-badges-manager' ); ?></a>
                        </div>
                    </div>
                </div>

                <!-- Step 7 -->
                <div class="sbm-card sbm-step-card" style="display: flex; gap: 20px; align-items: flex-start;">
                    <div class="sbm-step-number" style="display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 50%; background: #0f172a; color: #ffffff; font-weight: 700; flex-shrink: 0; font-size: 16px;">7</div>
                    <div class="sbm-step-content" style="flex-grow: 1;">
                        <h3 class="sbm-step-title" style="margin: 0 0 8px 0; font-size: 18px; font-weight: 600; color: #0f172a;"><?php esc_html_e( 'Employees Take the Exam', 'safety-badges-manager' ); ?></h3>
                        <p class="sbm-step-desc" style="margin: 0 0 15px 0; font-size: 14px; color: #475569; line-height: 1.5;">
                            <?php esc_html_e( 'Share the exam page URL with employees. When a logged-in employee completes the quiz and scores above the pass threshold, a safety badge is automatically generated with a unique badge number and calculated expiry date. Previous active badges for the same exam are automatically superseded.', 'safety-badges-manager' ); ?>
                        </p>
                        <?php if ( ! empty( $safety_forms ) ) : ?>
                            <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 12px; margin-top: 10px;">
                                <h4 style="margin: 0 0 8px 0; font-size: 13px; font-weight: 600; color: #475569; text-transform: uppercase;"><?php esc_html_e( 'Exam Page URLs / Previews:', 'safety-badges-manager' ); ?></h4>
                                <ul style="margin: 0; padding-left: 20px; list-style-type: disc;">
                                    <?php foreach ( $safety_forms as $form ) :
                                        $preview_url = admin_url( 'admin.php?page=gf_entries&view=preview&id=' . $form['id'] );
                                        $front_url   = '';
                                        global $wpdb;
                                        $post_id = $wpdb->get_var( $wpdb->prepare(
                                            "SELECT ID FROM $wpdb->posts WHERE post_status = 'publish' AND post_type = 'page' AND post_content LIKE %s LIMIT 1",
                                            '%' . $wpdb->esc_like( '[gravityform id="' . $form['id'] . '"' ) . '%'
                                        ) );
                                        if ( $post_id ) {
                                            $front_url = get_permalink( $post_id );
                                        } else {
                                            $post_id = $wpdb->get_var( $wpdb->prepare(
                                                "SELECT ID FROM $wpdb->posts WHERE post_status = 'publish' AND post_type = 'page' AND post_content LIKE %s LIMIT 1",
                                                '%"formId":' . intval( $form['id'] ) . '%'
                                            ) );
                                            if ( $post_id ) {
                                                $front_url = get_permalink( $post_id );
                                            }
                                        }
                                        $display_url = ! empty( $front_url ) ? $front_url : $preview_url;
                                        $url_label   = ! empty( $front_url ) ? esc_html__( 'View Published Page', 'safety-badges-manager' ) : esc_html__( 'Preview Exam', 'safety-badges-manager' );
                                        ?>
                                        <li style="margin-bottom: 8px;">
                                            <strong style="color: #0f172a;"><?php echo esc_html( $form['title'] ); ?>:</strong>
                                            <a href="<?php echo esc_url( $display_url ); ?>" target="_blank" style="margin-left: 8px; text-decoration: none; font-size: 13px; font-weight: 600;">
                                                <?php echo esc_html( $url_label ); ?> &rarr;
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Step 8 -->
                <div class="sbm-card sbm-step-card" style="display: flex; gap: 20px; align-items: flex-start;">
                    <div class="sbm-step-number" style="display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 50%; background: #0f172a; color: #ffffff; font-weight: 700; flex-shrink: 0; font-size: 16px;">8</div>
                    <div class="sbm-step-content" style="flex-grow: 1;">
                        <h3 class="sbm-step-title" style="margin: 0 0 8px 0; font-size: 18px; font-weight: 600; color: #0f172a;"><?php esc_html_e( 'Monitor Compliance on the Dashboard', 'safety-badges-manager' ); ?></h3>
                        <p class="sbm-step-desc" style="margin: 0 0 15px 0; font-size: 14px; color: #475569; line-height: 1.5;">
                            <?php esc_html_e( 'The Dashboard shows real-time compliance metrics including active badges, pass/fail trends over 6 months, and a badge expiry forecast. Use this as your command center for safety training oversight.', 'safety-badges-manager' ); ?>
                        </p>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=safety-training' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Open Dashboard', 'safety-badges-manager' ); ?></a>
                    </div>
                </div>

                <!-- Step 9 -->
                <div class="sbm-card sbm-step-card" style="display: flex; gap: 20px; align-items: flex-start;">
                    <div class="sbm-step-number" style="display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 50%; background: #0f172a; color: #ffffff; font-weight: 700; flex-shrink: 0; font-size: 16px;">9</div>
                    <div class="sbm-step-content" style="flex-grow: 1;">
                        <h3 class="sbm-step-title" style="margin: 0 0 8px 0; font-size: 18px; font-weight: 600; color: #0f172a;"><?php esc_html_e( 'Manage Employee Records', 'safety-badges-manager' ); ?></h3>
                        <p class="sbm-step-desc" style="margin: 0 0 15px 0; font-size: 14px; color: #475569; line-height: 1.5;">
                            <?php esc_html_e( 'The Employees page shows every registered employee with their latest badge status. Filter by company, exam, date range, or IQAMA number. Click any employee to see their full profile including badge history timeline and exam attempt history. You can revoke, re-activate badges, assign exams, and authorize retakes.', 'safety-badges-manager' ); ?>
                        </p>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=safety-employees' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'View Employees', 'safety-badges-manager' ); ?></a>
                    </div>
                </div>

                <!-- Step 10 -->
                <div class="sbm-card sbm-step-card" style="display: flex; gap: 20px; align-items: flex-start;">
                    <div class="sbm-step-number" style="display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 50%; background: #0f172a; color: #ffffff; font-weight: 700; flex-shrink: 0; font-size: 16px;">10</div>
                    <div class="sbm-step-content" style="flex-grow: 1;">
                        <h3 class="sbm-step-title" style="margin: 0 0 8px 0; font-size: 18px; font-weight: 600; color: #0f172a;"><?php esc_html_e( 'Generate & Print Badge PDFs', 'safety-badges-manager' ); ?></h3>
                        <p class="sbm-step-desc" style="margin: 0 0 15px 0; font-size: 14px; color: #475569; line-height: 1.5;">
                            <?php esc_html_e( 'Print individual badges from an employee\'s profile, or select multiple employees on the Employees list and use the "Print Selected Badges (PDF)" bulk action. Badges are generated as PDF sheets using Dompdf with your configured page size and branding.', 'safety-badges-manager' ); ?>
                        </p>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=safety-employees' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Go to Employees → Select & Print', 'safety-badges-manager' ); ?></a>
                    </div>
                </div>

                <!-- Step 11 -->
                <div class="sbm-card sbm-step-card" style="display: flex; gap: 20px; align-items: flex-start;">
                    <div class="sbm-step-number" style="display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 50%; background: #0f172a; color: #ffffff; font-weight: 700; flex-shrink: 0; font-size: 16px;">11</div>
                    <div class="sbm-step-content" style="flex-grow: 1;">
                        <h3 class="sbm-step-title" style="margin: 0 0 8px 0; font-size: 18px; font-weight: 600; color: #0f172a;"><?php esc_html_e( 'Run Reports & Export Data', 'safety-badges-manager' ); ?></h3>
                        <p class="sbm-step-desc" style="margin: 0 0 15px 0; font-size: 14px; color: #475569; line-height: 1.5;">
                            <?php esc_html_e( 'The Reports page provides detailed analytics: KPI cards (total attempts, candidates passed/failed, pass rate, average score), monthly trend charts, average score by exam, and company compliance breakdown. Filter by company, exam, date range, or IQAMA. Export filtered data as CSV for external analysis.', 'safety-badges-manager' ); ?>
                        </p>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=safety-reports' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Open Reports', 'safety-badges-manager' ); ?></a>
                    </div>
                </div>

                <!-- Step 12 -->
                <div class="sbm-card sbm-step-card" style="display: flex; gap: 20px; align-items: flex-start;">
                    <div class="sbm-step-number" style="display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 50%; background: #0f172a; color: #ffffff; font-weight: 700; flex-shrink: 0; font-size: 16px;">12</div>
                    <div class="sbm-step-content" style="flex-grow: 1;">
                        <h3 class="sbm-step-title" style="margin: 0 0 8px 0; font-size: 18px; font-weight: 600; color: #0f172a;"><?php esc_html_e( 'Automated Expiry & Notifications', 'safety-badges-manager' ); ?></h3>
                        <p class="sbm-step-desc" style="margin: 0 0 15px 0; font-size: 14px; color: #475569; line-height: 1.5;">
                            <?php esc_html_e( 'The plugin runs automated cron jobs to check badge expiry dates. When a badge is approaching expiration (configurable per-form, default 30 days), reminder emails are automatically sent to the employee. Expired badges are marked with "expired" status. No manual intervention required.', 'safety-badges-manager' ); ?>
                        </p>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=safety-settings' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Review Notification Settings', 'safety-badges-manager' ); ?></a>
                    </div>
                </div>

                <!-- Step 13 -->
                <div class="sbm-card sbm-step-card" style="display: flex; gap: 20px; align-items: flex-start;">
                    <div class="sbm-step-number" style="display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 50%; background: #0f172a; color: #ffffff; font-weight: 700; flex-shrink: 0; font-size: 16px;">13</div>
                    <div class="sbm-step-content" style="flex-grow: 1;">
                        <h3 class="sbm-step-title" style="margin: 0 0 8px 0; font-size: 18px; font-weight: 600; color: #0f172a;"><?php esc_html_e( 'Verify Badges (Public Verification)', 'safety-badges-manager' ); ?></h3>
                        <p class="sbm-step-desc" style="margin: 0 0 15px 0; font-size: 14px; color: #475569; line-height: 1.5;">
                            <?php esc_html_e( 'Each badge has a unique badge number that can be publicly verified. Share the verification URL with third parties (auditors, clients) to confirm an employee\'s certification status, expiry date, and authenticity without needing admin access.', 'safety-badges-manager' ); ?>
                        </p>
                        <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 12px; font-family: monospace; font-size: 13px; color: #475569;">
                            <?php echo esc_html( site_url( '/verify-badge/?code=BADGE_NUMBER' ) ); ?>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Quick Links Shortcut Cards -->
            <div class="sbm-card" style="margin-top: 25px;">
                <h3 style="margin-top: 0; margin-bottom: 20px; font-size: 16px; font-weight: 600; color: #0f172a; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px;"><?php esc_html_e( 'Quick Shortcuts', 'safety-badges-manager' ); ?></h3>
                <div class="sbm-quick-links-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=safety-training' ) ); ?>" class="sbm-search-result-item" style="display: flex; flex-direction: column; align-items: center; text-align: center; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px; text-decoration: none; color: #0f172a; transition: background 0.15s ease;">
                        <span class="dashicons dashicons-dashboard" style="font-size: 32px; width: 32px; height: 32px; color: #64748b; margin-bottom: 10px;"></span>
                        <span style="font-weight: 600; font-size: 15px; margin-bottom: 5px;"><?php esc_html_e( 'Dashboard', 'safety-badges-manager' ); ?></span>
                        <span style="font-size: 12px; color: #64748b;"><?php esc_html_e( 'View real-time compliance metrics', 'safety-badges-manager' ); ?></span>
                    </a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=safety-employees' ) ); ?>" class="sbm-search-result-item" style="display: flex; flex-direction: column; align-items: center; text-align: center; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px; text-decoration: none; color: #0f172a; transition: background 0.15s ease;">
                        <span class="dashicons dashicons-admin-users" style="font-size: 32px; width: 32px; height: 32px; color: #64748b; margin-bottom: 10px;"></span>
                        <span style="font-weight: 600; font-size: 15px; margin-bottom: 5px;"><?php esc_html_e( 'Employees', 'safety-badges-manager' ); ?></span>
                        <span style="font-size: 12px; color: #64748b;"><?php esc_html_e( 'Manage profiles & print certificates', 'safety-badges-manager' ); ?></span>
                    </a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=safety-reports' ) ); ?>" class="sbm-search-result-item" style="display: flex; flex-direction: column; align-items: center; text-align: center; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px; text-decoration: none; color: #0f172a; transition: background 0.15s ease;">
                        <span class="dashicons dashicons-media-text" style="font-size: 32px; width: 32px; height: 32px; color: #64748b; margin-bottom: 10px;"></span>
                        <span style="font-weight: 600; font-size: 15px; margin-bottom: 5px;"><?php esc_html_e( 'Reports', 'safety-badges-manager' ); ?></span>
                        <span style="font-size: 12px; color: #64748b;"><?php esc_html_e( 'Analytical insights & CSV exports', 'safety-badges-manager' ); ?></span>
                    </a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=safety-settings' ) ); ?>" class="sbm-search-result-item" style="display: flex; flex-direction: column; align-items: center; text-align: center; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px; text-decoration: none; color: #0f172a; transition: background 0.15s ease;">
                        <span class="dashicons dashicons-admin-generic" style="font-size: 32px; width: 32px; height: 32px; color: #64748b; margin-bottom: 10px;"></span>
                        <span style="font-weight: 600; font-size: 15px; margin-bottom: 5px;"><?php esc_html_e( 'Settings', 'safety-badges-manager' ); ?></span>
                        <span style="font-size: 12px; color: #64748b;"><?php esc_html_e( 'Configure rules & branding', 'safety-badges-manager' ); ?></span>
                    </a>
                </div>
            </div>

            <!-- Support / Info Footer -->
            <div style="text-align: center; margin-top: 30px; font-size: 13px; color: #64748b;">
                <p>
                    <?php echo sprintf( esc_html__( 'Safety Badges Manager v%s', 'safety-badges-manager' ), SBM_VERSION ); ?>
                    |
                    <a href="https://standardtouch.com" target="_blank" style="text-decoration: none; font-weight: 600; color: #0f172a;"><?php esc_html_e( 'StandardTouch', 'safety-badges-manager' ); ?></a>
                </p>
            </div>

        </div>
        <?php
    }

    /**
     * Handle global search input via AJAX.
     */
    public function handle_global_search() {
        // Verify nonce
        check_ajax_referer( 'sbm_global_search_nonce', 'nonce' );

        // Check capability
        if ( ! current_user_can( 'manage_safety_training' ) ) {
            wp_send_json_error( esc_html__( 'Unauthorized user.', 'safety-badges-manager' ) );
        }

        $query = isset( $_GET['q'] ) ? sanitize_text_field( $_GET['q'] ) : '';
        if ( strlen( $query ) < 3 ) {
            wp_send_json_success( array(
                'employees' => array(),
                'badges'    => array(),
                'entries'   => array(),
                'forms'     => array(),
            ) );
        }

        global $wpdb;
        $like_query = '%' . $wpdb->esc_like( $query ) . '%';

        // 1. Query Employees (Users who are not administrators)
        $employees_results = $wpdb->get_results( $wpdb->prepare( "
            SELECT DISTINCT 
                u.ID as id,
                u.display_name,
                u.user_email as email,
                um_iqama.meta_value as iqama,
                um_company.meta_value as company
            FROM {$wpdb->users} u
            LEFT JOIN {$wpdb->usermeta} um_capabilities ON u.ID = um_capabilities.user_id AND um_capabilities.meta_key = '{$wpdb->prefix}capabilities'
            LEFT JOIN {$wpdb->usermeta} um_iqama ON u.ID = um_iqama.user_id AND um_iqama.meta_key = 'sbm_iqama'
            LEFT JOIN {$wpdb->usermeta} um_company ON u.ID = um_company.user_id AND um_company.meta_key = 'sbm_company'
            WHERE 
                (um_capabilities.meta_value NOT LIKE '%administrator%')
                AND (
                    u.display_name LIKE %s 
                    OR u.user_email LIKE %s 
                    OR u.user_login LIKE %s 
                    OR um_iqama.meta_value LIKE %s
                )
            LIMIT 5
        ", $like_query, $like_query, $like_query, $like_query ) );

        $employees = array();
        foreach ( $employees_results as $emp ) {
            $user_id = intval( $emp->id );
            $name = SBM()->gravity_forms->heal_user_display_name( $user_id );
            $employees[] = array(
                'id'      => $user_id,
                'name'    => ! empty( $name ) ? $name : $emp->display_name,
                'email'   => $emp->email,
                'iqama'   => ! empty( $emp->iqama ) ? $emp->iqama : '',
                'company' => ! empty( $emp->company ) ? $emp->company : 'S-Chem',
                'url'     => admin_url( 'admin.php?page=safety-employees&action=view&user_id=' . $user_id ),
            );
        }

        // 2. Query Badges
        $badges_table = $wpdb->prefix . 'safety_badges';
        $badges_results = $wpdb->get_results( $wpdb->prepare( "
            SELECT 
                b.id,
                b.badge_number,
                b.status,
                b.pass_date,
                b.expiry_date,
                b.user_id
            FROM {$badges_table} b
            WHERE b.badge_number LIKE %s
            LIMIT 5
        ", $like_query ) );

        $badges = array();
        foreach ( $badges_results as $badge ) {
            $user_id = intval( $badge->user_id );
            $user_name = SBM()->gravity_forms->heal_user_display_name( $user_id );
            $badges[] = array(
                'id'           => intval( $badge->id ),
                'badge_number' => $badge->badge_number,
                'status'       => $badge->status,
                'pass_date'    => date( 'Y-m-d', strtotime( $badge->pass_date ) ),
                'expiry_date'  => date( 'Y-m-d', strtotime( $badge->expiry_date ) ),
                'user_name'    => $user_name,
                'url'          => admin_url( 'admin.php?page=safety-employees&action=view&user_id=' . $user_id ),
            );
        }

        // 3. Query Gravity Forms Entries for SBM Enabled Forms
        $entries = array();
        $enabled_form_ids = SBM()->db->get_enabled_form_ids();
        if ( ! empty( $enabled_form_ids ) ) {
            $gf_entry_table = $wpdb->prefix . 'gf_entry';
            $form_placeholders = implode( ',', array_map( 'intval', $enabled_form_ids ) );
            
            $entries_results = $wpdb->get_results( $wpdb->prepare( "
                SELECT DISTINCT 
                    e.id as entry_id,
                    e.form_id,
                    e.date_created,
                    e.created_by as user_id,
                    u.display_name,
                    u.user_email,
                    u.user_login
                FROM {$gf_entry_table} e
                INNER JOIN {$wpdb->users} u ON e.created_by = u.ID
                WHERE e.form_id IN ($form_placeholders)
                AND (
                    u.display_name LIKE %s 
                    OR u.user_email LIKE %s 
                    OR u.user_login LIKE %s
                )
                ORDER BY e.date_created DESC
                LIMIT 5
            ", $like_query, $like_query, $like_query ) );

            foreach ( $entries_results as $ent ) {
                $f_id = intval( $ent->form_id );
                $e_id = intval( $ent->entry_id );
                $u_id = intval( $ent->user_id );
                
                $form_title = 'Form #' . $f_id;
                if ( class_exists( 'GFAPI' ) ) {
                    $form_info = GFAPI::get_form( $f_id );
                    if ( $form_info ) {
                        $form_title = $form_info['title'];
                    }
                }
                
                $score = gform_get_meta( $e_id, 'gquiz_percent' );
                $is_pass = gform_get_meta( $e_id, 'gquiz_is_pass' );
                $user_name = SBM()->gravity_forms->heal_user_display_name( $u_id );

                $entries[] = array(
                    'entry_id'   => $e_id,
                    'form_id'    => $f_id,
                    'form_title' => $form_title,
                    'user_name'  => $user_name,
                    'date'       => date( 'Y-m-d', strtotime( $ent->date_created ) ),
                    'score'      => $score !== '' ? floatval( $score ) . '%' : '-',
                    'result'     => $is_pass == '1' ? 'Passed' : 'Failed',
                    'url'        => admin_url( 'admin.php?page=gf_entries&view=entry&id=' . $f_id . '&lid=' . $e_id ),
                );
            }
        }

        // 4. Query SBM Enabled Forms
        $forms = array();
        if ( class_exists( 'GFAPI' ) ) {
            $all_forms = GFAPI::get_forms();
            $count = 0;
            foreach ( $all_forms as $form ) {
                if ( $count >= 5 ) {
                    break;
                }
                if ( rgar( $form, 'sbm_enabled' ) && stripos( $form['title'], $query ) !== false ) {
                    $forms[] = array(
                        'id'            => intval( $form['id'] ),
                        'title'         => $form['title'],
                        'pass_percent'  => intval( rgar( $form, 'sbm_pass_percent', 80 ) ),
                        'validity_days' => intval( rgar( $form, 'sbm_validity_period', 365 ) ),
                        'url'           => admin_url( 'admin.php?page=gf_edit_forms&view=settings&subview=safety_badges&id=' . $form['id'] ),
                    );
                    $count++;
                }
            }
        }

        wp_send_json_success( array(
            'employees' => $employees,
            'badges'    => $badges,
            'entries'   => $entries,
            'forms'     => $forms,
        ) );
    }

    public function hide_admin_bar_for_subscribers( $show ) {
        if ( ! current_user_can( 'manage_safety_training' ) ) {
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
            'iqama'        => esc_html__( 'Iqaama No.', 'safety-badges-manager' ),
            'badge_status' => esc_html__( 'Compliance Status', 'safety-badges-manager' ),
            'badge_number' => esc_html__( 'Active Badge #', 'safety-badges-manager' ),
            'pass_date'    => esc_html__( 'Certified On', 'safety-badges-manager' ),
            'expiry_date'  => esc_html__( 'Expires On', 'safety-badges-manager' ),
        );
    }

    public function get_sortable_columns() {
        return array(
            'display_name' => array( 'display_name', true ),
            'iqama'        => array( 'iqama', false ),
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

        $display_name = SBM()->gravity_forms->heal_user_display_name( $item->user_id );

        return sprintf(
            '<strong><a class="row-title" href="%s">%s</a></strong> %s',
            esc_url( $view_url ),
            esc_html( $display_name ),
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
            case 'iqama':
                return ! empty( $item->iqama ) ? esc_html( $item->iqama ) : '-';
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
        $iqama_filter   = isset( $_GET['iqama_filter'] ) ? sanitize_text_field( $_GET['iqama_filter'] ) : '';
        
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
        if ( ! empty( $iqama_filter ) ) {
            $filter_args['iqama_filter'] = $iqama_filter;
        }

        // Count totals for badges
        $count_args = array(
            'company_filter' => $company_filter,
            'quiz_filter'    => $quiz_filter,
            'start_date'     => $start_date,
            'end_date'       => $end_date,
            'iqama_filter'   => $iqama_filter,
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
        $iqama_filter   = isset( $_GET['iqama_filter'] ) ? sanitize_text_field( $_GET['iqama_filter'] ) : '';
        $orderby        = isset( $_GET['orderby'] ) ? sanitize_text_field( $_GET['orderby'] ) : 'display_name';
        $order          = isset( $_GET['order'] ) ? sanitize_text_field( $_GET['order'] ) : 'ASC';

        $total_items = $this->db->get_employee_records_count( array(
            'search'         => $search,
            'status_filter'  => $status_filter,
            'company_filter' => $company_filter,
            'quiz_filter'    => $quiz_filter,
            'start_date'     => $start_date,
            'end_date'       => $end_date,
            'iqama_filter'   => $iqama_filter,
        ) );

        $items = $this->db->get_employee_records( array(
            'search'         => $search,
            'status_filter'  => $status_filter,
            'company_filter' => $company_filter,
            'quiz_filter'    => $quiz_filter,
            'start_date'     => $start_date,
            'end_date'       => $end_date,
            'iqama_filter'   => $iqama_filter,
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
