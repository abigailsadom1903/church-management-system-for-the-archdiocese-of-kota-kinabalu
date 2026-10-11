<?php
session_start();
require __DIR__ . '/includes/legal.php';

render_legal_page('Terms of Use', 'The simple rules for using the Archdiocese of Kota Kinabalu parish portal.', [
    ['Who this portal is for', 'The portal helps parishioners and visitors find parish information, read announcements and bulletins, see the parish calendar, register for events and send donations. Some services are available only after you sign in.'],
    ['Your account', 'Please give correct details when you register and keep your password private. You are responsible for what is done with your account. Tell the parish office if you think someone else has used it.'],
    ['Using the services respectfully', 'Please do not try to break into the portal, upload harmful files, or use it to send unwanted messages. Accounts that misuse the portal may be removed.'],
    ['Donations and offerings', 'A donation you record here is reviewed by the parish office before it is approved. Approval depends on the parish confirming that the payment was received. Recording a donation does not move money by itself.'],
    ['Information on the portal', 'We work to keep Mass times, events and church details up to date, but they can change. Please confirm with the parish office when something is important to you.'],
    ['Changes', 'We may update these terms from time to time. The version on this page is the current one.'],
]);
