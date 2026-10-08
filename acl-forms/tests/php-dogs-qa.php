<?php

declare(strict_types=1);

define('ABSPATH', __DIR__);
define('ACL_FORMS_DIR', dirname(__DIR__) . '/');
define('ACL_FORMS_INSTRUCTOR_ID', 'test-instructor-id');
define('ACL_FORMS_FIREBASE_SERVICE_ACCOUNT_JSON', json_encode([
    'project_id' => 'test-project',
    'client_email' => 'test@example.org',
    'private_key' => 'unused',
]));

final class WP_Error
{
    public function __construct(public string $code, public string $message, public mixed $data = null) {}
    public function get_error_data(): mixed { return $this->data; }
}

function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function get_transient(string $key): string { return 'test-token'; }
function wp_json_encode(mixed $value): string { return json_encode($value, JSON_THROW_ON_ERROR); }
function wp_generate_uuid4(): string
{
    static $counter = 0;
    $counter++;
    return sprintf('00000000-0000-4000-8000-%012d', $counter);
}
function wp_remote_retrieve_response_code(array $response): int { return $response['status']; }
function wp_remote_retrieve_body(array $response): string { return json_encode($response['body'], JSON_THROW_ON_ERROR); }

$documents = ['dogs' => [], 'pairs' => []];
function wp_remote_request(string $url, array $args): array
{
    global $documents;
    $path = parse_url($url, PHP_URL_PATH);
    $body = isset($args['body']) ? json_decode($args['body'], true, 512, JSON_THROW_ON_ERROR) : [];
    if (str_ends_with($path, '/documents:runQuery')) {
        $handler_id = $body['structuredQuery']['where']['fieldFilter']['value']['stringValue'];
        $matches = [];
        foreach ($documents['pairs'] as $id => $fields) {
            if ($fields['handlerId']['stringValue'] === $handler_id) {
                $matches[] = ['document' => ['name' => 'projects/test-project/databases/(default)/documents/pairs/' . $id, 'fields' => $fields]];
            }
        }
        return ['status' => 200, 'body' => $matches];
    }
    if (str_ends_with($path, '/documents:commit')) {
        foreach ($body['writes'] as $write) {
            $name = $write['update']['name'];
            if (!str_starts_with($name, 'projects/test-project/databases/(default)/documents/')) {
                return ['status' => 400, 'body' => ['error' => ['message' => 'Document name must be a Firestore resource name']]];
            }
            $collection = basename(dirname($name));
            $id = basename($name);
            if (isset($documents[$collection][$id])) {
                return ['status' => 409, 'body' => ['error' => ['message' => 'Already exists']]];
            }
            $documents[$collection][$id] = $write['update']['fields'];
        }
        return ['status' => 200, 'body' => ['writeResults' => [[], []]]];
    }
    if (preg_match('#/documents/dogs/([^/]+)$#', $path, $match)) {
        $id = rawurldecode($match[1]);
        if (!isset($documents['dogs'][$id])) {
            return ['status' => 404, 'body' => ['error' => ['message' => 'Not found']]];
        }
        if ($args['method'] === 'PATCH') {
            $documents['dogs'][$id] = array_merge($documents['dogs'][$id], $body['fields']);
        }
        return ['status' => 200, 'body' => ['fields' => $documents['dogs'][$id]]];
    }
    if (preg_match('#/documents/pairs/([^/]+)$#', $path, $match) && $args['method'] === 'PATCH') {
        $id = rawurldecode($match[1]);
        $documents['pairs'][$id] = array_merge($documents['pairs'][$id], $body['fields']);
        return ['status' => 200, 'body' => ['fields' => $documents['pairs'][$id]]];
    }
    throw new RuntimeException('Richiesta inattesa: ' . $path);
}

require dirname(__DIR__) . '/includes/class-acl-config.php';
require dirname(__DIR__) . '/includes/class-acl-google.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$google = new ACL_Forms_Google();
$data = [
    'nomeCane1' => '', 'razzaCane1' => '', 'sessoCane1' => '', 'altezzaCane1' => '',
    'microchipCane1' => '', 'dataNascitaCane1' => '',
    'nomeCane2' => '', 'razzaCane2' => '', 'sessoCane2' => '', 'altezzaCane2' => '',
    'microchipCane2' => '', 'dataNascitaCane2' => '', 'aggiungiSecondoCane' => false,
];
$handler = ['name' => 'projects/test-project/databases/(default)/documents/handlers/handler-1'];
check($google->upsert_dogs($data, $handler)['count'] === 0, 'Nessun cane deve creare documenti.');
check(!$documents['dogs'] && !$documents['pairs'], 'Sono stati creati documenti vuoti.');

$data['nomeCane1'] = 'Stella';
$data['razzaCane1'] = 'Meticcia';
$data['sessoCane1'] = 'F';
$data['microchipCane1'] = '123456789012345';
$data['dataNascitaCane1'] = '2025-03-08';
$first = $google->upsert_dogs($data, $handler);
check(!is_wp_error($first) && $first['count'] === 1, 'Il primo cane non è stato scritto.');
check(count($documents['dogs']) === 1 && count($documents['pairs']) === 1, 'Manca il cane o la coppia.');
$id = $first['ids'][0];
check($documents['dogs'][$id]['birthday']['timestampValue'] === '2025-03-07T23:00:00Z', 'Data di nascita con fuso orario errata.');
check($documents['dogs'][$id]['skills']['mapValue']['fields']['terra']['mapValue']['fields']['value']['booleanValue'] === false, 'Skills predefinite mancanti.');
$pair = reset($documents['pairs']);
check($pair['handlerId']['stringValue'] === 'handler-1' && $pair['dogId']['stringValue'] === $id, 'Collegamento handler/cane errato.');
check($pair['instructorId']['stringValue'] === ACL_Forms_Config::instructor_id(), 'Istruttore predefinito non assegnato al binomio.');
check($pair['isDeleted']['booleanValue'] === false && $pair['status']['stringValue'] === 'active', 'Stato del binomio non valido.');

$documents['dogs'][$id]['notes'] = ['stringValue' => 'Nota esistente'];
$data['nomeCane1'] = 'Stella aggiornata';
$again = $google->upsert_dogs($data, $handler);
check($again['ids'] === [$id], 'Il cane con lo stesso microchip deve essere riutilizzato.');
check(count($documents['dogs']) === 1 && count($documents['pairs']) === 1, 'La seconda iscrizione ha duplicato il cane.');
check($documents['dogs'][$id]['notes']['stringValue'] === 'Nota esistente', 'La nota esistente è stata sovrascritta.');

$pair_id = array_key_first($documents['pairs']);
$documents['pairs'][$pair_id]['instructorId'] = ['stringValue' => ''];
$google->upsert_dogs($data, $handler);
check($documents['pairs'][$pair_id]['instructorId']['stringValue'] === ACL_Forms_Config::instructor_id(), 'Istruttore mancante non completato.');
$documents['pairs'][$pair_id]['instructorId'] = ['stringValue' => 'altro-istruttore'];
$google->upsert_dogs($data, $handler);
check($documents['pairs'][$pair_id]['instructorId']['stringValue'] === 'altro-istruttore', 'Istruttore esistente sovrascritto.');

$documents['pairs'][$pair_id]['isDeleted'] = ['booleanValue' => true];
$documents['pairs'][$pair_id]['isActive'] = ['booleanValue' => false];
$documents['pairs'][$pair_id]['status'] = ['stringValue' => 'inactive'];
$restored = $google->upsert_dogs($data, $handler);
check($restored['ids'] === [$id] && count($documents['dogs']) === 1, 'Il cane di un binomio eliminato deve essere riutilizzato.');
check(count($documents['pairs']) === 2, 'Serve un binomio nuovo dopo l’eliminazione di quello precedente.');
$new_pair = end($documents['pairs']);
check($new_pair['isActive']['booleanValue'] === true && $new_pair['isDeleted']['booleanValue'] === false, 'Il nuovo binomio non è attivo.');
check($new_pair['instructorId']['stringValue'] === ACL_Forms_Config::instructor_id(), 'Istruttore mancante nel nuovo binomio.');

$data['aggiungiSecondoCane'] = true;
$data['nomeCane2'] = 'Luna';
$data['razzaCane2'] = 'Border Collie';
$two = $google->upsert_dogs($data, $handler);
check($two['count'] === 2 && count($documents['dogs']) === 2 && count($documents['pairs']) === 3, 'Il secondo cane non è stato creato.');
echo "Dog Firestore QA passed.\n";
