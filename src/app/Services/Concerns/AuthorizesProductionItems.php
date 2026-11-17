<?php

namespace App\Services\Concerns;

use App\Exceptions\BusinessRuleException;
use App\Models\ProductionItem;
use App\Models\User;
use App\Support\OutletGate;

/**
 * Mutasi piring berbasis `itemIds` (mark-sold, mark-waste, expired/bulk,
 * waste) dulu tidak membawa outlet. Middleware `outlet.access` tidak punya
 * apa pun untuk diperiksa, dan service meng-update `whereIn('id', $ids)` —
 * user outlet BDG bisa menutup piring outlet JKT.
 *
 * Aturannya di satu tempat, dipakai ProductionItemService dan
 * WasteRecordService:
 * 1. Semua item harus milik satu outlet: outlet request. Batch campuran
 *    ditolak utuh, bukan diproses sebagian.
 * 2. User harus boleh outlet itu (OutletGate). Untuk request yang menyebut
 *    `outletId` ini sudah dijaga middleware; diperiksa lagi karena request
 *    transisi tanpa `outletId` tidak melewati middleware sama sekali.
 *
 * Pemanggil tetap menambahkan `where('outlet_id', ...)` di query UPDATE-nya
 * (defense in depth) memakai outlet yang dikembalikan di sini.
 */
trait AuthorizesProductionItems
{
    /**
     * @param  array<int, string>  $ids
     * @param  string|null  $outletId  null = request lama (masa transisi); outlet
     *                                 diturunkan dari item, lihat
     *                                 MarkProductionItemsRequest.
     * @return string|null  outlet tempat semua item berada; null kalau tidak
     *                      satu pun item ada (tidak ada yang perlu diubah)
     *
     * @throws BusinessRuleException 403
     */
    protected function authorizeItemsForOutlet(User $actor, array $ids, ?string $outletId): ?string
    {
        if ($ids === []) {
            return $outletId;
        }

        if ($outletId === null || $outletId === '') {
            $outletId = ProductionItem::whereIn('id', $ids)->value('outlet_id');

            if ($outletId === null) {
                return null;
            }
        }

        $outletId = trim((string) $outletId);

        $foreign = ProductionItem::whereIn('id', $ids)
            ->where('outlet_id', '!=', $outletId)
            ->exists();

        if ($foreign) {
            throw new BusinessRuleException('Sebagian piring bukan milik outlet ini', 403);
        }

        app(OutletGate::class)->authorize($actor, $outletId);

        return $outletId;
    }
}
