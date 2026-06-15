<?php
/**
 * Frontend Badge Verification Class
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SBM_Verification {

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
     * Initialize verification hooks.
     */
    public function init() {
        // Intercept template loading to capture verification requests
        add_action( 'template_redirect', array( $this, 'intercept_verification_request' ) );
    }

    /**
     * Intercept the page request to capture `/verify-badge/` virtual path.
     */
    public function intercept_verification_request() {
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
        $path = parse_url( $request_uri, PHP_URL_PATH );
        $path = trim( $path, '/' );

        // Clean path check (matches '/verify-badge' or '/verify-badge/')
        if ( $path === 'verify-badge' ) {
            $code = isset( $_GET['code'] ) ? sanitize_text_field( $_GET['code'] ) : '';
            $this->render_verification_page( $code );
            exit;
        }
    }

    /**
     * Render the frontend verification UI page.
     */
    private function render_verification_page( $code ) {
        $badge = null;
        $user  = null;
        $error = '';
        $active_certs  = array();
        $expired_certs = array();
        $revoked_certs = array();

        if ( ! empty( $code ) ) {
            $badge = $this->db->get_badge_by_code( $code );
            if ( $badge ) {
                $user = get_userdata( $badge->user_id );
                
                // Fetch all certifications for this employee
                $all_badges = $this->db->get_badges_by_user( $badge->user_id );
                
                foreach ( $all_badges as $b ) {
                    // Fetch gravity forms title
                    $exam_name = 'Safety Exam';
                    if ( class_exists( 'GFAPI' ) ) {
                        $form = GFAPI::get_form( $b->form_id );
                        if ( $form && ! empty( $form['title'] ) ) {
                            $exam_name = $form['title'];
                        }
                    }
                    $b->exam_name = $exam_name;
                    
                    if ( 'active' === $b->status ) {
                        $active_certs[] = $b;
                    } elseif ( 'expired' === $b->status ) {
                        $expired_certs[] = $b;
                    } elseif ( 'revoked' === $b->status ) {
                        $revoked_certs[] = $b;
                    }
                }
            } else {
                $error = 'No badge records matching this code could be found.';
            }
        } else {
            $error = 'No badge code specified. Please scan a valid QR code.';
        }

        // Overall compliance state
        $is_compliant = ! empty( $active_certs ) && empty( $expired_certs );
        $overall_status = 'non-compliant';
        if ( ! empty( $active_certs ) ) {
            $overall_status = $is_compliant ? 'compliant' : 'partial';
        }
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Employee Safety Verification</title>
            <style>
                body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
                    background-color: #f1f5f9;
                    margin: 0;
                    padding: 0;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    min-height: 100vh;
                    color: #1e293b;
                }
                .container {
                    width: 100%;
                    max-width: 480px;
                    padding: 20px;
                }
                .card {
                    background-color: #ffffff;
                    border-radius: 16px;
                    box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.05);
                    padding: 35px 25px;
                    text-align: center;
                    border: 1px solid #e2e8f0;
                }
                .header-logo {
                    font-size: 24px;
                    font-weight: 800;
                    color: #0f172a;
                    margin-bottom: 25px;
                    letter-spacing: 1.5px;
                }
                
                /* Compliance status banners */
                .status-banner {
                    border-radius: 8px;
                    padding: 15px;
                    margin-bottom: 25px;
                    font-weight: bold;
                    font-size: 16px;
                    text-transform: uppercase;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    gap: 10px;
                }
                .status-banner.compliant {
                    background-color: #d1fae5;
                    color: #065f46;
                    border: 1px solid #a7f3d0;
                }
                .status-banner.partial {
                    background-color: #fffbeb;
                    color: #92400e;
                    border: 1px solid #fde68a;
                }
                .status-banner.non-compliant {
                    background-color: #fee2e2;
                    color: #991b1b;
                    border: 1px solid #fca5a5;
                }
                
                .employee-profile {
                    display: flex;
                    align-items: center;
                    gap: 15px;
                    text-align: left;
                    margin-bottom: 25px;
                    border-bottom: 1px solid #e2e8f0;
                    padding-bottom: 20px;
                }
                .avatar {
                    border-radius: 50%;
                    background: #e2e8f0;
                    width: 60px;
                    height: 60px;
                }
                .emp-name {
                    font-size: 18px;
                    font-weight: bold;
                    color: #0f172a;
                    margin: 0;
                }
                .emp-email {
                    font-size: 13px;
                    color: #64748b;
                    margin: 2px 0 0 0;
                }

                /* Section styling */
                .section-title {
                    font-size: 13px;
                    font-weight: bold;
                    color: #475569;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                    text-align: left;
                    margin: 20px 0 10px 0;
                }

                /* Certifications items list */
                .cert-item {
                    background-color: #f8fafc;
                    border-radius: 8px;
                    border: 1px solid #e2e8f0;
                    padding: 12px 15px;
                    margin-bottom: 10px;
                    text-align: left;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                }
                .cert-name {
                    font-weight: 600;
                    font-size: 14px;
                    color: #1e293b;
                    max-width: 70%;
                    white-space: nowrap;
                    overflow: hidden;
                    text-overflow: ellipsis;
                }
                .cert-date {
                    font-size: 11px;
                    color: #64748b;
                    margin-top: 3px;
                }
                .cert-status-tag {
                    font-size: 9px;
                    font-weight: bold;
                    text-transform: uppercase;
                    padding: 2px 8px;
                    border-radius: 10px;
                }
                .cert-status-tag.active {
                    background-color: #d1fae5;
                    color: #065f46;
                }
                .cert-status-tag.expired {
                    background-color: #fee2e2;
                    color: #991b1b;
                }
                .cert-status-tag.revoked {
                    background-color: #fef3c7;
                    color: #92400e;
                }

                .footer-text {
                    font-size: 11px;
                    color: #94a3b8;
                    margin-top: 25px;
                }
                svg {
                    width: 20px;
                    height: 20px;
                    fill: currentColor;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="card">
                    <div class="header-logo">S-CHEM SAFETY</div>
                    
                    <?php if ( $badge ) : ?>
                        <!-- Status Banner -->
                        <?php if ( 'compliant' === $overall_status ) : ?>
                            <div class="status-banner compliant">
                                <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
                                Fully Compliant
                            </div>
                        <?php elseif ( 'partial' === $overall_status ) : ?>
                            <div class="status-banner partial">
                                <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
                                Action Required (Partial Expiry)
                            </div>
                        <?php else : ?>
                            <div class="status-banner non-compliant">
                                <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
                                Non-Compliant / Expired
                            </div>
                        <?php endif; ?>

                        <!-- Employee Info -->
                        <div class="employee-profile">
                            <img class="avatar" src="<?php echo esc_url( get_avatar_url( $badge->user_id, array( 'size' => 120 ) ) ); ?>" alt="Avatar">
                            <div>
                                <h3 class="emp-name"><?php echo esc_html( $user ? $user->display_name : 'Unknown User' ); ?></h3>
                                <p class="emp-email"><?php echo esc_html( $user ? $user->user_email : '' ); ?></p>
                            </div>
                        </div>

                        <!-- Active Certifications -->
                        <div class="section-title">Active Certifications (<?php echo count( $active_certs ); ?>)</div>
                        <?php if ( empty( $active_certs ) ) : ?>
                            <p style="text-align: left; font-size: 13px; color: #64748b; margin-left: 15px;">No active safety credentials found.</p>
                        <?php else : ?>
                            <?php foreach ( $active_certs as $cert ) : ?>
                                <div class="cert-item">
                                    <div>
                                        <div class="cert-name"><?php echo esc_html( $cert->exam_name ); ?></div>
                                        <div class="cert-date">Expires: <?php echo date('d M Y', strtotime( $cert->expiry_date ) ); ?></div>
                                    </div>
                                    <span class="cert-status-tag active">Valid</span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <!-- Expired / Revoked -->
                        <?php if ( ! empty( $expired_certs ) || ! empty( $revoked_certs ) ) : ?>
                            <div class="section-title">Lapsed Credentials (<?php echo ( count( $expired_certs ) + count( $revoked_certs ) ); ?>)</div>
                            
                            <?php foreach ( $expired_certs as $cert ) : ?>
                                <div class="cert-item" style="border-left: 4px solid #dc2626;">
                                    <div>
                                        <div class="cert-name" style="color: #64748b;"><?php echo esc_html( $cert->exam_name ); ?></div>
                                        <div class="cert-date" style="color: #ef4444;">Expired: <?php echo date('d M Y', strtotime( $cert->expiry_date ) ); ?></div>
                                    </div>
                                    <span class="cert-status-tag expired">Expired</span>
                                </div>
                            <?php endforeach; ?>

                            <?php foreach ( $revoked_certs as $cert ) : ?>
                                <div class="cert-item" style="border-left: 4px solid #d97706;">
                                    <div>
                                        <div class="cert-name" style="color: #64748b;"><?php echo esc_html( $cert->exam_name ); ?></div>
                                        <div class="cert-date">Revoked</div>
                                    </div>
                                    <span class="cert-status-tag revoked">Revoked</span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                    <?php else : ?>
                        <!-- ERROR / INVALID BADGE -->
                        <div class="status-banner non-compliant" style="font-size: 14px; text-transform: none; margin-bottom: 20px;">
                            <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
                            <?php echo esc_html( ! empty( $error ) ? $error : 'The scanned certificate link is invalid.' ); ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="footer-text">Official S-Chem Verification Portal</div>
                </div>
            </div>
        </body>
        </html>
        <?php
    }
}
