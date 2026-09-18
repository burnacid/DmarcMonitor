<?php

namespace App\Support;

use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Message;

class ForensicReportDetector
{
    /**
     * An RFC 6591 forensic (ARF) report is its own multipart/report email
     * carrying a machine-readable message/feedback-report MIME part —
     * distinct from (and never mixed with) an aggregate report's XML
     * attachment, so detection happens up front rather than per-attachment.
     */
    public static function looksLikeForensicReport(Message $message): bool
    {
        return self::feedbackReportAttachment($message) !== null;
    }

    public static function feedbackReportAttachment(Message $message): ?Attachment
    {
        foreach ($message->getAttachments() as $attachment) {
            if (str_starts_with(strtolower((string) $attachment->content_type), 'message/feedback-report')) {
                return $attachment;
            }
        }

        return null;
    }
}
