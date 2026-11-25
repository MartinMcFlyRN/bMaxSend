<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Services\CampaignQueue;

final class DashboardController extends Controller
{
    public function index(): void
    {
        $u = $this->user();
        $stats = [];
        $recent = [];

        if ($u['role'] === 'admin') {
            $stats = [
                __('Resellers') => DB::value("SELECT COUNT(*) FROM users WHERE role = 'reseller'"),
                __('Clients') => DB::value("SELECT COUNT(*) FROM users WHERE role = 'client'"),
                __('SMTP servers') => DB::value('SELECT COUNT(*) FROM smtp_servers'),
                __('Emails sent (30 days)') => DB::value("SELECT COUNT(*) FROM campaign_recipients WHERE status = 'sent' AND sent_at > UTC_TIMESTAMP() - INTERVAL 30 DAY"),
            ];
            $recent = DB::all("SELECT c.*, u.name AS client_name FROM campaigns c JOIN users u ON u.id = c.client_id
                               WHERE c.status <> 'draft' ORDER BY COALESCE(c.started_at, c.scheduled_at, c.updated_at) DESC LIMIT 10");
        } elseif ($u['role'] === 'reseller') {
            $stats = [
                __('Clients') => DB::value("SELECT COUNT(*) FROM users WHERE role = 'client' AND parent_id = ?", [$u['id']]),
                __('SMTP servers') => DB::value('SELECT COUNT(*) FROM smtp_assignments WHERE user_id = ?', [$u['id']]),
                __('Emails sent (30 days)') => DB::value("SELECT COUNT(*) FROM campaign_recipients r JOIN campaigns c ON c.id = r.campaign_id
                    JOIN users u ON u.id = c.client_id WHERE u.parent_id = ? AND r.status = 'sent' AND r.sent_at > UTC_TIMESTAMP() - INTERVAL 30 DAY", [$u['id']]),
            ];
            $recent = DB::all("SELECT c.*, u.name AS client_name FROM campaigns c JOIN users u ON u.id = c.client_id
                               WHERE u.parent_id = ? AND c.status <> 'draft' ORDER BY COALESCE(c.started_at, c.scheduled_at, c.updated_at) DESC LIMIT 10", [$u['id']]);
        } else {
            $stats = [
                __('Lists') => DB::value('SELECT COUNT(*) FROM lists WHERE client_id = ?', [$u['id']]),
                __('Active subscribers') => DB::value("SELECT COUNT(DISTINCT s.email) FROM subscribers s JOIN lists l ON l.id = s.list_id WHERE l.client_id = ? AND s.status = 1", [$u['id']]),
                __('Campaigns') => DB::value('SELECT COUNT(*) FROM campaigns WHERE client_id = ?', [$u['id']]),
                __('Emails sent this month') => CampaignQueue::sentThisMonth((int) $u['id'])
                    . ($u['monthly_limit'] !== null ? ' / ' . nf($u['monthly_limit']) : ''),
            ];
            $recent = DB::all('SELECT c.*, NULL AS client_name FROM campaigns c WHERE c.client_id = ? ORDER BY c.updated_at DESC LIMIT 10', [$u['id']]);
        }

        $this->view('dashboard/index', ['title' => __('Dashboard'), 'stats' => $stats, 'recent' => $recent]);
    }
}
