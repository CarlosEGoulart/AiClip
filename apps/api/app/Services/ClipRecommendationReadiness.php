<?php

namespace App\Services;

use App\Exceptions\ProcessMediaException;

/**
 * The seven M5 transcript states and their precedence.
 *
 * Pure: the caller passes the authoritative upstream facts it already read
 * under the claim, and this classifier decides the state without touching the
 * database, the worker or the filesystem.
 *
 * Precedence is fixed by the specification:
 *
 * 1. An authoritative no-audio probe wins over any stale transcript.
 * 2. A recorded audio extraction failure wins over any stale transcript.
 *    A generic asset failure caused by another stage is never read as an
 *    extraction failure: the caller must pass the recorded outcome only.
 * 3. Otherwise the transcript row decides, and a pending/transcribing row (or
 *    a missing row while upstream audio or transcription is still active) is
 *    not ready rather than missing.
 */
final class ClipRecommendationReadiness
{
    public const COMPLETED_VALID = 'completed_valid';

    public const COMPLETED_EMPTY = 'completed_empty';

    public const NO_AUDIO = 'no_audio';

    public const EXTRACTION_FAILED = 'extraction_failed';

    public const TRANSCRIPTION_FAILED = 'transcription_failed';

    public const MISSING = 'missing';

    public const NOT_READY = 'not_ready';

    public const NO_CANDIDATE_TEXT = 'no_candidate_text';

    /**
     * @var list<string>
     */
    public const STATES = [
        self::COMPLETED_VALID,
        self::COMPLETED_EMPTY,
        self::NO_AUDIO,
        self::EXTRACTION_FAILED,
        self::TRANSCRIPTION_FAILED,
        self::MISSING,
        self::NOT_READY,
    ];

    /**
     * Classify the transcript state from authoritative upstream facts.
     *
     * @param  bool  $hasAudioStream  The probe recorded an audio stream.
     * @param  bool  $extractionFailed  A recorded audio extraction failure, not an inferred one.
     * @param  string|null  $transcriptStatus  The persisted transcript status, or null when no row exists.
     * @param  bool  $upstreamActive  Upstream audio or transcription is still in flight.
     * @param  mixed  $transcriptSegments  Raw persisted segments, only inspected for a completed transcript.
     *
     * @throws ProcessMediaException invalid_input for a malformed completed transcript
     */
    public static function classify(
        bool $hasAudioStream,
        bool $extractionFailed,
        ?string $transcriptStatus,
        bool $upstreamActive,
        mixed $transcriptSegments,
        int $durationMs,
    ): string {
        // Authoritative probe outcome beats even a stale or malformed
        // completed transcript, so no segment is read on these paths.
        if (! $hasAudioStream) {
            return self::NO_AUDIO;
        }

        if ($extractionFailed) {
            return self::EXTRACTION_FAILED;
        }

        if ($transcriptStatus === null) {
            return $upstreamActive ? self::NOT_READY : self::MISSING;
        }

        if ($transcriptStatus === 'pending' || $transcriptStatus === 'transcribing') {
            return self::NOT_READY;
        }

        if ($transcriptStatus === 'failed') {
            return self::TRANSCRIPTION_FAILED;
        }

        if ($transcriptStatus !== 'completed') {
            throw new ProcessMediaException('invalid_input', 1, '');
        }

        // A completed transcript is validated as a whole, never reclassified
        // as missing or empty when it turns out to be malformed.
        $validated = ClipRecommendationProjection::validateSegments($transcriptSegments, $durationMs);

        return $validated === [] ? self::COMPLETED_EMPTY : self::COMPLETED_VALID;
    }

    /**
     * The recorded transcript state of a durable snapshot.
     *
     * An unavailable local outcome repeats the outcome reason; a completed
     * worker result records the classified state that produced it.
     */
    public static function snapshotState(string $state, ?string $unavailableReason = null): string
    {
        if (in_array($state, self::STATES, true)) {
            return $state;
        }

        if ($state === self::NO_CANDIDATE_TEXT) {
            return self::NO_CANDIDATE_TEXT;
        }

        return (string) $unavailableReason;
    }
}
