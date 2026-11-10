<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use JsonException;

final class TaskBriefReviewService
{
    private const REVIEW_TTL_MINUTES = 10;

    /**
     * @param  array<string, mixed>  $taskBrief
     */
    public function requiresReview(array $taskBrief): bool
    {
        return ($taskBrief['risk']['level'] ?? 'high') !== 'low'
            || ($taskBrief['complexity']['level'] ?? 'complex') !== 'simple';
    }

    /**
     * @param  array<string, mixed>  $taskBrief
     */
    public function issueToken(User $user, string $prompt, array $taskBrief): ?string
    {
        if (! $this->requiresReview($taskBrief)) {
            return null;
        }

        return Crypt::encryptString(json_encode([
            'user_id' => $user->getKey(),
            'prompt_hash' => hash('sha256', $prompt),
            'brief_hash' => $this->briefHash($taskBrief),
            'expires_at' => now()->addMinutes(self::REVIEW_TTL_MINUTES)->getTimestamp(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $taskBrief
     */
    public function assertReviewed(
        User $user,
        string $prompt,
        array $taskBrief,
        ?string $token,
    ): void {
        if (! $this->requiresReview($taskBrief)) {
            return;
        }

        if (! is_string($token) || $token === '') {
            throw new InvalidArgumentException(
                'This task requires a current TaskBrief review. Run the task estimate again before submitting.',
            );
        }

        try {
            $claims = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException $exception) {
            throw new InvalidArgumentException(
                'The TaskBrief review is invalid or expired. Run the task estimate again before submitting.',
                0,
                $exception,
            );
        }

        if (
            ! is_array($claims)
            || ($claims['user_id'] ?? null) !== $user->getKey()
            || ! is_string($claims['prompt_hash'] ?? null)
            || ! hash_equals(hash('sha256', $prompt), $claims['prompt_hash'])
            || ! is_string($claims['brief_hash'] ?? null)
            || ! hash_equals($this->briefHash($taskBrief), $claims['brief_hash'])
            || ! is_int($claims['expires_at'] ?? null)
            || $claims['expires_at'] <= now()->getTimestamp()
        ) {
            throw new InvalidArgumentException(
                'The TaskBrief review is invalid, expired, or no longer matches this task. Review a new estimate before submitting.',
            );
        }
    }

    private function briefHash(array $taskBrief): string
    {
        return hash('sha256', json_encode($taskBrief, JSON_THROW_ON_ERROR));
    }
}
