<?php

namespace App\Models;

use Database\Factories\WebAuthnCredentialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Symfony\Component\Uid\Uuid;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * @method static WebAuthnCredentialFactory factory(...$parameters)
 */
class WebAuthnCredential extends Model
{
    /** @use HasFactory<WebAuthnCredentialFactory> */
    use HasFactory;

    protected $table = 'webauthn_credentials';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'user_id',
        'credential_id',
        'public_key',
        'sign_count',
        'transports',
        'user_chosen_name',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'sign_count' => 'integer',
            'transports' => 'array',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function toPublicKeyCredentialSource(): PublicKeyCredentialSource
    {
        return PublicKeyCredentialSource::create(
            publicKeyCredentialId: base64_decode($this->credential_id),
            type: 'public-key',
            transports: $this->transports ?? [],
            attestationType: 'none',
            trustPath: new EmptyTrustPath,
            aaguid: Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            credentialPublicKey: base64_decode($this->public_key),
            userHandle: (string) $this->user_id,
            counter: $this->sign_count,
        );
    }
}
