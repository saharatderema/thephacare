<?php
/**
 * ============================================================================
 * AI INSIGHT DASHBOARD v3.0 — 2 Tabs (Stats + AI)
 * ============================================================================
 *  🆕 v3.0 (2026-10-30) — 2-TAB LAYOUT:
 *    - 📊 Tab 1: สถิติภาพรวม (default) — charts, KPI, table, map
 *    - ✨ Tab 2: AI วิเคราะห์ — full-height chat + mini chips + compact filter
 *    - ✅ Default: สถิติ + จำแท็บล่าสุด (sessionStorage)
 *    - ✅ Deep link hash (#stats / #ai) + popstate handler
 *    - ✅ Keyboard shortcuts: Ctrl+1 = สถิติ, Ctrl+2 = AI
 *    - ✅ "NEW" badge บนแท็บ AI (แสดงครั้งแรกเท่านั้น, localStorage)
 *    - ✅ Cross-tab: ปุ่ม "ถาม AI" ใน Hotspot row → auto-send
 *    - ✅ Chat เต็มจอ (flex-chain, no pixel calc)
 *    - ✅ Lazy: Map init ตอน stats tab visible
 *    - ✅ Mobile: header compact, tab pill
 *  ✅ v2.2 ALL: Full-bleed layout, sticky-top-wrap
 *  ✅ v2/v1 ALL: Chat, Local Analysis, Multi-Dimension, Hotspot, Readiness
 * ============================================================================
 */

$AI_GUARD_LOGIN_URL = '../../login.php';
require_once __DIR__ . '/includes/ai_guard.php';

$currentFile = basename($_SERVER['PHP_SELF']);

$group_options = [];
foreach ($all_groups as $g) {
    $group_options[] = ['id' => $g['gstatus_id'], 'name' => $g['gstatus_name'], 'color' => $g['color']];
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>AI วิเคราะห์กลุ่มเปราะบาง | VOC System | Thepha Care</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        html, body {
            margin: 0 !important;
            padding: 0 !important;
            width: 100%;
            min-height: 100vh;
            min-height: 100dvh;
        }
        body {
            font-family: 'Kanit', sans-serif;
            background: linear-gradient(135deg, #F8FAFC 0%, #EFF6FF 100%);
            overflow-x: hidden;
        }

        .glass-effect {
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.15);
        }

        /* ============================================================
         * Layout — full-bleed, sticky-top-wrap
         * ============================================================ */
        .main-content {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            min-height: 100dvh;
            margin-left: 0 !important;
            width: 100% !important;
            min-width: 0;
            box-sizing: border-box;
        }
        @media (min-width: 1024px) {
            .main-content {
                margin-left: 288px !important;
                width: calc(100% - 288px) !important;
            }
        }
        .main-content > .sticky-top-wrap {
            position: sticky;
            top: 0;
            z-index: 30;
            flex-shrink: 0;
        }
        .main-content > main {
            flex: 1;
            min-height: 0;
            display: flex;
            flex-direction: column;
            overflow-x: clip;
        }

        /* ============================================================
         * Header — compact + responsive
         * ============================================================ */
        .header-main {
            background: linear-gradient(135deg, #1E293B 0%, #0F172A 50%, #020617 100%);
            color: #fff;
            box-shadow: 0 4px 12px rgba(0,0,0,.15);
        }
        .header-inner {
            padding: 10px 12px;
        }
        @media (min-width: 640px) {
            .header-inner { padding: 14px 20px; }
        }
        @media (min-width: 1024px) {
            .header-inner { padding: 16px 24px; }
        }

        .hdr-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 10px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 10px;
            color: #fff;
            font-size: 12px;
            font-family: inherit;
            cursor: pointer;
            transition: background 0.15s;
            white-space: nowrap;
            min-height: 38px;
        }
        .hdr-btn:hover { background: rgba(255, 255, 255, 0.18); }
        .hdr-btn-label { display: none; }
        @media (min-width: 900px) {
            .hdr-btn-label { display: inline; }
            .hdr-btn { padding: 8px 14px; }
        }

        /* ============================================================
         * Tab bar — pill / segmented
         * ============================================================ */
        .tab-bar-wrap {
            background: #F8FAFC;
            border-bottom: 1px solid #E2E8F0;
            padding: 8px 12px;
        }
        @media (min-width: 640px) {
            .tab-bar-wrap { padding: 10px 20px; }
        }
        @media (min-width: 1024px) {
            .tab-bar-wrap { padding: 10px 24px; }
        }
        .tab-bar {
            display: flex;
            gap: 4px;
            background: #fff;
            padding: 4px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,.06);
            border: 1px solid #E2E8F0;
            max-width: 480px;
        }
        .tab-btn {
            flex: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 9px;
            background: transparent;
            color: #64748B;
            font-weight: 600;
            font-size: 13px;
            border: 0;
            cursor: pointer;
            transition: all 0.15s;
            position: relative;
            font-family: inherit;
            min-height: 40px;
        }
        .tab-btn:hover {
            background: #F1F5F9;
            color: #334155;
        }
        .tab-btn.active {
            background: linear-gradient(135deg, #7C3AED, #EC4899);
            color: #fff;
            box-shadow: 0 2px 8px rgba(124, 58, 237, 0.3);
        }
        .tab-btn.active i { color: #fff; }
        .tab-btn i { font-size: 13px; }
        .tab-badge {
            display: none;
            position: absolute;
            top: -5px;
            right: -5px;
            background: #DC2626;
            color: #fff;
            font-size: 8px;
            font-weight: 700;
            padding: 2px 5px;
            border-radius: 999px;
            line-height: 1;
            box-shadow: 0 0 0 2px #fff;
            letter-spacing: 0.3px;
        }
        @media (max-width: 480px) {
            .tab-btn { padding: 9px 10px; font-size: 12px; gap: 6px; }
        }

        /* ============================================================
         * Tab panels
         * ============================================================ */
        .tab-panel {
            display: none;
            flex: 1;
            min-width: 0;
        }
        .tab-panel.active { display: block; }

        /* Stats panel — natural flow */
        .tab-panel[data-tab="stats"].active > .content-inner {
            padding: 12px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            min-width: 0;
        }
        @media (min-width: 640px) {
            .tab-panel[data-tab="stats"].active > .content-inner {
                padding: 20px;
                gap: 20px;
            }
        }
        @media (min-width: 1024px) {
            .tab-panel[data-tab="stats"].active > .content-inner {
                padding: 24px;
                gap: 24px;
            }
        }

        /* AI panel — flex column, fills viewport */
        .tab-panel[data-tab="ai"].active {
            display: flex;
            flex-direction: column;
            flex: 1;
            min-height: 0;
            overflow: hidden;
        }
        body.ai-tab-active { overflow: hidden; }
        body.ai-tab-active .main-content {
            height: 100vh;
            height: 100dvh;
            min-height: 0;
            overflow: hidden;
        }
        body.ai-tab-active .main-content > main {
            flex: 1;
            min-height: 0;
            overflow: hidden;
        }

        /* ============================================================
         * Components
         * ============================================================ */
        .card {
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 4px 20px rgba(15, 23, 42, 0.06);
            border: 1px solid #E2E8F0;
        }
        .kpi-card {
            border-radius: 18px;
            padding: 16px;
            background: #fff;
            border: 1px solid #E2E8F0;
            box-shadow: 0 4px 18px rgba(15, 23, 42, 0.05);
        }
        .kpi-card:hover { transform: translateY(-2px); box-shadow: 0 8px 22px rgba(15,23,42,.08); }

        .skeleton {
            background: #E2E8F0;
            border-radius: 8px;
        }
        .chip {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 2px 9px; border-radius: 999px;
            font-size: 11px; font-weight: 500; white-space: nowrap;
        }
        .chip-danger  { background: #FEE2E2; color: #B91C1C; }
        .chip-warn    { background: #FEF3C7; color: #B45309; }
        .chip-info    { background: #DBEAFE; color: #1D4ED8; }
        .chip-muted   { background: #F1F5F9; color: #475569; }

        .tier-pill { padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
        .reason-row { border-left: 3px solid #CBD5E1; padding-left: 10px; }
        #map { height: 380px; border-radius: 14px; z-index: 1; }
        .table-scroll { max-height: 560px; overflow-y: auto; overflow-x: auto; }
        .table-scroll thead th { position: sticky; top: 0; z-index: 2; background: #F8FAFC; }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #F1F5F9; border-radius: 10px; }
        ::-webkit-scrollbar-thumb { background: #CBD5E1; border-radius: 10px; }
        .bar-track { background: #F1F5F9; border-radius: 999px; height: 8px; overflow: hidden; }
        .bar-fill  { height: 8px; border-radius: 999px; }

        /* ============================================================
         * AI Tab
         * ============================================================ */
        .ai-tab-inner {
            display: flex;
            flex-direction: column;
            flex: 1;
            min-height: 0;
            padding: 10px 12px;
            gap: 10px;
        }
        @media (min-width: 640px) {
            .ai-tab-inner { padding: 16px; gap: 12px; }
        }

        .ai-context-bar {
            flex-shrink: 0;
            background: #fff;
            border: 1px solid #E2E8F0;
            border-radius: 12px;
            padding: 8px 12px;
        }
        .ai-mini-stats {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .mini-stat {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 11.5px;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 999px;
            white-space: nowrap;
        }
        .mini-stat i { font-size: 10px; }
        .mini-stat-total { background: #F1F5F9; color: #475569; }
        .mini-stat-critical { background: #FEE2E2; color: #B91C1C; }
        .mini-stat-high { background: #FFEDD5; color: #C2410C; }
        .mini-stat-avg { background: #DCFCE7; color: #15803D; }

        .ai-filter-inline {
            display: flex;
            gap: 6px;
            margin-left: auto;
            flex-wrap: wrap;
        }
        .ai-select {
            font-size: 11px;
            padding: 3px 8px;
            border: 1px solid #E2E8F0;
            border-radius: 6px;
            background: #fff;
            color: #475569;
            font-family: inherit;
            cursor: pointer;
            min-height: 26px;
        }
        .ai-select:focus { outline: 2px solid #A855F7; outline-offset: 1px; }

        .ai-context-chip-row {
            margin-top: 6px;
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .ai-context-chip {
            font-size: 10px;
            background: #F3E8FF;
            color: #6B21A8;
            padding: 2px 8px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }

        .ai-chat-area {
            display: flex;
            flex-direction: column;
            flex: 1;
            min-height: 0;
            background: #fff;
            border: 1px solid #E2E8F0;
            border-radius: 14px;
            overflow: hidden;
        }

        .ai-chat-history {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            padding: 14px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            background: #F8FAFC;
        }
        @media (min-width: 640px) {
            .ai-chat-history { padding: 20px; }
        }

        .ai-input-bar {
            flex-shrink: 0;
            padding: 10px 12px;
            border-top: 1px solid #F1F5F9;
            background: #FAFBFC;
        }

        /* Welcome (empty chat) */
        .ai-welcome { padding: 12px 0; }
        .ai-welcome-icon {
            width: 52px;
            height: 52px;
            border-radius: 16px;
            background: linear-gradient(135deg, #7C3AED, #EC4899);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            box-shadow: 0 8px 24px rgba(124, 58, 237, 0.25);
        }
        .ai-welcome-suggestions {
            display: flex;
            flex-direction: column;
            gap: 8px;
            max-width: 640px;
            margin: 16px auto 0;
        }
        .ai-welcome-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 14px;
            background: #fff;
            border: 1px solid #E2E8F0;
            border-radius: 10px;
            text-align: left;
            font-family: inherit;
            font-size: 13px;
            color: #334155;
            cursor: pointer;
            transition: all 0.15s;
            min-height: 44px;
        }
        .ai-welcome-btn:hover {
            border-color: #C4B5FD;
            background: #FAF5FF;
            transform: translateX(2px);
        }
        .ai-welcome-btn > i:first-child {
            color: #A855F7;
            font-size: 14px;
            flex-shrink: 0;
        }
        .ai-welcome-btn > span { flex: 1; }
        .ai-welcome-btn > i:last-child {
            color: #CBD5E1;
            font-size: 11px;
            transition: color 0.15s;
        }
        .ai-welcome-btn:hover > i:last-child { color: #A855F7; }

        /* Chat messages */
        .ai-msg {
            display: flex;
            gap: 8px;
            max-width: 100%;
        }
        .ai-msg-user { flex-direction: row-reverse; }
        .ai-msg-avatar {
            width: 30px; height: 30px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 12px;
            flex-shrink: 0;
        }
        .ai-msg-user .ai-msg-avatar { background: #3B82F6; color: #fff; }
        .ai-msg-assistant .ai-msg-avatar { background: linear-gradient(135deg, #7C3AED, #EC4899); color: #fff; }
        .ai-msg-bubble {
            padding: 10px 14px;
            border-radius: 14px;
            font-size: 13.5px;
            line-height: 1.6;
            max-width: calc(100% - 46px);
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        .ai-msg-user .ai-msg-bubble {
            background: #3B82F6;
            color: #fff;
            border-top-right-radius: 4px;
        }
        .ai-msg-assistant .ai-msg-bubble {
            background: #fff;
            border: 1px solid #E2E8F0;
            color: #1E293B;
            border-top-left-radius: 4px;
        }
        .ai-msg-bubble p { margin: 4px 0; }
        .ai-msg-bubble p:first-child { margin-top: 0; }
        .ai-msg-bubble p:last-child { margin-bottom: 0; }
        .ai-msg-bubble h3 { font-size: 14px; font-weight: 700; margin: 10px 0 6px; color: #0F172A; }
        .ai-msg-bubble h4 { font-size: 13px; font-weight: 700; margin: 8px 0 4px; color: #1E293B; }
        .ai-msg-bubble strong { font-weight: 700; color: #7C3AED; }
        .ai-msg-bubble em { font-style: italic; color: #64748B; }
        .ai-msg-bubble ul, .ai-msg-bubble ol { margin: 6px 0; padding-left: 20px; }
        .ai-msg-bubble li { margin: 2px 0; }
        .ai-msg-bubble code {
            background: #F1F5F9;
            padding: 1px 5px;
            border-radius: 4px;
            font-family: ui-monospace, Menlo, monospace;
            font-size: 12px;
        }
        .ai-msg-error .ai-msg-bubble {
            background: #FEF2F2;
            border-color: #FECACA;
            color: #991B1B;
        }
        .ai-msg-meta {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 6px;
            font-size: 11px;
            color: #94A3B8;
        }
        .ai-msg-copy {
            background: none;
            border: 1px solid #E2E8F0;
            border-radius: 6px;
            padding: 2px 8px;
            cursor: pointer;
            color: #64748B;
            font-size: 10px;
            font-family: inherit;
        }
        .ai-msg-copy:hover { background: #F1F5F9; color: #7C3AED; }

        .ai-loading {
            display: inline-flex;
            gap: 4px;
            align-items: center;
            padding: 8px 0;
        }
        .ai-loading span {
            width: 6px; height: 6px;
            border-radius: 50%;
            background: #A855F7;
            animation: bounce 1.4s infinite;
        }
        .ai-loading span:nth-child(2) { animation-delay: 0.15s; }
        .ai-loading span:nth-child(3) { animation-delay: 0.3s; }
        @keyframes bounce {
            0%, 60%, 100% { transform: translateY(0); opacity: 0.4; }
            30% { transform: translateY(-6px); opacity: 1; }
        }

        .ai-suggested {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .ai-suggested-btn {
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 999px;
            padding: 5px 12px;
            font-size: 11.5px;
            color: #475569;
            cursor: pointer;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-family: inherit;
        }
        .ai-suggested-btn:hover {
            background: #F3E8FF;
            border-color: #C4B5FD;
            color: #6B21A8;
        }

        .ai-input-wrap {
            display: flex;
            gap: 6px;
            background: #fff;
            border: 2px solid #E2E8F0;
            border-radius: 12px;
            padding: 6px 6px 6px 14px;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .ai-input-wrap:focus-within {
            border-color: #A855F7;
            box-shadow: 0 0 0 3px rgba(168, 85, 247, 0.15);
        }
        .ai-input-wrap textarea {
            flex: 1;
            border: 0;
            outline: 0;
            background: transparent;
            font-family: inherit;
            font-size: 14px;
            color: #1E293B;
            resize: none;
            min-height: 36px;
            max-height: 120px;
            padding: 6px 0;
            line-height: 1.5;
        }
        .ai-input-wrap textarea::placeholder { color: #94A3B8; }
        .ai-send-btn {
            background: linear-gradient(135deg, #7C3AED, #EC4899);
            color: #fff;
            border: 0;
            width: 40px;
            height: 40px;
            border-radius: 9px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            flex-shrink: 0;
            transition: transform 0.1s, opacity 0.15s;
        }
        .ai-send-btn:hover:not(:disabled) { transform: scale(1.05); }
        .ai-send-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .ai-input-hint {
            font-size: 10.5px;
            color: #94A3B8;
            margin-top: 5px;
            display: flex;
            justify-content: space-between;
            gap: 8px;
            flex-wrap: wrap;
        }
        .ai-input-hint kbd {
            background: #F1F5F9;
            border: 1px solid #E2E8F0;
            border-radius: 4px;
            padding: 0 4px;
            font-size: 10px;
            font-family: inherit;
        }

        .ai-quick-ask {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            border-radius: 6px;
            background: #F3E8FF;
            color: #7C3AED;
            border: 0;
            cursor: pointer;
            font-size: 10px;
            transition: all 0.15s;
        }
        .ai-quick-ask:hover { background: #7C3AED; color: #fff; }

        .dim-cell {
            text-align: center;
            padding: 8px 4px;
            font-size: 12px;
            font-weight: 500;
            border-radius: 6px;
        }
        .dim-cell-zero { color: #CBD5E1; }

        @media (max-width: 640px) {
            .kpi-card { padding: 12px; }
            .ai-tab-inner { padding: 8px; gap: 8px; }
            .ai-context-bar { padding: 8px 10px; }
            .mini-stat { font-size: 10.5px; padding: 2px 8px; }
            .ai-filter-inline { margin-left: 0; width: 100%; }
            .ai-select { font-size: 10px; flex: 1; }
            .ai-welcome-suggestions { margin-top: 12px; }
            .ai-welcome-btn { font-size: 12px; padding: 10px 12px; }
            .ai-msg-bubble { font-size: 13px; padding: 8px 12px; }
        }
    </style>
</head>
<body>

<?php include 'sidebar_eoc.php'; ?>

<div class="main-content">

    <!-- ================= Sticky top: header + tab bar ================= -->
    <div class="sticky-top-wrap">

        <header class="header-main">
            <div class="header-inner">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2 sm:gap-3 min-w-0">
                        <div class="glass-effect rounded-2xl p-2 sm:p-3 flex-shrink-0">
                            <i class="fas fa-brain text-lg sm:text-2xl text-fuchsia-400"></i>
                        </div>
                        <div class="min-w-0">
                            <h1 class="text-sm sm:text-xl lg:text-2xl font-bold truncate">AI วิเคราะห์กลุ่มเปราะบาง</h1>
                            <p class="hidden sm:block text-gray-300 text-xs">Risk Stratification &amp; Spatial Insight Engine</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-1 sm:gap-2 flex-shrink-0">
                        <button onclick="openMethodology()" class="hdr-btn" title="เกณฑ์การให้คะแนน">
                            <i class="fas fa-circle-info"></i>
                            <span class="hdr-btn-label">เกณฑ์การให้คะแนน</span>
                        </button>
                        <label class="hdr-btn cursor-pointer" title="แสดงข้อมูลระบุตัวตน">
                            <input type="checkbox" id="piiToggle" class="w-4 h-4 accent-fuchsia-500" onchange="togglePii()">
                            <span class="hdr-btn-label">แสดงข้อมูลระบุตัวตน</span>
                        </label>
                        <button onclick="loadOverview(true)" class="hdr-btn" title="คำนวณใหม่">
                            <i class="fas fa-rotate"></i>
                            <span class="hdr-btn-label">คำนวณใหม่</span>
                        </button>
                        <div class="hidden md:flex items-center gap-2 glass-effect rounded-xl px-3 py-1.5">
                            <div class="w-7 h-7 bg-gradient-to-br from-fuchsia-500 to-purple-600 rounded-full flex items-center justify-center">
                                <i class="fas fa-user-md text-white text-xs"></i>
                            </div>
                            <div class="text-xs leading-tight">
                                <div class="font-semibold whitespace-nowrap"><?= htmlspecialchars($user_name) ?></div>
                                <div class="text-gray-300 text-[10px] whitespace-nowrap"><?= htmlspecialchars($status_user) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="mt-2 text-[10px] sm:text-xs text-gray-400 flex items-center gap-2 flex-wrap">
                    <i class="fas fa-code-branch"></i>
                    <span id="modelLabel">Rule-based Explainable Model</span>
                    <span class="opacity-50">·</span>
                    <span>ขอบเขต: <?= $is_super_admin ? 'ทุกหน่วยบริการ (สสอ.)' : 'รหัสหน่วย ' . htmlspecialchars($user_hospcode) ?></span>
                </div>
            </div>
        </header>

        <!-- ================= Tab bar ================= -->
        <div class="tab-bar-wrap">
            <div class="tab-bar" role="tablist" aria-label="มุมมองหลัก">
                <button class="tab-btn active" data-tab="stats" role="tab" id="tab-stats" aria-selected="true" aria-controls="panel-stats" aria-label="สถิติภาพรวม">
                    <i class="fas fa-chart-pie" aria-hidden="true"></i>
                    <span>สถิติ</span>
                </button>
                <button class="tab-btn" data-tab="ai" role="tab" id="tab-ai" aria-selected="false" aria-controls="panel-ai" aria-label="AI วิเคราะห์">
                    <i class="fas fa-sparkles" aria-hidden="true"></i>
                    <span>AI วิเคราะห์</span>
                    <span class="tab-badge" id="aiTabBadge">NEW</span>
                </button>
            </div>
        </div>
    </div>

    <!-- ================= Main ================= -->
    <main>

        <!-- ============================================================
             TAB 1: STATS
             ============================================================ -->
        <div class="tab-panel active" data-tab="stats" id="panel-stats" role="tabpanel" aria-labelledby="tab-stats">
            <div class="content-inner">

                <!-- Warning -->
                <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 sm:px-5 py-3 flex items-start gap-3">
                    <i class="fas fa-shield-halved text-amber-500 mt-1 flex-shrink-0"></i>
                    <p class="text-xs sm:text-sm text-amber-800">
                        <strong>ข้อควรทราบ:</strong> คะแนนนี้เป็น <u>เครื่องมือช่วยจัดลำดับความสำคัญเพื่อการติดตาม</u> ไม่ใช่การวินิจฉัยทางการแพทย์
                        และคำนวณจากข้อมูลที่บันทึกไว้ในระบบเท่านั้น หากข้อมูลไม่ครบถ้วน คะแนนอาจสูงกว่าความเป็นจริง
                        โดยมีปัจจัยด้าน "คุณภาพข้อมูล" รวมอยู่ด้วย — <strong>ต้องให้บุคลากรยืนยันก่อนดำเนินการทุกครั้ง</strong>
                    </p>
                </div>

                <!-- Filter -->
                <section class="card p-4 sm:p-5">
                    <div class="flex flex-col lg:flex-row lg:items-end gap-3 sm:gap-4">
                        <div class="flex-1">
                            <label class="block text-xs font-semibold text-slate-500 mb-1">ระดับความเสี่ยง</label>
                            <div id="tierTabs" class="flex flex-wrap gap-2">
                                <button data-tier="" class="tier-tab px-3 sm:px-4 py-2 rounded-xl text-xs sm:text-sm font-medium bg-slate-800 text-white transition">ทั้งหมด</button>
                                <button data-tier="critical" class="tier-tab px-3 sm:px-4 py-2 rounded-xl text-xs sm:text-sm font-medium bg-red-50 text-red-700 hover:bg-red-100 transition">วิกฤต</button>
                                <button data-tier="high" class="tier-tab px-3 sm:px-4 py-2 rounded-xl text-xs sm:text-sm font-medium bg-orange-50 text-orange-700 hover:bg-orange-100 transition">สูง</button>
                                <button data-tier="medium" class="tier-tab px-3 sm:px-4 py-2 rounded-xl text-xs sm:text-sm font-medium bg-yellow-50 text-yellow-700 hover:bg-yellow-100 transition">ปานกลาง</button>
                                <button data-tier="low" class="tier-tab px-3 sm:px-4 py-2 rounded-xl text-xs sm:text-sm font-medium bg-green-50 text-green-700 hover:bg-green-100 transition">ต่ำ</button>
                            </div>
                        </div>
                        <div class="w-full lg:w-52">
                            <label class="block text-xs font-semibold text-slate-500 mb-1">กลุ่มผู้ป่วย</label>
                            <select id="groupFilter" class="w-full border border-slate-300 rounded-xl px-3 py-2 text-sm focus:ring-2 focus:ring-fuchsia-500">
                                <option value="">ทุกกลุ่ม</option>
                                <?php foreach ($group_options as $go): ?>
                                    <option value="<?= htmlspecialchars($go['id']) ?>"><?= htmlspecialchars($go['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="w-full lg:w-64">
                            <label class="block text-xs font-semibold text-slate-500 mb-1">ค้นหา</label>
                            <div class="relative">
                                <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                                <input id="searchBox" type="text" placeholder="พื้นที่ / ชื่อที่ปิดบัง..."
                                       class="w-full border border-slate-300 rounded-xl pl-9 pr-3 py-2 text-sm focus:ring-2 focus:ring-fuchsia-500">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 mb-1">จำนวนแถว</label>
                            <select id="pageSize" class="border border-slate-300 rounded-xl px-3 py-2 text-sm">
                                <option value="25">25</option>
                                <option value="50" selected>50</option>
                                <option value="100">100</option>
                                <option value="500">ทั้งหมด</option>
                            </select>
                        </div>
                    </div>
                </section>

                <!-- KPI -->
                <section class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3 sm:gap-4" id="kpiRow">
                    <div class="kpi-card">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-semibold text-slate-500">ขึ้นทะเบียน</span>
                            <i class="fas fa-users text-slate-300"></i>
                        </div>
                        <div class="text-2xl font-bold text-slate-800 mt-2" id="kpiTotal">-</div>
                        <div class="text-[11px] text-slate-400 mt-1">ราย</div>
                    </div>
                    <div class="kpi-card" style="border-color:#FECACA">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-semibold text-red-500">วิกฤต</span>
                            <i class="fas fa-triangle-exclamation text-red-300"></i>
                        </div>
                        <div class="text-2xl font-bold text-red-600 mt-2" id="kpiCritical">-</div>
                        <div class="text-[11px] text-red-400 mt-1">ติดตาม 24-48 ชม.</div>
                    </div>
                    <div class="kpi-card" style="border-color:#FED7AA">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-semibold text-orange-500">สูง</span>
                            <i class="fas fa-circle-exclamation text-orange-300"></i>
                        </div>
                        <div class="text-2xl font-bold text-orange-600 mt-2" id="kpiHigh">-</div>
                        <div class="text-[11px] text-orange-400 mt-1">ติดตาม 7 วัน</div>
                    </div>
                    <div class="kpi-card" style="border-color:#A5F3FC">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-semibold text-cyan-600">เชิงรุก</span>
                            <i class="fas fa-bullseye text-cyan-300"></i>
                        </div>
                        <div class="text-2xl font-bold text-cyan-700 mt-2" id="kpiActionable">-</div>
                        <div class="text-[11px] text-cyan-500 mt-1">วิกฤต + สูง</div>
                    </div>
                    <div class="kpi-card col-span-2 md:col-span-1">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-semibold text-slate-500">คะแนนเฉลี่ย</span>
                            <i class="fas fa-gauge-high text-slate-300"></i>
                        </div>
                        <div class="text-2xl font-bold text-slate-800 mt-2" id="kpiAvg">-</div>
                        <div class="bar-track mt-2"><div class="bar-fill bg-gradient-to-r from-emerald-400 via-amber-400 to-red-500" id="kpiAvgBar" style="width:0%"></div></div>
                    </div>
                </section>

                <!-- Charts -->
                <section class="grid grid-cols-1 lg:grid-cols-3 gap-4 sm:gap-5">
                    <div class="card p-4 sm:p-5">
                        <h2 class="font-bold text-slate-800 mb-1 text-sm sm:text-base"><i class="fas fa-chart-pie text-fuchsia-500 mr-2"></i>สัดส่วนระดับความเสี่ยง</h2>
                        <p class="text-xs text-slate-400 mb-3">Risk Stratification Distribution</p>
                        <div class="relative" style="height:230px"><canvas id="chartTier"></canvas></div>
                    </div>
                    <div class="card p-4 sm:p-5">
                        <h2 class="font-bold text-slate-800 mb-1 text-sm sm:text-base"><i class="fas fa-chart-column text-blue-500 mr-2"></i>คะแนนเฉลี่ยรายกลุ่ม</h2>
                        <p class="text-xs text-slate-400 mb-3">Average Risk Score by Group</p>
                        <div class="relative" style="height:230px"><canvas id="chartGroup"></canvas></div>
                    </div>
                    <div class="card p-4 sm:p-5">
                        <h2 class="font-bold text-slate-800 mb-1 text-sm sm:text-base"><i class="fas fa-chart-simple text-emerald-500 mr-2"></i>การกระจายตามอายุ</h2>
                        <p class="text-xs text-slate-400 mb-3">Age Band Distribution</p>
                        <div class="relative" style="height:230px"><canvas id="chartAge"></canvas></div>
                    </div>
                </section>

                <!-- Multi-Dimension -->
                <section class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-5">
                    <div class="card p-4 sm:p-5">
                        <h2 class="font-bold text-slate-800 mb-1 text-sm sm:text-base"><i class="fas fa-venus-mars text-pink-500 mr-2"></i>เพศ × ระดับความเสี่ยง</h2>
                        <p class="text-xs text-slate-400 mb-4">Gender × Risk Tier</p>
                        <div id="genderMatrix" class="overflow-x-auto">
                            <div class="skeleton h-32 rounded-xl"></div>
                        </div>
                    </div>
                    <div class="card p-4 sm:p-5">
                        <h2 class="font-bold text-slate-800 mb-1 text-sm sm:text-base"><i class="fas fa-clock-rotate-left text-teal-500 mr-2"></i>การติดตาม &amp; การใช้บริการ</h2>
                        <p class="text-xs text-slate-400 mb-4">Follow-up &amp; Service</p>
                        <div id="followupPanel" class="space-y-3">
                            <div class="skeleton h-8"></div>
                            <div class="skeleton h-8"></div>
                            <div class="skeleton h-8"></div>
                        </div>
                    </div>
                </section>

                <!-- Factors + Hotspots -->
                <section class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-5">
                    <div class="card p-4 sm:p-5">
                        <h2 class="font-bold text-slate-800 mb-1 text-sm sm:text-base"><i class="fas fa-list-check text-amber-500 mr-2"></i>ปัจจัยเสี่ยงที่พบบ่อย</h2>
                        <p class="text-xs text-slate-400 mb-4">Risk Driver Frequency</p>
                        <div id="factorList" class="space-y-3">
                            <div class="skeleton h-6"></div><div class="skeleton h-6"></div><div class="skeleton h-6"></div>
                        </div>
                    </div>
                    <div class="card p-4 sm:p-5">
                        <h2 class="font-bold text-slate-800 mb-1 text-sm sm:text-base"><i class="fas fa-location-dot text-red-500 mr-2"></i>พื้นที่ที่ควรลงเชิงรุก</h2>
                        <p class="text-xs text-slate-400 mb-4">Spatial Hotspot — กดปุ่ม ✨ เพื่อถาม AI</p>
                        <div class="table-scroll" style="max-height:300px">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-left text-xs text-slate-500 border-b">
                                        <th class="py-2 pr-2">#</th>
                                        <th class="py-2 pr-2">ตำบล / อำเภอ</th>
                                        <th class="py-2 pr-2 text-center">รวม</th>
                                        <th class="py-2 pr-2 text-center text-red-600">วิกฤต</th>
                                        <th class="py-2 pr-2 text-center text-orange-500">สูง</th>
                                        <th class="py-2 text-right">เฉลี่ย</th>
                                    </tr>
                                </thead>
                                <tbody id="hotspotBody">
                                    <tr><td colspan="6" class="py-3"><div class="skeleton h-5"></div></td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <!-- Readiness -->
                <section class="card p-4 sm:p-5">
                    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3 mb-4">
                        <div>
                            <h2 class="font-bold text-slate-800 text-sm sm:text-base"><i class="fas fa-clipboard-check text-indigo-500 mr-2"></i>ความพร้อมของข้อมูล</h2>
                            <p class="text-xs text-slate-400">Data Readiness Assessment</p>
                        </div>
                        <div class="text-right">
                            <div class="text-3xl font-bold text-indigo-600" id="readinessScore">-</div>
                            <div class="text-[11px] text-slate-400">คะแนน (0-100)</div>
                        </div>
                    </div>
                    <div class="rounded-xl bg-indigo-50 border border-indigo-100 px-4 py-2 mb-4 text-sm text-indigo-800" id="readinessVerdict">กำลังประเมิน...</div>
                    <div id="readinessList" class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-3">
                        <div class="skeleton h-8"></div><div class="skeleton h-8"></div>
                        <div class="skeleton h-8"></div><div class="skeleton h-8"></div>
                    </div>
                </section>

                <!-- Table -->
                <section class="card">
                    <div class="p-4 sm:p-5 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                        <div>
                            <h2 class="font-bold text-slate-800 text-sm sm:text-base"><i class="fas fa-table-list text-fuchsia-500 mr-2"></i>รายชื่อพร้อมคะแนน</h2>
                            <p class="text-xs text-slate-400">กดแถวเพื่อดูเหตุผล (Explainable)</p>
                        </div>
                        <div class="text-sm text-slate-500" id="listSummary">-</div>
                    </div>
                    <div class="table-scroll">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs text-slate-500 border-b">
                                    <th class="py-3 px-3 sm:px-4">คะแนน</th>
                                    <th class="py-3 px-3 sm:px-4">ระดับ</th>
                                    <th class="py-3 px-3 sm:px-4">ชื่อ - สกุล</th>
                                    <th class="py-3 px-3 sm:px-4">เลขบัตร</th>
                                    <th class="py-3 px-3 sm:px-4">กลุ่ม</th>
                                    <th class="py-3 px-3 sm:px-4 text-center">อายุ</th>
                                    <th class="py-3 px-3 sm:px-4">พื้นที่</th>
                                    <th class="py-3 px-3 sm:px-4">หน่วยบริการ</th>
                                    <th class="py-3 px-3 sm:px-4 text-center">ติดตาม</th>
                                </tr>
                            </thead>
                            <tbody id="patientBody">
                                <tr><td colspan="9" class="py-4 px-4"><div class="skeleton h-6"></div></td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- Map -->
                <section class="card p-4 sm:p-5">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-3">
                        <div>
                            <h2 class="font-bold text-slate-800 text-sm sm:text-base"><i class="fas fa-map-location-dot text-cyan-600 mr-2"></i>แผนที่เชิงพื้นที่</h2>
                            <p class="text-xs text-slate-400">สีหมุดตามระดับความเสี่ยง</p>
                        </div>
                        <div class="flex flex-wrap gap-2 text-xs">
                            <span class="chip chip-danger"><i class="fas fa-circle"></i> วิกฤต</span>
                            <span class="chip chip-warn"><i class="fas fa-circle"></i> สูง</span>
                            <span class="chip chip-info"><i class="fas fa-circle"></i> ปานกลาง</span>
                            <span class="chip chip-muted"><i class="fas fa-circle"></i> ต่ำ</span>
                        </div>
                    </div>
                    <div id="map"></div>
                </section>

                <p class="text-center text-xs text-slate-400 pb-4">VOC System · AI Insight v3.0 · คะแนนคำนวณสด</p>
            </div>
        </div>

        <!-- ============================================================
             TAB 2: AI
             ============================================================ -->
        <div class="tab-panel" data-tab="ai" id="panel-ai" role="tabpanel" aria-labelledby="tab-ai">
            <div class="ai-tab-inner">

                <!-- Context bar -->
                <div class="ai-context-bar">
                    <div class="ai-mini-stats">
                        <span class="mini-stat mini-stat-total" title="ขึ้นทะเบียนทั้งหมด">
                            <i class="fas fa-users"></i>
                            <span id="miniTotal">-</span>
                        </span>
                        <span class="mini-stat mini-stat-critical" title="ระดับวิกฤต">
                            <i class="fas fa-triangle-exclamation"></i>
                            <span id="miniCritical">-</span>
                        </span>
                        <span class="mini-stat mini-stat-high" title="ระดับสูง">
                            <i class="fas fa-circle-exclamation"></i>
                            <span id="miniHigh">-</span>
                        </span>
                        <span class="mini-stat mini-stat-avg" title="คะแนนเฉลี่ย">
                            <i class="fas fa-gauge-high"></i>
                            <span id="miniAvg">-</span>
                        </span>
                        <div class="ai-filter-inline">
                            <select id="tierFilterAI" class="ai-select" aria-label="กรองระดับความเสี่ยง">
                                <option value="">ทุกระดับ</option>
                                <option value="critical">🔴 วิกฤต</option>
                                <option value="high">🟠 สูง</option>
                                <option value="medium">🟡 ปานกลาง</option>
                                <option value="low">🟢 ต่ำ</option>
                            </select>
                            <select id="groupFilterAI" class="ai-select" aria-label="กรองกลุ่มผู้ป่วย">
                                <option value="">ทุกกลุ่ม</option>
                                <?php foreach ($group_options as $go): ?>
                                    <option value="<?= htmlspecialchars($go['id']) ?>"><?= htmlspecialchars($go['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="ai-context-chip-row" id="chatContextChip" style="display:none"></div>
                </div>

                <!-- Chat area -->
                <div class="ai-chat-area">
                    <div class="ai-chat-history" id="chatHistory"></div>
                    <div class="ai-input-bar">
                        <div class="ai-input-wrap">
                            <textarea id="chatInput" placeholder="ถาม AI เช่น 'ผู้ป่วยรายใดควรติดตามก่อน'..." rows="1" aria-label="พิมพ์คำถาม"></textarea>
                            <button id="chatSendBtn" class="ai-send-btn" onclick="sendChat()" aria-label="ส่งคำถาม">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </div>
                        <div class="ai-input-hint">
                            <span><kbd>Enter</kbd> ส่ง · <kbd>Shift</kbd>+<kbd>Enter</kbd> ขึ้นบรรทัดใหม่</span>
                            <span id="chatStatus">พร้อมทำงาน</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>


<script>
/* ============================================================================
   AI INSIGHT DASHBOARD v3.0 — 2 Tabs + Chat
   ============================================================================ */
const API = 'api/ai-risk-api.php';
const CHAT_STORAGE_KEY = 'ai_chat_history_v3';
const CHAT_MAX_HISTORY = 30;
const TAB_STORAGE_KEY = 'ai_insight_tab_v3';
const BADGE_STORAGE_KEY = 'ai_tab_visited_v3';
const TABS = ['stats', 'ai'];

const state = {
    tier: '', group: '', q: '', limit: 50, offset: 0,
    pii: false, totalRows: 0,
    _pendingMapRender: null,
    aiTabInited: false
};

const charts = { tier: null, group: null, age: null };
const TIER_META = {
    critical: { label: 'วิกฤต',    color: '#DC2626', bg: '#FEE2E2', text: '#B91C1C', icon: 'fa-triangle-exclamation' },
    high:     { label: 'สูง',      color: '#EA580C', bg: '#FFEDD5', text: '#C2410C', icon: 'fa-circle-exclamation' },
    medium:   { label: 'ปานกลาง',  color: '#CA8A04', bg: '#FEF9C3', text: '#A16207', icon: 'fa-circle-info' },
    low:      { label: 'ต่ำ',      color: '#16A34A', bg: '#DCFCE7', text: '#15803D', icon: 'fa-circle-check' }
};
window._overviewData = null;
window._lastRows = [];

/* ============================================================
 * Tab management
 * ============================================================ */
function getTabFromURL() {
    const h = location.hash.replace('#', '');
    return TABS.includes(h) ? h : null;
}
function getInitialTab() {
    return getTabFromURL() || sessionStorage.getItem(TAB_STORAGE_KEY) || 'stats';
}
function activateTab(tab, pushHash = true) {
    if (!TABS.includes(tab)) tab = 'stats';

    document.querySelectorAll('.tab-panel').forEach(p => {
        p.classList.toggle('active', p.dataset.tab === tab);
    });
    document.querySelectorAll('.tab-btn').forEach(b => {
        const active = b.dataset.tab === tab;
        b.classList.toggle('active', active);
        b.setAttribute('aria-selected', String(active));
    });
    document.body.classList.toggle('ai-tab-active', tab === 'ai');
    sessionStorage.setItem(TAB_STORAGE_KEY, tab);

    if (pushHash && location.hash !== '#' + tab) {
        try { history.pushState(null, '', '#' + tab); } catch (e) {}
    }

    if (tab === 'ai') {
        localStorage.setItem(BADGE_STORAGE_KEY, '1');
        const badge = document.getElementById('aiTabBadge');
        if (badge) badge.style.display = 'none';
        syncAITabFilters();
        updateMiniChips();
        renderChatHistory();
        setTimeout(() => document.getElementById('chatInput')?.focus(), 150);
    } else {
        // Stats tab: render pending map, invalidate
        if (state._pendingMapRender) {
            renderMap(state._pendingMapRender);
            state._pendingMapRender = null;
        }
        if (leafletMap) setTimeout(() => leafletMap.invalidateSize(), 120);
    }
}

function initBadge() {
    const badge = document.getElementById('aiTabBadge');
    if (badge && !localStorage.getItem(BADGE_STORAGE_KEY)) {
        badge.style.display = 'inline-block';
    }
}

function syncAITabFilters() {
    const aiTier = document.getElementById('tierFilterAI');
    const aiGroup = document.getElementById('groupFilterAI');
    if (aiTier) aiTier.value = state.tier || '';
    if (aiGroup) aiGroup.value = state.group || '';
}

/* ============================================================
 * Helpers
 * ============================================================ */
async function apiGet(params = {}) {
    const qs = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => {
        if (v !== '' && v !== null && v !== undefined) qs.append(k, v);
    });
    if (state.pii) qs.append('show_pii', '1');
    const res = await fetch(API + '?' + qs.toString(), { headers: { 'Accept': 'application/json' } });
    if (!res.ok) { const t = await res.text(); throw new Error('API ' + res.status + ': ' + t.slice(0, 200)); }
    return res.json();
}
function fmt(n) { return (n === null || n === undefined) ? '-' : Number(n).toLocaleString('th-TH'); }
function esc(s) {
    return String(s === null || s === undefined ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
function safeJSON(s) { return JSON.stringify(s).replace(/'/g, '&#39;'); }

/* ============================================================
 * Overview load
 * ============================================================ */
async function loadOverview(force) {
    try {
        const params = { action: 'overview' };
        if (force) params.force = '1';
        const data = await apiGet(params);
        if (!data.success) throw new Error(data.error || 'ไม่ทราบสาเหตุ');

        window._overviewData = data;
        renderKpi(data.kpi);
        updateMiniChips(data.kpi);
        renderTierChart(data.summary.tiers, data.summary.total);
        renderGroupChart(data.summary.by_group);
        renderAgeChart(data.summary.by_age);
        renderFactors(data.factor_freq, data.summary.total);
        renderHotspots(data.hotspots);
        renderReadiness(data.readiness);
        renderMultiDimensions(data);

        if (data.model && data.model.version) {
            document.getElementById('modelLabel').textContent = 'Model ' + data.model.version;
        }
        if (force) Swal.fire({ icon: 'success', title: 'คำนวณใหม่แล้ว', timer: 1200, showConfirmButton: false, toast: true, position: 'top-end' });
    } catch (err) {
        console.error(err);
        Swal.fire({ icon: 'error', title: 'โหลดภาพรวมไม่สำเร็จ', text: err.message });
    }
}

function updateMiniChips(kpi) {
    if (!kpi) kpi = (window._overviewData && window._overviewData.kpi) || {};
    const t = document.getElementById('miniTotal'); if (t) t.textContent = fmt(kpi.total);
    const c = document.getElementById('miniCritical'); if (c) c.textContent = fmt(kpi.critical);
    const h = document.getElementById('miniHigh'); if (h) h.textContent = fmt(kpi.high);
    const a = document.getElementById('miniAvg'); if (a) a.textContent = (kpi.avg_score !== undefined ? kpi.avg_score : '-');
}

/* ---------- Renderers (เหมือน v2.2) ---------- */
function renderKpi(kpi) {
    document.getElementById('kpiTotal').textContent      = fmt(kpi.total);
    document.getElementById('kpiCritical').textContent   = fmt(kpi.critical);
    document.getElementById('kpiHigh').textContent       = fmt(kpi.high);
    document.getElementById('kpiActionable').textContent = fmt(kpi.actionable);
    document.getElementById('kpiAvg').textContent        = kpi.avg_score;
    document.getElementById('kpiAvgBar').style.width     = Math.min(100, kpi.avg_score) + '%';
}
function renderTierChart(tiers, total) {
    const order = ['critical', 'high', 'medium', 'low'];
    const labels = order.map(t => TIER_META[t].label);
    const values = order.map(t => tiers[t] || 0);
    const colors = order.map(t => TIER_META[t].color);
    if (charts.tier) { charts.tier.data.labels = labels; charts.tier.data.datasets[0].data = values; charts.tier.update(); return; }
    charts.tier = new Chart(document.getElementById('chartTier'), {
        type: 'doughnut',
        data: { labels, datasets: [{ data: values, backgroundColor: colors, borderWidth: 2, borderColor: '#fff' }] },
        options: { responsive: true, maintainAspectRatio: false, cutout: '58%', plugins: { legend: { position: 'bottom', labels: { font: { family: 'Kanit', size: 12 }, boxWidth: 12 } }, tooltip: { callbacks: { label: (ctx) => { const pct = total > 0 ? ((ctx.parsed / total) * 100).toFixed(1) : 0; return ` ${ctx.label}: ${ctx.parsed} ราย (${pct}%)`; } }, titleFont: { family: 'Kanit' }, bodyFont: { family: 'Kanit' } } } }
    });
}
function renderGroupChart(byGroup) {
    const rows = Object.values(byGroup || {});
    const labels = rows.map(r => r.name);
    const values = rows.map(r => r.avg_score);
    const counts = rows.map(r => r.count);
    const colors = values.map(v => v >= 75 ? TIER_META.critical.color : v >= 60 ? TIER_META.high.color : v >= 40 ? TIER_META.medium.color : TIER_META.low.color);
    if (charts.group) { charts.group.data.labels = labels; charts.group.data.datasets[0].data = values; charts.group.data.datasets[0].backgroundColor = colors; charts.group.update(); return; }
    charts.group = new Chart(document.getElementById('chartGroup'), {
        type: 'bar',
        data: { labels, datasets: [{ label: 'คะแนนเฉลี่ย', data: values, backgroundColor: colors, borderRadius: 8, maxBarThickness: 46 }] },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, max: 100, ticks: { font: { family: 'Kanit' } }, grid: { color: '#F1F5F9' } }, x: { ticks: { font: { family: 'Kanit', size: 10 } }, grid: { display: false } } }, plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => ` คะแนนเฉลี่ย ${ctx.parsed.y} (${counts[ctx.dataIndex]} ราย)` }, titleFont: { family: 'Kanit' }, bodyFont: { family: 'Kanit' } } } }
    });
}
function renderAgeChart(byAge) {
    const labels = Object.keys(byAge || {});
    const values = Object.values(byAge || {});
    if (charts.age) { charts.age.data.labels = labels; charts.age.data.datasets[0].data = values; charts.age.update(); return; }
    charts.age = new Chart(document.getElementById('chartAge'), {
        type: 'line',
        data: { labels, datasets: [{ label: 'จำนวน (ราย)', data: values, borderColor: '#10B981', backgroundColor: 'rgba(16,185,129,.15)', fill: true, tension: 0.35, pointRadius: 4, pointBackgroundColor: '#059669' }] },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { font: { family: 'Kanit' } }, grid: { color: '#F1F5F9' } }, x: { ticks: { font: { family: 'Kanit', size: 10 } }, grid: { display: false } } }, plugins: { legend: { display: false }, tooltip: { titleFont: { family: 'Kanit' }, bodyFont: { family: 'Kanit' } } } }
    });
}
function renderFactors(list, total) {
    const box = document.getElementById('factorList');
    if (!list || !list.length) { box.innerHTML = '<p class="text-sm text-slate-400">ไม่พบข้อมูล</p>'; return; }
    const maxCount = Math.max(...list.map(f => f.count));
    const typeColor = { gstatus: '#EF4444', age: '#F59E0B', emergency: '#DC2626', access: '#3B82F6', followup: '#8B5CF6', service: '#14B8A6', data_quality: '#64748B' };
    box.innerHTML = list.map(f => {
        const pct = total > 0 ? (f.count / total) * 100 : 0;
        const w = maxCount > 0 ? (f.count / maxCount) * 100 : 0;
        const color = typeColor[f.type] || '#64748B';
        return `<div><div class="flex items-center justify-between text-xs mb-1 gap-2"><span class="text-slate-700 font-medium truncate" title="${esc(f.label)}">${esc(f.label)}</span><span class="text-slate-500 whitespace-nowrap">${fmt(f.count)} ราย · ${pct.toFixed(1)}% · +${f.points}</span></div><div class="bar-track"><div class="bar-fill" style="width:${w}%;background:${color}"></div></div></div>`;
    }).join('');
}
function renderHotspots(list) {
    const body = document.getElementById('hotspotBody');
    if (!list || !list.length) { body.innerHTML = '<tr><td colspan="6" class="py-3 text-center text-sm text-slate-400">ไม่พบข้อมูล</td></tr>'; return; }
    body.innerHTML = list.map((h, i) => {
        const medal = i === 0 ? '🥇' : i === 1 ? '🥈' : i === 2 ? '🥉' : (i + 1);
        const tambon = h.name.split(' / ')[0];
        return `<tr class="border-b border-slate-50 hover:bg-slate-50 cursor-pointer" onclick="filterByArea('${esc(tambon)}')">
            <td class="py-2 pr-2 text-slate-400">${medal}</td>
            <td class="py-2 pr-2 font-medium text-slate-700">${esc(h.name)}</td>
            <td class="py-2 pr-2 text-center">${fmt(h.total)}</td>
            <td class="py-2 pr-2 text-center"><span class="chip chip-danger">${fmt(h.critical)}</span></td>
            <td class="py-2 pr-2 text-center"><span class="chip chip-warn">${fmt(h.high)}</span></td>
            <td class="py-2 text-right">
                <div class="flex items-center justify-end gap-2">
                    <span class="font-semibold text-slate-700">${h.avg_score}</span>
                    <button class="ai-quick-ask" onclick="event.stopPropagation(); askAIAboutArea('${esc(tambon)}')" title="ถาม AI เกี่ยวกับพื้นที่นี้" aria-label="ถาม AI เกี่ยวกับพื้นที่ ${esc(tambon)}">
                        <i class="fas fa-sparkles"></i>
                    </button>
                </div>
            </td>
        </tr>`;
    }).join('');
}
function renderReadiness(rd) {
    document.getElementById('readinessScore').textContent = rd.readiness_score + '%';
    document.getElementById('readinessVerdict').innerHTML = '<i class="fas fa-lightbulb mr-2"></i>' + esc(rd.verdict) + ' <span class="text-indigo-500">(ประเมินจากผู้ป่วย ' + fmt(rd.total) + ' ราย)</span>';
    const colorOf = { good: '#16A34A', warn: '#EAB308', bad: '#DC2626' };
    const iconOf  = { good: 'fa-circle-check', warn: 'fa-triangle-exclamation', bad: 'fa-circle-xmark' };
    document.getElementById('readinessList').innerHTML = (rd.checks || []).map(c => `<div><div class="flex items-center justify-between text-xs mb-1 gap-2"><span class="text-slate-700 truncate" title="${esc(c.note || c.label)}"><i class="fas ${iconOf[c.status]} mr-1" style="color:${colorOf[c.status]}"></i>${esc(c.label)}${c.critical ? '<span class="chip chip-muted ml-1">จำเป็น</span>' : ''}</span><span class="font-semibold whitespace-nowrap" style="color:${colorOf[c.status]}">${c.percent}%</span></div><div class="bar-track"><div class="bar-fill" style="width:${c.percent}%;background:${colorOf[c.status]}"></div></div>${c.missing > 0 ? `<div class="text-[10px] text-slate-400 mt-1">ขาดข้อมูล ${fmt(c.missing)} ราย — ${esc(c.note || '')}</div>` : ''}</div>`).join('');
}
function renderMultiDimensions(data) {
    const summary = data.summary || {};
    const gender = summary.by_gender || {};
    const genderKeys = Object.keys(gender);
    const el = document.getElementById('genderMatrix');
    if (!genderKeys.length) { el.innerHTML = '<p class="text-sm text-slate-400 py-6 text-center">ไม่พบข้อมูลเพศ</p>'; }
    else {
        let html = '<table class="w-full text-sm"><thead><tr class="text-xs text-slate-500 border-b"><th class="py-2 text-left">เพศ</th><th class="py-2 text-center" style="color:#DC2626">วิกฤต</th><th class="py-2 text-center" style="color:#EA580C">สูง</th><th class="py-2 text-center" style="color:#CA8A04">ปานกลาง</th><th class="py-2 text-center" style="color:#16A34A">ต่ำ</th><th class="py-2 text-center">รวม</th></tr></thead><tbody>';
        genderKeys.forEach(gk => {
            const g = gender[gk] || {};
            const gt = g.tiers || {};
            const rowTotal = g.total || 0;
            const cell = (v, color) => v > 0 ? `<td class="dim-cell" style="background:${color}20;color:${color};font-weight:700">${fmt(v)}</td>` : `<td class="dim-cell dim-cell-zero">-</td>`;
            html += `<tr class="border-b border-slate-50"><td class="py-2 font-medium text-slate-700">${esc(g.label || gk)}</td>${cell(gt.critical || 0, '#DC2626')}${cell(gt.high || 0, '#EA580C')}${cell(gt.medium || 0, '#CA8A04')}${cell(gt.low || 0, '#16A34A')}<td class="dim-cell font-bold text-slate-700">${fmt(rowTotal)}</td></tr>`;
        });
        html += '</tbody></table>';
        el.innerHTML = html;
    }
    const fu = document.getElementById('followupPanel');
    const f = summary.followup || {};
    const o90 = f.overdue_90 || 0, o180 = f.overdue_180 || 0, onT = f.on_time || 0;
    const never = summary.never_served || 0, open = summary.open_service || 0, tR = summary.total || 0;
    const bar = (v, t, c) => `<div class="bar-track mt-1"><div class="bar-fill" style="width:${t > 0 ? Math.min(100, (v / t) * 100) : 0}%;background:${c}"></div></div>`;
    fu.innerHTML = `
        <div><div class="flex items-center justify-between text-xs mb-1"><span class="text-slate-700"><i class="fas fa-circle-check mr-1" style="color:#16A34A"></i>ติดตามตรงเวลา</span><span class="font-bold text-green-600">${fmt(onT)} ราย</span></div>${bar(onT, tR, '#16A34A')}</div>
        <div><div class="flex items-center justify-between text-xs mb-1"><span class="text-slate-700"><i class="fas fa-clock mr-1" style="color:#EAB308"></i>เกิน 90 วัน</span><span class="font-bold text-yellow-600">${fmt(o90)} ราย</span></div>${bar(o90, tR, '#EAB308')}</div>
        <div><div class="flex items-center justify-between text-xs mb-1"><span class="text-slate-700"><i class="fas fa-triangle-exclamation mr-1" style="color:#DC2626"></i>เกิน 180 วัน</span><span class="font-bold text-red-600">${fmt(o180)} ราย</span></div>${bar(o180, tR, '#DC2626')}</div>
        <div class="pt-2 border-t border-slate-100 mt-2"><div class="flex items-center justify-between text-xs mb-1"><span class="text-slate-700"><i class="fas fa-user-slash mr-1" style="color:#94A3B8"></i>ไม่เคยรับบริการ</span><span class="font-bold text-slate-600">${fmt(never)} ราย</span></div>${bar(never, tR, '#94A3B8')}</div>
        <div><div class="flex items-center justify-between text-xs mb-1"><span class="text-slate-700"><i class="fas fa-hourglass-half mr-1" style="color:#3B82F6"></i>มีงานค้าง</span><span class="font-bold text-blue-600">${fmt(open)} ราย</span></div>${bar(open, tR, '#3B82F6')}</div>
    `;
}

/* ============================================================
 * Chat
 * ============================================================ */
const PRESET_QUESTIONS = [
    { icon: 'fa-clipboard-list',   text: 'สรุปภาพรวมผู้ป่วยกลุ่มเปราะบางทั้งหมด' },
    { icon: 'fa-user-shield',      text: 'ผู้ป่วยรายใดควรติดตามเป็นอันดับแรก' },
    { icon: 'fa-triangle-exclamation', text: 'ปัจจัยเสี่ยงใดที่พบมากที่สุด' },
    { icon: 'fa-location-dot',     text: 'พื้นที่ใดต้องการการลงพื้นที่เชิงรุกเร่งด่วน' },
    { icon: 'fa-database',         text: 'คุณภาพข้อมูลในระบบเป็นอย่างไร' },
    { icon: 'fa-chart-line',       text: 'เปรียบเทียบระดับความเสี่ยงระหว่างกลุ่ม' },
    { icon: 'fa-user-doctor',      text: 'อธิบายปัจจัยเสี่ยงของผู้ป่วยวิกฤตคนแรก' },
    { icon: 'fa-briefcase',        text: 'ให้คำแนะนำเชิงนโยบายสำหรับผู้บริหาร' }
];

let chatHistory = [];
let chatBusy = false;

function loadChatFromStorage() {
    try { const raw = sessionStorage.getItem(CHAT_STORAGE_KEY); if (raw) { chatHistory = JSON.parse(raw); if (!Array.isArray(chatHistory)) chatHistory = []; } }
    catch (e) { chatHistory = []; }
    renderChatHistory();
}
function saveChatToStorage() {
    try { sessionStorage.setItem(CHAT_STORAGE_KEY, JSON.stringify(chatHistory.slice(-CHAT_MAX_HISTORY))); } catch (e) {}
}
function renderChatHistory() {
    const box = document.getElementById('chatHistory');
    if (!chatHistory.length) {
        box.innerHTML = `
            <div class="ai-welcome">
                <div style="text-align:center">
                    <div class="ai-welcome-icon">✨</div>
                    <h3 style="font-size:15px;font-weight:700;color:#334155;margin-top:8px">สวัสดีครับ ผมคือ AI ผู้ช่วยวิเคราะห์</h3>
                    <p style="font-size:12px;color:#94A3B8;margin-top:4px">ถามได้ทุกเรื่อง หรือเลือกจากตัวอย่างด้านล่าง</p>
                </div>
                <div class="ai-welcome-suggestions">
                    ${PRESET_QUESTIONS.map(q => `
                        <button class="ai-welcome-btn" onclick='handleSuggested(${safeJSON(q.text)})'>
                            <i class="fas ${q.icon}"></i>
                            <span>${esc(q.text)}</span>
                            <i class="fas fa-arrow-right"></i>
                        </button>
                    `).join('')}
                </div>
            </div>`;
        return;
    }
    box.innerHTML = chatHistory.map((m, i) => {
        const isUser = m.role === 'user';
        const isErr = m.error === true;
        const avatar = isUser ? 'fa-user' : 'fa-sparkles';
        const cls = isUser ? 'ai-msg-user' : (isErr ? 'ai-msg-assistant ai-msg-error' : 'ai-msg-assistant');
        const time = m.ts ? new Date(m.ts).toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' }) : '';
        const content = isUser ? `<p>${esc(m.text)}</p>` : renderMarkdown(m.text);
        return `<div class="ai-msg ${cls}"><div class="ai-msg-avatar"><i class="fas ${avatar}"></i></div><div><div class="ai-msg-bubble">${content}</div><div class="ai-msg-meta"><span>${time}</span>${!isUser ? `<button class="ai-msg-copy" onclick="copyMessage(${i})"><i class="fas fa-copy"></i> คัดลอก</button>` : ''}</div></div></div>`;
    }).join('');
    box.scrollTop = box.scrollHeight;
}
function renderMarkdown(text) {
    if (!text) return '';
    return String(text).split(/\n\n+/).map(block => {
        const t = block.trim();
        if (!t) return '';
        if (/^###\s/.test(t)) return `<h4>${inlineFormat(t.replace(/^###\s+/, ''))}</h4>`;
        if (/^##\s/.test(t))  return `<h3>${inlineFormat(t.replace(/^##\s+/, ''))}</h3>`;
        if (/^[-*]\s/.test(t) || /^\d+\.\s/.test(t)) {
            const items = t.split('\n').filter(Boolean).map(l => `<li>${inlineFormat(l.replace(/^[-*]\s+/, '').replace(/^\d+\.\s+/, ''))}</li>`).join('');
            return `<ul>${items}</ul>`;
        }
        return `<p>${inlineFormat(t.replace(/\n/g, '<br>'))}</p>`;
    }).join('');
}
function inlineFormat(text) {
    return esc(text)
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/(?<!\*)\*([^*]+?)\*(?!\*)/g, '<em>$1</em>')
        .replace(/`([^`]+?)`/g, '<code>$1</code>');
}
function appendMessage(role, text, opts = {}) {
    chatHistory.push({ role, text, ts: Date.now(), error: opts.error || false });
    saveChatToStorage();
    renderChatHistory();
}
function copyMessage(idx) {
    const msg = chatHistory[idx];
    if (!msg) return;
    navigator.clipboard.writeText(msg.text).then(() => {
        const btn = event?.target?.closest('.ai-msg-copy');
        if (btn) {
            const orig = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-check"></i> คัดลอกแล้ว';
            setTimeout(() => { btn.innerHTML = orig; }, 1500);
        }
    }).catch(() => Swal.fire({ icon: 'info', title: 'คัดลอกไม่สำเร็จ', timer: 1800, showConfirmButton: false }));
}
function handleSuggested(text) {
    document.getElementById('chatInput').value = text;
    sendChat();
}
async function sendChat() {
    if (chatBusy) return;
    const input = document.getElementById('chatInput');
    const question = input.value.trim();
    if (!question) { input.focus(); return; }

    appendMessage('user', question);
    input.value = '';
    input.style.height = 'auto';
    chatBusy = true;
    document.getElementById('chatSendBtn').disabled = true;
    document.getElementById('chatStatus').innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> กำลังคิด...';

    const loadingId = 'loading-' + Date.now();
    const box = document.getElementById('chatHistory');
    box.insertAdjacentHTML('beforeend', `<div class="ai-msg ai-msg-assistant" id="${loadingId}"><div class="ai-msg-avatar"><i class="fas fa-sparkles"></i></div><div class="ai-msg-bubble"><div class="ai-loading"><span></span><span></span><span></span></div></div></div>`);
    box.scrollTop = box.scrollHeight;

    try {
        let data;
        try { data = await apiGet({ action: 'ask', question, tier: state.tier, gstatus: state.group, q: state.q }); }
        catch (apiErr) { console.warn('[AI] fallback:', apiErr.message); data = localAnalyze(question); }
        document.getElementById(loadingId)?.remove();
        if (data && data.success) {
            appendMessage('assistant', data.answer || 'ไม่ได้รับคำตอบ');
            if (data.suggestions && data.suggestions.length) renderFollowups(data.suggestions);
        } else {
            appendMessage('assistant', '❌ ' + (data?.error || 'ไม่ทราบสาเหตุ'), { error: true });
        }
    } catch (err) {
        document.getElementById(loadingId)?.remove();
        appendMessage('assistant', '❌ ไม่สามารถเชื่อมต่อ AI: ' + err.message, { error: true });
    } finally {
        chatBusy = false;
        document.getElementById('chatSendBtn').disabled = false;
        document.getElementById('chatStatus').textContent = 'พร้อมทำงาน';
    }
}
function renderFollowups(suggestions) {
    const box = document.getElementById('chatHistory');
    if (!suggestions || !suggestions.length) return;
    box.insertAdjacentHTML('beforeend', `<div class="ai-suggested" style="padding-left:38px">${suggestions.map(s => `<button class="ai-suggested-btn" onclick='handleSuggested(${safeJSON(s)})'><i class="fas fa-arrow-right" style="color:#A855F7"></i>${esc(s)}</button>`).join('')}</div>`);
    box.scrollTop = box.scrollHeight;
}
function clearChat() {
    if (!chatHistory.length) return;
    Swal.fire({
        icon: 'question', title: 'ล้างประวัติสนทนา?', text: 'ประวัติทั้งหมดจะหายไป',
        showCancelButton: true, confirmButtonText: 'ล้าง', cancelButtonText: 'ยกเลิก', confirmButtonColor: '#DC2626'
    }).then(r => {
        if (r.isConfirmed) { chatHistory = []; saveChatToStorage(); renderChatHistory(); }
    });
}
function exportChat() {
    if (!chatHistory.length) { Swal.fire({ icon: 'info', title: 'ยังไม่มีบทสนทนา', timer: 1500, showConfirmButton: false }); return; }
    const lines = chatHistory.map(m => { const role = m.role === 'user' ? '👤 ผู้ใช้' : '🤖 AI'; const time = m.ts ? new Date(m.ts).toLocaleString('th-TH') : ''; return `[${time}] ${role}\n${m.text}\n`; }).join('\n---\n\n');
    const blob = new Blob([lines], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `ai_chat_${new Date().toISOString().slice(0, 10)}.txt`;
    a.click();
    URL.revokeObjectURL(url);
}

/* ============================================================
 * Local Analysis (เหมือน v2.2)
 * ============================================================ */
function localAnalyze(question) {
    const q = question.toLowerCase();
    const o = window._overviewData;
    const rows = window._lastRows || [];
    if (!o) return { success: true, answer: 'ยังโหลดข้อมูลไม่เสร็จ กรุณารอสักครู่แล้วลองใหม่' };
    if (/สรุป|ภาพรวม|executive|overview/i.test(q)) {
        const k = o.kpi;
        return { success: true, answer: `## สรุปภาพรวม\n\n- ผู้ป่วยทั้งหมด: **${fmt(k.total)}** ราย\n- ระดับวิกฤต: **${fmt(k.critical)}** ราย\n- ระดับสูง: **${fmt(k.high)}** ราย\n- ต้องติดตามเชิงรุก: **${fmt(k.actionable)}** ราย\n- คะแนนเสี่ยงเฉลี่ย: **${k.avg_score}/100**\n\n**ข้อสังเกต:** ${k.critical > 0 ? `มีผู้ป่วยวิกฤต **${fmt(k.critical)} ราย** ต้องการติดตามภายใน 24-48 ชั่วโมง` : 'ยังไม่มีผู้ป่วยระดับวิกฤต'}`, suggestions: ['ผู้ป่วยรายใดควรติดตามเป็นอันดับแรก', 'ปัจจัยเสี่ยงใดที่พบมากที่สุด', 'พื้นที่ใดต้องการการลงพื้นที่เร่งด่วน'] };
    }
    if (/ผู้ป่วยรายใด|ใครควร|เสี่ยงสูงสุด|ติดตาม.*ก่อน|top/i.test(q)) {
        const top = rows.slice(0, 5);
        if (!top.length) return { success: true, answer: 'ไม่พบข้อมูลผู้ป่วย ลองเปลี่ยนตัวกรอง' };
        const list = top.map((p, i) => { const m = TIER_META[p.risk_tier] || TIER_META.low; return `${i + 1}. **${esc(p.name)}** — คะแนน ${p.risk_score} (${m.label})\n   - กลุ่ม: ${esc(p.group_name)} · อายุ ${p.age || '-'} ปี\n   - พื้นที่: ${esc(p.tambon || '-')} ${esc(p.ampher || '')}`; }).join('\n\n');
        return { success: true, answer: `## ผู้ป่วยที่ควรติดตามเป็นอันดับแรก 5 ราย\n\n${list}\n\n_กดแถวในตารางเพื่อดูรายละเอียด_`, suggestions: [`อธิบายปัจจัยเสี่ยงของ ${top[0].name}`, 'ปัจจัยเสี่ยงใดที่พบมากที่สุด', 'พื้นที่ใดต้องการการลงพื้นที่เร่งด่วน'] };
    }
    if (/ปัจจัย|สาเหตุ|risk factor|แก้ไขอะไร/i.test(q)) {
        const factors = o.factor_freq || [];
        if (!factors.length) return { success: true, answer: 'ไม่พบข้อมูลปัจจัยเสี่ยง' };
        const list = factors.slice(0, 8).map((f, i) => `${i + 1}. **${esc(f.label)}** — พบ ${fmt(f.count)} ราย (${((f.count / o.summary.total) * 100).toFixed(1)}%) · น้ำหนัก +${f.points}`);
        return { success: true, answer: `## ปัจจัยเสี่ยงที่พบมากที่สุด\n\n${list.join('\n')}\n\n**ข้อแนะนำ:** เริ่มแก้ไขจาก 2-3 ปัจจัยแรก`, suggestions: ['พื้นที่ใดต้องการการลงพื้นที่เร่งด่วน', 'สรุปภาพรวม', 'เปรียบเทียบระดับความเสี่ยงระหว่างกลุ่ม'] };
    }
    if (/พื้นที่|ตำบล|อำเภอ|hotspot|ลงพื้นที่|โซน/i.test(q)) {
        const spots = o.hotspots || [];
        if (!spots.length) return { success: true, answer: 'ไม่พบข้อมูล hotspot' };
        const list = spots.slice(0, 5).map((h, i) => `${i + 1}. **${esc(h.name)}** — รวม ${fmt(h.total)} ราย (วิกฤต ${fmt(h.critical)}, สูง ${fmt(h.high)}) · เฉลี่ย ${h.avg_score}`);
        return { success: true, answer: `## พื้นที่ที่ควรลงเชิงรุก 5 อันดับแรก\n\n${list.join('\n')}\n\n_คลิกชื่อพื้นที่ในตาราง Hotspot เพื่อกรอง_`, suggestions: ['ผู้ป่วยรายใดควรติดตามเป็นอันดับแรก', 'ปัจจัยเสี่ยงใดที่พบมากที่สุด', 'สรุปภาพรวม'] };
    }
    if (/คุณภาพ|ข้อมูลครบ|data quality|ข้อมูลไม่ครบ/i.test(q)) {
        const rd = o.readiness;
        if (!rd) return { success: true, answer: 'ไม่พบข้อมูล readiness' };
        const bad = (rd.checks || []).filter(c => c.status === 'bad' || c.status === 'warn');
        const list = bad.slice(0, 6).map(c => `- **${esc(c.label)}** — ครบ ${c.percent}% (ขาด ${fmt(c.missing)} ราย)`);
        return { success: true, answer: `## คุณภาพข้อมูล\n\n**คะแนนความพร้อม:** ${rd.readiness_score}%\n\n**ข้อสรุป:** ${esc(rd.verdict)}\n\n**ปัจจัยที่ต้องปรับปรุง:**\n${list.join('\n') || '_ทุกปัจจัยอยู่ในเกณฑ์ดี_'}`, suggestions: ['สรุปภาพรวม', 'ปัจจัยเสี่ยงใดที่พบมากที่สุด'] };
    }
    if (/เปรียบเทียบ|กลุ่มไหน|ระหว่างกลุ่ม|by group/i.test(q)) {
        const arr = Object.values(o.summary.by_group || {}).sort((a, b) => b.avg_score - a.avg_score);
        if (!arr.length) return { success: true, answer: 'ไม่พบข้อมูลกลุ่ม' };
        const list = arr.map((g, i) => `${i + 1}. **${esc(g.name)}** — เฉลี่ย ${g.avg_score} (${fmt(g.count)} ราย)`);
        return { success: true, answer: `## เปรียบเทียบระดับความเสี่ยงระหว่างกลุ่ม\n\n${list.join('\n')}\n\n**ข้อสังเกต:** ${esc(arr[0].name)} มีคะแนนเฉลี่ยสูงสุด (${arr[0].avg_score})`, suggestions: ['ผู้ป่วยรายใดควรติดตามเป็นอันดับแรก', 'ปัจจัยเสี่ยงใดที่พบมากที่สุด'] };
    }
    if (/อธิบาย|รายละเอียด|ทำไม.*ได้คะแนน/i.test(q)) {
        const p = rows[0];
        if (!p) return { success: true, answer: 'ไม่พบข้อมูลผู้ป่วย' };
        const m = TIER_META[p.risk_tier] || TIER_META.low;
        const factors = (p.risk_factors || []).map(f => `- **${esc(f.label)}** (+${f.points} คะแนน)`).join('\n');
        return { success: true, answer: `## ผู้ป่วย: ${esc(p.name)}\n\n- **คะแนน:** ${p.risk_score}/100 (${m.label})\n- **กลุ่ม:** ${esc(p.group_name)}\n- **อายุ:** ${p.age || '-'} ปี · **เพศ:** ${esc(p.gender || '-')}\n- **พื้นที่:** ${esc(p.tambon || '-')} ${esc(p.ampher || '')}\n- **หน่วยบริการ:** ${esc(p.hosp_name)}\n\n**ปัจจัยเสี่ยง:**\n${factors}\n\n**ข้อเสนอ:** ${esc(p.advice)}`, suggestions: ['ผู้ป่วยรายใดควรติดตามเป็นอันดับแรก', 'ปัจจัยเสี่ยงใดที่พบมากที่สุด'] };
    }
    if (/นโยบาย|ผู้บริหาร|แนะนำ|ควรทำอะไร|next step/i.test(q)) {
        const k = o.kpi;
        const spots = (o.hotspots || []).slice(0, 3);
        const factors = (o.factor_freq || []).slice(0, 3);
        return { success: true, answer: `## คำแนะนำเชิงนโยบายสำหรับผู้บริหาร\n\n**1. สถานการณ์ปัจจุบัน**\n- ผู้ป่วยทั้งหมด **${fmt(k.total)}** ราย · ต้องติดตามเชิงรุก **${fmt(k.actionable)}** ราย\n- กลุ่มวิกฤต **${fmt(k.critical)}** ราย ต้องการติดตามภายใน 24-48 ชม.\n\n**2. พื้นที่เร่งด่วน 3 อันดับ**\n${spots.map((s, i) => `${i + 1}. ${esc(s.name)} — ${fmt(s.total)} ราย (วิกฤต ${fmt(s.critical)})`).join('\n')}\n\n**3. ปัจจัยที่ต้องแก้ไขก่อน**\n${factors.map((f, i) => `${i + 1}. ${esc(f.label)} — ${fmt(f.count)} ราย`).join('\n')}\n\n**4. ข้อเสนอเชิงปฏิบัติ**\n- จัดทีมลงพื้นที่ใน 3 ตำบลเร่งด่วนภายใน 7 วัน\n- แก้ไขปัจจัยเสี่ยงที่พบบ่อยที่สุดก่อน\n- ติดตามผู้ป่วยวิกฤตแบบรายวันผ่านระบบ`, suggestions: ['ผู้ป่วยรายใดควรติดตามเป็นอันดับแรก', 'พื้นที่ใดต้องการการลงพื้นที่เร่งด่วน', 'สรุปภาพรวม'] };
    }
    return { success: true, answer: `ขออภัยครับ ผมยังไม่เข้าใจคำถามนี้\n\n**ลองถามแบบนี้:**\n- _สรุปภาพรวมผู้ป่วย_\n- _ผู้ป่วยรายใดควรติดตามก่อน_\n- _ปัจจัยเสี่ยงที่พบมากที่สุด_\n- _พื้นที่ใดต้องการลงพื้นที่ด่วน_\n- _คุณภาพข้อมูลเป็นอย่างไร_\n- _เปรียบเทียบกลุ่มผู้ป่วย_\n- _แนะนำนโยบายสำหรับผู้บริหาร_\n\nหรือกดปุ่มคำถามแนะนำ`, suggestions: ['สรุปภาพรวม', 'ผู้ป่วยรายใดควรติดตามเป็นอันดับแรก', 'ปัจจัยเสี่ยงใดที่พบมากที่สุด'] };
}

/* ============================================================
 * Table + Map
 * ============================================================ */
async function loadList() {
    const body = document.getElementById('patientBody');
    body.innerHTML = '<tr><td colspan="9" class="py-4 px-4"><div class="skeleton h-6"></div></td></tr>';
    try {
        const data = await apiGet({ action: 'list', tier: state.tier, gstatus: state.group, q: state.q, limit: state.limit, offset: state.offset });
        if (!data.success) throw new Error(data.error || 'ไม่ทราบสาเหตุ');
        window._lastRows = data.patients || [];
        state.totalRows = data.total;
        renderTable(window._lastRows, data.total);
        // Map only when stats tab active
        const statsActive = document.getElementById('panel-stats')?.classList.contains('active');
        if (statsActive) renderMap(window._lastRows);
        else state._pendingMapRender = window._lastRows;
    } catch (err) {
        console.error(err);
        body.innerHTML = `<tr><td colspan="9" class="py-4 px-4 text-center text-red-600 text-sm">โหลดรายชื่อไม่สำเร็จ: ${esc(err.message)}</td></tr>`;
    }
}
function renderTable(rows, total) {
    const body = document.getElementById('patientBody');
    document.getElementById('listSummary').innerHTML = `พบ <strong>${fmt(total)}</strong> ราย · แสดง ${fmt(rows.length)} แถว`;
    if (!rows.length) {
        body.innerHTML = '<tr><td colspan="9" class="py-6 text-center text-slate-400 text-sm">ไม่พบข้อมูลตามเงื่อนไข</td></tr>';
        return;
    }
    body.innerHTML = rows.map((p, idx) => {
        const m = TIER_META[p.risk_tier] || TIER_META.low;
        const area = [p.tambon, p.ampher].filter(Boolean).join(' / ') || '-';
        const days = p.days_since_update === null || p.days_since_update === undefined ? '-' : p.days_since_update + ' วัน';
        return `<tr class="border-b border-slate-50 hover:bg-slate-50 cursor-pointer" onclick="toggleReasons(${idx})">
            <td class="py-3 px-3 sm:px-4"><div class="flex items-center gap-2"><span class="font-bold" style="color:${m.color}">${p.risk_score}</span><div class="bar-track" style="width:44px"><div class="bar-fill" style="width:${p.risk_score}%;background:${m.color}"></div></div></div></td>
            <td class="py-3 px-3 sm:px-4"><span class="tier-pill" style="background:${m.bg};color:${m.text}"><i class="fas ${m.icon} mr-1"></i>${m.label}</span></td>
            <td class="py-3 px-3 sm:px-4 font-medium text-slate-700">${esc(p.name)}</td>
            <td class="py-3 px-3 sm:px-4 text-slate-500 font-mono text-xs">${esc(p.idcard)}</td>
            <td class="py-3 px-3 sm:px-4"><span class="chip chip-muted" title="${esc(p.group_name)}">${esc(p.group_name)}</span></td>
            <td class="py-3 px-3 sm:px-4 text-center text-slate-600">${p.age === null ? '-' : p.age}</td>
            <td class="py-3 px-3 sm:px-4 text-slate-600 text-xs">${esc(area)}</td>
            <td class="py-3 px-3 sm:px-4 text-slate-600 text-xs">${esc(p.hosp_name)}</td>
            <td class="py-3 px-3 sm:px-4 text-center text-xs ${p.days_since_update > 90 ? 'text-red-600 font-semibold' : 'text-slate-500'}">${days}</td>
        </tr>
        <tr id="reason-${idx}" class="hidden bg-slate-50">
            <td colspan="9" class="px-4 sm:px-6 py-4">
                <div class="mb-2 text-xs font-semibold text-slate-600"><i class="fas fa-circle-question mr-1"></i>ทำไมได้ ${p.risk_score} คะแนน — ${esc(p.advice)}</div>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2">${(p.risk_factors || []).map(f => `<div class="reason-row"><div class="text-xs text-slate-700">${esc(f.label)}</div><div class="text-[11px] text-slate-400">+${f.points} คะแนน · ${esc(f.type)}</div></div>`).join('')}</div>
                <div class="mt-3 text-[11px] text-slate-400"><i class="fas fa-info-circle mr-1"></i>เพศ ${esc(p.gender || '-')} · เบอร์ ${esc(p.mobile || '-')} · บริการ ${fmt(p.service_count)} ครั้ง (ค้าง ${fmt(p.open_count)}) · ระดับ ${esc(p.emergency_code || 'ไม่ระบุ')}</div>
            </td>
        </tr>`;
    }).join('');
}
function toggleReasons(idx) {
    const row = document.getElementById('reason-' + idx);
    if (row) row.classList.toggle('hidden');
}

let leafletMap = null;
let markerLayer = null;
function initMap() {
    if (leafletMap) return;
    const el = document.getElementById('map');
    if (!el) return;
    leafletMap = L.map('map').setView([6.78, 100.94], 11);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }).addTo(leafletMap);
    markerLayer = L.layerGroup().addTo(leafletMap);
}
function renderMap(rows) {
    initMap();
    if (!markerLayer) return;
    markerLayer.clearLayers();
    const pts = [];
    rows.forEach(p => {
        if (p.lat === null || p.lng === null) return;
        const m = TIER_META[p.risk_tier] || TIER_META.low;
        const mk = L.circleMarker([p.lat, p.lng], { radius: p.risk_tier === 'critical' ? 9 : p.risk_tier === 'high' ? 7 : 6, color: '#fff', weight: 2, fillColor: m.color, fillOpacity: 0.85 });
        mk.bindPopup(`<div style="font-family:Kanit,sans-serif;min-width:190px"><div style="font-weight:700;font-size:14px">${esc(p.name)}</div><div style="font-size:11px;color:#64748B;margin-bottom:6px">${esc(p.idcard)} · อายุ ${p.age === null ? '-' : p.age} ปี</div><div style="display:inline-block;padding:2px 9px;border-radius:999px;background:${m.bg};color:${m.text};font-size:11px;font-weight:600">คะแนน ${p.risk_score} · ${m.label}</div><div style="font-size:11px;color:#475569;margin-top:6px">${esc(p.group_name)}<br>${esc(p.tambon || '')} ${esc(p.ampher || '')}<br>${esc(p.hosp_name)}</div><div style="font-size:10px;color:#94A3B8;margin-top:6px">${esc(p.advice)}</div></div>`);
        mk.addTo(markerLayer);
        pts.push([p.lat, p.lng]);
    });
    if (pts.length) leafletMap.fitBounds(L.latLngBounds(pts).pad(0.15));
    setTimeout(() => leafletMap.invalidateSize(), 100);
}

/* ============================================================
 * Methodology modal
 * ============================================================ */
async function openMethodology() {
    try {
        const data = await apiGet({ action: 'methodology' });
        const w = data.weights;
        const tierRows = Object.values(data.tiers).map(t => `<tr><td style="padding:4px 8px"><span style="color:${t.color};font-weight:600">${t.label}</span></td><td style="padding:4px 8px;text-align:center">${t.min}-${t.max}</td><td style="padding:4px 8px;color:#64748B">${t.advice}</td></tr>`).join('');
        const wRows = [];
        for (const [k, v] of Object.entries(w.gstatus)) wRows.push(['กลุ่ม ' + k, v]);
        for (const b of w.age_bands) wRows.push([b.label, b.score]);
        for (const [k, v] of Object.entries(w.emergency)) wRows.push(['ความเร่งด่วน ' + k, v]);
        wRows.push(['ไม่มีเบอร์โทร', w.no_mobile]); wRows.push(['ไม่มีพิกัด', w.no_geo]);
        wRows.push(['ไม่ติดตาม 90+ วัน', w.overdue_90]); wRows.push(['ไม่ติดตาม 180+ วัน', w.overdue_180]);
        wRows.push(['ไม่เคยรับบริการ', w.never_served]); wRows.push(['มีงานค้าง', w.open_service]);
        wRows.push(['รหัสกลุ่มผิด', w.orphan_group]); wRows.push(['ไม่พบใน person', w.no_person_link]);
        const wTable = wRows.map(r => `<tr><td style="padding:3px 8px">${esc(r[0])}</td><td style="padding:3px 8px;text-align:right;font-weight:600">+${r[1]}</td></tr>`).join('');
        Swal.fire({
            title: 'เกณฑ์การให้คะแนนความเสี่ยง', width: 860,
            html: `<div style="text-align:left;font-family:Kanit,sans-serif;font-size:13px"><div style="background:#EFF6FF;border:1px solid #BFDBFE;border-radius:10px;padding:10px;margin-bottom:12px;color:#1E40AF"><strong>Model:</strong> ${esc(data.version)} — เพดาน 100</div><div style="font-weight:700;margin-bottom:6px">1) ระดับความเสี่ยง</div><table style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:14px"><thead><tr style="background:#F8FAFC"><th style="padding:4px 8px;text-align:left">ระดับ</th><th style="padding:4px 8px">ช่วง</th><th style="padding:4px 8px;text-align:left">ข้อเสนอ</th></tr></thead><tbody>${tierRows}</tbody></table><div style="font-weight:700;margin-bottom:6px">2) น้ำหนักคะแนน</div><div style="max-height:240px;overflow:auto;border:1px solid #E2E8F0;border-radius:10px"><table style="width:100%;border-collapse:collapse;font-size:12px"><tbody>${wTable}</tbody></table></div><div style="font-weight:700;margin:14px 0 6px">3) หลักการ</div><ul style="margin:0;padding-left:18px;color:#334155">${(data.principles || []).map(p => `<li>${esc(p)}</li>`).join('')}</ul><div style="margin-top:12px;background:#FEF3C7;border:1px solid #FDE68A;border-radius:10px;padding:10px;color:#92400E;font-size:12px"><i class="fas fa-triangle-exclamation"></i> ${esc(data.disclaimer || '')}</div></div>`,
            showCloseButton: true, confirmButtonText: 'เข้าใจแล้ว', confirmButtonColor: '#7C3AED'
        });
    } catch (err) { Swal.fire({ icon: 'error', title: 'โหลดเกณฑ์ไม่สำเร็จ', text: err.message }); }
}

/* ============================================================
 * Interactions
 * ============================================================ */
let searchTimer = null;
function togglePii() {
    state.pii = document.getElementById('piiToggle').checked;
    state.offset = 0;
    loadOverview(false);
    loadList();
    Swal.fire({
        icon: state.pii ? 'warning' : 'success',
        title: state.pii ? 'เปิดแสดงข้อมูลระบุตัวตน' : 'กลับสู่โหมดปิดบัง',
        timer: 2200, showConfirmButton: false, toast: true, position: 'top-end'
    });
}
function filterByArea(areaName) {
    const searchBox = document.getElementById('searchBox');
    if (searchBox) searchBox.value = areaName;
    state.q = areaName;
    state.offset = 0;
    state.tier = '';
    document.querySelectorAll('.tier-tab').forEach(b => {
        b.classList.toggle('ring-2', b.dataset.tier === '');
        b.classList.toggle('ring-fuchsia-500', b.dataset.tier === '');
        b.classList.toggle('shadow-md', b.dataset.tier === '');
    });
    const aiTier = document.getElementById('tierFilterAI');
    if (aiTier) aiTier.value = '';
    updateChatContextChip();
    loadList();
    activateTab('stats');
    setTimeout(() => document.getElementById('patientBody')?.scrollIntoView({ behavior: 'smooth', block: 'center' }), 100);
}
function setTier(tier) {
    state.tier = tier;
    state.offset = 0;
    document.querySelectorAll('.tier-tab').forEach(btn => {
        const active = btn.dataset.tier === tier;
        btn.classList.toggle('ring-2', active);
        btn.classList.toggle('ring-fuchsia-500', active);
        btn.classList.toggle('shadow-md', active);
    });
    const aiTier = document.getElementById('tierFilterAI');
    if (aiTier && aiTier.value !== tier) aiTier.value = tier;
    updateChatContextChip();
    loadList();
}
function setGroup(groupId) {
    state.group = groupId;
    state.offset = 0;
    const g1 = document.getElementById('groupFilter');
    const g2 = document.getElementById('groupFilterAI');
    if (g1 && g1.value !== groupId) g1.value = groupId;
    if (g2 && g2.value !== groupId) g2.value = groupId;
    updateChatContextChip();
    loadList();
}
function updateChatContextChip() {
    const chip = document.getElementById('chatContextChip');
    if (!chip) return;
    const ctx = [];
    if (state.tier) { const m = TIER_META[state.tier]; ctx.push(`ระดับ ${m?.label || state.tier}`); }
    if (state.group) {
        const sel = document.getElementById('groupFilterAI') || document.getElementById('groupFilter');
        if (sel) { const opt = sel.options[sel.selectedIndex]; if (opt && opt.value) ctx.push(opt.textContent); }
    }
    if (state.q) ctx.push(`ค้นหา "${state.q}"`);
    if (ctx.length) {
        chip.style.display = 'flex';
        chip.innerHTML = `<span class="ai-context-chip"><i class="fas fa-filter"></i> ใช้ตัวกรอง: ${ctx.map(esc).join(' · ')}</span>`;
    } else {
        chip.style.display = 'none';
    }
}
function askAIAboutArea(areaName) {
    activateTab('ai');
    setTimeout(() => {
        const input = document.getElementById('chatInput');
        if (!input) return;
        input.value = `วิเคราะห์พื้นที่ ${areaName} ให้หน่อย: มีผู้ป่วยกี่ราย ระดับไหน และควรทำอะไร`;
        sendChat();
    }, 250);
}

/* ============================================================
 * Init
 * ============================================================ */
document.addEventListener('DOMContentLoaded', () => {
    initBadge();
    loadChatFromStorage();

    // Tab buttons
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', () => activateTab(btn.dataset.tab));
    });

    // Restore initial tab
    const initialTab = getInitialTab();
    activateTab(initialTab, false);

    // Keyboard shortcuts
    document.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key === '1') { e.preventDefault(); activateTab('stats'); }
        if ((e.ctrlKey || e.metaKey) && e.key === '2') { e.preventDefault(); activateTab('ai'); }
    });

    // Popstate (back/forward)
    window.addEventListener('popstate', () => {
        const tab = getTabFromURL();
        if (tab) activateTab(tab, false);
    });

    // Stats tab: tier tabs
    document.querySelectorAll('.tier-tab').forEach(btn => {
        btn.addEventListener('click', () => setTier(btn.dataset.tier));
    });

    // Group filter (stats)
    document.getElementById('groupFilter')?.addEventListener('change', (e) => setGroup(e.target.value));

    // Group filter (AI)
    document.getElementById('groupFilterAI')?.addEventListener('change', (e) => setGroup(e.target.value));

    // Tier filter (AI)
    document.getElementById('tierFilterAI')?.addEventListener('change', (e) => setTier(e.target.value));

    // Search box (stats)
    document.getElementById('searchBox')?.addEventListener('input', (e) => {
        clearTimeout(searchTimer);
        const val = e.target.value.trim();
        searchTimer = setTimeout(() => {
            state.q = val;
            state.offset = 0;
            updateChatContextChip();
            loadList();
        }, 350);
    });

    // Page size
    document.getElementById('pageSize')?.addEventListener('change', (e) => {
        state.limit = parseInt(e.target.value, 10);
        state.offset = 0;
        loadList();
    });

    // Chat input
    const chatInput = document.getElementById('chatInput');
    if (chatInput) {
        chatInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendChat(); }
        });
        chatInput.addEventListener('input', () => {
            chatInput.style.height = 'auto';
            chatInput.style.height = Math.min(120, chatInput.scrollHeight) + 'px';
        });
    }

    // Load data
    loadOverview(false);
    loadList();
});

window.addEventListener('resize', () => {
    if (leafletMap) leafletMap.invalidateSize();
});

setTimeout(() => { if (leafletMap) leafletMap.invalidateSize(); }, 800);
</script>

<?php require_once __DIR__ . '/includes/timeout_guard.php'; ?>
</body>
</html>