<?php
/**
 * PDF Generator Class using Dompdf
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Dompdf\Dompdf;
use Dompdf\Options;

class SBM_PDF_Generator {

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
     * Initialize PDF generator hooks.
     */
    public function init() {
        add_action( 'admin_post_sbm_print_badges', array( $this, 'generate_bulk_pdf' ) );
    }

    /**
     * Generate bulk PDF of badges.
     */
    public function generate_bulk_pdf() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to print badges.', 'safety-badges-manager' ) );
        }

        // Get badge IDs from query arguments (either array or single value)
        $badge_ids = isset( $_GET['badges'] ) ? (array) $_GET['badges'] : array();
        $badge_ids = array_map( 'intval', $badge_ids );

        if ( empty( $badge_ids ) ) {
            wp_die( esc_html__( 'No badges selected for printing.', 'safety-badges-manager' ) );
        }

        // 1. Group selected badges by user_id to ensure one badge printout per employee
        $user_ids = array();
        foreach ( $badge_ids as $id ) {
            $badge = $this->db->get_badge( $id );
            if ( $badge ) {
                $user_ids[ $badge->user_id ] = true;
            }
        }

        // 2. Fetch all active credentials for each unique employee
        $employees_badges = array();
        foreach ( array_keys( $user_ids ) as $user_id ) {
            $user = get_userdata( $user_id );
            if ( ! $user ) {
                continue;
            }

            // Fetch all badges for this user
            $user_badges = $this->db->get_badges_by_user( $user_id );
            $active_certs = array();

            foreach ( $user_badges as $ub ) {
                if ( 'active' === $ub->status ) {
                    $exam_name = 'Safety Quiz';
                    if ( class_exists( 'GFAPI' ) ) {
                        $form = GFAPI::get_form( $ub->form_id );
                        if ( $form && ! empty( $form['title'] ) ) {
                            $exam_name = $form['title'];
                        }
                    }
                    $ub->exam_name = $exam_name;
                    $active_certs[] = $ub;
                }
            }

            // If employee has at least one active certification, add them to the print queue
            if ( ! empty( $active_certs ) ) {
                // Fetch Iqama and Company metadata
                $iqama   = get_user_meta( $user_id, 'sbm_iqama', true );
                $company = get_user_meta( $user_id, 'sbm_company', true );

                $employees_badges[] = array(
                    'user_display_name' => $user->display_name,
                    'user_login'        => $user->user_login, // EmployeeID
                    'user_email'        => $user->user_email,
                    'iqama'             => $iqama,
                    'company'           => ! empty( $company ) ? $company : 'S-Chem',
                    'avatar_url'        => get_avatar_url( $user_id, array( 'size' => 120 ) ),
                    'certifications'    => $active_certs,
                    'badge_number'      => $active_certs[0]->badge_number,
                );
            }
        }

        if ( empty( $employees_badges ) ) {
            wp_die( esc_html__( 'No active safety certifications found for selected employees.', 'safety-badges-manager' ) );
        }

        // Configure Dompdf options
        $options = new Options();
        $options->set( 'isHtml5ParserEnabled', true );
        $options->set( 'isRemoteEnabled', true ); // Required for loading remote images (QR, avatars)
        $options->set( 'defaultFont', 'Helvetica' );

        $dompdf = new Dompdf( $options );

        // Render HTML layout
        $html = $this->build_badge_sheet_html( $employees_badges );

        $dompdf->loadHtml( $html );
        $dompdf->setPaper( 'A4', 'portrait' );
        $dompdf->render();

        // Output the generated PDF to Browser
        $dompdf->stream( 'safety-badges-' . date( 'Ymd-His' ) . '.pdf', array( 'Attachment' => 0 ) );
        exit;
    }

    /**
     * Build the HTML markup for bulk badge printing.
     * Uses A4 Grid alignment (2 columns x 2 rows per page = 4 badges max per page, size 80mm x 110mm).
     */
    private function build_badge_sheet_html( $employees_badges ) {
        $chunks = array_chunk( $employees_badges, 4 ); // 4 badges per A4 page

        // Determine if local logo file exists to embed in the PDF header
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
        
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <style>
                @page {
                    margin: 15mm;
                }
                body {
                    font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
                    background-color: #ffffff;
                    margin: 0;
                    padding: 0;
                    color: #1e293b;
                }
                .page {
                    page-break-after: always;
                    width: 100%;
                }
                .page:last-child {
                    page-break-after: avoid;
                }
                .badge-grid {
                    width: 100%;
                    border-collapse: collapse;
                }
                .badge-cell {
                    width: 50%;
                    padding: 10px 5px;
                    vertical-align: top;
                }
                
                /* EXACT 80mm x 110mm BADGE */
                .badge {
                    border: 1px solid #0f172a;
                    border-radius: 8px;
                    width: 80mm;
                    height: 110mm;
                    padding: 0;
                    overflow: hidden;
                    background-color: #ffffff;
                    position: relative;
                    box-sizing: border-box;
                    margin: 0 auto;
                }
                
                /* Header block: 8mm height */
                .badge-header {
                    background-color: #0f172a;
                    color: #ffffff;
                    padding: 6px 12px;
                    height: 20px;
                }
                .logo-container {
                    float: left;
                    height: 20px;
                    display: flex;
                    align-items: center;
                }
                .logo-img {
                    height: 18px;
                    max-width: 45mm;
                    vertical-align: middle;
                    margin-top: 1px;
                }
                .logo-text {
                    font-size: 13px;
                    font-weight: bold;
                    letter-spacing: 1px;
                    line-height: 20px;
                }
                .badge-status {
                    float: right;
                    font-size: 8px;
                    font-weight: bold;
                    text-transform: uppercase;
                    background-color: #10b981;
                    padding: 1px 6px;
                    border-radius: 8px;
                    margin-top: 1px;
                }
                
                /* Body: 7mm padding, 22mm employee header, 47mm certifications */
                .badge-body {
                    padding: 10px 12px;
                }
                .employee-section {
                    margin-bottom: 8px;
                    border-bottom: 1px solid #f1f5f9;
                    padding-bottom: 6px;
                    min-height: 20mm;
                }
                .employee-avatar-container {
                    float: right;
                    width: 25%;
                    text-align: right;
                }
                .employee-avatar {
                    width: 13mm;
                    height: 13mm;
                    border-radius: 50%;
                    border: 1.5px solid #cbd5e1;
                }
                .employee-info {
                    float: left;
                    width: 70%;
                    font-size: 9px;
                    line-height: 1.35;
                    color: #475569;
                }
                .employee-name {
                    font-size: 12px;
                    font-weight: bold;
                    margin: 0 0 3px 0;
                    color: #0f172a;
                    white-space: nowrap;
                    overflow: hidden;
                    text-overflow: ellipsis;
                }
                
                /* Certifications Section: 47mm */
                .certs-section {
                    height: 38mm;
                    overflow: hidden;
                }
                .certs-table {
                    width: 100%;
                    border-collapse: collapse;
                }
                .certs-table th {
                    text-align: left;
                    font-size: 8px;
                    color: #64748b;
                    border-bottom: 1px solid #cbd5e1;
                    padding-bottom: 2px;
                    font-weight: bold;
                }
                .certs-table td {
                    font-size: 8px;
                    padding: 3.5px 0;
                    border-bottom: 1px dotted #f1f5f9;
                    color: #1e293b;
                }
                .cert-expiry {
                    text-align: right;
                    font-weight: 500;
                }
                
                /* Footer Section: 24mm height */
                .badge-footer {
                    position: absolute;
                    bottom: 0;
                    left: 0;
                    right: 0;
                    background-color: #f8fafc;
                    border-top: 1px solid #f1f5f9;
                    padding: 6px 12px;
                    height: 24mm;
                    box-sizing: border-box;
                }
                .badge-code-container {
                    float: left;
                    width: 65%;
                    margin-top: 2px;
                }
                .badge-code {
                    font-family: monospace;
                    font-size: 8px;
                    font-weight: bold;
                    color: #475569;
                }
                .badge-verif-lbl {
                    font-size: 7px;
                    color: #94a3b8;
                    margin-top: 3px;
                    line-height: 1.2;
                }
                .badge-qr-container {
                    float: right;
                    width: 30%;
                    text-align: right;
                }
                .qr-code {
                    width: 17mm;
                    height: 17mm;
                    border: 1px solid #e2e8f0;
                    padding: 1px;
                    border-radius: 4px;
                    margin-top: -3px;
                }
                .clear {
                    clear: both;
                }
            </style>
        </head>
        <body>
            <?php foreach ( $chunks as $page_badges ) : ?>
                <div class="page">
                    <table class="badge-grid">
                        <?php 
                        // Split into rows of 2 columns
                        $rows = array_chunk( $page_badges, 2 );
                        foreach ( $rows as $row ) :
                        ?>
                            <tr>
                                <?php foreach ( $row as $emp ) : 
                                    $qr_data = esc_url( home_url( '/verify-badge/?code=' . urlencode( $emp['badge_number'] ) ) );
                                    $qr_url  = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=' . urlencode( $qr_data );
                                    ?>
                                    <td class="badge-cell">
                                        <div class="badge">
                                            <!-- Badge Top Banner -->
                                            <div class="badge-header">
                                                <div class="logo-container">
                                                    <?php if ( ! empty( $logo_img_src ) ) : ?>
                                                        <img class="logo-img" src="<?php echo esc_attr( $logo_img_src ); ?>" alt="Logo" />
                                                    <?php else : ?>
                                                        <div class="logo-text">S-CHEM</div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="badge-status">Certified</div>
                                                <div class="clear"></div>
                                            </div>

                                            <!-- Badge Core Body -->
                                            <div class="badge-body">
                                                <!-- Employee section: Name & Avatar & Metadata -->
                                                <div class="employee-section">
                                                    <div class="employee-avatar-container">
                                                        <img class="employee-avatar" src="<?php echo esc_url( $emp['avatar_url'] ); ?>" alt="Avatar" />
                                                    </div>
                                                    <div class="employee-info">
                                                        <div class="employee-name"><?php echo esc_html( $emp['user_display_name'] ); ?></div>
                                                        <div><span style="font-weight: bold; color: #64748b;">ID:</span> <?php echo esc_html( $emp['user_login'] ); ?></div>
                                                        <div style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><span style="font-weight: bold; color: #64748b;">Company:</span> <?php echo esc_html( $emp['company'] ); ?></div>
                                                        <?php if ( ! empty( $emp['iqama'] ) ) : ?>
                                                            <div><span style="font-weight: bold; color: #64748b;">Iqama:</span> <?php echo esc_html( $emp['iqama'] ); ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="clear"></div>
                                                </div>
                                                
                                                <!-- List of Certifications -->
                                                <div class="certs-section">
                                                    <table class="certs-table">
                                                        <thead>
                                                            <tr>
                                                                <th>Assessment</th>
                                                                <th style="text-align: right;">Expires</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php 
                                                            // Limit to max 3 certifications to fit vertical card space
                                                            $display_certs = array_slice( $emp['certifications'], 0, 3 );
                                                            foreach ( $display_certs as $cert ) : 
                                                            ?>
                                                                <tr>
                                                                    <td style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100px;">
                                                                        <?php echo esc_html( $cert->exam_name ); ?>
                                                                    </td>
                                                                    <td class="cert-expiry">
                                                                        <?php echo date('d M Y', strtotime( $cert->expiry_date ) ); ?>
                                                                    </td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                            <?php if ( count( $emp['certifications'] ) > 3 ) : ?>
                                                                <tr>
                                                                    <td colspan="2" style="font-style: italic; color: #64748b; font-size: 7px; text-align: center; border-bottom: 0;">
                                                                        + <?php echo count( $emp['certifications'] ) - 3; ?> more (Scan QR)
                                                                    </td>
                                                                </tr>
                                                            <?php endif; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>

                                            <!-- Badge Footer (Verification info & QR Code) -->
                                            <div class="badge-footer">
                                                <div class="badge-code-container">
                                                    <div class="badge-code">ID: <?php echo esc_html( $emp['badge_number'] ); ?></div>
                                                    <div class="badge-verif-lbl">Scan QR code for validation portal.</div>
                                                </div>
                                                <div class="badge-qr-container">
                                                    <img class="qr-code" src="<?php echo esc_url( $qr_url ); ?>" alt="QR" />
                                                </div>
                                                <div class="clear"></div>
                                            </div>
                                        </div>
                                    </td>
                                <?php endforeach; ?>
                                <?php if ( count( $row ) === 1 ) : ?>
                                    <!-- Empty column to balance row -->
                                    <td class="badge-cell"></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            <?php endforeach; ?>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
}
