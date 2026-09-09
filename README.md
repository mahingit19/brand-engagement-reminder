# Brand Engagement Reminder — XAMPP Local MVP

A simple local PHP + MySQL system for reminding you to engage with your office brands' social posts/pages during a configured daily time window.

## Features

- Add unlimited brands
- Add multiple social links per brand
- Optional latest post URL per brand
- Daily engagement queue
- Like / Comment / Share checklist
- Mark All Done
- Snooze 30 minutes / 1 hour
- Skip for today
- Global daily reminder time window
- Configurable reminder interval
- Browser notifications
- Pending / Completed / Skipped summary
- Mobile-friendly UI

## Requirements

- Windows + XAMPP
- Apache
- MySQL/MariaDB
- PHP 8.x recommended
- Chrome / Edge / Firefox

## Setup

1. Start **Apache** and **MySQL** in XAMPP.
2. Copy the folder `brand-engagement-reminder` into:
   `C:\xampp\htdocs\`
3. Open phpMyAdmin:
   `http://localhost/phpmyadmin`
4. Click **Import** and import `schema.sql` from this project.
5. Open:
   `http://localhost/brand-engagement-reminder/`
6. Open **Brands**, add your brands and their social links.
7. Open **Settings**, set your office reminder window and interval.
8. Return to **Dashboard** and click **Enable Notifications**.
9. Keep the Dashboard tab open during the configured office window.

## Default database configuration

- Host: `127.0.0.1`
- Database: `brand_engagement`
- User: `root`
- Password: empty

If your XAMPP MySQL settings differ, edit `config.php`.

## Reminder logic

The dashboard checks the server periodically. During the configured time window it selects one eligible pending brand. A brand is eligible when:

- it is active,
- today's task is pending,
- it is not currently snoozed,
- the configured global reminder gap has passed since the previous reminder.

Only one brand reminder is emitted per configured interval, so reminders are spread across the day. Unreminded brands are preferred first; after that, the least-recently-reminded pending brand is selected. When Like, Comment and Share are all marked, the brand is completed for that day and stops reminding.

## Important limitation

This MVP intentionally does **not** automatically like, comment or share on social media. It also does not automatically read whether you actually interacted on Facebook/LinkedIn/etc. You manually mark the actions in the dashboard after doing them.

Browser notifications work while the dashboard is open. For reminders when the browser is completely closed, add a Windows Task Scheduler / Telegram / Slack notification worker in a later version.

## Suggested next upgrades

- Login + multiple employees
- Brand-to-employee assignment
- Per-brand reminder windows
- Per-platform task tracking
- Post history instead of one latest URL
- Telegram / Slack / Teams notifications
- Windows Task Scheduler worker
- Daily/weekly reports
- CSV export
