<?php

namespace App\Services\Demo;

/**
 * Produces ciphertext in exactly the formats of `frontend/src/crypto/`, so the browser
 * opens demo data like anything it encrypted itself. Only ever used on made-up demo
 * content: real user data is encrypted in the browser and never reaches this class.
 */
class DemoCrypto
{
    private const PBKDF2_ITERATIONS = 600000;

    private const AUTH_HASH_LABEL = 'avapeek-auth-v1';

    public function generateSalt(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function generateKey(): string
    {
        return random_bytes(32);
    }

    public function deriveKek(string $password, string $saltHex): string
    {
        return hash_pbkdf2('sha256', $password, (string) hex2bin($saltHex), self::PBKDF2_ITERATIONS, 32, true);
    }

    public function deriveAuthHash(string $kek): string
    {
        return hash_hmac('sha256', self::AUTH_HASH_LABEL, $kek);
    }

    /**
     * base64(iv || ciphertext || tag), the format of wrapDEK and wrapPrivateKey.
     */
    public function wrapWithKek(string $key, string $kek): string
    {
        $iv = random_bytes(12);

        return base64_encode($iv.$this->seal($key, $kek, $iv));
    }

    /**
     * @return array{encrypted_pek: string, iv_pek: string}
     */
    public function wrapPek(string $pek, string $dek): array
    {
        $iv = random_bytes(12);

        return [
            'encrypted_pek' => base64_encode($this->seal($pek, $dek, $iv)),
            'iv_pek' => base64_encode($iv),
        ];
    }

    /**
     * @return array{encrypted: string, iv: string} Raw ciphertext and a hex IV (encryptBuffer).
     */
    public function encryptBuffer(string $bytes, string $key): array
    {
        $iv = random_bytes(16);

        return ['encrypted' => $this->seal($bytes, $key, $iv), 'iv' => bin2hex($iv)];
    }

    /**
     * @return array{encrypted: string, iv: string} Base64 ciphertext and a hex IV (encryptString).
     */
    public function encryptString(string $text, string $key): array
    {
        $result = $this->encryptBuffer($text, $key);

        return ['encrypted' => base64_encode($result['encrypted']), 'iv' => $result['iv']];
    }

    /**
     * An RSA-OAEP key pair as the browser exports it: the public key as a JWK string
     * and the private key as PKCS#8 DER.
     *
     * @return array{public_jwk: string, private_pkcs8: string}
     */
    public function generateKeyPair(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            throw new \RuntimeException('Could not generate an RSA key pair.');
        }

        openssl_pkey_export($key, $pem);
        $rsa = openssl_pkey_get_details($key)['rsa'];

        return [
            'public_jwk' => (string) json_encode([
                'alg' => 'RSA-OAEP-256',
                'e' => $this->base64Url($rsa['e']),
                'ext' => true,
                'key_ops' => ['wrapKey'],
                'kty' => 'RSA',
                'n' => $this->base64Url($rsa['n']),
            ]),
            'private_pkcs8' => (string) base64_decode(
                (string) preg_replace('/-----[^-]+-----|\s/', '', (string) $pem)
            ),
        ];
    }

    /**
     * AES-256-GCM, returning ciphertext || tag as Web Crypto does.
     */
    private function seal(string $plaintext, string $key, string $iv): string
    {
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ciphertext === false) {
            throw new \RuntimeException('Could not encrypt demo data.');
        }

        return $ciphertext.$tag;
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
