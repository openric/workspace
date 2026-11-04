---
title: Background jobs
category: Administration
order: 50
staff: true
routes: jobs.*, operations.*
heratio: jobs-user-guide.md
---

# Background jobs

Some work takes too long to do while you wait: a large import, the viewing copies of a big upload, reading the text of a long PDF, reading the embedded metadata of a batch of images. The Workspace hands these to background jobs, run by its queue, and you carry on.

## The list

**Jobs** (`/jobs`) lists the institution's jobs, newest first, with their kind, their status (pending, running, completed, failed or cancelled), who started them, when, and how far they got. Open one for its detail and, if it failed, why. **Export as CSV** gives the list as a spreadsheet; **Clear finished jobs older than 30 days** tidies it.

A failed job is listed on **Operations** with why it failed; **Try again** starts it again once whatever stopped it is put right, and **Forget** removes it from the list.

## Operations

**Operations** (`/operations`, for those who administer the institution) is the health of the installation:

- whether the scheduler is running, from the time it last reported in;
- the queue: how many jobs are waiting and how many failed;
- the tools on the server, such as Siegfried, ClamAV, ImageMagick and FFmpeg, and their versions;
- fixity schedules and their last runs; see [Fixity](/help/article/dp-fixity-and-checksums).

An installation can also give its figures to Prometheus at `/metrics`, for monitoring alongside its other systems.

## For operators

The queue is worked by the scheduler, which the server runs every minute (`php artisan schedule:run`). If jobs stay pending, check that the scheduler is running, from **Operations** or the server's cron.
