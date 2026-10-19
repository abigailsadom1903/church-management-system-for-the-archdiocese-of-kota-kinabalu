# Web-Based Church Management System
### Archdiocese of Kota Kinabalu — Final Year Project

A web-based Church Management System developed to centralise church information and improve access to announcements, digital bulletins, church calendars, sacramental information, donations, and other parish-related services.

**Project Status:** Testing and Evaluation  
**Project Type:** Final Year Project (FYP)  
**Institution:** Universiti Malaysia Sabah (UMS)  
**Programme:** Bachelor of Computer Science (Software Engineering)

---

## Table of Contents

- [Overview](#overview)
- [Problem Statement](#problem-statement)
- [Project Objectives](#project-objectives)
- [Key Features](#key-features)
- [Technology Stack](#technology-stack)
- [System Roles](#system-roles)
- [System Architecture](#system-architecture)
- [Database](#database)
- [Getting Started](#getting-started)
- [Testing and Evaluation](#testing-and-evaluation)
- [Project Structure](#project-structure)
- [Project Scope and Limitations](#project-scope-and-limitations)
- [Future Improvements](#future-improvements)
- [Acknowledgements](#acknowledgements)
- [Author](#author)

---

## Overview

The Web-Based Church Management System is designed for the Archdiocese of Kota Kinabalu to provide a centralised platform for accessing church information and managing selected administrative activities.

Traditional communication methods, including physical notice boards, printed bulletins, spreadsheets, messaging applications, and social media, can make information difficult to organise and access consistently.

This project aims to improve information accessibility and streamline selected church management processes through a responsive web application.

The system is developed as a Final Year Project using the Laravel framework and a relational database.

## Problem Statement

Church-related information and administrative activities may be distributed across multiple communication channels and manual processes. This can lead to:

- Difficulty accessing the latest announcements and church information.
- Dependence on printed bulletins and physical notice boards.
- Challenges organising information across parishes.
- Time-consuming administrative work.
- Limited centralisation of selected church services and records.

The proposed system provides a centralised web platform to address these challenges.

## Project Objectives

1. To develop a web-based platform for centralising church information and announcements.
2. To provide convenient access to digital bulletins, church calendars, and parish-related information.
3. To support selected church administrative processes through role-based access.
4. To evaluate the system's usability and functionality through software testing and user feedback.

## Key Features

The system's implemented features include the following modules, subject to final verification during testing.

| Module | Description |
|---|---|
| Digital Bulletin | Provides access to church bulletins and related publications. |
| Announcements | Organises church announcements for convenient access. |
| Church Calendar | Displays church-related dates and calendar information. |
| Church Information | Presents parish information, contact details, and related services. |
| Sacramental Information | Provides information about sacraments, including Baptism, Confirmation, First Communion, Confession, and Marriage. |
| Donation and Offerings | Supports the intended donation workflow, including payment information and submission of supporting details. |
| Catechesis | Provides access to catechetical information and resources. |
| Pastoral Care | Presents pastoral care information and related services. |
| Intelligent Church Assistant | Provides a conversational interface for assisting users in finding church-related information. |
| Role-Based Dashboards | Provides administrative interfaces according to assigned user roles. |

> Note: The presence of a module in this list does not necessarily mean every workflow has been fully tested or deployed to a production server.

## Technology Stack

| Technology | Purpose |
|---|---|
| Laravel (PHP) | Backend application framework |
| PHP | Server-side programming |
| MySQL | Relational database management |
| HTML5 | Web page structure |
| CSS3 | Styling and responsive layout |
| JavaScript | Client-side interactions |
| Git and GitHub | Version control and source code management |
| XAMPP | Local development environment |

*Update this list if your final implementation uses additional libraries, APIs, or frontend technologies.*

## System Roles

The system is designed around role-based access control.

- **Super Administrator** — oversees system-wide administration and relevant church information.
- **Church Administrator** — manages assigned parish-related information and administrative activities.
- **Public User / Parishioner** — accesses available church information and public-facing services.

Access to administrative functions depends on the permissions implemented in the system.

## System Architecture

The application follows a web-based client-server approach.

1. Users access the application through a web browser.
2. The Laravel application processes requests and applies application logic.
3. The database stores and retrieves relevant system information.
4. The application returns the appropriate pages and results to the user.

## Database

The system uses MySQL to store structured application data.

The database supports relevant entities such as user accounts, announcements, bulletins, church information, and other records required by the implemented modules.

Database configuration is maintained through the application's environment settings. Credentials and other secrets must not be committed to the repository.

## Getting Started

Follow these instructions to run the project locally. The steps assume a Windows development environment using XAMPP.

### Prerequisites

Install the following software:

- PHP compatible with the project's Laravel version.
- Composer.
- MySQL or MariaDB.
- Node.js and npm, if the project requires frontend asset compilation.
- Git.
- XAMPP or another compatible local development environment.

### 1. Clone the Repository

```bash
git clone YOUR_GITHUB_REPOSITORY_URL
```

Navigate to the project directory:

```bash
cd church-management-system
```

Replace the repository URL with the actual GitHub clone URL.

### 2. Install PHP Dependencies

```bash
composer install
```

### 3. Configure Environment Variables

Copy the example environment file:

```bash
copy .env.example .env
```

Generate the application key:

```bash
php artisan key:generate
```

Open `.env` and configure the database connection:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=church_management
DB_USERNAME=root
DB_PASSWORD=
```

Adjust the database username and password to match your local MySQL configuration.

**Security:** Never upload your actual `.env` file, database passwords, API keys, or private credentials to GitHub.

### 4. Create the Database

Start MySQL through XAMPP and create a database named:

```sql
CREATE DATABASE church_management;
```

If the project uses database migrations, run:

```bash
php artisan migrate
```

If the application requires seed data, run the relevant seeder only after confirming that it is safe for your local environment:

```bash
php artisan db:seed
```

Use the database import procedure instead if your project depends on a supplied SQL dump rather than migrations.

### 5. Install Frontend Dependencies

If the repository includes a `package.json` file, run:

```bash
npm install
npm run build
```

For frontend development with hot reloading, use:

```bash
npm run dev
```

### 6. Start the Application

Run the Laravel development server:

```bash
php artisan serve
```

Open the local address displayed in the terminal, usually:

```text
http://127.0.0.1:8000
```

Follow any additional setup instructions required by the project, including file storage configuration, email settings, API credentials, or administrator account creation.

## Testing and Evaluation

Software testing is conducted to assess whether the system meets its functional requirements and usability objectives.

Testing activities may include:

- **Functional testing:** Verifying that implemented features behave according to requirements.
- **Validation testing:** Checking form inputs, required fields, and error handling.
- **Role and access testing:** Verifying that users can access only the functions permitted by their roles.
- **Integration testing:** Checking interactions between connected modules.
- **Usability evaluation:** Gathering user feedback on ease of use and navigation.
- **Defect tracking:** Recording issues, expected results, actual results, and retesting outcomes.

The System Usability Scale (SUS) is used as the planned usability evaluation method.

Actual test results, defect statistics, and SUS scores should be documented after the relevant tests and evaluations have been completed.

## Project Structure

A typical Laravel project contains the following directories and files:

```text
church-management-system/
├── app/
│   ├── Http/
│   ├── Models/
│   └── Providers/
├── bootstrap/
├── config/
├── database/
│   ├── migrations/
│   ├── seeders/
│   └── factories/
├── public/
├── resources/
│   ├── css/
│   ├── js/
│   └── views/
├── routes/
├── storage/
├── tests/
├── .env.example
├── artisan
├── composer.json
├── package.json
└── README.md
```

The exact structure may differ depending on the modules and implementation choices in the repository.

## Project Scope and Limitations

This project focuses on a web-based platform for church information and selected administrative processes.

The system's operational readiness depends on final testing, security checks, configuration, and deployment requirements. External integrations and workflows may require additional configuration or approval before production use.

The local development version should not be considered a production deployment unless it has been properly configured, secured, and approved for use.

## Future Improvements

Potential enhancements include:

- Production deployment with appropriate security and backup procedures.
- Further automated testing for critical application workflows.
- Improved accessibility and mobile responsiveness.
- Additional reporting and administrative tools.
- Enhanced monitoring, maintenance, and performance optimisation.
- Further evaluation with intended users.

## Acknowledgements

This project is developed as part of the Final Year Project requirements for the Bachelor of Computer Science (Software Engineering) programme at Universiti Malaysia Sabah.

Special appreciation is extended to the project supervisor, the Archdiocese of Kota Kinabalu, and the individuals who provide feedback and support during requirements gathering, development, testing, and evaluation.

## Author

**Abigail Alau R. Sadom**

Bachelor of Computer Science (Software Engineering)  
Faculty of Computing and Informatics  
Universiti Malaysia Sabah (UMS)

GitHub: [abigailsadom1903](https://github.com/abigailsadom1903)

---

*Developed for academic purposes as a Final Year Project.*
