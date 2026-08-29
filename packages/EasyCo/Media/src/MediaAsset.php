<?php

namespace EasyCo\Media;

use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\Enums\ProcessingStatus;
use EasyCo\Media\Exceptions\InvalidMediaStateTransitionException;
use InvalidArgumentException;
use LogicException;

/**
 * One uploaded image or video — see media-domain-design.md §8 for the
 * full entity sketch. Owns the image-processing lifecycle
 * (PENDING -> PROCESSING -> READY/FAILED) for IMAGE/SOCIAL_IMAGE
 * assets; VIDEO/SOCIAL_VIDEO assets never enter that lifecycle at all
 * (§4 — no processing pipeline for video in v1), which is why every
 * guarded transition method on this class rejects them outright,
 * regardless of their current processingStatus.
 */
final class MediaAsset
{
    private function __construct(
        private ?string $id,
        private readonly MediaType $type,
        private readonly string $disk,
        private readonly string $path,
        private ?string $altText,
        private ProcessingStatus $processingStatus,
        private ?string $processingFailureReason,
        private array $variants,
    ) {
        if ($path === '') {
            throw new InvalidArgumentException('MediaAsset path must not be empty.');
        }
    }

    /**
     * processingStatus branches by type, not a caller-supplied value —
     * see media-domain-design.md §4/§8: IMAGE/SOCIAL_IMAGE go through
     * the WebP processing pipeline and so start PENDING (a queued job
     * picks them up later); VIDEO/SOCIAL_VIDEO have no pipeline to run
     * at all (§4, a deliberate v1 scope decision, not a gap) and so
     * start — and permanently stay, from this class's own perspective —
     * READY. variants is always empty at creation either way; only
     * markReady() ever populates it, and only for IMAGE/SOCIAL_IMAGE.
     */
    public static function create(MediaType $type, string $disk, string $path, ?string $altText = null): self
    {
        $processingStatus = match ($type) {
            MediaType::IMAGE, MediaType::SOCIAL_IMAGE => ProcessingStatus::PENDING,
            MediaType::VIDEO, MediaType::SOCIAL_VIDEO => ProcessingStatus::READY,
        };

        return new self(
            id: null,
            type: $type,
            disk: $disk,
            path: $path,
            altText: $altText,
            processingStatus: $processingStatus,
            processingFailureReason: null,
            variants: [],
        );
    }

    /**
     * Reconstitutes a MediaAsset exactly as it exists in storage.
     *
     * PERSISTENCE-LAYER ONLY — trusts the caller (a repository) that the
     * given data is already-valid data read back from storage. This
     * method is not a business operation and application code must never
     * call it directly; only a repository implementation reconstructing
     * this entity from an already-validated row should call it.
     *
     * @param MediaVariant[] $variants
     */
    public static function reconstituteFromStorage(
        string $id,
        MediaType $type,
        string $disk,
        string $path,
        ?string $altText,
        ProcessingStatus $processingStatus,
        ?string $processingFailureReason,
        array $variants,
    ): self {
        return new self(
            id: $id,
            type: $type,
            disk: $disk,
            path: $path,
            altText: $altText,
            processingStatus: $processingStatus,
            processingFailureReason: $processingFailureReason,
            variants: $variants,
        );
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new LogicException('MediaAsset already has an id; assignId() is a one-time operation.');
        }

        $this->id = $id;
    }

    public function type(): MediaType
    {
        return $this->type;
    }

    public function disk(): string
    {
        return $this->disk;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function altText(): ?string
    {
        return $this->altText;
    }

    public function updateAltText(?string $altText): void
    {
        $this->altText = $altText;
    }

    public function processingStatus(): ProcessingStatus
    {
        return $this->processingStatus;
    }

    public function processingFailureReason(): ?string
    {
        return $this->processingFailureReason;
    }

    /** @return MediaVariant[] */
    public function variants(): array
    {
        return $this->variants;
    }

    public function isReady(): bool
    {
        return $this->processingStatus === ProcessingStatus::READY;
    }

    public function markProcessing(): void
    {
        $this->assertNotVideo('markProcessing');

        if ($this->processingStatus !== ProcessingStatus::PENDING) {
            throw InvalidMediaStateTransitionException::forAsset(
                $this->id ?? '(unassigned)',
                $this->processingStatus,
                'markProcessing'
            );
        }

        $this->processingStatus = ProcessingStatus::PROCESSING;
    }

    /** @param MediaVariant[] $variants */
    public function markReady(array $variants): void
    {
        $this->assertNotVideo('markReady');

        if ($this->processingStatus !== ProcessingStatus::PROCESSING) {
            throw InvalidMediaStateTransitionException::forAsset(
                $this->id ?? '(unassigned)',
                $this->processingStatus,
                'markReady'
            );
        }

        $this->processingStatus = ProcessingStatus::READY;
        $this->variants = $variants;
        // Clears a previous failure reason, if this is a retry after
        // markFailed() — a successful run leaves no stale reason behind.
        $this->processingFailureReason = null;
    }

    public function markFailed(string $reason): void
    {
        $this->assertNotVideo('markFailed');

        if ($this->processingStatus !== ProcessingStatus::PROCESSING) {
            throw InvalidMediaStateTransitionException::forAsset(
                $this->id ?? '(unassigned)',
                $this->processingStatus,
                'markFailed'
            );
        }

        $this->processingStatus = ProcessingStatus::FAILED;
        $this->processingFailureReason = $reason;
        // variants is deliberately untouched — a failed (re)processing
        // run must not wipe out variants from a still-valid prior READY
        // state.
    }

    /**
     * VIDEO/SOCIAL_VIDEO assets never go through PENDING/PROCESSING —
     * they start (and stay) READY (§4/§8) — so every guarded transition
     * method must reject them outright, before even checking
     * processingStatus, rather than relying on the status guard alone
     * to happen to also catch this case.
     */
    private function assertNotVideo(string $attemptedTransition): void
    {
        if ($this->type === MediaType::VIDEO || $this->type === MediaType::SOCIAL_VIDEO) {
            throw InvalidMediaStateTransitionException::forAsset(
                $this->id ?? '(unassigned)',
                $this->processingStatus,
                $attemptedTransition
            );
        }
    }
}
