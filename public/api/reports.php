<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Statistics.php';
apiRequirePlatformAdministrator();

if (!Statistics::canGenerate($db, (int)$apiUser['id'])) {
    apiError('Rapoartele centralizate sunt disponibile numai Administratorului platformei si Superuserului.', 403);
}

$kind = (string)($_GET['report'] ?? 'guards');
if (!in_array($kind, ['guards','progop','daily_activity'], true)) apiError('Tipul raportului nu este valid.');
$dailyDate = (new DateTimeImmutable('today'))->modify('-1 day')->format('Y-m-d');
$periodType = (string)($_GET['period'] ?? 'monthly');
if (!in_array($periodType, ['monthly','annual'], true)) apiError('Perioada raportului nu este valida.');
$year = (int)($_GET['year'] ?? date('Y'));
$month = $periodType === 'monthly' ? (int)($_GET['month'] ?? date('n')) : null;
$period = null;
try {
    $period = Statistics::period($year, $month);
} catch (DomainException $error) {
    apiError($error->getMessage());
}
$personId = (int)($_GET['person_id'] ?? 0) ?: null;
$specialty = $kind === 'guards' ? trim((string)($_GET['specialty'] ?? '')) : null;
$specialty = $specialty === '' ? null : $specialty;
try {
    $report = match ($kind) {
        'guards' => Statistics::guards($db, $period, $specialty, $personId),
        'progop' => Statistics::operations($db, $period, $personId),
        default => Statistics::dailyActivity($db, $dailyDate),
    };
} catch (DomainException $error) {
    apiError($error->getMessage());
}
$format = strtolower((string)($_GET['format'] ?? 'preview'));

if ($format === 'preview') {
    apiSuccess(['report'=>$report]);
}
if (!in_array($format, ['pdf','xlsx'], true)) apiError('Formatul raportului nu este valid.');

$baseName = $kind === 'daily_activity' ? 'raport_activitate_'.$dailyDate : 'raport_'.$kind.'_'.$periodType.'_'.$year.($month === null ? '' : '_'.sprintf('%02d',$month));
apiAudit($db, 'reports.statistics_generated', 'statistics_report', null, null, [
    'report'=>$kind,'period'=>$periodType,'year'=>$year,'month'=>$month,'specialty'=>$specialty,'person_id'=>$personId,'format'=>$format,
]);

if ($format === 'pdf') {
    $contents = buildStatisticsPdf($report);
    header('Content-Type: application/pdf', true);
    header('Content-Disposition: attachment; filename="'.$baseName.'.pdf"', true);
    header('Content-Length: '.strlen($contents), true);
    echo $contents;
    exit;
}

$contents = buildStatisticsXlsx($report);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', true);
header('Content-Disposition: attachment; filename="'.$baseName.'.xlsx"', true);
header('Content-Length: '.strlen($contents), true);
echo $contents;
exit;

function buildStatisticsPdf(array $report): string
{
    require_once dirname(__DIR__, 2) . '/app/PdfDocument.php';
    $guards = $report['kind'] === 'guards';
    $daily = $report['kind'] === 'daily_activity';
    $title = ($guards ? 'Raport garzi - ' : ($daily ? 'Activitate bloc operator - ' : 'Raport Program operator - ')) . $report['period']['label'];
    $pdf = createUnicodePdf($title);
    $pdf->SetAutoPageBreak(true, 9);
    $pdf->AddPage();
    $pdf->SetTextColor(25, 51, 65);
    $pdf->SetFont('dejavusans', 'B', 15);
    $pdf->Cell(0, 8, $title, 0, 1, 'L');
    $pdf->SetFont('dejavusans', '', 7.5);
    $pdf->SetTextColor(91, 112, 121);
    $pdf->Cell(0, 5, 'Generat la '.date('d.m.Y H:i').' | Date agregate, fara numele pacientelor', 0, 1, 'L');
    $pdf->Ln(2);

    $summaryLabels = $guards ? [
        'performed'=>'Efectuate','scheduled'=>'Programate','total'=>'Total','weekend'=>'Weekend','holidays'=>'Sarbatori',
        'changed'=>'Modificate','gaps'=>'Lipsa','coverage'=>'Acoperire %','consecutive'=>'Consecutive',
    ] : ($daily ? [
        'completed'=>'Operatii efectuate','extra'=>'Operatii extra','doctors'=>'Medici operatori','types'=>'Tipuri efectuate',
    ] : [
        'completed'=>'Efectuate','future'=>'Programate','unconfirmed'=>'Neconfirmate','cancelled'=>'Anulate','extra'=>'Extra',
        'guard_day'=>'In zile de garda','confirmation_rate'=>'Confirmare %','average_lead_days'=>'Avans mediu (zile)',
    ]);
    $summaryCells=[];
    foreach($summaryLabels as $key=>$label){if(array_key_exists($key,$report['summary'])&&$report['summary'][$key]!==null)$summaryCells[]=['label'=>$label,'value'=>$report['summary'][$key]];}
    $html='<table cellpadding="5" cellspacing="2"><tr>';
    foreach($summaryCells as $index=>$cell){if($index>0&&$index%4===0)$html.='</tr><tr>';$html.='<td style="background-color:#e8f2ee;color:#244f4a"><span style="font-size:7pt">'.reportEscape($cell['label']).'</span><br><b style="font-size:13pt">'.reportEscape((string)$cell['value']).'</b></td>';}
    $html.='</tr></table><br>';
    $pdf->writeHTML($html, true, false, true, false, '');

    if ($guards) {
        $rows=[];
        foreach($report['by_person'] as $person)$rows[]=[
            $person['name'],$person['specialty'],$person['performed'],$person['scheduled'],$person['total'],$person['weekend'],$person['holidays'],$person['exchanges'],$person['given'],$person['taken'],
        ];
        writeStatisticsPdfTable($pdf,'Statistica pe medici',['Medic','Spec.','Efect.','Prog.','Total','Weekend','Sarb.','Schimb','Cedate','Preluate'],$rows,[52,14,14,14,14,17,14,15,15,16]);
        $trend=[];foreach($report['by_month'] as $row)$trend[]=[$row['month'],$row['performed'],$row['scheduled'],$row['total']];
        writeStatisticsPdfTable($pdf,'Evolutie lunara',['Luna','Efectuate','Programate','Total'],$trend,[42,28,28,28]);
        if($report['changes']){$changes=[];foreach(array_slice($report['changes'],0,100) as $row)$changes[]=[$row['guard_date'],$row['specialty'].' L'.$row['line_no'],reportChangeLabel($row['change_type']),$row['from_name'],$row['to_name'],$row['initiated_by_name']];writeStatisticsPdfTable($pdf,'Schimburi, cedari si preluari',['Data','Linie','Tip','De la','Catre','Operat de'],$changes,[22,18,24,48,48,48]);}
    } elseif ($daily) {
        $rows=[];foreach($report['by_person'] as $person)$rows[]=[$person['name'],$person['completed'],$person['extra'],$person['type_breakdown']];
        writeStatisticsPdfTable($pdf,'Operatii efectuate pe medici',['Medic','Total','Extra','Tipuri efectuate'],$rows,[62,18,18,102]);
        $types=[];foreach($report['by_type'] as $row)$types[]=[$row['name'],$row['completed']];
        writeStatisticsPdfTable($pdf,'Operatii efectuate pe categorii',['Tip operatie','Numar'],$types,[120,30]);
        $rooms=[];foreach($report['by_room'] as $row)$rooms[]=[$row['name'],$row['completed']];
        writeStatisticsPdfTable($pdf,'Operatii efectuate pe sali',['Sala','Numar'],$rooms,[90,30]);
    } else {
        $rows=[];
        foreach($report['by_person'] as $person)$rows[]=[$person['name'],$person['completed'],$person['future'],$person['unconfirmed'],$person['cancelled'],$person['extra'],$person['guard_day'],$person['main_type']];
        writeStatisticsPdfTable($pdf,'Statistica pe medici',['Medic','Efect.','Prog.','Neconf.','Anul.','Extra','In garda','Tip principal'],$rows,[58,15,15,17,15,15,18,62]);
        $types=[];foreach($report['by_type'] as $row)$types[]=[$row['name'],$row['completed'],$row['total'],$row['unconfirmed'],$row['cancelled']];
        writeStatisticsPdfTable($pdf,'Tipuri de operatii',['Tip operatie','Efectuate','Total','Neconfirmate','Anulate'],$types,[100,25,20,28,22]);
        $rooms=[];foreach($report['by_room'] as $row)$rooms[]=[$row['name'],$row['completed'],$row['total'],$row['extra']];
        writeStatisticsPdfTable($pdf,'Distributie pe sali',['Sala','Efectuate','Total','Extra'],$rooms,[75,28,24,24]);
        $trend=[];foreach($report['by_month'] as $row)$trend[]=[$row['month'],$row['completed'],$row['unconfirmed'],$row['future'],$row['total']];
        writeStatisticsPdfTable($pdf,'Evolutie lunara',['Luna','Efectuate','Neconfirmate','Programate','Total'],$trend,[42,28,32,28,24]);
    }
    return $pdf->Output('', 'S');
}

function writeStatisticsPdfTable(TCPDF $pdf,string $title,array $headers,array $rows,array $widths): void
{
    if (($pdf->getPageHeight() - $pdf->GetY()) < 38) {
        $pdf->AddPage();
    }
    $pdf->SetTextColor(25,51,65);$pdf->SetFont('dejavusans','B',10);$pdf->Cell(0,7,$title,0,1,'L');
    $widthTotal=max(1,array_sum($widths));
    $percentages=array_map(static fn($width): string => number_format(((float)$width/$widthTotal)*100,2,'.','').'%',$widths);
    $html='<table width="100%" border="1" cellpadding="3" cellspacing="0"><thead><tr style="background-color:#1f5660;color:#ffffff;font-weight:bold">';
    foreach($headers as $index=>$header)$html.='<th width="'.$percentages[$index].'">'.reportEscape($header).'</th>';
    $html.='</tr></thead><tbody>';
    foreach($rows as $rowIndex=>$row){$html.='<tr style="background-color:'.($rowIndex%2?'#f1f6f4':'#ffffff').';color:#18323d">';foreach($row as $index=>$value)$html.='<td width="'.$percentages[$index].'">'.reportEscape((string)$value).'</td>';$html.='</tr>';}
    if(!$rows)$html.='<tr><td colspan="'.count($headers).'">Nu exista date pentru perioada selectata.</td></tr>';
    $html.='</tbody></table><br>';
    $pdf->SetFont('dejavusans','',6.7);$pdf->writeHTML($html,true,false,true,false,'');
}

function buildStatisticsXlsx(array $report): string
{
    require_once dirname(__DIR__, 2) . '/app/lib/xlsxwriter.class.php';
    $writer=new XLSXWriter();
    $writer->setAuthor('Maternitatea Giulesti');
    $guards=$report['kind']==='guards';$daily=$report['kind']==='daily_activity';
    $summaryRows=[];
    foreach($report['summary'] as $key=>$value)if($value!==null)$summaryRows[]=[reportSummaryLabel($key,$guards),(string)$value];
    writeReportSheet($writer,'Sumar general',['Indicator'=>'string','Valoare'=>'string'],$summaryRows,[32,20]);
    if($guards){
        $people=[];foreach($report['by_person'] as $row)$people[]=[$row['name'],$row['specialty'],$row['category'],$row['performed'],$row['scheduled'],$row['total'],$row['weekend'],$row['holidays'],$row['initial'],$row['changed'],$row['exchanges'],$row['given'],$row['taken'],reportLineCounts($row['lines'])];
        writeReportSheet($writer,'Statistica medici',['Medic'=>'string','Specialitate'=>'string','Categorie'=>'string','Efectuate'=>'integer','Programate'=>'integer','Total'=>'integer','Weekend'=>'integer','Sarbatori'=>'integer','Initiale'=>'integer','Modificate'=>'integer','Schimburi'=>'integer','Cedate'=>'integer','Preluate'=>'integer','Linii'=>'string'],$people,[28,12,20,11,11,9,10,10,10,12,11,10,11,30]);
        $details=[];foreach($report['assignments'] as $row)$details[]=[$row['guard_date'],$row['specialty'],(int)$row['line_no'],$row['original_name'],$row['current_name'],reportStateLabel($row['assignment_state']),$row['period_status'],$row['day_type'],$row['holiday_name']??''];
        writeReportSheet($writer,'Detaliu garzi',['Data'=>'date','Specialitate'=>'string','Linie'=>'integer','Medic initial'=>'string','Medic final'=>'string','Stare'=>'string','Perioada'=>'string','Tip zi'=>'string','Sarbatoare'=>'string'],$details,[13,12,8,28,28,16,14,16,26]);
        $changes=[];foreach($report['changes'] as $row)$changes[]=[$row['guard_date'],$row['specialty'],(int)$row['line_no'],reportChangeLabel($row['change_type']),$row['from_name'],$row['to_name'],$row['initiated_by_name'],$row['created_at'],$row['reason']??''];
        writeReportSheet($writer,'Schimburi si preluari',['Data garda'=>'date','Specialitate'=>'string','Linie'=>'integer','Tip'=>'string','De la'=>'string','Catre'=>'string','Operat de'=>'string','Data modificarii'=>'datetime','Motiv'=>'string'],$changes,[13,12,8,18,28,28,28,20,35]);
        $specialDays=[];foreach($report['assignments'] as $row)if($row['day_type']!=='Zi lucratoare')$specialDays[]=[$row['guard_date'],$row['day_type'],$row['specialty'],(int)$row['line_no'],$row['current_name'],$row['holiday_name']??''];
        writeReportSheet($writer,'Weekend si sarbatori',['Data'=>'date','Tip zi'=>'string','Specialitate'=>'string','Linie'=>'integer','Medic'=>'string','Sarbatoare'=>'string'],$specialDays,[13,18,12,8,30,30]);
        $trend=[];foreach($report['by_month'] as $row)$trend[]=[$row['month'],$row['performed'],$row['scheduled'],$row['total']];
        writeReportSheet($writer,'Acoperire lunara',['Luna'=>'string','Efectuate'=>'integer','Programate'=>'integer','Total'=>'integer'],$trend,[14,12,12,10]);
    }elseif($daily){
        $people=[];foreach($report['by_person'] as $row)$people[]=[$row['name'],$row['completed'],$row['extra'],$row['type_breakdown']];
        writeReportSheet($writer,'Activitate medici',['Medic'=>'string','Operatii efectuate'=>'integer','Extra'=>'integer','Tipuri efectuate'=>'string'],$people,[32,18,10,55]);
        $types=[];foreach($report['by_type'] as $row)$types[]=[$row['name'],$row['completed']];
        writeReportSheet($writer,'Tipuri efectuate',['Tip operatie'=>'string','Numar'=>'integer'],$types,[45,12]);
        $rooms=[];foreach($report['by_room'] as $row)$rooms[]=[$row['name'],$row['completed']];
        writeReportSheet($writer,'Sali',['Sala'=>'string','Numar'=>'integer'],$rooms,[25,12]);
    }else{
        $people=[];foreach($report['by_person'] as $row)$people[]=[$row['name'],$row['category'],$row['completed'],$row['future'],$row['unconfirmed'],$row['cancelled'],$row['extra'],$row['guard_day'],$row['total'],$row['main_type']];
        writeReportSheet($writer,'Statistica medici',['Medic'=>'string','Categorie'=>'string','Efectuate'=>'integer','Programate'=>'integer','Neconfirmate'=>'integer','Anulate'=>'integer','Extra'=>'integer','In zile de garda'=>'integer','Total'=>'integer','Tip principal'=>'string'],$people,[30,22,11,11,13,10,9,16,9,28]);
        $allTypes=[];foreach($report['by_person'] as $person)foreach(array_keys($person['types']) as $type)$allTypes[$type]=true;$allTypes=array_keys($allTypes);sort($allTypes,SORT_NATURAL|SORT_FLAG_CASE);
        $matrixHeaders=['Medic'=>'string'];foreach($allTypes as $type)$matrixHeaders[$type]='integer';$matrix=[];foreach($report['by_person'] as $person){$row=[$person['name']];foreach($allTypes as $type)$row[]=$person['types'][$type]??0;$matrix[]=$row;}
        writeReportSheet($writer,'Medici x tip operatie',$matrixHeaders,$matrix,array_merge([30],array_fill(0,count($allTypes),18)));
        $rooms=[];foreach($report['by_room'] as $row)$rooms[]=[$row['name'],$row['completed'],$row['total'],$row['extra']];writeReportSheet($writer,'Statistica sali',['Sala'=>'string','Efectuate'=>'integer','Total'=>'integer','Extra'=>'integer'],$rooms,[24,12,10,10]);
        $extras=[];foreach($report['extras'] as $row)$extras[]=[$row['date'],$row['doctor'],$row['type'],$row['room'],reportOperationStatus($row['status']),$row['created_by']];writeReportSheet($writer,'Operatii extra',['Data'=>'date','Medic'=>'string','Tip operatie'=>'string','Sala'=>'string','Status'=>'string','Programat de'=>'string'],$extras,[13,30,30,18,14,28]);
        $unconfirmed=[];foreach($report['unconfirmed'] as $row)$unconfirmed[]=[$row['date'],$row['doctor'],$row['type'],$row['room'],$row['created_by']];writeReportSheet($writer,'Neconfirmate',['Data'=>'date','Medic'=>'string','Tip operatie'=>'string','Sala'=>'string','Programat de'=>'string'],$unconfirmed,[13,30,30,18,28]);
        $activity=[];foreach($report['activity'] as $row)$activity[]=[$row['name'],$row['created'],$row['updated'],$row['moved'],$row['reordered'],$row['completed'],$row['cancelled'],$row['deleted'],$row['restored'],$row['total']];writeReportSheet($writer,'Activitate utilizatori',['Utilizator'=>'string','Adaugate'=>'integer','Modificate'=>'integer','Mutate'=>'integer','Reordonate'=>'integer','Finalizate'=>'integer','Anulate'=>'integer','Sterse'=>'integer','Restaurate'=>'integer','Total actiuni'=>'integer'],$activity,[30,11,12,10,12,12,10,10,12,13]);
        $types=[];foreach($report['by_type'] as $row)$types[]=[$row['name'],$row['completed'],$row['total'],$row['unconfirmed'],$row['cancelled']];writeReportSheet($writer,'Tipuri operatii',['Tip operatie'=>'string','Efectuate'=>'integer','Total'=>'integer','Neconfirmate'=>'integer','Anulate'=>'integer'],$types,[38,12,10,14,10]);
    }
    return $writer->writeToString();
}

function writeReportSheet(XLSXWriter $writer,string $sheet,array $headers,array $rows,array $widths): void
{
    $writer->writeSheetHeader($sheet,$headers,['widths'=>$widths,'freeze_rows'=>1,'auto_filter'=>true]);
    foreach($rows as $index=>$row)$writer->writeSheetRow($sheet,$row,['height'=>18,'font-size'=>9,'fill'=>$index%2?'#F1F6F4':'#FFFFFF','border'=>'all','border-style'=>'thin']);
}

function reportEscape(string $value): string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function reportLineCounts(array $lines): string{$parts=[];foreach($lines as$key=>$count)$parts[]=$key.': '.$count;return implode(', ',$parts);}
function reportChangeLabel(string $type): string{return match($type){'exchange'=>'Schimb','transfer'=>'Cedare / preluare','admin_correction'=>'Corectie administrativa','restore'=>'Restaurare',default=>$type};}
function reportStateLabel(string $state): string{return match($state){'initial'=>'Initiala','exchanged'=>'Schimbata','transferred'=>'Preluata','admin_corrected'=>'Corectata de admin','manual_corrected'=>'Corectata manual',default=>$state};}
function reportOperationStatus(string $status): string{return match($status){'completed'=>'Efectuata','scheduled'=>'Programata','cancelled'=>'Anulata','deleted'=>'Stearsa',default=>$status};}
function reportSummaryLabel(string $key,bool $guards): string{$labels=$guards?['total'=>'Total','performed'=>'Efectuate','scheduled'=>'Programate','weekend'=>'Weekend','holidays'=>'Sarbatori','initial'=>'Initiale','changed'=>'Modificate','gaps'=>'Lipsa','coverage'=>'Acoperire %','consecutive'=>'Garzi consecutive']:['total'=>'Total','completed'=>'Efectuate','future'=>'Programate','unconfirmed'=>'Neconfirmate','cancelled'=>'Anulate','deleted'=>'Sterse','extra'=>'Extra','guard_day'=>'In zile de garda','average_lead_days'=>'Avans mediu (zile)','confirmation_rate'=>'Confirmare %'];return$labels[$key]??$key;}
