<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/XlsxReader.php';
apiRequirePermission('progop.create', 'progop.manage_all');

const IMPORT_MAX_BYTES = 5242880;
const IMPORT_HEADERS = ['Data', 'Sala', 'Pacient', 'Diagnostic', 'Tip operatie', 'Medic', 'Observatii', 'Extra', 'Motiv extra'];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        handleImportGet($db);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        importError('Metoda nu este acceptata.', 405);
    }
    apiVerifyCsrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'preview') {
        previewImport($db);
    }
    $input = apiInput();
    $action = (string) ($input['action'] ?? '');
    if ($action === 'preview_rows') {
        previewImportRows($db, $input);
    }
    if ($action === 'commit') {
        commitImport($db, $input);
    }
    if ($action === 'undo') {
        undoImport($db, $input);
    }
    importError('Actiune necunoscuta.');
} catch (DomainException $error) {
    if ($db->inTransaction()) $db->rollBack();
    importError($error->getMessage(), 409);
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    apiLogException($error, 'progop-import');
    importError('Importul nu a putut fi finalizat.', 500);
}

function handleImportGet(PDO $db): never
{
    $action = (string) ($_GET['action'] ?? 'last');
    if ($action === 'template') {
        downloadImportTemplate($db);
    }
    if ($action === 'last') {
        global $apiUser;
        $statement = $db->prepare(
            'SELECT id,source_filename,total_rows,imported_rows,skipped_rows,status,created_at,undone_at
             FROM operation_imports WHERE user_id=:user ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(['user' => $apiUser['id']]);
        importSuccess(['lastImport' => $statement->fetch() ?: null]);
    }
    importError('Actiune necunoscuta.');
}

function downloadImportTemplate(PDO $db): never
{
    $rooms = importRooms($db);
    require_once dirname(__DIR__, 2) . '/app/lib/xlsxwriter.class.php';
    $writer = new XLSXWriter();
    $writer->setAuthor('Intern Maternitatea Giulesti');
    $types = array_fill_keys(IMPORT_HEADERS, 'string');
    $headerStyle = ['font-style' => 'bold', 'fill' => '#2274A5', 'color' => '#FFFFFF', 'border' => 'bottom', 'freeze_rows' => 1, 'widths' => [14, 12, 28, 34, 28, 30, 35, 10, 30]];
    $writer->writeSheetHeader('Import operatii', $types, $headerStyle);
    $writer->writeSheetRow('Import operatii', ['2026-09-01', $rooms[0], 'EXEMPLU - stergeti acest rand', 'Diagnostic exemplu', 'Cezariana', 'NUME MEDIC DIN LISTA', 'Observatii optionale', 'NU', '']);
    $writer->writeSheetHeader('Instructiuni', ['Camp' => 'string', 'Completare' => 'string'], ['font-style' => 'bold', 'fill' => '#42B883', 'color' => '#FFFFFF', 'widths' => [24, 95]]);
    $instructions = [
        ['Data', 'Obligatoriu. Format recomandat AAAA-LL-ZZ (de exemplu 2026-09-01).'],
        ['Sala', 'Obligatoriu. Una dintre valorile: ' . implode(', ', $rooms) . '.'],
        ['Pacient', 'Obligatoriu. Numele pacientului.'],
        ['Diagnostic', 'Optional.'],
        ['Tip operatie', 'Obligatoriu. Trebuie sa existe deja in nomenclatorul aplicatiei.'],
        ['Medic', 'Obligatoriu. Numele trebuie sa coincida cu un medic OG activ din aplicatie.'],
        ['Observatii', 'Optional. Maximum 1000 de caractere.'],
        ['Extra', 'Optional. DA/NU. Daca data este in trecut, operatia va fi marcata automat EXTRA.'],
        ['Motiv extra', 'Optional. Se completeaza numai pentru operatiile marcate EXTRA.'],
        ['Important', 'Stergeti randul demonstrativ inainte de import. Fisierul va fi verificat si afisat pentru confirmare inainte de salvare.'],
    ];
    foreach ($instructions as $row) $writer->writeSheetRow('Instructiuni', $row, ['height' => 24, 'valign' => 'center']);
    $path = tempnam(sys_get_temp_dir(), 'model_progop_');
    if ($path === false) throw new RuntimeException('Fisierul temporar nu a putut fi creat.');
    $writer->writeToFile($path);
    apiAudit($db, 'progop.import_template_downloaded', 'operation_import', null, null, ['file' => basename($path)]);
    header_remove('Content-Type');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="model_import_program_operator.xlsx"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    @unlink($path);
    exit;
}

function previewImport(PDO $db): never
{
    global $apiUser;
    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) throw new DomainException('Alege un fisier XLSX.');
    $file = $_FILES['file'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new DomainException('Fisierul nu a putut fi incarcat.');
    if ((int) ($file['size'] ?? 0) > IMPORT_MAX_BYTES) throw new DomainException('Fisierul depaseste limita de 5 MB.');
    $name = basename((string) ($file['name'] ?? 'import.xlsx'));
    if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'xlsx') throw new DomainException('Este acceptat doar formatul XLSX.');

    $rawRows = \App\XlsxReader::readFirstSheet((string) $file['tmp_name']);
    if (!$rawRows) throw new DomainException('Fisierul nu contine randuri.');
    $headerMap = mapImportHeaders(array_shift($rawRows));
    $rows = [];
    $seen = [];
    foreach ($rawRows as $index => $raw) {
        $sourceRow = $index + 2;
        $values = importRowValues($raw, $headerMap);
        if (count(array_filter($values, fn($value) => trim((string) $value) !== '')) === 0) continue;
        $rows[] = validateImportRow($db, $values, $sourceRow, $seen);
    }
    if (!$rows) throw new DomainException('Fisierul nu contine operatii de importat.');
    if (count($rows) > 1000) throw new DomainException('Un import poate contine maximum 1000 de operatii.');

    $token = storeImportPreview($name, $rows);
    apiAudit($db, 'progop.import_previewed', 'operation_import', null, null, ['filename' => $name, 'rows' => count($rows)]);
    importSuccess(importPreviewResponse($rows, $token, $name));
}

function previewImportRows(PDO $db, array $input): never
{
    $incoming = $input['rows'] ?? null;
    if (!is_array($incoming) || !$incoming) throw new DomainException('Trimite cel putin un rand pentru validare.');
    if (count($incoming) > 1000) throw new DomainException('Un import poate contine maximum 1000 de operatii.');
    $rows = [];
    $seen = [];
    foreach ($incoming as $index => $item) {
        if (!is_array($item)) throw new DomainException('Fiecare rand trebuie sa fie un obiect.');
        $values = [
            'Data' => (string)($item['date'] ?? ''),
            'Sala' => (string)($item['room'] ?? ''),
            'Pacient' => (string)($item['patient_name'] ?? ''),
            'Diagnostic' => (string)($item['diagnostic'] ?? ''),
            'Tip operatie' => (string)($item['operation_type'] ?? ''),
            'Medic' => (string)($item['doctor'] ?? ''),
            'Observatii' => (string)($item['notes'] ?? ''),
            'Extra' => !empty($item['is_extra']) ? 'DA' : 'NU',
            'Motiv extra' => (string)($item['extra_reason'] ?? ''),
        ];
        $rows[] = validateImportRow($db, $values, (int)($item['source_row'] ?? ($index + 2)), $seen);
    }
    $filename = mb_substr(trim((string)($input['source_filename'] ?? 'import_api.xlsx')), 0, 255) ?: 'import_api.xlsx';
    $token = storeImportPreview($filename, $rows);
    apiAudit($db, 'progop.import_api_previewed', 'operation_import', null, null, ['filename'=>$filename,'rows'=>count($rows)]);
    importSuccess(importPreviewResponse($rows, $token, $filename));
}

function storeImportPreview(string $filename, array $rows): string
{
    global $apiUser;
    $token = bin2hex(random_bytes(24));
    $directory = dirname(__DIR__, 2) . '/storage/imports';
    if (!is_dir($directory)) mkdir($directory, 0775, true);
    cleanupImportPreviews($directory);
    $payload = ['user_id'=>(int)$apiUser['id'],'filename'=>$filename,'created_at'=>time(),'rows'=>$rows];
    file_put_contents($directory.'/preview_'.$token.'.json', json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR), LOCK_EX);
    return $token;
}

function commitImport(PDO $db, array $input): never
{
    global $apiUser;
    $token = preg_replace('/[^a-f0-9]/', '', (string) ($input['previewToken'] ?? ''));
    if (strlen($token) !== 48) throw new DomainException('Previzualizarea importului nu este valida.');
    $path = dirname(__DIR__, 2) . '/storage/imports/preview_' . $token . '.json';
    if (!is_file($path)) throw new DomainException('Previzualizarea a expirat. Incarca din nou fisierul.');
    $preview = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if ((int) ($preview['user_id'] ?? 0) !== (int) $apiUser['id']) throw new DomainException('Previzualizarea apartine altui utilizator.');
    if ((int) ($preview['created_at'] ?? 0) < time() - 3600) throw new DomainException('Previzualizarea a expirat.');
    $rows = is_array($preview['rows'] ?? null) ? $preview['rows'] : [];
    $errors = array_filter($rows, fn($row) => !empty($row['errors']));
    if ($errors) throw new DomainException('Corecteaza randurile cu erori inainte de import.');

    $db->beginTransaction();
    $batch = $db->prepare('INSERT INTO operation_imports(user_id,source_filename,total_rows) VALUES(:user,:filename,:total)');
    $batch->execute(['user' => $apiUser['id'], 'filename' => mb_substr((string) $preview['filename'], 0, 255), 'total' => count($rows)]);
    $importId = (int) $db->lastInsertId();
    $imported = 0;
    $skipped = 0;
    $dates = [];
    foreach ($rows as $row) {
        if ((int) $row['doctor_id'] === 0 && isNoDoctorImportLabel((string) $row['doctor'])) {
            $row['doctor_id'] = ensureNoDoctorPerson($db);
        }
        if (!empty($row['duplicate']) || importDuplicateExists($db, $row)) { $skipped++; continue; }
        assertImportRoomAvailable($db, $row['date'], (int) $row['room_id']);
        $order = $db->prepare('SELECT COALESCE(MAX(display_order),0)+1 FROM operations WHERE scheduled_date=:date AND room_id=:room AND status<>"deleted"');
        $order->execute(['date' => $row['date'], 'room' => $row['room_id']]);
        $isExtra = !empty($row['extra']) || new DateTimeImmutable($row['date']) < new DateTimeImmutable('today');
        $insert = $db->prepare('INSERT INTO operations(scheduled_date,room_id,display_order,patient_name,diagnostic,operation_type_id,scheduled_doctor_id,created_by,custom_notes,is_extra,extra_reason) VALUES(:date,:room,:display_order,:patient,:diagnostic,:type,:doctor,:user,:notes,:extra,:extra_reason)');
        $insert->execute(['date'=>$row['date'],'room'=>$row['room_id'],'display_order'=>(int)$order->fetchColumn(),'patient'=>$row['patient'],'diagnostic'=>$row['diagnostic'],'type'=>$row['type_id'],'doctor'=>$row['doctor_id'],'user'=>$apiUser['id'],'notes'=>$row['notes'],'extra'=>$isExtra?1:0,'extra_reason'=>$isExtra?($row['extra_reason'] ?: 'Importata ulterior'):null]);
        $operationId = (int) $db->lastInsertId();
        $db->prepare('INSERT INTO operation_import_items(import_id,operation_id,source_row) VALUES(:import_id,:operation_id,:source_row)')->execute(['import_id'=>$importId,'operation_id'=>$operationId,'source_row'=>$row['source_row']]);
        $eventData = ['import_id'=>$importId,'source_row'=>$row['source_row'],'date'=>$row['date'],'room'=>$row['room'],'patient'=>$row['patient'],'doctor'=>$row['doctor'],'operation_type'=>$row['operation_type']];
        $db->prepare('INSERT INTO operation_events(operation_id,event_type,performed_by,after_data) VALUES(:operation,"created",:user,:after_data)')->execute(['operation'=>$operationId,'user'=>$apiUser['id'],'after_data'=>json_encode($eventData,JSON_UNESCAPED_UNICODE)]);
        apiAudit($db, 'progop.operation_imported', 'operation', $operationId, null, $eventData);
        $dates[] = $row['date'];
        $imported++;
    }
    $db->prepare('UPDATE operation_imports SET imported_rows=:imported,skipped_rows=:skipped WHERE id=:id')->execute(['imported'=>$imported,'skipped'=>$skipped,'id'=>$importId]);
    apiAudit($db, 'progop.import_committed', 'operation_import', $importId, null, ['filename'=>$preview['filename'],'imported'=>$imported,'skipped'=>$skipped]);
    $db->commit();
    @unlink($path);
    importSuccess(['importId'=>$importId,'imported'=>$imported,'skipped'=>$skipped,'months'=>array_values(array_unique(array_map(fn($date)=>substr($date,0,7),$dates)))], "Au fost importate {$imported} operatii.");
}

function undoImport(PDO $db, array $input): never
{
    global $apiUser, $apiPermissions;
    $id = (int) ($input['importId'] ?? 0);
    $statement = $db->prepare('SELECT * FROM operation_imports WHERE id=:id');
    $statement->execute(['id'=>$id]);
    $batch = $statement->fetch();
    if (!$batch || $batch['status'] !== 'completed') throw new DomainException('Importul nu poate fi anulat.');
    $canManageAll = in_array('progop.manage_all', $apiPermissions, true);
    if ((int)$batch['user_id'] !== (int)$apiUser['id'] && !$canManageAll) throw new DomainException('Poti anula doar importurile tale.');
    $items = $db->prepare('SELECT o.* FROM operation_import_items oi JOIN operations o ON o.id=oi.operation_id WHERE oi.import_id=:id');
    $items->execute(['id'=>$id]);
    $operations = $items->fetchAll();
    $db->beginTransaction();
    $undone = 0;
    $skipped = 0;
    foreach ($operations as $operation) {
        if ($operation['status'] === 'deleted') { $skipped++; continue; }
        $changed = $db->prepare('SELECT COUNT(*) FROM operation_events WHERE operation_id=:id AND event_type<>"created"');
        $changed->execute(['id'=>$operation['id']]);
        if ((int)$changed->fetchColumn() > 0) { $skipped++; continue; }
        $db->prepare('UPDATE operations SET status="deleted",deleted_at=NOW() WHERE id=:id')->execute(['id'=>$operation['id']]);
        $after = ['status'=>'deleted','reason'=>'undo_import','import_id'=>$id];
        $db->prepare('INSERT INTO operation_events(operation_id,event_type,performed_by,before_data,after_data) VALUES(:operation,"deleted",:user,:before_data,:after_data)')->execute(['operation'=>$operation['id'],'user'=>$apiUser['id'],'before_data'=>json_encode($operation,JSON_UNESCAPED_UNICODE),'after_data'=>json_encode($after,JSON_UNESCAPED_UNICODE)]);
        apiAudit($db, 'progop.operation_import_undone', 'operation', (int)$operation['id'], $operation, $after);
        $undone++;
    }
    $db->prepare('UPDATE operation_imports SET status="undone",undone_at=NOW(),undone_by=:user WHERE id=:id')->execute(['user'=>$apiUser['id'],'id'=>$id]);
    apiAudit($db, 'progop.import_undone', 'operation_import', $id, $batch, ['undone'=>$undone,'skipped_modified'=>$skipped]);
    $db->commit();
    importSuccess(['undone'=>$undone,'skippedModified'=>$skipped], "Au fost eliminate {$undone} operatii importate.");
}

function validateImportRow(PDO $db, array $values, int $sourceRow, array &$seen): array
{
    $errors = [];
    $date = parseImportDate($values['Data'] ?? '');
    if (!$date) $errors[] = 'Data nu este valida.';
    $room = trim((string)($values['Sala'] ?? ''));
    $roomStatement = $db->prepare('SELECT id FROM operation_rooms WHERE name=:name AND active=1');
    $roomStatement->execute(['name'=>$room]);
    $roomId = (int)$roomStatement->fetchColumn();
    if (!$roomId || !in_array($room, importRooms($db), true)) $errors[] = 'Sala nu este valida.';
    $patient = trim((string)($values['Pacient'] ?? ''));
    if ($patient === '') $errors[] = 'Pacientul este obligatoriu.';
    $typeName = trim((string)($values['Tip operatie'] ?? ''));
    $type = $db->prepare('SELECT id,name FROM operation_types WHERE active=1 AND LOWER(name)=LOWER(:name) LIMIT 1');
    $type->execute(['name'=>$typeName]);
    $typeRow = $type->fetch();
    if (!$typeRow) $errors[] = 'Tipul operatiei nu exista in nomenclator.';
    $doctorName = trim((string)($values['Medic'] ?? ''));
    $isNoDoctor = isNoDoctorImportLabel($doctorName);
    $doctor = $db->prepare('SELECT id,display_name FROM people WHERE specialty="OG" AND LOWER(TRIM(display_name))=LOWER(TRIM(:name)) AND (active=1 OR :placeholder=1) ORDER BY id');
    $doctor->execute(['name'=>$doctorName,'placeholder'=>$isNoDoctor ? 1 : 0]);
    $doctorMatches = $doctor->fetchAll();
    $doctorRow = count($doctorMatches) === 1 ? $doctorMatches[0] : null;
    if (!$doctorMatches && !$isNoDoctor) $errors[] = 'Medicul OG nu a fost gasit.';
    if (count($doctorMatches) > 1) $errors[] = 'Exista mai multi medici activi cu acest nume. Corecteaza lista din Administrare.';
    if ($date && $roomId) {
        $blocked = $db->prepare('SELECT COUNT(*) FROM room_blocks WHERE block_date=:date AND room_id=:room AND removed_at IS NULL');
        $blocked->execute(['date'=>$date,'room'=>$roomId]);
        if ((int)$blocked->fetchColumn() > 0) $errors[] = 'Sala este blocata in aceasta zi.';
    }
    $row = ['source_row'=>$sourceRow,'date'=>$date ?: trim((string)($values['Data']??'')),'room'=>$room,'room_id'=>$roomId,'patient'=>mb_substr($patient,0,220),'diagnostic'=>mb_substr(trim((string)($values['Diagnostic']??'')),0,2000),'operation_type'=>$typeRow['name']??$typeName,'type_id'=>(int)($typeRow['id']??0),'doctor'=>$doctorRow['display_name']??($isNoDoctor ? 'FĂRĂ MEDIC' : $doctorName),'doctor_id'=>(int)($doctorRow['id']??0),'notes'=>mb_substr(trim((string)($values['Observatii']??'')),0,1000),'extra'=>importBoolean($values['Extra']??''),'extra_reason'=>mb_substr(trim((string)($values['Motiv extra']??'')),0,255),'errors'=>$errors,'duplicate'=>false];
    if (!$errors) {
        $key = importDuplicateKey($row);
        $row['duplicate'] = isset($seen[$key]) || importDuplicateExists($db, $row);
        $seen[$key] = true;
    }
    return $row;
}

function importRooms(PDO $db): array
{
    static $rooms = null;
    if (is_array($rooms)) return $rooms;
    $rooms = array_map('strval', $db->query('SELECT name FROM operation_rooms WHERE active=1 ORDER BY display_order,id LIMIT 5')->fetchAll(PDO::FETCH_COLUMN));
    if (!$rooms) throw new DomainException('Nu exista nicio sala activa configurata.');
    if (count($rooms) > 4) throw new DomainException('Sunt active mai mult de patru sali. Pastreaza maximum patru sali active.');
    return $rooms;
}

function importDuplicateExists(PDO $db, array $row): bool
{
    $statement=$db->prepare('SELECT COUNT(*) FROM operations WHERE scheduled_date=:date AND room_id=:room AND scheduled_doctor_id=:doctor AND operation_type_id=:type AND LOWER(patient_name)=LOWER(:patient) AND status<>"deleted"');
    $statement->execute(['date'=>$row['date'],'room'=>$row['room_id'],'doctor'=>$row['doctor_id'],'type'=>$row['type_id'],'patient'=>$row['patient']]);
    return (int)$statement->fetchColumn()>0;
}

function importDuplicateKey(array $row): string { return mb_strtolower(implode('|',[$row['date'],$row['room_id'],$row['doctor_id'],$row['type_id'],$row['patient']])); }
function importBoolean(mixed $value): bool { return in_array(mb_strtolower(trim((string)$value)), ['da','1','true','yes','x'], true); }
function assertImportRoomAvailable(PDO $db,string $date,int $room): void { $s=$db->prepare('SELECT COUNT(*) FROM room_blocks WHERE block_date=:date AND room_id=:room AND removed_at IS NULL');$s->execute(['date'=>$date,'room'=>$room]);if((int)$s->fetchColumn()>0)throw new DomainException("Sala este blocata la data {$date}."); }

function isNoDoctorImportLabel(string $value): bool { return normalizeImportHeader($value) === 'faramedic'; }
function ensureNoDoctorPerson(PDO $db): int
{
    $find = $db->prepare('SELECT id FROM people WHERE specialty="OG" AND LOWER(TRIM(display_name))=LOWER("FĂRĂ MEDIC") ORDER BY id LIMIT 1');
    $find->execute();
    $id = (int) $find->fetchColumn();
    if ($id > 0) return $id;
    $insert = $db->prepare('INSERT INTO people(first_name,last_name,display_name,specialty,professional_category,active,guard_eligible,notes) VALUES("Medic","FĂRĂ","FĂRĂ MEDIC","OG","Placeholder import",0,0,"Creat automat pentru operatii importate fara medic specificat")');
    $insert->execute();
    return (int) $db->lastInsertId();
}

function mapImportHeaders(array $headers): array
{
    $map=[];foreach($headers as $index=>$header)$map[normalizeImportHeader((string)$header)]=$index;
    $aliases=['data'=>'Data','sala'=>'Sala','pacient'=>'Pacient','numepacient'=>'Pacient','diagnostic'=>'Diagnostic','tipoperatie'=>'Tip operatie','operatie'=>'Tip operatie','medic'=>'Medic','medicoperator'=>'Medic','observatii'=>'Observatii','extra'=>'Extra','motivextra'=>'Motiv extra'];
    $result=[];foreach($aliases as $normalized=>$canonical)if(isset($map[$normalized]))$result[$canonical]=$map[$normalized];
    foreach(['Data','Sala','Pacient','Tip operatie','Medic'] as $required)if(!isset($result[$required]))throw new DomainException("Lipseste coloana obligatorie: {$required}.");
    return $result;
}
function importRowValues(array $row,array $map): array { $values=[];foreach(IMPORT_HEADERS as $header)$values[$header]=isset($map[$header])?(string)($row[$map[$header]]??''):'';return$values; }
function normalizeImportHeader(string $value): string { $value=mb_strtolower(trim($value));$value=strtr($value,['ă'=>'a','â'=>'a','î'=>'i','ș'=>'s','ş'=>'s','ț'=>'t','ţ'=>'t']);return preg_replace('/[^a-z0-9]+/','',$value)??''; }
function parseImportDate(string $value): ?string { $value=trim($value);if($value==='')return null;if(is_numeric($value)){try{return(new DateTimeImmutable('1899-12-30'))->modify('+'.((int)$value).' days')->format('Y-m-d');}catch(Throwable){return null;}}foreach(['Y-m-d','d.m.Y','d/m/Y','d-m-Y']as$format){$date=DateTimeImmutable::createFromFormat('!'.$format,$value);if($date&&$date->format($format)===$value)return$date->format('Y-m-d');}return null; }

function cleanupImportPreviews(string $directory): void { foreach(glob($directory.'/preview_*.json')?:[]as$file)if(filemtime($file)<time()-3600)@unlink($file); }
function importPreviewResponse(array $rows,string $token,string $filename): array { $valid=count(array_filter($rows,fn($r)=>empty($r['errors'])&&!$r['duplicate']));$errors=count(array_filter($rows,fn($r)=>!empty($r['errors'])));$duplicates=count(array_filter($rows,fn($r)=>!empty($r['duplicate'])));return['previewToken'=>$token,'filename'=>$filename,'rows'=>$rows,'summary'=>['total'=>count($rows),'valid'=>$valid,'errors'=>$errors,'duplicates'=>$duplicates]]; }
function importSuccess(array $data=[],?string $message=null): never { echo json_encode(['success'=>true,'data'=>$data,'message'=>$message],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit; }
function importError(string $message,int $status=400): never { http_response_code($status);echo json_encode(['success'=>false,'data'=>null,'message'=>$message],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit; }
