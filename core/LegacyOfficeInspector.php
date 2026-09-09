<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore;

use InvalidArgumentException;

/**
 * The binary Office formats that predate OOXML: .doc, .ppt and .xls.
 *
 * This inspector does not read the file. It does not open it, stat it, or care
 * whether it exists — it returns `unsupported` and says what to do about it.
 *
 * That is the whole point. Spec §12 is explicit: detect them and report them
 * clearly rather than attempting to parse them. A half-understood OLE2 stream
 * that yields no findings would be recorded as a document with nothing wrong,
 * and "no problems found" is a far worse answer than "cannot check this" for a
 * format nobody should still be publishing.
 */
final class LegacyOfficeInspector implements Inspector
{
    /**
     * Nothing runs, so the result carries no engine at all. This is the engine
     * that *would* have run, which the control panel needs when it lists what
     * is installed; it never reaches a stored result.
     */
    public function engine(): Engine
    {
        return Engine::heuristics(Version::CURRENT);
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function supports(Format $format): bool
    {
        return $format->isLegacy();
    }

    public function inspect(string $path, Format $format): InspectionResult
    {
        if (! $this->supports($format)) {
            throw new InvalidArgumentException(
                "LegacyOfficeInspector was handed a {$format->value} file. Check supports() first."
            );
        }

        return InspectionResult::unsupported(sprintf(
            '%s files cannot be checked for accessibility. Open this one and save it as .%s, '
            .'then it can be — and it will be easier for everyone to read in the meantime.',
            self::describe($format),
            $format->modernEquivalent()->value,
        ));
    }

    private static function describe(Format $format): string
    {
        return match ($format) {
            Format::Doc => 'Word 97–2003 (.doc)',
            Format::Ppt => 'PowerPoint 97–2003 (.ppt)',
            Format::Xls => 'Excel 97–2003 (.xls)',
            default => strtoupper($format->value),
        };
    }
}
