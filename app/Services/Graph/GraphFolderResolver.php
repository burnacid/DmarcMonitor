<?php

namespace App\Services\Graph;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GraphFolderResolver
{
    /**
     * Graph's language-independent folder names. Display names are localized
     * (a Dutch mailbox calls its inbox "Postvak IN"), so a configured "Inbox"
     * only resolves through these.
     */
    private const array WELL_KNOWN_NAMES = [
        'archive', 'clutter', 'conflicts', 'conversationhistory', 'deleteditems',
        'drafts', 'inbox', 'junkemail', 'localfailures', 'msgfolderroot', 'outbox',
        'recoverableitemsdeletions', 'scheduled', 'searchfolders', 'sentitems',
        'serverfailures', 'syncissues',
    ];

    /**
     * Finds a top-level mail folder by display name, falling back to Graph's
     * well-known folder name ("Inbox", "Junk Email", "Deleted Items", …).
     *
     * @param  string  $mailbox  The URL-encoded mailbox address.
     *
     * @throws RuntimeException When Graph rejects the lookup, e.g. missing permissions.
     */
    public function resolve(string $mailbox, string $token, string $folderName): ?string
    {
        $response = Http::withToken($token)->get("https://graph.microsoft.com/v1.0/users/{$mailbox}/mailFolders", [
            '$filter' => "displayName eq '".str_replace("'", "''", $folderName)."'",
            '$select' => 'id,displayName',
        ]);

        if ($response->failed()) {
            throw new RuntimeException($response->json('error.message') ?? $response->body());
        }

        $id = $response->json('value.0.id');

        if ($id !== null) {
            return $id;
        }

        $wellKnownName = strtolower(str_replace(' ', '', $folderName));

        if (! in_array($wellKnownName, self::WELL_KNOWN_NAMES, true)) {
            return null;
        }

        $response = Http::withToken($token)->get("https://graph.microsoft.com/v1.0/users/{$mailbox}/mailFolders/{$wellKnownName}", [
            '$select' => 'id',
        ]);

        return $response->successful() ? $response->json('id') : null;
    }

    /**
     * Like resolve(), but creates the folder at the top level of the mailbox
     * when it doesn't exist yet.
     *
     * @param  string  $mailbox  The URL-encoded mailbox address.
     *
     * @throws RuntimeException When Graph rejects the lookup or the creation.
     */
    public function resolveOrCreate(string $mailbox, string $token, string $folderName): string
    {
        $id = $this->resolve($mailbox, $token, $folderName);

        if ($id !== null) {
            return $id;
        }

        $response = Http::withToken($token)->post("https://graph.microsoft.com/v1.0/users/{$mailbox}/mailFolders", [
            'displayName' => $folderName,
        ]);

        if ($response->failed()) {
            throw new RuntimeException("Could not create folder [{$folderName}]: ".($response->json('error.message') ?? $response->body()));
        }

        return $response->json('id');
    }
}
