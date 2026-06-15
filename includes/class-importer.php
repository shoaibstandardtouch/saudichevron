<?php
/**
 * Bulk Employee Importer Class
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SBM_Importer {

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
     * Initialize importer hooks.
     */
    public function init() {
        add_action( 'admin_init', array( $this, 'handle_template_download' ) );
    }

    /**
     * Download CSV Import template format.
     */
    public function handle_template_download() {
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'sbm_download_import_template' ) {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'You do not have permission to download templates.', 'safety-badges-manager' ) );
            }

            header( 'Content-Type: text/csv; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename=employee-bulk-import-template.csv' );

            $output = fopen( 'php://output', 'w' );
            
            // Header Row
            fputcsv( $output, array( 'Name of Employee', 'Iqama', 'Company', 'EmployeeID', 'password', 'Email' ) );
            // Sample Rows
            fputcsv( $output, array( 'Ahmad Al-Saeed', '1029384756', 'S-Chem Partner', 'EMP5001', 'PassSecure99', 'ahmad@s-chem.com' ) );
            fputcsv( $output, array( 'Ali Al-Hassan', '2039485761', 'Logistics Contracting', 'EMP5002', '', '' ) ); // Password & Email left blank
            
            fclose( $output );
            exit;
        }
    }

    /**
     * Process bulk employee import.
     */
    public function process_import_upload() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized user.', 'safety-badges-manager' ) );
        }

        if ( empty( $_FILES['sbm_csv_file']['tmp_name'] ) ) {
            return '<div class="notice notice-error"><p>' . esc_html__( 'Please select a valid CSV file to upload.', 'safety-badges-manager' ) . '</p></div>';
        }

        $file_path = $_FILES['sbm_csv_file']['tmp_name'];
        $handle    = fopen( $file_path, 'r' );

        if ( ! $handle ) {
            return '<div class="notice notice-error"><p>' . esc_html__( 'Error: Could not open the uploaded CSV file.', 'safety-badges-manager' ) . '</p></div>';
        }

        // 1. Read headers and normalize
        $headers = fgetcsv( $handle );
        if ( ! $headers ) {
            fclose( $handle );
            return '<div class="notice notice-error"><p>' . esc_html__( 'CSV file is empty.', 'safety-badges-manager' ) . '</p></div>';
        }

        $headers = array_map( function( $header ) {
            return trim( strtolower( $header ) );
        }, $headers );

        // Find matches for column positions
        $name_idx     = $this->find_column_index( $headers, array( 'name of employee', 'name', 'employee name' ) );
        $iqama_idx    = $this->find_column_index( $headers, array( 'iqama', 'iqama number', 'iqama_no' ) );
        $company_idx  = $this->find_column_index( $headers, array( 'company', 'employer' ) );
        $empid_idx    = $this->find_column_index( $headers, array( 'employeeid', 'employee id', 'username', 'user_login' ) );
        $password_idx = $this->find_column_index( $headers, array( 'password', 'pass' ) );
        $email_idx    = $this->find_column_index( $headers, array( 'email', 'email address' ) );

        if ( $empid_idx === false || $name_idx === false ) {
            fclose( $handle );
            return '<div class="notice notice-error"><p>' . esc_html__( 'CSV header must include at least "EmployeeID" and "Name of Employee" columns.', 'safety-badges-manager' ) . '</p></div>';
        }

        $imported = 0;
        $updated  = 0;
        $errors   = array();
        $row_count = 1;

        // 2. Loop through row items
        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            $row_count++;

            $employee_id = isset( $row[ $empid_idx ] ) ? trim( $row[ $empid_idx ] ) : '';
            $name        = isset( $row[ $name_idx ] ) ? trim( $row[ $name_idx ] ) : '';
            $iqama       = ( $iqama_idx !== false && isset( $row[ $iqama_idx ] ) ) ? trim( $row[ $iqama_idx ] ) : '';
            $company     = ( $company_idx !== false && isset( $row[ $company_idx ] ) ) ? trim( $row[ $company_idx ] ) : '';
            
            // Password defaults to username/employee_id if empty
            $password    = ( $password_idx !== false && ! empty( $row[ $password_idx ] ) ) ? trim( $row[ $password_idx ] ) : $employee_id;
            
            // Email defaults to employee_id@gmail.com if empty
            $email       = ( $email_idx !== false && ! empty( $row[ $email_idx ] ) ) ? trim( $row[ $email_idx ] ) : $employee_id . '@gmail.com';

            if ( empty( $employee_id ) || empty( $name ) ) {
                $errors[] = sprintf( esc_html__( 'Row %d: Missing Username (EmployeeID) or display Name.', 'safety-badges-manager' ), $row_count );
                continue;
            }

            // Check if username/EmployeeID exists
            $user_id = username_exists( $employee_id );

            if ( $user_id ) {
                // Update display name of existing user
                wp_update_user( array(
                    'ID'           => $user_id,
                    'display_name' => $name
                ) );
                $updated++;
            } else {
                // Insert new employee user
                $user_data = array(
                    'user_login'   => $employee_id,
                    'user_pass'    => $password,
                    'user_email'   => $email,
                    'display_name' => $name,
                    'role'         => 'subscriber' // standard employee role
                );

                $user_id = wp_insert_user( $user_data );

                if ( is_wp_error( $user_id ) ) {
                    $errors[] = sprintf( esc_html__( 'Row %d (%s): %s', 'safety-badges-manager' ), $row_count, esc_html( $employee_id ), $user_id->get_error_message() );
                    continue;
                }
                $imported++;
            }

            // Update employee metadata parameters
            if ( ! empty( $iqama ) ) {
                update_user_meta( $user_id, 'sbm_iqama', $iqama );
            }
            if ( ! empty( $company ) ) {
                update_user_meta( $user_id, 'sbm_company', $company );
            }
        }

        fclose( $handle );

        // 3. Compile output notices
        $output = '';
        if ( $imported > 0 ) {
            $output .= '<div class="notice notice-success"><p>' . sprintf( esc_html__( 'Successfully imported %d new employees.', 'safety-badges-manager' ), $imported ) . '</p></div>';
        }
        if ( $updated > 0 ) {
            $output .= '<div class="notice notice-info"><p>' . sprintf( esc_html__( 'Successfully updated %d existing employee accounts.', 'safety-badges-manager' ), $updated ) . '</p></div>';
        }
        if ( ! empty( $errors ) ) {
            $output .= '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Some rows encountered issues:', 'safety-badges-manager' ) . '</strong><br>' . implode( '<br>', $errors ) . '</p></div>';
        }

        return $output;
    }

    /**
     * Find column header index inside the row.
     */
    private function find_column_index( $headers, $needles ) {
        foreach ( $needles as $needle ) {
            $idx = array_search( $needle, $headers );
            if ( $idx !== false ) {
                return $idx;
            }
        }
        return false;
    }

    /**
     * Output bulk importer admin page layout.
     */
    public function render_import_page() {
        $message = '';
        if ( isset( $_POST['sbm_submit_import'] ) ) {
            check_admin_referer( 'sbm_csv_import_nonce', 'sbm_csv_import_field' );
            $message = $this->process_import_upload();
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Bulk Import Employees', 'safety-badges-manager' ); ?></h1>
            <hr class="wp-header-end" style="margin-bottom: 20px;">

            <?php if ( ! empty( $message ) ) echo $message; ?>

            <div class="sbm-card" style="max-width: 650px; background: #fff; padding: 25px; border-radius: 8px; border: 1px solid #ccd0d4;">
                <h3 style="margin-top: 0;"><?php esc_html_e( 'Import Instructions', 'safety-badges-manager' ); ?></h3>
                <p><?php esc_html_e( 'Please download the template and structure your spreadsheet values with the exact columns. When saving from Microsoft Excel, select Save As and select the comma-separated format (.csv).', 'safety-badges-manager' ); ?></p>
                
                <p style="margin: 20px 0;">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=safety-import&action=sbm_download_import_template' ) ); ?>" class="button button-secondary">
                        <span class="dashicons dashicons-download" style="vertical-align: middle; margin-top: -3px; font-size: 16px;"></span>
                        <?php esc_html_e( 'Download CSV Template', 'safety-badges-manager' ); ?>
                    </a>
                </p>

                <hr style="border: 0; border-top: 1px solid #eee; margin: 25px 0;">

                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field( 'sbm_csv_import_nonce', 'sbm_csv_import_field' ); ?>
                    
                    <table class="form-table" style="margin-bottom: 20px;">
                        <tr>
                            <th scope="row"><label for="sbm_csv_file"><?php esc_html_e( 'Upload CSV File', 'safety-badges-manager' ); ?></label></th>
                            <td>
                                <input type="file" id="sbm_csv_file" name="sbm_csv_file" accept=".csv" required />
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <input type="submit" name="sbm_submit_import" class="button button-primary" value="<?php esc_attr_e( 'Start Bulk Import', 'safety-badges-manager' ); ?>" />
                    </p>
                </form>
            </div>
        </div>
        <?php
    }
}
