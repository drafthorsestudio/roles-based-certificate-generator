<?php
/*
Plugin Name: Course Certificate Generator
Plugin URI: https://drafthorsestudio.com/plugins/
Description: Generate and email course completion certificates for custom post type "training".
Version: 1.6 - Added admin settings page for role mappings and configuration
Author: Adam Murray
Author URI: https://drafthorsestudio.com/
License: GPL2
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

function log_memory_usage($location) {
    $memory_usage = memory_get_usage(true);
    $memory_peak = memory_get_peak_usage(true);
    error_log("Memory at {$location}: Current=" . size_format($memory_usage) . ", Peak=" . size_format($memory_peak));
}

// Get plugin settings with defaults
function certificate_generator_get_settings() {
    $defaults = array(
        'role_group_mappings' => array(),
        'taxonomy_for_filtering' => 'group',
        'enable_network_filtering' => true,
    );
    
    $settings = get_option('certificate_generator_settings', $defaults);
    
    // Ensure defaults are set if option exists but is missing keys
    return wp_parse_args($settings, $defaults);
}

// Get role to group mappings
function certificate_generator_get_role_mappings() {
    $settings = certificate_generator_get_settings();
    return $settings['role_group_mappings'];
}

// Include FPDF library
function course_certificate_include_fpdf() {
    require_once plugin_dir_path(__FILE__) . 'includes/fpdf.php';
}
add_action('plugins_loaded', 'course_certificate_include_fpdf');

// Generate PDF certificate
function generate_course_certificate($name, $course_name, $course_id, $attendee_hours = '', $attendee_credentials = '', $attendee_ches = '') {

    log_memory_usage('start_certificate_generation');

    // Clear any existing FPDF instances
    if (isset($pdf)) {
        unset($pdf);
    }
    
    // Force garbage collection before creating PDF
    if (function_exists('gc_collect_cycles')) {
        gc_collect_cycles();
    }

	//get fields
	$course_name = str_replace("&#8217;", "'", $course_name);
    $page_width = 279.4;
	$text_limit = 225;
    $page_height = 215.9;

    $certificate_misc_text = get_field('certificate_misc_text', $course_id);
    $training_date = get_field('training_date', $course_id); // February 8, 2025
    $date_object = DateTime::createFromFormat('F j, Y', $training_date);
    if ($date_object) {
        $formatted_date = $date_object->format('Ymd');  // Format as "20250208"
    } else {
        $formatted_date = '';
    }

    // Get certificate Y positions
    $name_position = get_field('certificate_name_position', $course_id) ?: 105; // Default if not set
    $course_position = get_field('certificate_course_position', $course_id) ?: 128; // Default if not set
    $date_position = get_field('certificate_date_position', $course_id) ?: 138; // Default if not set

    $credentials_position = get_field('certificate_credentials_position', $course_id) ?: 146; // Default if not set
    $ches_position = get_field('certificate_ches_position', $course_id) ?: 153; // Default if not set
    $hours_position = get_field('certificate_hours_position', $course_id) ?: 160; // Default if not set
    $misc_text_position = get_field('certificate_misc_text_position', $course_id) ?: 180; // Default if not set
    
    $certificate_background = get_field('certificate_background', $course_id);

    course_certificate_include_fpdf();
    
    $pdf = new FPDF('L', 'mm', 'Letter');
	$pdf->SetLeftMargin(15);
	$pdf->SetRightMargin(15);
    $pdf->AddPage();

    // FIXED: Better background image handling
    if (!empty($certificate_background)) {
        // Handle both URL and local path formats
        if (is_array($certificate_background)) {
            // ACF returns array format
            $image_url = $certificate_background['url'];
            $image_path = isset($certificate_background['path']) ? $certificate_background['path'] : '';
        } else {
            // ACF returns URL string
            $image_url = $certificate_background;
            $image_path = '';
        }
        
        // Try local path first (faster), then URL
        if (!empty($image_path) && file_exists($image_path) && is_readable($image_path)) {
            $pdf->Image($image_path, 0, 0, $page_width, $page_height);
            error_log('Certificate background added from local path: ' . $image_path);
        } elseif (!empty($image_url)) {
            // Convert URL to local path if it's a WordPress upload
            $upload_dir = wp_upload_dir();
            $local_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $image_url);
            
            if (file_exists($local_path) && is_readable($local_path)) {
                $pdf->Image($local_path, 0, 0, $page_width, $page_height);
                error_log('Certificate background added from converted URL path: ' . $local_path);
            } else {
                // Fallback to URL (requires allow_url_fopen)
                if (ini_get('allow_url_fopen')) {
                    $pdf->Image($image_url, 0, 0, $page_width, $page_height);
                    error_log('Certificate background added from URL: ' . $image_url);
                } else {
                    error_log('Certificate background image not accessible and allow_url_fopen disabled: ' . $image_url);
                }
            }
        } else {
            error_log('Certificate background field is empty or invalid');
        }
    }

    $pdf->SetFont('Arial', 'B', 24);
    $pdf->SetXY(0, $name_position);
    $pdf->Cell($page_width, 0, $name, 0, 1, 'C');

    $pdf->SetFont('Arial', '', 16);
    $pdf->SetXY(15, $course_position);  // This sets X to 15mm (respecting left margin)
    //$pdf->Cell($page_width, 0, $course_name, 0, 1, 'C');
    $pdf->MultiCell($text_limit, 6, !empty($course_name) ? $course_name : "", 0, 'C', false);

    $pdf->SetXY(0, $credentials_position);
    $pdf->SetFont('Arial', '', 16);
    $pdf->Cell($page_width, 0, $attendee_credentials, 0, 1, 'C');

    $pdf->SetXY(0, $ches_position);
    $pdf->SetFont('Arial', '', 16);
    $pdf->Cell($page_width, 0, !empty($attendee_ches) ? "CHES #: ".$attendee_ches : "", 0, 1, 'C');

    $pdf->SetXY(0, $hours_position);
    $pdf->SetFont('Arial', '', 16);
    $pdf->Cell($page_width, 0, !empty($attendee_hours) ? "CE Hours: ".$attendee_hours : "", 0, 1, 'C');

    $pdf->SetXY(0, $misc_text_position);
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell($page_width, 0, !empty($certificate_misc_text) ? $certificate_misc_text : "", 0, 1, 'C');

    $pdf->SetXY(0, $date_position);
    $pdf->SetFont('Arial', '', 16);
    $pdf->Cell($page_width, 0, $training_date, 0, 1, 'C');

    
    // Get the post publish date to determine the correct upload directory
    $post = get_post($course_id);
    $post_date = $post->post_date;
    
    // Get upload directory for the specific post publish date
    $upload_dir = wp_upload_dir($post_date);
    
    // Find and delete any existing certificate files for this person to avoid duplicates.
    $search_pattern = $upload_dir['path'] . "/certificate_".$formatted_date."__".$name."_".$course_id.".pdf";
    $existing_files = glob($search_pattern);
    
    if (is_array($existing_files)) {
        foreach ($existing_files as $file) {
            unlink($file);
        }
    }
    
    // Define the new file path and create the certificate.
    $file_path = $upload_dir['path'] . "/certificate_".$formatted_date."__".$name."_".$course_id.".pdf";

    $pluginlog = plugin_dir_path(__FILE__).'debug.log';
    $message = '$file_path: ' . $file_path . PHP_EOL;
    error_log($message, 3, $pluginlog);

    $pdf->Output('F', $file_path);

    // Explicitly clean up
    unset($pdf);
    if (function_exists('gc_collect_cycles')) {
        gc_collect_cycles();
    }

    log_memory_usage('end_certificate_generation');

    return $file_path;

}

// Send the certificate via email
function send_certificate_email($name, $email, $course_name, $course_id, $course_center, $attendee_hours = '', $attendee_credentials = '', $attendee_ches = '') {

    $pdf_path = generate_course_certificate($name, $course_name, $course_id, $attendee_hours, $attendee_credentials, $attendee_ches);

    // Get email subject from ACF field, fallback to default if not set
    $subject = get_field('email_subject', $course_id);
    if (empty($subject)) {
        $subject = 'Your Course Completion Certificate';
    }
	
	$message = "Thank you for participating in $course_name, hosted by the $course_center.<br/>";
	$message .= "Attached is your certificate, awarded for attending the training.<br/>Please keep this certificate for your records.<br/><br/>If you have any questions or require additional support, feel free to reach out to us.<br/>";
	$message .= "You can contact your regional center that awarded your certificate or <a href='https://attcnetwork.org/find-your-center/'>find them here</a>.";
    
    $headers = array('Content-Type: text/html; charset=UTF-8');
    $attachments = array($pdf_path);

    error_log("Attempting to send email to: " . $email . " with subject: " . $subject);
    
    // Reset PHPMailer errors before sending
    global $phpmailer;
    if (isset($phpmailer)) {
        $phpmailer->clearAllRecipients();
        $phpmailer->clearAttachments();
    }
    
    // Capture any PHP errors during wp_mail
    $mail_errors = array();
    set_error_handler(function($errno, $errstr) use (&$mail_errors) {
        $mail_errors[] = $errstr;
    });
    
    $result = wp_mail($email, $subject, $message, $headers, $attachments);
    
    restore_error_handler();
    
    // Check for SMTP or PHPMailer errors even if result is true
    $has_errors = false;
    $error_details = '';
    
    if (isset($phpmailer) && is_object($phpmailer)) {
        if (!empty($phpmailer->ErrorInfo)) {
            $has_errors = true;
            $error_details = $phpmailer->ErrorInfo;
            error_log("PHPMailer error for {$email}: " . $error_details);
        }
    }
    
    if (!empty($mail_errors)) {
        $has_errors = true;
        $error_details .= (!empty($error_details) ? '; ' : '') . implode('; ', $mail_errors);
        error_log("PHP mail errors for {$email}: " . implode('; ', $mail_errors));
    }

    if ($result && !$has_errors) {
        error_log("Successfully sent certificate to: " . $email);
    } else {
        error_log("Failed to send certificate to: " . $email . ($error_details ? " - " . $error_details : ""));
        $result = false; // Override result if we detected errors
    }
    
    // Clean up the PDF file after sending to free memory
    if (file_exists($pdf_path)) {
        @unlink($pdf_path);
    }

    return $result;
}

function add_certificate_capabilities() {
    $roles = ['administrator', 'editor'];
    
    foreach ($roles as $role_name) {
        $role = get_role($role_name);
        if ($role) {
            $role->add_cap('manage_certificates');
        }
    }
}
add_action('init', 'add_certificate_capabilities');

// Admin interface
function add_certificate_generator_admin_page() {
    add_menu_page(
        'Certificate Generator',
        'Certificate Generator', 
        'manage_certificates',
        'certificate-generator',
        'certificate_generator_admin_page_content',
        'dashicons-awards',
        23
    );
    
    // Add Settings submenu
    add_submenu_page(
        'certificate-generator',
        'Settings',
        'Settings',
        'manage_options',
        'certificate-generator-settings',
        'certificate_generator_settings_page_content'
    );
}
add_action('admin_menu', 'add_certificate_generator_admin_page');

function certificate_generator_admin_page_content() {
    ?>
    <div class="wrap">
        <h1>Course Certificate Manager</h1>
        
        <div class="card" style="max-width: 800px; margin-bottom: 20px; padding: 20px;">
            <div class="course-selection">
                <label for="course_id"><strong>Select Training Course:</strong></label>
                <select name="course_id" id="course_id" required style="margin: 10px 0; display: block; min-width: 300px;">
                    <option value="">-- Select a Training Course --</option>
                    <?php
                    $current_user_groups = get_current_user_attc_groups();
                    $settings = certificate_generator_get_settings();
                    $taxonomy = $settings['taxonomy_for_filtering'];

                    $args = array(
                        'post_type'      => 'training',
                        'posts_per_page' => -1,
                        'meta_key'       => 'training_date',
                        'orderby'        => 'meta_value',
                        'order'          => 'DESC',
                        'meta_type'      => 'DATE',
                    );

                    if (!empty($current_user_groups) && $settings['enable_network_filtering']) {
                        $term_ids = [];
                        foreach ($current_user_groups as $slug) {
                            $term = get_term_by('slug', $slug, $taxonomy);
                            if ($term) {
                                $term_ids[] = $term->term_id;
                            }
                        }

                        if (!empty($term_ids)) {
                            $args['tax_query'] = array(
                                array(
                                    'taxonomy' => $taxonomy,
                                    'field'    => 'term_id',
                                    'terms'    => $term_ids,
                                    'operator' => 'IN',
                                ),
                            );
                        } else {
                            // If user has groups but no matching terms found, show no trainings.
                            $args['post__in'] = array(0);
                        }
                    }

                    $trainings = get_posts($args);

                    foreach ($trainings as $training) {
                        $training_date = get_field('training_date', $training->ID);
                        $attendees = get_field('attendees', $training->ID);
                        $misc_text = get_field('certificate_misc_text', $training->ID);
                        $attendee_count = is_array($attendees) ? count($attendees) : 0;
                        
                        $option_text = sprintf(
                            '%s (%s) - %d Attendees',
                            $training->post_title,
                            $training_date,
                            $attendee_count
                        );
                        ?>
                        <option value="<?php echo esc_attr($training->ID); ?>">
                            <?php echo esc_html($option_text); ?>
                        </option>
                        <?php
                    }
                    ?>
                </select>
                <?php

                if (isset($_GET['debug']) && $_GET['debug'] == '1') {
                    echo 'Current PublishPress roles: ';
                    foreach ( $current_user_groups as $item ) {
                        echo $item . ', ';
                    }
                    $user = wp_get_current_user();
                    echo '<p>Has manage_certificates role: ' . (current_user_can('manage_certificates') ? 'YES' : 'NO') . '</p>';
                }
                ?>
            </div>

            <!-- Upload Section -->
            <div class="upload-section" style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd;">
                <h3>Upload Attendees</h3>
                <p>Upload a CSV file with attendee information. The CSV should contain these columns: "attendee, attendee_hours, attendee_email_address, attendee_credentials, attendee_ches". <a href="<?php echo plugin_dir_url(__FILE__) . 'sample.csv'; ?>">Download sample.csv</a>. Uploading a CSV will replace any existing attendee rows.</p>
                <form id="upload-attendees-form" method="post" enctype="multipart/form-data">
                    <input type="file" name="attendees_csv" id="attendees_csv" accept=".csv" required style="margin-bottom: 10px; display: block;">
                    <button type="button" id="upload-attendees-btn" class="button button-secondary">Upload Attendees</button>
                </form>
                <div id="upload-progress" style="margin-top: 10px; display: none;">
                    <p id="upload-message"></p>
                </div>
            </div>

            <!-- Certificate Generation Section -->
            <div class="generate-section" style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd;">
                <h3>Generate Certificates</h3>
                <p>Select 'All' or a specific attendee to generate and email certificates.</p>
                
                <div class="notice notice-info" style="margin: 15px 0; padding: 10px;">
                    <p><strong>📧 Email Delivery Note:</strong> The plugin validates email format and domain existence before sending. However, some invalid emails (like non-existent mailboxes on valid domains) may still show as "successful" because they're accepted by the mail server.</p>
                    <p>To verify actual delivery and see bounce details, check <strong>Post SMTP → Email Log</strong> for complete delivery status.</p>
                </div>
                
                <!-- Status Filter -->
                <div class="status-filter" style="margin-bottom: 15px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                    <label for="status_filter"><strong>Filter by Certificate Status:</strong></label>
                    <select name="status_filter" id="status_filter" style="margin: 10px 0; display: block; min-width: 300px;">
                        <option value="all">All Attendees</option>
                        <option value="pending">Pending (Not Sent Yet)</option>
                        <option value="sent">Sent Successfully</option>
                        <option value="failed">Failed (All Types)</option>
                        <option value="invalid_format">Invalid Email Format</option>
                        <option value="domain_not_exist">Domain Does Not Exist</option>
                        <option value="send_failed">Failed to Send</option>
                        <option value="smtp_error">SMTP Connection Error</option>
                        <option value="smtp_auth_failed">SMTP Authentication Failed</option>
                        <option value="recipient_rejected">Recipient Rejected</option>
                        <option value="mailbox_full">Mailbox Full</option>
                        <option value="spam_rejected">Spam Filter Rejection</option>
                        <option value="rate_limited">Rate Limit Exceeded</option>
                        <option value="attachment_too_large">Attachment Too Large</option>
                        <option value="temporary_failure">Temporary Failure</option>
                    </select>
                    <p class="description">Filter attendee list by certificate status to easily resend failed certificates.</p>
                </div>
                
                <div class="attendee-selection" style="margin-bottom: 15px;">
                    <label for="attendee_select"><strong>Select Attendee:</strong></label>
                    <select name="attendee_select" id="attendee_select" style="margin: 10px 0; display: block; min-width: 300px;">
                        <option value="">-- Select a course first --</option>
                    </select>
                    <div id="attendee-stats" style="margin-top: 10px; padding: 10px; background: #fff; border: 1px solid #ddd; border-radius: 4px; display: none;">
                        <strong>Attendee Summary:</strong>
                        <span style="margin-left: 10px;">Total: <strong id="stat-total">0</strong></span>
                        <span style="margin-left: 15px; color: #46b450;">✅ Sent: <strong id="stat-sent">0</strong></span>
                        <span style="margin-left: 15px; color: #999;">⏳ Pending: <strong id="stat-pending">0</strong></span>
                        <span style="margin-left: 15px; color: #dc3232;">❌ Failed: <strong id="stat-failed">0</strong></span>
                    </div>
                    <p class="description">Select "All" to generate certificates for all attendees (or filtered subset), or select a specific attendee.</p>
                </div>
                
                <button type="button" id="start-sending-certificates" class="button button-primary">Generate & Send Certificates</button>
                <div id="progress-area" style="margin-top: 10px; display: none;">
                    <p id="progress-message">Sending certificates...</p>
                    <div class="progress-details">
                        <p><strong>Emails sent:</strong> <span id="emails-sent-count">0</span></p>
                        <p id="current-recipient"></p>
                    </div>
                </div>
                
                <!-- Results Table Section -->
                <div id="results-section" style="margin-top: 20px; display: none;">
                    <h3>Certificate Generation Results</h3>
                    <div style="margin-bottom: 10px;">
                        <button type="button" id="download-results-csv" class="button button-secondary">
                            📥 Download Results as CSV
                        </button>
                    </div>
                    <div style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; background: #fff;">
                        <table id="results-table" class="wp-list-table widefat fixed striped" style="margin: 0;">
                            <thead>
                                <tr>
                                    <th style="width: 5%; padding: 8px;">#</th>
                                    <th style="width: 30%; padding: 8px;">Name</th>
                                    <th style="width: 35%; padding: 8px;">Email Address</th>
                                    <th style="width: 15%; padding: 8px;">Status</th>
                                    <th style="width: 15%; padding: 8px;">Details</th>
                                </tr>
                            </thead>
                            <tbody id="results-table-body">
                                <!-- Results will be populated here -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        var resultsData = []; // Store all results for CSV export
        
        // Shared course validation
        function validateCourseSelection() {
            var courseId = $('#course_id').val();
            if (!courseId) {
                alert('Please select a training course first.');
                return false;
            }
            return courseId;
        }
        
        // Load attendees when course is selected
        $('#course_id').on('change', function() {
            var courseId = $(this).val();
            if (!courseId) {
                // Reset attendee dropdown if no course selected
                $('#attendee_select').html('<option value="">-- Select a course first --</option>');
                $('#attendee-stats').hide();
                return;
            }
            
            loadAttendees(courseId);
        });
        
        // Reload attendees when status filter changes
        $('#status_filter').on('change', function() {
            var courseId = $('#course_id').val();
            if (courseId) {
                loadAttendees(courseId);
            }
        });
        
        // Function to get status icon and label
        function getStatusBadge(status) {
            var badges = {
                'pending': '⏳ Pending',
                'sent': '✅ Sent',
                'invalid_format': '❌ Invalid Format',
                'domain_not_exist': '❌ Bad Domain',
                'send_failed': '❌ Failed',
                'smtp_error': '🔧 SMTP Error',
                'smtp_auth_failed': '🔧 Auth Failed',
                'recipient_rejected': '📫 Rejected',
                'mailbox_full': '📫 Mailbox Full',
                'spam_rejected': '🚫 Spam Block',
                'rate_limited': '🚫 Rate Limited',
                'attachment_too_large': '📎 Too Large',
                'temporary_failure': '⚠️ Temp Failure'
            };
            return badges[status] || '❓ Unknown';
        }
        
        // Function to load attendees
        function loadAttendees(courseId) {
            // Show loading state
            $('#attendee_select').html('<option value="">Loading attendees...</option>');
            $('#attendee-stats').hide();
            
            var statusFilter = $('#status_filter').val();
            
            // Fetch attendees for the selected course
            $.post(ajaxurl, {
                action: 'get_course_attendees',
                course_id: courseId,
                status_filter: statusFilter
            }, function(response) {
                if (response.success && response.data.attendees) {
                    var attendees = response.data.attendees;
                    var stats = response.data.stats || {};
                    
                    // Update stats display
                    $('#stat-total').text(stats.total || 0);
                    $('#stat-sent').text(stats.sent || 0);
                    $('#stat-pending').text(stats.pending || 0);
                    $('#stat-failed').text(stats.failed || 0);
                    $('#attendee-stats').show();
                    
                    // Build options
                    var options = '<option value="all">All (' + attendees.length + ' attendees)</option>';
                    $.each(attendees, function(_, attendee) {
                        var statusBadge = getStatusBadge(attendee.status || 'pending');
                        options += '<option value="' + attendee.index + '">' + 
                                   attendee.name + ' (' + attendee.email + ') - ' + statusBadge + 
                                   '</option>';
                    });
                    $('#attendee_select').html(options);
                    
                    // Show message if filtered list is empty
                    if (attendees.length === 0) {
                        $('#attendee_select').html('<option value="">No attendees match this filter</option>');
                    }
                } else {
                    $('#attendee_select').html('<option value="">No attendees found</option>');
                    $('#attendee-stats').hide();
                }
            }).fail(function() {
                $('#attendee_select').html('<option value="">Error loading attendees</option>');
                $('#attendee-stats').hide();
            });
        }

        // Handle CSV Upload
        $('#upload-attendees-btn').on('click', function() {
            var courseId = validateCourseSelection();
            if (!courseId) return;
            
            var fileInput = $('#attendees_csv')[0];
            if (!fileInput.files.length) {
                alert('Please select a CSV file.');
                return;
            }
            
            var formData = new FormData();
            formData.append('action', 'upload_attendees_csv');
            formData.append('course_id', courseId);
            formData.append('attendees_csv', fileInput.files[0]);
            
            $('#upload-progress').show();
            $('#upload-message').text('Uploading and processing CSV...');
            $(this).prop('disabled', true);
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        $('#upload-message').html(
                            'Success! Added ' + response.data.count + ' attendees.<br>' +
                            'Updated training: ' + response.data.course_name
                        );
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        $('#upload-message').text('Error: ' + response.data);
                    }
                },
                error: function() {
                    $('#upload-message').text('Upload failed. Please try again.');
                },
                complete: function() {
                    $('#upload-attendees-btn').prop('disabled', false);
                }
            });
        });

        // Handle Certificate Generation with improved timeout handling
        $('#start-sending-certificates').on('click', function() {
            var courseId = validateCourseSelection();
            if (!courseId) return;
            
            var attendeeSelection = $('#attendee_select').val();
            if (!attendeeSelection) {
                alert('Please select an attendee or "All".');
                return;
            }

            $(this).prop('disabled', true);
            $('#progress-area').show();
            $('#results-section').show();
            $('#progress-message').text('Starting to send certificates...');
            $('#emails-sent-count').text('0');
            $('#current-recipient').text('');
            
            // Clear previous results
            resultsData = [];
            $('#results-table-body').empty();
            
            sendNextCertificate(courseId, 0, attendeeSelection);
        });
        
        // Function to add row to results table
        function addResultRow(index, name, email, status, details) {
            var statusClass = '';
            var statusIcon = '';
            var statusText = '';
            
            if (status === 'sent') {
                statusClass = 'success';
                statusIcon = '✅';
                statusText = 'Sent';
            } else if (status === 'skipped') {
                statusClass = 'warning';
                statusIcon = '⚠️';
                statusText = 'Skipped';
            } else {
                statusClass = 'error';
                statusIcon = '❌';
                statusText = 'Failed';
            }
            
            var row = '<tr style="background-color: ' + 
                      (statusClass === 'success' ? '#f0f9ff' : 
                       statusClass === 'warning' ? '#fffbf0' : '#fff0f0') + ';">' +
                      '<td style="padding: 8px;">' + index + '</td>' +
                      '<td style="padding: 8px;">' + $('<div>').text(name).html() + '</td>' +
                      '<td style="padding: 8px;">' + $('<div>').text(email).html() + '</td>' +
                      '<td style="padding: 8px;"><strong>' + statusIcon + ' ' + statusText + '</strong></td>' +
                      '<td style="padding: 8px; font-size: 11px; color: #666;">' + 
                      $('<div>').text(details || '-').html() + '</td>' +
                      '</tr>';
            
            $('#results-table-body').append(row);
            
            // Store in resultsData for CSV export
            resultsData.push({
                index: index,
                name: name,
                email: email,
                status: statusText,
                details: details || ''
            });
            
            // Auto-scroll to bottom of table
            var resultsTable = $('#results-section > div')[0];
            if (resultsTable) {
                resultsTable.scrollTop = resultsTable.scrollHeight;
            }
        }
        
        // CSV Download function
        $('#download-results-csv').on('click', function() {
            if (resultsData.length === 0) {
                alert('No results to download.');
                return;
            }
            
            // Create CSV content
            var csv = 'Number,Name,Email Address,Status,Details\n';
            resultsData.forEach(function(row) {
                csv += row.index + ',';
                csv += '"' + row.name.replace(/"/g, '""') + '",';
                csv += '"' + row.email.replace(/"/g, '""') + '",';
                csv += row.status + ',';
                csv += '"' + row.details.replace(/"/g, '""') + '"\n';
            });
            
            // Create download link
            var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            var link = document.createElement('a');
            var url = URL.createObjectURL(blob);
            
            var courseId = $('#course_id').val();
            var courseName = $('#course_id option:selected').text();
            // Extract just the course name before the first parenthesis (before date/attendee count)
            courseName = courseName.split('(')[0].trim();
            // Replace non-alphanumeric with hyphen, then remove consecutive hyphens
            courseName = courseName.replace(/[^a-z0-9]+/gi, '-').replace(/-+/g, '-').replace(/^-|-$/g, '');
            var timestamp = new Date().toISOString().slice(0, 10);
            var filename = 'certificate-results_' + courseName + '_' + timestamp + '.csv';
            
            link.setAttribute('href', url);
            link.setAttribute('download', filename);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        });

        function sendNextCertificate(courseId, startIndex, attendeeSelection) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'send_certificates',
                    course_id: courseId,
                    start_index: startIndex,
                    attendee_selection: attendeeSelection
                },
                timeout: 60000, // 60 second timeout per request
                success: function(response) {
                    if (response.success) {
                        var data = response.data;
                        
                        // Update sent count
                        $('#emails-sent-count').text(data.emails_sent || 0);
                        
                        // Add row to results table if we have recipient info
                        if (data.current_recipient_name && data.current_recipient_email) {
                            var status = data.current_status || 'sent';
                            var details = data.current_details || 'Certificate sent successfully';
                            
                            addResultRow(
                                data.emails_sent,
                                data.current_recipient_name,
                                data.current_recipient_email,
                                status,
                                details
                            );
                        }
                        
                        if (data.is_complete) {
                            // Show completion message with success/failure summary
                            var successCount = data.emails_succeeded || data.emails_sent;
                            var failedCount = data.failed_count || 0;
                            var totalCount = data.total_attendees;
                            
                            var message = '<strong>Certificate generation complete!</strong><br>';
                            message += '✅ ' + successCount + ' of ' + totalCount + ' certificates sent successfully.';
                            
                            if (failedCount > 0) {
                                message += '<br><strong style="color: #d63638;">⚠ ' + failedCount + ' failed:</strong>';
                            }
                            
                            message += '<br><br>📊 <em>See detailed results in the table below. Download as CSV for your records.</em>';
                            
                            $('#progress-message').html(message);
                            $('#current-recipient').text('');
                            $('#start-sending-certificates').prop('disabled', false);
                        } else {
                            // Show progress with success count
                            var successCount = data.emails_succeeded || data.emails_sent;
                            var failedSoFar = data.emails_sent - successCount;
                            
                            var progressText = 'Sending certificates... ' + 
                                             data.emails_sent + ' of ' + 
                                             data.total_attendees + ' processed';
                            
                            if (failedSoFar > 0) {
                                progressText += ' (' + failedSoFar + ' failed)';
                            }
                            
                            $('#progress-message').text(progressText);
                            $('#current-recipient').text(
                                'Currently sending to: ' + data.current_recipient_email
                            );
                            // Continue with next certificate
                            sendNextCertificate(courseId, startIndex + 1, attendeeSelection);
                        }
                    } else {
                        $('#progress-message').text('Error: ' + response.data);
                        $('#start-sending-certificates').prop('disabled', false);
                    }
                },
                error: function(xhr, status, error) {
                    if (status === 'timeout') {
                        $('#progress-message').text('Request timed out. Please check your server settings and try again with fewer attendees.');
                    } else {
                        $('#progress-message').text('Error: Failed to email certificates. ' + error);
                    }
                    $('#start-sending-certificates').prop('disabled', false);
                }
            });
        }
    });
    </script>
    
    <style>
    #results-table {
        border-collapse: collapse;
    }
    #results-table thead th {
        position: sticky;
        top: 0;
        background: #f0f0f1;
        font-weight: 600;
        border-bottom: 2px solid #c3c4c7;
        z-index: 1;
    }
    #results-table tbody tr:hover {
        background-color: #f6f7f7 !important;
    }
    #results-table td {
        border-bottom: 1px solid #e0e0e0;
    }
    .results-summary {
        display: inline-block;
        padding: 5px 10px;
        margin: 0 5px;
        border-radius: 3px;
        font-weight: 600;
    }
    .results-summary.success {
        background: #d4edda;
        color: #155724;
    }
    .results-summary.error {
        background: #f8d7da;
        color: #721c24;
    }
    .status-filter {
        transition: border-color 0.2s;
    }
    .status-filter:hover {
        border-color: #0073aa;
    }
    #attendee-stats {
        font-size: 14px;
        line-height: 1.8;
    }
    #attendee-stats strong {
        font-size: 16px;
    }
    #attendee_select option {
        padding: 5px;
    }
    </style>
    <?php
}

// Settings page content
function certificate_generator_settings_page_content() {
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        return;
    }

    // Handle form submission
    if (isset($_POST['certificate_settings_nonce']) && wp_verify_nonce($_POST['certificate_settings_nonce'], 'save_certificate_settings')) {
        
        error_log('========================================');
        error_log('Certificate Settings: Form submitted at ' . current_time('mysql'));
        error_log('Certificate Settings: Raw $_POST[role_mappings] = ' . print_r($_POST['role_mappings'], true));
        
        // Process role mappings
        $role_mappings = array();
        if (isset($_POST['role_mappings']) && is_array($_POST['role_mappings'])) {
            error_log('Certificate Settings: Processing ' . count($_POST['role_mappings']) . ' mapping entries');
            
            // The POST data structure is: role_mappings[unique_id][role] and role_mappings[unique_id][group]
            foreach ($_POST['role_mappings'] as $unique_id => $mapping) {
                error_log("Certificate Settings: Processing entry {$unique_id}: " . print_r($mapping, true));
                
                if (is_array($mapping) && !empty($mapping['role']) && !empty($mapping['group'])) {
                    $role_key = sanitize_text_field($mapping['role']);
                    $group_value = sanitize_text_field($mapping['group']);
                    $role_mappings[$role_key] = $group_value;
                    error_log("Certificate Settings: ✓ Added mapping - {$role_key} => {$group_value}");
                } else {
                    error_log("Certificate Settings: ✗ Skipped entry {$unique_id} - empty role or group");
                }
            }
        } else {
            error_log('Certificate Settings: No role_mappings in POST data');
        }
        
        error_log('Certificate Settings: Final processed mappings (' . count($role_mappings) . ' total): ' . print_r($role_mappings, true));
        
        // Save settings
        $settings = array(
            'role_group_mappings' => $role_mappings,
            'taxonomy_for_filtering' => sanitize_text_field($_POST['taxonomy_for_filtering']),
            'enable_network_filtering' => isset($_POST['enable_network_filtering']),
        );
        
        error_log('Certificate Settings: Saving full settings array: ' . print_r($settings, true));
        
        $update_result = update_option('certificate_generator_settings', $settings);
        
        error_log('Certificate Settings: Update result = ' . ($update_result ? 'SUCCESS' : 'FAILED (or no change)'));
        error_log('Certificate Settings: Verification - retrieving saved data: ' . print_r(get_option('certificate_generator_settings'), true));
        error_log('========================================');
        
        echo '<div class="notice notice-success is-dismissible"><p>Settings saved successfully! (' . count($role_mappings) . ' mappings)</p></div>';
    }
    
    // Get current settings
    $settings = certificate_generator_get_settings();
    $role_mappings = $settings['role_group_mappings'];
    $selected_taxonomy = $settings['taxonomy_for_filtering'];
    $filtering_enabled = $settings['enable_network_filtering'];
    
    // Get available taxonomies for 'training' post type
    $taxonomies = get_object_taxonomies('training', 'objects');
    
    // Get all terms from the selected taxonomy
    $taxonomy_terms = array();
    if (!empty($selected_taxonomy) && taxonomy_exists($selected_taxonomy)) {
        $terms = get_terms(array(
            'taxonomy' => $selected_taxonomy,
            'hide_empty' => false,
        ));
        if (!is_wp_error($terms)) {
            $taxonomy_terms = $terms;
        }
    }
    
    // Get PublishPress roles/groups if available
    $available_roles = array();
    if (function_exists('pp_get_all_groups')) {
        $pp_groups = pp_get_all_groups();
        foreach ($pp_groups as $group) {
            if (isset($group->metagroup_id)) {
                $available_roles[$group->metagroup_id] = $group->label;
            }
        }
    }
    
    // Fallback to WordPress roles if PublishPress not available
    if (empty($available_roles)) {
        global $wp_roles;
        $available_roles = $wp_roles->get_names();
    }
    
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        
        <form method="post" action="">
            <?php wp_nonce_field('save_certificate_settings', 'certificate_settings_nonce'); ?>
            
            <h2 class="nav-tab-wrapper">
                <a href="#role-access" class="nav-tab nav-tab-active">Role & Access Control</a>
            </h2>
            
            <div id="role-access" class="tab-content">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label>Enable Group-Based Filtering</label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" name="enable_network_filtering" value="1" <?php checked($filtering_enabled, true); ?>>
                                Filter trainings based on user's assigned groups/roles
                            </label>
                            <p class="description">When enabled, users only see trainings from their assigned groups.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="taxonomy_for_filtering">Taxonomy for Filtering</label>
                        </th>
                        <td>
                            <select name="taxonomy_for_filtering" id="taxonomy_for_filtering">
                                <?php foreach ($taxonomies as $taxonomy): ?>
                                    <option value="<?php echo esc_attr($taxonomy->name); ?>" <?php selected($selected_taxonomy, $taxonomy->name); ?>>
                                        <?php echo esc_html($taxonomy->label); ?> (<?php echo esc_html($taxonomy->name); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Select which taxonomy to use for grouping trainings (typically "group").</p>
                        </td>
                    </tr>
                </table>
                
                <hr>
                
                <h3>Role to Group Mappings</h3>
                <p class="description">Map PublishPress roles/groups to taxonomy terms. Users with these roles will only see trainings tagged with their corresponding group term.</p>
                
                <table class="wp-list-table widefat fixed striped" id="role-mappings-table">
                    <thead>
                        <tr>
                            <th style="width: 40%;">User Role/Group</th>
                            <th style="width: 40%;">Maps to Taxonomy Term</th>
                            <th style="width: 20%;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="role-mappings-tbody">
                        <?php 
                        $has_mappings = !empty($role_mappings);
                        $mappings_to_display = $has_mappings ? $role_mappings : array('' => ''); // At least one empty row
                        
                        foreach ($mappings_to_display as $role => $group): 
                            $row_id = uniqid(); // Generate ONCE per row, use for both selects
                        ?>
                            <tr class="mapping-row">
                                <td>
                                    <select name="role_mappings[<?php echo esc_attr($row_id); ?>][role]" class="regular-text">
                                        <option value="">-- Select Role --</option>
                                        <?php foreach ($available_roles as $role_key => $role_label): ?>
                                            <option value="<?php echo esc_attr($role_key); ?>" <?php selected($role, $role_key); ?>>
                                                <?php echo esc_html($role_label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <select name="role_mappings[<?php echo esc_attr($row_id); ?>][group]" class="regular-text">
                                        <option value="">-- Select Group Term --</option>
                                        <?php foreach ($taxonomy_terms as $term): ?>
                                            <option value="<?php echo esc_attr($term->slug); ?>" <?php selected($group, $term->slug); ?>>
                                                <?php echo esc_html($term->name); ?> (<?php echo esc_html($term->slug); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <button type="button" class="button remove-mapping">Remove</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <p style="margin-top: 15px;">
                    <button type="button" id="add-mapping" class="button">+ Add Mapping</button>
                </p>
                
                <div class="notice notice-info inline" style="margin-top: 20px;">
                    <p><strong>Note:</strong> After changing taxonomy or mappings, users may need to log out and back in for changes to take effect.</p>
                </div>
            </div>
            
            <?php submit_button('Save Settings'); ?>
        </form>
    </div>
    
    <script>
    jQuery(document).ready(function($) {
        // Store the available roles and terms as data for creating new rows
        var availableRoles = <?php echo json_encode($available_roles); ?>;
        var taxonomyTerms = <?php echo json_encode(array_map(function($term) {
            return array('slug' => $term->slug, 'name' => $term->name);
        }, $taxonomy_terms)); ?>;
        
        // Function to create a new mapping row
        function createMappingRow() {
            var uniqueId = 'new_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
            
            var roleOptions = '<option value="">-- Select Role --</option>';
            $.each(availableRoles, function(key, label) {
                roleOptions += '<option value="' + $('<div>').text(key).html() + '">' + 
                              $('<div>').text(label).html() + '</option>';
            });
            
            var groupOptions = '<option value="">-- Select Group Term --</option>';
            $.each(taxonomyTerms, function(i, term) {
                groupOptions += '<option value="' + $('<div>').text(term.slug).html() + '">' + 
                               $('<div>').text(term.name).html() + ' (' + 
                               $('<div>').text(term.slug).html() + ')</option>';
            });
            
            var newRow = $('<tr class="mapping-row">' +
                '<td>' +
                    '<select name="role_mappings[' + uniqueId + '][role]" class="regular-text">' +
                        roleOptions +
                    '</select>' +
                '</td>' +
                '<td>' +
                    '<select name="role_mappings[' + uniqueId + '][group]" class="regular-text">' +
                        groupOptions +
                    '</select>' +
                '</td>' +
                '<td>' +
                    '<button type="button" class="button remove-mapping">Remove</button>' +
                '</td>' +
            '</tr>');
            
            return newRow;
        }
        
        // Add new mapping row
        $('#add-mapping').on('click', function() {
            var newRow = createMappingRow();
            $('#role-mappings-tbody').append(newRow);
        });
        
        // Remove mapping row
        $(document).on('click', '.remove-mapping', function() {
            if ($('#role-mappings-tbody tr').length > 1) {
                $(this).closest('tr').remove();
            } else {
                alert('At least one mapping row must remain. Clear the values instead of removing.');
            }
        });
        
        // Tab switching (for future tabs)
        $('.nav-tab').on('click', function(e) {
            e.preventDefault();
            $('.nav-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');
            $('.tab-content').hide();
            $($(this).attr('href')).show();
        });
    });
    </script>
    
    <style>
    .tab-content {
        background: #fff;
        padding: 20px;
        border: 1px solid #ccd0d4;
        border-top: none;
        margin-bottom: 20px;
    }
    #role-mappings-table {
        margin-top: 15px;
    }
    #role-mappings-table td {
        padding: 10px;
    }
    .mapping-row select {
        width: 100%;
    }
    </style>
    <?php
}

// AJAX handler for sending certificates - FIXED for proper batch processing
add_action('wp_ajax_send_certificates', 'send_certificates_via_ajax');

// Helper function to update certificate status in attendee repeater
function update_attendee_certificate_status($course_id, $attendee_index, $status, $details = '') {
    $attendees = get_field('attendees', $course_id);
    
    if (!$attendees || !isset($attendees[$attendee_index])) {
        error_log("Could not update status for attendee index {$attendee_index} in course {$course_id}");
        return false;
    }
    
    // Update the certificate_status field for this attendee
    $attendees[$attendee_index]['certificate_status'] = $status;
    
    // Optionally store the details/error message in a separate field if it exists
    if (!empty($details) && isset($attendees[$attendee_index]['certificate_status_details'])) {
        $attendees[$attendee_index]['certificate_status_details'] = $details;
    }
    
    // Save back to ACF
    update_field('attendees', $attendees, $course_id);
    
    error_log("Updated certificate status for {$attendees[$attendee_index]['attendee']} to: {$status}");
    
    return true;
}

// Helper function to determine status code from error message
function parse_email_error_to_status($error_message) {
    $error_lower = strtolower($error_message);
    
    // Check for specific error patterns
    if (strpos($error_lower, 'smtp') !== false && strpos($error_lower, 'auth') !== false) {
        return 'smtp_auth_failed';
    } elseif (strpos($error_lower, 'smtp') !== false && strpos($error_lower, 'connect') !== false) {
        return 'smtp_error';
    } elseif (strpos($error_lower, '550') !== false || strpos($error_lower, 'user not found') !== false || 
              strpos($error_lower, 'no such user') !== false || strpos($error_lower, 'does not exist') !== false) {
        return 'recipient_rejected';
    } elseif (strpos($error_lower, '552') !== false || strpos($error_lower, 'mailbox full') !== false || 
              strpos($error_lower, 'quota exceeded') !== false) {
        return 'mailbox_full';
    } elseif (strpos($error_lower, '554') !== false || strpos($error_lower, 'spam') !== false || 
              strpos($error_lower, 'blocked') !== false || strpos($error_lower, 'blacklist') !== false) {
        return 'spam_rejected';
    } elseif (strpos($error_lower, '421') !== false || strpos($error_lower, 'too many') !== false || 
              strpos($error_lower, 'rate limit') !== false) {
        return 'rate_limited';
    } elseif (strpos($error_lower, 'too large') !== false || strpos($error_lower, 'size') !== false) {
        return 'attachment_too_large';
    } elseif (strpos($error_lower, '4') === 0 || strpos($error_lower, 'temporary') !== false || 
              strpos($error_lower, 'try again') !== false) {
        return 'temporary_failure';
    } else {
        return 'send_failed';
    }
}

function send_certificates_via_ajax() {
    // Increase execution time and memory limits
    set_time_limit(300); // 5 minutes
    
    $current_memory = ini_get('memory_limit');
    if (wp_convert_hr_to_bytes($current_memory) < wp_convert_hr_to_bytes('256M')) {
        ini_set('memory_limit', '256M');
    }

    $course_id = intval($_POST['course_id']);
    $start_index = isset($_POST['start_index']) ? intval($_POST['start_index']) : 0;
    $attendee_selection = isset($_POST['attendee_selection']) ? $_POST['attendee_selection'] : 'all';
    
    // Use transient for lock with timestamp
    $lock_key = 'cert_lock_' . $course_id;
    
    // Only check/set lock on first request (start_index = 0)
    if ($start_index === 0) {
        $existing_lock = get_transient($lock_key);
        if ($existing_lock) {
            // Check if lock is stale (more than 10 minutes old)
            if ((time() - $existing_lock) < 600) {
                wp_send_json_error('Certificate generation already in progress for this course');
                return;
            }
            // Lock is stale, delete it
            delete_transient($lock_key);
        }
        // Set new lock with current timestamp (10 minute expiration)
        set_transient($lock_key, time(), 600);
    }
    
    // Memory check before processing
    $memory_usage = memory_get_usage(true);
    $memory_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
    
    if ($memory_usage > ($memory_limit * 0.8)) {
        delete_transient($lock_key);
        error_log("Memory usage critical: " . size_format($memory_usage) . " of " . size_format($memory_limit));
        wp_send_json_error('Memory usage too high. Please contact your administrator to increase PHP memory_limit.');
        return;
    }
    
    $attendees = get_field('attendees', $course_id);
    
    if (!$attendees) {
        delete_transient($lock_key);
        wp_send_json_error('No attendees found for this training.');
        return;
    }
    
    // If a specific attendee is selected (not "all")
    if ($attendee_selection !== 'all') {
        $attendee_index = intval($attendee_selection);
        if (isset($attendees[$attendee_index])) {
            // Create a new array with just the selected attendee
            $attendees = array($attendees[$attendee_index]);
            // Reset start_index since we're only processing one attendee
            $start_index = 0;
        } else {
            delete_transient($lock_key); 
            wp_send_json_error('Selected attendee not found.');
            return;
        }
    }
    
    $total_attendees = count($attendees);
    
    if ($start_index >= $total_attendees) {
        delete_transient($lock_key);
        wp_send_json_success(array(
            'is_complete' => true,
            'emails_sent' => $total_attendees
        ));
        return;
    }
    
    $attendee = $attendees[$start_index];
    $name = $attendee['attendee'];
    $email = $attendee['attendee_email_address'];
    $hours = isset($attendee['attendee_hours']) ? $attendee['attendee_hours'] : '';
    $credentials = isset($attendee['attendee_credentials']) ? $attendee['attendee_credentials'] : '';
    $ches = isset($attendee['attendee_ches']) ? $attendee['attendee_ches'] : '';
    
    // Validate email format and basic domain check
    $email_validation_error = null;
    $validation_status = null;
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email_validation_error = "Invalid email format";
        $validation_status = 'invalid_format';
    } else {
        // Extract domain and check if it has MX records
        $domain = substr(strrchr($email, "@"), 1);
        if (!checkdnsrr($domain, 'MX') && !checkdnsrr($domain, 'A')) {
            $email_validation_error = "Domain does not exist or cannot receive email";
            $validation_status = 'domain_not_exist';
        }
    }
    
    // If email validation failed, log and skip
    if ($email_validation_error) {
        error_log("Skipping certificate for {$name} ({$email}): {$email_validation_error}");
        
        // Update status in ACF repeater
        update_attendee_certificate_status($course_id, $start_index, $validation_status, $email_validation_error);
        
        // Track validation failure
        $failed_emails = get_transient('cert_failed_' . $course_id) ?: array();
        $failed_emails[] = array(
            'name' => $name,
            'email' => $email,
            'error' => $email_validation_error,
            'index' => $start_index + 1
        );
        set_transient('cert_failed_' . $course_id, $failed_emails, 3600);
        
        // Calculate success count and continue
        $success_count = ($start_index + 1) - count(get_transient('cert_failed_' . $course_id) ?: array());
        
        // Check if we've processed all attendees
        $is_complete = ($start_index + 1 >= $total_attendees);
        
        $response_data = array(
            'is_complete' => $is_complete,
            'emails_sent' => $start_index + 1,
            'emails_succeeded' => $success_count,
            'total_attendees' => $total_attendees,
            'current_recipient_name' => $name,
            'current_recipient_email' => $email,
            'current_recipient' => $email . ' (SKIPPED)',
            'current_status' => 'skipped',
            'current_details' => $email_validation_error
        );
        
        if ($is_complete) {
            $failed_emails_final = get_transient('cert_failed_' . $course_id);
            if (!empty($failed_emails_final)) {
                $response_data['failed_count'] = count($failed_emails_final);
                $response_data['failed_emails'] = $failed_emails_final;
            }
            delete_transient($lock_key);
            delete_transient('cert_failed_' . $course_id);
            
            $failed_count = isset($response_data['failed_count']) ? $response_data['failed_count'] : 0;
            error_log("Certificate batch complete: {$success_count} succeeded, {$failed_count} failed/skipped out of {$total_attendees} for course ID {$course_id}");
        }
        
        wp_send_json_success($response_data);
        return;
    }
    
    global $phpmailer;
    // Use training_name ACF field if available, otherwise fall back to post title
    $course_name = get_field('training_name', $course_id) ?: get_the_title($course_id);
	//get the center (group) applied to the course:
	$group_terms = wp_get_post_terms($course_id, 'group');
	$course_center = '';
	if (!is_wp_error($group_terms) && !empty($group_terms)) {
    	$course_center = $group_terms[0]->name;
	}
    
    error_log("Processing certificate " . ($start_index + 1) . " of {$total_attendees} for {$name} ({$email})");
    
    $send_result = send_certificate_email($name, $email, $course_name, $course_id, $course_center, $hours, $credentials, $ches);
    
    // Force garbage collection after each email
    if (function_exists('gc_collect_cycles')) {
        gc_collect_cycles();
    }

    // Determine status and update ACF
    if ($send_result) {
        // Success - update status to 'sent'
        update_attendee_certificate_status($course_id, $start_index, 'sent', 'Certificate sent successfully');
        $current_status = 'sent';
        $current_details = 'Certificate sent successfully';
    } else {
        // Failed - determine specific error type
        $error_message = "Failed to send certificate to {$email}";
        if (isset($phpmailer) && is_object($phpmailer) && !empty($phpmailer->ErrorInfo)) {
            $error_message .= ": " . $phpmailer->ErrorInfo;
        }
        
        $error_status = parse_email_error_to_status($error_message);
        update_attendee_certificate_status($course_id, $start_index, $error_status, $error_message);
        
        error_log($error_message);
        
        $current_status = 'failed';
        $current_details = $error_message;
    }

    // Track failed emails but continue processing
    if (!$send_result) {
        // Store failed email in transient for reporting at end
        $failed_emails = get_transient('cert_failed_' . $course_id) ?: array();
        $failed_emails[] = array(
            'name' => $name,
            'email' => $email,
            'error' => $error_message,
            'index' => $start_index + 1
        );
        set_transient('cert_failed_' . $course_id, $failed_emails, 3600); // Store for 1 hour
        
        // CONTINUE PROCESSING - don't stop the batch!
    }

    // Success/failure count tracking
    $success_count = ($start_index + 1) - count(get_transient('cert_failed_' . $course_id) ?: array());
    
    // Responses - continue regardless of individual email failure
    if ($attendee_selection !== 'all') {
        delete_transient($lock_key);
        
        $response_data = array(
            'is_complete' => true,
            'emails_sent' => 1,
            'emails_succeeded' => $send_result ? 1 : 0,
            'total_attendees' => 1,
            'current_recipient_name' => $name,
            'current_recipient_email' => $email,
            'current_recipient' => $email,
            'current_status' => $current_status,
            'current_details' => $current_details
        );
        
        if (!$send_result && isset($error_message)) {
            $response_data['warning'] = $error_message;
        }
        
        wp_send_json_success($response_data);
    } else {
        // Check if we've processed all attendees
        $is_complete = ($start_index + 1 >= $total_attendees);
        
        $response_data = array(
            'is_complete' => $is_complete,
            'emails_sent' => $start_index + 1,
            'emails_succeeded' => $success_count,
            'total_attendees' => $total_attendees,
            'current_recipient_name' => $name,
            'current_recipient_email' => $email,
            'current_recipient' => $email,
            'current_status' => $current_status,
            'current_details' => $current_details
        );
        
        // Add failure summary if complete
        if ($is_complete) {
            $failed_emails = get_transient('cert_failed_' . $course_id);
            if (!empty($failed_emails)) {
                $response_data['failed_count'] = count($failed_emails);
                $response_data['failed_emails'] = $failed_emails;
            }
            
            delete_transient($lock_key);
            delete_transient('cert_failed_' . $course_id); // Clean up after reporting
            
            $failed_count = isset($response_data['failed_count']) ? $response_data['failed_count'] : 0;
            error_log("Certificate batch complete: {$success_count} succeeded, {$failed_count} failed out of {$total_attendees} for course ID {$course_id}");
        }
        
        wp_send_json_success($response_data);
    }
}

// Add AJAX handler for CSV upload
add_action('wp_ajax_upload_attendees_csv', 'handle_attendees_csv_upload');

function handle_attendees_csv_upload() {
    if (!current_user_can('manage_certificates')) {
        wp_send_json_error('Insufficient permissions');
        return;
    }

    $course_id = intval($_POST['course_id']);
    
    if (!$course_id) {
        wp_send_json_error('Invalid course ID');
        return;
    }

    if (!isset($_FILES['attendees_csv'])) {
        wp_send_json_error('No file uploaded');
        return;
    }

    $file = $_FILES['attendees_csv'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        wp_send_json_error('File upload error');
        return;
    }

    // Read CSV content and remove BOM if present
    $csv_content = file_get_contents($file['tmp_name']);
    if ($csv_content === false) {
        wp_send_json_error('Failed to read CSV file');
        return;
    }

    // Remove BOM if present
    $bom = pack('H*','EFBBBF');
    $csv_content = preg_replace("/^$bom/", '', $csv_content);

    // Normalize line endings
    $csv_content = str_replace(array("\r\n", "\r"), "\n", $csv_content);
    
    // Split into rows and clean up empty rows
    $rows = array_filter(explode("\n", $csv_content), 'strlen');
    
    // Parse first row for headers
    $headers = array_map(function($header) {
        return trim(strtolower($header));
    }, str_getcsv($rows[0]));

    // Debug headers
    error_log('CSV Headers after BOM removal: ' . print_r($headers, true));
    
    $required_columns = array('attendee', 'attendee_email_address');
    $optional_columns = array('attendee_hours', 'attendee_credentials', 'attendee_ches');
    
    foreach ($required_columns as $column) {
        if (!in_array($column, $headers)) {
            wp_send_json_error("Missing required column: $column (Found: " . implode(', ', $headers) . ")");
            return;
        }
    }

    $name_index = array_search('attendee', $headers);
    $email_index = array_search('attendee_email_address', $headers);
    $hours_index = array_search('attendee_hours', $headers);
    $credentials_index = array_search('attendee_credentials', $headers);
    $ches_index = array_search('attendee_ches', $headers);

    $attendees = array();
    // Start from index 1 to skip headers
    for ($i = 1; $i < count($rows); $i++) {
        $row = str_getcsv($rows[$i]);
        if (isset($row[$name_index], $row[$email_index])) {
            $name = trim($row[$name_index]);
            $email = trim($row[$email_index]);
            $hours = ($hours_index !== false && isset($row[$hours_index])) ? trim($row[$hours_index]) : '';
            $credentials = ($credentials_index !== false && isset($row[$credentials_index])) ? trim($row[$credentials_index]) : '';
            $ches = ($ches_index !== false && isset($row[$ches_index])) ? trim($row[$ches_index]) : '';
            
            if (!empty($name) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $attendees[] = array(
                    'attendee' => $name,
                    'attendee_email_address' => $email,
                    'attendee_hours' => $hours,
                    'attendee_credentials' => $credentials,
                    'attendee_ches' => $ches
                );
            }
        }
    }

    if (empty($attendees)) {
        wp_send_json_error('No valid attendees found in CSV');
        return;
    }

    update_field('attendees', $attendees, $course_id);

    wp_send_json_success(array(
        'count' => count($attendees),
        'course_name' => get_field('training_name', $course_id) ?: get_the_title($course_id)
    ));
}

// AJAX handler for getting course attendees
add_action('wp_ajax_get_course_attendees', 'get_course_attendees_via_ajax');

function get_course_attendees_via_ajax() {
    $course_id = intval($_POST['course_id']);
    $status_filter = isset($_POST['status_filter']) ? sanitize_text_field($_POST['status_filter']) : 'all';
    
    if (!$course_id) {
        wp_send_json_error('Invalid course ID');
        return;
    }
    
    $attendees = get_field('attendees', $course_id);
    
    if (!$attendees || !is_array($attendees)) {
        wp_send_json_error('No attendees found for this training.');
        return;
    }
    
    // Calculate statistics
    $stats = array(
        'total' => count($attendees),
        'sent' => 0,
        'pending' => 0,
        'failed' => 0
    );
    
    // Collect attendees with their original indices
    $formatted_attendees = array();
    foreach ($attendees as $index => $attendee) {
        $status = isset($attendee['certificate_status']) ? $attendee['certificate_status'] : 'pending';
        
        // Update statistics
        if ($status === 'sent') {
            $stats['sent']++;
        } elseif ($status === 'pending') {
            $stats['pending']++;
        } else {
            $stats['failed']++;
        }
        
        // Apply status filter
        $include_attendee = false;
        
        if ($status_filter === 'all') {
            $include_attendee = true;
        } elseif ($status_filter === 'failed') {
            // "Failed" includes all non-sent, non-pending statuses
            $include_attendee = ($status !== 'sent' && $status !== 'pending');
        } else {
            // Exact match for specific status
            $include_attendee = ($status === $status_filter);
        }
        
        if ($include_attendee) {
            $formatted_attendees[$index] = array(
                'name' => $attendee['attendee'],
                'email' => $attendee['attendee_email_address'],
                'hours' => isset($attendee['attendee_hours']) ? $attendee['attendee_hours'] : '',
                'credentials' => isset($attendee['attendee_credentials']) ? $attendee['attendee_credentials'] : '',
                'ches' => isset($attendee['attendee_ches']) ? $attendee['attendee_ches'] : '',
                'status' => $status
            );
        }
    }
    
    // Sort attendees by name (A to Z)
    uasort($formatted_attendees, function($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });
    
    // Debug log to verify sorting
    error_log('Filtered attendees (' . $status_filter . '): ' . count($formatted_attendees) . ' of ' . $stats['total']);
    
    // Convert to numerically indexed array while preserving original indices
    $indexed_attendees = [];
    foreach ($formatted_attendees as $index => $attendee) {
        $indexed_attendees[] = [
            'index' => $index,  // Store original index as a value
            'name' => $attendee['name'],
            'email' => $attendee['email'],
            'hours' => $attendee['hours'],
            'credentials' => $attendee['credentials'],
            'ches' => $attendee['ches'],
            'status' => $attendee['status']
        ];
    }
    
    wp_send_json_success(array(
        'attendees' => $indexed_attendees,
        'stats' => $stats
    ));
}

function get_current_user_attc_groups( $user_id = null ) {
    $settings = certificate_generator_get_settings();
    
    // Check if filtering is enabled
    if (!$settings['enable_network_filtering']) {
        return array(); // No filtering - user sees all trainings
    }
    
    $role_group_mapping = $settings['role_group_mappings'];
    
    // Use current user if no user ID provided
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    
    // Return empty array if no user ID
    if ( ! $user_id ) {
        return array();
    }
    
    // Get user's groups using PublishPress hook
    $user_groups = pp_get_groups_for_user( $user_id );
    
    if ( empty( $user_groups ) ) {
        return array();
    }

    $matching_roles = get_matching_role_mappings($user_groups, $role_group_mapping);

    return $matching_roles;
}

// Function to get matching role mappings
function get_matching_role_mappings($user_groups, $role_group_mapping) {
    $matching_mappings = array();
    
    // Extract metagroup_ids from user groups
    foreach ($user_groups as $group) {
        if (isset($group->metagroup_id) && isset($role_group_mapping[$group->metagroup_id])) {
            $matching_mappings[$group->metagroup_id] = $role_group_mapping[$group->metagroup_id];
        }
    }
    
    return $matching_mappings;
}
