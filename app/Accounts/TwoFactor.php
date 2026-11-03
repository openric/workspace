<?php

// OpenRiC Workspace - two-factor sign-in with time-based one-time passwords (roadmap phase 12b, ADR 0021), after
// Heratio's ahg-security-clearance TotpService and MfaPolicyService: a secret set up from a QR code and confirmed with
// a code, ten recovery codes each good once, and the institution's policy of who must have it, with days of grace.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Changed from Heratio: the codes are RFC 6238 (TOTP: HMAC-SHA1, 30-second steps, six digits) worked here rather
// than through Google2FA; a code is never accepted twice, as RFC 6238 5.2 asks; recovery codes are kept only as
// SHA-256 hashes; and a sign-in through the institution's own identity provider is left to that provider's second
// factor. WebAuthn and codes by email are not carried in 12b.

namespace App\Accounts;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\User;
use App\Support\Vocab;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use InvalidArgumentException;
use ParagonIE\ConstantTime\Base32;

class TwoFactor
{
    public const STEP = 30;

    public const DIGITS = 6;

    /** Codes of the step before and after are accepted too, for clocks that drift. */
    public const WINDOW = 1;

    /** The permissions that make an account an administrator for the policy. */
    public const ADMINISTRATION = ['manage-institution', 'manage-accounts', 'manage-roles'];

    /** The code of a secret at a time step (RFC 6238, RFC 4226 5.3). */
    public static function code(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), Base32::decodeUpper($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($binary % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** Begin: a new secret, not in force until a code from it is confirmed. @return array{secret: string, uri: string, qr: string} */
    public function begin(User $user): array
    {
        $secret = Base32::encodeUpperUnpadded(random_bytes(20));
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null, 'two_factor_last_step' => null])->save();
        $issuer = $user->institution->name.' (OpenRiC Workspace)';
        $uri = 'otpauth://totp/'.rawurlencode($issuer.':'.$user->email).'?'.http_build_query(['secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => self::DIGITS, 'period' => self::STEP]);
        $svg = (new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd)))->writeString($uri);

        return ['secret' => $secret, 'uri' => $uri, 'qr' => 'data:image/svg+xml;base64,'.base64_encode($svg)];
    }

    /** Confirm with a code from the app: two-factor is then in force. @return list<string> the recovery codes, shown once */
    public function confirm(User $user, string $code): array
    {
        $user->two_factor_secret !== null || throw new InvalidArgumentException(__('Set up two-factor sign-in first.'));
        $this->totp($user, $code) || throw new InvalidArgumentException(__('That code is not right. Check the time on your phone and try the newest code.'));
        $codes = $this->newRecoveryCodes();
        AuditLog::actingAs($user, fn () => $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => array_map(fn ($c) => hash('sha256', $c), $codes)])->save());

        return $codes;
    }

    public function enabled(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null && $user->two_factor_secret !== null;
    }

    /** A code from the app, or a recovery code (used up), for an account that has two-factor in force. */
    public function verify(User $user, string $code): bool
    {
        if (! $this->enabled($user)) {
            return false;
        }
        if ($this->totp($user, $code)) {
            return true;
        }
        $hash = hash('sha256', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code)));
        $codes = $user->two_factor_recovery_codes ?? [];
        if (! in_array($hash, $codes, true)) {
            return false;
        }
        $user->forceFill(['two_factor_recovery_codes' => array_values(array_diff($codes, [$hash]))])->save();

        return true;
    }

    /** @return list<string> new recovery codes, the old ones no longer good */
    public function regenerate(User $user): array
    {
        $this->enabled($user) || throw new InvalidArgumentException(__('Two-factor sign-in is not on.'));
        $codes = $this->newRecoveryCodes();
        AuditLog::actingAs($user, fn () => $user->forceFill(['two_factor_recovery_codes' => array_map(fn ($c) => hash('sha256', $c), $codes)])->save());

        return $codes;
    }

    /** Turn it off: by the account with a current code, or by an administrator for an account that lost its phone. */
    public function disable(User $user, User $actor): void
    {
        AuditLog::actingAs($actor, fn () => $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null, 'two_factor_last_step' => null])->save());
    }

    /** Must this account have two-factor sign-in, under its institution's policy? */
    public function required(User $user): bool
    {
        return match ($this->policy($user->institution)) {
            'required' => true,
            'required-for-administrators' => collect(self::ADMINISTRATION)->contains(fn ($action) => $user->may($action, 'institution')),
            default => false,
        };
    }

    /** Required, not set up, and the days of grace are over: the account sets it up before anything else. */
    public function overdue(User $user): bool
    {
        if ($this->enabled($user) || ! $this->required($user)) {
            return false;
        }
        $since = max($user->created_at?->getTimestamp() ?? 0, $user->institution->two_factor_policy_since?->getTimestamp() ?? 0);

        return now()->getTimestamp() > $since + $user->institution->two_factor_grace_days * 86400;
    }

    public function policy(Institution $institution): string
    {
        return $institution->twoFactorPolicy?->notation ?? 'optional';
    }

    public function setPolicy(Institution $institution, string $notation, int $graceDays, User $actor): void
    {
        $concept = Concept::choices(Vocab::BASE.'two-factor-policy/')->where('notation', $notation)->first() ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
        ($graceDays >= 0 && $graceDays <= 365) || throw new InvalidArgumentException(__('The grace period is from 0 to 365 days.'));
        AuditLog::actingAs($actor, fn () => $institution->update(['two_factor_policy_id' => $concept->id, 'two_factor_grace_days' => $graceDays]
            + ($institution->two_factor_policy_id !== $concept->id ? ['two_factor_policy_since' => now()] : [])));
    }

    /** A code of the current step or one either side, not of a step already used. */
    private function totp(User $user, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== self::DIGITS || $user->two_factor_secret === null) {
            return false;
        }
        $now = intdiv(now()->getTimestamp(), self::STEP);
        for ($step = $now - self::WINDOW; $step <= $now + self::WINDOW; $step++) {
            if ($step > (int) $user->two_factor_last_step && hash_equals(self::code($user->two_factor_secret, $step), $code)) {
                $user->forceFill(['two_factor_last_step' => $step])->save();

                return true;
            }
        }

        return false;
    }

    /** @return list<string> ten codes of ten characters, as Heratio gives them */
    private function newRecoveryCodes(): array
    {
        return array_map(fn () => strtoupper(substr(Base32::encodeUpperUnpadded(random_bytes(10)), 0, 10)), range(1, 10));
    }
}
