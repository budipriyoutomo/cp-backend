<?php

namespace App\Traits;

use App\Services\RunningNumberService;

trait RunningNumberModuleTrait
{
    /**
     * Konfigurasi modul → prefix/table/column
     */
    protected array $runningConfig = [
        'order' => [
            'prefix' => 'PO',
            'table'  => 'purchase_orders',
            'column' => 'po_number',
        ],

        'delivery' => [
            'prefix' => 'DO',
            'table'  => 'purchase_deliveries',
            'column' => 'delivery_number',
        ],

        'receipt' => [
            'prefix' => 'RC',
            'table'  => 'purchase_receipts',
            'column' => 'receipt_number',
        ],

        'invoice' => [
            'prefix' => 'INV',
            'table'  => 'purchase_invoices',
            'column' => 'invoice_number',
        ],

        'payment' => [
            'prefix' => 'PAY',
            'table'  => 'purchase_payments',
            'column' => 'payment_number',
        ],
        
        'paymentbatch' => [
            'prefix' => 'PAB',
            'table'  => 'purchase_payment_batches',
            'column' => 'payment_number',
        ],

        'direct' => [
            'prefix' => 'DIR',
            'table'  => 'purchase_directs',
            'column' => 'purchase_number',
        ],

        'rap' => [
            'prefix' => 'RAP',
            'table'  => 'project_raps',
            'column' => 'rap_number',
        ],

        'realization' => [
            'prefix' => 'REA',
            'table'  => 'project_realisasis',
            'column' => 'realization_number',
        ],
    ];

    /**
     * Ambil nomor berikutnya berdasarkan modul.
     */
    protected function getNextNumber(string $module): string
    {
        if (!isset($this->runningConfig[$module])) {
            throw new \Exception("Invalid module: $module");
        }

        $cfg = $this->runningConfig[$module];

        return RunningNumberService::generate(
            $cfg['prefix'],
            $cfg['table'],
            $cfg['column']
        );
    }

    /**
     * Untuk universal endpoint: /purchase/next-number?module=order
     */
    public function nextNumber()
    {
        $module = request()->module;

        try {
            return response()->json([
                'status' => true,
                'number' => $this->getNextNumber($module),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }
}
