<?php

namespace App\Services;

use App\Enums\EarningsOwner;
use App\Events\DocumentRequested;
use App\Exceptions\ApiException;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Vendor and rider documents for approval (spec 03 §3.3, §3.7, spec 12 "approvals and
 * documents"). Files are uploaded to a private bucket first; only their paths are stored, and
 * admins view them through short-lived signed URLs (with the admin panel). An admin can ask
 * for a document; submitting one of a kind replaces the earlier request or rejection.
 */
final class Documents
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return list<string> */
    public static function kinds(EarningsOwner $type): array
    {
        return match ($type) {
            EarningsOwner::Vendor => ['cac_certificate', 'tin_certificate', 'owner_id', 'pharmacy_licence', 'premises_permit', 'other'],
            EarningsOwner::Rider => ['government_id', 'drivers_licence', 'vehicle_papers', 'guarantor_form', 'passport_photo', 'other'],
        };
    }

    public function submit(EarningsOwner $type, int $ownerId, string $kind, string $path): Document
    {
        return DB::transaction(function () use ($type, $ownerId, $kind, $path): Document {
            $document = Document::query()->where('owner_type', $type->value)->where('owner_id', $ownerId)->where('kind', $kind)
                ->whereIn('status', ['requested', 'rejected'])->lockForUpdate()->first() ?? new Document;
            $document->forceFill([
                'owner_type' => $type,
                'owner_id' => $ownerId,
                'kind' => $kind,
                'file_path' => $path,
                'status' => 'submitted',
                'reviewed_by' => null,
                'reviewed_at' => null,
                'review_note' => null,
            ])->save();

            return $document;
        });
    }

    public function request(EarningsOwner $type, int $ownerId, string $kind, User $admin, ?string $note): Document
    {
        $document = new Document;
        $document->forceFill(['owner_type' => $type, 'owner_id' => $ownerId, 'kind' => $kind, 'status' => 'requested', 'review_note' => $note])->save();
        $this->audit->record($admin, 'document.requested', $type->value, (string) $ownerId, after: ['kind' => $kind, 'note' => $note]);
        event(new DocumentRequested($document->id));

        return $document;
    }

    public function review(Document $document, User $admin, bool $approve, ?string $note): Document
    {
        if ($document->status !== 'submitted') {
            throw new ApiException('document_status_conflict', 409, meta: ['status' => $document->status]);
        }
        $document->forceFill(['status' => $approve ? 'approved' : 'rejected', 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'review_note' => $note])->save();
        $this->audit->record($admin, $approve ? 'document.approved' : 'document.rejected', 'document', $document->uuid, after: ['kind' => $document->kind, 'note' => $note]);

        return $document;
    }
}
