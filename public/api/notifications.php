<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $statement = $db->prepare(
        'SELECT id,notification_type,title,message,entity_type,entity_id,read_at,created_at
         FROM notifications WHERE user_id=:user_id ORDER BY created_at DESC,id DESC LIMIT 50'
    );
    $statement->execute(['user_id' => $apiUser['id']]);
    apiSuccess(['notifications' => $statement->fetchAll()]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') apiError('Metoda nu este acceptata.', 405);
apiVerifyCsrf();
$input = apiInput();
$action = (string) ($input['action'] ?? '');

if ($action === 'mark_all_read') {
    $db->prepare('UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE user_id=:user_id')
        ->execute(['user_id' => $apiUser['id']]);
    apiSuccess(['message' => 'Notificarile au fost marcate ca citite.']);
}

if ($action === 'mark_read') {
    $id = (int) ($input['notification_id'] ?? 0);
    $db->prepare('UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE id=:id AND user_id=:user_id')
        ->execute(['id' => $id, 'user_id' => $apiUser['id']]);
    apiSuccess(['message' => 'Notificarea a fost marcata ca citita.']);
}

if ($action === 'delete_read') {
    $id = (int) ($input['notification_id'] ?? 0);
    if ($id <= 0) apiError('Notificarea nu este valida.');
    $statement = $db->prepare(
        'SELECT n.id,n.notification_type,n.entity_id,o.extra_reason,o.status
         FROM notifications n LEFT JOIN operations o ON n.entity_type="operation" AND o.id=n.entity_id
         WHERE n.id=:id AND n.user_id=:user_id AND n.read_at IS NOT NULL'
    );
    $statement->execute(['id'=>$id,'user_id'=>$apiUser['id']]);
    $notification = $statement->fetch();
    if (!$notification) apiError('Poți șterge numai notificările tale deja citite.', 409);
    if ($notification['notification_type'] === 'extra_approval_request'
        && str_starts_with((string)($notification['extra_reason'] ?? ''), '[[EXTRA_PENDING]]')
        && (string)($notification['status'] ?? '') !== 'deleted') {
        apiError('Notificarea nu poate fi ștearsă până când operația nu este aprobată sau refuzată.', 409);
    }
    $db->prepare('DELETE FROM notifications WHERE id=:id AND user_id=:user_id AND read_at IS NOT NULL')
        ->execute(['id'=>$id,'user_id'=>$apiUser['id']]);
    apiSuccess(['message'=>'Notificarea a fost ștearsă.']);
}

if ($action === 'delete_all_read') {
    $statement = $db->prepare(
        'DELETE n FROM notifications n
         LEFT JOIN operations o ON n.entity_type="operation" AND o.id=n.entity_id
         WHERE n.user_id=:user_id AND n.read_at IS NOT NULL
           AND NOT (
               n.notification_type="extra_approval_request"
               AND LEFT(COALESCE(o.extra_reason,""),17)="[[EXTRA_PENDING]]"
               AND COALESCE(o.status,"")<>"deleted"
           )'
    );
    $statement->execute(['user_id'=>$apiUser['id']]);
    $deleted = $statement->rowCount();
    apiSuccess([
        'message'=>$deleted === 1 ? 'O notificare citită a fost ștearsă.' : $deleted . ' notificări citite au fost șterse.',
        'deleted'=>$deleted,
    ]);
}

apiError('Actiune necunoscuta.');
