<?php

namespace App\Support;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — NameNormalizer
//  Location: app/Support/NameNormalizer.php
//
//  One place that decides what "the same name" means for customers,
//  suppliers/employees and team members:
//
//    clean()  what gets SAVED — spaces at the start and end removed,
//             runs of spaces/tabs/line breaks inside the name
//             collapsed to one space, invisible characters (zero-
//             width space, byte-order mark, direction marks) dropped.
//             "  Cairo   Company " is saved as "Cairo Company".
//
//    key()    what gets COMPARED — clean() plus lower-casing, so
//             "cairo company" and "Cairo Company" are the same name.
//
//  Both are multibyte-safe, so Arabic names work exactly like English
//  ones.
// ══════════════════════════════════════════════════════════════════
final class NameNormalizer
{
    public static function clean(mixed $name): string
    {
        if (! is_string($name)) {
            return '';
        }

        // Invisible characters that make two identical-looking names
        // different: zero-width space, left/right-to-right marks and
        // embeddings, byte-order mark. (Zero-width joiners are kept —
        // some scripts need them.)
        $name = preg_replace('/[\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{FEFF}]/u', '', $name) ?? $name;

        // Any run of whitespace (space, tab, line break, non-breaking
        // space, other Unicode spaces) becomes a single plain space.
        $name = preg_replace('/[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+/u', ' ', $name) ?? $name;

        return trim($name);
    }

    public static function key(mixed $name): string
    {
        return mb_strtolower(self::clean($name), 'UTF-8');
    }
}
