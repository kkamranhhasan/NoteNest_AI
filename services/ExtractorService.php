<?php
// ============================================================
// services/ExtractorService.php — NoteNest AI Platform
// Document Text Extraction Service
// ============================================================

namespace Services;

use Smalot\PdfParser\Parser as PdfParser;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpPresentation\IOFactory as PresentationIOFactory;

class ExtractorService {
    /**
     * Extracts plain text from supported files based on extension.
     */
    public function extractText(string $filePath): string {
        if (!file_exists($filePath)) {
            return '';
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        try {
            switch ($ext) {
                case 'pdf':
                    return $this->extractPdf($filePath);
                case 'docx':
                    return $this->extractDocx($filePath);
                case 'pptx':
                    return $this->extractPptx($filePath);
                case 'txt':
                case 'md':
                case 'html':
                case 'xml':
                    return $this->extractTxt($filePath);
                default:
                    return ''; // Unsupported extensions
            }
        } catch (\Exception $e) {
            error_log("ExtractorService error parsing [{$filePath}]: " . $e->getMessage());
            return '';
        }
    }

    private function extractPdf(string $filePath): string {
        // Lightweight regex stream extraction first (ultra low memory usage)
        $infile = @file_get_contents($filePath);
        if (!empty($infile)) {
            $texts = [];
            preg_match_all("/stream(.*?)endstream/is", $infile, $matches);
            foreach ($matches[1] as $match) {
                $data = @gzuncompress(trim($match));
                if ($data) {
                    preg_match_all('/\((.*?)\)\s*T[jJ]/is', $data, $tj2);
                    if (!empty($tj2[1])) {
                        foreach ($tj2[1] as $str) {
                            $texts[] = preg_replace('/\\\\./', '', $str);
                        }
                    }
                }
            }
            $rawText = implode(" ", $texts);
            if (strlen(trim($rawText)) > 50) {
                return $rawText;
            }
        }

        // Fallback to Smalot PDF Parser if regex yields little text
        try {
            $parser = new PdfParser();
            $pdf = $parser->parseFile($filePath);
            return $pdf->getText();
        } catch (\Throwable $e) {
            error_log("PdfParser memory/parse error: " . $e->getMessage());
            return $rawText ?? '';
        }
    }

    private function extractDocx(string $filePath): string {
        $phpWord = WordIOFactory::load($filePath);
        $text = '';
        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $text .= $this->extractWordElementText($element);
            }
        }
        return $text;
    }

    private function extractWordElementText($element): string {
        $text = '';
        if (method_exists($element, 'getText')) {
            $text .= $element->getText() . ' ';
        } elseif ($element instanceof \PhpOffice\PhpWord\Element\TextRun || $element instanceof \PhpOffice\PhpWord\Element\Cell) {
            foreach ($element->getElements() as $child) {
                $text .= $this->extractWordElementText($child);
            }
        } elseif ($element instanceof \PhpOffice\PhpWord\Element\Table) {
            foreach ($element->getRows() as $row) {
                foreach ($row->getCells() as $cell) {
                    $text .= $this->extractWordElementText($cell);
                }
            }
        }
        return $text;
    }

    private function extractPptx(string $filePath): string {
        $powerpoint = PresentationIOFactory::load($filePath);
        $text = '';
        foreach ($powerpoint->getAllSlides() as $slide) {
            foreach ($slide->getShapeCollection() as $shape) {
                if ($shape instanceof \PhpOffice\PhpPresentation\Shape\RichText) {
                    foreach ($shape->getParagraphs() as $paragraph) {
                        foreach ($paragraph->getRichTextElements() as $element) {
                            $text .= $element->getText() . ' ';
                        }
                    }
                }
            }
        }
        return $text;
    }

    private function extractTxt(string $filePath): string {
        return @file_get_contents($filePath) ?: '';
    }
}
