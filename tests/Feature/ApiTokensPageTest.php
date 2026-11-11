<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\ManageApiTokens;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;
use Tests\TestCase;

class ApiTokensPageTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserRole $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    public function test_page_renders_for_editors_and_admins(): void
    {
        $this->actingAs($this->user(UserRole::Editor))->get('/admin/api-tokens')->assertOk();
        $this->actingAs($this->user(UserRole::Admin))->get('/admin/api-tokens')->assertOk();
    }

    public function test_editor_creates_a_token_and_sees_it_once(): void
    {
        $editor = $this->user(UserRole::Editor);
        $this->actingAs($editor);

        Livewire::test(ManageApiTokens::class)
            ->callAction(TestAction::make('create')->table(), ['name' => 'Claude'])
            ->assertHasNoActionErrors()
            ->assertActionMounted('showToken');

        $token = $editor->tokens()->sole();
        $this->assertSame('Claude', $token->name);
        $this->assertNull($token->expires_at);
    }

    public function test_new_token_works_against_the_mcp_endpoint(): void
    {
        $editor = $this->user(UserRole::Editor);
        $this->actingAs($editor);

        $page = Livewire::test(ManageApiTokens::class)
            ->callAction(TestAction::make('create')->table(), ['name' => 'Claude']);

        $plain = $page->instance()->mountedActions[0]['arguments']['token'];
        $this->assertStringContainsString('|', $plain);

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json, text/event-stream'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) []])
            ->assertOk();
    }

    public function test_expiry_is_saved_and_enforced(): void
    {
        $editor = $this->user(UserRole::Editor);
        $this->actingAs($editor);

        Livewire::test(ManageApiTokens::class)
            ->callAction(TestAction::make('create')->table(), ['name' => 'Short', 'expires_at' => now()->addDay()->toDateTimeString()])
            ->assertHasNoActionErrors();

        $this->assertTrue($editor->tokens()->sole()->expires_at->isFuture());

        $expired = $editor->createToken('Old', expiresAt: now()->subMinute())->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => "Bearer {$expired}", 'Accept' => 'application/json, text/event-stream'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) []])
            ->assertUnauthorized();
    }

    public function test_editors_only_see_and_revoke_their_own_tokens(): void
    {
        $editor = $this->user(UserRole::Editor);
        $other = $this->user(UserRole::Editor);
        $mine = $editor->createToken('mine')->accessToken;
        $theirs = $other->createToken('theirs')->accessToken;
        $this->actingAs($editor);

        Livewire::test(ManageApiTokens::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs])
            ->callAction(TestAction::make(DeleteAction::class)->table($mine));

        $this->assertModelMissing($mine);
        $this->assertModelExists($theirs);
    }

    public function test_editors_cannot_issue_tokens_for_others(): void
    {
        $editor = $this->user(UserRole::Editor);
        $other = $this->user(UserRole::Admin);
        $this->actingAs($editor);

        Livewire::test(ManageApiTokens::class)
            ->callAction(TestAction::make('create')->table(), ['name' => 'Sneaky', 'user_id' => $other->id]);

        $this->assertSame(0, $other->tokens()->count());
        $this->assertSame(1, $editor->tokens()->count());
    }

    public function test_admins_see_all_tokens_and_issue_for_other_users(): void
    {
        $admin = $this->user(UserRole::Admin);
        $bot = $this->user(UserRole::Editor);
        $theirs = $bot->createToken('theirs')->accessToken;
        $this->actingAs($admin);

        Livewire::test(ManageApiTokens::class)
            ->assertCanSeeTableRecords([$theirs])
            ->callAction(TestAction::make('create')->table(), ['name' => 'On-call agent', 'user_id' => $bot->id])
            ->assertHasNoActionErrors();

        $this->assertSame(['theirs', 'On-call agent'], $bot->tokens()->orderBy('id')->pluck('name')->all());
    }

    public function test_deleting_a_user_revokes_their_tokens(): void
    {
        $user = $this->user(UserRole::Editor);
        $user->createToken('x');

        $user->delete();

        $this->assertSame(0, PersonalAccessToken::count());
    }
}
