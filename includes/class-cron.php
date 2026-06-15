<?php
/**
 * Cron and Automated Expiry Handler Class
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SBM_Cron {

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
     * Initialize cron hooks.
     */
    public function init() {
        add_action( 'safety_badge_daily_expiry_check', array( $this, 'run_expiry_checks' ) );
        add_action( 'sbm_badge_created', array( $this, 'send_badge_created_email' ), 10, 3 );
    }

    /**
     * Schedule the daily cron event.
     */
    public function schedule_events() {
        if ( ! wp_next_scheduled( 'safety_badge_daily_expiry_check' ) ) {
            wp_schedule_event( time(), 'daily', 'safety_badge_daily_expiry_check' );
        }
    }

    /**
     * Clear the scheduled cron event.
     */
    public function unschedule_events() {
        $timestamp = wp_next_scheduled( 'safety_badge_daily_expiry_check' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'safety_badge_daily_expiry_check' );
        }
    }

    /**
     * Run daily checks for expired badges and upcoming expiry warnings.
     */
    public function run_expiry_checks() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'safety_badges';
        $now = current_time( 'mysql' );

        // 1. Process actual expiries
        $expired_badges = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table_name WHERE status = 'active' AND expiry_date <= %s",
            $now
        ) );

        foreach ( $expired_badges as $badge ) {
            $this->db->update_badge_status( $badge->id, 'expired' );
            $this->send_badge_expired_email( $badge );
            
            // Fire hook for reporting/audit logs
            do_action( 'sbm_badge_expired', $badge->id, $badge->user_id, $badge->badge_number );
        }

        // 2. Process warning notifications (e.g. 30 days before expiry)
        $active_badges = $wpdb->get_results(
            "SELECT * FROM $table_name WHERE status = 'active'"
        );

        foreach ( $active_badges as $badge ) {
            // Fetch Gravity Forms form settings for sbm_notification_days
            $notification_days = 30; // Fallback default
            if ( class_exists( 'GFAPI' ) ) {
                $form = GFAPI::get_form( $badge->form_id );
                if ( $form && isset( $form['sbm_notification_days'] ) && $form['sbm_notification_days'] !== '' ) {
                    $notification_days = intval( $form['sbm_notification_days'] );
                }
            }

            $expiry_timestamp = strtotime( $badge->expiry_date );
            $warning_timestamp = $expiry_timestamp - ( $notification_days * DAY_IN_SECONDS );
            $current_timestamp = current_time( 'timestamp' );

            // If we are within the warning threshold and haven't expired yet
            if ( $current_timestamp >= $warning_timestamp && $current_timestamp < $expiry_timestamp ) {
                $meta_key = 'sbm_warning_sent_' . $badge->id;
                $already_sent = get_user_meta( $badge->user_id, $meta_key, true );

                if ( ! $already_sent ) {
                    $this->send_badge_warning_email( $badge, $notification_days );
                    update_user_meta( $badge->user_id, $meta_key, 1 );
                }
            }
        }
    }

    /**
     * Send email notification when a badge is created.
     */
    public function send_badge_created_email( $badge_id, $user_id, $badge_number ) {
        $user  = get_userdata( $user_id );
        $badge = $this->db->get_badge( $badge_id );
        if ( ! $user || ! $badge ) {
            return;
        }

        $subject = 'Congratulations! You Passed your Safety Certification';
        
        $body = $this->get_email_template(
            'Certification Passed',
            sprintf( 'Hello %s,', esc_html( $user->display_name ) ),
            sprintf( 
                'Congratulations! You have successfully passed the safety assessment. Your safety badge has been generated and is now active.<br><br>' .
                '<strong>Badge Details:</strong><br>' .
                '<ul>' .
                '<li><strong>Badge Number:</strong> %s</li>' .
                '<li><strong>Issue Date:</strong> %s</li>' .
                '<li><strong>Expiry Date:</strong> %s</li>' .
                '</ul>' .
                'Please keep this certification details safe. You can view your credentials or request a print-out from your safety administrator.',
                esc_html( $badge->badge_number ),
                date_i18n( get_option( 'date_format' ), strtotime( $badge->pass_date ) ),
                date_i18n( get_option( 'date_format' ), strtotime( $badge->expiry_date ) )
            ),
            'Go to Dashboard',
            home_url( '/dashboard/' )
        );

        $this->send_html_email( $user->user_email, $subject, $body );
    }

    /**
     * Send email notification when a badge expires.
     */
    private function send_badge_expired_email( $badge ) {
        $user = get_userdata( $badge->user_id );
        if ( ! $user ) {
            return;
        }

        $subject = 'Action Required: Your Safety Certification Has Expired';
        
        $body = $this->get_email_template(
            'Certification Expired',
            sprintf( 'Hello %s,', esc_html( $user->display_name ) ),
            sprintf( 
                'Your safety certificate <strong>%s</strong> has expired as of %s.<br><br>' .
                'To maintain site access compliance, you must retake the safety assessment as soon as possible.',
                esc_html( $badge->badge_number ),
                date_i18n( get_option( 'date_format' ), strtotime( $badge->expiry_date ) )
            ),
            'Retake Quiz Now',
            home_url( '/safety-quiz/' )
        );

        $this->send_html_email( $user->user_email, $subject, $body );
    }

    /**
     * Send upcoming expiry warning email.
     */
    private function send_badge_warning_email( $badge, $days ) {
        $user = get_userdata( $badge->user_id );
        if ( ! $user ) {
            return;
        }

        $subject = sprintf( 'Reminder: Your Safety Certification Expires in %d Days', $days );
        
        $body = $this->get_email_template(
            'Certification Expiry Warning',
            sprintf( 'Hello %s,', esc_html( $user->display_name ) ),
            sprintf( 
                'This is a friendly reminder that your safety certificate <strong>%s</strong> is scheduled to expire in <strong>%d days</strong> (on %s).<br><br>' .
                'Please schedule some time to retake the safety assessment before this date to prevent certificate lapse and ensure uninterrupted compliance.',
                esc_html( $badge->badge_number ),
                $days,
                date_i18n( get_option( 'date_format' ), strtotime( $badge->expiry_date ) )
            ),
            'Renew Certification',
            home_url( '/safety-quiz/' )
        );

        $this->send_html_email( $user->user_email, $subject, $body );
    }

    /**
     * Standard HTML Email Template Wrapper.
     */
    private function get_email_template( $title, $greeting, $message, $btn_text = '', $btn_url = '' ) {
        $logo_url = SBM_URL . 'assets/schem-logo.png'; // Fallback link
        
        $button_html = '';
        if ( ! empty( $btn_text ) && ! empty( $btn_url ) ) {
            $button_html = '
                <tr>
                    <td align="center" style="padding: 20px 0;">
                        <a href="' . esc_url( $btn_url ) . '" style="background-color: #0d47a1; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 4px; font-weight: bold; display: inline-block;">' . esc_html( $btn_text ) . '</a>
                    </td>
                </tr>';
        }

        return '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>' . esc_html( $title ) . '</title>
        </head>
        <body style="background-color: #f4f6f9; font-family: Arial, sans-serif; margin: 0; padding: 40px 0;">
            <table width="100%" border="0" cellspacing="0" cellpadding="0">
                <tr>
                    <td align="center">
                        <table width="600" border="0" cellspacing="0" cellpadding="0" style="background-color: #ffffff; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); overflow: hidden; border: 1px solid #e1e4e8;">
                            <!-- Header -->
                            <tr style="background-color: #0d47a1;">
                                <td style="padding: 30px; text-align: center;">
                                    <h1 style="color: #ffffff; margin: 0; font-size: 24px; font-weight: 600;">S-Chem Safety Training</h1>
                                </td>
                            </tr>
                            <!-- Body -->
                            <tr>
                                <td style="padding: 40px 30px; color: #333333; line-height: 1.6; font-size: 16px;">
                                    <h3 style="margin-top: 0; color: #111111;">' . $greeting . '</h3>
                                    <p style="margin-bottom: 25px;">' . $message . '</p>
                                    ' . $button_html . '
                                </td>
                            </tr>
                            <!-- Footer -->
                            <tr style="background-color: #f8f9fa; border-top: 1px solid #e1e4e8;">
                                <td style="padding: 20px 30px; text-align: center; font-size: 12px; color: #6c757d;">
                                    <p style="margin: 0 0 5px 0;">&copy; ' . date('Y') . ' S-Chem. All rights reserved.</p>
                                    <p style="margin: 0;">This is an automated safety system notification.</p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>';
    }

    /**
     * Send HTML mail helper.
     */
    private function send_html_email( $to, $subject, $body ) {
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: S-Chem Safety Training <no-reply@' . parse_url( home_url(), PHP_URL_HOST ) . '>'
        );
        return wp_mail( $to, $subject, $body, $headers );
    }
}
