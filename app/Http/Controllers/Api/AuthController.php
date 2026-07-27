<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function google(Request $request)
    {
        $token = $request->validate(['id_token' => ['required', 'string']])['id_token'];
        $account = $this->verifyGoogleIdToken($token);

        return $this->authenticate(
            provider: 'google',
            providerId: $account['sub'] ?? null,
            email: $account['email'] ?? null,
            name: $account['name'] ?? null,
        );
    }

    public function apple(Request $request)
    {
        $validated = $request->validate([
            'identity_token' => ['required', 'string'],
            'full_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $account = $this->verifyAppleIdentityToken($validated['identity_token']);

        return $this->authenticate(
            provider: 'apple',
            providerId: $account['sub'] ?? null,
            email: $account['email'] ?? null,
            name: $validated['full_name'] ?? null,
        );
    }

    public function me(Request $request)
    {
        return response()->json(['user' => new UserResource($request->user())]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'jobTitle' => ['sometimes', 'string', 'max:255'],
        ]);
        if (isset($validated['jobTitle'])) {
            $request->user()->update(['job_title' => trim($validated['jobTitle'])]);
        }

        return response()->json(['user' => new UserResource($request->user()->refresh())]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    private function authenticate(string $provider, mixed $providerId, mixed $email, mixed $name)
    {
        $tokenField = "{$provider}_id";
        if (! is_string($providerId) || $providerId === '') {
            throw ValidationException::withMessages([
                'token' => ["{$provider} did not return a valid account ID."],
            ]);
        }

        $query = User::query()->where($tokenField, $providerId);
        if (is_string($email) && $email !== '') {
            $query->orWhere('email', $email);
        }

        $user = $query->first();
        $isNewUser = $user === null;

        if ($user?->{$tokenField} && $user->{$tokenField} !== $providerId) {
            throw ValidationException::withMessages([
                'token' => ['This email is linked to another social account.'],
            ]);
        }

        if ($user) {
            $user->forceFill([$tokenField => $providerId])->save();
        } else {
            if (! is_string($email) || $email === '') {
                throw ValidationException::withMessages([
                    'token' => ["{$provider} did not return an email for this new account."],
                ]);
            }

            $user = User::query()->create([
                'name' => trim((string) $name) ?: Str::before($email, '@'),
                'email' => $email,
                $tokenField => $providerId,
            ]);
        }

        return response()->json([
            'token' => $user->createToken('mobile')->plainTextToken,
            'user' => new UserResource($user->refresh()),
            'is_new_user' => $isNewUser,
        ]);
    }

    private function verifyGoogleIdToken(string $idToken): array
    {
        $clientIds = config('services.google.client_ids', []);
        if ($clientIds === []) {
            throw ValidationException::withMessages(['id_token' => ['Google login is not configured.']]);
        }

        $response = Http::get('https://oauth2.googleapis.com/tokeninfo', ['id_token' => $idToken]);
        $payload = $response->json();

        if (! $response->ok()
            || ! in_array($payload['aud'] ?? null, $clientIds, true)
            || ! in_array($payload['email_verified'] ?? null, [true, 'true'], true)
            || ($payload['exp'] ?? 0) < time()) {
            throw ValidationException::withMessages(['id_token' => ['Invalid or expired Google token.']]);
        }

        return $payload;
    }

    private function verifyAppleIdentityToken(string $identityToken): array
    {
        $clientIds = config('services.apple.client_ids', []);
        $parts = explode('.', $identityToken);
        if ($clientIds === [] || count($parts) !== 3) {
            throw ValidationException::withMessages(['identity_token' => ['Apple login is not configured or the token is invalid.']]);
        }

        $header = json_decode($this->base64UrlDecode($parts[0]), true);
        $payload = json_decode($this->base64UrlDecode($parts[1]), true);
        $signature = $this->base64UrlDecode($parts[2]);

        if (! is_array($header) || ! is_array($payload) || ($header['alg'] ?? null) !== 'RS256') {
            throw ValidationException::withMessages(['identity_token' => ['Invalid Apple token.']]);
        }

        $keys = Http::get('https://appleid.apple.com/auth/keys');
        $key = $keys->ok()
            ? collect($keys->json('keys', []))->first(
                fn (array $candidate) => ($candidate['kid'] ?? null) === ($header['kid'] ?? null)
            )
            : null;

        $valid = $key
            && $this->verifyJwtSignature("{$parts[0]}.{$parts[1]}", $signature, $key)
            && ($payload['iss'] ?? null) === 'https://appleid.apple.com'
            && in_array($payload['aud'] ?? null, $clientIds, true)
            && ($payload['exp'] ?? 0) >= time();

        if (! $valid) {
            throw ValidationException::withMessages(['identity_token' => ['Invalid or expired Apple token.']]);
        }

        return $payload;
    }

    private function verifyJwtSignature(string $payload, string $signature, array $jwk): bool
    {
        $pem = $this->jwkToPem($jwk);

        return $pem !== null && openssl_verify($payload, $signature, $pem, OPENSSL_ALGO_SHA256) === 1;
    }

    private function jwkToPem(array $jwk): ?string
    {
        if (($jwk['kty'] ?? null) !== 'RSA' || empty($jwk['n']) || empty($jwk['e'])) {
            return null;
        }

        $rsa = $this->asn1Sequence([
            $this->asn1Integer($this->base64UrlDecode($jwk['n'])),
            $this->asn1Integer($this->base64UrlDecode($jwk['e'])),
        ]);
        $publicKey = $this->asn1Sequence([
            $this->asn1Sequence([
                $this->asn1ObjectIdentifier('1.2.840.113549.1.1.1'),
                "\x05\x00",
            ]),
            $this->asn1BitString($rsa),
        ]);

        return "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($publicKey), 64, "\n")
            ."-----END PUBLIC KEY-----\n";
    }

    private function base64UrlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4)) ?: '';
    }

    private function asn1Length(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }
        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xFF).$bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    private function asn1Integer(string $value): string
    {
        $value = ltrim($value, "\x00");
        if ($value === '' || (ord($value[0]) & 0x80)) {
            $value = "\x00".$value;
        }

        return "\x02".$this->asn1Length(strlen($value)).$value;
    }

    private function asn1Sequence(array $items): string
    {
        $value = implode('', $items);

        return "\x30".$this->asn1Length(strlen($value)).$value;
    }

    private function asn1BitString(string $value): string
    {
        $value = "\x00".$value;

        return "\x03".$this->asn1Length(strlen($value)).$value;
    }

    private function asn1ObjectIdentifier(string $oid): string
    {
        $parts = array_map('intval', explode('.', $oid));
        $value = chr($parts[0] * 40 + $parts[1]);
        foreach (array_slice($parts, 2) as $part) {
            $encoded = chr($part & 0x7F);
            $part >>= 7;
            while ($part > 0) {
                $encoded = chr(0x80 | ($part & 0x7F)).$encoded;
                $part >>= 7;
            }
            $value .= $encoded;
        }

        return "\x06".$this->asn1Length(strlen($value)).$value;
    }
}
