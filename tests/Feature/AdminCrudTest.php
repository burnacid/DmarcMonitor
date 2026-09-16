<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\ImapAccount;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AdminCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_admin_pages(): void
    {
        $this->get('/admin/organisations')->assertRedirect('/login');
        $this->get('/admin/domains')->assertRedirect('/login');
        $this->get('/admin/imap-accounts')->assertRedirect('/login');
    }

    public function test_authenticated_user_can_create_an_organisation(): void
    {
        $user = User::factory()->create();

        Volt::actingAs($user)->test('admin.organisations')
            ->call('create')
            ->set('name', 'Acme Inc')
            ->set('notes', 'Test org')
            ->call('save');

        $this->assertDatabaseHas('organisations', ['name' => 'Acme Inc']);
    }

    public function test_authenticated_user_can_create_a_domain_assigned_to_an_organisation(): void
    {
        $user = User::factory()->create();
        $org = Organisation::create(['name' => 'Acme Inc']);

        Volt::actingAs($user)->test('admin.domains')
            ->call('create')
            ->set('fqdn', 'example.com')
            ->set('organisation_id', $org->id)
            ->call('save');

        $this->assertDatabaseHas('domains', [
            'fqdn' => 'example.com',
            'organisation_id' => $org->id,
        ]);
    }

    public function test_domain_fqdn_must_be_unique(): void
    {
        $user = User::factory()->create();
        Domain::create(['fqdn' => 'example.com']);

        Volt::actingAs($user)->test('admin.domains')
            ->call('create')
            ->set('fqdn', 'example.com')
            ->call('save')
            ->assertHasErrors(['fqdn']);
    }

    public function test_deleting_a_domain_soft_deletes_it(): void
    {
        $user = User::factory()->create();
        $domain = Domain::create(['fqdn' => 'example.com']);

        Volt::actingAs($user)->test('admin.domains')
            ->call('delete', $domain->id);

        $this->assertDatabaseHas('domains', ['id' => $domain->id]);
        $this->assertSoftDeleted('domains', ['id' => $domain->id]);
        $this->assertNull(Domain::find($domain->id));
    }

    public function test_admin_can_restore_a_trashed_domain(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $domain = Domain::create(['fqdn' => 'example.com']);
        $domain->delete();

        Volt::actingAs($admin)->test('admin.domains-trash')
            ->call('restore', $domain->id);

        $this->assertDatabaseHas('domains', ['id' => $domain->id, 'deleted_at' => null]);
    }

    public function test_authenticated_user_can_create_an_imap_account_with_domains(): void
    {
        $user = User::factory()->create();
        $domain = Domain::create(['fqdn' => 'example.com']);

        Volt::actingAs($user)->test('admin.imap-accounts')
            ->call('create')
            ->set('label', 'Main mailbox')
            ->set('host', 'imap.example.com')
            ->set('port', 993)
            ->set('encryption', 'ssl')
            ->set('username', 'reports@example.com')
            ->set('password', 'secret')
            ->set('include_read_messages', true)
            ->set('domain_ids', [$domain->id])
            ->call('save');

        $this->assertDatabaseHas('imap_accounts', [
            'label' => 'Main mailbox',
            'host' => 'imap.example.com',
            'include_read_messages' => true,
        ]);

        $account = ImapAccount::firstWhere('label', 'Main mailbox');
        $this->assertTrue($account->domains->contains($domain));
        $this->assertNotEquals('secret', $account->getRawOriginal('password'));
    }

    public function test_editing_an_imap_account_without_password_keeps_existing_password(): void
    {
        $user = User::factory()->create();
        $account = ImapAccount::create([
            'label' => 'Main mailbox',
            'host' => 'imap.example.com',
            'port' => 993,
            'encryption' => 'ssl',
            'username' => 'reports@example.com',
            'password' => 'original-secret',
        ]);

        Volt::actingAs($user)->test('admin.imap-accounts')
            ->call('edit', $account->id)
            ->set('label', 'Renamed mailbox')
            ->set('password', '')
            ->call('save');

        $account->refresh();
        $this->assertEquals('Renamed mailbox', $account->label);
        $this->assertEquals('original-secret', $account->password);
    }
}
