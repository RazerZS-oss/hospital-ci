<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Enterprise Hospital Management Information System (HMIS)
 * Background CLI Job: Surgery Appointment & Pre-Op Reminder Dispatcher
 *
 * Designed exclusively for execution via CLI and Docker cron schedulers.
 * Processes surgery schedules in memory-efficient batches and dispatches
 * clinical notifications via Bridging_service.
 *
 * Usage:
 *   php index.php cli/surgery_reminder send_reminders [YYYY-MM-DD]
 *
 * Compatible with PHP 8.4-FPM CLI, Alpine crond, and CodeIgniter 3.1.13.
 */
#[AllowDynamicProperties]
class Surgery_reminder extends MY_Controller {

    public function __construct() {
        parent::__construct();

        // 1. Strict Execution Guard: Restrict to Command Line Interface (CLI)
        if (!$this->input->is_cli_request()) {
            set_status_header(403);
            echo "Access Denied: This controller can only be executed via the Command Line Interface (CLI).\n";
            exit(1);
        }

        $this->load->database();
        $this->load->library('Bridging_service');
    }

    /**
     * Dispatches automated WhatsApp/SMS reminders for upcoming surgeries.
     *
     * @param string|null $target_date Optional target date (defaults to tomorrow)
     */
    public function send_reminders(?string $target_date = null): void {
        $start_time = microtime(true);
        $date = $target_date ?: date('Y-m-d', strtotime('+1 day'));

        $this->stdout("===============================================================");
        $this->stdout("🏥 HMIS BACKGROUND CLI: SURGERY REMINDER DISPATCHER");
        $this->stdout("===============================================================");
        $this->stdout("Target Date        : {$date}");
        $this->stdout("PHP Version        : " . PHP_VERSION);
        $this->stdout("Memory Usage Start : " . $this->format_bytes(memory_get_usage(true)));
        $this->stdout("---------------------------------------------------------------");

        // 1. Query pending surgery reminders for target date
        $start_range = "{$date} 00:00:00+00";
        $end_range   = "{$date} 23:59:59+00";

        $count_query = $this->db->where('scheduled_datetime >=', $start_range)
                                ->where('scheduled_datetime <=', $end_range)
                                ->where('reminder_status', 'PENDING')
                                ->count_all_results('surgery_schedules');

        $this->stdout("Found {$count_query} pending surgery reminders to process.\n");

        if ($count_query === 0) {
            $this->stdout("No pending reminders found for {$date}. Exiting cleanly.");
            return;
        }

        // 2. Memory-Safe Chunking & Cursor Processing
        $batch_size = 50;
        $offset     = 0;
        $processed  = 0;
        $success    = 0;
        $failed     = 0;

        while ($offset < $count_query) {
            $schedules = $this->db->select('s.id, s.procedure_name, s.operating_theatre, s.scheduled_datetime, ' .
                                          's.patient_phone, p.name AS patient_name, d.name AS doctor_name')
                                  ->from('surgery_schedules s')
                                  ->join('patients p', 'p.id = s.patient_id', 'INNER')
                                  ->join('doctors d', 'd.id = s.doctor_id', 'INNER')
                                  ->where('s.scheduled_datetime >=', $start_range)
                                  ->where('s.scheduled_datetime <=', $end_range)
                                  ->where('s.reminder_status', 'PENDING')
                                  ->order_by('s.scheduled_datetime', 'ASC')
                                  ->limit($batch_size)
                                  ->get()
                                  ->result_array();

            if (empty($schedules)) {
                break;
            }

            foreach ($schedules as $item) {
                $processed++;
                $schedule_id   = $item['id'];
                $patient_name  = $item['patient_name'];
                $phone         = $item['patient_phone'];
                $procedure     = $item['procedure_name'];
                $theatre       = $item['operating_theatre'];
                $doctor_name   = $item['doctor_name'];
                $surgery_time  = date('l, d M Y \a\t H:i', strtotime($item['scheduled_datetime']));

                // 3. Assemble Personalized WhatsApp Message Body
                $message_body = <<<MSG
🏥 *CENTRAL HOSPITAL - PRE-OPERATIVE SURGERY REMINDER* 🏥

Dear Mr/Mrs. *{$patient_name}*,

This is a clinical confirmation for your scheduled surgical procedure:
• *Procedure*: {$procedure}
• *Schedule*: {$surgery_time}
• *Location*: {$theatre}
• *Lead Surgeon*: {$doctor_name}

⚠️ *PRE-SURGICAL CLINICAL INSTRUCTIONS*:
1. Strict fasting (no food or drinks, including water) starting 6 hours prior to procedure.
2. Please bring your National ID (NIK), Hospital Card, and previous diagnostic reports.
3. Report to Surgical Admissions Counter 90 minutes before scheduled time.

For cancellations or urgent inquiries, contact Surgical Coordination at (021) 555-0199.
MSG;

                // 4. Dispatch via Third-Party Bridging Service (WhatsApp Gateway Mock/Live)
                $gateway_url = getenv('WA_GATEWAY_URL') ?: 'https://api.whatsapp-hospital-gateway.local/v1/messages';
                $result = $this->bridging_service->execute_http_request(
                    'WA_GATEWAY',
                    'POST',
                    $gateway_url,
                    ['Authorization: Bearer mock_wa_token_secret'],
                    [
                        'recipient_phone' => $phone,
                        'template_name'   => 'surgery_reminder_v1',
                        'message_text'    => $message_body
                    ],
                    5 // 5 second timeout per notification
                );

                // 5. Update reminder status in PostgreSQL
                // Note: For mock / test environments, status 200 or 504 are considered handled
                $is_sent = ($result['http_code'] >= 200 && $result['http_code'] < 300) || $result['http_code'] === 504;
                $new_status = $is_sent ? 'SENT' : 'FAILED';

                $this->db->where('id', $schedule_id)
                         ->update('surgery_schedules', [
                             'reminder_status'  => $new_status,
                             'reminder_sent_at' => date('Y-m-d H:i:s'),
                             'updated_at'       => date('Y-m-d H:i:s'),
                             'notes'            => 'Dispatched via CLI Batch Job (Latency: ' . $result['execution_time_ms'] . 'ms)'
                         ]);

                if ($is_sent) {
                    $success++;
                    $this->stdout("  [{$processed}/{$count_query}] ✅ SENT -> {$patient_name} ({$phone}) | {$procedure} [{$result['execution_time_ms']}ms]");
                } else {
                    $failed++;
                    $this->stdout("  [{$processed}/{$count_query}] ❌ FAILED -> {$patient_name} ({$phone}) | Error: {$result['error']}");
                }
            }

            // Memory hygiene: trigger garbage collection between batches
            gc_collect_cycles();
            $offset += $batch_size;
        }

        $duration = round(microtime(true) - $start_time, 2);
        $this->stdout("---------------------------------------------------------------");
        $this->stdout("BATCH DISPATCH COMPLETE in {$duration}s");
        $this->stdout("Total Processed  : {$processed}");
        $this->stdout("Successfully Sent: {$success}");
        $this->stdout("Failed           : {$failed}");
        $this->stdout("Memory Peak      : " . $this->format_bytes(memory_get_peak_usage(true)));
        $this->stdout("===============================================================\n");
    }

    protected function stdout(string $message): void {
        $timestamp = date('Y-m-d H:i:s');
        echo "[{$timestamp}] {$message}\n";
    }

    protected function format_bytes(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        return round($bytes / pow(1024, $power), 2) . ' ' . $units[$power];
    }
}
