<?php
// Reminder emails for people registered for an event (my-events.php button, and automatically the day before).
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/event_helpers.php';

/**
 * Emails one reminder. $row needs: name, email, people, title, event_date, start_time, end_time, is_all_day,
 * location, church_name, church_email.
 */
function send_event_reminder(array $row): bool
{
    $when = $row['event_date'] === date('Y-m-d') ? 'today' : ($row['event_date'] === date('Y-m-d', strtotime('+1 day')) ? 'tomorrow' : 'coming up');
    $body = "Hi {$row['name']},\n\nThis is a reminder that an event you registered for is $when:\n\n"
        . $row['title'] . "\n" . date('l, j F Y', strtotime($row['event_date'])) . ', ' . event_time_text($row)
        . ($row['location'] ? "\nLocation: " . $row['location'] : '') . "\nChurch: " . $row['church_name']
        . "\n" . people_label((int)$row['people']) . " registered\n\n"
        . "You can see your registrations here:\n" . site_base_url() . "/my-events.php\n\nGod bless.\n\nParish Office\n" . $row['church_name'] . "\n";
    return send_plain_email((string)$row['email'], 'Reminder: ' . $row['title'] . ' - ' . date('j M Y', strtotime($row['event_date'])), $body, $row);
}

/**
 * The automatic reminder: everyone registered for an event that takes place tomorrow (or today, if nobody
 * opened the site yesterday) is emailed once. There is no cron job, so this runs when a page is opened;
 * the check itself is one small query. Returns how many reminders went out.
 */
function send_due_event_reminders(PDO $pdo): int
{
    try {
        $stmt = $pdo->query(
            "SELECT r.registration_id, r.name, COALESCE(NULLIF(r.email, ''), u.user_email) AS email, r.people,
                    e.title, e.event_date, e.start_time, e.end_time, e.is_all_day, e.location, c.church_name, c.church_email
               FROM event_registrations r
               JOIN events e ON e.event_id = r.event_id
               JOIN church_info c ON c.church_info_id = e.church_id
          LEFT JOIN user u ON u.user_id = r.user_id
              WHERE r.reminded_at IS NULL AND e.repeat_weekly = 0
                AND e.event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 1 DAY)
              LIMIT 50"
        );
        $due = $stmt->fetchAll();
        if (!$due) return 0;

        $claim = $pdo->prepare('UPDATE event_registrations SET reminded_at = NOW() WHERE registration_id = ? AND reminded_at IS NULL');
        $undo  = $pdo->prepare('UPDATE event_registrations SET reminded_at = NULL WHERE registration_id = ?');
        $sent = 0;
        foreach ($due as $row) {
            // Marked first, so two page loads at the same moment cannot both send it.
            $claim->execute([$row['registration_id']]);
            if ($claim->rowCount() !== 1) continue;
            if (!filter_var((string)$row['email'], FILTER_VALIDATE_EMAIL)) continue;   // no address: nothing to send, stays marked
            if (send_event_reminder($row)) {
                $sent++;
            } else {
                $undo->execute([$row['registration_id']]);   // try again the next time a page is opened
                break;                                        // the mail account is not working right now
            }
        }
        return $sent;
    } catch (PDOException $ex) {
        return 0;   // column not added yet (database/event_reminders.sql)
    }
}
