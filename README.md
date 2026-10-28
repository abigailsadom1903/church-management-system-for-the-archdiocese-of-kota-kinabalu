# Church Management System: Archdiocese of Kota Kinabalu

Final Year Project, Bachelor of Computer Science (Software Engineering), Universiti Malaysia Sabah (UMS).

**Status:** testing and evaluation. This is a local development version, not a verified production deployment.

A web-based system that centralises church announcements, bulletins, catechesis, donations and parish information, with separate administration for each church.

## Problem

Announcements, bulletins and parish information are spread across notice boards, printed bulletins, spreadsheets and messaging apps. This project brings selected parts of that into one web application with role-based administration.

## Roles

| Role | Purpose |
| --- | --- |
| Super administrator | System-wide administration (`super_admin`) |
| Church administrator | Manages the information of an assigned church (`church_admin`) |
| Parishioner / public user | Views public information; registered users have accounts |

## Modules in the code

These modules exist in the repository. Their test status is recorded in the Testing section; none has been formally tested yet.

- Announcements, with optional AI-assisted drafting
- Bulletin Builder: weekly bulletin from announcements, Masses and events; printable A4 page (Save as PDF)
- Catechesis: programmes, enrolment, lesson materials, attendance
- Sacrament information and deadlines; pastoral services; Mass times
- Church directory and church details
- Events, registrations and reminders
- Donations with receipt upload and administrator review; weekly collections
- Church Assistant chatbot
- Email verification and password reset using 6-digit OTP codes
- Administrator invitations
- Site content and reports

## Technology

PHP (plain PHP with shared includes, no framework), MySQL, HTML, CSS, JavaScript. XAMPP for local development. Git and GitHub.
AI features call the Google Gemini API (Church Assistant and announcement drafting) and the Groq API (announcement drafting). Email is sent through the settings in `config/mail.php`.

## Structure

```
/                 public and parishioner pages
churchadmin/      church administrator pages
superadmin/       super administrator pages
includes/         shared helpers (auth, mail, env loading, AI, per-module helpers)
config/           db.example.php, mail.example.php (copy and fill in locally)
database/         schema.sql, per-feature SQL scripts, seed scripts
uploads/          user uploads (git-ignored; no user files are committed)
```

## Run locally

1. Install XAMPP (Apache, PHP, MySQL) and Git.
2. Clone into `htdocs` with `git clone https://github.com/abigailsadom1903/church-management-system-for-the-archdiocese-of-kota-kinabalu.git`.
3. Copy `config/db.example.php` to `config/db.php` and `config/mail.example.php` to `config/mail.php`. Fill in your own local values (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` and your mail settings). Both files are git-ignored.
4. Copy `.env.example` to `.env`. Set `GEMINI_API_KEY` and `GROQ_API_KEY` with your own keys if you want the AI features. `.env` is git-ignored. Never commit it.
5. Create a MySQL database, then import `database/schema.sql`. It does not contain every table: tables for later features (for example OTP codes, Bulletin Builder, event registrants and Mass times) are in the other `.sql` files in `database/`. Import those next. If an import fails on a foreign key, import the script for the referenced table first.
6. Optional sample data: `database/demo_user.sql` and the `seed_*.php` scripts. These are for local testing only. Change or remove demo accounts before any real use.
7. Open `http://localhost/<folder-name>/`.

## Testing

No automated tests and no executed test results are in the repository yet. The cases below are **planned, not executed**. Results will be added only after they are run.

Planned test types: functional, form validation, boundary-value, role-based access, integration (for example announcement to bulletin), regression, and a System Usability Scale (SUS) evaluation.

| ID | Feature | Preconditions | Steps | Test data | Expected result | Actual result | Status |
| --- | --- | --- | --- | --- | --- | --- | --- |
| TC-AUTH-01 | Login | Valid church-admin account | Open login, submit credentials | Valid email and password | Redirected to church-admin dashboard | | Planned |
| TC-AUTH-02 | Login | Account exists | Submit wrong password | Valid email, wrong password | Error shown; no session created | | Planned |
| TC-AUTH-03 | OTP verification | Registration submitted | Enter an expired code | Code older than its expiry | Code rejected; resend offered | | Planned |
| TC-RBAC-01 | Access control | Logged in as church admin | Open a `/superadmin` URL directly | n/a | Access denied or redirected | | Planned |
| TC-RBAC-02 | Access control | Church admin of Church A | Try to edit Church B's announcement | Church B record ID | Action refused | | Planned |
| TC-RBAC-03 | Access control | Not logged in | Open a dashboard URL | n/a | Redirected to login | | Planned |
| TC-ANN-01 | Announcements | Church admin logged in | Create announcement | Valid title and body | Saved and listed | | Planned |
| TC-ANN-02 | Announcements | Church admin logged in | Submit with empty title | Empty title | Validation message; nothing saved | | Planned |
| TC-ANN-03 | Announcements (boundary) | Church admin logged in | Submit title at, and one over, the maximum length | Length N and N+1 | N accepted; N+1 rejected | | Planned |
| TC-DON-01 | Donation upload | Logged-in user | Upload a disallowed file type | `.exe` renamed `.png` | Upload rejected | | Planned |
| TC-DON-02 | Donation upload (boundary) | Logged-in user | Upload a file above the size limit | File over limit | Rejected with message | | Planned |
| TC-BUL-01 | Bulletin Builder | Announcements and events exist | Generate the weekly bulletin | Current week | Bulletin lists the week's items | | Planned |

Defects will be recorded here once they have been reproduced. Suspected risks will be kept in a separate list.

## Limitations

- Local development version; deployment has not been verified.
- No automated tests yet; no executed test results or SUS scores yet.
- The database import order is not yet documented as a single verified sequence.
- No ERD or screenshots in the repository yet.

## Future improvements

- Automated tests for authentication, access control and upload validation
- A single verified database setup script
- ERD and screenshots using sample data only
- Production deployment with HTTPS, backups and monitoring
- Accessibility and mobile review

## AI assistance

Parts of this codebase were developed with AI coding assistance (some commit messages show a co-author line). The author is responsible for understanding and testing the code.

## Author

Abigail Alau R. Sadom, Software Engineering, Faculty of Computing and Informatics, UMS.
Developed for academic purposes as a Final Year Project.
