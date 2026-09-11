<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$scheme = $https ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$directory = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/api/openapi.php')), '/');
$server = $scheme . '://' . $host . $directory;

$document = [
    'openapi' => '3.1.0',
    'info' => [
        'title' => 'Maternitatea Giulesti - API intern',
        'version' => '1.0.0',
        'description' => 'API securizat pentru garzi, Program Operator si administrare. Foloseste un token creat din Administrare > Acces API / AI.',
    ],
    'servers' => [['url' => $server]],
    'security' => [['bearerAuth' => []]],
    'paths' => [
        '/assistant.php' => [
            'get' => [
                'operationId' => 'queryHospitalSchedule',
                'summary' => 'Citeste dictionarele, lista completa de garzi, operatiile sau cele mai libere zile de garda',
                'parameters' => [
                    ['name'=>'action','in'=>'query','required'=>true,'schema'=>['type'=>'string','enum'=>['capabilities','guards','schedule','best_guard_days']]],
                    ['name'=>'date_from','in'=>'query','required'=>false,'schema'=>['type'=>'string','format'=>'date']],
                    ['name'=>'date_to','in'=>'query','required'=>false,'schema'=>['type'=>'string','format'=>'date']],
                    ['name'=>'specialty','in'=>'query','required'=>false,'schema'=>['type'=>'string','enum'=>['OG','ATI','NN']]],
                    ['name'=>'person_id','in'=>'query','required'=>false,'schema'=>['type'=>'integer']],
                ],
                'responses' => ['200'=>['$ref'=>'#/components/responses/JsonSuccess'],'401'=>['$ref'=>'#/components/responses/Unauthorized'],'403'=>['$ref'=>'#/components/responses/Forbidden']],
            ],
            'post' => [
                'operationId' => 'addOperationByName',
                'summary' => 'Adauga o operatie folosind numele salii, medicului si tipului de operatie',
                'requestBody' => ['required'=>true,'content'=>['application/json'=>['schema'=>['$ref'=>'#/components/schemas/AssistantAddOperation']]]],
                'responses' => ['200'=>['$ref'=>'#/components/responses/JsonSuccess'],'400'=>['$ref'=>'#/components/responses/JsonError'],'401'=>['$ref'=>'#/components/responses/Unauthorized'],'403'=>['$ref'=>'#/components/responses/Forbidden']],
            ],
        ],
        '/guards.php' => [
            'get' => [
                'operationId' => 'listGuards',
                'summary' => 'Citeste garzile si medicii unei luni',
                'parameters' => [
                    ['name'=>'month','in'=>'query','required'=>true,'schema'=>['type'=>'string','pattern'=>'^\\d{4}-(0[1-9]|1[0-2])$']],
                    ['name'=>'specialty','in'=>'query','required'=>true,'schema'=>['type'=>'string','enum'=>['OG','ATI','NN']]],
                ],
                'responses' => ['200' => ['$ref'=>'#/components/responses/JsonSuccess'], '401'=>['$ref'=>'#/components/responses/Unauthorized'], '403'=>['$ref'=>'#/components/responses/Forbidden']],
            ],
            'post' => [
                'operationId' => 'modifyGuards',
                'summary' => 'Adauga, inlocuieste, sterge, schimba, cedeaza sau preia o garda',
                'requestBody' => ['required'=>true,'content'=>['application/json'=>['schema'=>['$ref'=>'#/components/schemas/GuardAction']]]],
                'responses' => ['200'=>['$ref'=>'#/components/responses/JsonSuccess'],'400'=>['$ref'=>'#/components/responses/JsonError'],'401'=>['$ref'=>'#/components/responses/Unauthorized'],'403'=>['$ref'=>'#/components/responses/Forbidden']],
            ],
        ],
        '/progop.php' => [
            'get' => [
                'operationId' => 'listMonthlyOperations',
                'summary' => 'Citeste operatiile, garzile si salile unei luni',
                'parameters' => [['name'=>'month','in'=>'query','required'=>true,'schema'=>['type'=>'string','pattern'=>'^\\d{4}-(0[1-9]|1[0-2])$']]],
                'responses' => ['200'=>['$ref'=>'#/components/responses/JsonSuccess'],'401'=>['$ref'=>'#/components/responses/Unauthorized'],'403'=>['$ref'=>'#/components/responses/Forbidden']],
            ],
            'post' => [
                'operationId' => 'manageOperation',
                'summary' => 'Adauga, modifica, sterge sau marcheaza o operatie si blocheaza/deblocheaza sali',
                'requestBody' => ['required'=>true,'content'=>['application/json'=>['schema'=>['$ref'=>'#/components/schemas/OperationAction']]]],
                'responses' => ['200'=>['$ref'=>'#/components/responses/JsonSuccess'],'400'=>['$ref'=>'#/components/responses/JsonError'],'401'=>['$ref'=>'#/components/responses/Unauthorized'],'403'=>['$ref'=>'#/components/responses/Forbidden']],
            ],
        ],
        '/progop-legacy.php' => [
            'get' => [
                'operationId' => 'readOperatingSchedule',
                'summary' => 'Citeste Programul Operator pentru luna selectata',
                'parameters' => [
                    ['name'=>'action','in'=>'query','required'=>true,'schema'=>['type'=>'string','enum'=>['load','overview']]],
                    ['name'=>'month','in'=>'query','required'=>false,'schema'=>['type'=>'string','pattern'=>'^\\d{4}-\\d{2}$']],
                    ['name'=>'date','in'=>'query','required'=>false,'schema'=>['type'=>'string','format'=>'date']],
                    ['name'=>'mode','in'=>'query','required'=>false,'schema'=>['type'=>'string','enum'=>['day','week']]],
                ],
                'responses' => ['200'=>['$ref'=>'#/components/responses/JsonSuccess'],'401'=>['$ref'=>'#/components/responses/Unauthorized'],'403'=>['$ref'=>'#/components/responses/Forbidden']],
            ],
            'post' => [
                'operationId' => 'modifyOperatingSchedule',
                'summary' => 'Modifica Programul Operator folosind actiunile aplicatiei',
                'requestBody' => ['required'=>true,'content'=>['application/json'=>['schema'=>['type'=>'object','required'=>['action'],'properties'=>['action'=>['type'=>'string']],'additionalProperties'=>true]]]],
                'responses' => ['200'=>['$ref'=>'#/components/responses/JsonSuccess'],'401'=>['$ref'=>'#/components/responses/Unauthorized'],'403'=>['$ref'=>'#/components/responses/Forbidden']],
            ],
        ],
        '/progop-import.php' => [
            'post' => [
                'operationId' => 'importOperatingSchedule',
                'summary' => 'Valideaza in masa operatii extrase din XLSX, apoi confirma sau anuleaza importul',
                'requestBody' => ['required'=>true,'content'=>['application/json'=>['schema'=>['oneOf'=>[
                    ['$ref'=>'#/components/schemas/OperationImportPreview'],
                    ['$ref'=>'#/components/schemas/OperationImportCommit'],
                    ['$ref'=>'#/components/schemas/OperationImportUndo'],
                ]]]]],
                'responses' => ['200'=>['$ref'=>'#/components/responses/JsonSuccess'],'400'=>['$ref'=>'#/components/responses/JsonError'],'401'=>['$ref'=>'#/components/responses/Unauthorized'],'403'=>['$ref'=>'#/components/responses/Forbidden']],
            ],
        ],
        '/admin.php' => [
            'get' => [
                'operationId' => 'readAdministration',
                'summary' => 'Citeste configuratia, personalul, drepturile si tokenurile',
                'responses' => ['200'=>['$ref'=>'#/components/responses/JsonSuccess'],'401'=>['$ref'=>'#/components/responses/Unauthorized'],'403'=>['$ref'=>'#/components/responses/Forbidden']],
            ],
        ],
    ],
    'components' => [
        'securitySchemes' => ['bearerAuth'=>['type'=>'http','scheme'=>'bearer','bearerFormat'=>'API token']],
        'schemas' => [
            'AssistantAddOperation' => [
                'type'=>'object','required'=>['action','date','room','patient_name','operation_type'],
                'properties'=>[
                    'action'=>['type'=>'string','const'=>'add_operation'],'date'=>['type'=>'string','format'=>'date'],
                    'room'=>['oneOf'=>[['type'=>'integer'],['type'=>'string']]],'doctor'=>['oneOf'=>[['type'=>'integer'],['type'=>'string']]],
                    'patient_name'=>['type'=>'string'],'operation_type'=>['oneOf'=>[['type'=>'integer'],['type'=>'string']]],
                    'diagnostic'=>['type'=>'string'],'notes'=>['type'=>'string'],'is_extra'=>['type'=>'boolean'],'extra_reason'=>['type'=>'string'],
                ],
                'additionalProperties'=>false,
            ],
            'OperationAction' => [
                'type'=>'object','required'=>['action'],
                'properties'=>[
                    'action'=>['type'=>'string','enum'=>['add_operation','update_operation','delete_operation','toggle_completed','block_room','unblock_room']],
                    'id'=>['type'=>'integer'],'date'=>['type'=>'string','format'=>'date'],'room_id'=>['type'=>'integer'],'doctor_id'=>['type'=>'integer'],
                    'operation_type_id'=>['type'=>'integer'],'patient_name'=>['type'=>'string'],'diagnostic'=>['type'=>'string'],'notes'=>['type'=>'string'],
                    'is_extra'=>['type'=>'boolean'],'extra_reason'=>['type'=>'string'],'completed'=>['type'=>'boolean'],'block_id'=>['type'=>'integer'],'reason'=>['type'=>'string'],
                ],
                'additionalProperties'=>true,
            ],
            'OperationImportPreview' => [
                'type'=>'object','required'=>['action','rows'],
                'properties'=>[
                    'action'=>['type'=>'string','const'=>'preview_rows'],
                    'source_filename'=>['type'=>'string'],
                    'rows'=>['type'=>'array','minItems'=>1,'maxItems'=>1000,'items'=>['type'=>'object','required'=>['date','room','patient_name','operation_type','doctor'],'properties'=>[
                        'source_row'=>['type'=>'integer'],'date'=>['type'=>'string','format'=>'date'],'room'=>['type'=>'string','enum'=>['Sala 1','Sala 2','Sala 3','Sala 4']],
                        'patient_name'=>['type'=>'string'],'diagnostic'=>['type'=>'string'],'operation_type'=>['type'=>'string'],'doctor'=>['type'=>'string'],'notes'=>['type'=>'string'],
                        'is_extra'=>['type'=>'boolean'],'extra_reason'=>['type'=>'string'],
                    ],'additionalProperties'=>false]],
                ],'additionalProperties'=>false,
            ],
            'OperationImportCommit' => ['type'=>'object','required'=>['action','previewToken'],'properties'=>['action'=>['type'=>'string','const'=>'commit'],'previewToken'=>['type'=>'string']],'additionalProperties'=>false],
            'OperationImportUndo' => ['type'=>'object','required'=>['action','importId'],'properties'=>['action'=>['type'=>'string','const'=>'undo'],'importId'=>['type'=>'integer']],'additionalProperties'=>false],
            'GuardAction' => [
                'type'=>'object','required'=>['action'],
                'properties'=>[
                    'action'=>['type'=>'string','enum'=>['save_assignment','delete_assignment','exchange_guards','transfer_guard','takeover_guard','undo_guard_action']],
                    'date'=>['type'=>'string','format'=>'date'],'specialty'=>['type'=>'string','enum'=>['OG','ATI','NN']],
                    'line'=>['type'=>'integer','minimum'=>1,'maximum'=>9],'person_id'=>['type'=>'integer'],
                    'assignment_id'=>['type'=>'integer'],'source_assignment_id'=>['type'=>'integer'],'target_assignment_id'=>['type'=>'integer'],'target_person_id'=>['type'=>'integer'],
                ],
                'additionalProperties'=>false,
            ],
        ],
        'responses' => [
            'JsonSuccess'=>['description'=>'Raspuns JSON reusit','content'=>['application/json'=>['schema'=>['type'=>'object']]]],
            'JsonError'=>['description'=>'Cerere invalida','content'=>['application/json'=>['schema'=>['type'=>'object']]]],
            'Unauthorized'=>['description'=>'Token invalid, expirat sau revocat'],
            'Forbidden'=>['description'=>'Tokenul nu are dreptul necesar'],
        ],
    ],
];

echo json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
