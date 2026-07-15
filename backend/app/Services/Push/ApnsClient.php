<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ApnsClient
{
    public function send(PushSubscription $subscription, array $payload): void
    {
        if (! config('push.apns.enabled')) {
            return;
        }

        $token = $this->jwt();
        $bundleId = (string) config('push.apns.bundle_id');
        $host = config('push.apns.production')
            ? 'https://api.push.apple.com'
            : 'https://api.sandbox.push.apple.com';

        $response = Http::withToken($token)
            ->withHeaders([
                'apns-topic' => $bundleId,
                'apns-push-type' => 'alert',
                'apns-priority' => '10',
            ])
            ->post($host.'/3/device/'.$subscription->token, $payload);

        if ($response->successful()) {
            return;
        }

        if (in_array($response->status(), [400, 410], true)) {
            $reason = (string) ($response->json('reason') ?? '');
            if (in_array($reason, ['BadDeviceToken', 'DeviceTokenNotForTopic', 'Unregistered'], true)) {
                $subscription->delete();
            }
        }

        Log::warning('APNs push failed.', [
            'subscription_id' => $subscription->id,
            'status' => $response->status(),
            'reason' => $response->json('reason'),
        ]);
    }

    protected function jwt(): string
    {
        $teamId = config('push.apns.team_id');
        $keyId = config('push.apns.key_id');
        $privateKey = $this->privateKey();

        if (! $teamId || ! $keyId || ! $privateKey) {
            throw new RuntimeException('APNs credentials are not configured.');
        }

        $header = $this->base64UrlEncode(json_encode([
            'alg' => 'ES256',
            'kid' => $keyId,
        ], JSON_THROW_ON_ERROR));

        $claims = $this->base64UrlEncode(json_encode([
            'iss' => $teamId,
            'iat' => time(),
        ], JSON_THROW_ON_ERROR));

        $signature = '';
        $signed = openssl_sign($header.'.'.$claims, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        if (! $signed) {
            throw new RuntimeException('Could not sign APNs JWT.');
        }

        return $header.'.'.$claims.'.'.$this->base64UrlEncode($this->derSignatureToRaw($signature));
    }

    protected function privateKey(): ?string
    {
        $key = config('push.apns.private_key');
        if (! $key) {
            return null;
        }

        return str_replace('\\n', "\n", (string) $key);
    }

    protected function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    protected function derSignatureToRaw(string $signature): string
    {
        $offset = 2;

        if (ord($signature[$offset]) !== 0x02) {
            throw new RuntimeException('Invalid APNs JWT signature.');
        }

        $rLength = ord($signature[$offset + 1]);
        $r = substr($signature, $offset + 2, $rLength);
        $offset = $offset + 2 + $rLength;

        if (ord($signature[$offset]) !== 0x02) {
            throw new RuntimeException('Invalid APNs JWT signature.');
        }

        $sLength = ord($signature[$offset + 1]);
        $s = substr($signature, $offset + 2, $sLength);

        return str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT)
            .str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    }
}
