<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
apiRequirePermission('guards.view');

$month = validMonth((string) ($_GET['month'] ?? date('Y-m')));
$format = 'pdf';
$nameFormat = (string)($_GET['name_format'] ?? 'full');
if (!in_array($nameFormat, ['full','compact'], true)) apiError('Formatul numelor nu este valid.');
$requested = array_filter(array_map('trim', explode(',', (string) ($_GET['specialties'] ?? 'OG'))));
$specialties = array_values(array_intersect(['OG', 'ATI', 'NN'], $requested));
if (!$specialties) apiError('Selecteaza cel putin o specialitate.');
$start = $month . '-01';
$end = (new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');

$configuration = [];
foreach ($db->query('SELECT specialty,line_count,line_labels FROM guard_specialty_settings') as $row) {
    $configuration[$row['specialty']] = ['count' => (int) $row['line_count'], 'labels' => json_decode((string) $row['line_labels'], true) ?: []];
}
$guards = [];
$statement = $db->prepare(
    'SELECT ga.guard_date,ga.specialty,ga.line_no,p.id person_id,p.first_name,p.last_name,p.display_name,p.professional_category
     FROM guard_assignments ga JOIN guard_plan_versions gv ON gv.id=ga.version_id
     JOIN guard_plans gp ON gp.current_version_id=gv.id JOIN people p ON p.id=ga.current_person_id
     WHERE ga.guard_date BETWEEN :a AND :b ORDER BY ga.guard_date,ga.line_no'
);
$statement->execute(['a'=>$start,'b'=>$end]);
foreach ($statement as $row) $guards[$row['specialty']][$row['guard_date']][(int)$row['line_no']] = $row;
$formattedNames = guardExportNames($guards, $nameFormat);
$holidays = [];
$holidayStatement = $db->prepare('SELECT holiday_date,name FROM holidays WHERE holiday_date BETWEEN :a AND :b');
$holidayStatement->execute(['a'=>$start,'b'=>$end]);
foreach ($holidayStatement as $row) $holidays[$row['holiday_date']] = $row['name'];

$monthNames = [1=>'Ianuarie',2=>'Februarie',3=>'Martie',4=>'Aprilie',5=>'Mai',6=>'Iunie',7=>'Iulie',8=>'August',9=>'Septembrie',10=>'Octombrie',11=>'Noiembrie',12=>'Decembrie'];
$monthNumber = (int) substr($month,5,2); $year=(int)substr($month,0,4); $days=(int)(new DateTimeImmutable($start))->format('t');
$filename='garzi_'.$monthNames[$monthNumber].'_'.$year.'_'.implode('_',$specialties).'.'.$format;

$pdf=buildGuardUnicodePdf($specialties,$configuration,$guards,$holidays,$year,$monthNumber,$days,$monthNames[$monthNumber],$formattedNames);header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="'.$filename.'"');header('Content-Length: '.strlen($pdf));echo$pdf;exit;

function writeGuardOverviewSheet(XLSXWriter $writer,array $specialties,array $configuration,array $guards,array $holidays,int $year,int $month,int $days,string $monthName,array $formattedNames=[]): void
{
    $columns=['Data'=>'string','Zi'=>'string'];$widths=[12,16];
    foreach($specialties as $specialty){$config=$configuration[$specialty];for($line=1;$line<=$config['count'];$line++){$label=$config['labels'][$line-1]??('L'.$line);$columns[$specialty.' '.$label]='string';$widths[]=20;}}
    $sheet=substr('Garzi '.$monthName.' '.$year,0,31);$writer->writeSheetHeader($sheet,$columns,['widths'=>$widths,'suppress_row'=>true,'freeze_rows'=>2,'fit_to_page'=>true,'page_setup'=>['orientation'=>'landscape','paperSize'=>9,'fitToWidth'=>1,'fitToHeight'=>1],'page_margins'=>['left'=>'0.2','right'=>'0.2','top'=>'0.35','bottom'=>'0.35']]);
    $writer->writeSheetRow($sheet,['Garzi '.$monthName.' '.$year.' - '.implode(' / ',$specialties),...array_fill(0,count($columns)-1,'')],['height'=>27,'font-style'=>'bold','font-size'=>14,'fill'=>'#D9EAF7','border'=>'all']);$writer->markMergedCell($sheet,0,0,0,count($columns)-1);
    $writer->writeSheetRow($sheet,array_keys($columns),['height'=>22,'font-style'=>'bold','font-size'=>10,'fill'=>'#1F3864','color'=>'#FFFFFF','border'=>'all']);
    for($day=1;$day<=$days;$day++){$date=sprintf('%04d-%02d-%02d',$year,$month,$day);$point=new DateTimeImmutable($date);$weekday=(int)$point->format('N');$weekend=$weekday>=6;$holiday=isset($holidays[$date]);$fill=$holiday?'#FCE4D6':($weekend?'#D9D9D9':'#FFFFFF');$row=[$point->format('d.m.Y'),['Lu','Ma','Mi','Jo','Vi','Sa','Du'][$weekday-1].($holiday?' - '.$holidays[$date]:'')];foreach($specialties as$specialty){$config=$configuration[$specialty];for($line=1;$line<=$config['count'];$line++){$guard=$guards[$specialty][$date][$line]??null;$row[]=$guard?($formattedNames[(int)$guard['person_id']]??$guard['display_name']):'';}}$writer->writeSheetRow($sheet,$row,['height'=>18,'font-size'=>9,'fill'=>$fill,'border'=>'all','border-style'=>'thin']);}
}

function buildGuardUnicodePdf(array $specialties,array $configuration,array $guards,array $holidays,int $year,int $month,int $days,string $monthName,array $formattedNames=[]): string
{
    require_once dirname(__DIR__, 2) . '/app/PdfDocument.php';
    $title = 'Gărzi - ' . $monthName . ' ' . $year . ' - ' . implode(' / ', $specialties);
    $pdf = createUnicodePdf($title);
    $pdf->AddPage();
    $pdf->SetTextColor(23, 50, 74);
    $pdf->SetFont('dejavusans', 'B', 12);
    $pdf->Cell(0, 8, $title, 0, 1, 'L');

    $totalLines = 0;
    foreach ($specialties as $specialty) $totalLines += $configuration[$specialty]['count'];
    $tableWidth = $pdf->getPageWidth() - 16;
    $dateWidth = 22.0;
    $dayWidth = 14.0;
    $lineWidth = ($tableWidth - $dateWidth - $dayWidth) / max(1, $totalLines);
    $headerHeight = 8.0;
    $rowHeight = 5.35;
    $fontSize = max(4.2, min(6.5, $lineWidth / 4.2));

    $pdf->SetFillColor(31, 56, 100);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetDrawColor(170, 184, 193);
    $pdf->SetFont('dejavusans', 'B', 6.2);
    $pdf->Cell($dateWidth, $headerHeight, 'Data', 1, 0, 'C', true);
    $pdf->Cell($dayWidth, $headerHeight, 'Zi', 1, 0, 'C', true);
    foreach ($specialties as $specialty) {
        $config = $configuration[$specialty];
        for ($line=1; $line<=$config['count']; $line++) {
            $label = match ($specialty) {
                'OG' => 'OG Linia ' . $line,
                'ATI' => 'ATI',
                'NN' => 'NN Linia ' . $line,
                default => $specialty . ' Linia ' . $line,
            };
            $pdf->Cell($lineWidth, $headerHeight, pdfFitText($pdf, $label, $lineWidth-1), 1, 0, 'C', true);
        }
    }
    $pdf->Ln();

    for ($day=1; $day<=$days; $day++) {
        $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $point = new DateTimeImmutable($date);
        $weekday = (int)$point->format('N');
        if (isset($holidays[$date])) $pdf->SetFillColor(252, 228, 214);
        elseif ($weekday >= 6) $pdf->SetFillColor(225, 228, 228);
        else $pdf->SetFillColor(255, 255, 255);
        $pdf->SetTextColor(30, 48, 61);
        $pdf->SetFont('dejavusans', '', 5.7);
        $pdf->Cell($dateWidth, $rowHeight, $point->format('d.m.Y'), 1, 0, 'C', true);
        $dayLabel = ['Lu','Ma','Mi','Jo','Vi','Sâ','Du'][$weekday-1];
        $pdf->Cell($dayWidth, $rowHeight, pdfFitText($pdf, $dayLabel, $dayWidth-1), 1, 0, 'L', true);
        foreach ($specialties as $specialty) {
            $config = $configuration[$specialty];
            for ($line=1; $line<=$config['count']; $line++) {
                $guard = $guards[$specialty][$date][$line] ?? null;
                $name = $guard ? (string)($formattedNames[(int)$guard['person_id']] ?? $guard['display_name']) : '';
                $pdf->SetFont('dejavusans', $name !== '' ? 'B' : '', $fontSize);
                $pdf->Cell($lineWidth, $rowHeight, pdfFitText($pdf, $name, $lineWidth-1), 1, 0, 'L', true);
            }
        }
        $pdf->Ln();
    }
    return $pdf->Output('', 'S');
}

function guardExportNames(array $guards,string $format): array
{
    $people=[];
    foreach($guards as $specialtyDays)foreach($specialtyDays as $dayLines)foreach($dayLines as $guard)$people[(int)$guard['person_id']]=$guard;
    if($format==='full')return array_map(static fn(array$row):string=>(string)$row['display_name'],$people);
    $groups=[];
    foreach($people as $id=>$person){$first=trim((string)$person['first_name']);$last=trim((string)$person['last_name']);$initial=mb_strtoupper(mb_substr($first,0,1),'UTF-8');$groups[mb_strtoupper($last,'UTF-8').'|'.$initial][$id]=['first'=>$first,'last'=>$last];}
    $result=[];
    foreach($groups as $group){$collision=count($group)>1;$prefixLength=$collision?3:1;if($collision){$max=max(array_map(static fn(array$item):int=>mb_strlen($item['first'],'UTF-8'),$group));while($prefixLength<$max){$seen=[];foreach($group as$item)$seen[mb_strtolower(mb_substr($item['first'],0,$prefixLength),'UTF-8')]=true;if(count($seen)===count($group))break;$prefixLength++;}}
        foreach($group as$id=>$item){$prefix=mb_substr($item['first'],0,min($prefixLength,mb_strlen($item['first'],'UTF-8')),'UTF-8');$result[$id]=trim($item['last']).' '.$prefix.'.';}
    }
    return$result;
}
