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

        // Handle custom login post request
        add_action( 'init', array( $this, 'handle_custom_login' ) );

        // Intercept form HTML to render custom login form for guest users on quiz forms
        add_filter( 'gform_get_form_filter', array( $this, 'restrict_quiz_with_login' ), 10, 2 );

        // Frontend script injection for dynamic prefilling as the user types
        add_filter( 'gform_pre_render', array( $this, 'inject_dynamic_email_script' ) );
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
            return get_user_meta( get_current_user_id(), 'sbm_iqama', true );
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
                    $error_msg = esc_html__( 'Invalid Iqaama/Passport Number or Password.', 'safety-badges-manager' );
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
        ?>
        <div class="sbm-login-wrapper" style="width: 100%; max-width: 450px; margin: 40px auto; padding: 0 15px; box-sizing: border-box; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;">
            <div class="sbm-login-card" style="background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.05); padding: 35px 30px; text-align: center;">
                
                <!-- Logo -->
                <div class="sbm-login-logo" style="margin-bottom: 25px;">
                    <?php if ( ! empty( $logo_img_src ) ) : ?>
                        <img src="<?php echo esc_attr( $logo_img_src ); ?>" alt="S-Chem Logo" style="height: 40px; max-width: 100%; object-fit: contain;" />
                    <?php else : ?>
                        <span style="font-size: 24px; font-weight: 800; letter-spacing: 1.5px; color: #0f172a;">S-CHEM</span>
                    <?php endif; ?>
                </div>

                <!-- Titles -->
                <h3 style="font-size: 20px; font-weight: 700; color: #0f172a; margin: 0 0 8px 0;"><?php esc_html_e( 'Employee Login', 'safety-badges-manager' ); ?></h3>
                <p style="font-size: 13px; color: #64748b; margin: 0 0 25px 0; line-height: 1.5;"><?php esc_html_e( 'Enter your Iqaama/Passport Number to appear for the safety quiz.', 'safety-badges-manager' ); ?></p>

                <!-- Error Messages -->
                <?php echo $error_html; ?>

                <!-- Form -->
                <form method="post" action="" style="text-align: left;">
                    <?php wp_nonce_field( 'sbm_login_action', 'sbm_login_nonce' ); ?>
                    
                    <!-- Username / Iqama -->
                    <div style="margin-bottom: 18px;">
                        <label for="sbm_username" style="display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 6px;"><?php esc_html_e( 'Iqaama/Passport No.', 'safety-badges-manager' ); ?></label>
                        <input type="text" id="sbm_username" name="sbm_username" required placeholder="e.g. 123456789" style="width: 100%; padding: 12px 14px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 14px; color: #0f172a; box-sizing: border-box; transition: border-color 0.2s, box-shadow 0.2s;" onfocus="this.style.borderColor='#0f172a'; this.style.boxShadow='0 0 0 3px rgba(15, 23, 42, 0.08)';" onblur="this.style.borderColor='#cbd5e1'; this.style.boxShadow='none';" />
                    </div>

                    <!-- Password -->
                    <div style="margin-bottom: 24px;">
                        <label for="sbm_password" style="display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 6px;"><?php esc_html_e( 'Password', 'safety-badges-manager' ); ?></label>
                        <input type="text" id="sbm_password" name="sbm_password" required value="111111" style="width: 100%; padding: 12px 14px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 14px; color: #0f172a; box-sizing: border-box; transition: border-color 0.2s, box-shadow 0.2s;" onfocus="this.style.borderColor='#0f172a'; this.style.boxShadow='0 0 0 3px rgba(15, 23, 42, 0.08)';" onblur="this.style.borderColor='#cbd5e1'; this.style.boxShadow='none';" />
                        <span style="display: block; font-size: 11px; color: #94a3b8; margin-top: 5px;"><?php esc_html_e( 'Static employee password is pre-filled as 111111.', 'safety-badges-manager' ); ?></span>
                    </div>

                    <!-- Submit -->
                    <button type="submit" style="width: 100%; padding: 12px; background-color: #0f172a; color: #ffffff; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: background-color 0.2s; box-shadow: 0 4px 6px -1px rgba(15, 23, 42, 0.1), 0 2px 4px -1px rgba(15, 23, 42, 0.06);" onmouseover="this.style.backgroundColor='#1e293b';" onmouseout="this.style.backgroundColor='#0f172a';"><?php esc_html_e( 'Sign In', 'safety-badges-manager' ); ?></button>
                </form>

                <!-- Divider -->
                <div style="margin: 25px 0; border-top: 1px solid #e2e8f0; position: relative;">
                    <span style="position: absolute; top: -10px; left: 50%; transform: translateX(-50%); background-color: #ffffff; padding: 0 10px; font-size: 11px; color: #94a3b8; text-transform: uppercase;"><?php esc_html_e( 'or', 'safety-badges-manager' ); ?></span>
                </div>

                <!-- Register Link -->
                <p style="font-size: 13px; color: #64748b; margin: 0;">
                    <?php esc_html_e( 'Not registered yet?', 'safety-badges-manager' ); ?>
                    <a href="<?php echo esc_url( $reg_url ); ?>" style="color: #dc2626; font-weight: 600; text-decoration: none; transition: color 0.2s;" onmouseover="this.style.color='#b91c1c';" onmouseout="this.style.color='#dc2626';"><?php esc_html_e( 'Register Here', 'safety-badges-manager' ); ?></a>
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
}
