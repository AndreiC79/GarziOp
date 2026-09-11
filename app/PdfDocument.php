<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/tcpdf/tcpdf.php';

function createUnicodePdf(string $title): TCPDF
{
    $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('Platforma interna Maternitatea Giulesti');
    $pdf->SetAuthor('Maternitatea Giulesti');
    $pdf->SetTitle($title);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(8, 8, 8);
    $pdf->SetAutoPageBreak(false, 8);
    $pdf->setFontSubsetting(true);
    $pdf->SetFont('dejavusans', '', 8);
    return $pdf;
}

function pdfFitText(TCPDF $pdf, string $text, float $width): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    if ($pdf->GetStringWidth($text) <= $width) return $text;
    while (mb_strlen($text) > 1 && $pdf->GetStringWidth($text . '…') > $width) {
        $text = mb_substr($text, 0, -1);
    }
    return rtrim($text) . '…';
}
