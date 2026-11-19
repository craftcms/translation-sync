<?php

namespace craft\translationsync;

use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\ParserFactory;
use RuntimeException;

/**
 * Reads and writes translation files of the form `<?php return ['key' => 'value'];`.
 *
 * Files are parsed rather than included, so a file from a contributor's branch never runs.
 */
final class PhpArrayFile
{
    /**
     * @return array<string,string> Entries in file order.
     */
    public static function parse(string $code, string $label): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        foreach ($parser->parse($code) ?? [] as $stmt) {
            if (!$stmt instanceof Return_) {
                continue;
            }
            if (!$stmt->expr instanceof Array_) {
                break;
            }
            $entries = [];
            foreach ($stmt->expr->items as $item) {
                if (!$item instanceof ArrayItem || !$item->key instanceof String_ || !$item->value instanceof String_) {
                    $line = $item?->getStartLine() ?? '?';
                    throw new RuntimeException("$label:$line: only string keys and string values are supported.");
                }
                $entries[$item->key->value] = $item->value->value;
            }
            return $entries;
        }
        throw new RuntimeException("$label: expected `return [...];`.");
    }

    /**
     * Renders rows as a translation file. Rows marked `commented` are written as
     * `// 'key' => 'value',` lines, which parse() ignores.
     *
     * @param iterable<array{0:string|int,1:string,2?:bool}> $rows [key, value, commented]
     * @param string|null $template An existing file whose header (everything before `return [`) to keep.
     */
    public static function render(iterable $rows, ?string $template = null): string
    {
        $pos = $template === null ? false : strpos($template, 'return [');
        $out = ($pos === false ? "<?php\n\n" : substr($template, 0, $pos)) . "return [\n";
        foreach ($rows as $row) {
            $out .= '    ' . (!empty($row[2]) ? '// ' : '') . self::quote((string)$row[0]) . ' => ' . self::quote($row[1]) . ",\n";
        }
        return $out . "];\n";
    }

    public static function quote(string $value): string
    {
        if (!preg_match('/[\x00-\x1F\x7F]/', $value)) {
            return "'" . strtr($value, ['\\' => '\\\\', "'" => "\\'"]) . "'";
        }
        $escaped = strtr($value, [
            '\\' => '\\\\',
            '"' => '\\"',
            '$' => '\\$',
            "\n" => '\\n',
            "\r" => '\\r',
            "\t" => '\\t',
        ]);
        $escaped = preg_replace_callback('/[\x00-\x1F\x7F]/', fn($m) => sprintf('\\x%02X', ord($m[0])), $escaped);
        return '"' . $escaped . '"';
    }
}
