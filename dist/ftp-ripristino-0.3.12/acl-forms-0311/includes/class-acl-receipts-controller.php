<?php

if (!defined('ABSPATH')) {
    exit;
}

final class ACL_Receipts_Controller
{
    private const LOCK_OPTION = 'acl_forms_receipt_issue_lock';

    public function register_routes(): void
    {
        register_rest_route('acl-forms/v1', '/receipts/init', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'init'],
            'permission_callback' => [$this, 'permission'],
        ]);
        register_rest_route('acl-forms/v1', '/receipts', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'submit'],
            'permission_callback' => [$this, 'permission'],
        ]);
    }

    public function permission(): bool|WP_Error
    {
        return current_user_can('edit_posts')
            ? true
            : new WP_Error('acl_receipts_forbidden', 'Accesso riservato alla segreteria.', ['status' => 403]);
    }

    public function init(): WP_REST_Response|WP_Error
    {
        $google = new ACL_Forms_Google();
        $sheet_id = ACL_Forms_Config::receipts_sheet_id();
        $number_result = $google->get_values($sheet_id, 'Impostazioni!B1');
        if (is_wp_error($number_result)) {
            return $number_result;
        }
        $contacts_result = $google->get_values(ACL_Forms_Config::registration_sheet_id(), 'Soci!B2:D');
        $contacts = [];
        if (!is_wp_error($contacts_result)) {
            foreach (($contacts_result['values'] ?? []) as $row) {
                if (!empty($row[0]) && !empty($row[2]) && is_email($row[2])) {
                    $contacts[] = ['nome' => sanitize_text_field($row[0]), 'cognome' => sanitize_text_field($row[1] ?? ''), 'email' => sanitize_email($row[2])];
                }
            }
        }
        $last = (int) ($number_result['values'][0][0] ?? 0);
        return new WP_REST_Response(['nextNumber' => $last + 1, 'contacts' => $contacts]);
    }

    private function clean(array $input): array
    {
        return [
            'numeroRicevuta' => absint($input['numeroRicevuta'] ?? 0),
            'dataRicevuta' => sanitize_text_field((string) ($input['dataRicevuta'] ?? '')),
            'ricevutoDa' => sanitize_text_field((string) ($input['ricevutoDa'] ?? '')),
            'emailPagante' => sanitize_email((string) ($input['emailPagante'] ?? '')),
            'pseudonimo' => sanitize_text_field((string) ($input['pseudonimo'] ?? '')),
            'ricevutaPer' => sanitize_textarea_field((string) ($input['ricevutaPer'] ?? '')),
            'denaroRicevuto' => round((float) ($input['denaroRicevuto'] ?? 0), 2),
            'modalitaPagamento' => sanitize_key((string) ($input['modalitaPagamento'] ?? '')),
            'educatoreTecnico' => sanitize_text_field((string) ($input['educatoreTecnico'] ?? '')),
        ];
    }

    private function validate(array $data): bool|WP_Error
    {
        if ($data['numeroRicevuta'] < 1 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['dataRicevuta'])) {
            return new WP_Error('acl_invalid_receipt', 'Numero o data della ricevuta non validi.', ['status' => 400]);
        }
        if ($data['ricevutoDa'] === '' || !is_email($data['emailPagante']) || $data['ricevutaPer'] === '' || $data['educatoreTecnico'] === '') {
            return new WP_Error('acl_invalid_receipt', 'Compila tutti i campi obbligatori.', ['status' => 400]);
        }
        if ($data['denaroRicevuto'] <= 0 || !in_array($data['modalitaPagamento'], ['contanti','bonifico','pos','paypal'], true)) {
            return new WP_Error('acl_invalid_payment', 'Importo o modalità di pagamento non validi.', ['status' => 400]);
        }
        return true;
    }

    private function acquire_issue_lock(): bool
    {
        $existing = (int) get_option(self::LOCK_OPTION, 0);
        if ($existing > 0 && $existing < time() - 120) {
            delete_option(self::LOCK_OPTION);
        }
        if (!add_option(self::LOCK_OPTION, time(), '', false)) {
            return false;
        }
        register_shutdown_function(static function (): void {
            delete_option(self::LOCK_OPTION);
        });
        return true;
    }

    private function first_free_row(ACL_Forms_Google $google, string $sheet, string $range, int $minimum): int
    {
        $values = $google->get_values($sheet, $range);
        if (is_wp_error($values)) {
            return $minimum;
        }
        return max($minimum, $minimum + count($values['values'] ?? []));
    }

    private function save_cash(ACL_Forms_Google $google, array $data): array|WP_Error|null
    {
        if ($data['modalitaPagamento'] !== 'contanti') {
            return null;
        }
        $sheet = ACL_Forms_Config::receipts_sheet_id();
        $row = $this->first_free_row($google, $sheet, 'Cassa!C4:C', 4);
        return $google->update_values($sheet, "Cassa!C{$row}:H{$row}", [[
            $data['dataRicevuta'], $data['ricevutaPer'], '', '', $data['ricevutoDa'], $data['denaroRicevuto'],
        ]]);
    }

    private function save_instructor(ACL_Forms_Google $google, array $data): array|WP_Error|null
    {
        $mapping = [
            '10 lezioni di agility/hoopers' => ['AGILITY', 10],
            '10 lezioni di educazione' => ['EDUCAZIONE', 10],
            '5 lezioni di educazione' => ['EDUCAZIONE', 5],
            'Lezione singola' => ['EDUCAZIONE', 1],
            'Colloquio e valutazione del cane' => ['COLLOQUIO', 1],
        ];
        if ($data['educatoreTecnico'] === 'Generico' || !isset($mapping[$data['ricevutaPer']])) {
            return null;
        }
        $sheet = ACL_Forms_Config::receipts_sheet_id();
        $tab = str_replace("'", "''", $data['educatoreTecnico']);
        $row = $this->first_free_row($google, $sheet, "'{$tab}'!C19:C", 19);
        [$cause, $lessons] = $mapping[$data['ricevutaPer']];
        $amounts = ['', '', ''];
        if ($data['modalitaPagamento'] === 'contanti') {
            $amounts[1] = $data['denaroRicevuto'];
        } else {
            $amounts[0] = $data['denaroRicevuto'];
        }
        return $google->update_values($sheet, "'{$tab}'!B{$row}:K{$row}", [[
            $data['dataRicevuta'], $data['pseudonimo'] ?: $data['ricevutoDa'], $cause, $lessons, '', '', '', ...$amounts,
        ]]);
    }

    public function submit(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $input = $request->get_json_params();
        if (!is_array($input)) {
            return new WP_Error('acl_invalid_receipt', 'Richiesta non valida.', ['status' => 400]);
        }
        $data = $this->clean($input);
        $valid = $this->validate($data);
        if (is_wp_error($valid)) {
            return $valid;
        }
        if (!$this->acquire_issue_lock()) {
            return new WP_Error('acl_receipt_busy', 'Un’altra ricevuta è in elaborazione. Attendi qualche secondo e riprova.', ['status' => 409]);
        }
        $submission_id = sanitize_text_field((string) ($input['submissionId'] ?? wp_generate_uuid4()));
        $identifier = sprintf('ricevuta-%06d-%s', $data['numeroRicevuta'], $submission_id);
        $google = new ACL_Forms_Google();
        $sheet = ACL_Forms_Config::receipts_sheet_id();
        $current = $google->get_values($sheet, 'Impostazioni!B1');
        if (is_wp_error($current)) {
            return $current;
        }
        $expected = ((int) ($current['values'][0][0] ?? 0)) + 1;
        if ($data['numeroRicevuta'] !== $expected) {
            return new WP_Error('acl_receipt_number_conflict', "Il prossimo numero disponibile è {$expected}. Ricarica il modulo.", ['status' => 409]);
        }
        $pdf = ACL_Forms_Pdf::receipt($data);
        if (is_wp_error($pdf)) {
            return $pdf;
        }
        $stored = ACL_Forms_Storage::save_pdf_bytes('receipts', $identifier, $pdf);
        if (is_wp_error($stored)) {
            return $stored;
        }

        $main = $google->append_row($sheet, 'Ricevute!A:K', [
            current_time('mysql'), $data['numeroRicevuta'], $data['dataRicevuta'], $data['ricevutoDa'], $data['emailPagante'],
            $data['ricevutaPer'], $data['modalitaPagamento'], $data['educatoreTecnico'], $data['denaroRicevuto'], 'Emessa', 'Hash: ' . $stored['hash'],
        ]);
        $number = is_wp_error($main) ? $main : $google->update_values($sheet, 'Impostazioni!B1', [[$data['numeroRicevuta']]]);
        $cash = $this->save_cash($google, $data);
        $instructor = $this->save_instructor($google, $data);
        $mail = ACL_Forms_Mailer::receipt($data, $stored['path'], $stored['hash']);

        $audit = [
            'receiptNumber' => $data['numeroRicevuta'],
            'documentHash' => $stored['hash'],
            'documentSize' => $stored['size'],
            'createdAt' => gmdate('c'),
            'integrations' => [
                'googleSheets' => !is_wp_error($main) && !is_wp_error($number),
                'cashSheet' => !is_wp_error($cash),
                'instructorSheet' => !is_wp_error($instructor),
                'emailUser' => $mail['user'],
                'emailAdmin' => $mail['admin'],
            ],
            'email' => [
                'transport' => $mail['transport'],
                'userMessageId' => $mail['userMessageId'],
                'adminMessageId' => $mail['adminMessageId'],
            ],
        ];
        ACL_Forms_Storage::save_audit($stored['path'], $audit);

        if (is_wp_error($main) || is_wp_error($number) || is_wp_error($cash) || is_wp_error($instructor) || !$mail['user'] || !$mail['admin']) {
            return new WP_Error('acl_receipt_partial_failure', 'La ricevuta è stata archiviata, ma una delle integrazioni non è riuscita. Controlla il registro della pratica.', ['status' => 502]);
        }
        return new WP_REST_Response(['success' => true, 'receiptNumber' => $data['numeroRicevuta'], 'documentHash' => $stored['hash']], 201);
    }
}
