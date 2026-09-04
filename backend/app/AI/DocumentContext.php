<?php

namespace App\AI;

use App\Models\Document;

/**
 * The document facts handed to the provider. Metadata only — no file
 * contents are read or transmitted in this phase.
 */
final class DocumentContext
{
    public function __construct(
        public readonly string $title,
        public readonly ?string $documentType,
        public readonly ?string $reportingPeriod,
        public readonly ?string $keywords,
        public readonly ?string $description,
        public readonly ?string $currentCategory,
        public readonly ?string $accessLevel = null,
        public readonly ?string $documentDate = null,
    ) {}

    public static function fromDocument(Document $document): self
    {
        return new self(
            title: (string) $document->title,
            documentType: $document->document_type,
            reportingPeriod: $document->reporting_period,
            keywords: $document->keywords,
            description: $document->description,
            currentCategory: $document->category?->category_name,
            accessLevel: $document->access_level,
            documentDate: $document->document_date?->toDateString(),
        );
    }

    public function toPromptText(): string
    {
        return collect([
            'Title' => $this->title,
            'Stated document type' => $this->documentType,
            'Document date' => $this->documentDate,
            'Reporting period' => $this->reportingPeriod,
            'Keywords' => $this->keywords,
            'Description' => $this->description,
            'Current category' => $this->currentCategory,
            'Stated access level' => $this->accessLevel,
        ])->filter()->map(fn ($v, $k) => "{$k}: {$v}")->implode("\n");
    }
}
