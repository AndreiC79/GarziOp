<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
apiRequirePermission('guards.view');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    loadGuards($db);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError('Metoda nu este acceptata.', 405);
}

apiVerifyCsrf();
$input = apiInput();
$action = (string) ($input['action'] ?? '');

match ($action) {
    'save_assignment' => saveAssignment($db, $input),
    'delete_assignment' => deleteAssignment($db, $input),
    'move_assignment_line' => moveAssignmentLine($db, $input),
    'exchange_guards' => exchangeGuards($db, $input),
    'transfer_guard' => transferGuard($db, $input),
    'takeover_guard' => takeoverGuard($db, $input),
    'undo_guard_action' => undoGuardAction($db),
    default => apiError('Actiune necunoscuta.'),
};

function loadGuards(PDO $db): never
{
    global $apiPermissions, $apiUser;
    $fullGuardAccess = hasFullGuardAccess($db, (int)$apiUser['id']);
    $month = validMonth((string) ($_GET['month'] ?? date('Y-m')));
    $specialty = validSpecialty((string) ($_GET['specialty'] ?? 'OG'));
    $start = $month . '-01';
    $end = (new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
    $personId = $apiUser['person_id'] === null ? null : (int) $apiUser['person_id'];
    $canManageOwnSpecialty = in_array('guards.own_changes', $apiPermissions, true)
        && $personId !== null && (string) ($apiUser['specialty'] ?? '') === $specialty;
    $lineConfiguration = guardLineConfiguration($db, $specialty, $month);

    $statement = $db->prepare(
        'SELECT ga.id, ga.guard_date, ga.specialty, ga.line_no, ga.assignment_state,ga.created_by_user_id,
                ga.original_person_id, original.display_name AS original_name,
                ga.current_person_id, current.display_name AS current_name,
                EXISTS(
                    SELECT 1 FROM guard_change_items gci
                    WHERE gci.assignment_id = ga.id
                      AND (:person_id_a IS NOT NULL)
                      AND (gci.from_person_id = :person_id_b OR gci.to_person_id = :person_id_c)
                ) AS user_involved,
                (ga.current_person_id=:current_person_id OR ga.original_person_id=:original_person_id
                 OR ga.created_by_user_id=:creator_user_id OR EXISTS(
                    SELECT 1 FROM guard_change_items involved
                    WHERE involved.assignment_id=ga.id
                      AND (involved.from_person_id=:history_person_a OR involved.to_person_id=:history_person_b)
                 )) AS can_user_modify
         FROM guard_assignments ga
         INNER JOIN guard_plan_versions gv ON gv.id = ga.version_id
         INNER JOIN guard_plans gp ON gp.current_version_id = gv.id
         INNER JOIN people original ON original.id = ga.original_person_id
         INNER JOIN people current ON current.id = ga.current_person_id
         WHERE ga.guard_date BETWEEN :date_from AND :date_to AND ga.specialty = :specialty
           AND ga.line_no <= :visible_line_count
         ORDER BY ga.guard_date, ga.line_no'
    );
    $statement->execute([
        'person_id_a' => $personId,
        'person_id_b' => $personId,
        'person_id_c' => $personId,
        'current_person_id' => $personId,
        'original_person_id' => $personId,
        'creator_user_id' => (int) $apiUser['id'],
        'history_person_a' => $personId,
        'history_person_b' => $personId,
        'date_from' => $start,
        'date_to' => $end,
        'specialty' => $specialty,
        'visible_line_count' => $lineConfiguration['line_count'],
    ]);

    $peopleStatement = $db->prepare(
        'SELECT p.id,p.first_name,p.last_name,p.display_name,p.professional_category,p.rotation_position,p.active,p.guard_eligible,r.position generation_position
         FROM people p LEFT JOIN og_line1_rotation r ON r.person_id=p.id
         WHERE p.specialty=:specialty AND p.active=1 AND p.guard_eligible=1
         ORDER BY CASE WHEN p.professional_category NOT LIKE "Rezident%" AND r.position IS NOT NULL THEN 0
                       WHEN p.professional_category NOT LIKE "Rezident%" THEN 1 ELSE 2 END,
                  r.position,p.last_name,p.first_name'
    );
    $peopleStatement->execute(['specialty' => $specialty]);

    $holidayStatement = $db->prepare(
        'SELECT holiday_date, name FROM holidays WHERE holiday_date BETWEEN :date_from AND :date_to ORDER BY holiday_date'
    );
    $holidayStatement->execute(['date_from' => $start, 'date_to' => $end]);

    $undoStatement = $db->prepare(
        'SELECT id, action_type, created_at FROM guard_undo_actions
         WHERE user_id = :user_id AND undone_at IS NULL ORDER BY id DESC LIMIT 1'
    );
    $undoStatement->execute(['user_id' => $apiUser['id']]);

    apiSuccess([
        'month' => $month,
        'specialty' => $specialty,
        'assignments' => array_map(static function (array $assignment) use ($canManageOwnSpecialty): array {
            if ($canManageOwnSpecialty) $assignment['can_user_modify'] = 1;
            return $assignment;
        }, $statement->fetchAll()),
        'people' => $peopleStatement->fetchAll(),
        'holidays' => $holidayStatement->fetchAll(),
        'canManage' => $fullGuardAccess || in_array('guards.admin_correction', $apiPermissions, true),
        'canAdd' => $fullGuardAccess || in_array('guards.admin_correction', $apiPermissions, true) || $canManageOwnSpecialty,
        'canEditOtherLines' => $canManageOwnSpecialty,
        'canOwnChanges' => in_array('guards.own_changes', $apiPermissions, true) && $personId !== null,
        'currentPersonId' => $personId,
        'lastUndo' => $undoStatement->fetch() ?: null,
        'lineCount' => $lineConfiguration['line_count'],
        'configuredLineCount' => $lineConfiguration['configured_line_count'],
        'extraLineOpen' => $lineConfiguration['extra_line_open'],
        'nextLockedLine' => $lineConfiguration['next_locked_line'],
        'lineConfig' => $lineConfiguration['lines'],
        'line1Protected' => $lineConfiguration['line1_protected'],
    ]);
}

function saveAssignment(PDO $db, array $input): never
{
    global $apiUser, $apiPermissions;
    $fullGuardAccess = hasFullGuardAccess($db, (int)$apiUser['id']);
    if (!$fullGuardAccess && !array_intersect(['guards.admin_correction','guards.own_changes'], $apiPermissions)) apiError('Nu ai dreptul necesar pentru aceasta actiune.', 403);
    $isAdmin = $fullGuardAccess || in_array('guards.admin_correction', $apiPermissions, true);
    $date = futureOrToday((string) ($input['date'] ?? ''));
    $specialty = validSpecialty((string) ($input['specialty'] ?? 'OG'));
    $lineConfiguration = guardLineConfiguration($db, $specialty, substr($date, 0, 7));
    $line = (int) ($input['line'] ?? 0);
    $personId = (int) ($input['person_id'] ?? 0);
    if (!$isAdmin && (string) ($apiUser['specialty'] ?? '') !== $specialty) {
        apiError('Poti modifica numai garzile specialitatii tale.', 403);
    }
    if ($line < 1 || $line > $lineConfiguration['line_count'] || $personId <= 0) {
        apiError('Linia sau medicul nu este valid.');
    }
    if (!$isAdmin && $apiUser['person_id'] !== null && $specialty === 'OG' && $line === 1 && $lineConfiguration['line1_protected']) {
        apiError('Linia 1 poate fi modificata de medici doar prin schimb sau preluare.', 403);
    }

    $personStatement = $db->prepare('SELECT id, display_name FROM people WHERE id = :id AND specialty = :specialty AND active = 1 AND guard_eligible = 1');
    $personStatement->execute(['id' => $personId, 'specialty' => $specialty]);
    $person = $personStatement->fetch();
    if (!$person) {
        apiError('Medicul selectat nu este activ pentru garzi in aceasta specialitate.');
    }

    ensureNoDailyConflict($db, $personId, $date, null, $specialty, $line);
    ensureNoConsecutiveGuard($db, $personId, $date);
    $db->beginTransaction();
    try {
        [, $versionId] = findOrCreateActivePlan($db, $date, $specialty, $line, (int) $apiUser['id']);
        $existing = assignmentBySlot($db, $versionId, $date, $specialty, $line, true);
        $before = $existing ? [snapshot($existing)] : [];

        if ($existing) {
            $db->prepare(
                'UPDATE guard_assignments SET current_person_id = :person_id, assignment_state = :assignment_state WHERE id = :id'
            )->execute([
                'person_id' => $personId,
                'assignment_state' => $isAdmin ? 'admin_corrected' : 'manual_corrected',
                'id' => $existing['id'],
            ]);
            $assignmentId = (int) $existing['id'];
        } else {
            $db->prepare(
                'INSERT INTO guard_assignments
                 (version_id, guard_date, specialty, line_no, original_person_id, current_person_id, assignment_state,created_by_user_id)
                 VALUES (:version_id, :guard_date, :specialty, :line_no, :original_person_id, :current_person_id, :assignment_state,:created_by_user_id)'
            )->execute([
                'version_id' => $versionId,
                'guard_date' => $date,
                'specialty' => $specialty,
                'line_no' => $line,
                'original_person_id' => $personId,
                'current_person_id' => $personId,
                'assignment_state' => $isAdmin ? 'admin_corrected' : 'initial',
                'created_by_user_id' => (int) $apiUser['id'],
            ]);
            $assignmentId = (int) $db->lastInsertId();
        }

        $after = [snapshot(assignmentById($db, $assignmentId, false, true))];
        recordUndo($db, $isAdmin ? 'admin_save' : 'manual_save', $before, $after);
        $auditAction = $isAdmin ? 'guards.admin_correction' : ($existing ? 'guards.assignment_replaced' : 'guards.assignment_added');
        apiAudit($db, $auditAction, 'guard_assignment', $assignmentId, $before, $after);
        if ((int)($apiUser['person_id'] ?? 0) !== $personId) {
            $actor=(string)($apiUser['display_name'] ?: $apiUser['username']);
            notifyPeople($db,[$personId],'guard_assigned','Ai fost programat de garda','Ai fost pus de garda in data de '.date('d.m.Y',strtotime($date)).' pe '.$specialty.' L'.$line.' de catre '.$actor.'.',$assignmentId);
        }
        $db->commit();
        apiSuccess(['message' => 'Garda a fost salvata.']);
    } catch (DomainException $error) {
        if ($db->inTransaction()) $db->rollBack();
        apiError($error->getMessage(), 409);
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        apiLogException($error, 'guards.save_assignment');
        apiError('Garda nu a putut fi salvata.', 500);
    }
}

function deleteAssignment(PDO $db, array $input): never
{
    global $apiPermissions, $apiUser;
    $fullGuardAccess = hasFullGuardAccess($db, (int)$apiUser['id']);
    if (!$fullGuardAccess && !array_intersect(['guards.admin_correction','guards.own_changes'], $apiPermissions)) apiError('Nu ai dreptul necesar pentru aceasta actiune.', 403);
    $isAdmin = $fullGuardAccess || in_array('guards.admin_correction', $apiPermissions, true);
    $assignmentId = (int) ($input['assignment_id'] ?? 0);
    $assignment = assignmentById($db, $assignmentId, true);
    if (!$assignment) apiError('Garda nu a fost gasita.', 404);
    if (!$isAdmin && (string) ($apiUser['specialty'] ?? '') !== (string) $assignment['specialty']) {
        apiError('Poti modifica numai garzile specialitatii tale.', 403);
    }
    futureOrToday((string) $assignment['guard_date']);
    $lineConfiguration = guardLineConfiguration($db, (string) $assignment['specialty'], substr((string) $assignment['guard_date'], 0, 7));
    if (!$isAdmin && $apiUser['person_id'] !== null && $assignment['specialty'] === 'OG' && (int) $assignment['line_no'] === 1 && $lineConfiguration['line1_protected']) {
        apiError('Linia 1 poate fi modificata doar prin schimb sau preluare.', 403);
    }
    $before = [snapshot($assignment)];
    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM guard_assignments WHERE id = :id')->execute(['id' => $assignmentId]);
        recordUndo($db, $isAdmin ? 'admin_delete' : 'manual_delete', $before, []);
        apiAudit($db, $isAdmin ? 'guards.assignment_removed' : 'guards.assignment_deleted', 'guard_assignment', $assignmentId, $before, null);
        $db->commit();
        apiSuccess(['message' => 'Garda a fost stearsa.']);
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        apiError('Garda nu poate fi stearsa deoarece are istoric. Foloseste o corectie administrativa.', 409);
    }
}

function moveAssignmentLine(PDO $db, array $input): never
{
    global $apiPermissions, $apiUser;
    $fullGuardAccess = hasFullGuardAccess($db, (int)$apiUser['id']);
    $isAdmin = $fullGuardAccess || in_array('guards.admin_correction', $apiPermissions, true);
    if (!$fullGuardAccess && !array_intersect(['guards.admin_correction','guards.own_changes'], $apiPermissions)) {
        apiError('Nu ai dreptul sa muti garzi intre linii.', 403);
    }

    $sourceId = (int)($input['assignment_id'] ?? 0);
    $targetLine = (int)($input['target_line'] ?? 0);
    $source = assignmentById($db, $sourceId, true);
    if (!$source) apiError('Garda mutata nu a fost gasita.', 404);
    futureOrToday((string)$source['guard_date']);
    $specialty = (string)$source['specialty'];
    $configuration = guardLineConfiguration($db, $specialty, substr((string) $source['guard_date'], 0, 7));
    if ($targetLine < 1 || $targetLine > $configuration['line_count'] || $targetLine === (int)$source['line_no']) {
        apiError('Linia destinatie nu este valida.');
    }
    if (!$fullGuardAccess && (string)($apiUser['specialty'] ?? '') !== $specialty) {
        apiError('Poti muta numai garzile specialitatii tale.', 403);
    }
    if ($specialty === 'OG' && $configuration['line1_protected'] && ((int)$source['line_no'] === 1 || $targetLine === 1)) {
        apiError('OG Linia 1 ramane protejata si se modifica numai prin schimb sau preluare.', 403);
    }

    $db->beginTransaction();
    try {
        [, $targetVersionId] = findOrCreateActivePlan($db, (string)$source['guard_date'], $specialty, $targetLine, (int)$apiUser['id']);
        $target = assignmentBySlot($db, $targetVersionId, (string)$source['guard_date'], $specialty, $targetLine, true);
        $before = [snapshot($source)];
        if ($target) $before[] = snapshot($target);

        if ($target) {
            $db->prepare('UPDATE guard_assignments SET line_no=0 WHERE id=:id')->execute(['id'=>$target['id']]);
            $db->prepare('UPDATE guard_assignments SET version_id=:version,line_no=:line WHERE id=:id')
                ->execute(['version'=>$target['version_id'],'line'=>$targetLine,'id'=>$sourceId]);
            $db->prepare('UPDATE guard_assignments SET version_id=:version,line_no=:line WHERE id=:id')
                ->execute(['version'=>$source['version_id'],'line'=>$source['line_no'],'id'=>$target['id']]);
        } else {
            $db->prepare('UPDATE guard_assignments SET version_id=:version,line_no=:line WHERE id=:id')
                ->execute(['version'=>$targetVersionId,'line'=>$targetLine,'id'=>$sourceId]);
        }

        $after = [snapshot(assignmentById($db, $sourceId, false, true))];
        if ($target) $after[] = snapshot(assignmentById($db, (int)$target['id'], false, true));
        recordUndo($db, $isAdmin ? 'admin_save' : 'manual_save', $before, $after);
        apiAudit($db, 'guards.assignment_line_moved', 'guard_assignment', $sourceId, $before, $after);
        $db->commit();
        apiSuccess(['message'=>$target ? 'Garzile au fost mutate intre linii.' : 'Garda a fost mutata pe linia selectata.']);
    } catch (DomainException $error) {
        if ($db->inTransaction()) $db->rollBack();
        apiError($error->getMessage(), 409);
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        apiLogException($error, 'guards.move_assignment_line');
        apiError('Garda nu a putut fi mutata intre linii.', 500);
    }
}

function exchangeGuards(PDO $db, array $input): never
{
    global $apiUser, $apiPermissions;
    apiRequirePermission('guards.own_changes', 'guards.admin_correction');
    if (!guardLineConfiguration($db, 'OG')['line1_protected']) apiError('Protectia OG Linia 1 este dezactivata; modifica garda direct.', 409);
    $sourceId = (int) ($input['source_assignment_id'] ?? 0);
    $targetId = (int) ($input['target_assignment_id'] ?? 0);
    if ($sourceId <= 0 || $targetId <= 0 || $sourceId === $targetId) {
        apiError('Selecteaza doua garzi diferite pentru schimb.');
    }

    $db->beginTransaction();
    try {
        $source = assignmentById($db, $sourceId, true, true);
        $target = assignmentById($db, $targetId, true, true);
        if (!$source || !$target) throw new DomainException('Una dintre garzi nu mai este disponibila.');
        if ($source['specialty'] !== 'OG' || $target['specialty'] !== 'OG' || (int) $source['line_no'] !== 1 || (int) $target['line_no'] !== 1) throw new DomainException('Schimbul protejat este disponibil doar pentru OG Linia 1.');
        if ($source['specialty'] !== $target['specialty']) throw new DomainException('Garzile trebuie sa fie din aceeasi specialitate.');
        futureOrToday((string) $source['guard_date']);
        futureOrToday((string) $target['guard_date']);

        $actorPersonId = $apiUser['person_id'] === null ? null : (int) $apiUser['person_id'];
        $isAdmin = hasFullGuardAccess($db, (int)$apiUser['id']) || in_array('guards.admin_correction', $apiPermissions, true);
        if (!$isAdmin && $actorPersonId !== null && (int) $source['current_person_id'] !== $actorPersonId) {
            throw new DomainException('Poti porni schimbul doar de la o garda care iti apartine in prezent.');
        }
        if ($actorPersonId === null && !$isAdmin) {
            throw new DomainException('Contul nu este asociat unui medic.');
        }

        $sourcePerson = (int) $source['current_person_id'];
        $targetPerson = (int) $target['current_person_id'];
        if ($sourcePerson === $targetPerson) throw new DomainException('Ambele garzi apartin deja aceluiasi medic.');
        ensureNoDailyConflict($db, $targetPerson, (string) $source['guard_date'], [$sourceId, $targetId]);
        ensureNoDailyConflict($db, $sourcePerson, (string) $target['guard_date'], [$sourceId, $targetId]);
        ensureNoConsecutiveGuard($db, $targetPerson, (string) $source['guard_date'], [$sourceId, $targetId]);
        ensureNoConsecutiveGuard($db, $sourcePerson, (string) $target['guard_date'], [$sourceId, $targetId]);

        $before = [snapshot($source), snapshot($target)];
        $db->prepare('UPDATE guard_assignments SET current_person_id = :person_id, assignment_state = "exchanged" WHERE id = :id')
            ->execute(['person_id' => $targetPerson, 'id' => $sourceId]);
        $db->prepare('UPDATE guard_assignments SET current_person_id = :person_id, assignment_state = "exchanged" WHERE id = :id')
            ->execute(['person_id' => $sourcePerson, 'id' => $targetId]);

        $db->prepare('INSERT INTO guard_changes (change_type, initiated_by) VALUES ("exchange", :user_id)')
            ->execute(['user_id' => $apiUser['id']]);
        $changeId = (int) $db->lastInsertId();
        insertChangeItem($db, $changeId, $source, $sourcePerson, $targetPerson, 'exchanged');
        insertChangeItem($db, $changeId, $target, $targetPerson, $sourcePerson, 'exchanged');
        $after = [snapshot(assignmentById($db, $sourceId, false, true)), snapshot(assignmentById($db, $targetId, false, true))];
        recordUndo($db, 'exchange', $before, $after);
        $sourceName = personDisplayName($db, $sourcePerson);
        $targetName = personDisplayName($db, $targetPerson);
        $message = sprintf('%s: garda din %s a fost schimbata cu garda lui %s din %s.', $sourceName, formatGuardDate((string) $source['guard_date']), $targetName, formatGuardDate((string) $target['guard_date']));
        notifyPeople($db, [$sourcePerson, $targetPerson], 'guard_exchange', 'Schimb de garda OG L1', $message, $changeId);
        apiAudit($db, 'guards.exchange', 'guard_change', $changeId, $before, $after);
        $db->commit();
        apiSuccess(['message' => 'Schimbul de garzi a fost efectuat.']);
    } catch (DomainException $error) {
        if ($db->inTransaction()) $db->rollBack();
        apiError($error->getMessage(), 409);
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        apiError('Schimbul nu a putut fi salvat.', 500);
    }
}

function transferGuard(PDO $db, array $input): never
{
    global $apiUser;
    apiRequirePermission('guards.own_changes');
    if (!guardLineConfiguration($db, 'OG')['line1_protected']) apiError('Protectia OG Linia 1 este dezactivata; modifica garda direct.', 409);
    $actorPersonId = $apiUser['person_id'] === null ? null : (int) $apiUser['person_id'];
    if ($actorPersonId === null) apiError('Contul nu este asociat unui medic.', 403);
    $assignmentId = (int) ($input['assignment_id'] ?? 0);
    $targetPersonId = (int) ($input['target_person_id'] ?? 0);
    if ($assignmentId <= 0 || $targetPersonId <= 0) apiError('Garda sau medicul selectat nu este valid.');

    $db->beginTransaction();
    try {
        $assignment = assignmentById($db, $assignmentId, true, true);
        if (!$assignment) throw new DomainException('Garda nu mai este disponibila.');
        if ($assignment['specialty'] !== 'OG' || (int) $assignment['line_no'] !== 1) throw new DomainException('Cedarea protejata este disponibila doar pentru OG Linia 1.');
        futureOrToday((string) $assignment['guard_date']);
        if ((int) $assignment['current_person_id'] !== $actorPersonId) {
            throw new DomainException('Poti ceda doar o garda care iti apartine in prezent.');
        }
        if ($targetPersonId === $actorPersonId) throw new DomainException('Selecteaza un alt medic.');

        $personStatement = $db->prepare(
            'SELECT id, display_name FROM people
             WHERE id = :id AND specialty = :specialty AND active = 1 AND guard_eligible = 1'
        );
        $personStatement->execute(['id' => $targetPersonId, 'specialty' => $assignment['specialty']]);
        $targetPerson = $personStatement->fetch();
        if (!$targetPerson) throw new DomainException('Medicul selectat nu este activ pentru garzi in aceasta specialitate.');
        ensureNoDailyConflict($db, $targetPersonId, (string) $assignment['guard_date'], [$assignmentId]);
        ensureNoConsecutiveGuard($db, $targetPersonId, (string) $assignment['guard_date'], [$assignmentId]);

        $before = [snapshot($assignment)];
        $db->prepare('UPDATE guard_assignments SET current_person_id = :person_id, assignment_state = "transferred" WHERE id = :id')
            ->execute(['person_id' => $targetPersonId, 'id' => $assignmentId]);
        $db->prepare('INSERT INTO guard_changes (change_type, initiated_by) VALUES ("transfer", :user_id)')
            ->execute(['user_id' => $apiUser['id']]);
        $changeId = (int) $db->lastInsertId();
        insertChangeItem($db, $changeId, $assignment, $actorPersonId, $targetPersonId, 'transferred');
        $after = [snapshot(assignmentById($db, $assignmentId, false, true))];
        recordUndo($db, 'takeover', $before, $after);
        $actorName = personDisplayName($db, $actorPersonId);
        $message = sprintf('%s a cedat garda OG L1 din %s catre %s.', $actorName, formatGuardDate((string) $assignment['guard_date']), $targetPerson['display_name']);
        notifyPeople($db, [$actorPersonId, $targetPersonId], 'guard_transfer', 'Garda cedata', $message, $changeId);
        apiAudit($db, 'guards.transfer', 'guard_change', $changeId, $before, $after);
        $db->commit();
        apiSuccess(['message' => 'Garda a fost cedata.']);
    } catch (DomainException $error) {
        if ($db->inTransaction()) $db->rollBack();
        apiError($error->getMessage(), 409);
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        apiError('Cedarea nu a putut fi salvata.', 500);
    }
}

function takeoverGuard(PDO $db, array $input): never
{
    global $apiUser;
    apiRequirePermission('guards.own_changes');
    if (!guardLineConfiguration($db, 'OG')['line1_protected']) apiError('Protectia OG Linia 1 este dezactivata; modifica garda direct.', 409);
    $actorPersonId = $apiUser['person_id'] === null ? null : (int) $apiUser['person_id'];
    if ($actorPersonId === null) apiError('Contul nu este asociat unui medic.', 403);
    $assignmentId = (int) ($input['assignment_id'] ?? 0);

    $db->beginTransaction();
    try {
        $assignment = assignmentById($db, $assignmentId, true, true);
        if (!$assignment) throw new DomainException('Garda nu mai este disponibila.');
        if ($assignment['specialty'] !== 'OG' || (int) $assignment['line_no'] !== 1) throw new DomainException('Preluarea protejata este disponibila doar pentru OG Linia 1.');
        futureOrToday((string) $assignment['guard_date']);
        $formerPersonId = (int) $assignment['current_person_id'];
        if ($formerPersonId === $actorPersonId) throw new DomainException('Aceasta garda este deja a ta.');
        ensureNoDailyConflict($db, $actorPersonId, (string) $assignment['guard_date'], [$assignmentId]);
        ensureNoConsecutiveGuard($db, $actorPersonId, (string) $assignment['guard_date'], [$assignmentId]);

        $before = [snapshot($assignment)];
        $db->prepare('UPDATE guard_assignments SET current_person_id = :person_id, assignment_state = "transferred" WHERE id = :id')
            ->execute(['person_id' => $actorPersonId, 'id' => $assignmentId]);
        $db->prepare('INSERT INTO guard_changes (change_type, initiated_by) VALUES ("transfer", :user_id)')
            ->execute(['user_id' => $apiUser['id']]);
        $changeId = (int) $db->lastInsertId();
        insertChangeItem($db, $changeId, $assignment, $formerPersonId, $actorPersonId, 'transferred');
        $after = [snapshot(assignmentById($db, $assignmentId, false, true))];
        recordUndo($db, 'takeover', $before, $after);
        $formerName = personDisplayName($db, $formerPersonId);
        $actorName = personDisplayName($db, $actorPersonId);
        $message = sprintf('%s a preluat garda OG L1 din %s de la %s.', $actorName, formatGuardDate((string) $assignment['guard_date']), $formerName);
        notifyPeople($db, [$formerPersonId, $actorPersonId], 'guard_takeover', 'Garda preluata', $message, $changeId);
        apiAudit($db, 'guards.takeover', 'guard_change', $changeId, $before, $after);
        $db->commit();
        apiSuccess(['message' => 'Garda a fost preluata.']);
    } catch (DomainException $error) {
        if ($db->inTransaction()) $db->rollBack();
        apiError($error->getMessage(), 409);
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        apiError('Preluarea nu a putut fi salvata.', 500);
    }
}

function undoGuardAction(PDO $db): never
{
    global $apiUser;
    $db->beginTransaction();
    try {
        $statement = $db->prepare(
            'SELECT * FROM guard_undo_actions WHERE user_id = :user_id AND undone_at IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['user_id' => $apiUser['id']]);
        $undo = $statement->fetch();
        if (!$undo) throw new DomainException('Nu exista o actiune recenta care poate fi anulata.');
        $before = json_decode((string) $undo['before_data'], true, 512, JSON_THROW_ON_ERROR);
        $after = json_decode((string) $undo['after_data'], true, 512, JSON_THROW_ON_ERROR);
        if (!snapshotsMatch($db, $before, $after)) {
            throw new DomainException('Actiunea nu mai poate fi anulata deoarece garzile au fost modificate intre timp.');
        }
        restoreSnapshots($db, $before, $after);
        $db->prepare('UPDATE guard_undo_actions SET undone_at = NOW() WHERE id = :id')->execute(['id' => $undo['id']]);
        apiAudit($db, 'guards.undo', 'guard_undo_action', (int) $undo['id'], $after, $before);
        $db->commit();
        apiSuccess(['message' => 'Ultima actiune a fost anulata.']);
    } catch (DomainException $error) {
        if ($db->inTransaction()) $db->rollBack();
        apiError($error->getMessage(), 409);
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        apiError('Actiunea nu a putut fi anulata.', 500);
    }
}

function validSpecialty(string $specialty): string
{
    $specialty = strtoupper($specialty);
    if (!in_array($specialty, ['OG', 'ATI', 'NN'], true)) apiError('Specialitatea nu este valida.');
    return $specialty;
}

function guardLineConfiguration(PDO $db, string $specialty, ?string $month = null): array
{
    $statement = $db->prepare('SELECT line_count, line_labels, line1_protected FROM guard_specialty_settings WHERE specialty = :specialty');
    $statement->execute(['specialty' => $specialty]);
    $row = $statement->fetch();
    $defaults = [
        'OG' => ['L1 Spec.', 'L2 Spec.', 'L3 Rez.', 'L4 Rez.', 'L5 Rez.', 'L6 Rez.', 'L7 Rez.', 'L8 Rez.', 'L9 Rez.'],
        'ATI' => ['L1 ATI', 'L2 ATI', 'L3 ATI', 'L4 ATI', 'L5 ATI', 'L6 ATI', 'L7 ATI', 'L8 ATI', 'L9 ATI'],
        'NN' => ['L1 NN', 'L2 NN', 'L3 NN', 'L4 NN', 'L5 NN', 'L6 NN', 'L7 NN', 'L8 NN', 'L9 NN'],
    ];
    $labels = $row ? json_decode((string) $row['line_labels'], true) : null;
    if (!is_array($labels)) $labels = $defaults[$specialty];
    $fallbackCount = $row ? max(1, min(9, (int) $row['line_count'])) : count($defaults[$specialty]);
    $month = $month !== null ? validMonth($month) : null;
    $monthlySettings = guardMonthlyLineSettings();
    $savedMonthly = $month !== null ? ($monthlySettings[$month][$specialty] ?? null) : null;
    $savedCount = is_array($savedMonthly) ? ($savedMonthly['line_count'] ?? null) : $savedMonthly;
    $baseLineCount = $month !== null
        ? max(1, min(8, (int) ($savedCount ?? $fallbackCount)))
        : max(1, min(8, $fallbackCount));
    $extraEnabled = is_array($savedMonthly)
        ? !empty($savedMonthly['extra_enabled'])
        : $savedMonthly !== null;
    $lineCount = $month !== null && $extraEnabled
        ? visibleLineCountWithExtra($db, $specialty, $month, $baseLineCount)
        : $baseLineCount;
    $lines = [];
    for ($line = 1; $line <= $lineCount; $line++) {
        $label = trim((string) ($labels[$line - 1] ?? ('L' . $line)));
        $lines[] = ['line' => $line, 'label' => $label !== '' ? $label : ('L' . $line), 'short' => 'L' . $line];
    }
    return [
        'line_count' => $lineCount,
        'configured_line_count' => $baseLineCount,
        'extra_enabled' => $extraEnabled,
        'extra_line_open' => $lineCount > $baseLineCount,
        'next_locked_line' => $extraEnabled && $lineCount === $baseLineCount && $baseLineCount < 9 ? $baseLineCount + 1 : null,
        'lines' => $lines,
        'line1_protected' => $specialty === 'OG' && !empty($row['line1_protected']),
    ];
}

function guardMonthlyLineSettings(): array
{
    $path = dirname(__DIR__, 2) . '/storage/config/guard_monthly_lines.json';
    if (!is_file($path)) return [];
    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded['months'] ?? null) ? $decoded['months'] : [];
}

function visibleLineCountWithExtra(PDO $db, string $specialty, string $month, int $baseLineCount): int
{
    $start = $month . '-01';
    $daysInMonth = (int) (new DateTimeImmutable($start))->format('t');
    $end = $month . '-' . str_pad((string) $daysInMonth, 2, '0', STR_PAD_LEFT);
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM guard_assignments ga
         INNER JOIN guard_plan_versions gv ON gv.id = ga.version_id
         INNER JOIN guard_plans gp ON gp.current_version_id = gv.id
         WHERE ga.guard_date BETWEEN :date_from AND :date_to
           AND ga.specialty = :specialty AND ga.line_no BETWEEN 1 AND :last_required_line'
    );
    $statement->execute([
        'date_from' => $start,
        'date_to' => $end,
        'specialty' => $specialty,
        'last_required_line' => $baseLineCount,
    ]);
    return (int) $statement->fetchColumn() === $daysInMonth * $baseLineCount
        ? min(9, $baseLineCount + 1)
        : $baseLineCount;
}

function futureOrToday(string $date): string
{
    $date = validDate($date);
    if ($date < date('Y-m-d')) apiError('Garzile din trecut nu pot fi modificate.');
    return $date;
}

function assignmentById(PDO $db, int $id, bool $activeOnly = true, bool $lock = false): array|false
{
    $joins = $activeOnly
        ? ' INNER JOIN guard_plan_versions gv ON gv.id = ga.version_id INNER JOIN guard_plans gp ON gp.current_version_id = gv.id'
        : '';
    $statement = $db->prepare('SELECT ga.* FROM guard_assignments ga' . $joins . ' WHERE ga.id = :id' . ($lock ? ' FOR UPDATE' : ''));
    $statement->execute(['id' => $id]);
    return $statement->fetch();
}

function assignmentBySlot(PDO $db, int $versionId, string $date, string $specialty, int $line, bool $lock = false): array|false
{
    $statement = $db->prepare(
        'SELECT * FROM guard_assignments WHERE version_id = :version_id AND guard_date = :guard_date
         AND specialty = :specialty AND line_no = :line_no' . ($lock ? ' FOR UPDATE' : '')
    );
    $statement->execute(['version_id' => $versionId, 'guard_date' => $date, 'specialty' => $specialty, 'line_no' => $line]);
    return $statement->fetch();
}

function snapshot(array $assignment): array
{
    return [
        'id' => (int) $assignment['id'],
        'version_id' => (int) $assignment['version_id'],
        'guard_date' => (string) $assignment['guard_date'],
        'specialty' => (string) $assignment['specialty'],
        'line_no' => (int) $assignment['line_no'],
        'original_person_id' => (int) $assignment['original_person_id'],
        'current_person_id' => (int) $assignment['current_person_id'],
        'assignment_state' => (string) $assignment['assignment_state'],
    ];
}

function ensureNoDailyConflict(PDO $db, int $personId, string $date, ?array $excludedIds = null, ?string $slotSpecialty = null, ?int $slotLine = null): void
{
    $sql = 'SELECT ga.id FROM guard_assignments ga
            INNER JOIN guard_plan_versions gv ON gv.id = ga.version_id
            INNER JOIN guard_plans gp ON gp.current_version_id = gv.id
            WHERE ga.guard_date = :guard_date AND ga.current_person_id = :person_id';
    $params = ['guard_date' => $date, 'person_id' => $personId];
    if ($excludedIds) {
        $ids = array_values(array_map('intval', $excludedIds));
        $sql .= ' AND ga.id NOT IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $statement = $db->prepare(str_replace([':guard_date', ':person_id'], ['?', '?'], $sql) . ' LIMIT 1');
        $statement->execute(array_merge([$date, $personId], $ids));
    } elseif ($slotSpecialty !== null && $slotLine !== null) {
        $sql .= ' AND NOT (ga.specialty = :specialty AND ga.line_no = :line_no) LIMIT 1';
        $statement = $db->prepare($sql);
        $statement->execute($params + ['specialty' => $slotSpecialty, 'line_no' => $slotLine]);
    } else {
        $statement = $db->prepare($sql . ' LIMIT 1');
        $statement->execute($params);
    }
    if ($statement->fetchColumn()) apiError('Medicul are deja o alta linie de garda in aceasta zi.', 409);
}

function ensureNoConsecutiveGuard(PDO $db, int $personId, string $date, ?array $excludedIds = null): void
{
    $point = new DateTimeImmutable($date);
    $previous = $point->modify('-1 day')->format('Y-m-d');
    $next = $point->modify('+1 day')->format('Y-m-d');
    $sql = 'SELECT ga.guard_date,ga.specialty,ga.line_no
            FROM guard_assignments ga
            INNER JOIN guard_plan_versions gv ON gv.id=ga.version_id
            INNER JOIN guard_plans gp ON gp.current_version_id=gv.id
            WHERE ga.current_person_id=? AND ga.guard_date IN (?,?)';
    $params = [$personId, $previous, $next];
    if ($excludedIds) {
        $ids = array_values(array_filter(array_map('intval', $excludedIds)));
        if ($ids) {
            $sql .= ' AND ga.id NOT IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params = array_merge($params, $ids);
        }
    }
    $sql .= ' ORDER BY ga.guard_date LIMIT 1';
    $statement = $db->prepare($sql);
    $statement->execute($params);
    $conflict = $statement->fetch();
    if (!$conflict) return;

    $when = $conflict['guard_date'] === $previous ? 'ziua precedenta' : 'ziua urmatoare';
    apiError(sprintf(
        'Medicul este deja de garda in %s (%s, %s L%d). Nu sunt permise garzi in zile consecutive.',
        $when,
        formatGuardDate((string)$conflict['guard_date']),
        (string)$conflict['specialty'],
        (int)$conflict['line_no']
    ), 409);
}

function hasFullGuardAccess(PDO $db, int $userId): bool
{
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id=ur.role_id
         WHERE ur.user_id=:user_id AND r.code IN ("super_admin","platform_admin")'
    );
    $statement->execute(['user_id'=>$userId]);
    return (int)$statement->fetchColumn() > 0;
}

function insertChangeItem(PDO $db, int $changeId, array $assignment, int $from, int $to, string $newState): void
{
    $db->prepare(
        'INSERT INTO guard_change_items
         (change_id, assignment_id, from_person_id, to_person_id, previous_state, new_state)
         VALUES (:change_id, :assignment_id, :from_person_id, :to_person_id, :previous_state, :new_state)'
    )->execute([
        'change_id' => $changeId,
        'assignment_id' => $assignment['id'],
        'from_person_id' => $from,
        'to_person_id' => $to,
        'previous_state' => $assignment['assignment_state'],
        'new_state' => $newState,
    ]);
}

function userCanModifyAssignment(PDO $db,array $assignment,array $user): bool
{
    $personId=(int)($user['person_id']??0);$userId=(int)($user['id']??0);
    if($personId>0&&($personId===(int)$assignment['current_person_id']||$personId===(int)$assignment['original_person_id']))return true;
    if($userId>0&&$userId===(int)($assignment['created_by_user_id']??0))return true;
    if($personId<=0)return false;
    $statement=$db->prepare('SELECT COUNT(*) FROM guard_change_items WHERE assignment_id=:assignment_id AND (from_person_id=:from_person OR to_person_id=:to_person)');
    $statement->execute(['assignment_id'=>$assignment['id'],'from_person'=>$personId,'to_person'=>$personId]);
    return (int)$statement->fetchColumn()>0;
}

function notifyPeople(PDO $db, array $personIds, string $type, string $title, string $message, int $entityId): void
{
    $statement = $db->prepare(
        'INSERT INTO notifications (user_id, notification_type, title, message, entity_type, entity_id)
         SELECT id, :notification_type, :title, :message, "guard_change", :entity_id FROM users WHERE person_id = :person_id'
    );
    foreach (array_unique($personIds) as $personId) {
        $statement->execute([
            'notification_type' => $type,
            'title' => $title,
            'message' => $message,
            'entity_id' => $entityId,
            'person_id' => $personId,
        ]);
    }
}

function personDisplayName(PDO $db, int $personId): string
{
    $statement = $db->prepare('SELECT display_name FROM people WHERE id=:id');
    $statement->execute(['id' => $personId]);
    return (string) ($statement->fetchColumn() ?: 'Medic');
}

function formatGuardDate(string $date): string
{
    return (new DateTimeImmutable($date))->format('d.m.Y');
}

function recordUndo(PDO $db, string $actionType, array $before, array $after): void
{
    global $apiUser;
    $db->prepare(
        'INSERT INTO guard_undo_actions (user_id, action_type, before_data, after_data)
         VALUES (:user_id, :action_type, :before_data, :after_data)'
    )->execute([
        'user_id' => $apiUser['id'],
        'action_type' => $actionType,
        'before_data' => json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'after_data' => json_encode($after, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ]);
}

function snapshotsMatch(PDO $db, array $before, array $after): bool
{
    $expected = [];
    foreach ($after as $item) $expected[(int) $item['id']] = $item;
    $ids = array_unique(array_merge(array_column($before, 'id'), array_column($after, 'id')));
    foreach ($ids as $id) {
        $current = assignmentById($db, (int) $id, false, true);
        if (!isset($expected[(int) $id])) {
            if ($current) return false;
            continue;
        }
        if (!$current || snapshot($current) !== normalizeSnapshot($expected[(int) $id])) return false;
    }
    return true;
}

function normalizeSnapshot(array $item): array
{
    return [
        'id' => (int) $item['id'], 'version_id' => (int) $item['version_id'],
        'guard_date' => (string) $item['guard_date'], 'specialty' => (string) $item['specialty'],
        'line_no' => (int) $item['line_no'], 'original_person_id' => (int) $item['original_person_id'],
        'current_person_id' => (int) $item['current_person_id'], 'assignment_state' => (string) $item['assignment_state'],
    ];
}

function restoreSnapshots(PDO $db, array $before, array $after): void
{
    $wanted = [];
    foreach ($before as $item) $wanted[(int) $item['id']] = normalizeSnapshot($item);
    $ids = array_unique(array_merge(array_column($before, 'id'), array_column($after, 'id')));
    foreach ($ids as $id) {
        $id = (int) $id;
        if (!isset($wanted[$id])) {
            $db->prepare('DELETE FROM guard_assignments WHERE id = :id')->execute(['id' => $id]);
            continue;
        }
        $item = $wanted[$id];
        if (assignmentById($db, $id, false)) {
            $db->prepare(
                'UPDATE guard_assignments SET version_id=:version_id, guard_date=:guard_date, specialty=:specialty,
                 line_no=:line_no, original_person_id=:original_person_id, current_person_id=:current_person_id,
                 assignment_state=:assignment_state WHERE id=:id'
            )->execute($item);
        } else {
            $db->prepare(
                'INSERT INTO guard_assignments
                 (id, version_id, guard_date, specialty, line_no, original_person_id, current_person_id, assignment_state)
                 VALUES (:id, :version_id, :guard_date, :specialty, :line_no, :original_person_id, :current_person_id, :assignment_state)'
            )->execute($item);
        }
    }
}

function findOrCreateActivePlan(PDO $db, string $date, string $specialty, int $line, int $userId): array
{
    $statement = $db->prepare(
        'SELECT gp.id AS plan_id, gp.current_version_id AS version_id FROM guard_plans gp
         WHERE gp.specialty = :specialty AND gp.line_no = :line_no
           AND :guard_date BETWEEN gp.date_from AND gp.date_to AND gp.current_version_id IS NOT NULL
         ORDER BY gp.id DESC LIMIT 1'
    );
    $statement->execute(['specialty' => $specialty, 'line_no' => $line, 'guard_date' => $date]);
    $plan = $statement->fetch();
    if ($plan) return [(int) $plan['plan_id'], (int) $plan['version_id']];

    $monthStart = substr($date, 0, 7) . '-01';
    $monthEnd = (new DateTimeImmutable($monthStart))->modify('last day of this month')->format('Y-m-d');
    $db->prepare(
        'INSERT INTO guard_plans (specialty, line_no, date_from, date_to, created_by)
         VALUES (:specialty, :line_no, :date_from, :date_to, :created_by)'
    )->execute(['specialty' => $specialty, 'line_no' => $line, 'date_from' => $monthStart, 'date_to' => $monthEnd, 'created_by' => $userId]);
    $planId = (int) $db->lastInsertId();
    $db->prepare(
        'INSERT INTO guard_plan_versions (plan_id, version_no, status, created_by, published_by, published_at)
         VALUES (:plan_id, 1, "published", :created_by, :published_by, NOW())'
    )->execute(['plan_id' => $planId, 'created_by' => $userId, 'published_by' => $userId]);
    $versionId = (int) $db->lastInsertId();
    $db->prepare('UPDATE guard_plans SET current_version_id = :version_id WHERE id = :plan_id')
        ->execute(['version_id' => $versionId, 'plan_id' => $planId]);
    return [$planId, $versionId];
}
