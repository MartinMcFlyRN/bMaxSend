<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\CampaignController;
use App\Controllers\DashboardController;
use App\Controllers\DomainController;
use App\Controllers\ListController;
use App\Controllers\ProfileController;
use App\Controllers\PublicController;
use App\Controllers\SmtpController;
use App\Controllers\UserController;

/** @var App\Core\Router $router */

$pub = ['public' => true];
$track = ['public' => true, 'tracking' => true];
$admin = ['roles' => ['admin']];
$managers = ['roles' => ['admin', 'reseller']];
$client = ['roles' => ['client']];

// --- Public email endpoints (also served on alias domains) -------------------
$router->get('/v/{token}', [PublicController::class, 'webVersion'], $track);
$router->get('/o/{token}', [PublicController::class, 'open'], $track);
$router->get('/c/{token}/{link}', [PublicController::class, 'click'], $track);
$router->get('/u/{token}', [PublicController::class, 'unsubscribeForm'], $track);
// RFC 8058 one-click unsubscribe: mail clients POST without a CSRF token.
$router->post('/u/{token}', [PublicController::class, 'unsubscribe'], $track + ['csrf' => false]);
$router->get('/health', [PublicController::class, 'health'], $track);

// --- Authentication ------------------------------------------------------------
$router->get('/login', [AuthController::class, 'loginForm'], $pub);
$router->post('/login', [AuthController::class, 'login'], $pub);
$router->post('/logout', [AuthController::class, 'logout']);
$router->post('/impersonate/stop', [AuthController::class, 'stopImpersonating']);

$router->get('/', [DashboardController::class, 'index']);
$router->get('/profile', [ProfileController::class, 'edit']);
$router->post('/profile', [ProfileController::class, 'update']);

// --- Users (admin: everyone, reseller: its clients) -----------------------------
$router->get('/users', [UserController::class, 'index'], $managers);
$router->get('/users/create', [UserController::class, 'create'], $managers);
$router->post('/users', [UserController::class, 'store'], $managers);
$router->get('/users/{id}/edit', [UserController::class, 'edit'], $managers);
$router->post('/users/{id}', [UserController::class, 'update'], $managers);
$router->post('/users/{id}/delete', [UserController::class, 'delete'], $managers);
$router->post('/users/{id}/impersonate', [UserController::class, 'impersonate'], $managers);

// --- SMTP servers --------------------------------------------------------------
$router->get('/smtp', [SmtpController::class, 'index']);
$router->get('/smtp/create', [SmtpController::class, 'create'], $admin);
$router->post('/smtp', [SmtpController::class, 'store'], $admin);
$router->get('/smtp/{id}/edit', [SmtpController::class, 'edit'], $admin);
$router->post('/smtp/{id}', [SmtpController::class, 'update'], $admin);
$router->post('/smtp/{id}/delete', [SmtpController::class, 'delete'], $admin);
$router->post('/smtp/{id}/test', [SmtpController::class, 'test'], $admin);
$router->get('/smtp/{id}/assign', [SmtpController::class, 'assignForm'], $managers);
$router->post('/smtp/{id}/assign', [SmtpController::class, 'assign'], $managers);

// --- Alias domains ---------------------------------------------------------------
$router->get('/domains', [DomainController::class, 'index']);
$router->post('/domains', [DomainController::class, 'store']);
$router->get('/domains/{id}', [DomainController::class, 'show']);
$router->post('/domains/{id}', [DomainController::class, 'update']);
$router->post('/domains/{id}/verify', [DomainController::class, 'verify']);
$router->post('/domains/{id}/force', [DomainController::class, 'forceVerify'], $admin);
$router->post('/domains/{id}/delete', [DomainController::class, 'delete']);

// --- Lists & subscribers (clients) -------------------------------------------------
$router->get('/lists', [ListController::class, 'index'], $client);
$router->post('/lists', [ListController::class, 'store'], $client);
$router->get('/lists/{id}', [ListController::class, 'show'], $client);
$router->post('/lists/{id}', [ListController::class, 'update'], $client);
$router->post('/lists/{id}/delete', [ListController::class, 'delete'], $client);
$router->post('/lists/{id}/subscribers', [ListController::class, 'addSubscriber'], $client);
$router->post('/lists/{id}/subscribers/bulk', [ListController::class, 'bulk'], $client);
$router->get('/lists/{id}/subscribers/{sid}', [ListController::class, 'editSubscriber'], $client);
$router->post('/lists/{id}/subscribers/{sid}', [ListController::class, 'updateSubscriber'], $client);
$router->post('/lists/{id}/subscribers/{sid}/delete', [ListController::class, 'deleteSubscriber'], $client);
$router->post('/lists/{id}/fields', [ListController::class, 'addField'], $client);
$router->post('/lists/{id}/fields/{fid}', [ListController::class, 'updateField'], $client);
$router->post('/lists/{id}/fields/{fid}/delete', [ListController::class, 'deleteField'], $client);
$router->get('/lists/{id}/import', [ListController::class, 'importForm'], $client);
$router->post('/lists/{id}/import', [ListController::class, 'import'], $client);
$router->get('/lists/{id}/export', [ListController::class, 'export'], $client);
$router->get('/suppressions', [ListController::class, 'suppressions'], $client);
$router->post('/suppressions', [ListController::class, 'addSuppression'], $client);
$router->post('/suppressions/delete', [ListController::class, 'deleteSuppression'], $client);

// --- Campaigns (clients) --------------------------------------------------------
$router->get('/campaigns', [CampaignController::class, 'index'], $client);
$router->get('/campaigns/create', [CampaignController::class, 'create'], $client);
$router->post('/campaigns', [CampaignController::class, 'store'], $client);
$router->get('/campaigns/{id}/edit', [CampaignController::class, 'edit'], $client);
$router->post('/campaigns/{id}', [CampaignController::class, 'update'], $client);
$router->get('/campaigns/{id}/preview', [CampaignController::class, 'preview'], $client);
$router->post('/campaigns/{id}/test', [CampaignController::class, 'testSend'], $client);
$router->post('/campaigns/{id}/schedule', [CampaignController::class, 'schedule'], $client);
$router->post('/campaigns/{id}/unschedule', [CampaignController::class, 'unschedule'], $client);
$router->post('/campaigns/{id}/pause', [CampaignController::class, 'pause'], $client);
$router->post('/campaigns/{id}/resume', [CampaignController::class, 'resume'], $client);
$router->post('/campaigns/{id}/cancel', [CampaignController::class, 'cancel'], $client);
$router->post('/campaigns/{id}/duplicate', [CampaignController::class, 'duplicate'], $client);
$router->post('/campaigns/{id}/delete', [CampaignController::class, 'delete'], $client);
$router->get('/campaigns/{id}/report', [CampaignController::class, 'report'], $client);
$router->get('/campaigns/{id}/report/export', [CampaignController::class, 'exportReport'], $client);
