<?php
/**
 * Générateur PDF minimal et autonome (aucune dépendance, aucune extension
 * requise) — suffisant pour un récapitulatif texte + tableau.
 * Police standard Helvetica (WinAnsi) : les accents français sont gérés en
 * convertissant le texte UTF-8 en Windows-1252.
 */

class SimplePDF
{
    private array $pages = [];
    private string $cur = '';
    private float $pageW = 595.28;   // A4 en points
    private float $pageH = 841.89;
    private float $marginX = 50;
    private float $marginTop = 55;
    private float $marginBottom = 55;
    private float $lineH = 16;
    private float $y = 0;

    public function __construct()
    {
        $this->addPage();
    }

    public function addPage(): void
    {
        if ($this->cur !== '') {
            $this->pages[] = $this->cur;
        }
        $this->cur = '';
        $this->y = $this->pageH - $this->marginTop;
    }

    private function ensure(float $h): void
    {
        if ($this->y - $h < $this->marginBottom) {
            $this->addPage();
        }
    }

    private function esc(string $s): string
    {
        $conv = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
        if ($conv === false) {
            $conv = @mb_convert_encoding($s, 'Windows-1252', 'UTF-8') ?: $s;
        }
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ''], $conv);
    }

    private function put(float $x, float $y, string $text, float $size, bool $bold = false, string $color = '0 0 0'): void
    {
        $f = $bold ? 'F2' : 'F1';
        $this->cur .= "BT $color rg /$f $size Tf "
            . sprintf('%.2f %.2f', $x, $y) . ' Td (' . $this->esc($text) . ") Tj ET\n";
    }

    private function hrLight(): void
    {
        $this->cur .= sprintf("0.85 0.86 0.9 RG 0.5 w %.2f %.2f m %.2f %.2f l S\n",
            $this->marginX, $this->y, $this->pageW - $this->marginX, $this->y);
    }

    private function wrap(string $text, float $size): array
    {
        $maxWidth = $this->pageW - 2 * $this->marginX;
        $maxChars = max(10, (int) floor($maxWidth / ($size * 0.5)));
        $out = [];
        foreach (explode("\n", $text) as $para) {
            $words = preg_split('/\s+/', trim($para));
            $line = '';
            foreach ($words as $w) {
                if ($line === '') {
                    $line = $w;
                } elseif (strlen($line) + 1 + strlen($w) <= $maxChars) {
                    $line .= ' ' . $w;
                } else {
                    $out[] = $line;
                    $line = $w;
                }
            }
            $out[] = $line;
        }
        return $out ?: [''];
    }

    private function truncate(string $text, float $width, float $size): string
    {
        $maxChars = max(4, (int) floor($width / ($size * 0.52)));
        if (strlen($text) <= $maxChars) {
            return $text;
        }
        return substr($text, 0, $maxChars - 1) . '…';
    }

    /* ---------- API de haut niveau ---------- */

    public function title(string $t): void
    {
        $this->ensure(30);
        $this->put($this->marginX, $this->y, $t, 18, true, '0.09 0.15 0.56');
        $this->y -= 28;
    }

    public function h2(string $t): void
    {
        $this->y -= 8;
        $this->ensure(24);
        $this->put($this->marginX, $this->y, $t, 12.5, true, '0.09 0.15 0.56');
        $this->y -= 16;
        $this->hrLight();
        $this->y -= 8;
    }

    public function text(string $t, float $size = 10): void
    {
        foreach ($this->wrap($t, $size) as $ln) {
            $this->ensure($this->lineH);
            $this->put($this->marginX, $this->y, $ln, $size);
            $this->y -= $this->lineH;
        }
    }

    public function kv(string $k, string $v): void
    {
        $this->ensure($this->lineH);
        $this->put($this->marginX, $this->y, $k, 10, true);
        $this->put($this->marginX + 150, $this->y, $v, 10);
        $this->y -= $this->lineH;
    }

    public function spacer(float $h = 10): void
    {
        $this->y -= $h;
    }

    /** Ligne de tableau. $widths = largeurs absolues des colonnes (points). */
    public function row(array $cells, array $widths, bool $bold = false): void
    {
        $this->ensure($this->lineH + 4);
        $x = $this->marginX;
        foreach (array_values($cells) as $i => $c) {
            $w = $widths[$i] ?? 80;
            $this->put($x, $this->y, $this->truncate((string) $c, $w - 6, 9), 9, $bold);
            $x += $w;
        }
        $this->y -= 5;
        $this->hrLight();
        $this->y -= 9;
    }

    public function totalLine(string $label, string $value): void
    {
        $this->spacer(4);
        $this->ensure($this->lineH);
        $this->put($this->marginX, $this->y, $label, 12, true);
        $this->put($this->pageW - $this->marginX - 120, $this->y, $value, 12, true, '0.09 0.15 0.56');
        $this->y -= $this->lineH;
    }

    /* ---------- Assemblage du fichier PDF ---------- */

    public function output(): string
    {
        if ($this->cur !== '') {
            $this->pages[] = $this->cur;
            $this->cur = '';
        }
        if (!$this->pages) {
            $this->pages[] = '';
        }
        $N = count($this->pages);

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        $next = 5;
        $kids = [];
        foreach ($this->pages as $content) {
            $contentObj = $next++;
            $pageObj = $next++;
            $objects[$contentObj] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . 'endstream';
            $objects[$pageObj] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '
                . sprintf('%.2f %.2f', $this->pageW, $this->pageH)
                . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $contentObj . ' 0 R >>';
            $kids[] = "$pageObj 0 R";
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . "] /Count $N >>";

        $maxObj = $next - 1;
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        for ($i = 1; $i <= $maxObj; $i++) {
            $offsets[$i] = strlen($pdf);
            $pdf .= "$i 0 obj\n" . $objects[$i] . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . ($maxObj + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $maxObj; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size " . ($maxObj + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
        return $pdf;
    }

    public function save(string $path): bool
    {
        return file_put_contents($path, $this->output()) !== false;
    }
}
