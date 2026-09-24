<?php

namespace App\Services;

use App\Models\User;
use App\Models\WebAuthnCredential;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\Exception\AuthenticatorResponseVerificationException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialUserEntity;

class MfaWebAuthnService
{
    private readonly SerializerInterface $serializer;

    private readonly CeremonyStepManagerFactory $ceremonyStepManagerFactory;

    public function __construct()
    {
        $attestationStatementSupportManager = new AttestationStatementSupportManager([
            new NoneAttestationStatementSupport,
        ]);
        $this->serializer = (new WebauthnSerializerFactory($attestationStatementSupportManager))->create();
        $this->ceremonyStepManagerFactory = new CeremonyStepManagerFactory;
        $this->ceremonyStepManagerFactory->setSecuredRelyingPartyId([$this->getRpId()]);
    }

    public function getRpId(): string
    {
        $url = parse_url(Config::string('app.url', 'http://localhost'));

        return $url['host'] ?? 'localhost';
    }

    public function getRpName(): string
    {
        return Config::string('app.name', 'Avapeek');
    }

    public function getOrigin(): string
    {
        return rtrim(Config::string('app.url', 'http://localhost'), '/');
    }

    public function getUserEntity(User $user): PublicKeyCredentialUserEntity
    {
        return PublicKeyCredentialUserEntity::create(
            $user->email,
            (string) $user->id,
            $user->display_name,
        );
    }

    public function generateChallenge(): string
    {
        return random_bytes(32);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildRegistrationOptions(User $user): array
    {
        $challenge = $this->generateChallenge();
        $excludeCredentials = $user->webauthnCredentials()
            ->get()
            ->map(fn (WebAuthnCredential $cred): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                base64_decode($cred->credential_id),
                $cred->transports ?? []
            ))
            ->all();

        $options = PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create($this->getRpName(), $this->getRpId()),
            user: $this->getUserEntity($user),
            challenge: $challenge,
            pubKeyCredParams: [
                PublicKeyCredentialParameters::create('public-key', -7),
                PublicKeyCredentialParameters::create('public-key', -257),
            ],
            authenticatorSelection: AuthenticatorSelectionCriteria::create(
                null,
                AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_NO_PREFERENCE,
                null,
            ),
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            excludeCredentials: $excludeCredentials,
            timeout: 60000,
        );

        return $this->serializeOptionsToArray($options);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildAssertionOptions(User $user): array
    {
        $challenge = $this->generateChallenge();
        $allowCredentials = $user->webauthnCredentials()
            ->get()
            ->map(fn (WebAuthnCredential $cred): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                base64_decode($cred->credential_id),
                $cred->transports ?? []
            ))
            ->all();

        $options = PublicKeyCredentialRequestOptions::create(
            challenge: $challenge,
            rpId: $this->getRpId(),
            allowCredentials: $allowCredentials,
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            timeout: 60000,
        );

        return $this->serializeOptionsToArray($options);
    }

    /**
     * @param  array<string, mixed>  $clientData
     *
     * @throws AuthenticatorResponseVerificationException
     */
    public function verifyRegistration(array $clientData, PublicKeyCredentialCreationOptions $options): PublicKeyCredentialSource
    {
        try {
            $publicKeyCredential = $this->loadClientCredential($clientData);
        } catch (Throwable $e) {
            throw new AuthenticatorResponseVerificationException('Invalid attestation response.', 0, $e);
        }

        if (! $publicKeyCredential->response instanceof AuthenticatorAttestationResponse) {
            throw new AuthenticatorResponseVerificationException('Invalid attestation response.');
        }

        $validator = new AuthenticatorAttestationResponseValidator(
            ceremonyStepManager: $this->ceremonyStepManagerFactory->creationCeremony([$this->getRpId()])
        );

        return $validator->check($publicKeyCredential->response, $options, $this->getOrigin());
    }

    /**
     * @param  array<string, mixed>  $clientData
     *
     * @throws AuthenticatorResponseVerificationException
     */
    public function verifyAssertion(array $clientData, PublicKeyCredentialRequestOptions $options, PublicKeyCredentialSource $credentialSource): PublicKeyCredentialSource
    {
        try {
            $publicKeyCredential = $this->loadClientCredential($clientData);
        } catch (Throwable $e) {
            throw new AuthenticatorResponseVerificationException('Invalid assertion response.', 0, $e);
        }

        if (! $publicKeyCredential->response instanceof AuthenticatorAssertionResponse) {
            throw new AuthenticatorResponseVerificationException('Invalid assertion response.');
        }

        $validator = new AuthenticatorAssertionResponseValidator(
            ceremonyStepManager: $this->ceremonyStepManagerFactory->requestCeremony([$this->getRpId()])
        );

        return $validator->check($credentialSource, $publicKeyCredential->response, $options, $this->getOrigin(), $credentialSource->userHandle);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeOptionsToArray(PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions $options): array
    {
        /** @var string $json */
        $json = $this->serializer->serialize($options, 'json');

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function loadClientCredential(array $data): PublicKeyCredential
    {
        $credential = $this->serializer->deserialize(json_encode($data, JSON_THROW_ON_ERROR), PublicKeyCredential::class, 'json');

        if (! $credential instanceof PublicKeyCredential) {
            throw new AuthenticatorResponseVerificationException('Unable to load the public key credential.');
        }

        return $credential;
    }

    public function deserializeCreationOptions(string $json): PublicKeyCredentialCreationOptions
    {
        $options = $this->serializer->deserialize($json, PublicKeyCredentialCreationOptions::class, 'json');

        if (! $options instanceof PublicKeyCredentialCreationOptions) {
            throw new InvalidArgumentException('Invalid creation options.');
        }

        return $options;
    }

    public function deserializeRequestOptions(string $json): PublicKeyCredentialRequestOptions
    {
        $options = $this->serializer->deserialize($json, PublicKeyCredentialRequestOptions::class, 'json');

        if (! $options instanceof PublicKeyCredentialRequestOptions) {
            throw new InvalidArgumentException('Invalid request options.');
        }

        return $options;
    }
}
