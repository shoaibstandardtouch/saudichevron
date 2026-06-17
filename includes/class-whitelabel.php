<?php
/**
 * SBM Portal Whitelabeling and Custom Role Handler
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SBM_Whitelabel {

    /**
     * Initialize whitelabel hooks.
     */
    public function init() {
        // Register custom role and capabilities on init
        add_action( 'init', array( $this, 'register_custom_role' ) );

        // WordPress login page customization
        add_action( 'login_enqueue_scripts', array( $this, 'custom_login_styles' ) );
        add_filter( 'login_headerurl', array( $this, 'custom_login_logo_url' ) );
        add_filter( 'login_headertext', array( $this, 'custom_login_logo_title' ) );

        // Admin bar & footer branding
        add_action( 'wp_before_admin_bar_render', array( $this, 'remove_wp_logo_from_admin_bar' ) );
        add_filter( 'admin_footer_text', array( $this, 'custom_admin_footer' ) );

        // Hide default WP menus for Safety Manager role
        add_action( 'admin_menu', array( $this, 'hide_admin_menus' ), 999 );

        // Redirect Safety Manager login to Compliance Dashboard
        add_filter( 'login_redirect', array( $this, 'redirect_login_to_compliance_dashboard' ), 10, 3 );
    }

    /**
     * Register safety Compliance Manager role and capabilities.
     */
    public function register_custom_role() {
        // Ensure Administrator has the custom compliance capability
        $admin_role = get_role( 'administrator' );
        if ( $admin_role ) {
            $admin_role->add_cap( 'manage_safety_training' );
        }

        // Add custom SBM Manager role if it does not exist
        if ( ! get_role( 'sbm_manager' ) ) {
            add_role(
                'sbm_manager',
                __( 'Safety Compliance Manager', 'safety-badges-manager' ),
                array(
                    'read'                          => true,
                    'manage_safety_training'        => true,
                    // Gravity Forms Capabilities for managers
                    'gravityforms_create_form'      => true,
                    'gravityforms_edit_forms'        => true,
                    'gravityforms_delete_forms'      => true,
                    'gravityforms_preview_forms'     => true,
                    'gravityforms_view_entries'      => true,
                    'gravityforms_edit_entries'      => true,
                    'gravityforms_delete_entries'    => true,
                    'gravityforms_view_entry_notes'  => true,
                    'gravityforms_edit_entry_notes'  => true,
                    'gravityforms_export_entries'    => true,
                    'gravityforms_view_settings'     => true,
                    'gravityforms_edit_settings'     => true,
                    'gravityforms_user_registration' => true,
                )
            );
        } else {
            // Update/Verify capabilities are mapped
            $manager_role = get_role( 'sbm_manager' );
            if ( $manager_role ) {
                $manager_role->add_cap( 'manage_safety_training' );
                $manager_role->add_cap( 'gravityforms_create_form' );
                $manager_role->add_cap( 'gravityforms_edit_forms' );
                $manager_role->add_cap( 'gravityforms_delete_forms' );
                $manager_role->add_cap( 'gravityforms_preview_forms' );
                $manager_role->add_cap( 'gravityforms_view_entries' );
                $manager_role->add_cap( 'gravityforms_edit_entries' );
                $manager_role->add_cap( 'gravityforms_delete_entries' );
                $manager_role->add_cap( 'gravityforms_view_entry_notes' );
                $manager_role->add_cap( 'gravityforms_edit_entry_notes' );
                $manager_role->add_cap( 'gravityforms_export_entries' );
                $manager_role->add_cap( 'gravityforms_view_settings' );
                $manager_role->add_cap( 'gravityforms_edit_settings' );
                $manager_role->add_cap( 'gravityforms_user_registration' );
            }
        }
    }

    /**
     * Apply custom CSS styling to login page.
     */
    public function custom_login_styles() {
        $logo_url = SBM_URL . 'assets/schem-logo.png';
        ?>
        <style type="text/css">
            #login h1 a, .login h1 a {
                background-image: url(<?php echo esc_url( $logo_url ); ?>) !important;
                height: 90px !important;
                width: 320px !important;
                background-size: contain !important;
                background-repeat: no-repeat !important;
                background-position: center !important;
                padding-bottom: 20px !important;
            }
            body.login {
                background-color: #0f172a !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                min-height: 100vh !important;
            }
            body.login #login {
                padding: 0 !important;
                margin: auto !important;
                width: 360px !important;
            }
            .login form {
                background: #ffffff !important;
                border: 1px solid #e2e8f0 !important;
                border-radius: 12px !important;
                box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05) !important;
                padding: 30px 24px !important;
            }
            .login label {
                color: #475569 !important;
                font-weight: 500 !important;
                font-size: 14px !important;
            }
            .login input[type="text"], .login input[type="password"] {
                border: 1px solid #cbd5e1 !important;
                border-radius: 6px !important;
                padding: 8px 12px !important;
                font-size: 15px !important;
                color: #0f172a !important;
            }
            .login input[type="text"]:focus, .login input[type="password"]:focus {
                border-color: #0f172a !important;
                box-shadow: 0 0 0 1px #0f172a !important;
            }
            .login .button-primary {
                background-color: #0f172a !important;
                border-color: #0f172a !important;
                box-shadow: none !important;
                text-shadow: none !important;
                font-weight: 600 !important;
                border-radius: 6px !important;
                padding: 6px 16px !important;
                height: auto !important;
                line-height: normal !important;
                font-size: 14px !important;
                float: none !important;
                width: 100% !important;
                margin-top: 15px !important;
            }
            .login .button-primary:hover, .login .button-primary:focus {
                background-color: #1e293b !important;
                border-color: #1e293b !important;
            }
            .login .forgetmenot {
                float: none !important;
                margin-bottom: 10px !important;
            }
            .login #backtoblog a, .login #nav a {
                color: #94a3b8 !important;
                font-size: 13px !important;
                transition: color 0.2s !important;
            }
            .login #backtoblog a:hover, .login #nav a:hover {
                color: #ffffff !important;
            }
            .login .privacy-policy-page-link {
                display: none !important;
            }
        </style>
        <?php
    }

    /**
     * Change target URL of login logo to home site.
     */
    public function custom_login_logo_url() {
        return home_url();
    }

    /**
     * Change tooltip text of login logo.
     */
    public function custom_login_logo_title() {
        return esc_html__( 'Saudi Chevron Safety Training Portal', 'safety-badges-manager' );
    }

    /**
     * Remove default WordPress logo dropdown from top admin bar.
     */
    public function remove_wp_logo_from_admin_bar( $wp_admin_bar ) {
        global $wp_admin_bar;
        if ( is_object( $wp_admin_bar ) ) {
            $wp_admin_bar->remove_menu( 'wp-logo' );
        }
    }

    /**
     * Customize admin footer text.
     */
    public function custom_admin_footer() {
        return '<span>' . sprintf( esc_html__( '%s Safety Compliance Training System', 'safety-badges-manager' ), '<strong>S-Chem</strong>' ) . '</span>';
    }

    /**
     * Hide standard WordPress menus for Safety Compliance Manager role.
     */
    public function hide_admin_menus() {
        if ( current_user_can( 'sbm_manager' ) ) {
            remove_menu_page( 'index.php' );                  // Dashboard
            remove_menu_page( 'edit.php' );                   // Posts
            remove_menu_page( 'edit.php?post_type=page' );    // Pages
            remove_menu_page( 'edit-comments.php' );          // Comments
            remove_menu_page( 'themes.php' );                 // Appearance
            remove_menu_page( 'plugins.php' );                // Plugins
            remove_menu_page( 'users.php' );                  // Users
            remove_menu_page( 'tools.php' );                  // Tools
            remove_menu_page( 'options-general.php' );        // Settings
        }
    }

    /**
     * Redirect Safety managers directly to SBM compliance dashboard upon login.
     */
    public function redirect_login_to_compliance_dashboard( $redirect_to, $request, $user ) {
        if ( isset( $user->roles ) && is_array( $user->roles ) ) {
            if ( in_array( 'sbm_manager', $user->roles ) ) {
                return admin_url( 'admin.php?page=safety-training' );
            }
        }
        return $redirect_to;
    }
}
