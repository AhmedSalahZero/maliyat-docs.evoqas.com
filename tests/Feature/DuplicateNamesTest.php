<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Models\Vendor;
use App\Services\JournalService;
use App\Support\NameNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  Duplicate names and stray spaces.
//
//  A customer, supplier/employee or team member whose name already
//  exists IN THE SAME COMPANY is refused — spaces at the start/end
//  and capital letters do not make a name different. Another
//  company's identical name is never looked at.
// ══════════════════════════════════════════════════════════════════
class DuplicateNamesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->admin   = User::factory()->companyAdmin($this->company)->create(['language' => 'en']);
        app(JournalService::class)->seedChartOfAccounts($this->company);
        $this->actingAs($this->admin);
    }

    // ── The cleaning rule itself ─────────────────────────────────

    public function test_cleaning_removes_edge_and_double_spaces_and_invisible_characters(): void
    {
        $this->assertSame('Cairo Company', NameNormalizer::clean('  Cairo   Company '));
        $this->assertSame('Cairo Company', NameNormalizer::clean("\u{00A0}Cairo\u{00A0}Company\t"));
        $this->assertSame('Cairo Company', NameNormalizer::clean("Cairo\u{200B} Company"));
        $this->assertSame('شركة القاهرة', NameNormalizer::clean('  شركة   القاهرة '));
        $this->assertSame(NameNormalizer::key('CAIRO company'), NameNormalizer::key(' cairo   COMPANY '));
        $this->assertNotSame(NameNormalizer::key('Cairo Company'), NameNormalizer::key('Cairo Company 2'));
    }

    // ── Customers ────────────────────────────────────────────────

    public function test_saved_name_has_no_stray_spaces(): void
    {
        $this->postJson('/app/customers', ['name' => '   Cairo   Company  '])->assertCreated();

        $this->assertSame('Cairo Company', Customer::query()->latest('id')->first()->name);
    }

    public function test_same_customer_name_is_refused_whatever_the_spaces_or_capitals(): void
    {
        Customer::create(['company_id' => $this->company->id, 'name' => 'Cairo Company']);

        foreach (['Cairo Company', ' Cairo Company', 'Cairo Company  ', '  Cairo   Company ', 'cairo company', 'CAIRO COMPANY'] as $typed) {
            Cache::flush(); // the double-click guard would otherwise answer repeats
            $this->postJson('/app/customers', ['name' => $typed])
                ->assertStatus(422)
                ->assertJsonValidationErrors('name');
        }

        $this->assertSame(1, Customer::query()->where('name', 'like', '%airo%')->count());
    }

    public function test_an_old_record_saved_with_stray_spaces_still_counts_as_the_same_name(): void
    {
        // Saved before this rule existed, with a space at the end.
        Customer::create(['company_id' => $this->company->id, 'name' => 'Cairo Company ']);

        $this->postJson('/app/customers', ['name' => 'Cairo Company'])->assertStatus(422);
    }

    public function test_a_different_name_is_accepted(): void
    {
        Customer::create(['company_id' => $this->company->id, 'name' => 'Cairo Company']);

        $this->postJson('/app/customers', ['name' => 'Cairo Company - Giza'])->assertCreated();
    }

    public function test_the_same_name_in_another_company_is_never_a_clash(): void
    {
        $other = Company::factory()->create();
        Customer::create(['company_id' => $other->id, 'name' => 'Cairo Company']);

        $this->postJson('/app/customers', ['name' => 'Cairo Company'])->assertCreated();

        $this->assertSame(2, Customer::query()->withoutGlobalScopes()->where('name', 'Cairo Company')->count());
    }

    public function test_the_error_message_names_the_customer_and_comes_in_arabic_too(): void
    {
        Customer::create(['company_id' => $this->company->id, 'name' => 'Cairo Company']);

        Cache::flush();
        $en = $this->postJson('/app/customers', ['name' => 'cairo company'])->json('errors.name.0');
        $this->assertStringContainsString('cairo company', $en);
        $this->assertStringContainsString('already exists', $en);

        // The app takes the language from the signed-in user, not from app()->setLocale().
        $this->admin->forceFill(['language' => 'ar'])->save();
        $this->actingAs($this->admin->fresh());
        Cache::flush();
        $ar = $this->postJson('/app/customers', ['name' => 'cairo company'])->json('errors.name.0');
        $this->assertStringContainsString('بالفعل', $ar);
    }

    public function test_renaming_to_another_customers_name_is_refused_but_keeping_your_own_is_fine(): void
    {
        $a = Customer::create(['company_id' => $this->company->id, 'name' => 'Alpha']);
        Customer::create(['company_id' => $this->company->id, 'name' => 'Beta']);

        $this->patchJson("/app/customers/{$a->id}", ['name' => ' beta '])->assertStatus(422);

        // Same name (only the spaces differ) on the same record: allowed.
        $this->patchJson("/app/customers/{$a->id}", ['name' => '  Alpha ', 'phone' => '0100'])->assertOk();
        $this->assertSame('Alpha', $a->fresh()->name);
        $this->assertSame('0100', $a->fresh()->phone);
    }

    public function test_editing_the_phone_of_an_old_duplicate_is_not_blocked(): void
    {
        $first  = Customer::create(['company_id' => $this->company->id, 'name' => 'Twin']);
        $second = Customer::create(['company_id' => $this->company->id, 'name' => 'Twin ']);

        $this->patchJson("/app/customers/{$second->id}", ['name' => 'Twin', 'phone' => '0111'])->assertOk();
    }

    // ── Suppliers / employees (one shared list) ─────────────────

    public function test_supplier_and_employee_names_are_checked_across_the_shared_list(): void
    {
        Vendor::create(['company_id' => $this->company->id, 'name' => 'Nile Supplies', 'type' => 'vendor']);
        Vendor::create(['company_id' => $this->company->id, 'name' => 'Ahmed Ali', 'type' => 'employee']);

        $this->postJson('/app/vendors', ['name' => ' nile  supplies ', 'type' => 'vendor'])->assertStatus(422);
        $this->postJson('/app/vendors', ['name' => 'Ahmed Ali', 'type' => 'vendor'])->assertStatus(422);
        $this->postJson('/app/vendors', ['name' => ' New Supplier  ', 'type' => 'vendor'])->assertCreated();

        $this->assertSame('New Supplier', Vendor::query()->latest('id')->first()->name);
    }

    public function test_supplier_rename_clash_is_refused_and_other_company_is_ignored(): void
    {
        $a = Vendor::create(['company_id' => $this->company->id, 'name' => 'Alpha', 'type' => 'vendor']);
        Vendor::create(['company_id' => $this->company->id, 'name' => 'Beta', 'type' => 'vendor']);
        $other = Company::factory()->create();
        Vendor::create(['company_id' => $other->id, 'name' => 'Gamma', 'type' => 'vendor']);

        $this->patchJson("/app/vendors/{$a->id}", ['name' => 'BETA'])->assertStatus(422);
        $this->patchJson("/app/vendors/{$a->id}", ['name' => 'Gamma'])->assertOk();
    }

    // ── Team members ─────────────────────────────────────────────

    public function test_team_member_names_are_checked_inside_the_company_only(): void
    {
        $payload = fn (string $name, string $email) => [
            'name' => $name, 'email' => $email,
            'password' => 'Str0ng!Passw0rd#', 'password_confirmation' => 'Str0ng!Passw0rd#',
        ];

        Cache::flush();
        $response = $this->post(route('app.team.store'), $payload('  Sara   Hassan ', 'sara@example.com'));
        $response->assertSessionHasNoErrors();
        $this->assertSame(
            'Sara Hassan',
            User::withoutGlobalScopes()->where('company_id', $this->company->id)->where('name', 'like', '%Sara%')->value('name'),
            'status '.$response->getStatusCode().' flash error: '.json_encode(session('error'))
        );

        Cache::flush();
        $this->post(route('app.team.store'), $payload('sara hassan', 'sara2@example.com'))
            ->assertSessionHasErrors('name');

        // Another company may have an "Omar Fathy" of its own.
        User::factory()->companyAdmin()->create(['name' => 'Omar Fathy']);
        Cache::flush();
        $this->post(route('app.team.store'), $payload('Omar Fathy', 'omar@example.com'))
            ->assertSessionHasNoErrors();
    }
}
