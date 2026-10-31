<?php

return [
    'completed' => 'Action completed.',
    'skipped' => ':count record skipped (not allowed).|:count records skipped (not allowed).',
    'confirm' => 'Run this action?',
    'confirm_destructive' => 'Are you sure? This action cannot be undone.',
    'confirm_button' => 'Run',
    'confirm_destructive_button' => 'Confirm',
    'cancel_button' => 'Cancel',
    'nothing_matched' => 'No records matched.',
    'too_many' => 'Too many records selected: :total matched, the limit is :max. Narrow the search or filters.',
    'partial' => 'Completed for :done of :total records, then an error occurred.',
    'single' => 'This action runs on one record at a time.',

    // Queued actions (1.4)
    'queued' => '“:action” is running in the background. See notifications for progress.',
    'queued_limit' => 'Too many background tasks. Wait for the previous ones to finish (at most :max).',
    'queue_unavailable' => 'Could not queue the action.',
    'run_completed_title' => '“:action” finished',
    'run_failed_title' => '“:action” failed',
    'run_cancelled_title' => '“:action” was cancelled',
    'run_cancelled' => 'Processed :done of :total records.',
    'run_unauthorized' => 'You are not allowed to run this action.',
    'run_error' => 'An error occurred while running the action.',
    'run_download' => 'Download :filename',
    'run_open' => 'Open',
];
