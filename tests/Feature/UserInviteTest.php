<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\UserInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\TestCase;

class UserInviteTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_invite_a_new_user_without_setting_a_password(): void
    {
        Notification::fake();
        $admin = User::factory()->create();

        Volt::actingAs($admin)->test('admin.users')
            ->call('create')
            ->assertSet('sendInvite', true)
            ->set('name', 'Invited Person')
            ->set('email', 'invited@example.com')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('An invitation has been sent to invited@example.com.');

        $user = User::firstWhere('email', 'invited@example.com');
        $this->assertTrue($user->isInvitePending());
        Notification::assertSentTo($user, UserInvitation::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.invited', 'subject_id' => $user->id]);
    }

    public function test_invited_user_can_set_their_password_and_is_verified(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $user->sendInvitation();

        Notification::assertSentTo($user, UserInvitation::class, function (UserInvitation $notification) use ($user) {
            $this->assertStringContainsString('/invite/'.$notification->token, $notification->toMail($user)->actionUrl);

            $this->get(route('invite.accept', ['token' => $notification->token, 'email' => $user->email]))
                ->assertOk()
                ->assertSeeVolt('pages.auth.accept-invite');

            Volt::test('pages.auth.accept-invite', ['token' => $notification->token])
                ->set('email', $user->email)
                ->set('password', 'new-secret-password')
                ->set('password_confirmation', 'new-secret-password')
                ->call('acceptInvite')
                ->assertHasNoErrors()
                ->assertRedirect('/login');

            return true;
        });

        $user->refresh();
        $this->assertTrue(Hash::check('new-secret-password', $user->password));
        $this->assertFalse($user->isInvitePending());
    }

    public function test_invite_link_is_rejected_after_seven_days(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $user->sendInvitation();

        $this->travel(8)->days();

        Notification::assertSentTo($user, UserInvitation::class, function (UserInvitation $notification) use ($user) {
            Volt::test('pages.auth.accept-invite', ['token' => $notification->token])
                ->set('email', $user->email)
                ->set('password', 'new-secret-password')
                ->set('password_confirmation', 'new-secret-password')
                ->call('acceptInvite')
                ->assertHasErrors('email');

            return true;
        });

        $this->assertTrue($user->fresh()->isInvitePending());
    }

    public function test_admin_can_resend_an_invite_only_while_it_is_pending(): void
    {
        Notification::fake();
        $admin = User::factory()->create();
        $pending = User::factory()->unverified()->create();
        $active = User::factory()->create();

        Volt::actingAs($admin)->test('admin.users')
            ->call('resendInvite', $pending->id)
            ->call('resendInvite', $active->id)
            ->assertSet('errorMessage', $active->email.' has already accepted their invitation.');

        Notification::assertSentTo($pending, UserInvitation::class);
        Notification::assertNotSentTo($active, UserInvitation::class);
    }
}
