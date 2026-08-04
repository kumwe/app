<?php

declare(strict_types=1);

namespace Kumwe\CMS\Content\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\CMS\Workflow\Domain\Workflow;

final readonly class ContentEntry
{
    /** @var array<string, mixed> */
    private array $data;

    /**
     * @param array<array-key, mixed> $data
     */
    private function __construct(
        private string $id,
        private string $title,
        private string $slug,
        array $data,
        private ContentStatus $status,
        private PublicationWindow $publicationWindow,
        private int $version,
    ) {
        self::assertUuid($id);
        self::assertTitle($title);
        self::assertSlug($slug);
        self::assertData($data);

        /** @var array<string, mixed> $data */
        $this->data = $data;

        if ($version < 1) {
            throw new InvalidArgumentException('A content entry version must be at least one.');
        }
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function create(
        string $id,
        string $title,
        string $slug,
        array $data = [],
        ContentStatus $status = ContentStatus::Draft,
        ?PublicationWindow $publicationWindow = null,
    ): self {
        return new self(
            strtolower($id),
            trim($title),
            $slug,
            $data,
            $status,
            $publicationWindow ?? PublicationWindow::unbounded(),
            1,
        );
    }

    /**
     * Rebuild an entry loaded from trusted persistence while preserving all
     * domain validation performed by the constructor.
     *
     * @param array<array-key, mixed> $data
     */
    public static function reconstitute(
        string $id,
        string $title,
        string $slug,
        array $data,
        ContentStatus $status,
        PublicationWindow $publicationWindow,
        int $version,
    ): self {
        return new self(
            strtolower($id),
            trim($title),
            $slug,
            $data,
            $status,
            $publicationWindow,
            $version,
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    public function status(): ContentStatus
    {
        return $this->status;
    }

    public function publicationWindow(): PublicationWindow
    {
        return $this->publicationWindow;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function isVisibleAt(DateTimeImmutable $instant): bool
    {
        return $this->status->isPublic() && $this->publicationWindow->contains($instant);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public function revise(
        ExpectedVersion $expectedVersion,
        string $title,
        string $slug,
        array $data,
        ?PublicationWindow $publicationWindow = null,
    ): self
    {
        $expectedVersion->assertMatches($this->version);

        return new self(
            $this->id,
            trim($title),
            $slug,
            $data,
            $this->status,
            $publicationWindow ?? $this->publicationWindow,
            $this->version + 1,
        );
    }

    public function reschedule(ExpectedVersion $expectedVersion, PublicationWindow $publicationWindow): self
    {
        $expectedVersion->assertMatches($this->version);

        return new self(
            $this->id,
            $this->title,
            $this->slug,
            $this->data,
            $this->status,
            $publicationWindow,
            $this->version + 1,
        );
    }

    public function transition(
        ExpectedVersion $expectedVersion,
        Workflow $workflow,
        ContentStatus $target,
    ): self {
        $expectedVersion->assertMatches($this->version);
        $workflow->assertCanTransition($this->status, $target);

        return new self(
            $this->id,
            $this->title,
            $this->slug,
            $this->data,
            $target,
            $this->publicationWindow,
            $this->version + 1,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'data' => $this->data,
            'status' => $this->status->value,
            'publication_window' => [
                'starts_at' => $this->publicationWindow->startsAt()?->format(DATE_ATOM),
                'ends_at' => $this->publicationWindow->endsAt()?->format(DATE_ATOM),
            ],
            'version' => $this->version,
        ];
    }

    private static function assertUuid(string $id): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $id) !== 1) {
            throw new InvalidArgumentException('A content entry ID must be a canonical UUID.');
        }
    }

    private static function assertTitle(string $title): void
    {
        $length = mb_strlen(trim($title));

        if ($length < 1 || $length > 255) {
            throw new InvalidArgumentException('A content title must contain between 1 and 255 characters.');
        }
    }

    private static function assertSlug(string $slug): void
    {
        if (
            mb_strlen($slug) > 160
            || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1
        ) {
            throw new InvalidArgumentException(
                'A slug must contain lowercase ASCII letters, digits, and single hyphens.',
            );
        }
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function assertData(array $data): void
    {
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Top-level content data keys must be strings.');
            }

            self::assertJsonValue($value);
        }
    }

    private static function assertJsonValue(mixed $value): void
    {
        if ($value === null || is_int($value) || is_bool($value)) {
            return;
        }

        if (is_string($value)) {
            if (!mb_check_encoding($value, 'UTF-8')) {
                throw new InvalidArgumentException('Content strings must contain valid UTF-8.');
            }

            return;
        }

        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new InvalidArgumentException('Content numbers must be finite.');
            }

            return;
        }

        if (!is_array($value)) {
            throw new InvalidArgumentException('Content data must contain only JSON-compatible values.');
        }

        foreach ($value as $nestedValue) {
            self::assertJsonValue($nestedValue);
        }
    }
}
