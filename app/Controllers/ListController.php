<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\DB;
use App\Core\Paginator;
use App\Services\ListFields;

final class ListController extends Controller
{
    public function index(): void
    {
        $lists = DB::all(
            "SELECT l.*,
                    (SELECT COUNT(*) FROM subscribers s WHERE s.list_id = l.id AND s.status = 1) AS active_count,
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
        if (in_array($status, ['0', '1', '2'], true)) {
            $where[] = 'status = ?';
            $params[] = (int) $status;
        }
        $q = (string) input('q', '');
        if ($q !== '') {
            $where[] = '(email LIKE ? OR name LIKE ? OR fields LIKE ?)';
            array_push($params, "%$q%", "%$q%", "%$q%");
        }
        $w = implode(' AND ', $where);
        $pg = new Paginator((int) DB::value("SELECT COUNT(*) FROM subscribers WHERE $w", $params), 100);
        $subs = DB::all("SELECT * FROM subscribers WHERE $w ORDER BY id DESC LIMIT {$pg->perPage} OFFSET {$pg->offset}", $params);
        $counts = [0 => 0, 1 => 0, 2 => 0];
        foreach (DB::all('SELECT status, COUNT(*) n FROM subscribers WHERE list_id = ? GROUP BY status', [$list['id']]) as $r) {
            $counts[(int) $r['status']] = (int) $r['n'];
        }
        $this->view('lists/show', ['title' => $list['name'], 'list' => $list, 'subs' => $subs, 'pg' => $pg,
            'status' => $status, 'q' => $q, 'counts' => $counts, 'fields' => ListFields::all((int) $list['id'])]);
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

    // --- Custom fields ------------------------------------------------------------

    public function addField(string $id): void
    {
        $list = $this->find($id);
        $label = trim((string) input('label', ''));
        $key = ListFields::normalizeKey((string) input('field_key', '') ?: $label);
        $back = '/lists/' . $list['id'] . '#fields';
        if ($key === '') {
            $this->fail([__('Invalid field name.')], $back);
        }
        if (ListFields::isReserved($key)) {
            $this->fail([__('":key" is a reserved name.', ['key' => $key])], $back);
        }
        if (!ListFields::ensure((int) $list['id'], [$key => $label ?: $key])) {
            $this->fail([__('A field named ":key" already exists.', ['key' => $key])], $back);
        }
        flash('success', __('Field :key added.', ['key' => $key]));
        redirect($back);
    }

    public function updateField(string $id, string $fid): void
    {
        $list = $this->find($id);
        $err = ListFields::update((int) $list['id'], $this->intParam($fid), (string) input('field_key', ''), (string) input('label', ''));
        $err ? flash('error', $err) : flash('success', __('Field updated.'));
        redirect('/lists/' . $list['id'] . '#fields');
    }

    public function deleteField(string $id, string $fid): void
    {
        $list = $this->find($id);
        ListFields::delete((int) $list['id'], $this->intParam($fid));
        flash('success', __('Field deleted.'));
        redirect('/lists/' . $list['id'] . '#fields');
    }

    // --- Subscribers -------------------------------------------------------------

    public function addSubscriber(string $id): void
    {
        $list = $this->find($id);
        $back = '/lists/' . $list['id'];
        $email = mb_strtolower((string) input('email', ''));
        if (!is_valid_email($email)) {
            $this->fail([__('Invalid email address.')], $back);
        }
        if (DB::value('SELECT 1 FROM subscribers WHERE list_id = ? AND email = ?', [$list['id'], $email])) {
            $this->fail([__('This address is already in the list.')], $back);
        }
        if (!$this->hasRoom(1)) {
            $this->fail([__('Subscriber limit reached for your account.')], $back);
        }
        $fields = $this->postedFields((int) $list['id']);
        DB::insert('subscribers', [
            'list_id' => $list['id'], 'email' => $email,
            'name' => mb_substr((string) input('name', ''), 0, 190) ?: null,
            'fields' => $fields ? json_encode($fields, JSON_UNESCAPED_UNICODE) : null,
            'status' => ListFields::parseStatus((string) input('status', '0')),
            'track' => ListFields::parseTrack((string) input('track', '0')),
            'created_at' => now_utc(), 'updated_at' => now_utc(),
        ]);
        flash('success', __('Subscriber added.'));
        redirect($back);
    }

    public function editSubscriber(string $id, string $sid): void
    {
        $list = $this->find($id);
        $sub = $this->findSubscriber($list, $sid);
        $this->view('lists/subscriber', ['title' => $sub['email'], 'list' => $list, 'sub' => $sub,
            'fields' => ListFields::all((int) $list['id']), 'values' => json_decode((string) $sub['fields'], true) ?: []]);
    }

    public function updateSubscriber(string $id, string $sid): void
    {
        $list = $this->find($id);
        $sub = $this->findSubscriber($list, $sid);
        $back = '/lists/' . $list['id'] . '/subscribers/' . $sub['id'];
        $email = mb_strtolower((string) input('email', ''));
        if (!is_valid_email($email)) {
            $this->fail([__('Invalid email address.')], $back);
        }
        if (DB::value('SELECT 1 FROM subscribers WHERE list_id = ? AND email = ? AND id <> ?', [$list['id'], $email, $sub['id']])) {
            $this->fail([__('This address is already in the list.')], $back);
        }
        $status = ListFields::parseStatus((string) input('status', '0'));
        $values = array_merge(json_decode((string) $sub['fields'], true) ?: [], $this->postedFields((int) $list['id'], true));
        $values = array_filter($values, fn($v) => $v !== '' && $v !== null);
        DB::update('subscribers', [
            'email' => $email,
            'name' => mb_substr((string) input('name', ''), 0, 190) ?: null,
            'fields' => $values ? json_encode($values, JSON_UNESCAPED_UNICODE) : null,
            'status' => $status,
            'track' => ListFields::parseTrack((string) input('track', '0')),
            'unsubscribed_at' => $status === ListFields::STATUS_UNSUBSCRIBED ? ($sub['unsubscribed_at'] ?: now_utc()) : null,
            'updated_at' => now_utc(),
        ], 'id = ?', [$sub['id']]);
        Audit::log('subscriber.update', ['id' => (int) $sub['id']]);
        flash('success', __('Subscriber updated.'));
        redirect($back);
    }

    public function deleteSubscriber(string $id, string $sid): void
    {
        $list = $this->find($id);
        $sub = $this->findSubscriber($list, $sid);
        DB::query('DELETE FROM subscribers WHERE id = ?', [$sub['id']]);
        flash('success', __('Subscriber deleted.'));
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
            $set = match ($action) {
                'enable' => 'status = 1, unsubscribed_at = NULL',
                'disable' => 'status = 0, unsubscribed_at = NULL',
                'unsubscribe' => 'status = 2, unsubscribed_at = COALESCE(unsubscribed_at, UTC_TIMESTAMP())',
                'track_on' => 'track = 1',
                'track_off' => 'track = 0',
                default => null,
            };
            if ($set !== null) {
                DB::query("UPDATE subscribers SET $set, updated_at = UTC_TIMESTAMP() WHERE list_id = ? AND id IN ($in)", $params);
            } elseif ($action === 'delete') {
                DB::query("DELETE FROM subscribers WHERE list_id = ? AND id IN ($in)", $params);
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

    // --- Import / export ---------------------------------------------------------------

    public function importForm(string $id): void
    {
        $list = $this->find($id);
        $this->view('lists/import', ['title' => __('Import subscribers'), 'list' => $list, 'fields' => ListFields::all((int) $list['id'])]);
    }

    /**
     * CSV import. The first row is the header: email (required), nome/name, stato/status
     * (1 enabled, 0 disabled, 2 unsubscribed), track (1/0). Missing status/track = 0.
     * Every other column becomes a custom field of the list (created automatically).
     */
    public function import(string $id): void
    {
        $list = $this->find($id);
        $listId = (int) $list['id'];
        $back = '/lists/' . $listId . '/import';
        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            if ($_FILES['file']['size'] > (int) config('import.max_bytes', 20 * 1024 * 1024)) {
                $this->fail([__('The file is too large.')], $back);
            }
            $csv = (string) file_get_contents($_FILES['file']['tmp_name']);
        } else {
            $csv = (string) ($_POST['paste'] ?? '');
        }
        $csv = (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        if (!mb_check_encoding($csv, 'UTF-8')) {
            $csv = mb_convert_encoding($csv, 'UTF-8', 'Windows-1252');
        }
        if (trim($csv) === '') {
            $this->fail([__('Nothing to import.')], $back);
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        $delim = $this->detectDelimiter($lines[0]);
        $first = (string) array_shift($lines);
        $rawHeader = str_getcsv($first, $delim);

        // Map every column: fixed (email/name/status/track) or custom field key.
        $map = [];
        $custom = [];
        foreach ($rawHeader as $i => $raw) {
            $key = ListFields::normalizeKey((string) $raw);
            $fixed = ListFields::fixedColumn($key);
            if ($fixed !== null) {
                if (!in_array($fixed, $map, true)) {
                    $map[$i] = $fixed;
                }
            } elseif ($key !== '' && !ListFields::isReserved($key) && !in_array('f:' . $key, $map, true)) {
                $map[$i] = 'f:' . $key;
                $custom[$key] = trim((string) $raw);
            }
        }
        if (!in_array('email', $map, true)) {
            if (is_valid_email(trim((string) ($rawHeader[0] ?? ''), " \t\"'"))) {
                // No header row: the first column contains the addresses
                array_unshift($lines, $first);
                $map = [0 => 'email'];
                $custom = [];
            } else {
                $this->fail([__('The CSV must contain an "email" column.')], $back);
            }
        }
        $has = array_flip($map);
        $updateExisting = (bool) input('update_existing');
        $added = $updated = $skipped = $invalid = 0;
        $room = $this->room();
        $createdFields = [];

        DB::transaction(function () use ($lines, $delim, $map, $has, $custom, $listId, $updateExisting,
            &$added, &$updated, &$skipped, &$invalid, &$room, &$createdFields) {
            $createdFields = ListFields::ensure($listId, $custom);
            foreach ($lines as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $cols = str_getcsv($line, $delim);
                $email = mb_strtolower(trim((string) ($cols[$has['email']] ?? '')));
                if (!is_valid_email($email)) {
                    $invalid++;
                    continue;
                }
                $name = isset($has['name']) ? (mb_substr(trim((string) ($cols[$has['name']] ?? '')), 0, 190) ?: null) : null;
                $fields = [];
                foreach ($map as $i => $col) {
                    if (str_starts_with($col, 'f:')) {
                        $v = trim((string) ($cols[$i] ?? ''));
                        if ($v !== '') {
                            $fields[substr($col, 2)] = mb_substr($v, 0, 1000);
                        }
                    }
                }
                $status = isset($has['status']) ? ListFields::parseStatus($cols[$has['status']] ?? '') : null;
                $track = isset($has['track']) ? ListFields::parseTrack($cols[$has['track']] ?? '') : null;

                $existing = DB::one('SELECT id, fields, status FROM subscribers WHERE list_id = ? AND email = ?', [$listId, $email]);
                if ($existing) {
                    if (!$updateExisting) {
                        $skipped++;
                        continue;
                    }
                    $data = ['updated_at' => now_utc()];
                    if ($name !== null) {
                        $data['name'] = $name;
                    }
                    if ($fields) {
                        $merged = array_merge(json_decode((string) $existing['fields'], true) ?: [], $fields);
                        $data['fields'] = json_encode($merged, JSON_UNESCAPED_UNICODE);
                    }
                    // An address that unsubscribed by itself is never re-enabled by an import.
                    if ($status !== null && (int) $existing['status'] !== ListFields::STATUS_UNSUBSCRIBED) {
                        $data['status'] = $status;
                        if ($status === ListFields::STATUS_UNSUBSCRIBED) {
                            $data['unsubscribed_at'] = now_utc();
                        }
                    }
                    if ($track !== null) {
                        $data['track'] = $track;
                    }
                    DB::update('subscribers', $data, 'id = ?', [$existing['id']]);
                    $updated++;
                    continue;
                }
                if ($room !== null && $room <= 0) {
                    $skipped++;
                    continue;
                }
                DB::insert('subscribers', [
                    'list_id' => $listId, 'email' => $email, 'name' => $name,
                    'fields' => $fields ? json_encode($fields, JSON_UNESCAPED_UNICODE) : null,
                    'status' => $status ?? ListFields::STATUS_DISABLED,
                    'track' => $track ?? 0,
                    'unsubscribed_at' => $status === ListFields::STATUS_UNSUBSCRIBED ? now_utc() : null,
                    'created_at' => now_utc(), 'updated_at' => now_utc(),
                ]);
                $added++;
                if ($room !== null) {
                    $room--;
                }
            }
        });

        Audit::log('subscribers.import', ['list' => $listId, 'added' => $added, 'updated' => $updated, 'fields' => $createdFields]);
        flash('success', __('Import completed: :a added, :u updated, :s skipped, :i invalid.',
            ['a' => $added, 'u' => $updated, 's' => $skipped, 'i' => $invalid]));
        if ($createdFields) {
            flash('info', __('New custom fields created: :list', ['list' => implode(', ', $createdFields)]));
        }
        if (!isset($has['status'])) {
            flash('info', __('The file has no "stato" column: new subscribers were imported as disabled (0). Enable them before sending.'));
        }
        if ($room !== null && $room <= 0 && $skipped > 0) {
            flash('error', __('Subscriber limit reached for your account.'));
        }
        redirect('/lists/' . $listId);
    }

    /** Export in the same format accepted by the import (round trip). */
    public function export(string $id): void
    {
        $list = $this->find($id);
        $keys = ListFields::keys((int) $list['id']);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="list-' . $list['id'] . '-' . gmdate('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_merge(['email', 'nome', 'stato', 'track'], $keys), ';');
        $st = DB::query('SELECT email, name, status, track, fields FROM subscribers WHERE list_id = ? ORDER BY id', [$list['id']]);
        while ($r = $st->fetch()) {
            $f = json_decode((string) $r['fields'], true) ?: [];
            $line = [$r['email'], $r['name'], $r['status'], $r['track']];
            foreach ($keys as $k) {
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

    private function findSubscriber(array $list, string $sid): array
    {
        $sub = DB::one('SELECT * FROM subscribers WHERE id = ? AND list_id = ?', [$this->intParam($sid), $list['id']]);
        if (!$sub) {
            $this->abort(404);
        }
        return $sub;
    }

    /** Custom field values from the form (only fields defined for the list). */
    private function postedFields(int $listId, bool $keepEmpty = false): array
    {
        $posted = (array) ($_POST['fields'] ?? []);
        $out = [];
        foreach (ListFields::keys($listId) as $k) {
            $v = trim((string) ($posted[$k] ?? ''));
            if ($v !== '' || ($keepEmpty && array_key_exists($k, $posted))) {
                $out[$k] = mb_substr($v, 0, 1000);
            }
        }
        return $out;
    }
}
