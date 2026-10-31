# Content Toolkit

A WordPress tool manager for batch operations. Each job runs in a WP-CLI background worker, one chunk at a time, with progress, stop, restart and execution logs in the dashboard.

A tool extends `Tool`: it renders its form, turns the submission into job data and processes one chunk per `step()`. The base class handles queueing, locks, checkpoints, stop and recovery. Register it in `Plugin`.

## Included tools

- **Link rotti:** remove links listed in a CSV while keeping their inner markup. Optionally keep the original anchor in an HTML comment.
- **Shortcode obsoleti:** remove selected shortcode tags while keeping enclosed content. Shortcodes are never executed; the cautious mode skips those still registered by a theme or plugin.

Both tools offer a dry run. Back up your content before applying changes.

## Worker recovery

The dashboard shows the active job. When a worker stops sending heartbeats, its tool page offers **Riavvia worker**. Restart preserves the saved job payload and progress, and refuses to launch while the previous worker still holds its file lock or another tool owns the execution lock. A failed worker bootstrap can also be retried after correcting the server configuration. A request to stop a job cannot be overridden by restart. Completed jobs and jobs that failed during processing are not restarted.

This is recovery from a saved checkpoint, not a rollback: a tool must tolerate repeating work performed since its last checkpoint. Restart does not clear all jobs or remove a lock belonging to another tool.

## Setup

Requires WordPress 6.8+, PHP 8.3+, a Unix server, Bash, WP-CLI in the web user's PATH, and `proc_open` enabled.

1. Copy `wp-toolkit` into `wp-content/plugins` and activate it.
2. Create a writable directory outside the public web root, owned by the PHP/worker user.
3. Set its path in `wp-config.php`:

```php
define('CONTENT_TOOLKIT_STORAGE_DIR', '/srv/private/content-toolkit');
```

4. Open **Content Toolkit**, choose a tool and run a simulation first.

The CSV has a header row and two comma-separated columns: page URL, broken URL. Page URLs must belong to the current site. URL paths and queries are case sensitive. Upload the CSV for each run: it is deleted when the job ends.

## Limits

The link tool consumes an audit report; it does not crawl or check remote URLs. Shortcode cleanup handles WordPress shortcode syntax, skips escaped examples and HTML comments, and may require another pass for nested tags. Jobs are serialized, and progress is saved after each chunk of 100 pages or 250 posts. CSV and execution logs contain content information and stay in private storage.

Worker command: `wp content-toolkit run-job <tool> <job_id>`.
