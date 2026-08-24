<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Pas\DocumentNumberSeries;
use DomainException;

/**
 * Thrown when a controlled document number cannot be issued.
 *
 * Distinct from a generic failure because every case here is something an
 * operator or an accountant has to act on before any more documents can be
 * raised — a missing series, an inactive one, or an exhausted ATP range.
 */
final class DocumentNumberUnavailableException extends DomainException
{
    public static function noSeries(string $documentType): self
    {
        return new self(sprintf(
            "This school has no numbering series for '%s'. Configure one — with its BIR Authority To Print details, if the document is a controlled form — before issuing any.",
            $documentType,
        ));
    }

    public static function inactive(DocumentNumberSeries $series): self
    {
        return new self(sprintf(
            "The '%s' numbering series is inactive. Reactivate it, or configure the series that replaces it.",
            $series->label,
        ));
    }

    /**
     * The ATP range is used up.
     *
     * Issuing past the end would put numbers on real documents that the
     * Bureau never authorised, so the refusal is hard rather than a warning.
     */
    public static function rangeExhausted(DocumentNumberSeries $series): self
    {
        return new self(sprintf(
            "The '%s' series has used its authorised range (%s to %s). A new Authority To Print is needed before another %s can be issued.",
            $series->label,
            $series->serial_start !== null ? $series->format($series->serial_start) : '—',
            $series->serial_end !== null ? $series->format($series->serial_end) : '—',
            str_replace('_', ' ', $series->document_type),
        ));
    }
}
