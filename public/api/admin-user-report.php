<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
apiRequirePlatformAdministrator();
apiRequirePermission('admin.users');
require_once dirname(__DIR__, 2) . '/app/PdfDocument.php';

$users = $db->query(
    'SELECT u.id,u.username,p.display_name,p.first_name,p.last_name
     FROM users u
     LEFT JOIN people p ON p.id=u.person_id
     ORDER BY COALESCE(p.last_name,p.display_name,u.username),p.first_name,u.username'
)->fetchAll();

$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('Platforma interna Maternitatea Giulesti');
$pdf->SetAuthor('Maternitatea Giulesti');
$pdf->SetTitle('Lista utilizatori');
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(12, 12, 12);
$pdf->SetAutoPageBreak(false, 12);
$pdf->setFontSubsetting(true);

$addPage = static function (TCPDF $document): void {
    $document->AddPage();
    $document->SetTextColor(27, 54, 70);
    $document->SetFont('dejavusans', 'B', 14);
    $document->Cell(0, 8, 'Lista utilizatori', 0, 1, 'L');
    $document->Ln(2);
    $document->SetFillColor(40, 101, 96);
    $document->SetTextColor(255, 255, 255);
    $document->SetFont('dejavusans', 'B', 8);
    $document->Cell(110, 8, 'Nume', 0, 0, 'L', true);
    $document->Cell(75, 8, 'Username', 0, 1, 'L', true);
};

$addPage($pdf);
foreach ($users as $index => $user) {
    if ($pdf->GetY() + 7 > 283) {
        $addPage($pdf);
    }
    $fill = $index % 2 === 1;
    $pdf->SetFillColor(245, 248, 247);
    $pdf->SetTextColor(30, 53, 65);
    $pdf->SetFont('dejavusans', '', 8);
    $name = trim((string) ($user['display_name'] ?? ''));
    $pdf->Cell(110, 7, pdfFitText($pdf, $name !== '' ? $name : '-', 108), 'B', 0, 'L', $fill);
    $pdf->Cell(75, 7, pdfFitText($pdf, (string) $user['username'], 73), 'B', 1, 'L', $fill);
}

apiAudit($db, 'admin.user_report_downloaded', 'user_report', null, null, ['format' => 'pdf', 'users' => count($users)]);
$contents = $pdf->Output('', 'S');
$filename = 'lista_utilizatori_' . date('Y-m-d') . '.pdf';
header('Content-Type: application/pdf', true);
header('Content-Disposition: attachment; filename="' . $filename . '"', true);
header('Content-Length: ' . strlen($contents), true);
echo $contents;
exit;
