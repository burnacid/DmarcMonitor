<?php

namespace App\Models;

use Database\Factories\ForensicReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ForensicReport extends Model
{
    /** @use HasFactory<ForensicReportFactory> */
    use HasFactory;

    protected $fillable = [
        'domain_id', 'imap_account_id', 'microsoft365_mail_account_id', 'arrival_date', 'source_ip', 'original_envelope_id',
        'authentication_results', 'delivery_result', 'header_from', 'envelope_from',
        'envelope_to', 'dkim_domain', 'dkim_result', 'spf_domain', 'spf_result',
        'subject', 'raw_message_path', 'message_uid', 'processed_at',
    ];

    protected $casts = [
        'arrival_date' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function domain()
    {
        return $this->belongsTo(Domain::class);
    }

    public function imapAccount()
    {
        return $this->belongsTo(ImapAccount::class);
    }

    public function microsoft365MailAccount()
    {
        return $this->belongsTo(Microsoft365MailAccount::class);
    }
}
