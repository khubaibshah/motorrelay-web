<?php

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Job;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class InvoicePdfGenerator
{
    private const PAGE_WIDTH = 595.28; // A4 width in points
    private const PAGE_HEIGHT = 841.89; // A4 height in points

    public function render(Invoice $invoice, Job $job, Collection $items): string
    {
        $currency = strtoupper($invoice->currency ?? 'GBP');

        $issuedAt = $invoice->issued_at ? $invoice->issued_at->copy() : Carbon::now();
        $dueAt = $invoice->issued_at
            ? $invoice->issued_at->copy()->addDays(14)
            : $issuedAt->copy()->addDays(14);

        $billTo = array_values(array_filter([
            $job->postedBy?->name,
            $job->postedBy?->email,
            trim(($job->pickup_label ?? '') . ' ' . ($job->pickup_postcode ?? '')),
        ], fn ($line) => $line !== null && $line !== ''));

        $lineItems = $items->map(function (InvoiceItem $item) use ($currency) {
            $quantity = $item->quantity ?? 1;
            return [
                'description' => $item->description ?? 'Service',
                'unit' => $this->formatMoney((float) $item->amount, $currency),
                'qty' => (string) $quantity,
                'total' => $this->formatMoney((float) $item->total, $currency),
            ];
        })->values();

        $ops = [];
        $pageWidth = self::PAGE_WIDTH;
        $pageHeight = self::PAGE_HEIGHT;
        $margin = 52;
        $contentWidth = $pageWidth - ($margin * 2);
        $green = [11 / 255, 93 / 255, 71 / 255];
        $mint = [94 / 255, 226 / 255, 174 / 255];
        $ink = [3 / 255, 8 / 255, 22 / 255];
        $darkText = [48 / 255, 51 / 255, 58 / 255];
        $mutedText = [90 / 255, 95 / 255, 105 / 255];
        $pale = [232 / 255, 248 / 255, 241 / 255];
        $surface = [248 / 255, 251 / 255, 250 / 255];

        $this->rectFill($ops, 0, 0, $pageWidth, $pageHeight, $surface);
        $this->rectFill($ops, 0, $pageHeight - 170, $pageWidth, 170, $ink);
        $this->rectFill($ops, $margin, $pageHeight - 105, 72, 4, $mint);
        $this->drawText($ops, 'MotorRelay', $margin, $pageHeight - 68, 28, true, [1, 1, 1]);
        $this->drawText($ops, 'Move smarter logistics', $margin, $pageHeight - 94, 10, false, [225 / 255, 235 / 255, 233 / 255]);
        $this->drawText($ops, 'INVOICE', $pageWidth - 175, $pageHeight - 65, 17, true, $mint);
        $this->drawText($ops, '#' . $invoice->number, $pageWidth - 175, $pageHeight - 91, 10, false, [225 / 255, 235 / 255, 233 / 255]);

        $cardY = 560;
        $this->rectFill($ops, $margin, $cardY, $contentWidth, 125, [1, 1, 1]);
        $this->drawText($ops, 'ISSUED TO', $margin + 18, $cardY + 101, 9, true, $green);
        $this->drawText($ops, $this->shorten($billTo[0] ?? 'MotorRelay customer', 38), $margin + 18, $cardY + 78, 12, true, $ink);
        $this->drawText($ops, $this->shorten($billTo[1] ?? '', 38), $margin + 18, $cardY + 57, 10, false, $mutedText);
        $this->drawText($ops, 'PAYMENT DETAILS', $margin + 285, $cardY + 101, 9, true, $green);
        $this->drawText($ops, 'Status: ' . ucfirst($invoice->status), $margin + 285, $cardY + 78, 10, true, $darkText);
        $this->drawText($ops, 'Issued ' . $issuedAt->format('d M Y') . '  |  Due ' . $dueAt->format('d M Y'), $margin + 285, $cardY + 57, 9, false, $mutedText);

        $routeY = 395;
        $this->rectFill($ops, $margin, $routeY, $contentWidth, 130, [1, 1, 1]);
        $this->drawText($ops, 'JOB SUMMARY', $margin + 18, $routeY + 105, 9, true, $green);
        $this->drawText($ops, $this->shorten('Job #' . $job->id . '  |  ' . ($job->title ?: 'Vehicle movement'), 78), $margin + 18, $routeY + 82, 12, true, $ink);
        $this->drawText($ops, $this->shorten('Pickup: ' . trim(($job->pickup_label ?? '') . ' ' . ($job->pickup_postcode ?? '')), 78), $margin + 18, $routeY + 58, 9.5, false, $darkText);
        $this->drawText($ops, $this->shorten('Drop-off: ' . trim(($job->dropoff_label ?? '') . ' ' . ($job->dropoff_postcode ?? '')), 78), $margin + 18, $routeY + 38, 9.5, false, $darkText);
        $this->drawText($ops, 'PAY TO', $margin + 18, $routeY + 16, 8, true, $green);
        $this->drawText($ops, 'MotorRelay Finance  |  Ref: ' . $invoice->number, $margin + 70, $routeY + 16, 8.5, false, $mutedText);

        $tableLeft = $margin;
        $tableTop = $routeY - 24;
        $tableWidth = $contentWidth;
        $headerHeight = 25;
        $rowHeight = 23;
        $headerBottom = $tableTop - $headerHeight;
        $this->rectFill($ops, $tableLeft, $headerBottom, $tableWidth, $headerHeight, $pale);
        $descriptionX = $tableLeft + 10;
        $unitX = $tableLeft + 280;
        $qtyX = $tableLeft + 375;
        $totalX = $tableLeft + $tableWidth - 62;
        $this->drawText($ops, 'DESCRIPTION', $descriptionX, $headerBottom + 9, 8.5, true, $green);
        $this->drawText($ops, 'UNIT', $unitX, $headerBottom + 9, 8.5, true, $green);
        $this->drawText($ops, 'QTY', $qtyX, $headerBottom + 9, 8.5, true, $green);
        $this->drawText($ops, 'TOTAL', $totalX, $headerBottom + 9, 8.5, true, $green);
        $rowTop = $headerBottom;
        foreach ($lineItems as $index => $line) {
            $rowBottom = $rowTop - $rowHeight;
            if ($index % 2 === 0) $this->rectFill($ops, $tableLeft, $rowBottom, $tableWidth, $rowHeight, [0.97, 0.98, 0.97]);
            $textY = $rowBottom + 8;
            $this->drawText($ops, $this->shorten($line['description'], 42), $descriptionX, $textY, 9, false, $darkText);
            $this->drawText($ops, $line['unit'], $unitX, $textY, 9, false, $darkText);
            $this->drawText($ops, $line['qty'], $qtyX, $textY, 9, false, $darkText);
            $this->drawText($ops, $line['total'], $totalX, $textY, 9, false, $darkText);
            $rowTop = $rowBottom;
        }

        $totalsY = max(125, $rowTop - 25);
        $this->rectFill($ops, $margin, $totalsY - 25, $contentWidth, 58, $ink);
        $this->drawText($ops, 'TOTAL DUE', $margin + 18, $totalsY + 8, 10, true, $mint);
        $this->drawText($ops, $this->formatMoney((float) $invoice->total, $currency), $pageWidth - $margin - 145, $totalsY + 3, 18, true, [1, 1, 1]);

        $footerY = 58;
        $this->drawLine($ops, $margin, $footerY + 20, $pageWidth - $margin, $footerY + 20, [0.82, 0.85, 0.87], 0.6);
        $this->drawText($ops, 'Payment terms: due within 14 days.', $margin, $footerY + 4, 8.5, false, $mutedText);
        $this->drawText($ops, 'Generated by MotorRelay  |  motorrelay.com', $margin, $footerY - 12, 8.5, false, $mutedText);

        return $this->finalizePdf($ops, $pageWidth, $pageHeight);
    }

    private function shorten(?string $value, int $limit): string
    {
        $value = trim((string) $value);
        return $value === '' ? '' : (strlen($value) > $limit ? substr($value, 0, $limit - 3) . '...' : $value);
    }

    private function drawText(array &$ops, string $text, float $x, float $y, float $size = 12, bool $bold = false, array $rgb = [0, 0, 0]): void
    {
        [$r, $g, $b] = $rgb;
        $font = $bold ? 'F2' : 'F1';
        $escaped = $this->escapeText($text);
        $ops[] = sprintf(
            '%.3f %.3f %.3f rg BT /%s %.2f Tf 1 0 0 1 %.2f %.2f Tm (%s) Tj ET',
            $r,
            $g,
            $b,
            $font,
            $size,
            $x,
            $y,
            $escaped
        );
    }

    private function rectFill(array &$ops, float $x, float $y, float $width, float $height, array $rgb): void
    {
        [$r, $g, $b] = $rgb;
        $ops[] = sprintf('q %.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f Q', $r, $g, $b, $x, $y, $width, $height);
    }

    private function drawLine(array &$ops, float $x1, float $y1, float $x2, float $y2, array $rgb = [0, 0, 0], float $width = 1.0): void
    {
        [$r, $g, $b] = $rgb;
        $ops[] = sprintf(
            'q %.3f %.3f %.3f RG %.2f w %.2f %.2f m %.2f %.2f l S Q',
            $r,
            $g,
            $b,
            $width,
            $x1,
            $y1,
            $x2,
            $y2
        );
    }

    private function formatMoney(float $amount, string $currency): string
    {
        $symbol = $this->currencySymbol($currency);
        $formatted = number_format($amount, 2);

        return $symbol ? $symbol . $formatted : $currency . ' ' . $formatted;
    }

    private function currencySymbol(string $currency): string
    {
        return match ($currency) {
            'GBP' => '£',
            'USD' => '$',
            'EUR' => '€',
            default => ''
        };
    }

    private function finalizePdf(array $operations, float $pageWidth, float $pageHeight): string
    {
        $streamContent = implode("\n", $operations) . "\n";
        $length = strlen($streamContent);

        $objects = [
            "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj",
            "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj",
            "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pageWidth} {$pageHeight}] /Contents 4 0 R /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> >> endobj",
            "4 0 obj << /Length {$length} >> stream\n{$streamContent}endstream endobj",
            "5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj",
            "6 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >> endobj",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        $position = strlen($pdf);

        foreach ($objects as $object) {
            $offsets[] = $position;
            $pdf .= "{$object}\n";
            $position = strlen($pdf);
        }

        $xrefPosition = strlen($pdf);
        $pdf .= "xref\n0 " . count($offsets) . "\n";
        foreach ($offsets as $offset) {
            $pdf .= str_pad((string) $offset, 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        }
        $pdf .= "trailer << /Size " . count($offsets) . " /Root 1 0 R >>\nstartxref\n{$xrefPosition}\n%%EOF";

        return $pdf;
    }

    protected function escapeText(string $text): string
    {
        $replacements = [
            '\\' => '\\\\',
            '(' => '\(',
            ')' => '\)',
        ];

        return strtr($text, $replacements);
    }
}
