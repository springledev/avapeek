<?php

use App\Models\Child;
use App\Models\ChildUser;
use App\Models\Comment;
use App\Models\EncryptionKey;
use App\Models\Photo;
use App\Models\StorageUsage;
use App\Models\Tag;
use App\Models\User;
use App\Services\Demo\DemoCrypto;
use App\Services\Demo\DemoDataService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('s3');
});

/**
 * Opens AES-GCM output the way Web Crypto does: the last 16 bytes are the tag.
 */
function demoOpen(string $sealed, string $key, string $iv): string
{
    $plaintext = openssl_decrypt(substr($sealed, 0, -16), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, substr($sealed, -16));
    expect($plaintext)->not->toBeFalse();

    return $plaintext;
}

function demoUnwrap(string $wrapped, string $kek): string
{
    $bytes = base64_decode($wrapped);

    return demoOpen(substr($bytes, 12), $kek, substr($bytes, 0, 12));
}

it('derives the same keys as the browser client', function () {
    // Known answers produced by frontend/src/crypto/ (keychain.ts, auth-hash.ts).
    $crypto = new DemoCrypto;
    $kek = $crypto->deriveKek('correct horse battery staple', str_repeat('ab', 32));

    expect(bin2hex($kek))->toBe('bf1519c8713e14965f850a594abaa0dfad7ba98b9192578b9a21a086d449eca3')
        ->and($crypto->deriveAuthHash($kek))->toBe('0226c486a92fed3bd8f5dfbe658ff72d9801b6d38ef89e47b5d4bee165bcab6d');
});

it('opens a key wrapped by the browser client with the same format it writes', function () {
    $crypto = new DemoCrypto;
    $kek = hex2bin('bf1519c8713e14965f850a594abaa0dfad7ba98b9192578b9a21a086d449eca3');

    expect(bin2hex(demoUnwrap('BKMz/MrZND36C6j2CYqWrpIBp3KEU4YqURezTIgPyyH2+xvDU3zEi8jewcGrLG3TKGOerVn5f+z4xwXp', $kek)))->toBe('c6697c57cacd5b2aa186e4bddf4b61566a900ac6aea28105fefa685fdbd7a6c2')
        ->and(strlen(base64_decode('BKMz/MrZND36C6j2CYqWrpIBp3KEU4YqURezTIgPyyH2+xvDU3zEi8jewcGrLG3TKGOerVn5f+z4xwXp')))->toBe(strlen(base64_decode($crypto->wrapWithKek(random_bytes(32), $kek))));
});

it('seeds a family whose accounts can sign in and whose data decrypts', function () {
    $this->artisan('avapeek:demo-seed', ['--photos' => 8])->assertSuccessful();

    expect(User::count())->toBe(4)
        ->and(Child::count())->toBe(2)
        ->and(Photo::count())->toBe(8);

    $crypto = new DemoCrypto;
    $alex = User::where('email', 'alex@'.DemoDataService::EMAIL_DOMAIN)->first();
    $kek = $crypto->deriveKek(DemoDataService::PASSWORD, $alex->master_key_salt);

    $this->postJson('/api/auth/login', [
        'email' => $alex->email,
        'auth_hash' => $crypto->deriveAuthHash($kek),
    ])->assertOk()->assertJsonStructure(['token']);

    $photo = Photo::with('children')->first();
    $child = $photo->children->first();
    $dek = demoUnwrap(EncryptionKey::where('user_id', $alex->id)->where('child_id', $child->id)->value('encrypted_dek'), $kek);
    $pek = demoOpen(base64_decode($child->pivot->encrypted_pek), $dek, base64_decode($child->pivot->iv_pek));

    $original = demoOpen(Storage::disk('s3')->get($photo->original_path), $pek, hex2bin($photo->iv_original));
    expect(getimagesizefromstring($original)['mime'])->toBe('image/jpeg')
        ->and(strlen($original))->toBe((int) $photo->size_bytes);

    $thumbnail = demoOpen(Storage::disk('s3')->get($photo->thumbnail_path), $pek, hex2bin($photo->iv_thumbnail));
    expect(getimagesizefromstring($thumbnail)['mime'])->toBe('image/webp');

    $lqip = demoOpen(base64_decode($photo->lqip_encrypted), $pek, hex2bin($photo->iv_lqip));
    expect(getimagesizefromstring($lqip)['mime'])->toBe('image/webp');

    expect(demoOpen(base64_decode($photo->name_encrypted), $pek, hex2bin($photo->iv_name)))->toStartWith('IMG_');

    $tag = Tag::where('child_id', $child->id)->first();
    expect(demoOpen(base64_decode($tag->name_encrypted), $dek, hex2bin($tag->iv_name)))->not->toBe('');

    $comment = Comment::where('photo_id', $photo->id)->first();
    expect(demoOpen(base64_decode($comment->body_encrypted), $pek, hex2bin($comment->iv_body)))->not->toBe('');

    expect((int) StorageUsage::global()->used_bytes)->toBe((int) Photo::sum('stored_bytes'));
});

it('leaves the view-only and waiting members in the expected state', function () {
    $this->artisan('avapeek:demo-seed', ['--photos' => 0])->assertSuccessful();

    $ben = User::where('email', 'ben@'.DemoDataService::EMAIL_DOMAIN)->first();
    $rose = User::where('email', 'rose@'.DemoDataService::EMAIL_DOMAIN)->first();

    expect(EncryptionKey::where('user_id', $ben->id)->exists())->toBeFalse()
        ->and($ben->children()->count())->toBe(1)
        ->and(EncryptionKey::where('user_id', $rose->id)->count())->toBe(2)
        ->and($rose->uploadableChildIds())->toBeEmpty();
});

it('does not send push notifications while seeding', function () {
    Queue::fake();

    $this->artisan('avapeek:demo-seed', ['--photos' => 3])->assertSuccessful();

    Queue::assertNothingPushed();
});

it('refuses to seed next to real accounts', function () {
    User::factory()->create();

    $this->artisan('avapeek:demo-seed')->assertFailed();

    expect(Child::count())->toBe(0)->and(Photo::count())->toBe(0);
});

it('refuses to seed twice', function () {
    $this->artisan('avapeek:demo-seed', ['--photos' => 0])->assertSuccessful();
    $this->artisan('avapeek:demo-seed', ['--photos' => 0])->assertFailed();

    expect(User::count())->toBe(4);
});

it('clears everything and reopens first-account registration', function () {
    $this->artisan('avapeek:demo-seed', ['--photos' => 4])->assertSuccessful();
    expect(Storage::disk('s3')->allFiles())->not->toBeEmpty();
    expect($this->getJson('/api/auth/registration-mode')->json('data.mode'))->toBe('invite_only');

    $this->artisan('avapeek:demo-clear', ['--force' => true])->assertSuccessful();

    expect(User::count())->toBe(0)
        ->and(Child::count())->toBe(0)
        ->and(Photo::count())->toBe(0)
        ->and(Tag::count())->toBe(0)
        ->and(Comment::count())->toBe(0)
        ->and(Storage::disk('s3')->allFiles())->toBeEmpty()
        ->and((int) StorageUsage::global()->used_bytes)->toBe(0)
        ->and($this->getJson('/api/auth/registration-mode')->json('data.mode'))->toBe('open');
});

it('leaves real accounts and their photos alone when clearing', function () {
    $this->artisan('avapeek:demo-seed', ['--photos' => 2])->assertSuccessful();
    $real = User::factory()->create();
    $child = Child::factory()->create(['created_by' => $real->id]);
    $photo = Photo::factory()->create(['uploaded_by' => $real->id]);
    $photo->children()->attach($child->id, ['encrypted_pek' => 'x', 'iv_pek' => 'y']);

    $this->artisan('avapeek:demo-clear', ['--force' => true])->assertSuccessful();

    expect(User::pluck('id')->all())->toBe([$real->id])
        ->and(Child::pluck('id')->all())->toBe([$child->id])
        ->and(Photo::pluck('id')->all())->toBe([$photo->id]);
});

it('asks before clearing and changes nothing when declined', function () {
    $this->artisan('avapeek:demo-seed', ['--photos' => 1])->assertSuccessful();

    $this->artisan('avapeek:demo-clear')
        ->expectsConfirmation('Delete this demo data?', 'no')
        ->assertFailed();

    expect(User::count())->toBe(4)->and(Photo::count())->toBe(1);

    $this->artisan('avapeek:demo-clear')
        ->expectsConfirmation('Delete this demo data?', 'yes')
        ->assertSuccessful();

    expect(User::count())->toBe(0);
});

it('changes nothing without --force when it cannot ask', function () {
    $this->artisan('avapeek:demo-seed', ['--photos' => 1])->assertSuccessful();

    $this->artisan('avapeek:demo-clear', ['--no-interaction' => true])->assertFailed();

    expect(User::count())->toBe(4);
});

it('does nothing on an instance without demo data', function () {
    $real = User::factory()->create();

    $this->artisan('avapeek:demo-clear')->assertSuccessful();

    expect(User::pluck('id')->all())->toBe([$real->id]);
});

it('refuses to clear when a real account uploaded into a demo child', function () {
    $this->artisan('avapeek:demo-seed', ['--photos' => 1])->assertSuccessful();
    $real = User::factory()->create();
    $mia = Child::where('name', 'Mia')->first();
    ChildUser::create(['child_id' => $mia->id, 'user_id' => $real->id, 'role' => 'member']);
    $photo = Photo::factory()->create(['uploaded_by' => $real->id]);
    $photo->children()->attach($mia->id, ['encrypted_pek' => 'x', 'iv_pek' => 'y']);

    $this->artisan('avapeek:demo-clear', ['--force' => true])->assertFailed();

    expect(User::count())->toBe(5)->and(Photo::whereKey($photo->id)->exists())->toBeTrue();
});

it('refuses to clear when a demo child now belongs to a real account', function () {
    $this->artisan('avapeek:demo-seed', ['--photos' => 0])->assertSuccessful();
    $real = User::factory()->create();
    $mia = Child::where('name', 'Mia')->first();
    ChildUser::where('child_id', $mia->id)->where('role', 'owner')->update(['role' => 'admin']);
    ChildUser::create(['child_id' => $mia->id, 'user_id' => $real->id, 'role' => 'owner']);

    $this->artisan('avapeek:demo-clear', ['--force' => true])->assertFailed();

    expect(Child::whereKey($mia->id)->exists())->toBeTrue()->and(User::count())->toBe(5);
});

it('refuses to clear when a demo photo was shared into a real child', function () {
    $this->artisan('avapeek:demo-seed', ['--photos' => 1])->assertSuccessful();
    $real = User::factory()->create();
    $child = Child::factory()->create(['created_by' => $real->id]);
    Photo::first()->children()->attach($child->id, ['encrypted_pek' => 'x', 'iv_pek' => 'y']);

    $this->artisan('avapeek:demo-clear', ['--force' => true])->assertFailed();

    expect(Photo::count())->toBe(1);
});

it('keeps a real account that joined a demo child but removes its membership', function () {
    $this->artisan('avapeek:demo-seed', ['--photos' => 1])->assertSuccessful();
    $real = User::factory()->create();
    $mia = Child::where('name', 'Mia')->first();
    ChildUser::create(['child_id' => $mia->id, 'user_id' => $real->id, 'role' => 'member']);

    $this->artisan('avapeek:demo-clear', ['--force' => true])->assertSuccessful();

    expect(User::pluck('id')->all())->toBe([$real->id])
        ->and($real->children()->count())->toBe(0);
});

it('does not let a real account take the demo email domain', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson('/api/user/profile', ['email' => 'someone@DEMO.avapeek.test'])
        ->assertUnprocessable();

    expect($user->fresh()->email)->not->toEndWith('avapeek.test');
});
