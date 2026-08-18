<?php

namespace App\Services\Contacts;

use Illuminate\Http\UploadedFile;

/**
 * Turns an uploaded CSV into rows, and guesses which columns hold what.
 *
 * Kept apart from the importer because parsing a spreadsheet somebody exported
 * from a phone is a problem in its own right, and it is the part that will keep
 * needing fixes. The importer only ever sees clean rows.
 */
class ContactCsvParser
{
    /** Hard ceiling on rows read from one file. */
    public const MAX_ROWS = 50000;

    /** Header names that look like a person's name, best guess first. */
    private const NAME_HINTS = ['name', 'full name', 'fullname', 'contact name', 'customer name', 'first name', 'firstname', 'client'];

    /** Header names that look like a phone number. */
    private const PHONE_HINTS = ['phone', 'phone number', 'phonenumber', 'mobile', 'mobile number', 'number', 'msisdn', 'contact', 'tel', 'telephone', 'cell'];

    /**
     * Read the whole file.
     *
     * @return array{
     *     headers: array<int, string>,
     *     rows: array<int, array<int, string>>,
     *     has_header: bool,
     *     name_column: int|null,
     *     phone_column: int|null,
     *     truncated: bool
     * }
     */
    public function parse(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            return $this->empty();
        }

        $delimiter = $this->detectDelimiter($file->getRealPath());

        $rows = [];
        $truncated = false;

        // The escape character is passed explicitly and empty. PHP 8.4 deprecates
        // relying on the default, and empty is the RFC 4180 behaviour anyway —
        // a backslash in a CSV cell is a backslash, not an escape. Leaving it at
        // the default would make a name like "Kofi \"KB\" Boateng" parse
        // differently from how every spreadsheet wrote it.
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            // fgetcsv hands back [null] for a blank line rather than skipping it.
            if ($row === [null] || $row === []) {
                continue;
            }

            $row = array_map(fn ($cell) => trim((string) $cell), $row);

            if (implode('', $row) === '') {
                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                $truncated = true;
                break;
            }

            $rows[] = $row;
        }

        fclose($handle);

        if ($rows === []) {
            return $this->empty();
        }

        // Excel and Google Sheets both write a UTF-8 BOM, which lands invisibly
        // on the first header cell and makes "Name" not equal "Name".
        $rows[0][0] = preg_replace('/^\x{FEFF}/u', '', $rows[0][0]) ?? $rows[0][0];

        $hasHeader = $this->looksLikeHeader($rows[0]);
        $headers = $hasHeader
            ? array_shift($rows)
            : $this->positionalHeaders($rows[0]);

        [$nameColumn, $phoneColumn] = $hasHeader
            ? $this->matchColumns($headers, $rows)
            : $this->guessColumnsByContent($rows);

        return [
            'headers' => $headers,
            'rows' => $rows,
            'has_header' => $hasHeader,
            'name_column' => $nameColumn,
            'phone_column' => $phoneColumn,
            'truncated' => $truncated,
        ];
    }

    /**
     * Comma, semicolon or tab.
     *
     * Semicolon is not exotic — it is what Excel writes on any machine with a
     * European locale, and a semicolon file read as comma-separated parses as one
     * enormous column, which looks to the operator like the file being rejected
     * for no reason.
     */
    private function detectDelimiter(string $path): string
    {
        $sample = (string) file_get_contents($path, false, null, 0, 8192);

        $counts = [
            ',' => substr_count($sample, ','),
            ';' => substr_count($sample, ';'),
            "\t" => substr_count($sample, "\t"),
        ];

        arsort($counts);

        return max($counts) === 0 ? ',' : (string) array_key_first($counts);
    }

    /**
     * Whether the first row names the columns or is already data.
     *
     * Decided by asking whether the row contains a usable phone number: a header
     * row does not, and a data row almost always does. Matching against known
     * header words would miss "Mobile No." and every other spelling; this test
     * does not care what the columns are called.
     */
    private function looksLikeHeader(array $row): bool
    {
        foreach ($row as $cell) {
            if (PhoneNormaliser::normalise($cell) !== null) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, string> */
    private function positionalHeaders(array $firstRow): array
    {
        return array_map(fn (int $i) => 'Column '.($i + 1), array_keys($firstRow));
    }

    /**
     * Match header names against the hints, then fall back to reading the data.
     *
     * The fallback matters: a file whose phone column is headed "MSISDN 233" or
     * "Cell no." is common, and refusing to guess would put the operator in front
     * of a column picker with nothing pre-selected.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function matchColumns(array $headers, array $rows): array
    {
        $phoneColumn = $this->matchHeader($headers, self::PHONE_HINTS);
        $nameColumn = $this->matchHeader($headers, self::NAME_HINTS);

        if ($phoneColumn === null || $nameColumn === null) {
            [$guessedName, $guessedPhone] = $this->guessColumnsByContent($rows);

            $phoneColumn ??= $guessedPhone;
            $nameColumn ??= $guessedName;
        }

        // A single column matching both hint lists is a phone column. "Contact"
        // appears in both lists and is far more often a number than a name.
        if ($nameColumn !== null && $nameColumn === $phoneColumn) {
            $nameColumn = null;
        }

        return [$nameColumn, $phoneColumn];
    }

    private function matchHeader(array $headers, array $hints): ?int
    {
        foreach ($hints as $hint) {
            foreach ($headers as $index => $header) {
                if (strtolower(trim($header)) === $hint) {
                    return (int) $index;
                }
            }
        }

        // Nothing matched exactly, so accept a header that contains a hint —
        // "Mobile Number (Ghana)" should still find the phone column.
        foreach ($hints as $hint) {
            foreach ($headers as $index => $header) {
                if (str_contains(strtolower(trim($header)), $hint)) {
                    return (int) $index;
                }
            }
        }

        return null;
    }

    /**
     * Pick columns by what the data looks like.
     *
     * The phone column is whichever holds the most parseable numbers. The name
     * column is the leftmost that holds mostly non-numeric text — names sit left
     * of numbers in essentially every list anybody exports.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function guessColumnsByContent(array $rows): array
    {
        $sample = array_slice($rows, 0, 50);

        if ($sample === []) {
            return [null, null];
        }

        $width = max(array_map('count', $sample));
        $phoneHits = array_fill(0, $width, 0);
        $textHits = array_fill(0, $width, 0);

        foreach ($sample as $row) {
            for ($i = 0; $i < $width; $i++) {
                $cell = trim((string) ($row[$i] ?? ''));

                if ($cell === '') {
                    continue;
                }

                if (PhoneNormaliser::normalise($cell) !== null) {
                    $phoneHits[$i]++;
                } elseif (preg_match('/\p{L}/u', $cell)) {
                    $textHits[$i]++;
                }
            }
        }

        $phoneColumn = max($phoneHits) > 0 ? (int) array_search(max($phoneHits), $phoneHits, true) : null;

        $nameColumn = null;
        foreach ($textHits as $i => $hits) {
            if ($i !== $phoneColumn && $hits > count($sample) / 2) {
                $nameColumn = (int) $i;
                break;
            }
        }

        return [$nameColumn, $phoneColumn];
    }

    private function empty(): array
    {
        return [
            'headers' => [],
            'rows' => [],
            'has_header' => false,
            'name_column' => null,
            'phone_column' => null,
            'truncated' => false,
        ];
    }
}
