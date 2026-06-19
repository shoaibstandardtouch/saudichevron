<?php
/**
 * Gravity Forms and Quiz Integration Class
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SBM_Gravity_Forms {

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
     * Initialize hooks.
     */
    public function init() {
        // Add tab to the sidebar menu
        add_filter( 'gform_form_settings_menu', array( $this, 'add_settings_tab' ) );
        // Render and save page content
        add_action( 'gform_form_settings_page_safety_badges', array( $this, 'render_settings_page' ) );

        // Process entry after submission to evaluate quiz results
        add_action( 'gform_after_submission', array( $this, 'process_submission' ), 10, 2 );

        // Question Randomization hooks
        add_filter( 'gform_pre_render', array( $this, 'randomize_fields' ) );
        add_filter( 'gform_pre_validation', array( $this, 'randomize_fields' ) );
        add_filter( 'gform_pre_submission_filter', array( $this, 'randomize_fields' ) );
        add_filter( 'gform_admin_pre_render', array( $this, 'randomize_fields' ) );

        // Clear shuffled session when quiz is completed
        add_action( 'gform_post_submission', array( $this, 'clear_shuffled_session' ), 10, 2 );

        // Dynamic population filters for pre-filling and conditional visibility
        add_filter( 'gform_field_value_is_logged_in', array( $this, 'populate_is_logged_in' ) );
        add_filter( 'gform_field_value_sbm_name', array( $this, 'populate_sbm_name' ) );
        add_filter( 'gform_field_value_sbm_iqama', array( $this, 'populate_sbm_iqama' ) );
        add_filter( 'gform_field_value_sbm_company', array( $this, 'populate_sbm_company' ) );

        // Filter user data before creating account to automate credentials
        add_filter( 'gform_user_registration_user_data', array( $this, 'customize_user_registration_data' ), 10, 4 );

        // Hook to correct user display name after registration completes
        add_action( 'gform_user_registered', array( $this, 'correct_user_display_name' ), 10, 4 );

        // Filter confirmation to log in and redirect to homepage on successful registration
        add_filter( 'gform_confirmation', array( $this, 'redirect_registration_to_homepage' ), 10, 4 );

        // Force User Registration feed to process synchronously to allow auto-login
        add_filter( 'gform_is_feed_asynchronous', array( $this, 'disable_async_user_registration_feed' ), 10, 3 );

        // Handle custom login post request
        add_action( 'init', array( $this, 'handle_custom_login' ) );

        // Intercept form HTML to render custom login form for guest users on quiz forms
        add_filter( 'gform_get_form_filter', array( $this, 'restrict_quiz_with_login' ), 10, 2 );

        // Frontend script injection for dynamic prefilling as the user types
        add_filter( 'gform_pre_render', array( $this, 'inject_dynamic_email_script' ) );

        // Add custom columns to the Gravity Forms entries list
        add_filter( 'gform_entry_list_columns', array( $this, 'add_entry_list_columns' ), 10, 2 );
        add_filter( 'gform_entries_field_value', array( $this, 'get_custom_column_value' ), 10, 4 );

        // Add custom fields to Gravity Forms export
        add_filter( 'gform_export_fields', array( $this, 'add_export_fields' ), 10, 2 );
        add_filter( 'gform_export_field_value', array( $this, 'get_export_field_value' ), 10, 4 );

        // Frontend Employee Portal Hooks
        add_action( 'template_redirect', array( $this, 'intercept_homepage' ) );
        add_filter( 'logout_redirect', array( $this, 'customize_logout_redirect' ), 10, 3 );
    }

    /**
     * Add a tab to the Gravity Forms form settings sidebar.
     */
    public function add_settings_tab( $menu_items ) {
        $menu_items[] = array(
            'name'  => 'safety_badges',
            'label' => esc_html__( 'Safety Badges', 'safety-badges-manager' )
        );
        return $menu_items;
    }

    /**
     * Render and save Safety Badge settings in Gravity Forms.
     */
    public function render_settings_page() {
        $form_id = rgget( 'id' );
        $form    = GFAPI::get_form( $form_id );

        // Process settings submission
        if ( isset( $_POST['save_sbm_settings'] ) ) {
            check_admin_referer( 'sbm_save_form_settings', 'sbm_settings_nonce' );

            $form['sbm_enabled']           = isset( $_POST['sbm_enabled'] ) ? 1 : 0;
            $form['sbm_pass_percent']      = isset( $_POST['sbm_pass_percent'] ) ? floatval( $_POST['sbm_pass_percent'] ) : 80;
            $form['sbm_validity_period']   = isset( $_POST['sbm_validity_period'] ) ? intval( $_POST['sbm_validity_period'] ) : 365;
            $form['sbm_randomize']         = isset( $_POST['sbm_randomize'] ) ? 1 : 0;
            $form['sbm_notification_days'] = isset( $_POST['sbm_notification_days'] ) ? intval( $_POST['sbm_notification_days'] ) : 30;

            $result = GFAPI::update_form( $form );

            if ( is_wp_error( $result ) ) {
                echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved successfully.', 'safety-badges-manager' ) . '</p></div>';
            }
        }

        // Render header
        GFFormSettings::page_header( esc_html__( 'Safety Badge Settings', 'safety-badges-manager' ) );

        // Load current values
        $enabled           = rgar( $form, 'sbm_enabled', 0 );
        $pass_percent      = rgar( $form, 'sbm_pass_percent', 80 );
        $validity_period   = rgar( $form, 'sbm_validity_period', 365 );
        $randomize         = rgar( $form, 'sbm_randomize', 0 );
        $notification_days = rgar( $form, 'sbm_notification_days', 30 );
        ?>
        <form method="post" style="margin-top: 20px;">
            <?php wp_nonce_field( 'sbm_save_form_settings', 'sbm_settings_nonce' ); ?>
            
            <table class="form-table">
                <!-- Enable Safety Badges -->
                <tr>
                    <th scope="row" style="width: 240px;">
                        <label for="sbm_enabled"><?php esc_html_e( 'Enable Safety Badges', 'safety-badges-manager' ); ?></label>
                    </th>
                    <td>
                        <input type="checkbox" id="sbm_enabled" name="sbm_enabled" value="1" <?php checked( $enabled, 1 ); ?> />
                        <span class="description" style="margin-left: 10px;"><?php esc_html_e( 'Generate safety badges for employees passing this quiz.', 'safety-badges-manager' ); ?></span>
                    </td>
                </tr>

                <!-- Passing Percentage -->
                <tr>
                    <th scope="row">
                        <label for="sbm_pass_percent"><?php esc_html_e( 'Passing Percentage', 'safety-badges-manager' ); ?></label>
                    </th>
                    <td>
                        <input type="number" id="sbm_pass_percent" name="sbm_pass_percent" min="0" max="100" value="<?php echo esc_attr( $pass_percent ); ?>" class="small-text" /> %
                        <br><span class="description"><?php esc_html_e( 'The minimum score percentage required to pass and receive a badge.', 'safety-badges-manager' ); ?></span>
                    </td>
                </tr>

                <!-- Validity Period -->
                <tr>
                    <th scope="row">
                        <label for="sbm_validity_period"><?php esc_html_e( 'Validity Period (Days)', 'safety-badges-manager' ); ?></label>
                    </th>
                    <td>
                        <input type="number" id="sbm_validity_period" name="sbm_validity_period" min="1" value="<?php echo esc_attr( $validity_period ); ?>" class="regular-text" style="width: 100px;" /> Days
                        <br><span class="description"><?php esc_html_e( 'Number of days the safety badge remains valid (e.g., 365 for 1 year).', 'safety-badges-manager' ); ?></span>
                    </td>
                </tr>

                <!-- Randomize Questions -->
                <tr>
                    <th scope="row">
                        <label for="sbm_randomize"><?php esc_html_e( 'Randomize Questions', 'safety-badges-manager' ); ?></label>
                    </th>
                    <td>
                        <input type="checkbox" id="sbm_randomize" name="sbm_randomize" value="1" <?php checked( $randomize, 1 ); ?> />
                        <span class="description" style="margin-left: 10px;"><?php esc_html_e( 'Shuffle quiz questions dynamically for each user session.', 'safety-badges-manager' ); ?></span>
                    </td>
                </tr>

                <!-- Reminder Notifications -->
                <tr>
                    <th scope="row">
                        <label for="sbm_notification_days"><?php esc_html_e( 'Reminder Notification Days', 'safety-badges-manager' ); ?></label>
                    </th>
                    <td>
                        <input type="number" id="sbm_notification_days" name="sbm_notification_days" min="1" value="<?php echo esc_attr( $notification_days ); ?>" class="small-text" /> Days Before Expiry
                        <br><span class="description"><?php esc_html_e( 'Number of days before expiration to send safety reminder emails.', 'safety-badges-manager' ); ?></span>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <input type="submit" name="save_sbm_settings" class="button button-primary" value="<?php esc_html_e( 'Save Settings', 'safety-badges-manager' ); ?>" />
            </p>
        </form>
        <?php
        GFFormSettings::page_footer();
    }

    /**
     * Process Gravity Forms quiz submission.
     */
    public function process_submission( $entry, $form ) {
        // Check if Safety Badges is enabled
        $enabled = rgar( $form, 'sbm_enabled' );
        if ( ! $enabled ) {
            return;
        }

        // Check if entry was created by a logged-in user (employee) or a newly registered user
        $user_id = rgar( $entry, 'created_by' );
        if ( ! $user_id ) {
            $user_id = get_current_user_id();
        }
        if ( ! $user_id ) {
            return; // Only registered users receive badges
        }

        // Ensure user's display name and first name are corrected based on the Name field (excluding Company Name)
        $display_name = $this->get_field_value_by_parameter( $form, $entry, 'sbm_name' );
        if ( empty( $display_name ) ) {
            foreach ( $form['fields'] as $field ) {
                if ( in_array( $field->type, array( 'checkbox', 'radio', 'select', 'multiselect', 'consent', 'quiz' ), true ) ) {
                    continue;
                }
                $lbl = strtolower( $field->label );
                if ( strpos( $lbl, 'name' ) !== false && strpos( $lbl, 'company' ) === false ) {
                    $display_name = rgar( $entry, (string) $field->id );
                    break;
                }
            }
        }
        if ( ! empty( $display_name ) ) {
            $user = get_userdata( $user_id );
            if ( $user && $user->display_name !== $display_name ) {
                wp_update_user( array(
                    'ID'           => $user_id,
                    'display_name' => $display_name,
                    'first_name'   => $display_name,
                ) );
            }
        }

        // Auto-save user metadata if it's missing (fallback security with label search support)
        $iqama = get_user_meta( $user_id, 'sbm_iqama', true );
        if ( ! $iqama ) {
            $submitted_iqama = $this->get_field_value_by_parameter( $form, $entry, 'sbm_iqama' );
            if ( ! $submitted_iqama ) {
                foreach ( $form['fields'] as $field ) {
                    if ( in_array( $field->type, array( 'checkbox', 'radio', 'select', 'multiselect', 'consent', 'quiz' ), true ) ) {
                        continue;
                    }
                    $lbl = strtolower( $field->label );
                    if ( strpos( $lbl, 'iqama' ) !== false || strpos( $lbl, 'iqaama' ) !== false || strpos( $lbl, 'passport' ) !== false ) {
                        $submitted_iqama = rgar( $entry, (string) $field->id );
                        break;
                    }
                }
            }
            if ( $submitted_iqama ) {
                update_user_meta( $user_id, 'sbm_iqama', $submitted_iqama );
            }
        }

        $company = get_user_meta( $user_id, 'sbm_company', true );
        if ( ! $company ) {
            $submitted_company = $this->get_field_value_by_parameter( $form, $entry, 'sbm_company' );
            if ( ! $submitted_company ) {
                foreach ( $form['fields'] as $field ) {
                    if ( in_array( $field->type, array( 'checkbox', 'radio', 'select', 'multiselect', 'consent', 'quiz' ), true ) ) {
                        continue;
                    }
                    $lbl = strtolower( $field->label );
                    if ( strpos( $lbl, 'company' ) !== false ) {
                        $submitted_company = rgar( $entry, (string) $field->id );
                        break;
                    }
                }
            }
            if ( $submitted_company ) {
                update_user_meta( $user_id, 'sbm_company', $submitted_company );
            }
        }

        // Retrieve user quiz percentage score
        $score_percent = $this->get_quiz_percentage( $entry, $form );
        if ( false === $score_percent ) {
            return; // Not a quiz submission, or no quiz fields
        }

        // Compare with configured passing percentage
        $pass_threshold = floatval( rgar( $form, 'sbm_pass_percent', 80 ) );

        if ( $score_percent >= $pass_threshold ) {
            // Save Badge
            $validity_days = intval( rgar( $form, 'sbm_validity_period', 365 ) );
            
            $pass_date   = current_time( 'mysql' );
            $expiry_date = date( 'Y-m-d H:i:s', strtotime( "+$validity_days days", current_time( 'timestamp' ) ) );

            // Generate unique badge serial number: S-CHEM-YYYY-USERID-RAND
            $badge_number = 'S-CHEM-' . date('Y') . '-' . $user_id . '-' . strtoupper( wp_generate_password( 4, false ) );

            $badge_id = $this->db->save_badge( array(
                'user_id'      => $user_id,
                'form_id'      => $form['id'],
                'entry_id'     => $entry['id'],
                'badge_number' => $badge_number,
                'pass_date'    => $pass_date,
                'expiry_date'  => $expiry_date,
            ) );

            if ( $badge_id ) {
                // Trigger action for email/notifications
                do_action( 'sbm_badge_created', $badge_id, $user_id, $badge_number );
            }
        }
    }

    /**
     * Calculate/retrieve quiz percentage score.
     */
    private function get_quiz_percentage( $entry, $form ) {
        // 1. Try to get score from Gravity Forms Quiz Add-on meta data
        $gquiz_percent = gform_get_meta( $entry['id'], 'gquiz_percent' );
        if ( $gquiz_percent !== '' && $gquiz_percent !== false ) {
            return floatval( $gquiz_percent );
        }

        // 2. Fallback: Calculate manually if GF Quiz meta isn't loaded yet
        $total_questions   = 0;
        $correct_answers   = 0;

        foreach ( $form['fields'] as $field ) {
            if ( $field->type === 'quiz' ) {
                $total_questions++;
                $user_response = rgar( $entry, (string) $field->id );
                
                // Gravity Forms Quiz field correct value
                $correct_value = isset( $field->gquizCorrectValue ) ? $field->gquizCorrectValue : '';
                
                if ( ! empty( $correct_value ) && $user_response === $correct_value ) {
                    $correct_answers++;
                }
            }
        }

        if ( $total_questions > 0 ) {
            return ( $correct_answers / $total_questions ) * 100;
        }

        return false;
    }

    /**
     * Dynamic question randomization.
     * Keeps shuffling stable during the session.
     */
    public function randomize_fields( $form ) {
        if ( empty( $form ) || ! isset( $form['id'] ) ) {
            return $form;
        }

        // Check if randomized questions are enabled
        $enabled = rgar( $form, 'sbm_enabled' ) && rgar( $form, 'sbm_randomize' );
        if ( ! $enabled ) {
            return $form;
        }

        // Avoid shuffling in form editor page
        if ( is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
            $current_page = isset( $_SERVER['PHP_SELF'] ) ? basename( $_SERVER['PHP_SELF'] ) : '';
            if ( $current_page === 'admin.php' && isset( $_GET['page'] ) && $_GET['page'] === 'gf_edit_forms' ) {
                return $form;
            }
        }

        // Start session if not started
        if ( ! session_id() && ! headers_sent() ) {
            session_start();
        }

        $session_key = 'sbm_shuffled_fields_' . $form['id'];

        // Separate fields into quiz and other (form headers, submit, pages, etc.)
        $quiz_fields  = array();
        $other_fields = array();

        foreach ( $form['fields'] as $field ) {
            if ( $field->type === 'quiz' ) {
                $quiz_fields[] = $field;
            } else {
                $other_fields[] = $field;
            }
        }

        if ( empty( $quiz_fields ) ) {
            return $form;
        }

        // Restore order from session if exists, otherwise shuffle and save
        if ( isset( $_SESSION[ $session_key ] ) && is_array( $_SESSION[ $session_key ] ) ) {
            $shuffled_ids = $_SESSION[ $session_key ];
            $shuffled_quiz_fields = array();
            
            // Map quiz fields by ID
            $fields_by_id = array();
            foreach ( $quiz_fields as $field ) {
                $fields_by_id[ $field->id ] = $field;
            }

            foreach ( $shuffled_ids as $id ) {
                if ( isset( $fields_by_id[ $id ] ) ) {
                    $shuffled_quiz_fields[] = $fields_by_id[ $id ];
                }
            }

            // Sync check: ensure field counts match
            if ( count( $shuffled_quiz_fields ) !== count( $quiz_fields ) ) {
                $shuffled_quiz_fields = $quiz_fields;
                shuffle( $shuffled_quiz_fields );
                $_SESSION[ $session_key ] = wp_list_pluck( $shuffled_quiz_fields, 'id' );
            }
        } else {
            $shuffled_quiz_fields = $quiz_fields;
            shuffle( $shuffled_quiz_fields );
            $_SESSION[ $session_key ] = wp_list_pluck( $shuffled_quiz_fields, 'id' );
        }

        // Reassemble fields, placing randomized quiz fields in the first quiz field location
        $new_fields = array();
        $inserted   = false;

        foreach ( $form['fields'] as $field ) {
            if ( $field->type === 'quiz' ) {
                if ( ! $inserted ) {
                    $new_fields = array_merge( $new_fields, $shuffled_quiz_fields );
                    $inserted   = true;
                }
            } else {
                $new_fields[] = $field;
            }
        }

        $form['fields'] = $new_fields;
        return $form;
    }

    /**
     * Clear shuffling session after quiz is completed.
     */
    public function clear_shuffled_session( $entry, $form ) {
        if ( ! session_id() && ! headers_sent() ) {
            session_start();
        }

        $session_key = 'sbm_shuffled_fields_' . $form['id'];
        if ( isset( $_SESSION[ $session_key ] ) ) {
            unset( $_SESSION[ $session_key ] );
        }
    }

    /**
     * Populate hidden field to check if user is logged in.
     */
    public function populate_is_logged_in( $value ) {
        return is_user_logged_in() ? 'yes' : 'no';
    }

    /**
     * Pre-fill employee display name.
     */
    public function populate_sbm_name( $value ) {
        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            return $user->display_name;
        }
        return '';
    }

    /**
     * Pre-fill employee Iqama number.
     */
    public function populate_sbm_iqama( $value ) {
        if ( is_user_logged_in() ) {
            $user_id = get_current_user_id();
            $iqama = get_user_meta( $user_id, 'sbm_iqama', true );
            if ( empty( $iqama ) ) {
                $user = wp_get_current_user();
                $iqama = $user->user_login;
            }
            return $iqama;
        }
        return '';
    }

    /**
     * Pre-fill employee Company name.
     */
    public function populate_sbm_company( $value ) {
        if ( is_user_logged_in() ) {
            return get_user_meta( get_current_user_id(), 'sbm_company', true );
        }
        return '';
    }

    /**
     * Dynamically map and automate user credentials based on Iqama number on submission.
     */
    public function customize_user_registration_data( $user_data, $form, $entry, $feed ) {
        // Skip if user is already logged in
        if ( is_user_logged_in() ) {
            return $user_data;
        }

        // Get Iqama/Passport value
        $iqama = $this->get_field_value_by_parameter( $form, $entry, 'sbm_iqama' );
        if ( empty( $iqama ) ) {
            // Search by label fallback
            foreach ( $form['fields'] as $field ) {
                if ( strpos( strtolower( $field->label ), 'iqama' ) !== false || strpos( strtolower( $field->label ), 'passport' ) !== false ) {
                    $iqama = rgar( $entry, (string) $field->id );
                    break;
                }
            }
        }

        if ( ! empty( $iqama ) ) {
            $username = sanitize_user( $iqama, true );
            
            $user_data['user_login'] = $username;
            $user_data['user_pass']  = '111111'; // Set static password
            $user_data['user_email'] = $username . '@gmail.com'; // Set dynamic email
        }

        // Get Name value
        $display_name = $this->get_field_value_by_parameter( $form, $entry, 'sbm_name' );
        if ( empty( $display_name ) ) {
            // Search by label fallback
            foreach ( $form['fields'] as $field ) {
                $lbl = strtolower( $field->label );
                if ( strpos( $lbl, 'name' ) !== false && strpos( $lbl, 'company' ) === false ) {
                    $display_name = rgar( $entry, (string) $field->id );
                    break;
                }
            }
        }

        if ( ! empty( $display_name ) ) {
            $user_data['display_name'] = $display_name;
            $user_data['first_name']   = $display_name;
        }

        return $user_data;
    }

    /**
     * Helper: retrieve field value by its parameter name (inputName).
     */
    private function get_field_value_by_parameter( $form, $entry, $parameter_name ) {
        if ( ! empty( $form['fields'] ) ) {
            foreach ( $form['fields'] as $field ) {
                if ( $field->inputName === $parameter_name ) {
                    return rgar( $entry, (string) $field->id );
                }
            }
        }
        return '';
    }

    /**
     * Heal and recover a user's display name if it is corrupt.
     */
    public function heal_user_display_name( $user_id ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return '';
        }

        $display_name = $user->display_name;
        if ( empty( $display_name ) || in_array( strtolower( trim( $display_name ) ), array( '1', '0', 'true', 'false', 'yes', 'no' ), true ) ) {
            $found_name = '';
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
                $entries = GFAPI::get_entries( 0, $search_criteria );
                if ( ! empty( $entries ) ) {
                    foreach ( $entries as $ent ) {
                        $f = GFAPI::get_form( $ent['form_id'] );
                        if ( $f ) {
                            // 1. Try search by sbm_name input parameter
                            foreach ( $f['fields'] as $field ) {
                                if ( rgar( $field, 'inputName' ) === 'sbm_name' ) {
                                    $val = rgar( $ent, (string) $field->id );
                                    if ( ! empty( $val ) && ! in_array( strtolower( trim( $val ) ), array( '1', '0', 'true', 'false', 'yes', 'no' ), true ) ) {
                                        $found_name = $val;
                                        break 2;
                                    }
                                }
                            }
                            // 2. Try search by label fallback
                            foreach ( $f['fields'] as $field ) {
                                if ( in_array( $field->type, array( 'checkbox', 'radio', 'select', 'multiselect', 'consent', 'quiz' ), true ) ) {
                                    continue;
                                }
                                $lbl = strtolower( $field->label );
                                if ( strpos( $lbl, 'name' ) !== false && strpos( $lbl, 'company' ) === false ) {
                                    $val = rgar( $ent, (string) $field->id );
                                    if ( ! empty( $val ) && ! in_array( strtolower( trim( $val ) ), array( '1', '0', 'true', 'false', 'yes', 'no' ), true ) ) {
                                        $found_name = $val;
                                        break 2;
                                    }
                                }
                            }
                        }
                    }
                }
            }
            if ( ! empty( $found_name ) ) {
                $display_name = $found_name;
                wp_update_user( array(
                    'ID'           => $user_id,
                    'display_name' => $display_name,
                    'first_name'   => $display_name,
                ) );
            } else {
                $iqama = get_user_meta( $user_id, 'sbm_iqama', true );
                $display_name = ! empty( $iqama ) ? $iqama : $user->user_login;
            }
        }

        return $display_name;
    }

    /**
     * Inject frontend script to automatically populate Email and Password in real-time as the user types their Iqaama number.
     */
    public function inject_dynamic_email_script( $form ) {
        if ( is_admin() ) {
            return $form;
        }

        // Find Iqama/Passport, Email, and Password field IDs
        $iqama_field_id    = 0;
        $email_field_id    = 0;
        $password_field_id = 0;

        if ( ! empty( $form['fields'] ) ) {
            foreach ( $form['fields'] as $field ) {
                $label = strtolower( $field->label );
                if ( strpos( $label, 'iqama' ) !== false || strpos( $label, 'iqaama' ) !== false || strpos( $label, 'passport' ) !== false ) {
                    $iqama_field_id = $field->id;
                } elseif ( $field->type === 'email' || strpos( $label, 'email' ) !== false ) {
                    $email_field_id = $field->id;
                } elseif ( $field->type === 'password' || strpos( $label, 'password' ) !== false || strpos( $label, 'pass' ) !== false ) {
                    $password_field_id = $field->id;
                }
            }
        }

        if ( $iqama_field_id ) {
            $form_id = $form['id'];
            $script  = "
            <script type='text/javascript'>
            jQuery(document).ready(function($) {
                var iqamaInput = $('#input_{$form_id}_{$iqama_field_id}');
                var emailInput = $('#input_{$form_id}_{$email_field_id}');
                var passwordInput = $('#input_{$form_id}_{$password_field_id}');
                
                // Pre-fill password automatically with 111111 if empty and change type to text
                if (passwordInput.length) {
                    passwordInput.attr('type', 'text');
                    if (!passwordInput.val()) {
                        passwordInput.val('111111');
                    }
                }

                // Dynamically update email as the user types their Iqaama/Passport number
                if (iqamaInput.length && emailInput.length) {
                    var initVal = iqamaInput.val();
                    if (initVal && !emailInput.val()) {
                        emailInput.val(initVal.trim() + '@gmail.com');
                    }
                    
                    iqamaInput.on('input change keyup', function() {
                        var val = $(this).val();
                        emailInput.val(val ? val.trim() + '@gmail.com' : '');
                    });
                }
            });
            </script>
            ";
            echo $script;
        }

        return $form;
    }

    /**
     * Correct user display name and first name after registration completes.
     */
    public function correct_user_display_name( $user_id, $feed, $entry, $user_data ) {
        $form = GFAPI::get_form( $entry['form_id'] );
        if ( ! $form ) {
            return;
        }

        // Get Name value
        $display_name = $this->get_field_value_by_parameter( $form, $entry, 'sbm_name' );
        if ( empty( $display_name ) ) {
            // Search by label fallback (excluding Company Name)
            foreach ( $form['fields'] as $field ) {
                if ( in_array( $field->type, array( 'checkbox', 'radio', 'select', 'multiselect', 'consent', 'quiz' ), true ) ) {
                    continue;
                }
                $lbl = strtolower( $field->label );
                if ( strpos( $lbl, 'name' ) !== false && strpos( $lbl, 'company' ) === false ) {
                    $display_name = rgar( $entry, (string) $field->id );
                    break;
                }
            }
        }

        if ( ! empty( $display_name ) ) {
            wp_update_user( array(
                'ID'           => $user_id,
                'display_name' => $display_name,
                'first_name'   => $display_name,
            ) );
        }

        // Extract and save Iqama number to user meta during registration
        $iqama = get_user_meta( $user_id, 'sbm_iqama', true );
        if ( empty( $iqama ) ) {
            $submitted_iqama = $this->get_field_value_by_parameter( $form, $entry, 'sbm_iqama' );
            if ( empty( $submitted_iqama ) ) {
                foreach ( $form['fields'] as $field ) {
                    if ( in_array( $field->type, array( 'checkbox', 'radio', 'select', 'multiselect', 'consent', 'quiz' ), true ) ) {
                        continue;
                    }
                    $lbl = strtolower( $field->label );
                    if ( strpos( $lbl, 'iqama' ) !== false || strpos( $lbl, 'iqaama' ) !== false || strpos( $lbl, 'passport' ) !== false ) {
                        $submitted_iqama = rgar( $entry, (string) $field->id );
                        break;
                    }
                }
            }
            if ( ! empty( $submitted_iqama ) ) {
                update_user_meta( $user_id, 'sbm_iqama', $submitted_iqama );
            } else {
                // Fallback to username
                $user = get_userdata( $user_id );
                if ( $user ) {
                    update_user_meta( $user_id, 'sbm_iqama', $user->user_login );
                }
            }
        }

        // Extract and save Company name to user meta during registration
        $company = get_user_meta( $user_id, 'sbm_company', true );
        if ( empty( $company ) ) {
            $submitted_company = $this->get_field_value_by_parameter( $form, $entry, 'sbm_company' );
            if ( empty( $submitted_company ) ) {
                foreach ( $form['fields'] as $field ) {
                    $lbl = strtolower( $field->label );
                    if ( strpos( $lbl, 'company' ) !== false ) {
                        $submitted_company = rgar( $entry, (string) $field->id );
                        break;
                    }
                }
            }
            if ( ! empty( $submitted_company ) ) {
                update_user_meta( $user_id, 'sbm_company', $submitted_company );
            } else {
                update_user_meta( $user_id, 'sbm_company', 'S-Chem' );
            }
        }

        // Programmatically sign in the user immediately upon successful registration
        if ( ! is_user_logged_in() ) {
            wp_clear_auth_cookie();
            wp_set_current_user( $user_id );
            wp_set_auth_cookie( $user_id, true );
        }
    }

    /**
     * Log in and redirect to homepage on successful registration.
     */
    public function redirect_registration_to_homepage( $confirmation, $form, $entry, $ajax ) {
        // Only target Form ID 5 (Registration Form) or forms with User Registration feeds
        $is_reg_form = false;
        if ( $form['id'] == 5 ) {
            $is_reg_form = true;
        } else {
            if ( function_exists( 'gf_user_registration' ) ) {
                $feeds = gf_user_registration()->get_feeds( $form['id'] );
                if ( ! empty( $feeds ) ) {
                    $is_reg_form = true;
                }
            }
        }

        if ( $is_reg_form ) {
            // Redirect directly to the Homepage
            $confirmation = array( 'redirect' => home_url( '/' ) );
        }

        return $confirmation;
    }

    /**
     * Disable asynchronous processing for the User Registration feed to ensure auto-login works.
     */
    public function disable_async_user_registration_feed( $is_asynchronous, $feed, $form ) {
        if ( isset( $feed['addon_slug'] ) && $feed['addon_slug'] === 'gravityformsuserregistration' ) {
            return false;
        }
        return $is_asynchronous;
    }

    /**
     * Handle custom login POST requests.
     */
    public function handle_custom_login() {
        if ( is_user_logged_in() ) {
            return;
        }

        if ( isset( $_POST['sbm_login_nonce'] ) && wp_verify_nonce( $_POST['sbm_login_nonce'], 'sbm_login_action' ) ) {
            $username = sanitize_text_field( $_POST['sbm_username'] );
            $password = $_POST['sbm_password']; // Don't sanitize password to preserve special characters

            if ( empty( $username ) || empty( $password ) ) {
                set_query_var( 'sbm_login_error', esc_html__( 'Please enter both username and password.', 'safety-badges-manager' ) );
                return;
            }

            $creds = array(
                'user_login'    => $username,
                'user_password' => $password,
                'remember'      => true,
            );

            // Log the user in
            $user = wp_signon( $creds, is_ssl() );

            if ( is_wp_error( $user ) ) {
                // Friendly error translation
                $error_msg = $user->get_error_message();
                if ( strpos( $error_msg, 'invalid_username' ) !== false || strpos( $error_msg, 'incorrect_password' ) !== false ) {
                    $error_msg = esc_html__( 'Invalid Iqaama Number or Password.', 'safety-badges-manager' );
                }
                set_query_var( 'sbm_login_error', $error_msg );
            } else {
                // On success, redirect to the current URL to render the quiz
                wp_safe_redirect( $_SERVER['REQUEST_URI'] );
                exit;
            }
        }
    }

    /**
     * Restrict quiz forms for guest users by replacing the form with a professional login form.
     */
    public function restrict_quiz_with_login( $form_html, $form ) {
        // Skip in admin area
        if ( is_admin() ) {
            return $form_html;
        }

        // Only apply restriction if Safety Badges is enabled for this form
        if ( ! rgar( $form, 'sbm_enabled' ) ) {
            return $form_html;
        }

        // If user is already logged in, show the form normally
        if ( is_user_logged_in() ) {
            return $form_html;
        }

        // Check if there was a login error
        $login_error = get_query_var( 'sbm_login_error' );

        // Render custom professional login card
        return $this->get_professional_login_form_html( $form, $login_error );
    }

    /**
     * Build the HTML markup for custom professional login card.
     */
    private function get_professional_login_form_html( $form, $login_error ) {
        // Determine if local logo file exists to embed in the login card
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

        $reg_url = $this->get_registration_page_url();
        $error_html = '';
        if ( ! empty( $login_error ) ) {
            $error_html = '
            <div style="background-color: #fef2f2; border: 1px solid #fee2e2; color: #dc2626; padding: 12px; border-radius: 8px; font-size: 13px; margin-bottom: 20px; text-align: left; display: flex; align-items: center; gap: 8px;">
                <svg style="width: 16px; height: 16px; flex-shrink: 0;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
                <span>' . esc_html( $login_error ) . '</span>
            </div>';
        }

        ob_start();
        echo $this->get_portal_styles();
        ?>
        <div class="sbm-login-wrapper">
            <div class="sbm-login-card">
                
                <!-- Logo -->
                <div class="sbm-login-logo">
                    <?php if ( ! empty( $logo_img_src ) ) : ?>
                        <img src="<?php echo esc_attr( $logo_img_src ); ?>" alt="S-Chem Logo" style="filter: none !important;" />
                    <?php else : ?>
                        <span>S-CHEM</span>
                    <?php endif; ?>
                </div>

                <!-- Titles -->
                <h3 style="font-size: 20px; font-weight: 700; color: #0f172a; margin: 0 0 8px 0;"><?php esc_html_e( 'Employee Login', 'safety-badges-manager' ); ?></h3>
                <p style="font-size: 13px; color: #64748b; margin: 0 0 25px 0; line-height: 1.5;"><?php esc_html_e( 'Enter your Iqaama Number to appear for the safety exam.', 'safety-badges-manager' ); ?></p>

                <!-- Error Messages -->
                <?php echo $error_html; ?>

                <!-- Form -->
                <form method="post" action="" style="text-align: left;">
                    <?php wp_nonce_field( 'sbm_login_action', 'sbm_login_nonce' ); ?>
                    
                    <!-- Username / Iqama -->
                    <div style="margin-bottom: 24px;">
                        <label for="sbm_username" style="display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 6px;"><?php esc_html_e( 'Iqaama No.', 'safety-badges-manager' ); ?></label>
                        <input type="text" id="sbm_username" name="sbm_username" required placeholder="e.g. 123456789" style="width: 100%; padding: 12px 14px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 14px; color: #0f172a; box-sizing: border-box; transition: border-color 0.2s, box-shadow 0.2s;" onfocus="this.style.borderColor='#0f172a'; this.style.boxShadow='0 0 0 3px rgba(15, 23, 42, 0.08)';" onblur="this.style.borderColor='#cbd5e1'; this.style.boxShadow='none';" />
                    </div>

                    <!-- Password (Hidden static password for all employees) -->
                    <input type="hidden" id="sbm_password" name="sbm_password" value="111111" />

                    <!-- Submit -->
                    <button type="submit" style="width: 100%; padding: 12px; background-color: #0f172a; color: #ffffff; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: background-color 0.2s; box-shadow: 0 4px 6px -1px rgba(15, 23, 42, 0.1), 0 2px 4px -1px rgba(15, 23, 42, 0.06);" onmouseover="this.style.backgroundColor='#1e293b';" onmouseout="this.style.backgroundColor='#0f172a';"><?php esc_html_e( 'Sign In', 'safety-badges-manager' ); ?></button>
                </form>

                <!-- Divider -->
                <div style="margin: 25px 0; border-top: 1px solid #e2e8f0; position: relative;">
                    <span style="position: absolute; top: -10px; left: 50%; transform: translateX(-50%); background-color: #ffffff; padding: 0 10px; font-size: 11px; color: #94a3b8; text-transform: uppercase;"><?php esc_html_e( 'or', 'safety-badges-manager' ); ?></span>
                </div>

                <!-- Register Link -->
                <p style="font-size: 18px !important; color: #64748b; margin: 0; font-weight: 600;">
                    <?php esc_html_e( 'Not registered yet?', 'safety-badges-manager' ); ?>
                    <a href="<?php echo esc_url( $reg_url ); ?>" style="color: #dc2626; font-weight: 700; text-decoration: none; transition: color 0.2s; font-size: 18px !important;" onmouseover="this.style.color='#b91c1c';" onmouseout="this.style.color='#dc2626';"><?php esc_html_e( 'Register Here', 'safety-badges-manager' ); ?></a>
                </p>

            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Get the URL of the page containing the registration form.
     */
    private function get_registration_page_url() {
        global $wpdb;
        // Try to find page with gravityform id="5" or containing user-registration
        $post_id = $wpdb->get_var(
            "SELECT ID FROM $wpdb->posts WHERE post_status = 'publish' AND post_content LIKE '%[gravityform%' AND post_content LIKE '%id=\"5\"%' LIMIT 1"
        );
        if ( $post_id ) {
            return get_permalink( $post_id );
        }

        // Fallback to searching pages for name registration/register
        $pages = get_pages();
        if ( ! empty( $pages ) ) {
            foreach ( $pages as $page ) {
                $name = strtolower( $page->post_name );
                if ( strpos( $name, 'register' ) !== false || strpos( $name, 'registration' ) !== false || strpos( $name, 'signup' ) !== false ) {
                    return get_permalink( $page->ID );
                }
            }
        }

        return home_url( '/user-registration/' );
    }

    /**
     * Add custom columns to Gravity Forms entries list.
     */
    public function add_entry_list_columns( $columns, $form_id ) {
        $form = GFAPI::get_form( $form_id );
        if ( ! $form || ! rgar( $form, 'sbm_enabled' ) ) {
            return $columns;
        }

        $new_columns = array();
        
        // Star and checkbox selectors always stay at the beginning
        if ( isset( $columns['cb'] ) ) {
            $new_columns['cb'] = $columns['cb'];
            unset( $columns['cb'] );
        }
        if ( isset( $columns['is_starred'] ) ) {
            $new_columns['is_starred'] = $columns['is_starred'];
            unset( $columns['is_starred'] );
        }

        // Place custom SBM identity columns first as requested
        $new_columns['sbm_user_name']    = esc_html__( 'Employee Name', 'safety-badges-manager' );
        $new_columns['sbm_user_iqama']   = esc_html__( 'Iqaama No.', 'safety-badges-manager' );
        $new_columns['sbm_user_company'] = esc_html__( 'Company', 'safety-badges-manager' );

        // Place the remaining Gravity Forms core columns afterwards
        foreach ( $columns as $key => $label ) {
            if ( ! in_array( $key, array( 'sbm_user_name', 'sbm_user_iqama', 'sbm_user_company' ) ) ) {
                $new_columns[ $key ] = $label;
            }
        }

        return $new_columns;
    }

    /**
     * Retrieve values for custom columns in the entries list.
     */
    public function get_custom_column_value( $value, $form_id, $field_id, $entry ) {
        if ( in_array( $field_id, array( 'sbm_user_name', 'sbm_user_iqama', 'sbm_user_company' ) ) ) {
            $user_id = rgar( $entry, 'created_by' );
            if ( ! $user_id ) {
                return '';
            }

            $user = get_userdata( $user_id );
            if ( ! $user ) {
                return '';
            }

            switch ( $field_id ) {
                case 'sbm_user_name':
                    return esc_html( $user->display_name );
                case 'sbm_user_iqama':
                    $iqama = get_user_meta( $user_id, 'sbm_iqama', true );
                    if ( empty( $iqama ) ) {
                        $iqama = $user->user_login;
                    }
                    return esc_html( $iqama );
                case 'sbm_user_company':
                    $company = get_user_meta( $user_id, 'sbm_company', true );
                    return esc_html( ! empty( $company ) ? $company : 'S-Chem' );
            }
        }

        return $value;
    }

    /**
     * Add custom fields to Gravity Forms export screen.
     */
    public function add_export_fields( $export_fields, $form_id ) {
        $form = GFAPI::get_form( $form_id );
        if ( ! $form || ! rgar( $form, 'sbm_enabled' ) ) {
            return $export_fields;
        }

        $export_fields[] = array(
            'id'    => 'sbm_export_user_name',
            'label' => esc_html__( 'Employee Name', 'safety-badges-manager' )
        );
        $export_fields[] = array(
            'id'    => 'sbm_export_user_iqama',
            'label' => esc_html__( 'Iqaama No.', 'safety-badges-manager' )
        );
        $export_fields[] = array(
            'id'    => 'sbm_export_user_company',
            'label' => esc_html__( 'Company', 'safety-badges-manager' )
        );

        return $export_fields;
    }

    /**
     * Retrieve values for custom fields during Gravity Forms export.
     */
    public function get_export_field_value( $value, $form_id, $field_id, $entry ) {
        if ( in_array( $field_id, array( 'sbm_export_user_name', 'sbm_export_user_iqama', 'sbm_export_user_company' ) ) ) {
            $user_id = rgar( $entry, 'created_by' );
            if ( ! $user_id ) {
                return '';
            }

            $user = get_userdata( $user_id );
            if ( ! $user ) {
                return '';
            }

            switch ( $field_id ) {
                case 'sbm_export_user_name':
                    return $user->display_name;
                case 'sbm_export_user_iqama':
                    $iqama = get_user_meta( $user_id, 'sbm_iqama', true );
                    if ( empty( $iqama ) ) {
                        $iqama = $user->user_login;
                    }
                    return $iqama;
                case 'sbm_export_user_company':
                    $company = get_user_meta( $user_id, 'sbm_company', true );
                    return ! empty( $company ) ? $company : 'S-Chem';
            }
        }

        return $value;
    }

    public function intercept_homepage() {
        if ( is_front_page() ) {
            if ( current_user_can( 'manage_safety_training' ) ) {
                wp_safe_redirect( admin_url( 'admin.php?page=safety-training' ) );
                exit;
            }
            $this->render_portal_page();
            exit;
        }

        // Intercept registration page
        if ( ! is_user_logged_in() ) {
            global $wpdb;
            $post_id = $wpdb->get_var(
                "SELECT ID FROM $wpdb->posts WHERE post_status = 'publish' AND post_content LIKE '%[gravityform%' AND post_content LIKE '%id=\"5\"%' LIMIT 1"
            );
            if ( $post_id && is_page( intval( $post_id ) ) ) {
                $this->render_registration_page();
                exit;
            }
        }
    }

    /**
     * Redirect users to homepage after logging out.
     */
    public function customize_logout_redirect( $redirect_to, $requested_redirect_to, $user ) {
        return home_url( '/' );
    }

    /**
     * Render the custom employee safety portal dashboard or landing page.
     */
    private function render_portal_page() {
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

        $login_error = get_query_var( 'sbm_login_error' );
        $reg_url = $this->get_registration_page_url();

        // 1. GUEST USER: Render Split Landing / Login Page
        if ( ! is_user_logged_in() ) {
            ?>
            <!DOCTYPE html>
            <html <?php language_attributes(); ?>>
            <head>
                <meta charset="<?php bloginfo( 'charset' ); ?>">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title><?php esc_html_e( 'Safety Training Portal - Saudi Chevron Phillips', 'safety-badges-manager' ); ?></title>
                <?php wp_head(); ?>
                <?php echo $this->get_portal_styles(); ?>
            </head>
            <body class="sbm-portal-body">
                <div class="sbm-landing-split">
                    <!-- Left Banner -->
                    <div class="sbm-landing-banner">
                        <div style="margin-bottom: 40px;">
                            <?php if ( ! empty( $logo_img_src ) ) : ?>
                                <img src="<?php echo esc_attr( $logo_img_src ); ?>" alt="S-Chem Logo" style="filter: none !important;" />
                            <?php else : ?>
                                <span style="font-size: 24px; font-weight: 800; letter-spacing: 1.5px; color: #ffffff;">S-CHEM</span>
                            <?php endif; ?>
                        </div>
                        <h1><?php esc_html_e( 'Employee Safety Certification Portal', 'safety-badges-manager' ); ?></h1>
                        <p><?php esc_html_e( 'S-Chem is committed to maintaining the highest safety standards. Access active safety exams, manage your credentials, and verify your certification badges from this dashboard.', 'safety-badges-manager' ); ?></p>
                        <div style="font-size: 13px; color: #64748b;">&copy; <?php echo date('Y'); ?> Saudi Chevron Phillips (S-Chem). All rights reserved.</div>
                    </div>

                    <!-- Right Login Form -->
                    <div class="sbm-landing-form-side">
                        <div class="sbm-login-card">
                            <div class="sbm-login-logo">
                                <?php if ( ! empty( $logo_img_src ) ) : ?>
                                    <img src="<?php echo esc_attr( $logo_img_src ); ?>" alt="S-Chem Logo" style="filter: none !important;" />
                                <?php else : ?>
                                    <span>S-CHEM</span>
                                <?php endif; ?>
                            </div>
                            <h3 style="font-size: 22px; font-weight: 700; color: #0f172a; margin: 0 0 8px 0;"><?php esc_html_e( 'Employee Login', 'safety-badges-manager' ); ?></h3>
                            <p style="font-size: 14px; color: #64748b; margin: 0 0 30px 0;"><?php esc_html_e( 'Please sign in to take exams and check your active safety badges.', 'safety-badges-manager' ); ?></p>

                            <?php if ( ! empty( $login_error ) ) : ?>
                                <div style="background-color: #fef2f2; border: 1px solid #fee2e2; color: #dc2626; padding: 12px; border-radius: 8px; font-size: 13px; margin-bottom: 20px; text-align: left;">
                                    <?php echo esc_html( $login_error ); ?>
                                </div>
                            <?php endif; ?>

                            <form method="post" action="" style="text-align: left;">
                                <?php wp_nonce_field( 'sbm_login_action', 'sbm_login_nonce' ); ?>
                                <div style="margin-bottom: 24px;">
                                    <label for="sbm_username" style="display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 6px;"><?php esc_html_e( 'Iqaama No.', 'safety-badges-manager' ); ?></label>
                                    <input type="text" id="sbm_username" name="sbm_username" required placeholder="e.g. 123456789" style="width: 100%; padding: 12px 14px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 14px; color: #0f172a; box-sizing: border-box;" />
                                </div>
                                <input type="hidden" name="sbm_password" value="111111" />
                                <button type="submit" style="width: 100%; padding: 12px; background-color: #0f172a; color: #ffffff; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: background-color 0.2s; box-shadow: 0 4px 6px -1px rgba(15, 23, 42, 0.1);"><?php esc_html_e( 'Sign In', 'safety-badges-manager' ); ?></button>
                            </form>

                            <div style="margin: 30px 0; border-top: 1px solid #e2e8f0; position: relative;">
                                <span style="position: absolute; top: -10px; left: 50%; transform: translateX(-50%); background-color: #ffffff; padding: 0 10px; font-size: 12px; color: #94a3b8; text-transform: uppercase;"><?php esc_html_e( 'or', 'safety-badges-manager' ); ?></span>
                            </div>

                            <p style="font-size: 18px !important; color: #64748b; margin: 0; font-weight: 600;">
                                <?php esc_html_e( 'Not registered yet?', 'safety-badges-manager' ); ?>
                                <a href="<?php echo esc_url( $reg_url ); ?>" style="color: #dc2626; font-weight: 700; text-decoration: none; transition: color 0.2s; font-size: 18px !important;" onmouseover="this.style.color='#b91c1c';" onmouseout="this.style.color='#dc2626';"><?php esc_html_e( 'Register Here', 'safety-badges-manager' ); ?></a>
                            </p>
                        </div>
                    </div>
                </div>
                <?php wp_footer(); ?>
            </body>
            </html>
            <?php
            return;
        }

        // LOGGED-IN USERS
        $user_id = get_current_user_id();
        $user    = wp_get_current_user();
        $iqama   = get_user_meta( $user_id, 'sbm_iqama', true );
        if ( empty( $iqama ) ) {
            $iqama = $user->user_login;
        }
        $company = get_user_meta( $user_id, 'sbm_company', true );

        // Self-healing display name if corrupt (e.g. TRUE, 1, false, etc.)
        $display_name = $this->heal_user_display_name( $user_id );
        $user->display_name = $display_name;

        // 2. QUIZ MODE: Render a selected active Gravity Forms quiz page template
        $quiz_id = isset( $_GET['quiz_id'] ) ? intval( $_GET['quiz_id'] ) : 0;
        if ( $quiz_id ) {
            $form = GFAPI::get_form( $quiz_id );
            if ( $form && rgar( $form, 'is_active' ) && rgar( $form, 'sbm_enabled' ) ) {
                ?>
                <!DOCTYPE html>
                <html <?php language_attributes(); ?>>
                <head>
                    <meta charset="<?php bloginfo( 'charset' ); ?>">
                    <meta name="viewport" content="width=device-width, initial-scale=1">
                    <title><?php echo esc_html( $form['title'] ); ?> - S-Chem Portal</title>
                    <?php wp_head(); ?>
                    <?php echo $this->get_portal_styles(); ?>
                </head>
                <body class="sbm-portal-body">
                    <div class="sbm-portal-wrapper">
                        <header class="sbm-portal-header">
                            <div class="brand">
                                <?php if ( ! empty( $logo_img_src ) ) : ?>
                                    <img src="<?php echo esc_attr( $logo_img_src ); ?>" alt="S-Chem Logo" style="filter: none !important;" />
                                <?php else : ?>
                                    <span class="brand-text">S-CHEM</span>
                                <?php endif; ?>
                                <span class="brand-text">| Exam Portal</span>
                            </div>
                            <div>
                                <a href="<?php echo esc_url( home_url('/') ); ?>" class="btn-back">&larr; Back to Dashboard</a>
                            </div>
                        </header>
                        <main class="sbm-portal-content">
                            <div class="sbm-portal-card">
                                <h3><?php echo esc_html( $form['title'] ); ?></h3>
                                <p style="font-size: 13px; color: #64748b; margin-bottom: 25px;">Please read and answer all questions carefully. You must achieve the minimum passing score to receive your badge.</p>
                                <?php
                                // Output Gravity Form
                                gravity_form( $form['id'], false, true, false, null, true );
                                ?>
                            </div>
                        </main>
                    </div>
                    <?php wp_footer(); ?>
                </body>
                </html>
                <?php
                return;
            }
        }

        // 3. DASHBOARD MODE: Render Employee Portal Dashboard page
        $active_badge = $this->db->get_active_badge_by_user( $user_id );

        // Fetch active quizzes
        $active_quizzes = array();
        if ( class_exists( 'GFAPI' ) ) {
            $forms = GFAPI::get_forms();
            foreach ( $forms as $f ) {
                if ( rgar( $f, 'is_active' ) && rgar( $f, 'sbm_enabled' ) ) {
                    $active_quizzes[] = $f;
                }
            }
        }

        // Fetch history
        $user_attempts = array();
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
            $raw_attempts = GFAPI::get_entries(
                0,
                $search_criteria,
                array( 'key' => 'date_created', 'direction' => 'DESC' )
            );
            foreach ( $raw_attempts as $attempt ) {
                $f_id = $attempt['form_id'];
                $f = GFAPI::get_form( $f_id );
                if ( $f && rgar( $f, 'sbm_enabled' ) ) {
                    $score = gform_get_meta( $attempt['id'], 'gquiz_percent' );
                    $is_pass = gform_get_meta( $attempt['id'], 'gquiz_is_pass' );
                    $user_attempts[] = array(
                        'date'       => $attempt['date_created'],
                        'form_id'    => $f_id,
                        'form_title' => $f['title'],
                        'score'      => $score !== '' ? floatval( $score ) . '%' : '-',
                        'pass'       => $is_pass == '1'
                    );
                }
            }
        }

        // Build a list of passed and attempted form IDs for this user
        $passed_form_ids = array();
        $attempted_form_ids = array();

        // 1. Get passed form IDs from active badges database table
        $user_badges = $this->db->get_badges_by_user( $user_id );
        if ( ! empty( $user_badges ) ) {
            foreach ( $user_badges as $badge ) {
                if ( $badge->status === 'active' ) {
                    $passed_form_ids[] = intval( $badge->form_id );
                }
            }
        }

        // 2. Get attempted and passed form IDs from attempt history as fallback
        if ( ! empty( $user_attempts ) ) {
            foreach ( $user_attempts as $att ) {
                $f_id = isset( $att['form_id'] ) ? intval( $att['form_id'] ) : 0;
                if ( $f_id ) {
                    $attempted_form_ids[] = $f_id;
                    if ( $att['pass'] ) {
                        $passed_form_ids[] = $f_id;
                    }
                }
            }
        }
        $passed_form_ids = array_unique( $passed_form_ids );
        $attempted_form_ids = array_unique( $attempted_form_ids );
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo( 'charset' ); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php esc_html_e( 'Employee Dashboard - S-Chem Portal', 'safety-badges-manager' ); ?></title>
            <?php wp_head(); ?>
            <?php echo $this->get_portal_styles(); ?>
        </head>
        <body class="sbm-portal-body">
            <div class="sbm-portal-wrapper">
                <header class="sbm-portal-header">
                    <div class="brand">
                        <?php if ( ! empty( $logo_img_src ) ) : ?>
                            <img src="<?php echo esc_attr( $logo_img_src ); ?>" alt="S-Chem Logo" style="filter: none !important;" />
                        <?php else : ?>
                            <span class="brand-text">S-CHEM</span>
                        <?php endif; ?>
                        <span class="brand-text">| Employee Portal</span>
                    </div>
                    <div class="user-info">
                        <span class="user-name"><?php echo esc_html( $user->display_name ); ?></span>
                        <a href="<?php echo esc_url( wp_logout_url( home_url('/') ) ); ?>" class="btn-logout">Logout</a>
                    </div>
                </header>

                <main class="sbm-portal-content">
                    <div class="sbm-portal-grid">
                        
                        <!-- Left Side: Safety Badge Info -->
                        <div>
                            <div class="sbm-portal-card" style="text-align: center;">
                                <h3><?php esc_html_e( 'My Safety Badge', 'safety-badges-manager' ); ?></h3>
                                <?php if ( $active_badge ) : ?>
                                    <div class="sbm-visual-badge">
                                        <div class="badge-header">S-CHEM SAFETY BADGE</div>
                                        <div class="badge-body">
                                            <img class="badge-avatar" src="<?php echo esc_url( get_avatar_url( $user_id, array( 'size' => 120 ) ) ); ?>" alt="Avatar" />
                                            <h4 class="badge-name"><?php echo esc_html( $user->display_name ); ?></h4>
                                            <p class="badge-meta"><strong>Iqaama:</strong> <?php echo esc_html( $iqama ); ?></p>
                                            <p class="badge-meta"><strong>Company:</strong> <?php echo esc_html( ! empty( $company ) ? $company : 'S-Chem' ); ?></p>
                                            <div class="badge-number"><?php echo esc_html( $active_badge->badge_number ); ?></div>
                                            <div>
                                                <span class="badge-status-tag status-active">Active</span>
                                            </div>
                                            <p style="font-size: 11px; color: #64748b; margin-top: 10px;">Expires on: <?php echo date_i18n( get_option( 'date_format' ), strtotime( $active_badge->expiry_date ) ); ?></p>
                                        </div>
                                    </div>
                                    <?php
                                    $global_printing     = get_option( 'sbm_global_allow_printing', 'yes' );
                                    $individual_printing = get_user_meta( $user_id, 'sbm_allow_badge_printing', true );
                                    if ( $individual_printing === '' ) {
                                        $individual_printing = 'yes';
                                    }

                                    if ( 'yes' === $global_printing && 'yes' === $individual_printing ) :
                                    ?>
                                        <a href="<?php echo esc_url( admin_url( 'admin-post.php?action=sbm_print_badges&badges[]=' . $active_badge->id ) ); ?>" target="_blank" class="button button-primary" style="display: block; text-align: center; width: 100%; box-sizing: border-box; background-color: #0f172a !important; color: #ffffff !important; border: none; padding: 12px; border-radius: 8px; font-weight: 600; text-decoration: none; transition: background-color 0.2s;"><span class="dashicons dashicons-printer" style="margin-right: 6px; font-size: 16px; width: 16px; height: 16px; line-height: 16px; vertical-align: middle;"></span> Print Badge PDF</a>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <div style="padding: 20px 0; color: #64748b;">
                                        <span class="dashicons dashicons-shield-alt" style="font-size: 48px; width: 48px; height: 48px; color: #94a3b8; margin-bottom: 10px;"></span>
                                        <p style="font-size: 14px; margin: 0 0 15px 0;">You do not have an active safety badge. Please complete an active exam on the right to receive your badge.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Right Side: Active Quizzes & Attempt History -->
                        <div>
                            <!-- Active Quizzes -->
                            <div class="sbm-portal-card">
                                <h3><?php esc_html_e( 'Available Safety Exams', 'safety-badges-manager' ); ?></h3>
                                <?php if ( ! empty( $active_quizzes ) ) : ?>
                                    <div class="sbm-quizzes-grid">
                                        <?php foreach ( $active_quizzes as $quiz ) : 
                                            $quiz_id = intval( $quiz['id'] );
                                            $has_passed = in_array( $quiz_id, $passed_form_ids );
                                            $has_attempted = in_array( $quiz_id, $attempted_form_ids );
                                            ?>
                                            <div class="sbm-quiz-item-card">
                                                <div>
                                                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; margin-bottom: 10px;">
                                                        <h4 style="margin: 0;"><?php echo esc_html( $quiz['title'] ); ?></h4>
                                                        <?php if ( $has_passed ) : ?>
                                                            <span class="sbm-status-tag pass" style="flex-shrink: 0; margin-top: -2px;">Passed</span>
                                                        <?php elseif ( $has_attempted ) : ?>
                                                            <span class="sbm-status-tag fail" style="flex-shrink: 0; margin-top: -2px;">Failed</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <p class="meta">Passing Score: <?php echo esc_html( rgar( $quiz, 'sbm_pass_percent', 80 ) ); ?>%</p>
                                                </div>
                                                <?php if ( $has_passed || $has_attempted ) : ?>
                                                    <a href="<?php echo esc_url( add_query_arg( 'quiz_id', $quiz['id'], home_url('/') ) ); ?>" class="btn-start btn-retake">Retake Exam</a>
                                                <?php else : ?>
                                                    <a href="<?php echo esc_url( add_query_arg( 'quiz_id', $quiz['id'], home_url('/') ) ); ?>" class="btn-start">Start Exam</a>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else : ?>
                                    <p style="font-style: italic; color: #64748b; margin: 0;"><?php esc_html_e( 'No active safety exams are currently available.', 'safety-badges-manager' ); ?></p>
                                <?php endif; ?>
                            </div>

                            <!-- Attempt History -->
                            <div class="sbm-portal-card">
                                <h3><?php esc_html_e( 'My Exam Attempt History', 'safety-badges-manager' ); ?></h3>
                                <?php if ( ! empty( $user_attempts ) ) : ?>
                                    <div class="sbm-history-table-container">
                                        <table class="sbm-history-table">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Exam Name</th>
                                                    <th>Score</th>
                                                    <th>Result</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ( $user_attempts as $att ) : ?>
                                                    <tr>
                                                        <td><?php echo date_i18n( get_option( 'date_format' ) . ' H:i', strtotime( $att['date'] ) ); ?></td>
                                                        <td><?php echo esc_html( $att['form_title'] ); ?></td>
                                                        <td><?php echo esc_html( $att['score'] ); ?></td>
                                                        <td>
                                                            <?php if ( $att['pass'] ) : ?>
                                                                <span class="sbm-status-tag pass">Passed</span>
                                                            <?php else : ?>
                                                                <span class="sbm-status-tag fail">Failed</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else : ?>
                                    <p style="font-style: italic; color: #64748b; margin: 0;"><?php esc_html_e( 'You have not attempted any safety exams yet.', 'safety-badges-manager' ); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>

                    </div>
                </main>
            </div>
            <?php wp_footer(); ?>
        </body>
        </html>
        <?php
    }

    /**
     * Render the custom user registration page using a cohesive split layout.
     */
    private function render_registration_page() {
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
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo( 'charset' ); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php esc_html_e( 'Register - Safety Training Portal', 'safety-badges-manager' ); ?></title>
            <?php wp_head(); ?>
            <?php echo $this->get_portal_styles(); ?>
        </head>
        <body class="sbm-portal-body">
            <div class="sbm-landing-split">
                <!-- Left Banner -->
                <div class="sbm-landing-banner">
                    <div style="margin-bottom: 40px;">
                        <?php if ( ! empty( $logo_img_src ) ) : ?>
                            <img src="<?php echo esc_attr( $logo_img_src ); ?>" alt="S-Chem Logo" style="filter: none !important;" />
                        <?php else : ?>
                            <span style="font-size: 24px; font-weight: 800; letter-spacing: 1.5px; color: #ffffff;">S-CHEM</span>
                        <?php endif; ?>
                    </div>
                    <h1><?php esc_html_e( 'Employee Safety Certification Portal', 'safety-badges-manager' ); ?></h1>
                    <p><?php esc_html_e( 'S-Chem is committed to maintaining the highest safety standards. Access active safety quizzes, manage your credentials, and verify your certification badges from this dashboard.', 'safety-badges-manager' ); ?></p>
                    <div style="font-size: 13px; color: #64748b;">&copy; <?php echo date('Y'); ?> Saudi Chevron Phillips (S-Chem). All rights reserved.</div>
                </div>

                <!-- Right Registration Form -->
                <div class="sbm-landing-form-side">
                    <div class="sbm-login-card" style="max-width: 500px;">
                        <div class="sbm-login-logo">
                            <?php if ( ! empty( $logo_img_src ) ) : ?>
                                <img src="<?php echo esc_attr( $logo_img_src ); ?>" alt="S-Chem Logo" style="filter: none !important;" />
                            <?php else : ?>
                                <span>S-CHEM</span>
                            <?php endif; ?>
                        </div>
                        <h3 style="font-size: 22px; font-weight: 700; color: #0f172a; margin: 0 0 8px 0;"><?php esc_html_e( 'Employee Registration', 'safety-badges-manager' ); ?></h3>
                        <p style="font-size: 14px; color: #64748b; margin: 0 0 30px 0;"><?php esc_html_e( 'Register your account to start appearing for safety quizzes.', 'safety-badges-manager' ); ?></p>

                        <?php
                        // Render Gravity Form ID 5 (Registration Form)
                        gravity_form( 5, false, false, false, null, true );
                        ?>

                        <div style="margin: 30px 0; border-top: 1px solid #e2e8f0; position: relative;">
                            <span style="position: absolute; top: -10px; left: 50%; transform: translateX(-50%); background-color: #ffffff; padding: 0 10px; font-size: 12px; color: #94a3b8; text-transform: uppercase;"><?php esc_html_e( 'or', 'safety-badges-manager' ); ?></span>
                        </div>

                        <p style="font-size: 18px !important; color: #64748b; margin: 0; font-weight: 600;">
                            <?php esc_html_e( 'Already registered?', 'safety-badges-manager' ); ?>
                            <a href="<?php echo esc_url( home_url( '/' ) ); ?>" style="color: #dc2626; font-weight: 700; text-decoration: none; transition: color 0.2s; font-size: 18px !important;" onmouseover="this.style.color='#b91c1c';" onmouseout="this.style.color='#dc2626';"><?php esc_html_e( 'Login Here', 'safety-badges-manager' ); ?></a>
                        </p>
                    </div>
                </div>
            </div>
            <?php wp_footer(); ?>
        </body>
        </html>
        <?php
    }

    /**
     * Get the consolidated CSS styles for the Employee Portal frontend pages.
     */
    private function get_portal_styles() {
        ob_start();
        ?>
        <style>
            /* Base reset & theme colors */
            body.sbm-portal-body {
                background-color: #f1f5f9 !important;
                margin: 0 !important;
                padding: 0 !important;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif !important;
                color: #1e293b !important;
            }
            body.sbm-portal-body * {
                box-sizing: border-box !important;
            }

            /* Split layout for Guest Landing & Registration */
            .sbm-landing-split {
                display: grid;
                grid-template-columns: 1.2fr 1fr;
                min-height: 100vh;
            }
            .sbm-landing-banner {
                background-color: #0f172a;
                color: #ffffff;
                display: flex;
                flex-direction: column;
                justify-content: center;
                padding: 80px;
                position: relative;
                background-image: radial-gradient(circle at top right, #1e293b 0%, #0f172a 100%);
            }
            .sbm-landing-banner img {
                height: 50px !important;
                background-color: #ffffff !important;
                padding: 5px 12px !important;
                border-radius: 6px !important;
                object-fit: contain !important;
                display: inline-block !important;
                filter: none !important;
            }
            .sbm-landing-banner h1 {
                font-size: 38px;
                font-weight: 800;
                margin-bottom: 20px;
                line-height: 1.2;
                color: #ffffff;
            }
            .sbm-landing-banner p {
                font-size: 16px;
                color: #94a3b8;
                line-height: 1.6;
                max-width: 500px;
                margin-bottom: 30px;
            }
            .sbm-landing-form-side {
                background-color: #ffffff;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 40px 20px;
            }
            .sbm-login-card {
                width: 100%;
                max-width: 400px;
                text-align: center;
            }
            .sbm-login-logo {
                margin-bottom: 30px;
            }
            .sbm-login-logo img {
                height: 45px !important;
                max-width: 100% !important;
                object-fit: contain !important;
                filter: none !important;
            }
            .sbm-login-logo span {
                font-size: 26px;
                font-weight: 800;
                letter-spacing: 2px;
                color: #0f172a;
            }

            /* Portal Layout */
            .sbm-portal-wrapper {
                min-height: 100vh;
                display: flex;
                flex-direction: column;
            }
            .sbm-portal-header {
                background-color: #0f172a;
                color: #ffffff;
                padding: 15px 30px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
            }
            .sbm-portal-header .brand {
                display: flex;
                align-items: center;
                gap: 12px;
            }
            .sbm-portal-header .brand img {
                height: 35px !important;
                background-color: #ffffff !important;
                padding: 4px 10px !important;
                border-radius: 6px !important;
                max-width: 100% !important;
                object-fit: contain !important;
                filter: none !important;
            }
            .sbm-portal-header .brand-text {
                font-size: 18px;
                font-weight: 800;
                letter-spacing: 1px;
            }
            .sbm-portal-header .user-info {
                display: flex;
                align-items: center;
                gap: 20px;
            }
            .sbm-portal-header .user-name {
                font-size: 14px;
                font-weight: 600;
            }
            .sbm-portal-header .btn-logout {
                border: 1.5px solid #ef4444;
                color: #ef4444;
                padding: 6px 14px;
                border-radius: 6px;
                font-size: 13px;
                font-weight: 600;
                text-decoration: none;
                transition: all 0.2s;
            }
            .sbm-portal-header .btn-logout:hover {
                background-color: #ef4444;
                color: #ffffff;
            }
            .sbm-portal-header .btn-back {
                border: 1.5px solid #cbd5e1;
                color: #cbd5e1;
                padding: 6px 14px;
                border-radius: 6px;
                font-size: 13px;
                font-weight: 600;
                text-decoration: none;
                transition: all 0.2s;
            }
            .sbm-portal-header .btn-back:hover {
                background-color: #ffffff;
                color: #0f172a;
                border-color: #ffffff;
            }

            .sbm-portal-content {
                flex: 1;
                max-width: 1200px;
                width: 100%;
                margin: 40px auto;
                padding: 0 20px;
            }
            .sbm-portal-grid {
                display: grid;
                grid-template-columns: 1fr 2fr;
                grid-gap: 30px;
            }
            .sbm-portal-card {
                background-color: #ffffff;
                border: 1px solid #e2e8f0;
                border-radius: 12px;
                box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
                padding: 25px;
                margin-bottom: 30px;
            }
            .sbm-portal-card h3 {
                margin-top: 0;
                margin-bottom: 20px;
                font-size: 18px;
                font-weight: 700;
                color: #0f172a;
                border-bottom: 1px solid #f1f5f9;
                padding-bottom: 15px;
            }

            /* Badge display styles */
            .sbm-visual-badge {
                border: 2px solid #0f172a;
                border-radius: 12px;
                background: #ffffff;
                overflow: hidden;
                max-width: 300px;
                margin: 0 auto 20px auto;
                box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);
            }
            .sbm-visual-badge .badge-header {
                background-color: #0f172a;
                color: #ffffff;
                padding: 10px;
                text-align: center;
                font-size: 11px;
                font-weight: 800;
                letter-spacing: 1.5px;
            }
            .sbm-visual-badge .badge-body {
                padding: 20px 15px;
                text-align: center;
            }
            .sbm-visual-badge .badge-avatar {
                width: 90px;
                height: 90px;
                border-radius: 50%;
                margin-bottom: 15px;
                border: 2px solid #e2e8f0;
                object-fit: cover;
            }
            .sbm-visual-badge .badge-name {
                font-size: 16px;
                font-weight: 700;
                color: #0f172a;
                margin: 0 0 5px 0;
            }
            .sbm-visual-badge .badge-meta {
                font-size: 12px;
                color: #64748b;
                margin: 2px 0;
            }
            .sbm-visual-badge .badge-number {
                font-family: monospace;
                font-size: 13px;
                font-weight: 700;
                color: #0f172a;
                background-color: #f1f5f9;
                padding: 4px 8px;
                border-radius: 4px;
                display: inline-block;
                margin: 10px 0;
            }
            .sbm-visual-badge .badge-status-tag {
                font-size: 10px;
                font-weight: 800;
                padding: 3px 10px;
                border-radius: 12px;
                text-transform: uppercase;
                display: inline-block;
            }
            .sbm-visual-badge .status-active {
                background-color: #ecfdf5;
                color: #065f46;
                border: 1px solid #a7f3d0;
            }
            .sbm-visual-badge .status-expired {
                background-color: #fef2f2;
                color: #991b1b;
                border: 1px solid #fca5a5;
            }

            /* Quizzes Grid */
            .sbm-quizzes-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
                grid-gap: 20px;
            }
            .sbm-quiz-item-card {
                border: 1px solid #cbd5e1;
                border-radius: 8px;
                padding: 20px;
                background-color: #f8fafc;
                transition: all 0.2s;
                display: flex;
                flex-direction: column;
                justify-content: space-between;
                min-height: 180px;
            }
            .sbm-quiz-item-card:hover {
                border-color: #0f172a;
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            }
            .sbm-quiz-item-card h4 {
                margin: 0 0 10px 0;
                font-size: 15px;
                font-weight: 700;
                color: #0f172a;
            }
            .sbm-quiz-item-card .meta {
                font-size: 12px;
                color: #64748b;
                margin-bottom: 20px;
            }
            .sbm-quiz-item-card .btn-start {
                display: block;
                text-align: center;
                background-color: #0f172a;
                color: #ffffff;
                text-decoration: none;
                font-size: 13px;
                font-weight: 600;
                padding: 10px;
                border-radius: 6px;
                transition: background-color 0.2s;
            }
            .sbm-quiz-item-card .btn-start:hover {
                background-color: #1e293b;
            }
            .sbm-quiz-item-card .btn-start.btn-retake {
                background-color: #f1f5f9;
                color: #0f172a;
                border: 1.5px solid #cbd5e1;
            }
            .sbm-quiz-item-card .btn-start.btn-retake:hover {
                background-color: #e2e8f0;
                border-color: #0f172a;
            }

            /* Attempt History */
            .sbm-history-table-container {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            .sbm-history-table {
                width: 100%;
                border-collapse: collapse;
                text-align: left;
            }
            .sbm-history-table th, .sbm-history-table td {
                padding: 12px 15px;
                border-bottom: 1px solid #cbd5e1;
                font-size: 13px;
            }
            .sbm-history-table th {
                background-color: #f1f5f9;
                color: #475569;
                font-weight: 600;
            }
            .sbm-history-table tr:hover {
                background-color: #f8fafc;
            }
            .sbm-status-tag {
                font-size: 10px;
                font-weight: 700;
                text-transform: uppercase;
                padding: 2px 8px;
                border-radius: 4px;
                display: inline-block;
            }
            .sbm-status-tag.pass {
                background-color: #d1fae5;
                color: #065f46;
            }
            .sbm-status-tag.fail {
                background-color: #fee2e2;
                color: #991b1b;
            }

            /* Gravity Forms General Styling to match Premium UI */
            .gform_wrapper {
                text-align: left;
            }
            .gform_wrapper .gform_heading {
                display: none !important;
            }
            .gform_wrapper input[type="text"],
            .gform_wrapper input[type="email"],
            .gform_wrapper input[type="password"],
            .gform_wrapper select {
                width: 100% !important;
                padding: 12px 14px !important;
                border: 1.5px solid #cbd5e1 !important;
                border-radius: 8px !important;
                font-size: 14px !important;
                color: #0f172a !important;
                box-sizing: border-box !important;
                transition: border-color 0.2s, box-shadow 0.2s !important;
                background-color: #ffffff !important;
            }
            .gform_wrapper input[type="text"]:focus,
            .gform_wrapper input[type="email"]:focus,
            .gform_wrapper input[type="password"]:focus,
            .gform_wrapper select:focus {
                border-color: #0f172a !important;
                box-shadow: 0 0 0 3px rgba(15, 23, 42, 0.08) !important;
                outline: none !important;
            }
            .gform_wrapper .gfield_label {
                display: block !important;
                font-size: 13px !important;
                font-weight: 600 !important;
                color: #334155 !important;
                margin-bottom: 6px !important;
            }
            .gform_wrapper .gform_button,
            .gform_wrapper input[type="submit"] {
                width: 100% !important;
                padding: 12px !important;
                background-color: #0f172a !important;
                color: #ffffff !important;
                border: none !important;
                border-radius: 8px !important;
                font-size: 14px !important;
                font-weight: 600 !important;
                cursor: pointer !important;
                transition: background-color 0.2s !important;
                box-shadow: 0 4px 6px -1px rgba(15, 23, 42, 0.1) !important;
            }
            .gform_wrapper .gform_button:hover,
            .gform_wrapper input[type="submit"]:hover {
                background-color: #1e293b !important;
            }
            .gform_wrapper .gfield_required {
                color: #dc2626 !important;
                margin-left: 4px;
            }
            .gform_wrapper .gfield_description {
                font-size: 11px !important;
                color: #64748b !important;
                margin-top: 4px !important;
            }
            .gform_wrapper .validation_message {
                color: #dc2626 !important;
                font-size: 12px !important;
                margin-top: 4px !important;
            }
            .gform_wrapper .gfield_error input {
                border-color: #fca5a5 !important;
                background-color: #fef2f2 !important;
            }

            /* Responsive Overrides */
            @media (max-width: 900px) {
                .sbm-landing-split {
                    grid-template-columns: 1fr;
                }
                .sbm-landing-banner {
                    display: none !important;
                }
                .sbm-portal-grid {
                    grid-template-columns: 1fr;
                }
            }

            @media (max-width: 600px) {
                .sbm-portal-header {
                    flex-direction: column;
                    align-items: center;
                    gap: 12px;
                    padding: 15px 15px;
                    text-align: center;
                }
                .sbm-portal-header .brand {
                    flex-direction: column;
                    gap: 4px;
                }
                .sbm-portal-header .brand-text {
                    font-size: 16px;
                }
                .sbm-portal-header .user-info {
                    flex-direction: column;
                    gap: 8px;
                    width: 100%;
                }
                .sbm-portal-header .user-name {
                    display: block;
                }
                .sbm-portal-header .btn-logout,
                .sbm-portal-header .btn-back {
                    display: inline-block;
                    width: 100%;
                    max-width: 180px;
                    text-align: center;
                }
                .sbm-portal-content {
                    margin: 15px auto;
                    padding: 0 10px;
                }
                .sbm-portal-card {
                    padding: 15px;
                    margin-bottom: 20px;
                }
                
                /* Gravity Forms CSS Grid layout mobile collapse */
                .gform_wrapper .gform_fields {
                    grid-template-columns: 1fr !important;
                    display: flex !important;
                    flex-direction: column !important;
                    gap: 16px !important;
                }
                .gform_wrapper .gfield {
                    grid-column: span 12 !important;
                    width: 100% !important;
                    max-width: 100% !important;
                }
                .gform_wrapper .gform-grid-col {
                    grid-column: span 12 !important;
                    width: 100% !important;
                    max-width: 100% !important;
                }
                
                /* Legacy grid classes */
                .gform_wrapper .gfield--width-half,
                .gform_wrapper .gfield--width-third,
                .gform_wrapper .gfield--width-quarter,
                .gform_wrapper .gfield--width-two-thirds,
                .gform_wrapper .gfield--width-three-quarters {
                    width: 100% !important;
                    max-width: 100% !important;
                    float: none !important;
                    clear: both !important;
                    margin-left: 0 !important;
                    margin-right: 0 !important;
                }
            }

            @media (max-width: 480px) {
                .sbm-landing-form-side {
                    padding: 30px 15px;
                }
                .sbm-login-card {
                    padding: 25px 15px;
                }
                .sbm-quizzes-grid {
                    grid-template-columns: 1fr;
                }
            }
        </style>
        <?php
        return ob_get_clean();
    }
}
