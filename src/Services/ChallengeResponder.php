<?php
declare(strict_types=1);

namespace App\Services;

final class ChallengeResponder
{
    public function compute(string $challengeCode, string $verificationToken, string $endpoint): string
    {
        $hash = hash_init('sha256');
        hash_update($hash, $challengeCode);
        hash_update($hash, $verificationToken);
        hash_update($hash, $endpoint);
        return hash_final($hash);
    }
}
