<?php
session_start();
require __DIR__ . '/includes/legal.php';

render_legal_page('Privacy', 'What the portal keeps about you, and who can see it.', [
    ['What we keep', 'When you register we keep your name, email, phone number, your parish and your password (stored only in scrambled form, never as plain text). If you add a profile photo, we keep that too.'],
    ['Donations', 'A donation record holds your name, contact details, the amount, the purpose and the receipt you upload. Receipts are private: only you and the administrators of the parish you gave to can open them.'],
    ['Event registrations', 'When you register for an event, the parish administrators can see your name, contact details and the number of people, so they can plan and contact you about that event.'],
    ['Who can see it', 'Church Administrators see information for their own parish only. The Super Administrator can see all parishes. We do not sell your information or share it with advertisers.'],
    ['Emails', 'We email you only about things you did on the portal, such as resetting your password, a donation decision or an update on an event you registered for.'],
    ['Your choices', 'You can update your details on your profile page at any time. To have your account removed, contact the parish office.'],
]);
