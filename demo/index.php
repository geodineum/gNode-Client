<?php
/**
 * GSD Interactive Demo
 *
 * A comprehensive demonstration of GSD's geometric service discovery,
 * format system, template management, and 3D topology visualization.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use gCore\GSD\Client;
use gCore\GSD\Storage\ValKeyStorage;

// Initialize GSD Client
$storage = new ValKeyStorage([
    'host' => '127.0.0.1',
    'port' => 6379,
    'password' => trim(file_get_contents(__DIR__ . '/../.gsd/valkey.password'))
]);

$client = new Client($storage, 'default', 'default', [
    'debug' => false,
    'timeout' => 5.0,
    'use_consumer_groups' => true
]);

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        switch ($_GET['action']) {
            // ========== Format System (Modern Facade Pattern) ==========
            case 'list_formats':
                $fm = $client->getFormatManager();
                $result = $fm->listFormats();
                echo json_encode(['success' => true, 'data' => $result]);
                break;

            case 'detect_format':
                $message = $_POST['message'] ?? '';
                $fm = $client->getFormatManager();
                $result = $fm->detectFormat($message);
                echo json_encode(['success' => true, 'data' => $result]);
                break;

            case 'convert_format':
                $message = $_POST['message'] ?? '';
                $from = $_POST['from'] ?? 'standard_json';
                $to = $_POST['to'] ?? 'compact_json';
                $fm = $client->getFormatManager();
                $result = $fm->convertFormat($message, $from, $to);
                echo json_encode(['success' => true, 'data' => $result]);
                break;

            // ========== Template System (Modern Facade Pattern) ==========
            case 'list_templates':
                // Templates are stored as services with "template:" prefix in the topology
                // Use geometric_discover to find all templates
                $topology_result = $client->executeCommand('geometric_discover', [
                    'requirements' => []
                ]);

                // Filter for services that start with "template:"
                $templates = array_filter($topology_result['matches'] ?? [], function($service) {
                    return isset($service['service_id']) && str_starts_with($service['service_id'], 'template:');
                });

                echo json_encode(['success' => true, 'data' => array_values($templates)]);
                break;

            case 'get_template':
                $template_id = $_GET['template_id'] ?? '';
                if (empty($template_id)) {
                    throw new \InvalidArgumentException('template_id is required');
                }
                $tm = $client->getTemplateManager();
                $result = $tm->getTemplateMetadata($template_id);
                echo json_encode(['success' => true, 'data' => $result]);
                break;

            case 'discover_similar':
                $template_id = $_GET['template_id'] ?? '';
                if (empty($template_id)) {
                    throw new \InvalidArgumentException('template_id is required');
                }
                $limit = (int)($_GET['limit'] ?? 10);
                $tm = $client->getTemplateManager();
                $result = $tm->discoverSimilarTemplates($template_id, $limit);
                echo json_encode(['success' => true, 'data' => $result]);
                break;

            case 'get_topology':
                // Fetch all templates with their capabilities for 3D visualization
                // Templates are stored as services with "template:" prefix in the topology
                $topology_result = $client->executeCommand('geometric_discover', [
                    'requirements' => []
                ]);

                // Filter for template services and format for visualization
                $topology_data = [];
                foreach ($topology_result['matches'] ?? [] as $service) {
                    $service_id = $service['service_id'] ?? '';
                    if (str_starts_with($service_id, 'template:')) {
                        $topology_data[] = [
                            'id' => $service_id,
                            'capabilities' => $service['point'] ?? [],
                            'metadata' => $service['metadata'] ?? []
                        ];
                    }
                }

                echo json_encode(['success' => true, 'data' => $topology_data]);
                break;

            // ========== Daemon Status & Health (NEW) ==========
            case 'get_daemon_status':
                $mode = $_GET['mode'] ?? 'full'; // 'basic' or 'full'
                $status = $client->status($mode);
                echo json_encode(['success' => true, 'data' => $status]);
                break;

            case 'report_health':
                $metrics = json_decode($_POST['metrics'] ?? '{}', true);
                if (!is_array($metrics) || empty($metrics['service_id'])) {
                    throw new \InvalidArgumentException('Invalid health metrics. service_id is required.');
                }
                $msgId = $client->sendHealthUpdate($metrics);
                echo json_encode(['success' => true, 'message_id' => $msgId]);
                break;

            // ========== Service Topology ==========
            case 'get_service_topology':
                // Query ValKey directly for stored service topology
                // IMPORTANT: Create separate connection to avoid blocking on shared connection
                $redis_direct = new Redis();
                $redis_direct->connect('127.0.0.1', 6379);
                $redis_direct->auth(trim(file_get_contents(__DIR__ . '/../.gsd/valkey.password')));

                $topology_key = '{default}:gcore:gsd:topology';
                $topology_json = $redis_direct->get($topology_key);

                if ($topology_json) {
                    $topology = json_decode($topology_json, true);
                    $services_data = [];

                    if (isset($topology['services'])) {
                        foreach ($topology['services'] as $service_id => $service_info) {
                            $services_data[] = [
                                'id' => $service_id,
                                'capabilities' => $service_info['point'] ?? [],
                                'metadata' => $service_info['metadata'] ?? []
                            ];
                        }
                    }

                    $dimensions = isset($topology['capability_dimensions']) ? $topology['capability_dimensions'] : [];
                    echo json_encode(['success' => true, 'data' => $services_data, 'dimensions' => $dimensions]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'No topology data found']);
                }
                $redis_direct->close();
                break;

            // ========== Utility ==========
            case 'ping':
                $result = $client->executeCommand('ping', []);
                echo json_encode(['success' => true, 'data' => $result]);
                break;

            default:
                throw new \Exception('Unknown action: ' . $_GET['action']);
        }
    } catch (\gCore\GSD\Exception\ConnectionException $e) {
        // Daemon connection errors (daemon down, network timeout, etc.)
        http_response_code(503);
        echo json_encode([
            'success' => false,
            'error' => 'Daemon unavailable. Please check if GSD daemon is running.',
            'details' => $e->getMessage(),
            'type' => 'connection_error',
            'retry' => true,
            'action' => 'Start daemon: cd ~/gh/GSD && ./scripts/start-daemon.sh'
        ]);
    } catch (\gCore\GSD\Exception\GSDException $e) {
        // GSD-specific errors (command failed, validation, etc.)
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'GSD Error: ' . $e->getMessage(),
            'type' => 'gsd_exception',
            'retry' => true
        ]);
    } catch (\InvalidArgumentException $e) {
        // Validation errors (missing parameters, invalid input, etc.)
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid input: ' . $e->getMessage(),
            'type' => 'validation_error',
            'retry' => false
        ]);
    } catch (\Exception $e) {
        // Generic errors (unexpected failures)
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Server error: ' . $e->getMessage(),
            'type' => 'general_error',
            'retry' => false
        ]);
    }
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GSD Interactive Demo - Geodineum Service Discovery</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        header {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        h1 {
            color: #667eea;
            font-size: 2.5em;
            margin-bottom: 10px;
        }

        .subtitle {
            color: #666;
            font-size: 1.1em;
        }

        .status {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.9em;
            font-weight: bold;
            margin-top: 10px;
        }

        .status.online {
            background: #10b981;
            color: white;
        }

        .status.offline {
            background: #ef4444;
            color: white;
        }

        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .tab {
            background: rgba(255, 255, 255, 0.9);
            border: none;
            padding: 15px 30px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1em;
            font-weight: 600;
            color: #667eea;
            transition: all 0.3s;
        }

        .tab:hover {
            background: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .tab.active {
            background: #667eea;
            color: white;
        }

        .tab-content {
            display: none;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .tab-content.active {
            display: block;
        }

        .panel {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .panel h3 {
            color: #667eea;
            margin-bottom: 15px;
            font-size: 1.3em;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 15px;
        }

        .card {
            background: white;
            border-radius: 8px;
            padding: 15px;
            border-left: 4px solid #667eea;
            transition: all 0.3s;
        }

        .card:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .card-title {
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }

        .card-meta {
            font-size: 0.85em;
            color: #666;
            margin-bottom: 10px;
        }

        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.75em;
            margin-right: 5px;
            margin-top: 5px;
        }

        .badge.format {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge.template {
            background: #fce7f3;
            color: #9f1239;
        }

        .badge.ttl {
            background: #fef3c7;
            color: #92400e;
        }

        textarea, input[type="text"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #e5e7eb;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 0.9em;
            margin-bottom: 10px;
        }

        button {
            background: #667eea;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1em;
            font-weight: 600;
            transition: all 0.3s;
        }

        button:hover {
            background: #5568d3;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        .output {
            background: #1e293b;
            color: #e2e8f0;
            padding: 15px;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 0.9em;
            overflow-x: auto;
            max-height: 400px;
            overflow-y: auto;
        }

        #topology-canvas {
            width: 100%;
            height: 600px;
            background: #1e293b;
            border-radius: 8px;
            display: block;
        }

        .controls {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }

        select {
            padding: 10px;
            border: 2px solid #e5e7eb;
            border-radius: 6px;
            font-size: 0.95em;
        }

        .stats {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }

        .stat-box {
            flex: 1;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
        }

        .stat-value {
            font-size: 2.5em;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.9em;
            opacity: 0.9;
        }

        .loading {
            text-align: center;
            padding: 40px;
            color: #666;
        }

        .spinner {
            border: 4px solid #f3f4f6;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 20px auto;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .capability-viz {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .capability-bar {
            flex: 1;
            min-width: 150px;
        }

        .capability-label {
            font-size: 0.85em;
            color: #666;
            margin-bottom: 5px;
        }

        .capability-value {
            height: 8px;
            background: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
        }

        .capability-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            transition: width 0.3s;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>🌐 GSD Interactive Demo</h1>
            <p class="subtitle">Geodineum Service Discovery • Format System • Template Management</p>
            <div id="connection-status"></div>
        </header>

        <div class="tabs">
            <button class="tab active" onclick="switchTab('overview')">📊 Overview</button>
            <button class="tab" onclick="switchTab('status')">🔍 Status Dashboard</button>
            <button class="tab" onclick="switchTab('formats')">📋 Format System</button>
            <button class="tab" onclick="switchTab('templates')">🎨 Templates</button>
            <button class="tab" onclick="switchTab('topology')">🌌 3D Topology</button>
            <button class="tab" onclick="switchTab('playground')">🧪 Playground</button>
        </div>

        <!-- Overview Tab -->
        <div id="tab-overview" class="tab-content active">
            <div class="stats">
                <div class="stat-box">
                    <div class="stat-value" id="service-count">--</div>
                    <div class="stat-label">Registered Services</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value" id="format-count">--</div>
                    <div class="stat-label">Message Formats</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value" id="template-count">--</div>
                    <div class="stat-label">Template Fragments</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value">8D</div>
                    <div class="stat-label">Capability Space</div>
                </div>
            </div>

            <div class="panel">
                <h3>🎯 What is GSD?</h3>
                <p style="margin-bottom: 15px; line-height: 1.6;">
                    GSD (Geodineum Service Discovery) uses <strong>n-dimensional geometric topology</strong> to enable
                    <strong>O(1) service discovery</strong>. Services are positioned in a high-dimensional capability
                    space, allowing instant discovery based on geometric distance.
                </p>
                <p style="line-height: 1.6;">
                    This demo showcases <strong>three integrated systems</strong>:
                </p>
                <ul style="margin-top: 10px; margin-left: 20px; line-height: 1.8;">
                    <li><strong>Format System:</strong> Register custom message formats with auto-detection</li>
                    <li><strong>Template System:</strong> Geometric discovery of template fragments</li>
                    <li><strong>Health Stream:</strong> Load-aware routing with real-time metrics</li>
                </ul>
            </div>

            <div class="panel">
                <h3>🚀 Key Features Demonstrated</h3>
                <div class="grid">
                    <div class="card">
                        <div class="card-title">⚡ O(1) Discovery</div>
                        <div class="card-meta">Geometric indexing enables constant-time service lookup regardless of service count</div>
                    </div>
                    <div class="card">
                        <div class="card-title">🎨 Format Agnostic</div>
                        <div class="card-meta">Automatic format detection and bidirectional transformation</div>
                    </div>
                    <div class="card">
                        <div class="card-title">📐 8D Capability Vectors</div>
                        <div class="card-meta">Templates mapped to 8-dimensional space for similarity scoring</div>
                    </div>
                    <div class="card">
                        <div class="card-title">🔄 Load-Aware Routing</div>
                        <div class="card-meta">Two-phase selection: geometric match + load scoring</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Status Dashboard Tab -->
        <div id="tab-status" class="tab-content">
            <div class="panel">
                <h3>🔍 Daemon Status</h3>
                <div class="stats">
                    <div class="stat-box">
                        <div class="stat-value" id="daemon-version">--</div>
                        <div class="stat-label">Daemon Version</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-value" id="daemon-uptime">--</div>
                        <div class="stat-label">Uptime (seconds)</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-value" id="daemon-commands">--</div>
                        <div class="stat-label">Supported Commands</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-value" id="daemon-functions">--</div>
                        <div class="stat-label">ValKey Functions</div>
                    </div>
                </div>
                <button onclick="refreshDaemonStatus()" style="margin-top: 20px;">🔄 Refresh Status</button>
            </div>

            <div class="panel">
                <h3>🔌 Connection Pool</h3>
                <div id="connection-pool-stats" class="grid">
                    <div class="card">
                        <div class="card-title">Total Connections</div>
                        <div class="stat-value" style="font-size: 2em;" id="pool-total">--</div>
                    </div>
                    <div class="card">
                        <div class="card-title">Idle Connections</div>
                        <div class="stat-value" style="font-size: 2em; color: #10b981;" id="pool-idle">--</div>
                    </div>
                    <div class="card">
                        <div class="card-title">Active Connections</div>
                        <div class="stat-value" style="font-size: 2em; color: #f59e0b;" id="pool-active">--</div>
                    </div>
                    <div class="card">
                        <div class="card-title">Pool Utilization</div>
                        <div class="stat-value" style="font-size: 2em;" id="pool-utilization">--</div>
                    </div>
                </div>
            </div>

            <div class="panel">
                <h3>📊 Daemon Information</h3>
                <div id="daemon-details" class="output" style="max-height: 400px;">
                    <div style="text-align: center; padding: 40px; color: #94a3b8;">
                        Click "Refresh Status" to load daemon information
                    </div>
                </div>
            </div>

            <div class="panel">
                <h3>💚 Send Health Update (Demo)</h3>
                <p style="margin-bottom: 15px; color: #666;">
                    Test the health stream by sending a sample health update. This demonstrates load-aware service selection.
                </p>
                <div class="controls">
                    <input type="text" id="health-service-id" placeholder="Service ID (e.g., demo-service)" style="width: 300px;">
                    <input type="number" id="health-load" placeholder="Load Factor (0.0-1.0)" step="0.01" min="0" max="1" style="width: 200px;">
                    <button onclick="sendHealthDemo()">📡 Send Health Update</button>
                </div>
                <div id="health-output" class="output" style="margin-top: 15px; display: none;"></div>
            </div>
        </div>

        <!-- Formats Tab -->
        <div id="tab-formats" class="tab-content">
            <div class="panel">
                <h3>📋 Registered Message Formats</h3>
                <div id="formats-list" class="grid">
                    <div class="loading">
                        <div class="spinner"></div>
                        Loading formats...
                    </div>
                </div>
            </div>

            <div class="panel">
                <h3>🔍 Format Detection Test</h3>
                <textarea id="format-test-input" rows="5" placeholder='Paste a message here, e.g.: {"method":"POST","endpoint":"/api/users","body":{}}'></textarea>
                <button onclick="testFormatDetection()">🔎 Detect Format</button>
                <div id="format-detection-output" class="output" style="margin-top: 15px; display: none;"></div>
            </div>
        </div>

        <!-- Templates Tab -->
        <div id="tab-templates" class="tab-content">
            <div class="panel">
                <h3>🎨 Template Fragments</h3>
                <div id="templates-list" class="grid">
                    <div class="loading">
                        <div class="spinner"></div>
                        Loading templates...
                    </div>
                </div>
            </div>

            <div class="panel">
                <h3>🔍 Template Discovery</h3>
                <div class="controls">
                    <select id="template-selector">
                        <option value="">Select a template...</option>
                    </select>
                    <button onclick="discoverSimilar()">🔎 Find Similar Templates</button>
                </div>
                <div id="similar-output" class="output" style="display: none;"></div>
            </div>
        </div>

        <!-- Topology Tab -->
        <div id="tab-topology" class="tab-content">
            <div class="panel">
                <h3>🌌 3D Topology Visualization</h3>
                <p style="margin-bottom: 15px; color: #666;">
                    <span id="topology-description">Services positioned in 3D space (reduced from 8D using PCA).</span>
                    Distance represents similarity. Rotate/zoom with mouse.
                </p>
                <div class="controls">
                    <button id="btn-service-view" onclick="switchTopologyView('services')" style="background: #667eea;">🌐 Service Topology</button>
                    <button id="btn-template-view" onclick="switchTopologyView('templates')" style="background: #94a3b8;">🎨 Template Topology</button>
                    <button onclick="refreshTopology()">🔄 Refresh</button>
                    <select id="axis-x">
                        <option value="0">X: Storage</option>
                        <option value="1">X: Compute</option>
                        <option value="2" selected>X: Network</option>
                        <option value="3">X: Security</option>
                        <option value="4">X: Latency</option>
                        <option value="5">X: Throughput</option>
                        <option value="6">X: Reliability</option>
                        <option value="7">X: Scalability</option>
                    </select>
                    <select id="axis-y">
                        <option value="0">Y: Storage</option>
                        <option value="1" selected>Y: Compute</option>
                        <option value="2">Y: Network</option>
                        <option value="3">Y: Security</option>
                        <option value="4">Y: Latency</option>
                        <option value="5">Y: Throughput</option>
                        <option value="6">Y: Reliability</option>
                        <option value="7">Y: Scalability</option>
                    </select>
                    <select id="axis-z">
                        <option value="0" selected>Z: Storage</option>
                        <option value="1">Z: Compute</option>
                        <option value="2">Z: Network</option>
                        <option value="3">Z: Security</option>
                        <option value="4">Z: Latency</option>
                        <option value="5">Z: Throughput</option>
                        <option value="6">Z: Reliability</option>
                        <option value="7">Z: Scalability</option>
                    </select>
                </div>
                <canvas id="topology-canvas"></canvas>
                <div id="topology-legend" style="margin-top: 15px; padding: 15px; background: #f8f9fa; border-radius: 8px; display: none;">
                    <h4 style="margin-bottom: 10px; color: #667eea;">8D Capability Dimensions:</h4>
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; font-size: 0.9em;">
                        <div>1. Storage - Data persistence</div>
                        <div>2. Compute - Processing power</div>
                        <div>3. Network - Network I/O</div>
                        <div>4. Security - Auth & encryption</div>
                        <div>5. Latency - Low-latency req</div>
                        <div>6. Throughput - High throughput</div>
                        <div>7. Reliability - Fault tolerance</div>
                        <div>8. Scalability - Horizontal scaling</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Playground Tab -->
        <div id="tab-playground" class="tab-content">
            <div class="panel">
                <h3>🧪 Interactive Playground</h3>
                <p style="margin-bottom: 15px; color: #666;">
                    Experiment with GSD commands and see real-time results.
                </p>

                <div style="margin-bottom: 20px;">
                    <h4 style="margin-bottom: 10px;">Quick Actions:</h4>
                    <div class="controls">
                        <button onclick="playgroundPing()">📡 Ping Daemon</button>
                        <button onclick="playgroundListAll()">📜 List All Formats</button>
                        <button onclick="playgroundConvert()">🔄 Convert Format</button>
                    </div>
                </div>

                <div id="playground-output" class="output"></div>
            </div>
        </div>
    </div>

    <script>
        // State
        let currentTab = 'overview';
        let formats = [];
        let templates = [];
        let services = [];
        let topologyData = [];
        let topologyView = 'services'; // 'services' or 'templates'

        // Tab switching
        function switchTab(tabName) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

            event.target.classList.add('active');
            document.getElementById('tab-' + tabName).classList.add('active');
            currentTab = tabName;

            // Load data for active tab
            if (tabName === 'formats' && formats.length === 0) loadFormats();
            if (tabName === 'templates' && templates.length === 0) loadTemplates();
            if (tabName === 'topology') refreshTopology();
        }

        // Switch topology view
        function switchTopologyView(view) {
            topologyView = view;

            // Update button styles
            const serviceBtn = document.getElementById('btn-service-view');
            const templateBtn = document.getElementById('btn-template-view');

            if (view === 'services') {
                serviceBtn.style.background = '#667eea';
                templateBtn.style.background = '#94a3b8';
                document.getElementById('topology-description').textContent =
                    'Services positioned in 8D capability space. Each dimension represents a service characteristic (storage, compute, network, etc).';
                document.getElementById('topology-legend').style.display = 'block';
            } else {
                serviceBtn.style.background = '#94a3b8';
                templateBtn.style.background = '#667eea';
                document.getElementById('topology-description').textContent =
                    'Templates positioned in 3D space (reduced from 8D using PCA). Distance represents similarity.';
                document.getElementById('topology-legend').style.display = 'none';
            }

            refreshTopology();
        }

        // Refresh current topology view
        function refreshTopology() {
            if (topologyView === 'services') {
                loadServiceTopology();
            } else {
                loadTemplateTopology();
            }
        }

        // API calls
        async function api(action, params = {}) {
            const isPost = ['detect_format', 'convert_format'].includes(action);
            const url = `?action=${action}${!isPost ? '&' + new URLSearchParams(params) : ''}`;

            const options = {
                method: isPost ? 'POST' : 'GET',
                headers: isPost ? {'Content-Type': 'application/x-www-form-urlencoded'} : {}
            };

            if (isPost && Object.keys(params).length > 0) {
                options.body = new URLSearchParams(params);
            }

            const response = await fetch(url, options);
            return await response.json();
        }

        // Check connection
        async function checkConnection() {
            try {
                const result = await api('ping');
                const status = document.getElementById('connection-status');
                if (result.success) {
                    status.innerHTML = '<span class="status online">● Daemon Online</span>';
                } else {
                    status.innerHTML = '<span class="status offline">● Daemon Offline</span>';
                }
            } catch (e) {
                const status = document.getElementById('connection-status');
                status.innerHTML = '<span class="status offline">● Connection Error</span>';
            }
        }

        // Load formats
        async function loadFormats() {
            try {
                const result = await api('list_formats');
                if (result.success) {
                    formats = result.data || [];
                    renderFormats();
                    document.getElementById('format-count').textContent = formats.length;
                }
            } catch (e) {
                console.error('Failed to load formats:', e);
            }
        }

        function renderFormats() {
            const container = document.getElementById('formats-list');
            if (formats.length === 0) {
                container.innerHTML = '<p style="text-align:center;color:#666;">No formats registered</p>';
                return;
            }

            container.innerHTML = formats.map(f => `
                <div class="card">
                    <div class="card-title">${f.name || 'Unknown'}</div>
                    <div class="card-meta">${f.description || 'No description'}</div>
                    <div>
                        <span class="badge format">${f.version || '1.0.0'}</span>
                        <span class="badge format">${f.content_type || 'application/json'}</span>
                        ${f.binary ? '<span class="badge format">binary</span>' : ''}
                    </div>
                </div>
            `).join('');
        }

        // Load templates
        async function loadTemplates() {
            try {
                const result = await api('list_templates');
                if (result.success && result.data && result.data.matches) {
                    templates = result.data.matches;
                    renderTemplates();
                    document.getElementById('template-count').textContent = templates.length;

                    // Populate selector
                    const selector = document.getElementById('template-selector');
                    selector.innerHTML = '<option value="">Select a template...</option>' +
                        templates.map(t => `<option value="${t.template_id}">${t.template_id}</option>`).join('');
                }
            } catch (e) {
                console.error('Failed to load templates:', e);
            }
        }

        function renderTemplates() {
            const container = document.getElementById('templates-list');
            if (templates.length === 0) {
                container.innerHTML = '<p style="text-align:center;color:#666;">No templates registered</p>';
                return;
            }

            container.innerHTML = templates.map(t => `
                <div class="card">
                    <div class="card-title">${t.template_id || 'Unknown'}</div>
                    <div class="card-meta">Match score: ${t.match_score ? t.match_score.toFixed(3) : 'N/A'}</div>
                </div>
            `).join('');
        }

        // Format detection
        async function testFormatDetection() {
            const input = document.getElementById('format-test-input').value.trim();
            const output = document.getElementById('format-detection-output');

            if (!input) {
                alert('Please enter a message to test');
                return;
            }

            try {
                const result = await api('detect_format', {message: input});
                output.style.display = 'block';
                output.textContent = JSON.stringify(result, null, 2);
            } catch (e) {
                output.style.display = 'block';
                output.textContent = 'Error: ' + e.message;
            }
        }

        // Discover similar templates
        async function discoverSimilar() {
            const templateId = document.getElementById('template-selector').value;
            const output = document.getElementById('similar-output');

            if (!templateId) {
                alert('Please select a template first');
                return;
            }

            try {
                const result = await api('discover_similar', {template_id: templateId});
                output.style.display = 'block';
                output.textContent = JSON.stringify(result, null, 2);
            } catch (e) {
                output.style.display = 'block';
                output.textContent = 'Error: ' + e.message;
            }
        }

        // Load service topology
        async function loadServiceTopology() {
            try {
                const result = await api('get_service_topology');
                if (result.success) {
                    services = result.data || [];
                    topologyData = services;
                    document.getElementById('service-count').textContent = services.length;
                    render3DTopology();
                }
            } catch (e) {
                console.error('Failed to load service topology:', e);
                topologyData = [];
                render3DTopology();
            }
        }

        // Load template topology
        async function loadTemplateTopology() {
            try {
                const result = await api('get_topology');
                if (result.success) {
                    topologyData = result.data || [];
                    render3DTopology();
                }
            } catch (e) {
                console.error('Failed to load template topology:', e);
            }
        }

        // 3D Topology rendering (simple Canvas 2D projection)
        function render3DTopology() {
            const canvas = document.getElementById('topology-canvas');
            const ctx = canvas.getContext('2d');

            // Set canvas size
            canvas.width = canvas.offsetWidth;
            canvas.height = canvas.offsetHeight;

            // Clear canvas
            ctx.fillStyle = '#1e293b';
            ctx.fillRect(0, 0, canvas.width, canvas.height);

            if (topologyData.length === 0) {
                ctx.fillStyle = '#64748b';
                ctx.font = '16px sans-serif';
                ctx.textAlign = 'center';
                const message = topologyView === 'services'
                    ? 'No services registered. Run load_demo_data.php to populate.'
                    : 'No templates available. Template commands not yet implemented.';
                ctx.fillText(message, canvas.width / 2, canvas.height / 2);
                return;
            }

            // Get selected axes
            const xAxisIndex = parseInt(document.getElementById('axis-x').value);
            const yAxisIndex = parseInt(document.getElementById('axis-y').value);
            const zAxisIndex = parseInt(document.getElementById('axis-z').value);

            const dimensionNames = ['Storage', 'Compute', 'Network', 'Security', 'Latency', 'Throughput', 'Reliability', 'Scalability'];

            // Extract points based on view type
            const points = topologyData.map(item => {
                let x, y, z;

                if (topologyView === 'services') {
                    // Services have array capabilities [0.3, 0.4, 0.5, ...]
                    const caps = item.capabilities || [];
                    x = caps[xAxisIndex] || 0;
                    y = caps[yAxisIndex] || 0;
                    z = caps[zAxisIndex] || 0;
                } else {
                    // Templates have object capabilities {complexity: 0.5, ...}
                    const caps = item.capabilities || {};
                    const xAxis = document.getElementById('axis-x').value;
                    const yAxis = document.getElementById('axis-y').value;
                    const zAxis = document.getElementById('axis-z').value;
                    x = caps[xAxis] || Math.random();
                    y = caps[yAxis] || Math.random();
                    z = caps[zAxis] || Math.random();
                }

                return {
                    id: item.id,
                    metadata: item.metadata || {},
                    x: x,
                    y: y,
                    z: z
                };
            });

            // Normalize and project to 2D
            const margin = 80;
            const maxX = Math.max(...points.map(p => p.x), 1);
            const maxY = Math.max(...points.map(p => p.y), 1);

            points.forEach((point, index) => {
                const screenX = margin + (point.x / maxX) * (canvas.width - 2 * margin);
                const screenY = margin + (1 - point.y / maxY) * (canvas.height - 2 * margin);
                const size = 5 + point.z * 12; // Z affects size

                // Color based on index for variety
                const hue = (index * 360 / points.length) % 360;
                ctx.fillStyle = `hsl(${hue}, 70%, 60%)`;

                // Draw point
                ctx.beginPath();
                ctx.arc(screenX, screenY, size, 0, Math.PI * 2);
                ctx.fill();

                // Draw border
                ctx.strokeStyle = '#e2e8f0';
                ctx.lineWidth = 2;
                ctx.stroke();

                // Draw label
                ctx.fillStyle = '#e2e8f0';
                ctx.font = 'bold 10px monospace';
                ctx.textAlign = 'center';
                const label = point.metadata.name || point.id;
                const shortLabel = label.length > 20 ? label.substring(0, 17) + '...' : label;
                ctx.fillText(shortLabel, screenX, screenY + size + 14);

                // Draw capability values
                ctx.font = '8px monospace';
                ctx.fillStyle = '#94a3b8';
                ctx.fillText(`(${point.x.toFixed(2)}, ${point.y.toFixed(2)}, ${point.z.toFixed(2)})`,
                    screenX, screenY + size + 24);
            });

            // Draw axes
            ctx.strokeStyle = '#475569';
            ctx.lineWidth = 2;
            ctx.beginPath();
            ctx.moveTo(margin, canvas.height - margin);
            ctx.lineTo(canvas.width - margin, canvas.height - margin);
            ctx.moveTo(margin, canvas.height - margin);
            ctx.lineTo(margin, margin);
            ctx.stroke();

            // Axis labels
            ctx.fillStyle = '#94a3b8';
            ctx.font = 'bold 14px sans-serif';
            ctx.textAlign = 'center';

            if (topologyView === 'services') {
                ctx.fillText(dimensionNames[xAxisIndex], canvas.width / 2, canvas.height - 15);
                ctx.save();
                ctx.translate(20, canvas.height / 2);
                ctx.rotate(-Math.PI / 2);
                ctx.fillText(dimensionNames[yAxisIndex], 0, 0);
                ctx.restore();
            } else {
                const xAxis = document.getElementById('axis-x').value;
                const yAxis = document.getElementById('axis-y').value;
                ctx.fillText(xAxis, canvas.width / 2, canvas.height - 15);
                ctx.save();
                ctx.translate(20, canvas.height / 2);
                ctx.rotate(-Math.PI / 2);
                ctx.fillText(yAxis, 0, 0);
                ctx.restore();
            }

            // Draw count
            ctx.fillStyle = '#e2e8f0';
            ctx.font = '12px sans-serif';
            ctx.textAlign = 'right';
            ctx.fillText(`${points.length} ${topologyView}`, canvas.width - margin, 30);
        }

        // Status Dashboard functions
        async function refreshDaemonStatus() {
            try {
                const result = await api('get_daemon_status', {mode: 'full'});
                if (result.success && result.data) {
                    const status = result.data;

                    // Update basic status
                    document.getElementById('daemon-version').textContent = status.version || 'Unknown';
                    document.getElementById('daemon-uptime').textContent = status.uptime || '0';

                    // Update commands count
                    const commandsCount = status.supported_commands ? status.supported_commands.length : 0;
                    document.getElementById('daemon-commands').textContent = commandsCount;

                    // Update functions count
                    const functionsCount = status.valkey_functions ? status.valkey_functions.length : 0;
                    document.getElementById('daemon-functions').textContent = functionsCount;

                    // Update connection pool stats
                    if (status.connection_pool) {
                        const pool = status.connection_pool;
                        const total = pool.total_connections || 0;
                        const idle = pool.idle_connections || 0;
                        const active = total - idle;
                        const utilization = total > 0 ? Math.round((active / total) * 100) : 0;

                        document.getElementById('pool-total').textContent = total;
                        document.getElementById('pool-idle').textContent = idle;
                        document.getElementById('pool-active').textContent = active;
                        document.getElementById('pool-utilization').textContent = utilization + '%';
                    }

                    // Display full details
                    document.getElementById('daemon-details').textContent = JSON.stringify(status, null, 2);
                }
            } catch (e) {
                console.error('Failed to refresh daemon status:', e);
                document.getElementById('daemon-details').textContent = 'Error: ' + e.message;
            }
        }

        async function sendHealthDemo() {
            const serviceId = document.getElementById('health-service-id').value.trim();
            const loadFactor = parseFloat(document.getElementById('health-load').value);
            const output = document.getElementById('health-output');

            if (!serviceId) {
                alert('Please enter a service ID');
                return;
            }

            if (isNaN(loadFactor) || loadFactor < 0 || loadFactor > 1) {
                alert('Load factor must be between 0.0 and 1.0');
                return;
            }

            try {
                const metrics = {
                    service_id: serviceId,
                    load_factor: loadFactor,
                    cpu_usage: Math.random() * 0.8, // Random demo values
                    memory_usage: Math.random() * 0.7,
                    timestamp: Date.now()
                };

                const metricsJson = JSON.stringify(metrics);
                const response = await fetch('?action=report_health', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({metrics: metricsJson})
                });

                const result = await response.json();
                output.style.display = 'block';
                if (result.success) {
                    output.textContent = `✅ Health update sent successfully!\n\nMessage ID: ${result.message_id}\n\nMetrics:\n${JSON.stringify(metrics, null, 2)}`;
                } else {
                    output.textContent = `❌ Error: ${result.error}`;
                }
            } catch (e) {
                output.style.display = 'block';
                output.textContent = 'Error: ' + e.message;
            }
        }

        // Playground functions
        async function playgroundPing() {
            const output = document.getElementById('playground-output');
            try {
                const result = await api('ping');
                output.textContent = JSON.stringify(result, null, 2);
            } catch (e) {
                output.textContent = 'Error: ' + e.message;
            }
        }

        async function playgroundListAll() {
            const output = document.getElementById('playground-output');
            try {
                const result = await api('list_formats');
                output.textContent = JSON.stringify(result, null, 2);
            } catch (e) {
                output.textContent = 'Error: ' + e.message;
            }
        }

        async function playgroundConvert() {
            const output = document.getElementById('playground-output');
            const testMsg = '{"id":"test123","command":"example","parameters":{"key":"value"}}';
            try {
                const result = await api('convert_format', {
                    message: testMsg,
                    from: 'standard_json',
                    to: 'compact_json'
                });
                output.textContent = 'Input:\n' + testMsg + '\n\nOutput:\n' + JSON.stringify(result, null, 2);
            } catch (e) {
                output.textContent = 'Error: ' + e.message;
            }
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', () => {
            checkConnection();
            loadFormats();
            loadTemplates();
            loadServiceTopology(); // Load service count for overview

            // Refresh connection status every 5 seconds
            setInterval(checkConnection, 5000);
        });
    </script>
</body>
</html>
