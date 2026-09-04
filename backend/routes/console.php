<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Monthly retention report (DR-14). Report only — archival is left to an
// operator running `--archive`, and disposal is always manual.
Schedule::command('documents:apply-retention')->monthlyOn(1, '02:00');

// Daily nudge for reviews past their advisory lead time (Phase 7.1 /
// decision 0.9). Notifies the assignee / OSM pool; never auto-decides.
Schedule::command('documents:escalate-stale')->dailyAt('07:00');

// Monthly governance-cadence reminder (BR-07, Phase 7.2).
Schedule::command('governance:remind')->monthlyOn(1, '06:00');
