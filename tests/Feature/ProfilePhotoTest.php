<?php

namespace Tests\Feature;

use App\Infrastructure\Accounts\ProfilePhoto;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->user = User::create(['name' => 'Fernando', 'username' => 'FERNANDO', 'password' => 'SyntheticPassword123']);
        $this->actingAs($this->user);
    }

    public function test_upload_reencodes_and_shows_private_photo_in_profile_and_navigation(): void
    {
        $this->post('/account/avatar', ['avatar' => UploadedFile::fake()->image('foto.png', 600, 400)])
            ->assertRedirect('/account')->assertSessionHasNoErrors();
        $user = $this->user->fresh();
        $this->assertSame(0, $user->session_version);
        $this->assertNotNull($user->avatar_path);
        Storage::disk('local')->assertExists($user->avatar_path);
        $contents = Storage::disk('local')->get($user->avatar_path);
        $info = getimagesizefromstring($contents);
        $this->assertSame([256, 256, 'image/jpeg'], [$info[0], $info[1], $info['mime']]);
        $this->assertSame(hash('sha256', $contents), $user->avatar_sha256);
        $this->assertArrayNotHasKey('avatar_path', $user->toArray());
        $this->assertArrayNotHasKey('avatar_sha256', $user->toArray());
        $this->get('/account/avatar')->assertOk()->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('Cache-Control', 'no-store, private')->assertContent($contents);
        $this->get('/account')->assertOk()->assertSee('Salvar foto')->assertSee('Remover foto e usar minha inicial')
            ->assertSee(route('account.avatar.show'))->assertDontSee($user->avatar_path);
        $this->assertDatabaseHas('activity_events', ['actor_id' => $user->id, 'action' => 'profile_photo_updated']);
    }

    public function test_photo_is_personal_and_cannot_be_selected_using_another_user_id(): void
    {
        $this->post('/account/avatar', ['avatar' => UploadedFile::fake()->image('original.jpg', 64, 64)])->assertSessionHasNoErrors();
        $firstPath = $this->user->fresh()->avatar_path;
        $other = User::create(['name' => 'Outra pessoa', 'username' => 'OTHER', 'password' => 'SyntheticPassword123']);
        $this->actingAs($other)->get('/account/avatar?user_id='.$this->user->id)->assertNotFound();
        $this->post('/account/avatar', ['user_id' => $this->user->id, 'avatar_path' => $firstPath,
            'avatar' => UploadedFile::fake()->image('outra.webp', 64, 64)])->assertSessionHasNoErrors();
        $this->assertSame($firstPath, $this->user->fresh()->avatar_path);
        $this->assertNotSame($firstPath, $other->fresh()->avatar_path);
        $this->delete('/account/avatar', ['user_id' => $this->user->id])->assertRedirect('/account');
        Storage::disk('local')->assertExists($firstPath);
        $this->assertNull($other->fresh()->avatar_path);
    }

    public function test_replacing_and_removing_photo_cleans_only_the_previous_file(): void
    {
        $this->post('/account/avatar', ['avatar' => UploadedFile::fake()->image('primeira.jpg', 64, 64)]);
        $old = $this->user->fresh()->avatar_path;
        $this->post('/account/avatar', ['avatar' => UploadedFile::fake()->image('segunda.png', 64, 64)])->assertSessionHasNoErrors();
        $current = $this->user->fresh()->avatar_path;
        $this->assertNotSame($old, $current);
        Storage::disk('local')->assertMissing($old);
        Storage::disk('local')->assertExists($current);
        $this->delete('/account/avatar')->assertRedirect('/account');
        Storage::disk('local')->assertMissing($current);
        $this->assertNull($this->user->fresh()->avatar_path);
        $this->assertNull($this->user->fresh()->avatar_sha256);
        $this->get('/account/avatar')->assertNotFound();
        $this->get('/account')->assertOk()->assertDontSee('<img src="'.route('account.avatar.show'), false);
        $this->assertDatabaseHas('activity_events', ['action' => 'profile_photo_removed']);
    }

    public function test_invalid_type_size_dimensions_or_content_cannot_change_photo(): void
    {
        foreach ([UploadedFile::fake()->create('foto.svg', 1, 'image/svg+xml'),
            UploadedFile::fake()->create('foto.jpg', 1, 'image/jpeg'),
            UploadedFile::fake()->image('grande.png', 64, 64)->size(2049),
            UploadedFile::fake()->image('larga.png', 3001, 32)] as $file) {
            $this->post('/account/avatar', ['avatar' => $file])->assertSessionHasErrors('avatar');
        }
        $this->assertNull($this->user->fresh()->avatar_path);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_failed_audit_rolls_back_update_and_removal_without_losing_previous_photo(): void
    {
        $this->post('/account/avatar', ['avatar' => UploadedFile::fake()->image('original.png', 64, 64)]);
        $original = $this->user->fresh();
        ActivityEvent::creating(fn () => throw new \RuntimeException('Synthetic audit failure'));
        try {
            $this->post('/account/avatar', ['avatar' => UploadedFile::fake()->image('nova.png', 64, 64)])->assertStatus(500);
            $this->delete('/account/avatar')->assertStatus(500);
            $this->assertSame($original->avatar_path, $this->user->fresh()->avatar_path);
            $this->assertSame([$original->avatar_path], Storage::disk('local')->allFiles());
            $this->assertDatabaseCount('activity_events', 1);
        } finally {
            ActivityEvent::flushEventListeners();
        }
    }

    public function test_tampered_file_and_missing_authentication_are_rejected(): void
    {
        $this->post('/account/avatar', ['avatar' => UploadedFile::fake()->image('foto.png', 64, 64)]);
        Storage::disk('local')->put($this->user->fresh()->avatar_path, 'tampered');
        $this->get('/account/avatar')->assertStatus(409);
        auth()->logout();
        $this->get('/account/avatar')->assertRedirect('/login');
        $this->post('/account/avatar', ['avatar' => UploadedFile::fake()->image('foto.png', 64, 64)])->assertRedirect('/login');
        $this->delete('/account/avatar')->assertRedirect('/login');
    }

    public function test_old_session_identity_cannot_replace_photo(): void
    {
        $expected = clone $this->user;
        $this->user->session_version++;
        $this->user->save();
        try {
            app(ProfilePhoto::class)->replace($expected, UploadedFile::fake()->image('foto.png', 64, 64));
            $this->fail('Expected stale identity rejection');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertNull($this->user->fresh()->avatar_path);
    }
}
