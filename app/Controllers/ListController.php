<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\DB;
use App\Core\Paginator;

final class ListController extends Controller
{
    public function index(): void
    {
        $lists = DB::all(
            "SELECT l.*,
                    (SELECT COUNT(*) FROM subscribers s WHERE s.list_id = l.id AND s.status = 'subscribed') AS active_count,
                    (SELECT COUNT(*) FROM subscribers s WHERE s.list_id = l.id) AS total_count
             FROM lists l WHERE l.client_id = ? ORDER BY l.name",
            [$this->uid()]
        );
        $this->view('lists/index', ['title' => __('Lists'), 'lists' => $lists, 'limit' => $this->user()['max_subscribers'],
            'used' => $this->subscriberCount()]);
    }

    public function store(): void
    {
        $name = mb_substr((string) input('name', ''), 0, 150);
        if ($name === '') {
            $this->fail([__('Name is required.')], '/lists');
        }
        $id = DB::insert('lists', ['client_id' => $this->uid(), 'name' => $name,
            'description' => mb_substr((string) input('description', ''), 0, 500) ?: null,
            'created_at' => now_utc(), 'updated_at' => now_utc()]);
        Audit::log('list.create', ['id' => $id]);
        flash('success', __('List created.'));
        redirect('/lists/' . $id);
    }

    public function show(string $id): void
    {
        $list = $this->find($id);
        $where = ['list_id = ?'];
        $params = [$list['id']];
        $status = (string) input('status', '');
        if (in_array($status, ['subscribed', 'unsubscribed', 'bounced', 'complained'], true)) {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        $q = (string) input('q', '');
        if ($q !== '') {
            $where[] = '(email LIKE ? OR first_name LIKE ? OR last_name LIKE ?)';
            array_push($params, "%$q%", "%$q%", "%$q%");
        }
        $w = implode(' AND ', $where);
        $pg = new Paginator((int) DB::value("SELECT COUNT(*) FROM subscribers WHERE $w", $params), 100);
        $subs = DB::all("SELECT * FROM subscribers WHERE $w ORDER BY id DESC LIMIT {$pg->perPage} OFFSET {$pg->offset}", $params);
        $counts = [];
        foreach (DB::all('SELECT status, COUNT(*) n FROM subscribers WHERE list_id = ? GROUP BY status', [$list['id']]) as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }
        $this->view('lists/show', ['title' => $list['name'], 'list' => $list, 'subs' => $subs, 'pg' => $pg,
            'status' => $status, 'q' => $q, 'counts' => $counts]);
    }

    public function update(string $id): void
    {
        $list = $this->find($id);
        $name = mb_substr((string) input('name', ''), 0, 150);
        if ($name === '') {
            $this->fail([__('Name is required.')], '/lists/' . $list['id']);
        }
        DB::update('lists', ['name' => $name, 'description' => mb_substr((string) input('description', ''), 0, 500) ?: null,
            'updated_at' => now_utc()], 'id = ?', [$list['id']]);
        flash('success', __('List updated.'));
        redirect('/lists/' . $list['id']);
    }

    public function delete(string $id): void
    {
        $list = $this->find($id);
        $busy = (int) DB::value("SELECT COUNT(*) FROM campaign_lists cl JOIN campaigns c ON c.id = cl.campaign_id
                                 WHERE cl.list_id = ? AND c.status = 'scheduled'", [$list['id']]);
        if ($busy > 0) {
            flash('error', __('The list is used by :n scheduled campaigns.', ['n' => $busy]));
            redirect('/lists/' . $list['id']);
        }
        DB::query('DELETE FROM lists WHERE id = ?', [$list['id']]);
        Audit::log('list.delete', ['id' => (int) $list['id'], 'name' => $list['name']]);
        flash('success', __('List deleted.'));
        redirect('/lists');
    }

    public function addSubscriber(string $id): void
    {
        $list = $this->find($id);
        $email = mb_strtolower((string) input('email', ''));
        if (!is_valid_email($email)) {
            $this->fail([__('Invalid email address.')], '/lists/' . $list['id']);
        }
        if (DB::value('SELECT 1 FROM subscribers WHERE list_id = ? AND email = ?', [$list['id'], $email])) {
            $this->fail([__('This address is already in the list.')], '/lists/' . $list['id']);
        }
        if (!$this->hasRoom(1)) {
            $this->fail([__('Subscriber limit reached for your account.')], '/lists/' . $list['id']);
        }
        DB::insert('subscribers', [
            'list_id' => $list['id'], 'email' => $email,
            'first_name' => mb_substr((string) input('first_name', ''), 0, 100) ?: null,
            'last_name' => mb_substr((string) input('last_name', ''), 0, 100) ?: null,
            'fields' => null, 'status' => 'subscribed', 'created_at' => now_utc(), 'updated_at' => now_utc(),
        ]);
        flash('success', __('Subscriber added.'));
        redirect('/lists/' . $list['id']);
    }

    public function bulk(string $id): void
    {
        $list = $this->find($id);
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
        $action = (string) input('action', '');
        if ($ids) {
            $in = DB::in($ids);
            $params = array_merge([$list['id']], $ids);
            if ($action === 'delete') {
                DB::query("DELETE FROM subscribers WHERE list_id = ? AND id IN ($in)", $params);
            } elseif ($action === 'unsubscribe') {
                DB::query("UPDATE subscribers SET status = 'unsubscribed', unsubscribed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
                           WHERE list_id = ? AND id IN ($in)", $params);
            } elseif ($action === 'suppress') {
                foreach (DB::column("SELECT email FROM subscribers WHERE list_id = ? AND id IN ($in)", $params) as $em) {
                    DB::query("INSERT IGNORE INTO suppressions (client_id, email, reason, created_at) VALUES (?, ?, 'manual', UTC_TIMESTAMP())",
                        [$this->uid(), $em]);
                }
            }
            Audit::log('subscribers.bulk', ['list' => (int) $list['id'], 'action' => $action, 'count' => count($ids)]);
            flash('success', __(':n subscribers updated.', ['n' => count($ids)]));
        }
        back();
    }

    public function importForm(string $id): void
    {
        $this->view('lists/import', ['title' => __('Import subscribers'), 'list' => $this->find($id)]);
    }

    /**
     * CSV import. The first row is the header. Recognised columns: email,
     * first_name / nome, last_name / cognome; any other column becomes a custom field.
     */
    public function import(string $id): void
    {
        $list = $this->find($id);
        $csv = '';
        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            if ($_FILES['file']['size'] > (int) config('import.max_bytes', 20 * 1024 * 1024)) {
                $this->fail([__('The file is too large.')], '/lists/' . $list['id'] . '/import');
            }
            $csv = (string) file_get_contents($_FILES['file']['tmp_name']);
        } else {
            $csv = (string) ($_POST['paste'] ?? '');
        }
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv); // BOM
        if (!mb_check_encoding($csv, 'UTF-8')) {
            $csv = mb_convert_encoding($csv, 'UTF-8', 'Windows-1252');
        }
        if (trim($csv) === '') {
            $this->fail([__('Nothing to import.')], '/lists/' . $list['id'] . '/import');
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        $delim = $this->detectDelimiter($lines[0]);
        $first = (string) array_shift($lines);
        $rawHeader = str_getcsv($first, $delim);
        $header = array_map(fn($h) => $this->normalizeHeader((string) $h), $rawHeader);
        $emailCol = array_search('email', $header, true);
        if ($emailCol === false) {
            // No header row: the first column contains the addresses
            if (is_valid_email(trim((string) ($rawHeader[0] ?? ''), " \t\"'"))) {
                array_unshift($lines, $first);
                $header = array_merge(['email'], array_map(fn($i) => 'field' . $i, range(1, max(1, count($rawHeader) - 1))));
                if (count($rawHeader) === 1) {
                    $header = ['email'];
                }
                $emailCol = 0;
            } else {
                $this->fail([__('The CSV must contain an "email" column.')], '/lists/' . $list['id'] . '/import');
            }
        }
        $updateExisting = (bool) input('update_existing');
        $added = $updated = $skipped = $invalid = 0;
        $room = $this->room();

        DB::transaction(function () use ($lines, $delim, $header, $emailCol, $list, $updateExisting, &$added, &$updated, &$skipped, &$invalid, &$room) {
            foreach ($lines as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $cols = str_getcsv($line, $delim);
                $email = mb_strtolower(trim((string) ($cols[$emailCol] ?? '')));
                if (!is_valid_email($email)) {
                    $invalid++;
                    continue;
                }
                $first = $last = null;
                $fields = [];
                foreach ($header as $i => $h) {
                    if ($i === $emailCol || $h === '') {
                        continue;
                    }
                    $v = trim((string) ($cols[$i] ?? ''));
                    if ($h === 'first_name') {
                        $first = mb_substr($v, 0, 100) ?: null;
                    } elseif ($h === 'last_name') {
                        $last = mb_substr($v, 0, 100) ?: null;
                    } elseif ($v !== '') {
                        $fields[$h] = mb_substr($v, 0, 500);
                    }
                }
                $existing = DB::one('SELECT id, fields FROM subscribers WHERE list_id = ? AND email = ?', [$list['id'], $email]);
                if ($existing) {
                    if ($updateExisting) {
                        $merged = array_merge(json_decode((string) $existing['fields'], true) ?: [], $fields);
                        DB::update('subscribers', array_filter([
                            'first_name' => $first, 'last_name' => $last,
                            'fields' => $merged ? json_encode($merged, JSON_UNESCAPED_UNICODE) : null,
                            'updated_at' => now_utc(),
                        ], fn($v) => $v !== null), 'id = ?', [$existing['id']]);
                        $updated++;
                    } else {
                        $skipped++;
                    }
                    continue;
                }
                if ($room !== null && $room <= 0) {
                    $skipped++;
                    continue;
                }
                DB::insert('subscribers', [
                    'list_id' => $list['id'], 'email' => $email, 'first_name' => $first, 'last_name' => $last,
                    'fields' => $fields ? json_encode($fields, JSON_UNESCAPED_UNICODE) : null,
                    'status' => 'subscribed', 'created_at' => now_utc(), 'updated_at' => now_utc(),
                ]);
                $added++;
                if ($room !== null) {
                    $room--;
                }
            }
        });

        Audit::log('subscribers.import', ['list' => (int) $list['id'], 'added' => $added, 'updated' => $updated]);
        flash('success', __('Import completed: :a added, :u updated, :s skipped, :i invalid.',
            ['a' => $added, 'u' => $updated, 's' => $skipped, 'i' => $invalid]));
        if ($room !== null && $room <= 0 && $skipped > 0) {
            flash('error', __('Subscriber limit reached for your account.'));
        }
        redirect('/lists/' . $list['id']);
    }

    public function export(string $id): void
    {
        $list = $this->find($id);
        $rows = DB::all('SELECT email, first_name, last_name, status, fields, created_at, unsubscribed_at FROM subscribers WHERE list_id = ? ORDER BY id', [$list['id']]);
        $extra = [];
        foreach ($rows as $r) {
            foreach (array_keys(json_decode((string) $r['fields'], true) ?: []) as $k) {
                $extra[$k] = true;
            }
        }
        $extra = array_keys($extra);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="list-' . $list['id'] . '-' . gmdate('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_merge(['email', 'first_name', 'last_name', 'status', 'created_at', 'unsubscribed_at'], $extra), ';');
        foreach ($rows as $r) {
            $f = json_decode((string) $r['fields'], true) ?: [];
            $line = [$r['email'], $r['first_name'], $r['last_name'], $r['status'], $r['created_at'], $r['unsubscribed_at']];
            foreach ($extra as $k) {
                $line[] = $f[$k] ?? '';
            }
            fputcsv($out, $line, ';');
        }
        fclose($out);
        exit;
    }

    // --- Suppression list -------------------------------------------------------

    public function suppressions(): void
    {
        $q = (string) input('q', '');
        $params = [$this->uid()];
        $w = 'client_id = ?';
        if ($q !== '') {
            $w .= ' AND email LIKE ?';
            $params[] = "%$q%";
        }
        $pg = new Paginator((int) DB::value("SELECT COUNT(*) FROM suppressions WHERE $w", $params), 100);
        $rows = DB::all("SELECT * FROM suppressions WHERE $w ORDER BY created_at DESC LIMIT {$pg->perPage} OFFSET {$pg->offset}", $params);
        $this->view('lists/suppressions', ['title' => __('Suppression list'), 'rows' => $rows, 'pg' => $pg, 'q' => $q]);
    }

    public function addSuppression(): void
    {
        $n = 0;
        foreach (preg_split('/[\s,;]+/', (string) ($_POST['emails'] ?? '')) as $em) {
            $em = mb_strtolower(trim($em));
            if (is_valid_email($em)) {
                $n += DB::query("INSERT IGNORE INTO suppressions (client_id, email, reason, created_at) VALUES (?, ?, 'manual', UTC_TIMESTAMP())",
                    [$this->uid(), $em])->rowCount();
            }
        }
        flash('success', __(':n addresses added to the suppression list.', ['n' => $n]));
        redirect('/suppressions');
    }

    public function deleteSuppression(): void
    {
        DB::query('DELETE FROM suppressions WHERE client_id = ? AND email = ?', [$this->uid(), mb_strtolower((string) input('email', ''))]);
        flash('success', __('Address removed from the suppression list.'));
        redirect('/suppressions');
    }

    // --- helpers -------------------------------------------------------------

    private function find(string $id): array
    {
        $list = DB::one('SELECT * FROM lists WHERE id = ? AND client_id = ?', [$this->intParam($id), $this->uid()]);
        if (!$list) {
            $this->abort(404);
        }
        return $list;
    }

    private function subscriberCount(): int
    {
        return (int) DB::value('SELECT COUNT(*) FROM subscribers s JOIN lists l ON l.id = s.list_id WHERE l.client_id = ?', [$this->uid()]);
    }

    /** Remaining subscriber slots (null = unlimited). */
    private function room(): ?int
    {
        $max = $this->user()['max_subscribers'];
        return $max === null ? null : max(0, (int) $max - $this->subscriberCount());
    }

    private function hasRoom(int $n): bool
    {
        $room = $this->room();
        return $room === null || $room >= $n;
    }

    private function detectDelimiter(string $line): string
    {
        $best = ',';
        $max = 0;
        foreach ([',', ';', "\t", '|'] as $d) {
            $c = substr_count($line, $d);
            if ($c > $max) {
                $max = $c;
                $best = $d;
            }
        }
        return $best;
    }

    private function normalizeHeader(string $h): string
    {
        $h = mb_strtolower(trim($h, " \t\"'"));
        $h = preg_replace('/[^a-z0-9_]+/', '_', $h);
        $h = trim((string) $h, '_');
        return match ($h) {
            'e_mail', 'mail', 'email_address', 'indirizzo_email' => 'email',
            'nome', 'firstname', 'first', 'name' => 'first_name',
            'cognome', 'lastname', 'last', 'surname' => 'last_name',
            default => $h,
        };
    }
}
