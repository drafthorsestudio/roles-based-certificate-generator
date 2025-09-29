<?php
/*
Plugin Name: Course Certificate Generator - Roles-based version
Plugin URI: https://drafthorsestudio.com/plugins/
Description: Generate and email course completion certificates for custom post type "training".
Version: 1.1c custom role
Author: Adam Murray
Author URI: https://drafthorsestudio.com/
License: GPL2
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}



// Define role to group term mapping so it can be modified easily
$role_group_mapping = array(
    'central_east' => 'central-east-pttc',
    'central_east_editor' => 'central-east-pttc',
    'great_lakes_editor' => 'great-lakes-pttc',
    'mid_america_editor' => 'mid-america-pttc',
    'new_england_editor' => 'new-england-pttc',
    'northeast___caribbean_editor' => 'northeast-caribbean-pttc',
    'southeast_editor' => 'southeast-pttc',
    'mountain_plains_editor' => 'mountain-plains-pttc',
    'northwest_editor' => 'northwest-pttc',
    'pacific_southwest_editor' => 'pacific-southwest-pttc',
    'south_southwest_editor' => 'south-southwest-pttc',
);

// Include FPDF library
function course_certificate_include_fpdf() {
    require_once plugin_dir_path(__FILE__) . 'includes/fpdf.php';
}
add_action('plugins_loaded', 'course_certificate_include_fpdf');

// Generate PDF certificate
function generate_course_certificate($name, $course_name, $course_id, $attendee_hours = '', $attendee_credentials = '', $attendee_ches = '') {
    
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
    if (!empty($certificate_background)) {
        $pdf->Image($certificate_background, 0, 0, $page_width, $page_height);
    }

    $pdf->SetFont('Arial', 'B', 24);
    $pdf->SetXY(0, $name_position);
    $pdf->Cell($page_width, 0, $name, 0, 1, 'C');

    $pdf->SetXY(0, $course_position);
    $pdf->SetFont('Arial', '', 18);
    $pdf->Cell($page_width, 0, $course_name, 0, 1, 'C');

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
    $pdf->Cell($page_width, 0, !empty($misc_text_position) ? "".$certificate_misc_text : "", 0, 1, 'C');

    $pdf->SetXY(0, $date_position);
    $pdf->SetFont('Arial', '', 16);
    $pdf->Cell($page_width, 0, $training_date, 0, 1, 'C');

    $upload_dir = wp_upload_dir();

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

    return $file_path;
}

// add_filter( 'wp_mail_from', function( $email ) {
// 	return 'info@ctcsrh.org';
// } );

// Send the certificate via email
function send_certificate_email($name, $email, $course_name, $course_id, $attendee_hours = '', $attendee_credentials = '', $attendee_ches = '', $course_center) {
    $pdf_path = generate_course_certificate($name, $course_name, $course_id, $attendee_hours, $attendee_credentials, $attendee_ches);

    // Get email subject from ACF field, fallback to default if not set
    $subject = get_field('email_subject', $course_id);
    if (empty($subject)) {
        $subject = 'Your Course Completion Certificate';
    }
	
	$message = "Thank you for participating in $course_name, hosted by the $course_center. ";
	$message .= "Attached is your certificate, awarded for attending the training. Please keep this certificate for your records. If you have any questions or require additional support, feel free to reach out to us. ";
	$message .= "You can contact your regional center that awarded your certificate or <a href='https://attcnetwork.org/find-your-center/'>find them here</a>.";
 	//$message = "Hello $name,<br/><br/>Congratulations on completing the $course_name course. We have attached your certificate to this email.";
    //$message .= "<br/>You can find this certificate is also available at CTCSRH.org. You may <a href='https://ctcsrh.org/log-in/'>log in here</a> to view this and any of your other certificates.";
    //$message .= "<br/>If you do not have an account, <a href='https://ctcsrh.org/register/'>create one</a> using this email address, and you will have access to your certificates and other useful tools.";
    $headers = array('Content-Type: text/html; charset=UTF-8');

    $attachments = array($pdf_path);

    // error_log("Attempting to send email to: " . $email . " with subject: " . $subject);
    $result = wp_mail($email, $subject, $message, $headers, $attachments);

    // unlink($pdf_path);

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

                    $args = array(
                        'post_type'      => 'training',
                        'posts_per_page' => -1,
                        'meta_key'       => 'training_date',
                        'orderby'        => 'meta_value',
                        'order'          => 'DESC',
                        'meta_type'      => 'DATE',
                    );

                    if (!empty($current_user_groups)) {
                        $term_ids = [];
                        foreach ($current_user_groups as $slug) {
                            $term = get_term_by('slug', $slug, 'group');
                            if ($term) {
                                $term_ids[] = $term->term_id;
                            }
                        }

                        if (!empty($term_ids)) {
                            $args['tax_query'] = array(
                                array(
                                    'taxonomy' => 'group',
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

                // $user_id = get_current_user_id();
                // $user_groups = pp_get_groups_for_user( $user_id );
                // echo '<pre>'; print_r($user_groups); echo '</pre>';

                if (isset($_GET['debug']) && $_GET['debug'] == '1') {
                    echo 'Current PublishPress roles: ';
                    foreach ( $current_user_groups as $item ) {
                        echo $item . ', ';
                    }
                    $user = wp_get_current_user();
                    echo '<p>Has manage_certificates role: ' . (current_user_can('manage_certificates') ? 'YES' : 'NO') . '</p>';
                    // echo '<pre>User capabilities: ' . print_r($user->allcaps, true) . '</pre>';
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
                
                <div class="attendee-selection" style="margin-bottom: 15px;">
                    <label for="attendee_select"><strong>Select Attendee:</strong></label>
                    <select name="attendee_select" id="attendee_select" style="margin: 10px 0; display: block; min-width: 300px;">
                        <option value="">-- Select a course first --</option>
                    </select>
                    <p class="description">Select "All" to generate certificates for all attendees, or select a specific attendee.</p>
                </div>
                
                <button type="button" id="start-sending-certificates" class="button button-primary">Generate & Send Certificates</button>
                <div id="progress-area" style="margin-top: 10px; display: none;">
                    <p id="progress-message">Sending certificates...</p>
                    <div class="progress-details">
                        <p><strong>Emails sent:</strong> <span id="emails-sent-count">0</span></p>
                        <p id="current-recipient"></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
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
                return;
            }
            
            // Show loading state
            $('#attendee_select').html('<option value="">Loading attendees...</option>');
            
            // Fetch attendees for the selected course
            $.post(ajaxurl, {
                action: 'get_course_attendees',
                course_id: courseId
            }, function(response) {
                if (response.success && response.data.attendees) {
                    var options = '<option value="all">All</option>';
                    $.each(response.data.attendees, function(_, attendee) {
                        options += '<option value="' + attendee.index + '">' + attendee.name + ' (' + attendee.email + ')</option>';
                    });
                    $('#attendee_select').html(options);
                } else {
                    $('#attendee_select').html('<option value="">No attendees found</option>');
                }
            }).fail(function() {
                $('#attendee_select').html('<option value="">Error loading attendees</option>');
            });
        });

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

        // Handle Certificate Generation
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
            $('#progress-message').text('Starting to send certificates...');
            $('#emails-sent-count').text('0');
            $('#current-recipient').text('');
            
            sendNextCertificate(courseId, 0, attendeeSelection);
        });

        function sendNextCertificate(courseId, startIndex, attendeeSelection) {
            $.post(ajaxurl, {
                action: 'send_certificates',
                course_id: courseId,
                start_index: startIndex,
                attendee_selection: attendeeSelection
            }, function(response) {
                if (response.success) {
                    $('#emails-sent-count').text(response.data.emails_sent);
                    
                    if (response.data.is_complete) {
                        $('#progress-message').text('All certificates have been sent successfully!');
                        $('#current-recipient').text('');
                        $('#start-sending-certificates').prop('disabled', false);
                    } else {
                        $('#progress-message').text(
                            'Sending certificates... ' + 
                            response.data.emails_sent + ' of ' + 
                            response.data.total_attendees
                        );
                        $('#current-recipient').text(
                            'Currently sending to: ' + response.data.current_recipient
                        );
                        sendNextCertificate(courseId, startIndex + 1, attendeeSelection);
                    }
                } else {
                    $('#progress-message').text('Error: ' + response.data);
                    $('#start-sending-certificates').prop('disabled', false);
                }
            }).fail(function() {
                $('#progress-message').text('Error: Failed to email certificates. Check email settings and try again.');
                $('#start-sending-certificates').prop('disabled', false);
            });
        }
    });
    </script>
    <?php
}

// AJAX handler for sending certificates
add_action('wp_ajax_send_certificates', 'send_certificates_via_ajax');

function send_certificates_via_ajax() {
    $course_id = intval($_POST['course_id']);
    $start_index = isset($_POST['start_index']) ? intval($_POST['start_index']) : 0;
    $attendee_selection = isset($_POST['attendee_selection']) ? $_POST['attendee_selection'] : 'all';
    
    $attendees = get_field('attendees', $course_id);
    
    if (!$attendees) {
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
            wp_send_json_error('Selected attendee not found.');
            return;
        }
    }
    
    $total_attendees = count($attendees);
    
    if ($start_index >= $total_attendees) {
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
    
    global $phpmailer;
    // Use training_name ACF field if available, otherwise fall back to post title
    $course_name = get_field('training_name', $course_id) ?: get_the_title($course_id);
	//get the center (group) applied to the course:
	$group_terms = wp_get_post_terms($course_id, 'group');
	$course_center = '';
	if (!is_wp_error($group_terms) && !empty($group_terms)) {
    	$course_center = $group_terms[0]->name;
	}
    $send_result = send_certificate_email($name, $email, $course_name, $course_id, $hours, $credentials, $ches, $course_center);

    if (!$send_result) {
        $error_message = "Failed to send certificate to {$email}";
        if (isset($phpmailer) && is_object($phpmailer) && !empty($phpmailer->ErrorInfo)) {
            $error_message .= ": " . $phpmailer->ErrorInfo;
        }
        wp_send_json_error($error_message);
        return;
    }

    // For a single attendee, we need to set is_complete to true immediately
    if ($attendee_selection !== 'all') {
        wp_send_json_success(array(
            'is_complete' => true,
            'emails_sent' => 1,
            'total_attendees' => 1,
            'current_recipient' => $email
        ));
    } else {
        // For "All" selection, check if we've processed all attendees
        wp_send_json_success(array(
            'is_complete' => false,
            'emails_sent' => $start_index + 1,
            'total_attendees' => $total_attendees,
            'current_recipient' => $email
        ));
    }
}

// Add AJAX handler for CSV upload
add_action('wp_ajax_upload_attendees_csv', 'handle_attendees_csv_upload');

function handle_attendees_csv_upload() {
    if (!current_user_can('manage_options')) {
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
    
    if (!$course_id) {
        wp_send_json_error('Invalid course ID');
        return;
    }
    
    $attendees = get_field('attendees', $course_id);
    
    if (!$attendees || !is_array($attendees)) {
        wp_send_json_error('No attendees found for this training.');
        return;
    }
    
    // Collect attendees with their original indices
    $formatted_attendees = array();
    foreach ($attendees as $index => $attendee) {
        $formatted_attendees[$index] = array(
            'name' => $attendee['attendee'],
            'email' => $attendee['attendee_email_address'],
            'hours' => isset($attendee['attendee_hours']) ? $attendee['attendee_hours'] : '',
            'credentials' => isset($attendee['attendee_credentials']) ? $attendee['attendee_credentials'] : '',
            'ches' => isset($attendee['attendee_ches']) ? $attendee['attendee_ches'] : ''
        );
    }
    
    // Sort attendees by name (A to Z)
    uasort($formatted_attendees, function($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });
    
    // Debug log to verify sorting
    error_log('Sorted attendees: ' . print_r(array_column($formatted_attendees, 'name'), true));
    
    // Convert to numerically indexed array while preserving original indices
    $indexed_attendees = [];
    foreach ($formatted_attendees as $index => $attendee) {
        $indexed_attendees[] = [
            'index' => $index,  // Store original index as a value
            'name' => $attendee['name'],
            'email' => $attendee['email'],
            'hours' => $attendee['hours'],
            'credentials' => $attendee['credentials'],
            'ches' => $attendee['ches']
        ];
    }
    
    wp_send_json_success(array(
        'attendees' => $indexed_attendees
    ));
}


function get_current_user_attc_groups( $user_id = null ) {
    global $role_group_mapping;
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