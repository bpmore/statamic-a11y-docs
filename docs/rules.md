# What A11y Docs checks

36 rules across four formats. Generated from the rule definitions
themselves by `tools/build-rules-doc.php` — edit the code, not this file.

Severities are the same four A11y Report uses, so the two products describe a
site and its documents in one vocabulary:

| Severity | What it means |
|---|---|
| **critical** | Somebody cannot read the document at all. |
| **serious** | A real barrier, though it can be worked around. |
| **moderate** | Makes the document harder to use than it needs to be. |
| **minor** | Worth fixing; blocks nobody. |

## PDF

Checked by the built-in heuristics, and by veraPDF where it is installed.

| Rule | Severity | Microsoft calls this | Example |
|---|---|---|---|
| `pdf.not_tagged` | critical | — | `pdf/untagged.pdf` |
| `pdf.no_title` | serious | — | `pdf/no-title.pdf` |
| `pdf.title_not_displayed` | serious | — | `pdf/title-not-displayed.pdf` |
| `pdf.no_lang` | serious | — | `pdf/no-lang.pdf` |
| `pdf.image_only` | critical | — | `pdf/image-only-scan.pdf` |
| `pdf.extraction_blocked` | critical | — | `pdf/encrypted-no-extract.pdf` |
| `pdf.no_bookmarks` | moderate | — | `pdf/long-no-bookmarks.pdf` |
| `pdf.unlabelled_form_fields` | serious | — | `pdf/form-unlabelled-fields.pdf` |
| `pdf.figure_missing_alt` | serious | — | `pdf/figure-missing-alt.pdf` |
| `pdf.no_tounicode` | moderate | — | `pdf/no-tounicode.pdf` |

## Word

Grounded in Microsoft's own Accessibility Checker, so a finding matches something the author can see and fix in Word.

| Rule | Severity | Microsoft calls this | Example |
|---|---|---|---|
| `docx.image_missing_alt` | critical | Error | `docx/header-image-missing-alt.docx` |
| `docx.alt_text_is_filename` | critical | Error | `docx/alt-text-is-filename.docx` |
| `docx.alt_text_not_descriptive` | critical | Error | `docx/alt-text-is-filename.docx` |
| `docx.no_headings` | serious | Tip † | `docx/no-headings.docx` |
| `docx.table_missing_header_row` | serious | Error † | `docx/table-missing-header.docx` |
| `docx.complex_table` | moderate | Warning | `docx/complex-table.docx` |
| `docx.link_text_not_meaningful` | serious | — | `docx/link-text-not-meaningful.docx` |
| `docx.no_title` | moderate | — | `docx/no-title-no-lang.docx` |
| `docx.no_lang` | serious | — | `docx/no-title-no-lang.docx` |
| `docx.document_protected` | critical | Error | `docx/protected.docx` |
| `docx.content_control_untitled` | serious | Error † | `docx/form-controls-untitled.docx` |

## PowerPoint

As for Word: these correspond to what PowerPoint's own checker reports.

| Rule | Severity | Microsoft calls this | Example |
|---|---|---|---|
| `pptx.slide_missing_title` | critical | Error | `pptx/slide-missing-title.pptx` |
| `pptx.shape_missing_alt` | critical | Error | `pptx/shape-missing-alt.pptx` |
| `pptx.alt_text_is_filename` | critical | Error | `pptx/alt-text-not-descriptive.pptx` |
| `pptx.alt_text_not_descriptive` | critical | Error | `pptx/alt-text-not-descriptive.pptx` |
| `pptx.media_without_captions` | moderate | Warning | `pptx/media-without-captions.pptx` |
| `pptx.duplicate_slide_titles` | minor | Tip | `pptx/duplicate-slide-titles.pptx` |
| `pptx.reading_order` | minor | Warning † | `pptx/reading-order.pptx` |
| `pptx.default_section_names` | minor | Error † | `pptx/default-section-names.pptx` |

## Excel

As for Word: these correspond to what Excel's own checker reports.

| Rule | Severity | Microsoft calls this | Example |
|---|---|---|---|
| `xlsx.table_missing_header_row` | serious | Error † | `xlsx/table-missing-header.xlsx` |
| `xlsx.drawing_missing_alt` | critical | Error | `xlsx/drawing-missing-alt.xlsx` |
| `xlsx.alt_text_is_filename` | critical | Error | `xlsx/alt-text-not-descriptive.xlsx` |
| `xlsx.alt_text_not_descriptive` | critical | Error | `xlsx/alt-text-not-descriptive.xlsx` |
| `xlsx.default_sheet_name` | moderate | Warning | `xlsx/default-sheet-names.xlsx` |
| `xlsx.blank_sheet` | moderate | — | `xlsx/default-sheet-names.xlsx` |
| `xlsx.red_only_conditional_formatting` | moderate | Error † | `xlsx/red-only-formatting.xlsx` |

## † Where we grade differently from Microsoft

Microsoft's checker has three levels and this has four, so the mapping cannot be
one to one: **Error → critical, Warning → moderate, Tip → minor**, leaving *serious*
for the rules Microsoft does not check at all. Every rule that departs from that
says why.

**`docx.no_headings`** — Graded serious, three levels above Microsoft, and the one place this product deliberately disagrees with Word outright. A thirty-page document with no headings cannot be navigated at all by somebody who cannot skim it by eye — they are left arrowing through it a line at a time. Microsoft grades it a tip because Word makes it easy to fix, which is a fact about the authoring tool and no help whatsoever to the person reading the result.

**`docx.table_missing_header_row`** — A table without a declared header row can still be read cell by cell. What is lost is which column each value belongs to, which is a real barrier and not an impassable one. Critical is kept for content that cannot be reached at all.

**`docx.content_control_untitled`** — An unlabelled field can often still be completed by somebody who can infer its purpose from the text around it. A barrier rather than a wall.

**`pptx.reading_order`** — Graded a tip, deliberately below what Microsoft gives it. PowerPoint knows what its own layout means; we are inferring it from shape coordinates, and spec §12 warns that produces false positives on decorative layouts. A rule we cannot make reliable is graded so it sorts beneath the ones we can.

**`pptx.default_section_names`** — Microsoft splits this in two and grades the naming half an error. Sections are an authoring convenience: PowerPoint exposes them while a deck is being edited and not to anybody reading the presented result, so both halves are graded as a tip here.

**`xlsx.table_missing_header_row`** — As for Word: the values are still readable, the column labels are what goes missing.

**`xlsx.red_only_conditional_formatting`** — The numbers are still there and still readable; what is lost is the emphasis. Colour carrying meaning on its own is a genuine failure, but not one that puts the data out of reach.

## Rules Microsoft does not check

Checked here anyway, each for a stated reason.

**`docx.link_text_not_meaningful`** — Not on Microsoft's published list. WCAG 2.4.4 covers it and it is one of the most common real problems in a document library, so it is checked anyway.

**`docx.no_title`** — Word does not check whether a document has a title in its properties. It is what a screen reader announces in place of the filename, so it is checked here.

**`docx.no_lang`** — Word does not check this either; WCAG 3.1.1 does, and a document read aloud in the wrong language is unintelligible rather than merely awkward.

**`xlsx.blank_sheet`** — Microsoft checks for blank rows and columns inside a table but not for an entirely empty sheet, which is one more tab to move past for no reason.

## What is not checked

- **Legacy `.doc`, `.ppt` and `.xls`** are detected and reported as `unsupported`, never
  parsed. A half-understood binary file that yields no findings would be recorded as a
  document with nothing wrong, which is worse than saying it cannot be checked.
- **Colour contrast inside documents.** Microsoft's checker does this and this does not;
  it needs rendering, not reading.
- **Anything about the website itself.** That is A11y Report's job.
