<?php

namespace Tests\Feature\ClosingReport;

use App\Models\ClosingReport;
use App\Models\Outlet;
use App\Models\PlateColors;
use App\Models\SalesHeader;
use App\Models\SalesItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SeedsProductionData;
use Tests\TestCase;

class ClosingReportTest extends TestCase
{
    use RefreshDatabase;
    use SeedsProductionData;

    private const DATE = '2026-06-17';

    /**
     * Seed a SalesHeader (with one item) for the given outlet/date and status.
     */
    private function seedSales(Outlet $outlet, PlateColors $color, string $status = 'submitted'): SalesHeader
    {
        $header = SalesHeader::create([
            'outlet_id' => $outlet->id,
            'date'      => self::DATE,
            'status'    => $status,
        ]);

        SalesItem::create([
            'sales_id'         => $header->id,
            'plate_color_id'   => $color->id,
            'pos_sold'         => 10,
            'production_sold'  => 5,
            'production_waste' => 1,
            'adjustment'       => 1,
            'compensation'     => 2,
            'selisih'          => 2,
        ]);

        return $header;
    }

    public function test_submit_creates_report_and_generates_entries(): void
    {
        $outlet = $this->createOutlet();
        $color  = $this->createPlateColor();
        $this->seedSales($outlet, $color, 'submitted');

        $response = $this->postJson('/api/closing-reports/submit', [
            'outletId'        => $outlet->id,
            'date'            => self::DATE,
            'kitchenLeader'   => 'Budi',
            'operationLeader' => 'Sari',
            'notes'           => 'All good',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Closing report submitted')
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.kitchenLeader', 'Budi')
            // produced = production_sold + production_waste = 5 + 1
            ->assertJsonPath('data.totalProduced', 6)
            ->assertJsonPath('data.totalSold', 5)
            ->assertJsonPath('data.totalWaste', 1)
            ->assertJsonCount(1, 'data.entries');

        $this->assertDatabaseHas('closing_reports', [
            'outlet_id' => $outlet->id,
            'status'    => 'submitted',
        ]);
        $this->assertDatabaseHas('closing_report_entries', [
            'plate_color_id' => $color->id,
            'produced'       => 6,
            'sold'           => 5,
            'waste'          => 1,
            'pos_sold'       => 10,
            'selisih'        => 2,
        ]);
    }

    public function test_submit_validates_required_fields(): void
    {
        $this->postJson('/api/closing-reports/submit', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['outletId', 'date', 'kitchenLeader', 'operationLeader']);
    }

    public function test_submit_fails_when_no_sales_exists(): void
    {
        $outlet = $this->createOutlet();

        // No SalesHeader for this outlet/date -> firstOrFail() -> 404.
        $this->postJson('/api/closing-reports/submit', [
            'outletId'        => $outlet->id,
            'date'            => self::DATE,
            'kitchenLeader'   => 'Budi',
            'operationLeader' => 'Sari',
        ])->assertStatus(404);
    }

    public function test_submit_fails_when_sales_not_submitted(): void
    {
        $outlet = $this->createOutlet();
        $color  = $this->createPlateColor();
        $this->seedSales($outlet, $color, 'draft');

        // Business rule violation -> 422 with a descriptive message.
        $this->postJson('/api/closing-reports/submit', [
            'outletId'        => $outlet->id,
            'date'            => self::DATE,
            'kitchenLeader'   => 'Budi',
            'operationLeader' => 'Sari',
        ])->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'Sales Input harus disubmit terlebih dahulu.');

        $this->assertDatabaseMissing('closing_reports', ['outlet_id' => $outlet->id, 'status' => 'submitted']);
    }

    public function test_index_lists_reports(): void
    {
        $outlet = $this->createOutlet();
        $color  = $this->createPlateColor();
        $this->seedSales($outlet, $color, 'submitted');
        $this->postJson('/api/closing-reports/submit', [
            'outletId'        => $outlet->id,
            'date'            => self::DATE,
            'kitchenLeader'   => 'Budi',
            'operationLeader' => 'Sari',
        ])->assertOk();

        $this->getJson('/api/closing-reports')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure(['data' => [['id', 'status', 'entries']], 'meta' => ['total']]);
    }

    public function test_show_returns_report(): void
    {
        $outlet = $this->createOutlet();
        $color  = $this->createPlateColor();
        $this->seedSales($outlet, $color, 'submitted');
        $this->postJson('/api/closing-reports/submit', [
            'outletId'        => $outlet->id,
            'date'            => self::DATE,
            'kitchenLeader'   => 'Budi',
            'operationLeader' => 'Sari',
        ])->assertOk();

        $id = ClosingReport::first()->id;

        $this->getJson("/api/closing-reports/{$id}")
            ->assertOk()
            ->assertJsonPath('data.id', $id);
    }

    public function test_show_returns_404_for_unknown_id(): void
    {
        $this->getJson('/api/closing-reports/11111111-1111-1111-1111-111111111111')
            ->assertStatus(404);
    }

    public function test_destroy_deletes_draft_report(): void
    {
        $outlet = $this->createOutlet();
        $report = ClosingReport::create([
            'outlet_id' => $outlet->id,
            'date'      => self::DATE,
            'status'    => 'draft',
        ]);

        $this->deleteJson("/api/closing-reports/{$report->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Draft deleted');

        $this->assertSoftDeleted('closing_reports', ['id' => $report->id]);
    }

    public function test_destroy_rejects_submitted_report(): void
    {
        $outlet = $this->createOutlet();
        $report = ClosingReport::create([
            'outlet_id' => $outlet->id,
            'date'      => self::DATE,
            'status'    => 'submitted',
        ]);

        // Only drafts may be deleted; otherwise -> 409 Conflict.
        $this->deleteJson("/api/closing-reports/{$report->id}")
            ->assertStatus(409)
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'Only draft closing reports can be deleted.');

        $this->assertDatabaseHas('closing_reports', ['id' => $report->id, 'deleted_at' => null]);
    }

    public function test_data_returns_404_when_no_submitted_sales(): void
    {
        $outlet = $this->createOutlet();

        $this->getJson('/api/closing-reports/data?outletId=' . $outlet->id . '&date=' . self::DATE)
            ->assertStatus(404)
            ->assertJsonPath('message', 'No submitted sales data found');
    }

    public function test_data_returns_report_data_for_submitted_sales(): void
    {
        $outlet = $this->createOutlet();
        $color  = $this->createPlateColor();
        $this->seedSales($outlet, $color, 'submitted');

        $this->getJson('/api/closing-reports/data?outletId=' . $outlet->id . '&date=' . self::DATE)
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.outletId', $outlet->id);
    }

    public function test_data_validates_query_params(): void
    {
        $this->getJson('/api/closing-reports/data')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['outletId', 'date']);
    }

    public function test_upload_waste_photos_stores_and_returns_urls(): void
    {
        // Waste photos are uploaded to the S3 disk (see ClosingReportController).
        Storage::fake('s3');

        // Use create() with an explicit image mime instead of image() so the test
        // does not depend on the GD extension being installed.
        $response = $this->postJson('/api/closing-reports/upload-photos', [
            'photos' => [
                UploadedFile::fake()->create('waste1.jpg', 100, 'image/jpeg'),
                UploadedFile::fake()->create('waste2.jpg', 100, 'image/jpeg'),
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Photos uploaded')
            ->assertJsonCount(2, 'data.urls');

        $this->assertCount(2, Storage::disk('s3')->allFiles('closing-report/waste-photos'));
    }

    public function test_upload_waste_photos_validates_input(): void
    {
        $this->postJson('/api/closing-reports/upload-photos', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['photos']);
    }
}
