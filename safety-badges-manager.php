<?php
/**
 * Plugin Name: Safety Badges Manager
 * Plugin URI:  https://standardtouch.com
 * Description: Safety training compliance system. Extends Gravity Forms Quiz to manage employee certificates, automate badge expiry, randomize test questions, and print bulk PDFs.
 * Version:     1.4.5
 * Author:      StandardTouch
 * Author URI:  https://standardtouch.com
 * License:     GPL2
 * Text Domain: safety-badges-manager
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define Constants
define( 'SBM_VERSION', '1.4.5' );
define( 'SBM_PATH', plugin_dir_path( __FILE__ ) );
define( 'SBM_URL', plugin_dir_url( __FILE__ ) );
define( 'SBM_BASENAME', plugin_basename( __FILE__ ) );

// Include Composer autoloader if available (for Dompdf and other libraries)
if ( file_exists( SBM_PATH . 'vendor/autoload.php' ) ) {
    require_once SBM_PATH . 'vendor/autoload.php';
}

// Include our custom classes
require_once SBM_PATH . 'includes/class-db.php';
require_once SBM_PATH . 'includes/class-gravity-forms.php';
require_once SBM_PATH . 'includes/class-cron.php';
require_once SBM_PATH . 'includes/class-admin.php';
require_once SBM_PATH . 'includes/class-pdf-generator.php';
require_once SBM_PATH . 'includes/class-verification.php';
require_once SBM_PATH . 'includes/class-whitelabel.php';

/**
 * Main Safety Badges Manager Class
 */
class Safety_Badges_Manager {

    /**
     * Instance of this class.
     * @var Safety_Badges_Manager
     */
    private static $instance = null;

    /**
     * DB Handler.
     * @var SBM_DB
     */
    public $db;

    /**
     * Gravity Forms Handler.
     * @var SBM_Gravity_Forms
     */
    public $gravity_forms;

    /**
     * Cron Handler.
     * @var SBM_Cron
     */
    public $cron;

    /**
     * Admin Handler.
     * @var SBM_Admin
     */
    public $admin;

    /**
     * PDF Generator Handler.
     * @var SBM_PDF_Generator
     */
    public $pdf_generator;

    /**
     * Verification Handler.
     * @var SBM_Verification
     */
    public $verification;

    /**
     * Whitelabel Handler.
     * @var SBM_Whitelabel
     */
    public $whitelabel;

    /**
     * Get instance of the class.
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        // Initialize sub-components
        $this->db            = new SBM_DB();
        $this->gravity_forms = new SBM_Gravity_Forms( $this->db );
        $this->cron          = new SBM_Cron( $this->db );
        $this->admin         = new SBM_Admin( $this->db );
        $this->pdf_generator = new SBM_PDF_Generator( $this->db );
        $this->verification  = new SBM_Verification( $this->db );
        $this->whitelabel    = new SBM_Whitelabel();

        // Register hooks
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

        // Initialize plugin logic
        add_action( 'plugins_loaded', array( $this, 'init' ) );
    }

    /**
     * Activation logic.
     */
    public function activate() {
        // 1. Create database tables
        $this->db->create_tables();

        // 2. Setup Cron
        $this->cron->schedule_events();

        // 3. Clear rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Deactivation logic.
     */
    public function deactivate() {
        // Clear Cron schedules
        $this->cron->unschedule_events();
        
        flush_rewrite_rules();
    }

    /**
     * Initialize plugin hooks.
     */
    public function init() {
        // Trigger initialization in components if needed
        $this->gravity_forms->init();
        $this->cron->init();
        $this->admin->init();
        $this->pdf_generator->init();
        $this->verification->init();
        $this->whitelabel->init();
    }
}

// Instantiate the plugin
function SBM() {
    return Safety_Badges_Manager::get_instance();
}
SBM();
