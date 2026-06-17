<?php

namespace Tests\Feature\POS;

use App\Models\POSData;
use App\Services\POSService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsProductionData;
use Tests\TestCase;

/**
 * Covers the testable core of the POS module: POSService.
 *
 * The /api/reports/pos-data endpoint and the rabbit:consume-posdata command are
 * not exercised here — the former relies on PostgreSQL-only SQL (plate_colors.id::text
 * casts in ProductionItemService), and the latter is an AMQP consumer loop whose only
 * business logic is POSService::storeFromEvent(), tested directly below.
 */
class POSServiceTest extends TestCase
{
    use RefreshDatabase;
    use SeedsProductionData;

    private function service(): POSService
    {
        return app(POSService::class);
    }

    public function test_store_from_event_creates_new_pos_data(): void
    {
        $outlet = $this->createOutlet(['code' => 'BDG']);
        $color  = $this->createPlateColor(['platename' => 'Merah']);

        $result = $this->service()->storeFromEvent([
            'platecolor' => 'Merah',
            'outlet'     => 'BDG',
            'date'       => '2026-06-17',
            'sold'       => 12,
        ]);

        $this->assertSame('created', $result['status']);
        $this->assertDatabaseHas('posdata', [
            'plate_color_id' => $color->id,
            'outlet_id'      => $outlet->id,
            'date'           => '2026-06-17',
            'sold'           => 12,
        ]);
    }

    public function test_store_from_event_normalizes_name_and_code(): void
    {
        $outlet = $this->createOutlet(['code' => 'BDG']);
        $color  = $this->createPlateColor(['platename' => 'Merah']);

        // Extra punctuation, casing and whitespace must still map correctly.
        $result = $this->service()->storeFromEvent([
            'platecolor' => '  MERAH! ',
            'outlet'     => 'bdg',
            'date'       => '2026-06-17',
            'sold'       => 3,
        ]);

        $this->assertSame('created', $result['status']);
        $this->assertDatabaseHas('posdata', [
            'plate_color_id' => $color->id,
            'outlet_id'      => $outlet->id,
            'sold'           => 3,
        ]);
    }

    public function test_store_from_event_is_idempotent_and_updates_sold(): void
    {
        $outlet = $this->createOutlet(['code' => 'BDG']);
        $color  = $this->createPlateColor(['platename' => 'Merah']);

        $first = $this->service()->storeFromEvent([
            'platecolor' => 'Merah', 'outlet' => 'BDG', 'date' => '2026-06-17', 'sold' => 5,
        ]);
        $second = $this->service()->storeFromEvent([
            'platecolor' => 'Merah', 'outlet' => 'BDG', 'date' => '2026-06-17', 'sold' => 9,
        ]);

        $this->assertSame('created', $first['status']);
        $this->assertSame('updated', $second['status']);
        $this->assertSame($first['id'], $second['id']);

        // Only one row, with the updated sold value.
        $this->assertSame(1, POSData::count());
        $this->assertDatabaseHas('posdata', ['id' => $first['id'], 'sold' => 9]);
    }

    public function test_store_from_event_throws_when_plate_color_missing(): void
    {
        $this->createOutlet(['code' => 'BDG']);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Plate color not found');

        $this->service()->storeFromEvent([
            'platecolor' => 'Ungu', 'outlet' => 'BDG', 'date' => '2026-06-17', 'sold' => 1,
        ]);
    }

    public function test_store_from_event_throws_when_outlet_missing(): void
    {
        $this->createPlateColor(['platename' => 'Merah']);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Outlet not found');

        $this->service()->storeFromEvent([
            'platecolor' => 'Merah', 'outlet' => 'ZZZ', 'date' => '2026-06-17', 'sold' => 1,
        ]);
    }

    public function test_get_pos_data_for_closing_returns_matching_rows(): void
    {
        $outlet = $this->createOutlet(['code' => 'BDG']);
        $color  = $this->createPlateColor(['platename' => 'Merah']);

        $this->service()->storeFromEvent([
            'platecolor' => 'Merah', 'outlet' => 'BDG', 'date' => '2026-06-17', 'sold' => 7,
        ]);
        // A different date should be excluded.
        $this->service()->storeFromEvent([
            'platecolor' => 'Merah', 'outlet' => 'BDG', 'date' => '2026-06-10', 'sold' => 1,
        ]);

        $rows = $this->service()->getPosDataForClosing($outlet->id, '2026-06-17');

        $this->assertCount(1, $rows);
        $this->assertSame(7, (int) $rows->first()->sold);
        // plateColor relation eager-loaded
        $this->assertSame('Merah', $rows->first()->plateColor->platename);
    }
}
