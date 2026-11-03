<?php

namespace App\Services\Demo;

use App\Models\Child;
use App\Models\ChildUser;
use App\Models\Comment;
use App\Models\EncryptionKey;
use App\Models\Invite;
use App\Models\Photo;
use App\Models\Tag;
use App\Models\UploadIntent;
use App\Models\User;
use App\Services\QuotaService;
use App\Services\StorageService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates and removes a made-up family for trying Avapeek out. Demo users are marked
 * by their email domain. Seeding refuses to run next to real accounts, and clearing
 * reopens first-account registration, so a demo install can become a real one.
 */
class DemoDataService
{
    public const EMAIL_DOMAIN = 'demo.avapeek.test';

    public const PASSWORD = 'avapeek-demo-password';

    /** Validation rule keeping real accounts out of the domain that marks demo data for removal. */
    public const NOT_DEMO_EMAIL_RULE = 'not_regex:/@demo\.avapeek\.test$/i';

    private const TAG_BY_SCENE = [
        'sunset' => 'Evening walks',
        'beach' => 'Beach',
        'garden' => 'Garden',
        'party' => 'Birthday',
        'snow' => 'Winter',
        'forest' => 'Autumn',
    ];

    private const COMMENTS = [
        'What a lovely day that was!',
        'I want this one framed for the hallway.',
        'Look how much they have grown.',
        'This made my morning, thank you for sharing.',
        'Can we go back there next summer?',
        'Such a happy memory.',
        'Printing this one for the fridge.',
        'Missing you all, see you soon!',
    ];

    public function __construct(
        protected DemoCrypto $crypto,
        protected DemoImageFactory $images,
        protected StorageService $storageService,
        protected QuotaService $quotaService,
    ) {}

    /**
     * @return list<array{email: string, name: string, access: string}> The accounts created.
     */
    public function seed(int $photoCount): array
    {
        if (User::query()->exists()) {
            throw new \RuntimeException(
                self::hasDemoUsers()
                    ? 'Demo data already exists. Run avapeek:demo-clear first.'
                    : 'This instance has real accounts. Demo data can only be added to an empty instance.'
            );
        }

        try {
            Model::withoutEvents(fn () => $this->createFamily($photoCount));
        } catch (\Throwable $e) {
            $this->clear();

            throw $e;
        }

        return [
            ['email' => $this->email('alex'), 'name' => 'Alex Rivera', 'access' => 'Parent, owner of both children'],
            ['email' => $this->email('sam'), 'name' => 'Sam Rivera', 'access' => 'Parent, admin of both children'],
            ['email' => $this->email('rose'), 'name' => 'Grandma Rose', 'access' => 'View-only member of both children'],
            ['email' => $this->email('ben'), 'name' => 'Uncle Ben', 'access' => 'Joined Leo, waiting for the key until an admin signs in'],
        ];
    }

    /**
     * What clear() would do, without changing anything. `blockers` lists anything that
     * would take real data with it; clear() refuses to run while there are any.
     *
     * @return array{users: int, children: list<string>, photos: int, realAccounts: int, realMembersLosingAccess: int, blockers: list<string>}
     */
    public function clearPlan(): array
    {
        $userIds = $this->demoUserIds();
        $children = Child::query()->whereIn('created_by', $userIds)->get();
        $childIds = $children->pluck('id');

        $blockers = [];

        foreach ($children as $child) {
            $hasRealOwner = ChildUser::query()
                ->where('child_id', $child->id)
                ->where('role', 'owner')
                ->whereNotIn('user_id', $userIds)
                ->exists();
            if ($hasRealOwner) {
                $blockers[] = "{$child->name} now belongs to a real account.";
            }
        }

        $realPhotosInDemoChildren = Photo::query()
            ->whereNotIn('uploaded_by', $userIds)
            ->whereHas('children', fn ($query) => $query->whereIn('children.id', $childIds))
            ->count();
        if ($realPhotosInDemoChildren > 0) {
            $blockers[] = "{$realPhotosInDemoChildren} photo(s) uploaded by real accounts are in demo children.";
        }

        $demoPhotosInRealChildren = Photo::query()
            ->whereIn('uploaded_by', $userIds)
            ->whereHas('children', fn ($query) => $query->whereNotIn('children.id', $childIds))
            ->count();
        if ($demoPhotosInRealChildren > 0) {
            $blockers[] = "{$demoPhotosInRealChildren} demo photo(s) were shared into real children.";
        }

        return [
            'users' => $userIds->count(),
            'children' => $children->pluck('name')->all(),
            'photos' => Photo::query()
                ->where(fn ($query) => $query
                    ->whereIn('uploaded_by', $userIds)
                    ->orWhereHas('children', fn ($children) => $children->whereIn('children.id', $childIds)))
                ->count(),
            'realAccounts' => User::query()->whereNotIn('id', $userIds)->count(),
            'realMembersLosingAccess' => ChildUser::query()
                ->whereIn('child_id', $childIds)
                ->whereNotIn('user_id', $userIds)
                ->distinct()
                ->count('user_id'),
            'blockers' => $blockers,
        ];
    }

    /**
     * Removes demo users, their children and every blob that belonged to them. Real
     * accounts are kept; a real account that joined a demo child only loses that membership.
     *
     * @return array{users: int, photos: int} What was removed.
     *
     * @throws \RuntimeException When real data is tied to the demo data (see clearPlan()).
     */
    public function clear(): array
    {
        $blockers = $this->clearPlan()['blockers'];
        if ($blockers !== []) {
            throw new \RuntimeException(implode(' ', $blockers));
        }

        $userIds = $this->demoUserIds();
        $paths = [];
        $photoCount = 0;

        Model::withoutEvents(function () use ($userIds, &$paths, &$photoCount): void {
            DB::transaction(function () use ($userIds, &$paths, &$photoCount): void {
                $children = Child::query()->whereIn('created_by', $userIds)->get();
                $linkedPhotoIds = DB::table('child_photo')->whereIn('child_id', $children->pluck('id'))->pluck('photo_id');

                Invite::query()->whereIn('invited_by', $userIds)->delete();
                Child::query()->whereIn('id', $children->pluck('id'))->delete();

                Photo::query()
                    ->whereIn('uploaded_by', $userIds)
                    ->orWhere(fn ($query) => $query->whereIn('id', $linkedPhotoIds)->whereDoesntHave('children'))
                    ->get()
                    ->each(function (Photo $photo) use (&$paths, &$photoCount): void {
                        array_push($paths, $photo->original_path, $photo->thumbnail_path);
                        $this->quotaService->removeUsage((int) $photo->stored_bytes);
                        $photo->delete();
                        $photoCount++;
                    });

                UploadIntent::query()->whereIn('user_id', $userIds)->get()->each(function (UploadIntent $intent) use (&$paths): void {
                    array_push($paths, $intent->original_path, $intent->thumbnail_path);
                    $intent->delete();
                });

                User::query()->whereIn('id', $userIds)->get()->each(function (User $user): void {
                    $user->tokens()->delete();
                    $user->delete();
                });
            });
        });

        if ($paths !== []) {
            $this->storageService->deleteMultiple($paths);
        }

        return ['users' => $userIds->count(), 'photos' => $photoCount];
    }

    /**
     * @return Collection<int, int>
     */
    private function demoUserIds(): Collection
    {
        return User::query()->where('email', 'like', '%@'.self::EMAIL_DOMAIN)->pluck('id');
    }

    public static function hasDemoUsers(): bool
    {
        return User::query()->where('email', 'like', '%@'.self::EMAIL_DOMAIN)->exists();
    }

    private function createFamily(int $photoCount): void
    {
        $alex = $this->createUser('alex', 'Alex Rivera');
        $sam = $this->createUser('sam', 'Sam Rivera');
        $rose = $this->createUser('rose', 'Grandma Rose');
        $ben = $this->createUser('ben', 'Uncle Ben');

        $mia = $this->createChild('Mia', now()->subYears(6)->subDays(40), '#FF6B6B', $alex['user']);
        $leo = $this->createChild('Leo', now()->subYears(3)->subDays(75), '#4ECDC4', $alex['user']);
        $deks = [$mia->id => $this->crypto->generateKey(), $leo->id => $this->crypto->generateKey()];

        foreach ([$mia, $leo] as $child) {
            $this->addMember($child, $alex, 'owner', 'parent', true, $deks[$child->id]);
            $this->addMember($child, $sam, 'admin', 'parent', true, $deks[$child->id]);
            $this->addMember($child, $rose, 'member', 'grandparent', false, $deks[$child->id]);
        }
        $this->addMember($leo, $ben, 'member', 'uncle', true, null);

        Invite::create([
            'child_id' => $mia->id,
            'token' => Str::random(64),
            'email' => null,
            'role' => 'member',
            'relationship' => 'aunt',
            'can_upload' => false,
            'invited_by' => $alex['user']->id,
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
        ]);

        $tags = $this->createTags([$mia, $leo], $deks, $alex['user']);

        for ($index = 0; $index < $photoCount; $index++) {
            $children = match (true) {
                $index % 7 === 0 => [$mia, $leo],
                $index % 2 === 0 => [$mia],
                default => [$leo],
            };
            $photo = $this->createPhoto($index, $children, $deks, $tags, $index % 3 === 0 ? $sam['user'] : $alex['user']);

            if ($index % 3 === 0) {
                $this->addComments($photo['photo'], $photo['pek'], $index, [$alex['user'], $sam['user'], $rose['user']]);
            }
        }

        // Leave the newest photos unseen so Recents shows a badge on first sign-in.
        User::query()->whereIn('id', [$alex['user']->id, $sam['user']->id, $rose['user']->id])
            ->update(['last_seen_at' => now()->subDays(3)]);
    }

    /**
     * @return array{user: User, kek: string}
     */
    private function createUser(string $localPart, string $displayName): array
    {
        $salt = $this->crypto->generateSalt();
        $kek = $this->crypto->deriveKek(self::PASSWORD, $salt);
        $keyPair = $this->crypto->generateKeyPair();

        $user = User::create([
            'email' => $this->email($localPart),
            'password_hash' => Hash::make($this->crypto->deriveAuthHash($kek)),
            'display_name' => $displayName,
            'master_key_salt' => $salt,
            'public_key' => $keyPair['public_jwk'],
            'encrypted_private_key' => $this->crypto->wrapWithKek($keyPair['private_pkcs8'], $kek),
        ]);

        return ['user' => $user, 'kek' => $kek];
    }

    private function createChild(string $name, \DateTimeInterface $birthDate, string $avatarColor, User $creator): Child
    {
        return Child::create([
            'name' => $name,
            'slug' => Str::slug($name),
            'birth_date' => $birthDate,
            'avatar_color' => $avatarColor,
            'created_by' => $creator->id,
        ]);
    }

    /**
     * @param  array{user: User, kek: string}  $member
     * @param  string|null  $dek  Null leaves the member waiting for someone to share the key.
     */
    private function addMember(Child $child, array $member, string $role, string $relationship, bool $canUpload, ?string $dek): void
    {
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $member['user']->id,
            'role' => $role,
            'relationship' => $relationship,
            'can_upload' => $canUpload,
        ]);

        if ($dek !== null) {
            EncryptionKey::create([
                'child_id' => $child->id,
                'user_id' => $member['user']->id,
                'encrypted_dek' => $this->crypto->wrapWithKek($dek, $member['kek']),
                'wrap_type' => EncryptionKey::WRAP_KEK,
                'key_version' => 1,
            ]);
        }
    }

    /**
     * @param  list<Child>  $children
     * @param  array<int, string>  $deks
     * @return array<int, array<string, int>> Tag ids by child id and scene.
     */
    private function createTags(array $children, array $deks, User $creator): array
    {
        $tags = [];
        foreach ($children as $child) {
            foreach (self::TAG_BY_SCENE as $scene => $name) {
                $encrypted = $this->crypto->encryptString($name, $deks[$child->id]);
                $tags[$child->id][$scene] = Tag::create([
                    'child_id' => $child->id,
                    'created_by' => $creator->id,
                    'name_encrypted' => $encrypted['encrypted'],
                    'iv_name' => $encrypted['iv'],
                ])->id;
            }
        }

        return $tags;
    }

    /**
     * @param  list<Child>  $children
     * @param  array<int, string>  $deks
     * @param  array<int, array<string, int>>  $tags
     * @return array{photo: Photo, pek: string}
     */
    private function createPhoto(int $index, array $children, array $deks, array $tags, User $uploader): array
    {
        $scene = DemoImageFactory::SCENES[$index % count(DemoImageFactory::SCENES)];
        $image = $this->images->make($scene, $index);
        $uuid = (string) Str::uuid();
        $pek = $this->crypto->generateKey();

        $original = $this->crypto->encryptBuffer($image['original'], $pek);
        $thumbnail = $this->crypto->encryptBuffer($image['thumbnail'], $pek);
        $lqip = $this->crypto->encryptString($image['lqip'], $pek);
        $name = $this->crypto->encryptString('IMG_'.(4000 + $index).'.jpg', $pek);

        $originalPath = "photos/{$uuid}.jpg.enc";
        $thumbnailPath = "photos/{$uuid}_thumb.webp.enc";
        $storedBytes = strlen($original['encrypted']) + strlen($thumbnail['encrypted']);
        $takenAt = $this->takenAt($index);

        $this->storageService->upload($originalPath, $original['encrypted']);
        $this->storageService->upload($thumbnailPath, $thumbnail['encrypted']);

        $photo = new Photo([
            'uuid' => $uuid,
            'uploaded_by' => $uploader->id,
            'original_path' => $originalPath,
            'thumbnail_path' => $thumbnailPath,
            'lqip_encrypted' => $lqip['encrypted'],
            'iv_lqip' => $lqip['iv'],
            'iv_original' => $original['iv'],
            'iv_thumbnail' => $thumbnail['iv'],
            'original_mime' => 'image/jpeg',
            'size_bytes' => strlen($image['original']),
            'stored_bytes' => $storedBytes,
            'width' => $image['width'],
            'height' => $image['height'],
            'name_encrypted' => $name['encrypted'],
            'iv_name' => $name['iv'],
            'taken_at' => $takenAt,
        ]);
        $photo->forceFill(['created_at' => $takenAt])->save();

        foreach ($children as $child) {
            $photo->children()->attach($child->id, $this->crypto->wrapPek($pek, $deks[$child->id]));
            $photo->tags()->attach($tags[$child->id][$scene]);
        }

        $this->quotaService->addUsage($storedBytes);

        return ['photo' => $photo, 'pek' => $pek];
    }

    /**
     * Spreads photos over the past: a few recent ones, a few "on this day" in earlier
     * years so Memories has content, and the rest scattered over about two and a half years.
     */
    private function takenAt(int $index): \DateTimeInterface
    {
        return match (true) {
            $index < 4 => now()->subHours(3 + $index * 11),
            $index < 7 => now()->subYears($index - 3)->setTime(12, 0),
            default => now()->subDays(4 + ($index * 37) % 900)->setTime(8 + $index % 11, $index % 60),
        };
    }

    /**
     * @param  list<User>  $authors
     */
    private function addComments(Photo $photo, string $pek, int $index, array $authors): void
    {
        for ($turn = 0; $turn <= $index % 2; $turn++) {
            $encrypted = $this->crypto->encryptString(
                self::COMMENTS[($index + $turn) % count(self::COMMENTS)],
                $pek,
            );
            $comment = new Comment([
                'photo_id' => $photo->id,
                'user_id' => $authors[($index + $turn) % count($authors)]->id,
                'body_encrypted' => $encrypted['encrypted'],
                'iv_body' => $encrypted['iv'],
            ]);
            $comment->forceFill(['created_at' => $photo->taken_at->copy()->addMinutes(20 + $turn * 45)])->save();
        }
    }

    private function email(string $localPart): string
    {
        return $localPart.'@'.self::EMAIL_DOMAIN;
    }
}
