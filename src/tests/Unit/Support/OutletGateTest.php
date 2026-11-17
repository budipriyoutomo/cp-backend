<?php

namespace Tests\Unit\Support;

use App\Exceptions\BusinessRuleException;
use App\Models\Outlet;
use App\Models\User;
use App\Support\OutletGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\SeedsProductionData;
use Tests\TestCase;

/**
 * OutletGate adalah satu-satunya tempat aturan "user ini boleh outlet apa".
 * Middleware `outlet.access` dan service yang mencari data lewat id memakainya
 * bersama — test ini mengunci aturannya supaya kedua jalur tidak melenceng.
 */
class OutletGateTest extends TestCase
{
    use RefreshDatabase;
    use SeedsProductionData;

    private OutletGate $gate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gate = new OutletGate();
    }

    private function user(string $role, mixed $outlets): User
    {
        return new User(['role' => $role, 'outlet' => $outlets]);
    }

    private function outlet(string $code): Outlet
    {
        return new Outlet(['code' => $code]);
    }

    public function test_admin_may_access_every_outlet(): void
    {
        $admin = $this->user('admin', []);

        $this->assertTrue($this->gate->isAdmin($admin));
        $this->assertTrue($this->gate->canAccess($admin, $this->outlet('BDG')));
        $this->assertTrue($this->gate->canAccess($admin, $this->outlet('JKT')));
    }

    public function test_admin_role_stored_as_legacy_array_is_still_admin(): void
    {
        $this->assertTrue($this->gate->isAdmin($this->user('["admin"]', [])));
        $this->assertFalse($this->gate->isAdmin($this->user('kitchen', [])));
    }

    public function test_user_may_only_access_listed_outlets(): void
    {
        $user = $this->user('operation', ['BDG']);

        $this->assertTrue($this->gate->canAccess($user, $this->outlet('BDG')));
        $this->assertFalse($this->gate->canAccess($user, $this->outlet('JKT')));
    }

    public function test_codes_are_compared_after_trim_and_lowercase(): void
    {
        $user = $this->user('operation', [' bdg ']);

        $this->assertSame(['bdg'], $this->gate->allowedCodes($user));
        $this->assertTrue($this->gate->canAccess($user, $this->outlet('BDG')));
        $this->assertTrue($this->gate->canAccess($user, $this->outlet(' Bdg')));
    }

    public function test_legacy_json_string_outlet_column_is_still_read(): void
    {
        // Baris lama menyimpan array yang di-encode dua kali, jadi cast `array`
        // menghasilkan string JSON, bukan array.
        $user = new User(['role' => 'operation']);
        $user->setRawAttributes(['role' => 'operation', 'outlet' => json_encode(json_encode(['BDG']))]);

        $this->assertSame(['bdg'], $this->gate->allowedCodes($user));
        $this->assertTrue($this->gate->canAccess($user, $this->outlet('BDG')));
    }

    public function test_null_outlet_grants_nothing(): void
    {
        $user = $this->user('operation', null);

        $this->assertSame([], $this->gate->allowedCodes($user));
        $this->assertFalse($this->gate->canAccess($user, $this->outlet('BDG')));
    }

    public function test_accepts_outlet_uuid_string(): void
    {
        $bdg = $this->createOutlet();
        $jkt = $this->createOutlet(['code' => 'JKT', 'name' => 'Jakarta']);
        $user = $this->user('operation', ['BDG']);

        $this->assertTrue($this->gate->canAccess($user, $bdg->id));
        $this->assertFalse($this->gate->canAccess($user, $jkt->id));
    }

    public function test_unknown_or_malformed_outlet_id_is_denied_for_non_admin(): void
    {
        $user = $this->user('operation', ['BDG']);

        // Gagal-tertutup: id yang tidak menunjuk outlet mana pun tidak boleh
        // dianggap "boleh", dan bentuk yang salah tidak boleh jadi 500.
        $this->assertFalse($this->gate->canAccess($user, (string) Str::uuid()));
        $this->assertFalse($this->gate->canAccess($user, 'abc'));
    }

    public function test_authorize_passes_silently_for_allowed_outlet(): void
    {
        $this->gate->authorize($this->user('operation', ['BDG']), $this->outlet('BDG'));

        $this->addToAssertionCount(1);
    }

    public function test_authorize_throws_403_with_standard_message(): void
    {
        try {
            $this->gate->authorize($this->user('operation', ['BDG']), $this->outlet('JKT'));
            $this->fail('authorize() seharusnya menolak outlet lain');
        } catch (BusinessRuleException $e) {
            $response = $e->render(request());

            $this->assertSame(403, $response->getStatusCode());
            $this->assertSame([
                'status'  => false,
                'message' => 'Outlet ini tidak termasuk akses Anda',
                'errors'  => null,
            ], $response->getData(true));
        }
    }
}
