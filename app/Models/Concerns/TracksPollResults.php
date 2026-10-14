<?php

namespace App\Models\Concerns;

use App\Models\User;
use App\Notifications\MailboxFailing;
use App\Notifications\MailboxRecovered;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Keeps track of consecutive failed polls for an ingestion mailbox, so admins
 * hear about an expired password or revoked consent instead of reports
 * silently stopping. Admins are emailed once when a mailbox starts failing
 * and once when it works again.
 *
 * The model needs consecutive_failures, failure_notified_at, last_error and
 * last_polled_at columns, and must describe itself through
 * mailboxTypeLabel() and settingsUrl().
 */
trait TracksPollResults
{
    /**
     * Failed polls in a row before a mailbox counts as failing: 15 minutes at
     * the 5-minute poll interval, so a single network hiccup doesn't alarm
     * anyone.
     */
    public const int FAILURE_THRESHOLD = 3;

    /**
     * A short name for the kind of mailbox, such as "IMAP account".
     */
    abstract public function mailboxTypeLabel(): string;

    /**
     * The settings page where this mailbox is managed.
     */
    abstract public function settingsUrl(): string;

    public function initializeTracksPollResults(): void
    {
        $this->mergeCasts([
            'consecutive_failures' => 'integer',
            'failure_notified_at' => 'datetime',
        ]);
    }

    /**
     * Store the outcome of a poll: an error message, or null when it worked.
     */
    public function recordPollResult(?string $error): void
    {
        $attributes = ['last_polled_at' => now(), 'last_error' => $error];

        if ($error === null) {
            $wasNotified = $this->failure_notified_at !== null;

            $this->update([...$attributes, 'consecutive_failures' => 0, 'failure_notified_at' => null]);

            if ($wasNotified) {
                Notification::send(self::adminsToNotify(), new MailboxRecovered($this));
            }

            return;
        }

        $failures = (int) $this->consecutive_failures + 1;
        $shouldNotify = $failures >= self::FAILURE_THRESHOLD && $this->failure_notified_at === null;

        $this->update([
            ...$attributes,
            'consecutive_failures' => $failures,
            'failure_notified_at' => $shouldNotify ? now() : $this->failure_notified_at,
        ]);

        if ($shouldNotify) {
            Notification::send(self::adminsToNotify(), new MailboxFailing($this));
        }
    }

    public function isFailing(): bool
    {
        return (int) $this->consecutive_failures >= self::FAILURE_THRESHOLD;
    }

    public function scopeFailing(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('consecutive_failures', '>=', self::FAILURE_THRESHOLD);
    }

    /**
     * Admins who have accepted their invitation.
     *
     * @return Collection<int, User>
     */
    private static function adminsToNotify(): Collection
    {
        return User::where('role', 'admin')->whereNotNull('email_verified_at')->get();
    }
}
