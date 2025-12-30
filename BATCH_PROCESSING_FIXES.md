# Certificate Generator Plugin - Batch Processing Fixes (v1.4)

## Problem Summary
The plugin was stopping after approximately 35 emails out of 132, with only 111 of 132 attendees receiving certificates in recent tests.

## Root Causes Identified

### 1. **PHP Execution Time Limits**
- Default PHP `max_execution_time` is typically 30-60 seconds
- Processing 132 certificates at ~1-2 seconds each = 132-264 seconds needed
- Script was timing out after ~35 emails (≈60 seconds)

### 2. **Memory Exhaustion**
- Each PDF with background image: 1-2MB in memory
- Generated PDFs weren't being deleted after sending
- FPDF objects weren't properly destroyed
- Memory accumulated with each iteration
- By email 35: 35-70MB+ accumulated

### 3. **Lock Mechanism Issues**
- Lock set at start but never cleaned on timeout/error
- Stale locks prevented retry attempts
- Lock timestamp not checked for expiration

### 4. **No AJAX Timeout Handling**
- JavaScript had no timeout on AJAX requests
- If one request hung, entire process stopped
- No error recovery mechanism

## Fixes Applied

### 1. **Critical: Continue Processing on Email Failures** ⭐
**Problem:** When a single email failed (bad address, mailbox full, etc.), the entire batch stopped. If attendee #36 had a bad email, attendees #37-132 never received certificates.

**Solution:** 
```php
// OLD CODE - stopped entire batch:
if (!$send_result) {
    delete_transient($lock_key);
    wp_send_json_error($error_message);
    return; // ❌ Stops everything
}

// NEW CODE - continues processing:
if (!$send_result) {
    // Log the failure
    error_log($error_message);
    
    // Track failed emails for end-of-batch report
    $failed_emails = get_transient('cert_failed_' . $course_id) ?: array();
    $failed_emails[] = array(
        'name' => $name,
        'email' => $email,
        'error' => $error_message
    );
    set_transient('cert_failed_' . $course_id, $failed_emails, 3600);
    
    // ✅ Continue to next attendee - don't stop!
}
```

**Impact:**
- Batch continues even if some emails fail
- All valid email addresses receive certificates
- Failed emails are tracked and reported at completion
- Admin sees summary: "120 succeeded, 12 failed out of 132"

**Admin Interface Shows:**
```
Certificate generation complete! 120 of 132 certificates sent successfully.
⚠ 12 failed:
• John Doe (johndoe@badomain.com) - Failed to send certificate
• Jane Smith (jane@nonexistent.net) - Failed to send certificate
...
Check error logs for full details.
```

### 2. **Execution Time Management**
```php
// Added to send_certificates_via_ajax()
set_time_limit(300); // 5 minutes per request
```
**Impact:** Allows each AJAX request up to 5 minutes to complete

### 2. **Memory Management**
```php
// Increased memory limit
$current_memory = ini_get('memory_limit');
if (wp_convert_hr_to_bytes($current_memory) < wp_convert_hr_to_bytes('256M')) {
    ini_set('memory_limit', '256M');
}

// Added memory monitoring
$memory_usage = memory_get_usage(true);
$memory_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
if ($memory_usage > ($memory_limit * 0.8)) {
    // Abort and report error
}

// Clean up PDFs after sending
if (file_exists($pdf_path)) {
    @unlink($pdf_path);
}

// Force garbage collection
if (function_exists('gc_collect_cycles')) {
    gc_collect_cycles();
}
```
**Impact:** 
- Prevents memory exhaustion
- Cleans up temporary files immediately
- Alerts admin if memory is critically low

### 3. **Improved Lock Mechanism**
```php
// Lock now includes timestamp
set_transient($lock_key, time(), 600); // 10 minute expiration

// Check for stale locks
$existing_lock = get_transient($lock_key);
if ($existing_lock) {
    if ((time() - $existing_lock) < 600) {
        // Active lock, abort
    } else {
        // Stale lock, delete and proceed
        delete_transient($lock_key);
    }
}
```
**Impact:** 
- Prevents permanent locks from failed processes
- Allows automatic recovery after timeout
- Extended to 10 minutes for large batches

### 4. **AJAX Timeout Handling**
```javascript
$.ajax({
    // ...
    timeout: 60000, // 60 second timeout per request
    success: function(response) {
        // Continue to next certificate
    },
    error: function(xhr, status, error) {
        if (status === 'timeout') {
            $('#progress-message').text('Request timed out...');
        }
        // Stop processing and show error
    }
});
```
**Impact:**
- Detects and reports hung requests
- Provides user feedback on timeout errors
- Prevents infinite waiting

### 5. **Enhanced Error Logging**
```php
error_log("Processing certificate " . ($start_index + 1) . " of {$total_attendees} for {$name} ({$email})");
error_log("Certificate batch complete: {$total_attendees} certificates sent for course ID {$course_id}");
```
**Impact:**
- Better troubleshooting capability
- Track exactly where process stops
- Confirm successful completion

## Testing Recommendations

### Test 1: Small Batch (5 attendees)
- Verify basic functionality still works
- Check all 5 receive emails
- Confirm PDFs are generated correctly

### Test 2: Medium Batch (50 attendees)
- Monitor memory usage in error logs
- Check completion time
- Verify all 50 receive emails

### Test 3: Large Batch (130+ attendees)
- Full stress test
- Monitor server resources
- Confirm all attendees receive emails
- Check for timeout errors

## Server Requirements

### Minimum Configuration
```
PHP max_execution_time: 300 (5 minutes)
PHP memory_limit: 256M
PHP max_input_time: 300
```

### Recommended Configuration
```
PHP max_execution_time: 600 (10 minutes)
PHP memory_limit: 512M
PHP max_input_time: 600
```

### How to Check Current Settings
Add this to the admin page (with ?debug=1):
```php
if (isset($_GET['debug']) && $_GET['debug'] == '1') {
    echo '<h3>Server Configuration</h3>';
    echo 'max_execution_time: ' . ini_get('max_execution_time') . '<br>';
    echo 'memory_limit: ' . ini_get('memory_limit') . '<br>';
    echo 'max_input_time: ' . ini_get('max_input_time') . '<br>';
}
```

## Monitoring During Batch Processing

### What to Watch in Error Logs
```
// Successful processing looks like:
Processing certificate 1 of 132 for John Doe (john@example.com)
Memory at end_certificate_generation: Current=45MB, Peak=48MB
Successfully sent certificate to: john@example.com
Processing certificate 2 of 132 for Jane Smith (jane@example.com)
...
Certificate batch complete: 132 certificates sent for course ID 12345
```

### Red Flags in Logs
```
// Memory issues:
Memory usage critical: 245MB of 256MB
Memory usage too high. Please contact your administrator...

// Timeout issues:
(no new log entries after a certain point)

// Email failures:
Failed to send certificate to: user@example.com: SMTP Error
```

## Fallback Strategy

If issues persist with very large batches (200+):

### Option 1: Batch Processing in Chunks
Modify the admin interface to process in batches of 50:
- Process attendees 1-50
- Wait 30 seconds
- Process attendees 51-100
- etc.

### Option 2: Background Processing
Use WordPress Cron to process certificates in background:
- Queue all attendees
- Process 5-10 per minute via cron
- Send completion email to admin when done

### Option 3: External Service
For very large volumes, consider:
- Queue system (Redis/RabbitMQ)
- Separate worker process
- Cloud-based PDF generation

## Version History

**v1.3 → v1.4 Changes:**
- ✅ **CRITICAL:** Fixed batch stopping on failed emails - now continues processing
- ✅ Added failure tracking and end-of-batch reporting
- ✅ Fixed execution time limits (5 min per request)
- ✅ Improved memory management (256M minimum, monitoring)
- ✅ Enhanced lock mechanism (timestamp-based, auto-cleanup)
- ✅ Added AJAX timeout handling (60s per request)
- ✅ Immediate PDF cleanup after sending
- ✅ Better error logging and reporting
- ✅ Stale lock detection and recovery

## Installation Instructions

1. **Backup current plugin file**
   ```bash
   cp course-certificate-generator.php course-certificate-generator.php.backup
   ```

2. **Replace with fixed version**
   - Upload `course-certificate-generator-fixed.php`
   - Rename to `course-certificate-generator.php`

3. **Test with small batch first**
   - Use 5-10 attendees
   - Verify all functionality works

4. **Monitor first large batch**
   - Check error logs during processing
   - Watch for memory/timeout issues
   - Verify completion

5. **Adjust server settings if needed**
   - Contact hosting provider
   - Request increased limits if issues persist

## Support & Troubleshooting

### Common Issues

**Issue:** Batch stops at a specific attendee (e.g., always stops at #36)
**Solution:** This was likely a bad email address. v1.4 now continues processing even when individual emails fail. You'll see a failure report at completion.

**Issue:** "Memory usage too high" error
**Solution:** Contact hosting provider to increase PHP memory_limit to 512M

**Issue:** "Request timed out" errors
**Solution:** Contact hosting provider to increase max_execution_time to 600

**Issue:** Emails stop mid-batch, no error shown
**Solution:** Check server error logs for PHP fatal errors or email server limits

**Issue:** "Already in progress" error won't clear
**Solution:** Wait 10 minutes for lock to expire, or manually delete transient `cert_lock_{course_id}`

### Contact Information
For additional support with this plugin, contact:
- Plugin Author: Adam Murray (Draft Horse Studio)
- WordPress Admin (check hosting provider logs)
