<?php

namespace App\Support\Statements;

/**
 * Knows how one provider's statement export is laid out.
 *
 * FloatBook has no live connection to any provider. Today every adapter reads files the
 * business downloads from its provider dashboard. A future adapter could pull the same
 * rows from a provider API instead, and the matching and import code would not change.
 */
interface StatementAdapter
{
    /** The fields FloatBook understands, in display order. */
    public const FIELDS = ['date', 'amount', 'reference', 'type', 'status'];

    /** Fields a mapping must have before rows can be read. */
    public const REQUIRED = ['date', 'amount'];

    /**
     * Guess which column holds each field, from the file's header row.
     *
     * @param  list<string>  $headers
     * @return array<string, string|null> field => header (or null when not found)
     */
    public function suggestMapping(array $headers): array;
}
